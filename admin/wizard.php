<?php
/**
 * Financial Reporting — report setup wizard.
 *
 * Builds an income statement as a new Dolibarr personalised report, one template section
 * at a time (see lib/wizard.lib.php). The report in progress is kept in the session until
 * it is saved. Also lists the personalised reports, with Re-apply rules and Delete for the
 * ones the wizard built.
 */

$res = 0;
if (!$res && is_file('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && is_file('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
require_once dirname(__DIR__).'/lib/financialreporting.lib.php';
require_once dirname(__DIR__).'/lib/layout.lib.php';
require_once dirname(__DIR__).'/lib/wizard.lib.php';

$langs->loadLangs(array('accountancy', 'financialreporting@financialreporting'));

if (!isModEnabled('financialreporting')) {
	accessforbidden('Module Financial Reporting is not enabled (Setup > Modules).');
}
if (!$user->hasRight('financialreporting', 'setup') && !$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$step = GETPOSTINT('step');
$reportId = GETPOSTINT('report');
$entity = (int) $conf->entity;
$pcgversion = financialreporting_active_chart($db);
$self = $_SERVER['PHP_SELF'];
$sessKey = 'financialreporting_wizard';
$draft = (isset($_SESSION[$sessKey]) && is_array($_SESSION[$sessKey]) ? $_SESSION[$sessKey] : null);
$nbSteps = ($draft ? count($draft['sections']) : 0);

/*
 * Actions
 */

if ($action === 'start' && $pcgversion !== '') {
	$tpl = financialreporting_template_read(GETPOST('template', 'alphanohtml'));
	if ($tpl && $tpl['sections']) {
		$_SESSION[$sessKey] = array(
			'template' => $tpl['file'],
			'statement' => $tpl['statement'],
			'label' => (trim(GETPOST('label', 'alphanohtml')) !== '' ? trim(GETPOST('label', 'alphanohtml')) : $tpl['label']),
			'sections' => $tpl['sections'],
		);
		header('Location: '.$self.'?step=1');
		exit;
	}
	setEventMessages($langs->trans('ErrorFileNotFound', GETPOST('template', 'alphanohtml')), null, 'errors');
	header('Location: '.$self);
	exit;
}

if ($action === 'cancel') {
	unset($_SESSION[$sessKey]);
	header('Location: '.$self);
	exit;
}

if ($action === 'savestep' && $draft && $step >= 1 && $step <= $nbSteps) {
	$s = &$draft['sections'][$step - 1];
	$lines = array();
	foreach ($s['lines'] as $i => $l) {
		if (GETPOST('del_'.$i, 'int')) {
			continue;
		}
		$l['code'] = GETPOST('code_'.$i, 'alphanohtml');
		$l['label'] = GETPOST('label_'.$i, 'alphanohtml');
		if ($l['type'] === 'group') {
			$l['accounts'] = GETPOST('accounts_'.$i, 'alphanohtml');
		} else {
			$l['formula'] = GETPOST('formula_'.$i, 'alphanohtml');
		}
		$lines[] = financialreporting_wizard_clean_line($l, $l['stype']);
	}
	if (trim(GETPOST('label_ng', 'alphanohtml')) !== '' || trim(GETPOST('code_ng', 'alphanohtml')) !== '') {
		// a new group goes after the section's last group, before its formula lines
		$new = financialreporting_wizard_clean_line(array('code' => GETPOST('code_ng', 'alphanohtml'), 'label' => GETPOST('label_ng', 'alphanohtml'), 'type' => 'group', 'accounts' => GETPOST('accounts_ng', 'alphanohtml')), $s['type']);
		$at = 0;
		foreach ($lines as $k => $l) {
			if ($l['type'] === 'group') {
				$at = $k + 1;
			}
		}
		array_splice($lines, $at, 0, array($new));
	}
	if (trim(GETPOST('label_nf', 'alphanohtml')) !== '' || trim(GETPOST('code_nf', 'alphanohtml')) !== '') {
		$lines[] = financialreporting_wizard_clean_line(array('code' => GETPOST('code_nf', 'alphanohtml'), 'label' => GETPOST('label_nf', 'alphanohtml'), 'type' => 'formula', 'formula' => GETPOST('formula_nf', 'alphanohtml')), '');
	}
	$s['lines'] = $lines;
	if (trim(GETPOST('section_label', 'alphanohtml')) !== '') {
		$s['label'] = trim(GETPOST('section_label', 'alphanohtml'));
	}
	unset($s);
	if ($step === 1 && trim(GETPOST('report_label', 'alphanohtml')) !== '') {
		$draft['label'] = trim(GETPOST('report_label', 'alphanohtml'));
	}
	$_SESSION[$sessKey] = $draft;
	$go = GETPOST('go', 'aZ09');
	$next = ($go === 'next' ? $step + 1 : ($go === 'prev' ? $step - 1 : $step));
	header('Location: '.$self.($next >= 1 ? '?step='.$next : ''));
	exit;
}

if ($action === 'save' && $draft && $pcgversion !== '') {
	$accounts = financialreporting_load_accounts($db, $entity, $pcgversion);
	$assign = financialreporting_wizard_assign($draft, $accounts);
	$errors = financialreporting_wizard_errors($draft);
	$hasWarnings = ($assign['unassigned'] || $assign['unclassified'] || financialreporting_wizard_unused_groups($draft) || GETPOSTINT('mismatch'));
	if ($errors || ($hasWarnings && !GETPOSTINT('accept'))) {
		header('Location: '.$self.'?step='.($nbSteps + 1));
		exit;
	}
	$newId = financialreporting_wizard_save($db, $user, $entity, $draft, $assign);
	if ($newId > 0) {
		unset($_SESSION[$sessKey]);
		setEventMessages($langs->trans('FinRepWizSaved'), null);
		header('Location: '.dol_buildpath('/financialreporting/incomestatement.php', 1).'?layout=core:'.$newId);
		exit;
	}
	setEventMessages($langs->trans('FinRepWizSaveError', $newId), null, 'errors');
	header('Location: '.$self.'?step='.($nbSteps + 1));
	exit;
}

if ($action === 'reapply' && $reportId && $pcgversion !== '') {
	$n = financialreporting_coreport_reapply($db, $entity, $reportId, financialreporting_load_accounts($db, $entity, $pcgversion));
	setEventMessages($n >= 0 ? $langs->trans('FinRepWizReapplied', $n) : $langs->trans('Error'), null, $n >= 0 ? 'mesgs' : 'errors');
	header('Location: '.$self);
	exit;
}

if ($action === 'confirm_delete' && GETPOST('confirm', 'alpha') === 'yes' && $reportId) {
	$r = financialreporting_coreport_delete($db, $entity, $reportId);
	setEventMessages($r > 0 ? $langs->trans('FinRepWizDeleted') : ($r === 0 ? $langs->trans('FinRepWizDeleteRefused') : $langs->trans('Error')), null, $r > 0 ? 'mesgs' : 'errors');
	header('Location: '.$self);
	exit;
}

/*
 * View
 */

$form = new Form($db);
llxHeader('', $langs->trans('FinRepWizTitle'), '', '', 0, 0, '', '', '', 'mod-financialreporting page-wizard');
print load_fiche_titre($langs->trans('FinRepWizTitle'), '', 'accountancy');
financialreporting_print_style_css();
financialreporting_print_nav('admin/wizard.php', $pcgversion);
print '<style>
.frwiz-steps{margin:6px 0 12px} .frwiz-steps span{display:inline-block;padding:3px 10px;margin:2px 4px 2px 0;border-radius:12px;background:var(--colorbacklinepair2,#eee)}
.frwiz-steps span.cur{font-weight:bold;outline:2px solid var(--colortextlink,#2a6)} .frwiz-steps span.done{opacity:.7}
.frwiz-panel{margin-top:14px;padding:8px 12px;border:1px solid var(--colortopbordertitle1,#ddd);border-radius:6px}
.frwiz-panel details{margin:4px 0} .frwiz-panel summary{cursor:pointer}
.frwiz-acc{font-size:.9em;columns:2 220px;margin:4px 0 8px 0;padding-left:18px} .frwiz-notes{margin:6px 0 4px 22px;padding:6px 10px;border-left:3px solid #ccc}
.frwiz-badge{font-size:.85em;padding:1px 7px;border-radius:9px;background:#f0e6c8;color:#5a4a00;margin-left:6px} .frwiz-badge.ok{background:#d8f0d8;color:#1d5a1d}
</style>';

if ($pcgversion === '') {
	print '<div class="warning">'.$langs->trans('FinRepWizNoChart').'</div>';
	llxFooter();
	exit;
}

$accounts = financialreporting_load_accounts($db, $entity, $pcgversion);

/**
 * Print the step chips: one per section, then Check.
 *
 * @param array $draft
 * @param int   $current 1..n sections, n+1 = check
 * @return void
 */
function financialreporting_wizard_print_steps($draft, $current)
{
	global $langs;
	$n = count($draft['sections']);
	print '<div class="frwiz-steps"><b>'.dol_escape_htmltag($draft['label']).'</b> · ';
	foreach ($draft['sections'] as $i => $s) {
		$k = $i + 1;
		print '<span class="'.($k === $current ? 'cur' : ($k < $current ? 'done' : '')).'"><a href="'.$_SERVER['PHP_SELF'].'?step='.$k.'">'.$k.'. '.dol_escape_htmltag($s['label']).'</a></span>';
	}
	print '<span class="'.($current === $n + 1 ? 'cur' : '').'"><a href="'.$_SERVER['PHP_SELF'].'?step='.($n + 1).'">'.($n + 1).'. '.$langs->trans('FinRepWizStepCheck').'</a></span></div>';
}

/**
 * Compact list of accounts.
 *
 * @param int[]|object[] $list    Account rowids or account objects
 * @param array          $accounts
 * @return void
 */
function financialreporting_wizard_print_accounts($list, $accounts)
{
	global $langs;
	if (!$list) {
		print '<div class="opacitymedium" style="margin:2px 0 8px 18px">'.$langs->trans('FinRepWizNoAccount').'</div>';
		return;
	}
	print '<ul class="frwiz-acc">';
	foreach ($list as $a) {
		$acc = (is_object($a) ? $a : ($accounts['byRowid'][$a] ?? null));
		if ($acc) {
			print '<li>'.dol_escape_htmltag($acc->account_number.' '.$acc->label).($acc->active ? '' : ' <span class="opacitymedium">('.$langs->trans('FinRepWizInactive').')</span>').'</li>';
		}
	}
	print '</ul>';
}

// ---- Start page: reports, then a new report from a template ----
if (!$draft || $step < 1 || $step > $nbSteps + 1) {
	if ($action === 'delete' && $reportId) {
		$reps = financialreporting_coreports($db, $entity);
		print $form->formconfirm($self.'?report='.$reportId, $langs->trans('FinRepWizDelete'), $langs->trans('FinRepWizConfirmDelete', isset($reps[$reportId]) ? $reps[$reportId]->label : $reportId), 'confirm_delete', '', 0, 1);
	}
	if ($step >= 1 && !$draft) {
		print '<div class="warning">'.$langs->trans('FinRepWizNoDraft').'</div>';
	}
	print '<div class="info">'.$langs->trans('FinRepWizIntro').'<br><br>'.$langs->trans('FinRepWizIntroSign').'<br><br>'.$langs->trans('FinRepWizIntroWrites').'</div>';

	if ($draft) {
		print '<div class="warning">'.$langs->trans('FinRepWizInProgress', dol_escape_htmltag($draft['label'])).' <a class="button small" href="'.$self.'?step=1">'.$langs->trans('FinRepWizContinue').'</a> <a href="'.$self.'?action=cancel&token='.newToken().'">'.$langs->trans('FinRepWizDiscard').'</a></div>';
	}

	// Existing personalised reports
	print load_fiche_titre($langs->trans('FinRepWizExisting'), '', '');
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('Label').'</th><th>'.$langs->trans('Code').'</th><th>'.$langs->trans('FinRepWizBuiltBy').'</th><th class="center">'.$langs->trans('FinRepWizGroups').'</th><th></th></tr>';
	$reps = financialreporting_coreports($db, $entity);
	foreach ($reps as $r) {
		$isWizard = ($r->statement !== null);
		print '<tr class="oddeven"><td><b>'.dol_escape_htmltag($r->label).'</b></td><td>'.dol_escape_htmltag($r->code).'</td>';
		print '<td>'.($isWizard ? $langs->trans('FinRepWizBuiltWizard', dol_escape_htmltag((string) $r->template)) : $langs->trans('FinRepWizBuiltDolibarr')).'</td><td class="center">'.((int) $r->nbcat).'</td>';
		print '<td class="right nowraponall"><a href="'.dol_buildpath('/financialreporting/incomestatement.php', 1).'?layout=core:'.((int) $r->rowid).'">'.$langs->trans('FinRepWizView').'</a>';
		if ($isWizard) {
			print ' · <a href="'.$self.'?action=reapply&report='.((int) $r->rowid).'&token='.newToken().'" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepWizReapplyHelp')).'">'.$langs->trans('FinRepWizReapply').'</a>';
			print ' · <a href="'.$self.'?action=delete&report='.((int) $r->rowid).'&token='.newToken().'">'.$langs->trans('FinRepWizDelete').'</a>';
		}
		print '</td></tr>';
	}
	if (!$reps) {
		print '<tr><td colspan="5" class="opacitymedium">'.$langs->trans('FinRepWizNoReport').'</td></tr>';
	}
	print '</table>';
	print '<p class="opacitymedium">'.$langs->trans('FinRepWizCoreGap').'</p>';

	// New report from a template
	print load_fiche_titre($langs->trans('FinRepWizStart'), '', '');
	print '<form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="start">';
	print '<p><b>'.$langs->trans('FinRepWizChooseTemplate').'</b> <span class="opacitymedium">(★ = '.$langs->trans('FinRepWizSuggested').')</span></p>';
	$files = financialreporting_template_files($pcgversion);
	$checked = '';
	foreach ($files as $f) {
		if ($checked === '' && $f['match'] !== '') {
			$checked = $f['file'];
		}
	}
	if ($checked === '' && $files) {
		$checked = $files[0]['file'];
	}
	foreach ($files as $f) {
		$tpl = financialreporting_template_read($f['file']);
		if (!$tpl || $tpl['statement'] !== 'IS') {
			continue;	// balance sheet templates: phase 2
		}
		$review = ($tpl['review'] !== '' ? $tpl['review'] : 'unreviewed');
		$id = 'tpl_'.dol_escape_htmltag(preg_replace('/[^A-Za-z0-9]/', '_', $f['file']));
		print '<div style="margin:6px 0"><label for="'.$id.'"><input type="radio" name="template" id="'.$id.'" value="'.dol_escape_htmltag($f['file']).'"'.($f['file'] === $checked ? ' checked' : '').'> ';
		print ($f['match'] !== '' ? '★ ' : '').'<b>'.dol_escape_htmltag($f['label']).'</b></label>';
		print '<span class="frwiz-badge'.($review === 'accountant' ? ' ok' : '').'">'.$langs->trans('FinRepWizReview_'.$review).'</span>';
		$notes = financialreporting_template_notes($f['file']);
		if ($notes !== '') {
			$country = $f['label'];
			print '<details class="frwiz-notes"'.($f['file'] === $checked ? ' open' : '').'><summary>'.$langs->trans('FinRepWizCountryNotes').'</summary>';
			print '<div class="warning" style="margin:6px 0">'.$langs->trans('FinRepWizCaution', dol_escape_htmltag($country), dol_print_date(filemtime(financialreporting_seed_dir().'/templates/'.preg_replace('/\.csv$/', '', $f['file']).'.notes.md'), 'day')).'</div>';
			print $notes.'</details>';
		}
		print '</div>';
	}
	print '<p>'.$langs->trans('FinRepWizReportName').' <input type="text" name="label" class="flat minwidth300" placeholder="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepWizReportNamePlaceholder')).'"> ';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepWizStartButton')).'"></p></form>';

	llxFooter();
	$db->close();
	exit;
}

$assign = financialreporting_wizard_assign($draft, $accounts);
financialreporting_wizard_print_steps($draft, $step);

// ---- Section steps ----
if ($step <= $nbSteps) {
	$s = $draft['sections'][$step - 1];
	$wanted = ($s['type'] !== '' ? array($s['type']) : financialreporting_wizard_statement_natures($draft['statement']));
	print '<form method="POST" action="'.$self.'?step='.$step.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savestep">';
	print '<h3 style="margin-bottom:4px">'.$langs->trans('FinRepWizStep', $step, $nbSteps + 1).' · '.$langs->trans('FinRepWizSection').' <input type="text" name="section_label" class="flat minwidth200" value="'.dol_escape_htmltag($s['label']).'"></h3>';
	if ($step === 1) {
		print '<p>'.$langs->trans('FinRepWizReportName').' <input type="text" name="report_label" class="flat minwidth300" value="'.dol_escape_htmltag($draft['label']).'"></p>';
	}
	if ($s['type'] !== '') {
		print '<p class="opacitymedium">'.$langs->trans('FinRepWizSectionSign_'.$s['type']).'</p>';
	}

	$unused = array_intersect(financialreporting_wizard_unused_groups($draft), array_column(array_filter($s['lines'], function ($l) {
		return $l['type'] === 'group';
	}), 'code'));
	if ($unused) {
		print '<div class="warning">'.$langs->trans('FinRepWizWarnUnused', dol_escape_htmltag(implode(', ', $unused))).'</div>';
	}
	// Groups
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('FinRepWizCode').'</th><th>'.$langs->trans('FinRepWizLabel').'</th><th>'.$langs->trans('FinRepWizPrefixes').'</th><th class="center">'.$langs->trans('FinRepWizTakes').'</th><th class="center">'.$langs->trans('Delete').'</th></tr>';
	foreach ($s['lines'] as $i => $l) {
		if ($l['type'] !== 'group') {
			continue;
		}
		$extra = ($l['natures'] !== '' && $l['natures'] !== $l['stype'] ? ' <span class="opacitymedium" title="'.dol_escape_htmltag($l['natures']).'">('.dol_escape_htmltag(strtolower(str_replace('|', ' + ', $l['natures']))).')</span>' : '');
		print '<tr class="oddeven"><td><input type="text" name="code_'.$i.'" class="flat width100" maxlength="16" value="'.dol_escape_htmltag($l['code']).'"></td>';
		print '<td><input type="text" name="label_'.$i.'" class="flat minwidth200" value="'.dol_escape_htmltag($l['label']).'">'.$extra.'</td>';
		print '<td><input type="text" name="accounts_'.$i.'" class="flat minwidth100" value="'.dol_escape_htmltag($l['accounts']).'" placeholder="catch-all"></td>';
		print '<td class="center">'.count($assign['byLine'][$l['code']] ?? array()).'</td>';
		print '<td class="center"><input type="checkbox" name="del_'.$i.'" value="1"></td></tr>';
	}
	print '<tr class="oddeven"><td><input type="text" name="code_ng" class="flat width100" maxlength="16" placeholder="NEW"></td><td><input type="text" name="label_ng" class="flat minwidth200" placeholder="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepWizAddGroup')).'"></td><td><input type="text" name="accounts_ng" class="flat minwidth100" placeholder="e.g. 41,42"></td><td></td><td></td></tr>';
	print '</table></div>';
	print '<p class="opacitymedium">'.$langs->trans('FinRepWizPrefixHelp').'</p>';

	// Formula lines closing the section
	print '<b>'.$langs->trans('FinRepWizFormulasTitle').'</b>';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('FinRepWizCode').'</th><th>'.$langs->trans('FinRepWizLabel').'</th><th>'.$langs->trans('FinRepWizFormula').'</th><th class="center">'.$langs->trans('Delete').'</th></tr>';
	$nbf = 0;
	foreach ($s['lines'] as $i => $l) {
		if ($l['type'] !== 'formula') {
			continue;
		}
		$nbf++;
		print '<tr class="oddeven"><td><input type="text" name="code_'.$i.'" class="flat width100" maxlength="16" value="'.dol_escape_htmltag($l['code']).'"></td>';
		print '<td><input type="text" name="label_'.$i.'" class="flat minwidth200" value="'.dol_escape_htmltag($l['label']).'"></td>';
		print '<td><input type="text" name="formula_'.$i.'" class="flat minwidth200" value="'.dol_escape_htmltag($l['formula']).'"></td>';
		print '<td class="center"><input type="checkbox" name="del_'.$i.'" value="1"></td></tr>';
	}
	print '<tr class="oddeven"><td><input type="text" name="code_nf" class="flat width100" maxlength="16" placeholder="NEW"></td><td><input type="text" name="label_nf" class="flat minwidth200" placeholder="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepWizAddFormula')).'"></td><td><input type="text" name="formula_nf" class="flat minwidth200" placeholder="e.g. REVENUE-COGS"></td><td></td></tr>';
	print '</table></div>';
	print '<p class="opacitymedium">'.$langs->trans('FinRepWizFormulaHelp').'</p>';
	print '<div class="frwiz-panel">';

	// Accounts taken in this section, then accounts of its kind in no group
	print '<b>'.$langs->trans('FinRepWizAccountsInStep').'</b>';
	foreach ($s['lines'] as $l) {
		if ($l['type'] === 'group') {
			$ids = $assign['byLine'][$l['code']] ?? array();
			print '<details'.(count($ids) <= 12 ? ' open' : '').'><summary>'.dol_escape_htmltag($l['code'].' · '.$l['label']).' <span class="opacitymedium">('.count($ids).')</span></summary>';
			financialreporting_wizard_print_accounts($ids, $accounts);
			print '</details>';
		}
	}
	$unassigned = array_filter($assign['unassigned'], function ($acc) use ($wanted) {
		return in_array($acc->nature, $wanted, true);
	});
	print '<br><b>'.$langs->trans('FinRepWizUnassigned').'</b> <span class="opacitymedium">('.count($unassigned).')</span>';
	if ($unassigned) {
		print '<div class="opacitymedium">'.$langs->trans('FinRepWizUnassignedHelp').'</div>';
		financialreporting_wizard_print_accounts(array_values($unassigned), $accounts);
	} else {
		print '<div class="ok" style="margin-top:4px">'.$langs->trans('FinRepWizAllAssigned').'</div>';
	}
	print '</div>';

	print '<div class="center" style="margin:14px 0">';
	print '<button type="submit" class="button" name="go" value="prev">← '.$langs->trans('FinRepWizBack').'</button> ';
	print '<button type="submit" class="button" name="go" value="refresh">'.$langs->trans('FinRepWizRefresh').'</button> ';
	print '<button type="submit" class="button button-save" name="go" value="next">'.$langs->trans('FinRepWizNext').' →</button>';
	print ' &nbsp; <a href="'.$self.'?action=cancel&token='.newToken().'">'.$langs->trans('FinRepWizCancel').'</a></div>';
	print '</form>';

	llxFooter();
	$db->close();
	exit;
}

// ---- Check and save ----
$errors = financialreporting_wizard_errors($draft);
$layout = financialreporting_wizard_layout($draft, $assign);
$to = dol_now();
$from = financialreporting_fiscal_year_start($to);
$is = financialreporting_is_from_balances($accounts, financialreporting_balances($db, $entity, $from, $to));
$laid = financialreporting_apply_layout($layout, array_merge($is['sections']['INCOME'], $is['sections']['EXPENSE']), array());
$last = null;
foreach ($layout->lines as $ll) {
	if ($ll->type === 'formula') {
		$last = $ll;
	}
}
$mismatch = false;
$amount = function ($v) {
	return html_entity_decode(price(price2num($v, 'MT'), 0, '', 1, -1, 'MT'), ENT_QUOTES, 'UTF-8');
};

print '<h3>'.$langs->trans('FinRepWizStep', $nbSteps + 1, $nbSteps + 1).' · '.$langs->trans('FinRepWizStepCheck').'</h3>';
if ($errors) {
	print '<div class="error"><b>'.$langs->trans('FinRepWizErrors').'</b><ul><li>'.implode('</li><li>', $errors).'</li></ul></div>';
}
$unused = financialreporting_wizard_unused_groups($draft);
if ($unused) {
	print '<div class="warning">'.$langs->trans('FinRepWizWarnUnused', dol_escape_htmltag(implode(', ', $unused))).'</div>';
}
if ($assign['unassigned']) {
	print '<div class="warning">'.$langs->trans('FinRepWizWarnUnassigned', count($assign['unassigned']));
	financialreporting_wizard_print_accounts($assign['unassigned'], $accounts);
	print '</div>';
}
if ($assign['unclassified']) {
	print '<div class="warning">'.$langs->trans('FinRepWizWarnUnclassified', count($assign['unclassified'])).' <a href="'.dol_buildpath('/financialreporting/admin/setup.php', 1).'?search_nature=_none">'.$langs->trans('Setup').'</a>';
	financialreporting_wizard_print_accounts($assign['unclassified'], $accounts);
	print '</div>';
}
if ($last && !$errors) {
	$value = (float) ($laid['values'][strtoupper($last->code)] ?? 0);
	$diff = $value - $is['result'];
	if (abs($diff) >= 0.005) {
		$mismatch = true;
		print '<div class="warning">'.$langs->trans('FinRepWizWarnResult', dol_escape_htmltag($last->label), $amount($value), $amount($is['result']), $amount($diff)).'</div>';
	} else {
		print '<div class="ok">'.$langs->trans('FinRepWizResultOk', dol_escape_htmltag($last->label), $amount($value)).'</div>';
	}
}

print '<p><b>'.$langs->trans('FinRepWizPreview', dol_print_date($from, 'day'), dol_print_date($to, 'day')).'</b></p>';
print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('Account').'</th><th>'.$langs->trans('Label').'</th><th>'.$langs->trans('Note').'</th><th class="right">'.$langs->trans('Amount').'</th></tr>';
financialreporting_print_layout($laid, $accounts, 'summary', 0, null, array());
print '</table>';

$nbGroups = 0;
$nbFormulas = 0;
$nbLinks = 0;
foreach ($draft['sections'] as $s) {
	foreach ($s['lines'] as $l) {
		if ($l['type'] === 'group') {
			$nbGroups++;
			$nbLinks += count($assign['byLine'][$l['code']] ?? array());
		} else {
			$nbFormulas++;
		}
	}
}
$hasWarnings = ($assign['unassigned'] || $assign['unclassified'] || $unused || $mismatch);
print '<form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save"><input type="hidden" name="mismatch" value="'.($mismatch ? 1 : 0).'">';
print '<div class="info" style="margin-top:12px">'.$langs->trans('FinRepWizWillWrite', dol_escape_htmltag($draft['label']), $nbGroups, $nbFormulas, $nbLinks).'<br>'.$langs->trans('FinRepWizCoreGap').'</div>';
if ($hasWarnings && !$errors) {
	print '<p><label><input type="checkbox" name="accept" value="1" required> '.$langs->trans('FinRepWizAcceptWarnings').'</label></p>';
}
print '<div class="center" style="margin:14px 0"><a class="button" href="'.$self.'?step='.$nbSteps.'">← '.$langs->trans('FinRepWizBack').'</a> ';
print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepWizSave')).'"'.($errors ? ' disabled' : '').'>';
print ' &nbsp; <a href="'.$self.'?action=cancel&token='.newToken().'">'.$langs->trans('FinRepWizCancel').'</a></div>';
print '</form>';

llxFooter();
$db->close();
