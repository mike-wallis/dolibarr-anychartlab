<?php
/**
 * Financial Reporting — Getting started: first-run setup wizard.
 *
 *  1. Basics          Accounting module, chart of accounts, company country, fiscal year start
 *  2. Class structure the fixed types and their classes: rename, hide, add your own classes,
 *                     with what the country's rules require (seed/classes/frameworks/)
 *  3. Assign accounts every account in a type and a class, shown as the structure; the rules
 *                     fill what is empty (natures, then classes); one choice Type > Class each
 *  4. Review          clearing accounts with a balance, balances on the opposite side
 *  5. Income statement (optional) hands over to the Report wizard
 *  6. Done            links to the reports
 *
 * Progress is worked out live by financialreporting_setup_status() (lib/setup.lib.php),
 * so the wizard can be left and resumed at any time.
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
require_once dirname(__DIR__).'/lib/setup.lib.php';
require_once dirname(__DIR__).'/lib/explain.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$langs->loadLangs(array('accountancy', 'companies', 'financialreporting@financialreporting'));

if (!isModEnabled('financialreporting')) {
	accessforbidden('Module Financial Reporting is not enabled (Setup > Modules).');
}
if (!$user->hasRight('financialreporting', 'setup') && !$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$step = GETPOSTINT('step');
$entity = (int) $conf->entity;
$self = $_SERVER['PHP_SELF'];
$natures = financialreporting_natures();

/*
 * Actions
 */

$st = financialreporting_setup_status($db, $entity);
$pcgversion = $st['pcgversion'];

if ($action === 'applyrules' && $pcgversion !== '') {
	$file = GETPOST('ruleset', 'alphanohtml');
	$ruleFile = ($file === '' ? null : ($file === '_generic' ? '' : basename($file)));	// null = the suggested rule set
	$c = financialreporting_apply_defaults($db, $user, $entity, $pcgversion, $ruleFile);
	$k = financialreporting_apply_default_classes($db, $user, $entity, $pcgversion);
	setEventMessages($langs->trans('FinRepAssignApplied', $c['dolibarr'] + $c['user'] + $c['chart'] + $c['generic'] + $c['parent'], $k['user'] + $k['chart'] + $k['generic'] + $k['parent'] + $k['children'], $k['default']), null, $c['remaining'] ? 'warnings' : 'mesgs');
	header('Location: '.$self.'?step=3');
	exit;
}

if ($action === 'saveassign' && $pcgversion !== '') {
	$accounts = financialreporting_load_accounts($db, $entity, $pcgversion);
	$confirm = GETPOST('confirm', 'array');
	$changed = 0;
	$db->begin();
	foreach (GETPOST('assign', 'array') as $rowid => $value) {
		$acc = $accounts['byRowid'][(int) $rowid] ?? null;
		if (!$acc || strpos((string) $value, '|') === false) {
			continue;
		}
		list($nature, $class) = explode('|', $value, 2);
		if (!isset($natures[$nature]) || !financialreporting_class_valid($nature, $class)) {
			continue;
		}
		if ($nature !== $acc->nature) {
			// a new type: the alternate nature stays only if it still differs; contra is kept
			financialreporting_save_nature($db, $user, $entity, (int) $rowid, $nature, ($acc->nature_alt !== $nature ? $acc->nature_alt : null), (int) $acc->contra, 'manual');
			$changed++;
		}
		// saving an account listed for attention confirms its class, even when unchanged
		if ($class !== $acc->class || $nature !== $acc->nature || (!empty($confirm[$rowid]) && $acc->class_source === 'default')) {
			financialreporting_save_class($db, $user, $entity, (int) $rowid, $class, 'manual');
			$changed++;
		}
	}
	$db->commit();
	setEventMessages($langs->trans('FinRepAssignSaved', $changed), null);
	header('Location: '.$self.'?step=3');
	exit;
}

if ($action === 'savestructure') {
	$db->begin();
	foreach (array_keys(financialreporting_natures_default()) as $nat) {
		financialreporting_save_structure($db, $user, $entity, 'type', $nat, GETPOST('typelabel', 'array')[$nat] ?? '', 0);
	}
	$used = financialreporting_class_counts(financialreporting_load_accounts($db, $entity, $pcgversion));
	$labels = GETPOST('classlabel', 'array');
	$hide = GETPOST('hide', 'array');
	foreach (financialreporting_classes() as $list) {
		foreach ($list as $base) {
			if (array_key_exists($base, $labels)) {
				// the default class of a type, and classes in use, can't be hidden
				$h = (!empty($hide[$base]) && !financialreporting_class_required($base) && empty($used[$base])) ? 1 : 0;
				financialreporting_save_structure($db, $user, $entity, 'class', $base, $labels[$base], $h);
			}
		}
	}
	$st0 = financialreporting_structure(true);
	$custombase = GETPOST('custombase', 'array');
	foreach (GETPOST('customlabel', 'array') as $code => $label) {
		if (isset($st0['custom'][$code]) && trim($label) !== '') {
			financialreporting_save_structure($db, $user, $entity, 'class', $code, $label, 0, $st0['custom'][$code]['base']);
			$nb = (string) ($custombase[$code] ?? '');
			if ($nb !== '' && $nb !== $st0['custom'][$code]['base']) {
				financialreporting_move_class($db, $entity, $code, $nb);
			}
		}
	}
	$refused = array();
	foreach (array_keys(GETPOST('delclass', 'array')) as $code) {
		if (financialreporting_delete_class($db, $entity, $code) === 0) {
			$refused[] = $langs->transnoentitiesnoconv('FinRepStructCantDeleteUsed').' ('.$code.')';
		}
	}
	$newbase = GETPOST('newbase', 'array');
	foreach (GETPOST('newlabel', 'array') as $nat => $label) {
		if (trim($label) !== '') {
			if (empty($newbase[$nat])) {
				$refused[] = $langs->transnoentitiesnoconv('FinRepStructNoBase', $label);
				continue;
			}
			financialreporting_add_class($db, $user, $entity, $newbase[$nat], $label);
		}
	}
	if (GETPOST('confirm', 'alpha') !== '') {
		dolibarr_set_const($db, 'FINANCIALREPORTING_STRUCTURE_REVIEWED', (string) dol_now(), 'chaine', 0, 'Financial Reporting: class structure confirmed', $entity);
	}
	$db->commit();
	setEventMessages($langs->trans('FinRepStructSaved'), $refused ? $refused : null, $refused ? 'warnings' : 'mesgs');
	header('Location: '.$self.'?step='.(GETPOST('confirm', 'alpha') !== '' ? 3 : 2));
	exit;
}

if ($action === 'makeclearing' && $pcgversion !== '') {
	$acc = financialreporting_load_accounts($db, $entity, $pcgversion)['byRowid'][GETPOSTINT('account')] ?? null;
	if ($acc && isset(financialreporting_dolibarr_clearing_accounts()[$acc->account_number])) {
		$db->begin();
		financialreporting_save_nature($db, $user, $entity, (int) $acc->rowid, 'CLEARING', null, 0, 'manual');
		financialreporting_save_class($db, $user, $entity, (int) $acc->rowid, 'CLEARING', 'manual');
		$db->commit();
		setEventMessages($langs->trans('FinRepDolibarrMade', $acc->account_number), null);
	}
	header('Location: '.$self.'?step=4');
	exit;
}

if ($action === 'savereview' && $pcgversion !== '') {
	$accounts = financialreporting_load_accounts($db, $entity, $pcgversion);
	$postNature = GETPOST('nature', 'array');
	$postAlt = GETPOST('nature_alt', 'array');
	$postContra = GETPOST('contra', 'array');
	$changed = 0;
	$db->begin();
	foreach (array_unique(array_merge(array_keys($postNature), array_keys($postAlt))) as $rowid) {
		$acc = $accounts['byRowid'][(int) $rowid] ?? null;
		if (!$acc) {
			continue;
		}
		$nature = (array_key_exists($rowid, $postNature) ? (isset($natures[$postNature[$rowid]]) ? $postNature[$rowid] : null) : $acc->nature);
		$alt = (array_key_exists($rowid, $postAlt) ? (isset($natures[$postAlt[$rowid]]) ? $postAlt[$rowid] : null) : $acc->nature_alt);
		$contra = (!empty($postContra[$rowid]) ? 1 : 0);
		if ($nature !== $acc->nature || $alt !== $acc->nature_alt || $contra !== (int) $acc->contra) {
			financialreporting_save_nature($db, $user, $entity, (int) $rowid, $nature, $alt, $contra, 'manual');
			$changed++;
		}
	}
	$db->commit();
	setEventMessages($langs->trans('FinRepStartSaved', $changed), null);
	header('Location: '.$self.'?step=4');
	exit;
}

/*
 * View
 */

$basicsOk = ($st['accounting'] && $pcgversion !== '' && $st['country'] !== '');
$structureDone = (getDolGlobalString('FINANCIALREPORTING_STRUCTURE_REVIEWED') !== '');
if ($step < 1 || $step > 6) {
	// open where the user stands
	$step = (!$basicsOk ? 1 : (!$structureDone && !$st['started'] ? 2 : ($st['complete'] ? 6 : 3)));
}
if (!$basicsOk && $step > 1) {
	$step = 1;
}

$form = new Form($db);
llxHeader('', $langs->trans('FinRepStartTitle'), '', '', 0, 0, '', '', '', 'mod-financialreporting page-start');
print load_fiche_titre($langs->trans('FinRepStartTitle'), '', 'accountancy');
financialreporting_print_nav('admin/start.php', $pcgversion);
print '<style>
.frstart-steps{margin:6px 0 14px} .frstart-steps span{display:inline-block;padding:3px 10px;margin:2px 4px 2px 0;border-radius:12px;background:var(--colorbacklinepair2,#eee)}
.frstart-steps span.cur{font-weight:bold;outline:2px solid var(--colortextlink,#2a6)}
.frstart-steps .ok::before{content:"✓ ";color:#1d7a1d} .frstart-steps .todo::before{content:"• ";color:#b5651d}
.frassign{margin:6px 0;padding:2px 8px;border:1px solid var(--colortopbordertitle1,#ddd);border-radius:6px} .frassign summary,.frassign-class summary{cursor:pointer;padding:4px 0} .frassign-class{margin:2px 0} .frstart-notype{margin:8px 0 12px;padding:6px 10px;border-left:3px solid #ccc} .frstart-ok{color:#1d7a1d;font-weight:bold} .frstart-ko{color:#b00020;font-weight:bold} .frstart-opt{color:#8a6d00;font-weight:bold}
</style>';

$checks = $st['checks'] ?? array('clearing' => array(), 'opposite' => array(), 'unknown' => array());
$stepDone = array(
	1 => $basicsOk,
	2 => $structureDone,
	3 => ($st['started'] && !$st['unclassified'] && !$st['classmissing'] && !$st['classreview']),
	4 => ($st['started'] && !$checks['clearing'] && !$checks['opposite'] && !$st['clearingsuggest']),
	5 => ($st['nbreports'] > 0),
	6 => $st['complete'],
);
$labels = array(1 => 'FinRepStartStep1', 2 => 'FinRepStartStepStructure', 3 => 'FinRepStartStepAssign', 4 => 'FinRepStartStep3', 5 => 'FinRepStartStep4', 6 => 'FinRepStartStep5');
print '<div class="frstart-steps">';
foreach ($labels as $k => $key) {
	$cls = ($k === $step ? 'cur ' : '').($stepDone[$k] ? 'ok' : 'todo');
	$link = ($basicsOk || $k === 1 ? '<a href="'.$self.'?step='.$k.'">'.$k.'. '.$langs->trans($key).'</a>' : $k.'. '.$langs->trans($key));
	print '<span class="'.$cls.'">'.$link.'</span>';
}
if ($step !== 1) {
	print ' <a href="'.$self.'?step=1#how">'.$langs->trans('FinRepXLink').'</a>';
}
print '</div>';

/**
 * Previous / next buttons.
 *
 * @param int  $step
 * @param bool $nextEnabled
 * @return void
 */
function financialreporting_start_nav($step, $nextEnabled = true)
{
	global $langs;
	print '<div class="center" style="margin:16px 0">';
	if ($step > 1) {
		print '<a class="button" href="'.$_SERVER['PHP_SELF'].'?step='.($step - 1).'">← '.$langs->trans('FinRepWizBack').'</a> ';
	}
	if ($step < 6) {
		print ($nextEnabled ? '<a class="button button-save" href="'.$_SERVER['PHP_SELF'].'?step='.($step + 1).'">' : '<a class="button button-save disabled" href="#" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepStartFixFirst')).'">').$langs->trans('FinRepWizNext').' →</a>';
	}
	print '</div>';
}

/**
 * Status cell.
 *
 * @param string $kind ok, ko or opt
 * @param string $text
 * @return string
 */
function financialreporting_start_status($kind, $text)
{
	return '<span class="frstart-'.$kind.'">'.$text.'</span>';
}

// ---- 1. Basics ----
if ($step === 1) {
	print '<div class="info">'.$langs->trans('FinRepStartIntro').'</div>';
	if ($st['accounts']) {
		financialreporting_print_explain($db, $entity, $st['accounts'], true);
	}
	print '<h3>1. '.$langs->trans('FinRepStartStep1').'</h3>';
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('FinRepStartWhat').'</th><th>'.$langs->trans('Status').'</th><th></th></tr>';
	$rows = array(
		array($langs->trans('FinRepStartAccounting'), $st['accounting'], $st['accounting'] ? $langs->trans('Enabled') : $langs->trans('FinRepStartAccountingOff'), DOL_URL_ROOT.'/admin/modules.php?search_keyword=accounting', true),
		array($langs->trans('FinRepStartChart'), $pcgversion !== '', $pcgversion !== '' ? dol_escape_htmltag($pcgversion).' ('.$st['nbaccounts'].' '.$langs->trans('FinRepStartAccounts').')' : $langs->trans('FinRepStartNone'), DOL_URL_ROOT.'/accountancy/admin/account.php', true),
		array($langs->trans('FinRepStartCountry'), $st['country'] !== '', $st['country'] !== '' ? dol_escape_htmltag($st['country']) : $langs->trans('FinRepStartNone'), DOL_URL_ROOT.'/admin/company.php', true),
		array($langs->trans('FinRepStartFiscalMonth'), true, dol_escape_htmltag(dol_print_date(dol_mktime(12, 0, 0, max(1, $st['fiscalmonth']), 1, 2000), '%B')).($st['fiscalmonth'] ? '' : ' <span class="opacitymedium">('.$langs->trans('FinRepStartDefault').')</span>'), DOL_URL_ROOT.'/admin/company.php', false),
	);
	foreach ($rows as $r) {
		print '<tr class="oddeven"><td>'.$r[0].'</td><td>'.financialreporting_start_status($r[1] ? 'ok' : ($r[4] ? 'ko' : 'opt'), $r[2]).'</td>';
		print '<td class="right"><a href="'.$r[3].'">'.($r[1] ? $langs->trans('FinRepStartChange') : $langs->trans('FinRepStartFix')).'</a></td></tr>';
	}
	print '</table>';
	print '<p class="opacitymedium">'.$langs->trans('FinRepStartFiscalHelp').'</p>';
	financialreporting_start_nav(1, $basicsOk);
}

// ---- 2. Class structure ----
if ($step === 2) {
	$fw = financialreporting_framework();
	$structure = financialreporting_structure(true);
	$used = financialreporting_class_counts($st['accounts']);
	print '<h3>2. '.$langs->trans('FinRepStartStepStructure').'</h3>';
	print '<div class="info">'.$langs->trans('FinRepStructHelp').'</div>';
	print '<div class="warning">'.$langs->trans('FinRepStructCaution', dol_escape_htmltag($fw['label'])).($fw['notes'] !== '' ? '<br><br>'.dol_escape_htmltag($fw['notes']) : '').'</div>';
	print '<details class="frstart-notype"><summary><b>'.$langs->trans('FinRepStructNoTypeTitle').'</b></summary>'.$langs->trans('FinRepStructNoTypeHelp').'</details>';

	print '<form method="POST" action="'.$self.'?step=2"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savestructure">';
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('FinRepStructCol').'</th><th>'.$langs->trans('FinRepStructYourLabel').'</th><th>'.$langs->trans('FinRepStructCountry', dol_escape_htmltag($fw['label'])).'</th><th class="center">'.$langs->trans('FinRepStartAccounts').'</th><th class="center">'.$langs->trans('FinRepStructHideDelete').'</th></tr>';
	$defaults = financialreporting_natures_default();
	foreach (financialreporting_classes() as $nat => $list) {
		if (in_array($nat, array('CLEARING', 'EXCLUDED'), true)) {
			continue;	// one class each, equal to the type: nothing to set up
		}
		print '<tr class="liste_titre"><td><b>'.$langs->trans('FinRepStructType').': '.dol_escape_htmltag($nat).'</b></td>';
		print '<td><input type="text" class="flat minwidth200" name="typelabel['.$nat.']" value="'.dol_escape_htmltag($structure['types'][$nat] ?? '').'" placeholder="'.dol_escape_htmltag($defaults[$nat]).'"></td><td colspan="3" class="opacitymedium">'.$langs->trans('FinRepStructTypeFixed').'</td></tr>';
		foreach ($list as $base) {
			$fwr = $fw['rows'][$base] ?? null;
			if ($nat === 'LIABILITY' && $base === 'CASH') {
				print '<tr class="oddeven"><td class="opacitymedium" style="padding-left:20px">'.dol_escape_htmltag(financialreporting_class_label('CASH')).'</td><td class="opacitymedium" colspan="4">'.$langs->trans('FinRepStructCashBoth').'</td></tr>';
				continue;
			}
			$req = financialreporting_class_required($base);
			$hidden = !empty($structure['hidden'][$base]);
			print '<tr class="oddeven"><td style="padding-left:20px">'.dol_escape_htmltag(financialreporting_class_label($base)).' <span class="opacitymedium">('.dol_escape_htmltag($base).')</span></td>';
			print '<td><input type="text" class="flat minwidth200" name="classlabel['.$base.']" value="'.dol_escape_htmltag($structure['labels'][$base] ?? '').'" placeholder="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepClass_'.$base)).'"></td>';
			print '<td>'.($fwr ? dol_escape_htmltag($fwr['heading']).' '.($fwr['required'] ? '<span class="badge badge-status4">'.$langs->trans('FinRepStructRequired').'</span>' : '<span class="badge badge-status0">'.$langs->trans('FinRepStructOptional').'</span>').($fwr['note'] !== '' ? '<br><span class="opacitymedium">'.dol_escape_htmltag($fwr['note']).'</span>' : '') : '').'</td>';
			print '<td class="center">'.((int) ($used[$base] ?? 0)).'</td>';
			if ($req || !empty($used[$base])) {
				print '<td class="center opacitymedium" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv($req ? 'FinRepStructCantHideDefault' : 'FinRepStructCantHideUsed')).'">—</td></tr>';
			} else {
				print '<td class="center"><label><input type="checkbox" name="hide['.$base.']" value="1"'.($hidden ? ' checked' : '').'> '.$langs->trans('FinRepStructHide').'</label>'.($hidden && $fwr && $fwr['required'] ? '<br><span class="warning" style="padding:1px 4px">'.$langs->trans('FinRepStructHiddenRequired').'</span>' : '').'</td></tr>';
			}
			foreach ($structure['custom'] as $code => $c) {
				if ($c['base'] !== $base) {
					continue;
				}
				print '<tr class="oddeven"><td style="padding-left:40px">↳ '.dol_escape_htmltag($c['label']).' <span class="opacitymedium">('.$langs->trans('FinRepStructYourClass').')</span></td>';
				$moveOptions = array();
			foreach ($list as $b2) {
				$moveOptions[$b2] = financialreporting_class_label($b2);
			}
			print '<td><input type="text" class="flat minwidth200" name="customlabel['.$code.']" value="'.dol_escape_htmltag($c['label']).'"></td><td>'.$langs->trans('FinRepStructRollsUpInto').' '.$form->selectarray('custombase['.$code.']', $moveOptions, $base, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td>';
				print '<td class="center">'.((int) ($used[$code] ?? 0)).'</td>';
				print '<td class="center">'.(empty($used[$code]) ? '<label><input type="checkbox" name="delclass['.$code.']" value="1"> '.$langs->trans('Delete').'</label>' : '<span class="opacitymedium" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepStructCantDeleteUsed')).'">—</span>').'</td></tr>';
			}
		}
		// add a class of the user's own under one of this type's base classes
		$baseOptions = array();
		foreach ($list as $base) {
			$baseOptions[$base] = financialreporting_class_label($base);
		}
		print '<tr class="oddeven"><td style="padding-left:40px" class="opacitymedium">+ '.$langs->trans('FinRepStructAdd').'</td>';
		print '<td><input type="text" class="flat minwidth200" name="newlabel['.$nat.']" placeholder="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepStructAddPlaceholder_'.$nat)).'"></td>';
		print '<td colspan="3">'.$langs->trans('FinRepStructUnder').' '.$form->selectarray('newbase['.$nat.']', array('' => $langs->transnoentitiesnoconv('FinRepStructChooseBase')) + $baseOptions, '', 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
	}
	print '</table>';
	print '<div class="center" style="margin:10px 0"><input type="submit" class="button" name="save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"> ';
	print '<input type="submit" class="button button-save" name="confirm" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepStructConfirm')).'"></div></form>';
	if (getDolGlobalString('FINANCIALREPORTING_STRUCTURE_REVIEWED')) {
		print '<p class="opacitymedium">'.$langs->trans('FinRepStructConfirmedOn', dol_print_date((int) getDolGlobalString('FINANCIALREPORTING_STRUCTURE_REVIEWED'), 'dayhour')).'</p>';
	}
	financialreporting_start_nav(2, true);
}

// ---- 3. Assign accounts: every account in a type and a class, shown as the structure ----
if ($step === 3) {
	print '<h3>3. '.$langs->trans('FinRepStartStepAssign').'</h3>';
	print '<div class="info">'.$langs->trans('FinRepAssignHelp').'</div>';

	// rules: natures (chosen rule set) then classes
	$sug = financialreporting_suggest_ruleset($pcgversion);
	if ($sug) {
		$options = array('' => $sug['label'].' ('.$langs->transnoentitiesnoconv('FinRepStartSuggestedShort').')', '_generic' => $langs->transnoentitiesnoconv('FinRepStartGenericOnly'));
	} else {
		$options = array('' => $langs->transnoentitiesnoconv('FinRepStartGenericOnly').' ('.$langs->transnoentitiesnoconv('FinRepStartSuggestedShort').')');
	}
	foreach (financialreporting_catalog_ranked('nature', $pcgversion) as $e) {
		if (!$sug || $e['file'] !== $sug['file']) {
			$options[$e['file']] = $e['label'];
		}
	}
	print '<form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="applyrules">';
	print '<p>'.$langs->trans('FinRepStartDetected', dol_escape_htmltag($st['country'] ?: '?'), dol_escape_htmltag($pcgversion)).' ';
	print $langs->trans('FinRepStartRuleSet').' '.$form->selectarray('ruleset', $options, '', 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').' ';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepAssignApply')).'"> <a href="'.dol_buildpath('/financialreporting/admin/rules.php', 1).'">'.$langs->trans('FinRepRulesSeeEdit').'</a></p></form>';
	print '<p class="opacitymedium">'.($sug ? $langs->trans($sug['match'] === 'chart' ? 'FinRepStartWhyChart' : 'FinRepStartWhyCountry', dol_escape_htmltag($sug['label']), dol_escape_htmltag($sug['match'] === 'chart' ? $pcgversion : $st['country'])) : $langs->trans('FinRepStartWhyGeneric', dol_escape_htmltag($pcgversion))).' '.$langs->trans('FinRepAssignApplyHelp').'</p>';

	$accounts = $st['accounts'];
	$moved = financialreporting_accounts_with_movements($db, $entity);
	$attention = array();	// rowid => reason
	foreach ($st['unclassified'] as $a) {
		$attention[(int) $a->rowid] = 'FinRepAssignWhyNoType';
	}
	foreach ($st['classmissing'] as $a) {
		$attention[(int) $a->rowid] = 'FinRepAssignWhyNoClass';
	}
	foreach ($st['classreview'] as $a) {
		$attention[(int) $a->rowid] = 'FinRepAssignWhyDefault';
	}

	// one choice list for every account: Type > Class, from the user's structure
	$choice = function ($acc, $name) use ($natures) {
		$cur = ($acc->nature !== null ? $acc->nature.'|'.(string) financialreporting_account_class($acc) : '');
		$h = '<select name="'.$name.'" class="flat minwidth250">';
		if ($acc->nature === null) {
			$h .= '<option value="" selected>— '.dol_escape_htmltag($GLOBALS['langs']->transnoentitiesnoconv('FinRepAssignNoType')).' —</option>';
		} elseif (financialreporting_account_class($acc) === null) {
			// no class yet: an explicit empty choice, never a silent first option (saving skips it)
			$h .= '<option value="" selected>'.dol_escape_htmltag(($natures[$acc->nature] ?? $acc->nature).' › — '.$GLOBALS['langs']->transnoentitiesnoconv('FinRepAssignChooseClass').' —').'</option>';
		}
		foreach (array_keys(financialreporting_classes()) as $nat) {
			$h .= '<optgroup label="'.dol_escape_htmltag($natures[$nat] ?? $nat).'">';
			foreach (financialreporting_classes_for($nat) as $cl) {
				$v = $nat.'|'.$cl;
				$h .= '<option value="'.dol_escape_htmltag($v).'"'.($v === $cur ? ' selected' : '').'>'.dol_escape_htmltag(($natures[$nat] ?? $nat).' › '.financialreporting_class_label($cl)).'</option>';
			}
			if ($acc->nature === $nat && financialreporting_account_class($acc) !== null && !in_array(financialreporting_account_class($acc), financialreporting_classes_for($nat), true)) {
				// its current class was hidden later: keep it selectable
				$h .= '<option value="'.dol_escape_htmltag($cur).'" selected>'.dol_escape_htmltag(($natures[$nat] ?? $nat).' › '.financialreporting_class_label(financialreporting_account_class($acc))).'</option>';
			}
			$h .= '</optgroup>';
		}
		return $h.'</select>';
	};
	$sourceBadge = function ($acc) use ($langs) {
		$src = ($acc->nature === null ? '' : (string) $acc->class_source);
		if ($src === '') {
			return '';
		}
		return '<span class="badge '.($src === 'default' ? 'badge-status1' : ($src === 'manual' || $src === 'import' ? 'badge-status4' : 'badge-status0')).'" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepAssignSourceHelp')).'">'.$langs->trans('FinRepAssignSource_'.$src).'</span>';
	};
	$row = function ($acc, $select) use ($moved, $sourceBadge) {
		return '<tr class="oddeven"><td class="nowraponall"><a href="'.financialreporting_ledger_url($acc->account_number).'" target="_blank">'.dol_escape_htmltag($acc->account_number).'</a></td><td>'.dol_escape_htmltag($acc->label).(isset($moved[$acc->account_number]) ? '' : ' <span class="opacitymedium" title="No entries in the ledger">·</span>').'</td><td>'.$sourceBadge($acc).'</td><td>'.$select.'</td></tr>';
	};

	$nbDone = 0;
	foreach ($accounts['byRowid'] as $a) {
		if ($a->nature !== null && financialreporting_account_class($a) !== null) {
			$nbDone++;
		}
	}
	print '<p>'.$langs->trans('FinRepAssignSummary', $nbDone, $st['nbaccounts']).($attention ? ' · <b>'.$langs->trans('FinRepAssignNeedYou', count($attention)).'</b>' : '').'</p>';
	if ($nbDone < $st['nbaccounts'] / 2) {
		// most accounts still empty: the rules do the bulk, the user then checks
		print '<div class="info" style="border-left:4px solid #2a7"><b>'.$langs->trans('FinRepAssignStartWithRules').'</b></div>';
	}

	print '<form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="saveassign">';
	$thead = '<tr class="liste_titre"><th>'.$langs->trans('Account').'</th><th>'.$langs->trans('Label').'</th><th>'.$langs->trans('FinRepAssignSet').'</th><th>'.$langs->trans('FinRepAssignTypeClass').'</th></tr>';

	if ($attention) {
		print '<div class="warning"><b>'.$langs->trans('FinRepAssignAttention').'</b> '.$langs->trans('FinRepAssignAttentionHelp').'</div>';
		print '<table class="noborder centpercent">'.$thead;
		foreach ($attention as $rowid => $why) {
			$acc = $accounts['byRowid'][$rowid];
			print str_replace('</td></tr>', ' <span class="opacitymedium">'.$langs->trans($why).'</span><input type="hidden" name="confirm['.$rowid.']" value="1"></td></tr>', $row($acc, $choice($acc, 'assign['.$rowid.']')));
		}
		print '</table><p class="opacitymedium">'.$langs->trans('FinRepStartClassSaveHelp').'</p>';
	}

	// the structure: every type, every class (empty ones too), the accounts in each
	print '<h4>'.$langs->trans('FinRepAssignTree').'</h4>';
	$byPlace = array();
	foreach ($accounts['byRowid'] as $a) {
		$byPlace[(string) $a->nature][(string) financialreporting_account_class($a)][] = $a;
	}
	$groups = array_keys(financialreporting_classes());
	if (!empty($byPlace[''])) {
		array_unshift($groups, '');
	}
	foreach ($groups as $nat) {
		$inType = 0;
		foreach ($byPlace[$nat] ?? array() as $list) {
			$inType += count($list);
		}
		$title = ($nat === '' ? $langs->trans('FinRepAssignNoTypeGroup') : dol_escape_htmltag($natures[$nat] ?? $nat));
		$hasAttention = false;
		foreach ($byPlace[$nat] ?? array() as $list) {
			foreach ($list as $a) {
				$hasAttention = $hasAttention || isset($attention[(int) $a->rowid]);
			}
		}
		print '<details class="frassign"'.($nat === '' ? ' open' : '').'><summary><b>'.$title.'</b> <span class="opacitymedium">('.$inType.')</span></summary>';
		$classList = ($nat === '' ? array('') : financialreporting_classes_for($nat, true));
		if ($nat !== '' && !empty($byPlace[$nat][''])) {
			array_unshift($classList, '');	// accounts of this type with no class yet
		}
		foreach ($classList as $cl) {
			$list = $byPlace[$nat][$cl] ?? array();
			$st0 = financialreporting_structure();
			if ($cl !== '' && !$list && !empty($st0['hidden'][$cl])) {
				continue;	// hidden and empty: not part of the user's structure
			}
			$isCustom = isset($st0['custom'][$cl]);
			$clTitle = ($nat === '' ? $langs->trans('FinRepAssignNoTypeGroup') : ($cl === '' ? '<span class="error">'.$langs->trans('FinRepAssignNoClass').'</span>' : ($isCustom ? '↳ ' : '').dol_escape_htmltag(financialreporting_class_label($cl))));
			if (!$list) {
				print '<div class="frassign-class opacitymedium" style="padding-left:'.($isCustom ? 34 : 16).'px">'.$clTitle.' (0) · '.$langs->trans('FinRepAssignEmptyClass').'</div>';
				continue;
			}
			print '<details class="frassign-class" style="margin-left:'.($isCustom ? 24 : 6).'px"'.($nat === '' ? ' open' : '').'><summary>'.$clTitle.' <span class="opacitymedium">('.count($list).')</span></summary>';
			print '<table class="noborder centpercent">'.$thead;
			usort($list, function ($x, $y) {
				return strcmp($x->account_number, $y->account_number);
			});
			foreach ($list as $acc) {
				$sel = (isset($attention[(int) $acc->rowid]) ? '<span class="opacitymedium">'.$langs->trans('FinRepAssignSeeAbove').'</span>' : $choice($acc, 'assign['.$acc->rowid.']'));
				print $row($acc, $sel);
			}
			print '</table></details>';
		}
		print '</details>';
	}
	print '<div class="center" style="margin:12px 0"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepAssignSave')).'"></div></form>';
	print '<p class="opacitymedium">'.$langs->trans('FinRepAssignLater').' <a href="'.dol_buildpath('/financialreporting/admin/setup.php', 1).'">'.$langs->trans('FinRepStartNaturesPage').'</a></p>';
	financialreporting_start_nav(3, $st['started'] && !$st['unclassified'] && !$st['classmissing']);
}

// ---- 4. Review ----
if ($step === 4) {
	print '<h3>4. '.$langs->trans('FinRepStartStep3').'</h3>';
	print '<div class="info">'.$langs->trans('FinRepStartReviewHelp').'</div>';
	if ($st['clearingsuggest']) {
		print '<h4>'.$langs->trans('FinRepDolibarrTitle').' <span class="badge badge-status8">'.count($st['clearingsuggest']).'</span></h4>';
		print '<p class="opacitymedium">'.$langs->trans('FinRepDolibarrHelp').'</p>';
		print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('Account').'</th><th>'.$langs->trans('Label').'</th><th>'.$langs->trans('FinRepDolibarrUsedAs').'</th><th>'.$langs->trans('FinRepStartNature').'</th><th></th></tr>';
		foreach ($st['clearingsuggest'] as $r) {
			print '<tr class="oddeven"><td>'.dol_escape_htmltag($r[0]->account_number).'</td><td>'.dol_escape_htmltag($r[0]->label).'</td><td>'.$langs->trans($r[1]).'</td><td>'.dol_escape_htmltag($natures[$r[0]->nature] ?? $r[0]->nature).'</td>';
			print '<td class="right"><a class="button small" href="'.$self.'?action=makeclearing&account='.((int) $r[0]->rowid).'&token='.newToken().'">'.$langs->trans('FinRepDolibarrMake').'</a></td></tr>';
		}
		print '</table>';
	}
	print '<h4>'.$langs->trans('FinRepStartOpposite').' <span class="badge '.($checks['opposite'] ? 'badge-status8' : 'badge-status4').'">'.count($checks['opposite']).'</span></h4>';
	print '<p class="opacitymedium">'.$langs->trans('FinRepStartOppositeHelp').'</p>';
	if ($checks['opposite']) {
		print '<form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savereview">';
		print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('Account').'</th><th>'.$langs->trans('Label').'</th><th>'.$langs->trans('FinRepStartNature').'</th><th class="right">'.$langs->trans('FinRepStartBalance').'</th><th>'.$langs->trans('FinRepStartAltNature').'</th><th class="center">'.$langs->trans('FinRepStartContra').'</th></tr>';
		$altOptions = array('' => '') + $natures;
		foreach ($checks['opposite'] as $r) {
			$acc = $r[0];
			print '<tr class="oddeven"><td><a href="'.financialreporting_ledger_url($acc->account_number).'" target="_blank">'.dol_escape_htmltag($acc->account_number).'</a></td><td>'.dol_escape_htmltag($acc->label).'</td><td>'.dol_escape_htmltag($natures[$acc->nature] ?? $acc->nature).'</td>';
			print '<td class="right amount">'.financialreporting_price($r[1]).' '.($r[1] > 0 ? $langs->trans('FinRepStartDr') : $langs->trans('FinRepStartCr')).'</td>';
			print '<td>'.$form->selectarray('nature_alt['.$acc->rowid.']', $altOptions, (string) $acc->nature_alt, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150').'</td>';
			print '<td class="center"><input type="checkbox" name="contra['.$acc->rowid.']" value="1"'.($acc->contra ? ' checked' : '').'></td></tr>';
		}
		print '</table><div class="center" style="margin-top:8px"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div></form>';
	} else {
		print '<p>'.$langs->trans('FinRepStartNone').'</p>';
	}
	print '<h4>'.$langs->trans('FinRepStartClearing').' <span class="badge '.($checks['clearing'] ? 'badge-status8' : 'badge-status4').'">'.count($checks['clearing']).'</span></h4>';
	print '<p class="opacitymedium">'.$langs->trans('FinRepStartClearingHelp').'</p>';
	if ($checks['clearing']) {
		print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('Account').'</th><th>'.$langs->trans('Label').'</th><th class="right">'.$langs->trans('FinRepStartBalance').'</th></tr>';
		foreach ($checks['clearing'] as $r) {
			print '<tr class="oddeven"><td><a href="'.financialreporting_ledger_url($r[0]->account_number).'" target="_blank">'.dol_escape_htmltag($r[0]->account_number).'</a></td><td>'.dol_escape_htmltag($r[0]->label).'</td><td class="right amount">'.financialreporting_price($r[1]).'</td></tr>';
		}
		print '</table>';
	} else {
		print '<p>'.$langs->trans('FinRepStartNone').'</p>';
	}
	if ($checks['unknown']) {
		print '<div class="warning">'.$langs->trans('FinRepStartUnknown', count($checks['unknown']), dol_escape_htmltag(implode(', ', array_column($checks['unknown'], 0)))).'</div>';
	}
	print '<p class="opacitymedium">'.$langs->trans('FinRepStartReviewOptional').' <a href="'.dol_buildpath('/financialreporting/check.php', 1).'">'.$langs->trans('FinRepStartCheckPage').'</a></p>';
	financialreporting_start_nav(4, true);
}

// ---- 5. Income statement ----
if ($step === 5) {
	print '<h3>5. '.$langs->trans('FinRepStartStep4').'</h3>';
	print '<div class="info">'.$langs->trans('FinRepStartIsHelp').'</div>';
	if ($st['nbreports']) {
		print '<p class="frstart-ok">'.$langs->trans('FinRepStartIsDone', $st['nbreports']).'</p>';
	}
	print '<p><a class="button" href="'.dol_buildpath('/financialreporting/admin/wizard.php', 1).'">'.$langs->trans('FinRepStartOpenWizard').'</a> <span class="opacitymedium">'.$langs->trans('FinRepStartIsOptional').'</span></p>';
	financialreporting_start_nav(5, true);
}

// ---- 6. Done ----
if ($step === 6) {
	print '<h3>6. '.$langs->trans('FinRepStartStep5').'</h3>';
	if ($st['complete']) {
		print '<div class="ok">'.$langs->trans('FinRepStartComplete').'</div>';
	} else {
		print '<div class="warning">'.$langs->trans('FinRepStartNotComplete').' <a href="'.$self.'?step=3">'.$langs->trans('FinRepStartStepAssign').'</a></div>';
	}
	$links = array('trialbalance.php' => 'Trial Balance', 'balancesheet.php' => 'Balance Sheet', 'incomestatement.php' => 'Income Statement', 'cashflow.php' => 'Cash Flow', 'aged.php?type=ar' => 'Accounts Receivable', 'aged.php?type=ap' => 'Accounts Payable');
	print '<ul>';
	foreach ($links as $f => $l) {
		print '<li><a href="'.dol_buildpath('/financialreporting/'.$f, 1).'">'.$l.'</a></li>';
	}
	print '</ul>';
	print '<p class="opacitymedium">'.$langs->trans('FinRepStartLater').'</p>';
	financialreporting_start_nav(6);
}

llxFooter();
$db->close();
