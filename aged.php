<?php
/**
 * Any-Chart Reports Lab — Accounts Receivable / Accounts Payable from the ledger.
 *
 * aged.php?type=ar  customers (control account ACCOUNTING_ACCOUNT_CUSTOMER)
 * aged.php?type=ap  suppliers (control account ACCOUNTING_ACCOUNT_SUPPLIER)
 *
 * Open items per third party, aged by due date or document date, with two checks:
 *  1. report total = balance of the control account(s) in the ledger,
 *  2. per third party, ledger vs Dolibarr's unpaid invoices (today's position).
 */

$res = 0;
if (!$res && is_file('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && is_file('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
require_once __DIR__.'/lib/anychartlab.lib.php';
require_once __DIR__.'/lib/aged.lib.php';

if (!$user->hasRight('anychartlab', 'lire')) {
	accessforbidden();
}

$type = (GETPOST('type', 'aZ09') === 'ap' ? 'ap' : 'ar');
$action = GETPOST('action', 'aZ09');
$filtered = GETPOSTINT('filtered');
$basis = (GETPOST('basis', 'aZ09') === 'doc' ? 'doc' : 'due');
$view = (GETPOST('view', 'aZ09') === 'detailed' ? 'detailed' : 'summary');
$asof = GETPOSTINT('asofyear') ? dol_mktime(23, 59, 59, GETPOSTINT('asofmonth'), GETPOSTINT('asofday'), GETPOSTINT('asofyear')) : dol_mktime(23, 59, 59, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y'));
$accountsParam = trim(GETPOST('accounts', 'alphanohtml'));
$accounts = ($accountsParam !== '' ? array_values(array_filter(array_map('trim', preg_split('/[,; ]+/', $accountsParam)))) : anychartlab_aged_default_accounts($type));

$entity = (int) $conf->entity;
$pcgversion = anychartlab_active_chart($db);
$title = ($type === 'ap' ? 'Accounts Payable' : 'Accounts Receivable');
$partyWord = ($type === 'ap' ? 'Supplier' : 'Customer');
$buckets = anychartlab_aged_buckets($basis);
$shortBuckets = anychartlab_aged_buckets_short($basis);
if (anychartlab_cfg('AGED_ORDER') !== 'newest') {
	// oldest first: 90+ ... Not due (Display setting)
	$buckets = array_reverse($buckets, true);
	$shortBuckets = array_reverse($shortBuckets, true);
}

$aged = anychartlab_aged_build($db, $entity, $type, $accounts, $asof, $basis);
$ledgerBalance = anychartlab_aged_ledger_balance($db, $entity, $type, $accounts, $asof);
$isToday = (dol_print_date($asof, '%Y-%m-%d') === dol_print_date(dol_now(), '%Y-%m-%d'));

// Rows shared by screen, CSV and PDF: label, date, due, buckets..., total
$rows = array();
foreach ($aged['parties'] as $p) {
	$cells = array($p['name'].($p['code'] !== '' && $p['code'] !== $p['name'] ? ' ('.$p['code'].')' : ''), '', '');
	foreach (array_keys($buckets) as $k) {
		$cells[] = $p['buckets'][$k];
	}
	$cells[] = $p['total'];
	$rows[] = array('kind' => ($view === 'detailed' ? 'parent' : 'account'), 'cells' => $cells, 'party' => $p);
	if ($view === 'detailed') {
		foreach ($p['items'] as $it) {
			$c = array($it['ref'].(!empty($it['unallocated']) ? ' (unallocated '.($it['type'] === 'bank' ? 'payment' : 'credit').')' : ''), dol_print_date($it['date'], 'day'), ($it['type'] === 'bank' ? '' : dol_print_date($it['due'], 'day')));
			foreach (array_keys($buckets) as $k) {
				$c[] = ($it['bucket'] === $k ? $it['remain'] : '');
			}
			$c[] = $it['remain'];
			$rows[] = array('kind' => 'child', 'cells' => $c);
		}
	}
}
$totalCells = array('TOTAL', '', '');
foreach (array_keys($buckets) as $k) {
	$totalCells[] = $aged['totals'][$k];
}
$totalCells[] = $aged['total'];
$rows[] = array('kind' => 'grandtotal', 'cells' => $totalCells);
$header = array_merge(array($partyWord.' / document', 'Date', 'Due'), array_values($buckets), array('Total'));
// Date and Due belong to documents: the summary view has none, so it leaves those columns out
$amt = 3;	// index of the first amount column
if ($view === 'summary') {
	$header[0] = $partyWord;
	array_splice($header, 1, 2);
	foreach ($rows as $i => $r) {
		array_splice($rows[$i]['cells'], 1, 2);
	}
	$amt = 1;
}

if (in_array($action, array('export', 'pdf', 'pdfpreview'))) {
	$name = ($type === 'ap' ? 'accounts-payable' : 'accounts-receivable').'-'.dol_print_date($asof, '%Y-%m-%d');
	$subtitle = 'As of '.dol_print_date($asof, 'day').' - aged by '.($basis === 'doc' ? 'document date' : 'due date').' - account(s) '.implode(', ', $accounts).' - amounts in '.$conf->currency;
	if ($action === 'export') {
		$csv = array();
		foreach ($rows as $r) {
			$c = $r['cells'];
			for ($i = $amt; $i < count($c); $i++) {
				$c[$i] = ($c[$i] === '' ? '' : price2num($c[$i], 'MT'));
			}
			$csv[] = $c;
		}
		anychartlab_send_csv('anychartlab-'.$name, $header, $csv);
	}
	$columns = array(array('title' => $header[0], 'width' => ($amt === 3 ? 0.31 : 0.46), 'align' => 'L', 'indent' => 1));
	if ($amt === 3) {
		$columns[] = array('title' => 'Date', 'width' => 0.075, 'align' => 'L');
		$columns[] = array('title' => 'Due', 'width' => 0.075, 'align' => 'L');
	}
	foreach (array_values($shortBuckets) as $b) {
		$columns[] = array('title' => $b, 'width' => 0.095, 'align' => 'R');
	}
	$columns[] = array('title' => 'Total', 'width' => 0.105, 'align' => 'R');
	$pdfRows = array();
	foreach ($rows as $r) {
		$c = $r['cells'];
		for ($i = $amt; $i < count($c); $i++) {
			$c[$i] = ($c[$i] === '' || ($r['kind'] !== 'child' && abs((float) $c[$i]) < 0.005 && $i < count($c) - 1) ? '' : anychartlab_price($c[$i]));
		}
		$pdfRows[] = array('kind' => $r['kind'], 'cells' => $c);
	}
	anychartlab_pdf_table($name, $title, $subtitle, $columns, $pdfRows, $action === 'pdfpreview', 'L');	// up to 9 columns: always landscape
}

/*
 * View
 */

$form = new Form($db);
llxHeader('', $title.' (Any-Chart Lab)', '', '', 0, 0, '', '', '', 'mod-anychartlab page-aged');
print load_fiche_titre($title, '', 'accountancy');
anychartlab_print_style_css();
anychartlab_print_nav('aged.php?type='.$type, $pcgversion);

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="agedform">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="filtered" value="1"><input type="hidden" name="type" value="'.$type.'">';
print 'As of '.$form->selectDate($asof, 'asof', 0, 0, 0, 'agedform').' ';
print 'Aged by <select name="basis" class="flat"><option value="due"'.($basis === 'due' ? ' selected' : '').'>due date</option><option value="doc"'.($basis === 'doc' ? ' selected' : '').'>document date</option></select> ';
print 'View <select name="view" class="flat"><option value="summary"'.($view === 'summary' ? ' selected' : '').'>Summary (one line per '.strtolower($partyWord).')</option><option value="detailed"'.($view === 'detailed' ? ' selected' : '').'>Detailed (open items)</option></select> ';
print 'Account(s) <input type="text" name="accounts" class="flat width100" value="'.dol_escape_htmltag(implode(',', $accounts)).'" title="Control account(s); default from Accounting setup"> ';
print '<input type="submit" class="button small" value="Refresh"> ';
print '<button type="submit" class="button small" name="action" value="export">Export CSV</button> ';
print '<button type="submit" class="button small" name="action" value="pdf">PDF</button> ';
print '<button type="submit" class="button small" name="action" value="pdfpreview" formtarget="_blank">Preview PDF</button>';
print '</form>';

if (!$accounts) {
	print '<div class="warning">No '.($type === 'ap' ? 'supplier' : 'customer').' control account is set in Accounting &gt; Setup. Enter the account number(s) above.</div>';
}
print '<p class="opacitymedium">From the accounting ledger: entries on account(s) <b>'.dol_escape_htmltag(implode(', ', $accounts)).'</b> up to the date, per '.strtolower($partyWord).' (subledger account). '.($aged['lettered'] ? $aged['lettered'].' lettered entries matched by their lettering; others ' : 'Payments and credit notes ').'are matched to the oldest open invoices of the same '.strtolower($partyWord).'. Amounts in '.dol_escape_htmltag($conf->currency).'.</p>';

// Check 1: total = control account balance
$diff = $aged['total'] - $ledgerBalance;
print '<div class="'.(abs($diff) < 0.005 ? 'ok' : 'error').'"><b>Check 1:</b> report total '.anychartlab_price($aged['total']).' = balance of account(s) '.dol_escape_htmltag(implode(', ', $accounts)).' in the ledger '.anychartlab_price($ledgerBalance).(abs($diff) < 0.005 ? ' (as on the Balance Sheet).' : '. Difference '.anychartlab_price($diff).'.').'</div>';
if (abs($aged['nothirdparty']) >= 0.005) {
	print '<div class="warning">'.anychartlab_price($aged['nothirdparty']).' is on the control account without a '.strtolower($partyWord).' (no subledger account on the entry). It is in the total but cannot be aged per '.strtolower($partyWord).'.</div>';
}

// Main table
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.dol_escape_htmltag($header[0]).'</th>'.($amt === 3 ? '<th>Date</th><th>Due</th>' : '');
foreach ($buckets as $b) {
	print '<th class="right">'.dol_escape_htmltag($b).'</th>';
}
print '<th class="right">Total</th></tr>';
foreach ($rows as $r) {
	$c = $r['cells'];
	print '<tr class="'.($r['kind'] === 'grandtotal' ? 'liste_total' : 'oddeven').' acl-'.$r['kind'].'">';
	print '<td>'.dol_escape_htmltag($c[0]).'</td>';
	for ($i = 1; $i < $amt; $i++) {
		print '<td class="nowraponall">'.dol_escape_htmltag($c[$i]).'</td>';
	}
	for ($i = $amt; $i < count($c); $i++) {
		$show = ($c[$i] === '' || ($r['kind'] !== 'child' && abs((float) $c[$i]) < 0.005 && $i < count($c) - 1)) ? '' : anychartlab_price($c[$i]);
		print '<td class="right amount nowraponall">'.$show.'</td>';
	}
	print '</tr>';
}
if (!$aged['parties']) {
	print '<tr><td colspan="'.(count($header)).'" class="opacitymedium">Nothing outstanding.</td></tr>';
}
print '</table>';

// Check 2: ledger vs unpaid invoices (today only)
print '<h3>Check 2: ledger vs Dolibarr unpaid invoices</h3>';
if (!$isToday) {
	print '<p class="opacitymedium">Only available as of today: Dolibarr\'s "remaining to pay" on invoices is today\'s position.</p>';
} else {
	$unpaid = anychartlab_aged_unpaid_invoices($db, $entity, $type);
	$cmp = array();
	$seen = array();
	foreach ($aged['parties'] as $p) {
		if ($p['code'] === '') {
			continue;
		}
		$inv = $unpaid[$p['code']] ?? null;
		$cmp[] = array('name' => $p['name'], 'code' => $p['code'], 'ledger' => $p['total'], 'invoices' => $inv ? $inv['remain'] : 0.0, 'count' => $inv ? $inv['count'] : 0);
		$seen[$p['code']] = 1;
	}
	$done = array();
	foreach ($unpaid as $code => $inv) {
		if (isset($seen[$code]) || isset($done[$inv['name']]) || abs($inv['remain']) < 0.005) {
			continue;
		}
		$done[$inv['name']] = 1;
		$cmp[] = array('name' => $inv['name'], 'code' => $code, 'ledger' => 0.0, 'invoices' => $inv['remain'], 'count' => $inv['count']);
	}
	$mismatch = array_filter($cmp, function ($r) {
		return abs($r['ledger'] - $r['invoices']) >= 0.01;
	});
	print '<p>'.(count($cmp) - count($mismatch)).' of '.count($cmp).' '.strtolower($partyWord).'s agree. '.($mismatch ? 'Differences usually mean invoices or payments not yet transferred to accounting, payments not matched to their invoice in Dolibarr, or entries made directly in the ledger.' : '').'</p>';
	if ($mismatch) {
		print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$partyWord.'</th><th class="right">Ledger (this report)</th><th class="right">Unpaid invoices in Dolibarr</th><th class="right">Difference</th></tr>';
		foreach ($mismatch as $m) {
			print '<tr class="oddeven"><td>'.dol_escape_htmltag($m['name']).' <span class="opacitymedium">('.dol_escape_htmltag($m['code']).', '.$m['count'].' unpaid invoice'.($m['count'] === 1 ? '' : 's').')</span></td>';
			print '<td class="right amount">'.anychartlab_price($m['ledger']).'</td><td class="right amount">'.anychartlab_price($m['invoices']).'</td><td class="right amount"><b>'.anychartlab_price($m['ledger'] - $m['invoices']).'</b></td></tr>';
		}
		print '</table>';
	}
}

llxFooter();
$db->close();
