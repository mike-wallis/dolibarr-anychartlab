<?php
/**
 * Any-Chart Reports Lab — classification check.
 *
 * Lists what would make the statements wrong or incomplete, as of a date:
 *  1. accounts with movements that have no nature (left out of both statements),
 *  2. clearing / suspense accounts that still carry a balance,
 *  3. accounts whose balance is on the opposite side of their nature with no
 *     alternate nature and no contra flag (often a misclassification, e.g.
 *     accumulated depreciation typed as a plain asset),
 *  4. ledger accounts that are not in the active chart at all.
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

if (!$user->hasRight('anychartlab', 'lire')) {
	accessforbidden();
}

$entity = (int) $conf->entity;
$pcgversion = anychartlab_active_chart($db);
$asof = GETPOSTINT('asofyear') ? dol_mktime(23, 59, 59, GETPOSTINT('asofmonth'), GETPOSTINT('asofday'), GETPOSTINT('asofyear')) : dol_now();

$form = new Form($db);
llxHeader('', 'Classification check', '', '', 0, 0, '', '', '', 'mod-anychartlab page-check');
print load_fiche_titre('Classification check', '', 'accountancy');
anychartlab_print_nav('check.php', $pcgversion);

if ($pcgversion === '') {
	print '<div class="warning">No chart of accounts is selected.</div>';
	llxFooter();
	exit;
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="checkform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print 'Balances as of '.$form->selectDate($asof, 'asof', 0, 0, 0, 'checkform').' <input type="submit" class="button small" value="Refresh">';
print '</form><br>';

$accounts = anychartlab_load_accounts($db, $entity, $pcgversion);
$window = anychartlab_window_start($db, $entity, $asof);
$balances = anychartlab_balances($db, $entity, $window['start'], $asof);
$setupUrl = dol_buildpath('/anychartlab/admin/setup.php', 1);

$unclassified = $clearing = $opposite = $unknown = array();
foreach ($balances as $number => $netdebit) {
	if (abs($netdebit) < 0.005) {
		continue;
	}
	$acc = $accounts['byNumber'][(string) $number] ?? null;
	if (!$acc) {
		$unknown[] = array((string) $number, $netdebit);
		continue;
	}
	if ($acc->nature === null) {
		$unclassified[] = array($acc, $netdebit);
		continue;
	}
	if ($acc->nature === 'CLEARING') {
		$clearing[] = array($acc, $netdebit);
		continue;
	}
	$side = anychartlab_normal_side($acc->nature);
	if ($side !== '' && $acc->nature_alt === null && !$acc->contra && (($side === 'D' && $netdebit < 0) || ($side === 'C' && $netdebit > 0))) {
		$opposite[] = array($acc, $netdebit);
	}
}

/**
 * @param string $title
 * @param string $help
 * @param array  $rows
 * @param string $setupUrl
 * @return void
 */
function anychartlab_check_section($title, $help, $rows, $setupUrl)
{
	print '<h3>'.$title.' <span class="badge '.($rows ? 'badge-status8' : 'badge-status4').'">'.count($rows).'</span></h3>';
	print '<p class="opacitymedium">'.$help.'</p>';
	if (!$rows) {
		print '<p>None.</p>';
		return;
	}
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>Account</th><th>Label</th><th>Group (pcg_type)</th><th>Nature</th><th class="right">Balance (debit − credit)</th><th></th></tr>';
	foreach ($rows as $r) {
		$acc = $r[0];
		print '<tr class="oddeven"><td><a href="'.anychartlab_ledger_url($acc->account_number).'" target="_blank">'.dol_escape_htmltag($acc->account_number).'</a></td>';
		print '<td>'.dol_escape_htmltag($acc->label).'</td><td>'.dol_escape_htmltag((string) $acc->pcg_type).'</td>';
		print '<td>'.dol_escape_htmltag((string) ($acc->nature ?? 'unclassified')).'</td>';
		print '<td class="right amount">'.anychartlab_price($r[1]).'</td>';
		print '<td><a href="'.$setupUrl.'?search_account='.urlencode($acc->account_number).'">Edit nature</a></td></tr>';
	}
	print '</table>';
}

print '<p>Window: '.dol_escape_htmltag($window['label']).'.</p>';
foreach ($window['warnings'] as $w) {
	print '<div class="warning">'.dol_escape_htmltag($w).'</div>';
}

anychartlab_check_section('1. Unclassified accounts with a balance', 'These are left out of both statements, so the Balance Sheet will not balance. Give them a nature.', $unclassified, $setupUrl);
anychartlab_check_section('2. Clearing / suspense accounts with a balance', 'Should normally be zero. They are included in the Balance Sheet on the side their balance falls, but flagged.', $clearing, $setupUrl);
anychartlab_check_section('3. Balance on the opposite side of the nature', 'An asset in credit or a liability in debit, for example. Often a misclassification: accumulated depreciation is a <b>contra</b> asset, a bank account that can be overdrawn needs an <b>alternate nature</b> of liability, GST/VAT can need one too.', $opposite, $setupUrl);

print '<h3>4. Ledger accounts not in the active chart <span class="badge '.($unknown ? 'badge-status8' : 'badge-status4').'">'.count($unknown).'</span></h3>';
if ($unknown) {
	print '<table class="noborder"><tr class="liste_titre"><th>Account</th><th class="right">Balance</th></tr>';
	foreach ($unknown as $u) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($u[0]).'</td><td class="right amount">'.anychartlab_price($u[1]).'</td></tr>';
	}
	print '</table>';
} else {
	print '<p>None.</p>';
}

llxFooter();
$db->close();
