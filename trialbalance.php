<?php
/**
 * Any-Chart Reports Lab — Trial Balance over a period: opening balance, debits,
 * credits and closing balance of every account, with its nature, in account order
 * or grouped by nature. Closing balances are the Balance Sheet at the end date.
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
require_once __DIR__.'/lib/trialbalance.lib.php';

if (!$user->hasRight('anychartlab', 'lire')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$groupBy = (GETPOST('groupby', 'aZ09') === 'nature' ? 'nature' : 'none');
$to = GETPOSTINT('dateendyear') ? dol_mktime(23, 59, 59, GETPOSTINT('dateendmonth'), GETPOSTINT('dateendday'), GETPOSTINT('dateendyear')) : dol_now();
$from = GETPOSTINT('datestartyear') ? dol_mktime(0, 0, 0, GETPOSTINT('datestartmonth'), GETPOSTINT('datestartday'), GETPOSTINT('datestartyear')) : anychartlab_fiscal_year_start($to);

$entity = (int) $conf->entity;
$pcgversion = anychartlab_active_chart($db);
$accounts = ($pcgversion !== '' ? anychartlab_load_accounts($db, $entity, $pcgversion) : null);
$tb = ($accounts && $from <= $to ? anychartlab_tb_build($db, $entity, $accounts, $from, $to) : null);
$rows = ($tb ? anychartlab_tb_rows($tb, $groupBy) : array());
$header = array('Account', 'Label', 'Nature', 'Note', 'Opening (Dr - Cr)', 'Debit', 'Credit', 'Closing debit', 'Closing credit');

if (in_array($action, array('export', 'pdf', 'pdfpreview')) && $tb) {
	$name = 'trial-balance-'.dol_print_date($from, '%Y-%m-%d').'-'.dol_print_date($to, '%Y-%m-%d');
	if ($action === 'export') {
		$csv = array();
		foreach ($rows as $r) {
			$c = $r['cells'];
			for ($i = 4; $i < 9; $i++) {
				$c[$i] = ($c[$i] === '' ? '' : price2num($c[$i], 'MT'));
			}
			$csv[] = $c;
		}
		anychartlab_send_csv('anychartlab-'.$name, $header, $csv);
	}
	$columns = array(
		array('title' => 'Account', 'width' => 0.08, 'align' => 'L', 'indent' => 1),
		array('title' => 'Label', 'width' => 0.27, 'align' => 'L', 'indent' => 1),
		array('title' => 'Nature', 'width' => 0.09, 'align' => 'L'),
		array('title' => 'Opening', 'width' => 0.112, 'align' => 'R'),
		array('title' => 'Debit', 'width' => 0.112, 'align' => 'R'),
		array('title' => 'Credit', 'width' => 0.112, 'align' => 'R'),
		array('title' => 'Closing Dr', 'width' => 0.112, 'align' => 'R'),
		array('title' => 'Closing Cr', 'width' => 0.112, 'align' => 'R'),
	);
	$pdfRows = array();
	foreach ($rows as $r) {
		$c = $r['cells'];
		$cells = array((string) $c[0], (string) $c[1], (string) $c[2]);
		for ($i = 4; $i < 9; $i++) {
			$cells[] = ($c[$i] === '' ? '' : anychartlab_price($c[$i]));
		}
		$pdfRows[] = array('kind' => $r['kind'], 'cells' => $cells);
	}
	anychartlab_pdf_table($name, 'Trial Balance', dol_print_date($from, 'day').' to '.dol_print_date($to, 'day').' · '.$tb['window']['label'].' · amounts in '.$conf->currency, $columns, $pdfRows, $action === 'pdfpreview', 'L');
}

$form = new Form($db);
llxHeader('', 'Trial Balance (Any-Chart Lab)', '', '', 0, 0, '', '', '', 'mod-anychartlab page-trialbalance');
print load_fiche_titre('Trial Balance', '', 'accountancy');
anychartlab_print_style_css();
anychartlab_print_nav('trialbalance.php', $pcgversion);

if (!$accounts) {
	print '<div class="warning">No chart of accounts is selected.</div>';
	llxFooter();
	exit;
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="tbform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print 'From '.$form->selectDate($from, 'datestart', 0, 0, 0, 'tbform').' to '.$form->selectDate($to, 'dateend', 0, 0, 0, 'tbform').' ';
print 'Show <select name="groupby" class="flat"><option value="none"'.($groupBy === 'none' ? ' selected' : '').'>In account order</option><option value="nature"'.($groupBy === 'nature' ? ' selected' : '').'>Grouped by nature, with subtotals</option></select> ';
print '<input type="submit" class="button small" value="Refresh"> ';
print '<button type="submit" class="button small" name="action" value="export">Export CSV</button>';
print ' <button type="submit" class="button small" name="action" value="pdf">PDF</button>';
print ' <button type="submit" class="button small" name="action" value="pdfpreview" formtarget="_blank">Preview PDF</button>';
print '</form>';

if (!$tb) {
	print '<div class="warning">The start date must be before the end date.</div>';
	llxFooter();
	exit;
}

print '<p class="opacitymedium">Window: '.dol_escape_htmltag($tb['window']['label']).' (as on the Balance Sheet, so closing balances are the Balance Sheet at '.dol_print_date($to, 'day').'). Amounts in '.dol_escape_htmltag($conf->currency).'. Default period: current fiscal year to date.</p>';
foreach ($tb['window']['warnings'] as $w) {
	print '<div class="warning">'.dol_escape_htmltag($w).'</div>';
}
if ($tb['cut']) {
	print '<div class="warning">A fiscal year was closed inside this period: its balances were carried forward as opening entries on '.dol_print_date($tb['movementFrom'], 'day').', so debits and credits are shown from that date (the opening balance is 0).</div>';
}

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Account</th><th>Label</th><th>Nature</th><th class="right">Opening<br><span class="opacitymedium">(Dr − Cr)</span></th><th class="right">Debit</th><th class="right">Credit</th><th class="right">Closing debit</th><th class="right">Closing credit</th></tr>';
$setupUrl = dol_buildpath('/anychartlab/admin/setup.php', 1);
foreach ($rows as $r) {
	$c = $r['cells'];
	$kind = $r['kind'];
	$trClass = ($kind === 'section' ? 'liste_titre' : (in_array($kind, array('total', 'grandtotal')) ? 'liste_total' : 'oddeven'));
	print '<tr class="'.$trClass.' acl-'.$kind.'">';
	if ($kind === 'section') {
		print '<td colspan="3">'.dol_escape_htmltag($c[0]).'</td>';
	} else {
		$isAcc = ($kind === 'account' && $c[0] !== '');
		print '<td class="nowraponall">'.($isAcc ? '<a href="'.anychartlab_ledger_url($c[0]).'" target="_blank">'.dol_escape_htmltag($c[0]).'</a>' : '').'</td>';
		print '<td>'.dol_escape_htmltag($c[1]).'</td>';
		if ($isAcc && $c[3] === 'unclassified') {
			print '<td><a href="'.$setupUrl.'?search_account='.urlencode($c[0]).'" class="error">unclassified</a></td>';
		} elseif ($isAcc && $c[3] === 'not in the active chart') {
			print '<td class="error">not in chart</td>';
		} else {
			print '<td class="nowraponall">'.dol_escape_htmltag(strtolower($c[2])).($c[3] !== '' ? ' <span class="opacitymedium" title="'.dol_escape_htmltag($c[3]).'">*</span>' : '').'</td>';
		}
	}
	for ($i = 4; $i < 9; $i++) {
		print '<td class="right amount nowraponall">'.($c[$i] === '' ? '' : anychartlab_price($c[$i])).'</td>';
	}
	print '</tr>';
}
if (!$tb['lines']) {
	print '<tr><td colspan="8" class="opacitymedium">No entries in this period.</td></tr>';
}
print '</table></div>';
print '<p class="opacitymedium">* hover for a note: alternate nature used because the balance is on the other side, or a contra account.</p>';

// Checks
$t = $tb['totals'];
$okMoves = (abs($t['debit'] - $t['credit']) < 0.005);
$okClosing = (abs($t['closing_dr'] - $t['closing_cr']) < 0.005);
print '<div class="'.($okMoves ? 'ok' : 'error').'"><b>Check 1:</b> debits of the period '.anychartlab_price($t['debit']).($okMoves ? ' = ' : ' ≠ ').'credits '.anychartlab_price($t['credit']).($okMoves ? '.' : ': difference '.anychartlab_price($t['debit'] - $t['credit']).'. The ledger does not balance for this period.').'</div>';
print '<div class="'.($okClosing ? 'ok' : 'error').'"><b>Check 2:</b> closing debit balances '.anychartlab_price($t['closing_dr']).($okClosing ? ' = ' : ' ≠ ').'closing credit balances '.anychartlab_price($t['closing_cr']).($okClosing ? '.' : ': difference '.anychartlab_price($t['closing_dr'] - $t['closing_cr']).'.').'</div>';
$unclassified = count(array_filter($tb['lines'], function ($l) {
	return in_array($l['group'], array('UNCLASSIFIED', 'UNKNOWN'));
}));
if ($unclassified) {
	print '<div class="warning">'.$unclassified.' account(s) have no nature or are not in the active chart: they are in this Trial Balance but left out of the Balance Sheet and Income Statement. Set their nature in <a href="'.$setupUrl.'">Account natures</a>.</div>';
}

llxFooter();
$db->close();
