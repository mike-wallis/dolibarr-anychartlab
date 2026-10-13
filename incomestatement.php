<?php
/**
 * Financial Reporting — Income Statement for a period, built from account
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
require_once __DIR__.'/lib/financialreporting.lib.php';
require_once __DIR__.'/lib/setup.lib.php';
require_once __DIR__.'/lib/layout.lib.php';
require_once __DIR__.'/lib/periods.lib.php';
require_once __DIR__.'/lib/wizard.lib.php';

$langs->loadLangs(array('accountancy', 'financialreporting@financialreporting'));

if (!isModEnabled('financialreporting')) {
	accessforbidden('Module Financial Reporting is not enabled (Setup > Modules).');
}
if (!$user->hasRight('financialreporting', 'lire')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$showDraft = GETPOSTINT('show_draft');
$filtered = GETPOSTINT('filtered');	// 1 once the form has been submitted; before that, the Display defaults apply
$view = (GETPOST('view', 'aZ09') !== '' ? GETPOST('view', 'aZ09') : financialreporting_cfg('DEFAULT_VIEW'));
$view = ($view === 'summary' ? 'summary' : 'detailed');
$showEntries = ($filtered ? GETPOSTINT('show_entries') : (int) financialreporting_cfg('DEFAULT_SHOW_ENTRIES'));
$hideEmpty = ($filtered ? GETPOSTINT('hide_empty') : (int) financialreporting_cfg('DEFAULT_HIDE_EMPTY'));
$to = GETPOSTINT('dateendyear') ? dol_mktime(23, 59, 59, GETPOSTINT('dateendmonth'), GETPOSTINT('dateendday'), GETPOSTINT('dateendyear')) : dol_now();
$from = GETPOSTINT('datestartyear') ? dol_mktime(0, 0, 0, GETPOSTINT('datestartmonth'), GETPOSTINT('datestartday'), GETPOSTINT('datestartyear')) : financialreporting_fiscal_year_start($to);

$columnsMode = GETPOST('columns', 'aZ09');
$columnsMode = (in_array($columnsMode, array('months', 'quarters', 'years')) ? $columnsMode : 'single');
$compare = GETPOST('compare', 'aZ09');
$compare = ($columnsMode === 'single' && in_array($compare, array('prev', 'lastyear')) ? $compare : 'none');
$matrix = ($columnsMode !== 'single' || $compare !== 'none');	// several amount columns

$entity = (int) $conf->entity;
$pcgversion = financialreporting_active_chart($db);
$accounts = ($pcgversion !== '' ? financialreporting_load_accounts($db, $entity, $pcgversion) : null);
// Layout: "core:<id>" = a Dolibarr personalised report (read live), "<id>" = an advanced layout of this module
$layoutParam = GETPOST('layout', 'alphanohtml');
$layout = null;
if ($accounts && preg_match('/^core:(\d+)$/', $layoutParam, $m)) {
	$layout = financialreporting_coreport_layout($db, $entity, (int) $m[1], $pcgversion);
} elseif ($accounts && (int) $layoutParam > 0) {
	$layout = financialreporting_layout_fetch($db, (int) $layoutParam, $entity);
}
if ($layout && $layout->statement !== 'IS') {
	$layout = null;
}
$entries = ($accounts && $showEntries && $view === 'detailed' && !$matrix ? financialreporting_load_entries($db, $entity, $from, $to) : null);
$base = ($accounts ? financialreporting_is_rows($db, $entity, $accounts, $from, $to, $layout, $view, $hideEmpty, $entries) : null);
$is = ($base ? $base['is'] : null);
$laid = ($base ? $base['laid'] : null);
$titles = array('INCOME' => 'Income', 'EXPENSE' => 'Expenses');

$hidden = ($laid && $hideEmpty ? financialreporting_layout_hidden($laid) : array());

// Several columns: periods side by side (+ total), or this period against another one
$matrixError = '';
$colDefs = array();	// each: label, title, src (index of the merged amounts, or 'chg' / 'pct')
$merged = array();
$periodNote = '';
if ($base && $matrix) {
	$lists = array($base['rows']);
	if ($columnsMode !== 'single') {
		$periods = financialreporting_split_periods($from, $to, $columnsMode);
		if (count($periods) > FINANCIALREPORTING_MAX_PERIODS) {
			$matrixError = count($periods).' '.$columnsMode.' in this range: at most '.FINANCIALREPORTING_MAX_PERIODS.' columns. Shorten the range or choose larger periods.';
		} else {
			foreach ($periods as $i => $p) {
				$lists[] = financialreporting_is_rows($db, $entity, $accounts, $p['from'], $p['to'], $layout, $view, $hideEmpty)['rows'];
				$colDefs[] = array('label' => $p['label'], 'title' => $p['title'], 'src' => $i + 1);
			}
			$colDefs[] = array('label' => 'Total', 'title' => dol_print_date($from, 'day').' to '.dol_print_date($to, 'day'), 'src' => 0);
			$periodNote = 'by '.substr($columnsMode, 0, -1);
		}
	} else {
		$cp = financialreporting_compare_period($from, $to, $compare);
		$lists[] = financialreporting_is_rows($db, $entity, $accounts, $cp['from'], $cp['to'], $layout, $view, $hideEmpty)['rows'];
		$colDefs = array(
			array('label' => financialreporting_period_label($from, $to), 'title' => dol_print_date($from, 'day').' to '.dol_print_date($to, 'day'), 'src' => 0),
			array('label' => financialreporting_period_label($cp['from'], $cp['to']), 'title' => dol_print_date($cp['from'], 'day').' to '.dol_print_date($cp['to'], 'day'), 'src' => 1),
			array('label' => 'Change', 'title' => 'Current minus comparison', 'src' => 'chg'),
			array('label' => 'Change %', 'title' => 'Change as a % of the comparison amount', 'src' => 'pct'),
		);
		$periodNote = 'compared with '.($compare === 'prev' ? 'the previous period' : 'the same period last year').' ('.dol_print_date($cp['from'], 'day').' to '.dol_print_date($cp['to'], 'day').')';
	}
	if ($matrixError === '') {
		$merged = financialreporting_merge_period_rows($lists);
	} else {
		$matrix = false;
	}
}

if (in_array($action, array('export', 'pdf', 'pdfpreview')) && $is && $matrix) {
	$name = 'income-statement-'.dol_print_date($from, '%Y-%m-%d').'-'.dol_print_date($to, '%Y-%m-%d').($columnsMode !== 'single' ? '-'.$columnsMode : '-vs-'.$compare).($layout ? '-'.$layout->code : '');
	financialreporting_matrix_send($action, $name, ($layout ? $layout->label : 'Income Statement'), dol_print_date($from, 'day').' to '.dol_print_date($to, 'day').' · '.$periodNote.' · amounts in '.$conf->currency, $merged, $colDefs);
}

if (in_array($action, array('export', 'pdf', 'pdfpreview')) && $is) {
	$rows = $base['rows'];
	$name = 'income-statement-'.dol_print_date($from, '%Y-%m-%d').'-'.dol_print_date($to, '%Y-%m-%d').($layout ? '-'.$layout->code : '');
	if ($action === 'export') {
		financialreporting_send_csv('financialreporting-'.$name, array('account', 'label', 'nature', 'note', 'amount'), $rows);
	}
	financialreporting_send_pdf($name, ($layout ? $layout->label : 'Income Statement'), dol_print_date($from, 'day').' to '.dol_print_date($to, 'day').' · amounts in '.$conf->currency, $rows, $action === 'pdfpreview');
}

$form = new Form($db);
llxHeader('', 'Income Statement (Financial Reporting)', '', '', 0, 0, '', '', '', 'mod-financialreporting page-incomestatement');
print load_fiche_titre('Income Statement', '', 'accountancy');
financialreporting_print_style_css();
financialreporting_print_nav('incomestatement.php', $pcgversion);
financialreporting_setup_gate($db, (int) $conf->entity, true);

if (!$is) {
	print '<div class="warning">No chart of accounts is selected.</div>';
	llxFooter();
	exit;
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="isform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="filtered" value="1">';
print 'From '.$form->selectDate($from, 'datestart', 0, 0, 0, 'isform').' to '.$form->selectDate($to, 'dateend', 0, 0, 0, 'isform').' ';
$current = ($layout ? (string) $layout->rowid : '0');
print 'Layout <select name="layout" class="flat minwidth200" id="layout"><option value="0">By nature (no layout)</option>';
$coreOptions = '';
foreach (financialreporting_coreports($db, $entity) as $r) {
	if ($r->statement === null || $r->statement === 'IS') {
		$coreOptions .= '<option value="core:'.((int) $r->rowid).'"'.($current === 'core:'.((int) $r->rowid) ? ' selected' : '').'>'.dol_escape_htmltag($r->label).'</option>';
	}
}
if ($coreOptions !== '') {
	print '<optgroup label="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepWizLayoutCore')).'">'.$coreOptions.'</optgroup>';
}
$advOptions = '';
foreach (financialreporting_layouts_list($db, $entity, 'IS') as $lo) {
	$advOptions .= '<option value="'.((int) $lo->rowid).'"'.($current === (string) ((int) $lo->rowid) ? ' selected' : '').'>'.dol_escape_htmltag($lo->label).'</option>';
}
if ($advOptions !== '') {
	print '<optgroup label="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepWizLayoutAdvanced')).'">'.$advOptions.'</optgroup>';
}
print '</select> ';
$editUrl = ($layout && $layout->source === 'core' ? dol_buildpath('/financialreporting/admin/wizard.php', 1) : dol_buildpath('/financialreporting/admin/layouts.php', 1).($layout ? '?id='.$layout->rowid : ''));
print '<a href="'.$editUrl.'" title="'.($layout && $layout->source === 'core' ? dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepWizTitle')) : 'Edit layouts').'">'.img_picto('', 'edit').'</a> ';
print 'View <select name="view" class="flat"><option value="detailed"'.($view === 'detailed' ? ' selected' : '').'>Detailed (sub-accounts under their parent)</option><option value="summary"'.($view === 'summary' ? ' selected' : '').'>Summary (parent accounts only)</option></select> ';
print '<label title="Lists the ledger entries under each account (Detailed view)"><input type="checkbox" name="show_entries" value="1"'.($showEntries ? ' checked' : '').'> Show entries</label> ';
print '<label title="With a layout: hides lines and sections with no account balance"><input type="checkbox" name="hide_empty" value="1"'.($hideEmpty ? ' checked' : '').'> Hide empty lines</label> ';
print '<label><input type="checkbox" name="show_draft" value="1"'.($showDraft ? ' checked' : '').'> Show design-draft codes</label> ';
print '<br>Columns <select name="columns" class="flat" id="fr_columns">';
foreach (array('single' => 'One column', 'months' => 'Months', 'quarters' => 'Quarters (fiscal)', 'years' => 'Fiscal years') as $k => $v) {
	print '<option value="'.$k.'"'.($columnsMode === $k ? ' selected' : '').'>'.$v.'</option>';
}
print '</select> ';
print 'Compare with <select name="compare" class="flat" id="fr_compare"'.($columnsMode !== 'single' ? ' disabled' : '').'>';
foreach (array('none' => 'Nothing', 'prev' => 'Previous period', 'lastyear' => 'Same period last year') as $k => $v) {
	print '<option value="'.$k.'"'.($compare === $k ? ' selected' : '').'>'.$v.'</option>';
}
print '</select> ';
print '<script>jQuery(function(){jQuery("#fr_columns").on("change",function(){jQuery("#fr_compare").prop("disabled",jQuery(this).val()!=="single");});});</script>';
print '<input type="submit" class="button small" value="Refresh"> ';
print '<button type="submit" class="button small" name="action" value="export">Export CSV</button>';
print ' <button type="submit" class="button small" name="action" value="pdf">PDF</button>';
print ' <button type="submit" class="button small" name="action" value="pdfpreview" formtarget="_blank">Preview PDF</button>';
print '</form>';
print '<p class="opacitymedium">Amounts in '.dol_escape_htmltag($conf->currency).'. Default period: current fiscal year to date.</p>';

if ($matrixError !== '') {
	print '<div class="warning">'.dol_escape_htmltag($matrixError).'</div>';
}
if ($matrix) {
	print '<p class="opacitymedium">'.dol_escape_htmltag(ucfirst($periodNote)).'. Each column is built like the one-column report; an account with no movement in a column is left blank there.'.($columnsMode !== 'single' ? ' * = part period (the range starts or ends inside it).' : '').($showEntries && $view === 'detailed' ? ' Ledger entries are only listed in the one-column report.' : '').'</p>';
	if ($laid && $laid['errors']) {
		print '<div class="warning">'.implode('<br>', array_map('dol_escape_htmltag', $laid['errors'])).'</div>';
	}
	financialreporting_matrix_print($merged, $colDefs, $accounts);
}

$cols = ($showDraft ? 4 : 3);
if ($matrix) {
	// already printed above
} elseif ($laid) {
	if ($laid['errors']) {
		print '<div class="warning">'.implode('<br>', array_map('dol_escape_htmltag', $laid['errors'])).'</div>';
	}
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>Account</th><th>Label</th><th>Note</th>'.($showDraft ? '<th>Design-draft code</th>' : '').'<th class="right">Amount</th></tr>';
	financialreporting_print_layout($laid, $is['accounts'], $view, $showDraft, $entries, $hidden);
	print '</table>';
	financialreporting_print_unmatched($laid, '');
} else {
	print '<table class="noborder centpercent">';
	foreach ($titles as $code => $title) {
		print '<tr class="liste_titre fr-section"><th>'.$title.'</th><th>Label</th><th>Note</th>'.($showDraft ? '<th>Design-draft code</th>' : '').'<th class="right">Amount</th></tr>';
		financialreporting_print_groups(financialreporting_group_lines($is['sections'][$code], $is['accounts']), $view, $showDraft, $entries, $code);
		print '<tr class="liste_total fr-total"><td colspan="'.$cols.'">Total '.strtolower($title).'</td><td class="right amount">'.financialreporting_price($is['totals'][$code]).'</td></tr>';
	}
	print '<tr class="liste_total fr-grandtotal"><td colspan="'.$cols.'">Net result (income − expenses)</td><td class="right amount">'.financialreporting_price($is['result']).'</td></tr>';
	print '</table>';
}

if ($is['unclassified'] || $is['unknown']) {
	print '<br><div class="warning">Some accounts with movements in the period are not classified, so they are <b>not</b> in the result above.</div>';
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>Account</th><th>Label</th><th>Reason</th><th class="right">Movement (debit − credit)</th></tr>';
	foreach ($is['unclassified'] as $u) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($u['acc']->account_number).'</td><td>'.dol_escape_htmltag($u['acc']->label).'</td><td><a href="'.dol_buildpath('/financialreporting/admin/setup.php', 1).'?search_account='.urlencode($u['acc']->account_number).'">unclassified: set its nature</a></td><td class="right amount">'.financialreporting_price($u['netdebit']).'</td></tr>';
	}
	foreach ($is['unknown'] as $u) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($u['account_number']).'</td><td></td><td>not in the active chart</td><td class="right amount">'.financialreporting_price($u['netdebit']).'</td></tr>';
	}
	print '</table>';
}

llxFooter();
$db->close();
