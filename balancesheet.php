<?php
/**
 * Any-Chart Reports Lab — Balance Sheet at a date, built from account natures
 * (design draft §6 on Dolibarr/dolibarr#31760):
 *  - window: entries since the last closed fiscal year (closure carried earlier
 *    years forward as opening entries), or all entries if none is closed;
 *  - each account placed by its nature, or its alternate nature when the balance
 *    is on the other side; contra accounts shown as deductions; clearing accounts
 *    placed by the sign of their balance and flagged;
 *  - income/expense accounts summed into "Result of unclosed periods" in equity;
 *  - balance check, with any difference explained by what was left out.
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
require_once __DIR__.'/lib/periods.lib.php';

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
$asof = GETPOSTINT('asofyear') ? dol_mktime(23, 59, 59, GETPOSTINT('asofmonth'), GETPOSTINT('asofday'), GETPOSTINT('asofyear')) : dol_now();

$columnsMode = GETPOST('columns', 'aZ09');
$columnsMode = (in_array($columnsMode, array('months', 'quarters', 'years')) ? $columnsMode : 'single');
$compare = GETPOST('compare', 'aZ09');
$compare = ($columnsMode === 'single' && in_array($compare, array('prevmonth', 'prevfy', 'lastyear')) ? $compare : 'none');
$matrix = ($columnsMode !== 'single' || $compare !== 'none');	// several amount columns
$colFrom = GETPOSTINT('colfromyear') ? dol_mktime(0, 0, 0, GETPOSTINT('colfrommonth'), GETPOSTINT('colfromday'), GETPOSTINT('colfromyear')) : anychartlab_fiscal_year_start($asof);

$entity = (int) $conf->entity;
$pcgversion = anychartlab_active_chart($db);
$accounts = ($pcgversion !== '' ? anychartlab_load_accounts($db, $entity, $pcgversion) : null);
$layoutId = GETPOSTINT('layout');
$layout = ($accounts && $layoutId ? anychartlab_layout_fetch($db, $layoutId, $entity) : null);
if ($layout && $layout->statement !== 'BS') {
	$layout = null;
}
$entries = null;
if ($accounts && $showEntries && $view === 'detailed' && !$matrix) {
	$w = anychartlab_window_start($db, $entity, $asof);
	$entries = anychartlab_load_entries($db, $entity, $w['start'], $asof);
}
$base = ($accounts ? anychartlab_bs_rows($db, $entity, $accounts, $asof, $layout, $view, $hideEmpty, $entries, $matrix) : null);
$bs = ($base ? $base['bs'] : null);
$laid = ($base ? $base['laid'] : null);

$titles = array('ASSET' => 'Assets', 'LIABILITY' => 'Liabilities', 'EQUITY' => 'Equity');

$hidden = ($laid && $hideEmpty ? anychartlab_layout_hidden($laid) : array());

// Several columns: the Balance Sheet at several dates, or at this date and another one
$matrixError = '';
$colDefs = array();
$merged = array();
$periodNote = '';
$windows = array();	// window label per column date, to say when they differ
if ($base && $matrix) {
	$lists = array($base['rows']);
	$windows[$base['bs']['window']['label']] = true;
	if ($columnsMode !== 'single') {
		$dates = ($colFrom < $asof ? anychartlab_bs_dates($colFrom, $asof, $columnsMode) : array());
		if (count($dates) > ANYCHARTLAB_MAX_PERIODS) {
			$matrixError = count($dates).' dates in this range: at most '.ANYCHARTLAB_MAX_PERIODS.' columns. Move the first date closer or choose larger periods.';
		} elseif (count($dates) < 2) {
			$matrixError = 'The first date must be before the "As of" date.';
		} else {
			foreach ($dates as $i => $d) {
				if ($i === count($dates) - 1) {
					$colDefs[] = array('label' => dol_print_date($asof, 'day'), 'title' => 'As of '.dol_print_date($asof, 'day'), 'src' => 0);
					continue;
				}
				$r = anychartlab_bs_rows($db, $entity, $accounts, $d, $layout, $view, $hideEmpty, null, true);
				$windows[$r['bs']['window']['label']] = true;
				$lists[] = $r['rows'];
				$colDefs[] = array('label' => dol_print_date($d, 'day'), 'title' => 'As of '.dol_print_date($d, 'day'), 'src' => count($lists) - 1);
			}
			$periodNote = 'at each '.array('months' => 'month', 'quarters' => 'fiscal quarter', 'years' => 'fiscal year')[$columnsMode].' end from '.dol_print_date($colFrom, 'day');
		}
	} else {
		$cd = anychartlab_bs_compare_date($asof, $compare);
		$r = anychartlab_bs_rows($db, $entity, $accounts, $cd, $layout, $view, $hideEmpty, null, true);
		$windows[$r['bs']['window']['label']] = true;
		$lists[] = $r['rows'];
		$colDefs = array(
			array('label' => dol_print_date($asof, 'day'), 'title' => 'As of '.dol_print_date($asof, 'day'), 'src' => 0),
			array('label' => dol_print_date($cd, 'day'), 'title' => 'As of '.dol_print_date($cd, 'day'), 'src' => 1),
			array('label' => 'Change', 'title' => 'Current minus comparison', 'src' => 'chg'),
			array('label' => 'Change %', 'title' => 'Change as a % of the comparison amount', 'src' => 'pct'),
		);
		$periodNote = 'compared with '.array('prevmonth' => 'the end of the previous month', 'prevfy' => 'the end of the last fiscal year', 'lastyear' => 'the same date last year')[$compare].' ('.dol_print_date($cd, 'day').')';
	}
	if ($matrixError === '') {
		$merged = anychartlab_merge_period_rows($lists);
	} else {
		$matrix = false;
		$base = anychartlab_bs_rows($db, $entity, $accounts, $asof, $layout, $view, $hideEmpty, $entries, false);
	}
}

if (in_array($action, array('export', 'pdf', 'pdfpreview')) && $bs && $matrix) {
	$name = 'balance-sheet-'.dol_print_date($asof, '%Y-%m-%d').($columnsMode !== 'single' ? '-'.$columnsMode : '-vs-'.$compare).($layout ? '-'.$layout->code : '');
	anychartlab_matrix_send($action, $name, ($layout ? $layout->label : 'Balance Sheet'), 'As of '.dol_print_date($asof, 'day').' · '.$periodNote.' · amounts in '.$conf->currency, $merged, $colDefs);
}

if (in_array($action, array('export', 'pdf', 'pdfpreview')) && $bs) {
	$rows = $base['rows'];
	$name = 'balance-sheet-'.dol_print_date($asof, '%Y-%m-%d').($layout ? '-'.$layout->code : '');
	if ($action === 'export') {
		anychartlab_send_csv('anychartlab-'.$name, array('account', 'label', 'nature', 'note', 'amount'), $rows);
	}
	anychartlab_send_pdf($name, ($layout ? $layout->label : 'Balance Sheet'), 'As of '.dol_print_date($asof, 'day').' · '.$bs['window']['label'].' · amounts in '.$conf->currency, $rows, $action === 'pdfpreview');
}

$form = new Form($db);
llxHeader('', 'Balance Sheet (Any-Chart Lab)', '', '', 0, 0, '', '', '', 'mod-anychartlab page-balancesheet');
print load_fiche_titre('Balance Sheet', '', 'accountancy');
anychartlab_print_style_css();
anychartlab_print_nav('balancesheet.php', $pcgversion);

if (!$bs) {
	print '<div class="warning">No chart of accounts is selected.</div>';
	llxFooter();
	exit;
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="bsform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="filtered" value="1">';
print 'As of '.$form->selectDate($asof, 'asof', 0, 0, 0, 'bsform').' ';
$layoutOptions = array(0 => 'By nature (no layout)');
foreach (anychartlab_layouts_list($db, $entity, 'BS') as $lo) {
	$layoutOptions[(int) $lo->rowid] = $lo->label;
}
print 'Layout '.$form->selectarray('layout', $layoutOptions, $layout ? (int) $layout->rowid : 0, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').' <a href="'.dol_buildpath('/anychartlab/admin/layouts.php', 1).($layout ? '?id='.$layout->rowid : '').'" title="Edit layouts">'.img_picto('', 'edit').'</a> ';
print 'View <select name="view" class="flat"><option value="detailed"'.($view === 'detailed' ? ' selected' : '').'>Detailed (sub-accounts under their parent)</option><option value="summary"'.($view === 'summary' ? ' selected' : '').'>Summary (parent accounts only)</option></select> ';
print '<label title="Lists the ledger entries under each account (Detailed view)"><input type="checkbox" name="show_entries" value="1"'.($showEntries ? ' checked' : '').'> Show entries</label> ';
print '<label title="With a layout: hides lines and sections with no account balance"><input type="checkbox" name="hide_empty" value="1"'.($hideEmpty ? ' checked' : '').'> Hide empty lines</label> ';
print '<label><input type="checkbox" name="show_draft" value="1"'.($showDraft ? ' checked' : '').'> Show design-draft codes</label> ';
print '<br>Columns <select name="columns" class="flat" id="acl_columns">';
foreach (array('single' => 'One date', 'months' => 'Month-ends', 'quarters' => 'Quarter-ends (fiscal)', 'years' => 'Fiscal year-ends') as $k => $v) {
	print '<option value="'.$k.'"'.($columnsMode === $k ? ' selected' : '').'>'.$v.'</option>';
}
print '</select> ';
print '<span id="acl_colfrom"'.($columnsMode === 'single' ? ' style="display:none"' : '').'>from '.$form->selectDate($colFrom, 'colfrom', 0, 0, 0, 'bsform').' </span>';
print 'Compare with <select name="compare" class="flat" id="acl_compare"'.($columnsMode !== 'single' ? ' disabled' : '').'>';
foreach (array('none' => 'Nothing', 'prevmonth' => 'End of previous month', 'prevfy' => 'End of last fiscal year', 'lastyear' => 'Same date last year') as $k => $v) {
	print '<option value="'.$k.'"'.($compare === $k ? ' selected' : '').'>'.$v.'</option>';
}
print '</select> ';
print '<script>jQuery(function(){jQuery("#acl_columns").on("change",function(){var one=(jQuery(this).val()==="single");jQuery("#acl_compare").prop("disabled",!one);jQuery("#acl_colfrom").toggle(!one);});});</script>';
print '<input type="submit" class="button small" value="Refresh"> ';
print '<button type="submit" class="button small" name="action" value="export">Export CSV</button>';
print ' <button type="submit" class="button small" name="action" value="pdf">PDF</button>';
print ' <button type="submit" class="button small" name="action" value="pdfpreview" formtarget="_blank">Preview PDF</button>';
print '</form>';
print '<p class="opacitymedium">Window: '.dol_escape_htmltag($bs['window']['label']).'. Amounts in '.dol_escape_htmltag($conf->currency).'.</p>';
foreach ($bs['window']['warnings'] as $w) {
	print '<div class="warning">'.dol_escape_htmltag($w).'</div>';
}
if ($matrixError !== '') {
	print '<div class="warning">'.dol_escape_htmltag($matrixError).'</div>';
}
if ($matrix) {
	print '<p class="opacitymedium">'.dol_escape_htmltag(ucfirst($periodNote)).'. Each column is built like the one-date Balance Sheet; an account with no balance at a date is left blank there.'.(count($windows) > 1 ? ' The columns do not all start from the same closed fiscal year: '.dol_escape_htmltag(implode('; ', array_keys($windows))).'.' : '').($showEntries && $view === 'detailed' ? ' Ledger entries are only listed in the one-date report.' : '').'</p>';
	if ($laid && $laid['errors']) {
		print '<div class="warning">'.implode('<br>', array_map('dol_escape_htmltag', $laid['errors'])).'</div>';
	}
	anychartlab_matrix_print($merged, $colDefs, $accounts);
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
	anychartlab_print_layout($laid, $bs['accounts'], $view, $showDraft, $entries, $hidden);
	print '</table>';
	anychartlab_print_unmatched($laid, '');
} else {
	print '<table class="noborder centpercent">';
	foreach ($titles as $code => $title) {
		print '<tr class="liste_titre acl-section"><th>'.$title.'</th><th>Label</th><th>Note</th>'.($showDraft ? '<th>Design-draft code</th>' : '').'<th class="right">Amount</th></tr>';
		anychartlab_print_groups(anychartlab_group_lines($bs['sections'][$code], $bs['accounts']), $view, $showDraft, $entries, $code);
		if ($code === 'EQUITY') {
			print '<tr class="oddeven acl-account"><td></td><td><b>Result of unclosed periods</b> <span class="opacitymedium">(income − expenses of '.$bs['result_lines'].' accounts in the window)</span></td><td></td>'.($showDraft ? '<td></td>' : '').'<td class="right amount">'.anychartlab_price($bs['result']).'</td></tr>';
			print '<tr class="liste_total acl-total"><td colspan="'.$cols.'">Total equity</td><td class="right amount">'.anychartlab_price($bs['totals']['EQUITY'] + $bs['result']).'</td></tr>';
		} else {
			print '<tr class="liste_total acl-total"><td colspan="'.$cols.'">Total '.strtolower($title).'</td><td class="right amount">'.anychartlab_price($bs['totals'][$code]).'</td></tr>';
		}
	}
	print '<tr class="liste_total acl-grandtotal"><td colspan="'.$cols.'">Total liabilities + equity</td><td class="right amount">'.anychartlab_price($bs['total_liab_equity']).'</td></tr>';
	print '</table>';
}

// Balance check (of the "As of" date; with several columns each one has its check line)
$ok = (abs($bs['difference']) < 0.005);
print '<br><div class="'.($ok ? 'ok' : 'error').'" style="font-weight:bold">';
if ($ok) {
	print 'Check: Assets = Liabilities + Equity. The Balance Sheet balances'.($matrix ? ' as of '.dol_print_date($asof, 'day') : '').'.';
} else {
	print 'Check failed: Assets − (Liabilities + Equity) = '.anychartlab_price($bs['difference']).'.';
	if (abs($bs['difference'] - $bs['explained_by']) < 0.005) {
		print ' This is exactly explained by the accounts left out below.';
	} else {
		print ' Only '.anychartlab_price($bs['explained_by']).' is explained by the accounts left out below; the ledger itself may not balance.';
	}
}
print '</div>';

if ($bs['unclassified'] || $bs['unknown'] || abs($bs['excluded']) >= 0.005) {
	print '<h3>Left out of the statement</h3><table class="noborder centpercent"><tr class="liste_titre"><th>Account</th><th>Label</th><th>Reason</th><th class="right">Balance (debit − credit)</th></tr>';
	foreach ($bs['unclassified'] as $u) {
		print '<tr class="oddeven"><td><a href="'.anychartlab_ledger_url($u['acc']->account_number).'" target="_blank">'.dol_escape_htmltag($u['acc']->account_number).'</a></td><td>'.dol_escape_htmltag($u['acc']->label).'</td><td><a href="'.dol_buildpath('/anychartlab/admin/setup.php', 1).'?search_account='.urlencode($u['acc']->account_number).'">unclassified: set its nature</a></td><td class="right amount">'.anychartlab_price($u['netdebit']).'</td></tr>';
	}
	foreach ($bs['unknown'] as $u) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($u['account_number']).'</td><td></td><td>not in the active chart</td><td class="right amount">'.anychartlab_price($u['netdebit']).'</td></tr>';
	}
	if (abs($bs['excluded']) >= 0.005) {
		print '<tr class="oddeven"><td></td><td>Accounts with nature EXCLUDED</td><td>excluded on purpose</td><td class="right amount">'.anychartlab_price($bs['excluded']).'</td></tr>';
	}
	print '</table>';
}
if ($bs['clearing']) {
	print '<div class="warning">'.count($bs['clearing']).' clearing / suspense account(s) carry a balance and are included above. They should normally be zero: see the <a href="'.dol_buildpath('/anychartlab/check.php', 1).'">classification check</a>.</div>';
}

llxFooter();
$db->close();
