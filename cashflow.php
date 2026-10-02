<?php
/**
 * Any-Chart Reports Lab — Cash Flow statement over a period (indirect method), built
 * from account natures and cash flow classes (operating / investing / financing).
 * Cash = Dolibarr's bank and cash accounts, plus accounts set to "cash".
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
require_once __DIR__.'/lib/cashflow.lib.php';

if (!$user->hasRight('anychartlab', 'lire')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$filtered = GETPOSTINT('filtered');
$view = (GETPOST('view', 'aZ09') !== '' ? GETPOST('view', 'aZ09') : anychartlab_cfg('DEFAULT_VIEW'));
$view = ($view === 'summary' ? 'summary' : 'detailed');
$to = GETPOSTINT('dateendyear') ? dol_mktime(23, 59, 59, GETPOSTINT('dateendmonth'), GETPOSTINT('dateendday'), GETPOSTINT('dateendyear')) : dol_now();
$from = GETPOSTINT('datestartyear') ? dol_mktime(0, 0, 0, GETPOSTINT('datestartmonth'), GETPOSTINT('datestartday'), GETPOSTINT('datestartyear')) : anychartlab_fiscal_year_start($to);

$entity = (int) $conf->entity;
$pcgversion = anychartlab_active_chart($db);
$accounts = ($pcgversion !== '' ? anychartlab_load_accounts($db, $entity, $pcgversion) : null);
$cf = ($accounts && $from <= $to ? anychartlab_cf_build($db, $entity, $accounts, $pcgversion, $from, $to) : null);
$rows = ($cf ? anychartlab_cf_rows($cf, $accounts, $view) : array());

if (in_array($action, array('export', 'pdf', 'pdfpreview')) && $cf) {
	$name = 'cash-flow-'.dol_print_date($from, '%Y-%m-%d').'-'.dol_print_date($to, '%Y-%m-%d');
	if ($action === 'export') {
		anychartlab_send_csv('anychartlab-'.$name, array('account', 'label', 'nature', 'note', 'amount'), $rows);
	}
	anychartlab_send_pdf($name, 'Cash Flow Statement', dol_print_date($from, 'day').' to '.dol_print_date($to, 'day').' · indirect method · amounts in '.$conf->currency, $rows, $action === 'pdfpreview');
}

$form = new Form($db);
llxHeader('', 'Cash Flow (Any-Chart Lab)', '', '', 0, 0, '', '', '', 'mod-anychartlab page-cashflow');
print load_fiche_titre('Cash Flow Statement', '', 'accountancy');
anychartlab_print_style_css();
anychartlab_print_nav('cashflow.php', $pcgversion);

if (!$accounts) {
	print '<div class="warning">No chart of accounts is selected.</div>';
	llxFooter();
	exit;
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="cfform">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="filtered" value="1">';
print 'From '.$form->selectDate($from, 'datestart', 0, 0, 0, 'cfform').' to '.$form->selectDate($to, 'dateend', 0, 0, 0, 'cfform').' ';
print 'View <select name="view" class="flat"><option value="detailed"'.($view === 'detailed' ? ' selected' : '').'>Detailed (sub-accounts under their parent)</option><option value="summary"'.($view === 'summary' ? ' selected' : '').'>Summary (parent accounts only)</option></select> ';
print '<input type="submit" class="button small" value="Refresh"> ';
print '<button type="submit" class="button small" name="action" value="export">Export CSV</button>';
print ' <button type="submit" class="button small" name="action" value="pdf">PDF</button>';
print ' <button type="submit" class="button small" name="action" value="pdfpreview" formtarget="_blank">Preview PDF</button>';
print '</form>';

if (!$cf) {
	print '<div class="warning">The start date must be before the end date.</div>';
	llxFooter();
	exit;
}

$setupUrl = dol_buildpath('/anychartlab/admin/setup.php', 1);
print '<p class="opacitymedium">Indirect method: the profit, then the movement of every other balance sheet account in its cash flow class (operating, investing, financing; set or check them on <a href="'.$setupUrl.'?search_nature=">Account natures</a>). Positive = cash in. Window: '.dol_escape_htmltag($cf['window']['label']).'. Amounts in '.dol_escape_htmltag($conf->currency).'.';
if ($cf['openingEntries']) {
	print ' '.$cf['openingEntries'].' opening-balance entries dated in the period (opening journal, bank initial balances) are counted as opening balances, not as cash flows.';
}
print '</p>';
foreach ($cf['window']['warnings'] as $w) {
	print '<div class="warning">'.dol_escape_htmltag($w).'</div>';
}
if ($cf['cut']) {
	print '<div class="warning">A fiscal year was closed inside this period: flows are shown from '.dol_print_date($cf['moveFrom'], 'day').'.</div>';
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Account</th><th>Label</th><th>Note</th><th class="right">Amount</th></tr>';
foreach ($rows as $r) {
	$c = $r['cells'];
	$kind = $r['kind'];
	if ($kind === 'section') {
		print '<tr class="liste_titre acl-section"><td colspan="4">'.dol_escape_htmltag($c[0]).'</td></tr>';
		continue;
	}
	$trClass = (in_array($kind, array('total', 'grandtotal')) ? 'liste_total' : 'oddeven');
	$isAcc = ($c[0] !== '' && isset($accounts['byNumber'][(string) $c[0]]) && in_array($kind, array('account', 'child')));
	print '<tr class="'.$trClass.' acl-'.$kind.'">';
	print '<td class="nowraponall">'.($isAcc ? '<a href="'.anychartlab_ledger_url($c[0]).'" target="_blank">'.dol_escape_htmltag($c[0]).'</a>' : dol_escape_htmltag($c[0])).'</td>';
	print '<td>'.dol_escape_htmltag($c[1]).'</td>';
	print '<td class="opacitymedium">'.dol_escape_htmltag($c[3]).'</td>';
	print '<td class="right amount nowraponall">'.((string) $c[4] === '' ? '' : anychartlab_price($c[4])).'</td>';
	print '</tr>';
}
print '</table>';

$ok = (abs($cf['difference']) < 0.005);
print '<br><div class="'.($ok ? 'ok' : 'error').'"><b>Check:</b> cash at the beginning '.anychartlab_price($cf['openingCash']).' + net cash flow '.anychartlab_price($cf['netFlow']).' = '.anychartlab_price($cf['openingCash'] + $cf['netFlow']).($ok ? ' = ' : ' ≠ ').'cash accounts at the end '.anychartlab_price($cf['closingCash']).'.'.($ok ? '' : ' The ledger may not balance for this period.').'</div>';
if ($cf['sections']['OTHER']) {
	print '<div class="warning">Some accounts with movements have no nature, are EXCLUDED or are not in the chart. They are listed in their own section so the statement still adds up; set their nature on <a href="'.$setupUrl.'?search_nature=_none">Account natures</a>.</div>';
}
if ($cf['clearing']) {
	print '<div class="warning">Clearing / suspense account(s) '.dol_escape_htmltag(implode(', ', $cf['clearing'])).' moved in the period: they are treated as operating, and should normally end at zero.</div>';
}

print '<h3>Cash accounts</h3>';
print '<table class="noborder centpercent"><tr class="liste_titre"><th>Account</th><th>Label</th><th>Counted as cash because</th><th class="right">Beginning</th><th class="right">End</th></tr>';
foreach ($cf['cash'] as $c) {
	print '<tr class="oddeven"><td><a href="'.anychartlab_ledger_url($c['number']).'" target="_blank">'.dol_escape_htmltag($c['number']).'</a></td><td>'.dol_escape_htmltag($c['label']).'</td>';
	print '<td class="opacitymedium">'.($c['source'] === 'set' ? 'set to "cash" on Account natures' : 'a Dolibarr bank / cash account (Banks / Cash)').'</td>';
	print '<td class="right amount">'.anychartlab_price($c['opening']).'</td><td class="right amount">'.anychartlab_price($c['closing']).'</td></tr>';
}
if (!$cf['cash']) {
	print '<tr><td colspan="5" class="opacitymedium">No cash account: link your bank accounts to their accounting account in Banks / Cash, or set accounts to "cash" on Account natures.</td></tr>';
}
print '<tr class="liste_total"><td colspan="3">Total cash</td><td class="right amount">'.anychartlab_price($cf['openingCash']).'</td><td class="right amount">'.anychartlab_price($cf['closingCash']).'</td></tr>';
print '</table>';

llxFooter();
$db->close();
