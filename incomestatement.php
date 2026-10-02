<?php
/**
 * Any-Chart Reports Lab — Income Statement for a period, built from account
 * natures: INCOME and EXPENSE accounts (or accounts whose alternate nature is
 * income/expense when their balance is on the other side). Accounts with
 * movements but no nature are listed separately instead of being dropped.
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
require_once __DIR__.'/lib/layout.lib.php';

if (!$user->hasRight('anychartlab', 'lire')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$showDraft = GETPOSTINT('show_draft');
$filtered = GETPOSTINT('filtered');	// 1 once the form has been submitted; before that, the Display defaults apply
$view = (GETPOST('view', 'aZ09') !== '' ? GETPOST('view', 'aZ09') : anychartlab_cfg('DEFAULT_VIEW'));
$view = ($view === 'summary' ? 'summary' : 'detailed');
$showEntries = ($filtered ? GETPOSTINT('show_entries') : (int) anychartlab_cfg('DEFAULT_SHOW_ENTRIES'));
$hideEmpty = ($filtered ? GETPOSTINT('hide_empty') : (int) anychartlab_cfg('DEFAULT_HIDE_EMPTY'));
$to = GETPOSTINT('dateendyear') ? dol_mktime(23, 59, 59, GETPOSTINT('dateendmonth'), GETPOSTINT('dateendday'), GETPOSTINT('dateendyear')) : dol_now();
$from = GETPOSTINT('datestartyear') ? dol_mktime(0, 0, 0, GETPOSTINT('datestartmonth'), GETPOSTINT('datestartday'), GETPOSTINT('datestartyear')) : anychartlab_fiscal_year_start($to);

$entity = (int) $conf->entity;
$pcgversion = anychartlab_active_chart($db);
$is = ($pcgversion !== '' ? anychartlab_build_income_statement($db, $entity, $pcgversion, $from, $to) : null);
$entries = ($is && $showEntries && $view === 'detailed' ? anychartlab_load_entries($db, $entity, $from, $to) : null);
$layoutId = GETPOSTINT('layout');
$layout = ($is && $layoutId ? anychartlab_layout_fetch($db, $layoutId, $entity) : null);
if ($layout && $layout->statement !== 'IS') {
	$layout = null;
}
$laid = null;
if ($layout) {
	$alllines = array();
	foreach (array('INCOME','EXPENSE') as $sec) {
		$alllines = array_merge($alllines, $is['sections'][$sec]);
	}
	$laid = anychartlab_apply_layout($layout, $alllines, array('RESULT' => $is['result']));
}
$titles = array('INCOME' => 'Income', 'EXPENSE' => 'Expenses');

$hidden = ($laid && $hideEmpty ? anychartlab_layout_hidden($laid) : array());

if (in_array($action, array('export', 'pdf', 'pdfpreview')) && $is) {
	$rows = array();
	if ($laid) {
		$rows = anychartlab_layout_csv($laid, $is['accounts'], $view, $entries, $hidden);
	if ($laid['unmatched']) {
		$rows[] = anychartlab_r(array('Not in layout', '', '', '', ''), 'section');
		$sum = 0.0;
		foreach ($laid['unmatched'] as $l) {
			$rows[] = anychartlab_r(array($l['acc']->account_number, $l['acc']->label, (string) $l['acc']->nature, 'placed as '.$l['section'], price2num($l['amount'], 'MT')), 'account');
			$sum += $l['amount'];
		}
		$rows[] = anychartlab_r(array('', 'Total not in layout', '', '', price2num($sum, 'MT')), 'total');
	}
	} else {
		foreach ($titles as $code => $title) {
			$rows[] = anychartlab_r(array($title, '', '', '', ''), 'section');
			$rows = array_merge($rows, anychartlab_groups_csv(anychartlab_group_lines($is['sections'][$code], $is['accounts']), $view, $entries, $code));
			$rows[] = anychartlab_r(array('', 'Total '.strtolower($title), '', '', price2num($is['totals'][$code], 'MT')), 'total');
		}
		$rows[] = anychartlab_r(array('', 'Net result (income - expenses)', '', '', price2num($is['result'], 'MT')), 'grandtotal');
	}
	$name = 'income-statement-'.dol_print_date($from, '%Y-%m-%d').'-'.dol_print_date($to, '%Y-%m-%d').($layout ? '-'.$layout->code : '');
	if ($action === 'export') {
		anychartlab_send_csv('anychartlab-'.$name, array('account', 'label', 'nature', 'note', 'amount'), $rows);
	}
	anychartlab_send_pdf($name, ($layout ? $layout->label : 'Income Statement'), dol_print_date($from, 'day').' to '.dol_print_date($to, 'day').' · amounts in '.$conf->currency, $rows, $action === 'pdfpreview');
}

$form = new Form($db);
llxHeader('', 'Income Statement (Any-Chart Lab)', '', '', 0, 0, '', '', '', 'mod-anychartlab page-incomestatement');
print load_fiche_titre('Income Statement', '', 'accountancy');
anychartlab_print_style_css();
anychartlab_print_nav('incomestatement.php', $pcgversion);

if (!$is) {
	print '<div class="warning">No chart of accounts is selected.</div>';
	llxFooter();
	exit;
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="isform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="filtered" value="1">';
print 'From '.$form->selectDate($from, 'datestart', 0, 0, 0, 'isform').' to '.$form->selectDate($to, 'dateend', 0, 0, 0, 'isform').' ';
$layoutOptions = array(0 => 'By nature (no layout)');
foreach (anychartlab_layouts_list($db, $entity, 'IS') as $lo) {
	$layoutOptions[(int) $lo->rowid] = $lo->label;
}
print 'Layout '.$form->selectarray('layout', $layoutOptions, $layout ? (int) $layout->rowid : 0, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').' <a href="'.dol_buildpath('/anychartlab/admin/layouts.php', 1).($layout ? '?id='.$layout->rowid : '').'" title="Edit layouts">'.img_picto('', 'edit').'</a> ';
print 'View <select name="view" class="flat"><option value="detailed"'.($view === 'detailed' ? ' selected' : '').'>Detailed (sub-accounts under their parent)</option><option value="summary"'.($view === 'summary' ? ' selected' : '').'>Summary (parent accounts only)</option></select> ';
print '<label title="Lists the ledger entries under each account (Detailed view)"><input type="checkbox" name="show_entries" value="1"'.($showEntries ? ' checked' : '').'> Show entries</label> ';
print '<label title="With a layout: hides lines and sections with no account balance"><input type="checkbox" name="hide_empty" value="1"'.($hideEmpty ? ' checked' : '').'> Hide empty lines</label> ';
print '<label><input type="checkbox" name="show_draft" value="1"'.($showDraft ? ' checked' : '').'> Show design-draft codes</label> ';
print '<input type="submit" class="button small" value="Refresh"> ';
print '<button type="submit" class="button small" name="action" value="export">Export CSV</button>';
print ' <button type="submit" class="button small" name="action" value="pdf">PDF</button>';
print ' <button type="submit" class="button small" name="action" value="pdfpreview" formtarget="_blank">Preview PDF</button>';
print '</form>';
print '<p class="opacitymedium">Amounts in '.dol_escape_htmltag($conf->currency).'. Default period: current fiscal year to date.</p>';

$cols = ($showDraft ? 4 : 3);
if ($laid) {
	if ($laid['errors']) {
		print '<div class="warning">'.implode('<br>', array_map('dol_escape_htmltag', $laid['errors'])).'</div>';
	}
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>Account</th><th>Label</th><th>Note</th>'.($showDraft ? '<th>Design-draft code</th>' : '').'<th class="right">Amount</th></tr>';
	anychartlab_print_layout($laid, $is['accounts'], $view, $showDraft, $entries, $hidden);
	print '</table>';
	anychartlab_print_unmatched($laid, '');
} else {
	print '<table class="noborder centpercent">';
	foreach ($titles as $code => $title) {
		print '<tr class="liste_titre acl-section"><th>'.$title.'</th><th>Label</th><th>Note</th>'.($showDraft ? '<th>Design-draft code</th>' : '').'<th class="right">Amount</th></tr>';
		anychartlab_print_groups(anychartlab_group_lines($is['sections'][$code], $is['accounts']), $view, $showDraft, $entries, $code);
		print '<tr class="liste_total acl-total"><td colspan="'.$cols.'">Total '.strtolower($title).'</td><td class="right amount">'.anychartlab_price($is['totals'][$code]).'</td></tr>';
	}
	print '<tr class="liste_total acl-grandtotal"><td colspan="'.$cols.'">Net result (income − expenses)</td><td class="right amount">'.anychartlab_price($is['result']).'</td></tr>';
	print '</table>';
}

if ($is['unclassified'] || $is['unknown']) {
	print '<br><div class="warning">Some accounts with movements in the period are not classified, so they are <b>not</b> in the result above.</div>';
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>Account</th><th>Label</th><th>Reason</th><th class="right">Movement (debit − credit)</th></tr>';
	foreach ($is['unclassified'] as $u) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($u['acc']->account_number).'</td><td>'.dol_escape_htmltag($u['acc']->label).'</td><td><a href="'.dol_buildpath('/anychartlab/admin/setup.php', 1).'?search_account='.urlencode($u['acc']->account_number).'">unclassified: set its nature</a></td><td class="right amount">'.anychartlab_price($u['netdebit']).'</td></tr>';
	}
	foreach ($is['unknown'] as $u) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($u['account_number']).'</td><td></td><td>not in the active chart</td><td class="right amount">'.anychartlab_price($u['netdebit']).'</td></tr>';
	}
	print '</table>';
}

llxFooter();
$db->close();
