<?php
/**
 * Financial Reporting — rule sets.
 *
 * Shows every rule set the module uses to suggest types (natures) and classes, with how
 * many of the chart's accounts each rule takes. The shipped sets (seed/) are read only:
 * they are replaced on upgrade. The user's own rules ("My rules") are stored in
 * llx_financialreporting_rule, applied before the shipped sets, and can be copied from a
 * shipped set, exported and imported (same CSV format as the shipped files).
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

$langs->loadLangs(array('accountancy', 'financialreporting@financialreporting'));

if (!isModEnabled('financialreporting')) {
	accessforbidden('Module Financial Reporting is not enabled (Setup > Modules).');
}
if (!$user->hasRight('financialreporting', 'setup') && !$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$entity = (int) $conf->entity;
$self = $_SERVER['PHP_SELF'];
$pcgversion = financialreporting_active_chart($db);
$sets = financialreporting_rule_sets($pcgversion);
$setKey = GETPOST('set', 'alphanohtml');
if (!isset($sets[$setKey])) {
	$setKey = 'user:nature';
}
$set = $sets[$setKey];
$kind = $set['kind'];
$isUser = ($set['match'] === 'user');
$natureCols = array('pcg_type', 'account_prefix', 'label_contains', 'nature', 'nature_alt', 'contra', 'comment');
$classCols = array('nature', 'pcg_type', 'account_prefix', 'label_contains', 'class', 'comment');

/*
 * Actions
 */

if ($action === 'copy' && !$isUser) {
	$mine = financialreporting_user_rules($db, $entity, $kind, false);
	$pos = ($mine ? max(array_column($mine, 'position')) : 0);
	$n = 0;
	$db->begin();
	foreach (financialreporting_rule_set_rules($db, $entity, $setKey) as $r) {
		$pos += 10;
		$r['position'] = $pos;
		$r['comment'] = trim(($r['comment'] ?? '').' ['.$langs->transnoentitiesnoconv('FinRepRulesCopiedFrom', $set['file']).']');
		if (financialreporting_save_user_rule($db, $user, $entity, $kind, 0, $r) > 0) {
			$n++;
		}
	}
	$db->commit();
	setEventMessages($langs->trans('FinRepRulesCopied', $n), null);
	header('Location: '.$self.'?set=user:'.$kind);
	exit;
}

if ($action === 'save' && $isUser) {
	$db->begin();
	$rows = GETPOST('r', 'array');
	foreach ($rows as $id => $r) {
		if ($id === 'new') {
			$has = false;
			foreach (array('pcg_type', 'account_prefix', 'label_contains', 'nature', 'class') as $c) {
				$has = $has || trim((string) ($r[$c] ?? '')) !== '';
			}
			if ($kind === 'class') {
				$r['nature'] = implode('|', GETPOST('r_new_natures', 'array:aZ09'));
			}
			if ($has) {
				if (!isset($r['position']) || $r['position'] === '') {
					$mine = financialreporting_user_rules($db, $entity, $kind, false);
					$r['position'] = ($mine ? max(array_column($mine, 'position')) : 0) + 10;
				}
				$r['active'] = 1;
				if (financialreporting_save_user_rule($db, $user, $entity, $kind, 0, $r) === 0) {
					setEventMessages($langs->trans('FinRepRulesMustSet'), null, 'warnings');
				}
			}
			continue;
		}
		if (!empty($r['delete'])) {
			$db->query("DELETE FROM ".MAIN_DB_PREFIX."financialreporting_rule WHERE rowid = ".((int) $id)." AND entity = ".$entity);
			continue;
		}
		$r['active'] = !empty($r['active']) ? 1 : 0;
		if ($kind === 'class') {
			$r['nature'] = implode('|', GETPOST('r_'.((int) $id).'_natures', 'array:aZ09'));	// the "for types" multi-select
		}
		financialreporting_save_user_rule($db, $user, $entity, $kind, (int) $id, $r);
	}
	$db->commit();
	setEventMessages($langs->trans('FinRepRulesSaved'), null);
	header('Location: '.$self.'?set='.urlencode($setKey));
	exit;
}

if ($action === 'apply' && $pcgversion !== '') {
	$c = financialreporting_apply_defaults($db, $user, $entity, $pcgversion, null);
	$k = financialreporting_apply_default_classes($db, $user, $entity, $pcgversion);
	setEventMessages($langs->trans('FinRepAssignApplied', $c['dolibarr'] + $c['user'] + $c['chart'] + $c['generic'] + $c['parent'], $k['user'] + $k['chart'] + $k['generic'] + $k['parent'] + $k['children'], $k['default']), null);
	header('Location: '.$self.'?set='.urlencode($setKey));
	exit;
}

if ($action === 'export') {
	$cols = ($kind === 'class' ? $classCols : $natureCols);
	$rows = array();
	foreach (financialreporting_rule_set_rules($db, $entity, $setKey, false) as $r) {
		$line = array();
		foreach ($cols as $c) {
			$line[] = (string) ($r[$c] ?? '');
		}
		$rows[] = $line;
	}
	financialreporting_send_csv('financialreporting-rules-'.$kind.'-'.($isUser ? 'mine' : preg_replace('/\.csv$/', '', basename($set['file']))), $cols, $rows);
}

if ($action === 'import' && $isUser && !empty($_FILES['importfile']['tmp_name'])) {
	$mine = financialreporting_user_rules($db, $entity, $kind, false);
	$pos = ($mine ? max(array_column($mine, 'position')) : 0);
	$n = 0;
	$skipped = 0;
	$db->begin();
	foreach (financialreporting_load_rules_raw($_FILES['importfile']['tmp_name']) as $r) {
		$pos += 10;
		$r['position'] = $pos;
		if (financialreporting_save_user_rule($db, $user, $entity, $kind, 0, $r) > 0) {
			$n++;
		} else {
			$skipped++;
		}
	}
	$db->commit();
	setEventMessages($langs->trans('FinRepRulesImported', $n), $skipped ? array($langs->trans('FinRepRulesImportSkipped', $skipped)) : null, $skipped ? 'warnings' : 'mesgs');
	header('Location: '.$self.'?set='.urlencode($setKey));
	exit;
}

/*
 * View
 */

$form = new Form($db);
llxHeader('', $langs->trans('FinRepRulesTitle'), '', '', 0, 0, '', '', '', 'mod-financialreporting page-rules');
print load_fiche_titre($langs->trans('FinRepRulesTitle'), '', 'accountancy');
financialreporting_print_nav('admin/rules.php', $pcgversion);
print '<style>.frrules-tabs a{display:inline-block;padding:3px 10px;margin:2px 4px 2px 0;border-radius:12px;background:var(--colorbacklinepair2,#eee)} .frrules-tabs a.cur{font-weight:bold;outline:2px solid var(--colortextlink,#2a6)} .frrules-acc{font-size:.9em;columns:2 220px;margin:4px 0;padding-left:18px}</style>';

if ($pcgversion === '') {
	print '<div class="warning">'.$langs->trans('FinRepWizNoChart').'</div>';
	llxFooter();
	exit;
}

print '<div class="info">'.$langs->trans('FinRepRulesHelp').'</div>';

// the sets, as tabs: My rules first, then the ones suggested for this chart / country
print '<div class="frrules-tabs">';
foreach ($sets as $k => $s) {
	$badge = (in_array($s['match'], array('chart', 'country', 'generic'), true) ? ' ★' : '');	// generic sets are always applied
	print '<a class="'.($k === $setKey ? 'cur' : '').'" href="'.$self.'?set='.urlencode($k).'">'.dol_escape_htmltag($s['label']).$badge.'</a>';
}
print '</div><p class="opacitymedium">★ = '.$langs->trans('FinRepRulesUsedForChart', dol_escape_htmltag($pcgversion)).'</p>';

$accounts = financialreporting_load_accounts($db, $entity, $pcgversion);
$rules = financialreporting_rule_set_rules($db, $entity, $setKey, false);
$matches = financialreporting_rule_matches_per_rule(array_values(array_filter($rules, function ($r) {
	return !isset($r['active']) || (int) $r['active'] === 1;
})), $accounts, $kind);
// map matches back to all rules (inactive ones take nothing)
$mi = 0;
$perRule = array();
foreach ($rules as $i => $r) {
	$perRule[$i] = (!isset($r['active']) || (int) $r['active'] === 1) ? $matches[$mi++] : array('all' => array(), 'empty' => array());
}

$natures = financialreporting_natures();
$classOptions = array();
foreach (array_keys(financialreporting_classes()) as $nat) {
	foreach (financialreporting_classes_for($nat) as $cl) {
		$classOptions[$cl] = financialreporting_class_label($cl);
	}
}
$accList = function ($list) {
	if (!$list) {
		return '';
	}
	$h = '<ul class="frrules-acc">';
	foreach ($list as $a) {
		$h .= '<li>'.dol_escape_htmltag($a->account_number.' '.$a->label).'</li>';
	}
	return $h.'</ul>';
};
$matchCell = function ($m) use ($langs, $accList) {
	$h = '<details><summary>'.count($m['all']).($m['empty'] ? ' <span class="badge badge-status1">'.$langs->trans('FinRepRulesStillEmpty', count($m['empty'])).'</span>' : '').'</summary>'.$accList($m['all']).'</details>';
	return $h;
};

print '<h3>'.dol_escape_htmltag($set['label']).'</h3>';
print '<p class="opacitymedium">'.$langs->trans($kind === 'class' ? 'FinRepRulesKindClass' : 'FinRepRulesKindNature').'</p>';

if (!$isUser) {
	// ---- a shipped set: read only ----
	print '<p>'.$langs->trans('FinRepRulesShipped', dol_escape_htmltag($set['file'])).' <a class="button small" href="'.$self.'?set='.urlencode($setKey).'&action=copy&token='.newToken().'">'.$langs->trans('FinRepRulesCopy').'</a> <a class="button small" href="'.$self.'?set='.urlencode($setKey).'&action=export&token='.newToken().'">'.$langs->trans('FinRepRulesExport').'</a></p>';
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>#</th>'.($kind === 'class' ? '<th>'.$langs->trans('FinRepRulesForTypes').'</th>' : '').'<th>'.$langs->trans('FinRepRulesGroup').'</th><th>'.$langs->trans('FinRepRulesPrefix').'</th><th>'.$langs->trans('FinRepRulesLabel').'</th><th>'.$langs->trans('FinRepRulesSets').'</th><th>'.$langs->trans('FinRepRulesComment').'</th><th>'.$langs->trans('FinRepRulesTakes').'</th></tr>';
	foreach ($rules as $i => $r) {
		$sets_ = ($kind === 'class' ? dol_escape_htmltag(financialreporting_class_label(strtoupper($r['class'])))
			: dol_escape_htmltag($natures[strtoupper($r['nature'])] ?? $r['nature']).(($r['nature_alt'] ?? '') !== '' ? ' <span class="opacitymedium">('.$langs->trans('FinRepStartAltNature').': '.dol_escape_htmltag($natures[strtoupper($r['nature_alt'])] ?? $r['nature_alt']).')</span>' : '').(!empty($r['contra']) ? ' <span class="opacitymedium">('.$langs->trans('FinRepStartContra').')</span>' : ''));
		print '<tr class="oddeven"><td>'.($i + 1).'</td>'.($kind === 'class' ? '<td>'.dol_escape_htmltag(str_replace('|', ', ', (string) $r['nature'])).'</td>' : '');
		print '<td>'.dol_escape_htmltag(str_replace('|', ', ', (string) ($r['pcg_type'] ?? ''))).'</td><td>'.dol_escape_htmltag(str_replace('|', ', ', (string) ($r['account_prefix'] ?? ''))).'</td><td>'.dol_escape_htmltag(str_replace('|', ', ', (string) ($r['label_contains'] ?? ''))).'</td>';
		print '<td>'.$sets_.'</td><td class="opacitymedium">'.dol_escape_htmltag((string) ($r['comment'] ?? '')).'</td><td>'.$matchCell($perRule[$i]).'</td></tr>';
	}
	if (!$rules) {
		print '<tr><td colspan="8" class="opacitymedium">'.$langs->trans('FinRepRulesNone').'</td></tr>';
	}
	print '</table>';
} else {
	// ---- My rules: editable ----
	print '<form method="POST" action="'.$self.'?set='.urlencode($setKey).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('FinRepRulesOrder').'</th><th class="center">'.$langs->trans('FinRepRulesActive').'</th>';
	if ($kind === 'class') {
		print '<th>'.$langs->trans('FinRepRulesForTypes').'</th>';
	}
	print '<th>'.$langs->trans('FinRepRulesGroup').'</th><th>'.$langs->trans('FinRepRulesPrefix').'</th><th>'.$langs->trans('FinRepRulesLabel').'</th>';
	print ($kind === 'class' ? '<th>'.$langs->trans('FinRepStartClass').'</th>' : '<th>'.$langs->trans('FinRepStartNature').'</th><th>'.$langs->trans('FinRepStartAltNature').'</th><th class="center">'.$langs->trans('FinRepStartContra').'</th>');
	print '<th>'.$langs->trans('FinRepRulesComment').'</th><th>'.$langs->trans('FinRepRulesTakes').'</th><th class="center">'.$langs->trans('Delete').'</th></tr>';
	$natureOptions = array('' => '') + $natures;
	$line = function ($key, $r, $m) use ($form, $kind, $natures, $natureOptions, $classOptions, $matchCell) {
		$n = 'r['.$key.']';
		$isNew = ($key === 'new');
		print '<tr class="oddeven">';
		print '<td><input type="text" class="flat width40" name="'.$n.'[position]" value="'.($isNew ? '' : (int) $r['position']).'"'.($isNew ? ' placeholder="end"' : '').'></td>';
		print '<td class="center">'.($isNew ? '' : '<input type="checkbox" name="'.$n.'[active]" value="1"'.((int) $r['active'] ? ' checked' : '').'>').'</td>';
		if ($kind === 'class') {
			print '<td>'.$form->multiselectarray('r_'.$key.'_natures', $natures, $isNew ? array() : array_filter(explode('|', (string) $r['nature'])), 0, 0, 'minwidth150', 0, 0).'</td>';
		}
		print '<td><input type="text" class="flat minwidth100" name="'.$n.'[pcg_type]" value="'.dol_escape_htmltag($isNew ? '' : $r['pcg_type']).'" placeholder="e.g. EXPENSE"></td>';
		print '<td><input type="text" class="flat minwidth75" name="'.$n.'[account_prefix]" value="'.dol_escape_htmltag($isNew ? '' : $r['account_prefix']).'" placeholder="e.g. 61|62"></td>';
		print '<td><input type="text" class="flat minwidth150" name="'.$n.'[label_contains]" value="'.dol_escape_htmltag($isNew ? '' : $r['label_contains']).'" placeholder="e.g. vehicle|van"></td>';
		if ($kind === 'class') {
			print '<td>'.$form->selectarray($n.'[class]', array('' => '') + $classOptions, $isNew ? '' : strtoupper($r['class']), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td>';
		} else {
			print '<td>'.$form->selectarray($n.'[nature]', $natureOptions, $isNew ? '' : strtoupper($r['nature']), 0, 0, 0, '', 0, 0, 0, '', 'minwidth100').'</td>';
			print '<td>'.$form->selectarray($n.'[nature_alt]', $natureOptions, $isNew ? '' : strtoupper($r['nature_alt']), 0, 0, 0, '', 0, 0, 0, '', 'minwidth100').'</td>';
			print '<td class="center"><input type="checkbox" name="'.$n.'[contra]" value="1"'.(!$isNew && !empty($r['contra']) ? ' checked' : '').'></td>';
		}
		print '<td><input type="text" class="flat minwidth150" name="'.$n.'[comment]" value="'.dol_escape_htmltag($isNew ? '' : $r['comment']).'"></td>';
		print '<td>'.($isNew ? '' : $matchCell($m)).'</td>';
		print '<td class="center">'.($isNew ? '' : '<input type="checkbox" name="'.$n.'[delete]" value="1">').'</td></tr>';
	};
	foreach ($rules as $i => $r) {
		$line((int) $r['rowid'], $r, $perRule[$i]);
	}
	$line('new', array(), array());
	print '</table></div>';
	print '<p class="opacitymedium">'.$langs->trans('FinRepRulesMatchHelp').'</p>';
	print '<div class="center" style="margin:10px 0"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div></form>';

	print '<div class="tabsAction" style="text-align:left">';
	print '<a class="butAction" href="'.$self.'?set='.urlencode($setKey).'&action=apply&token='.newToken().'">'.$langs->trans('FinRepAssignApply').'</a>';
	print '<a class="butAction" href="'.$self.'?set='.urlencode($setKey).'&action=export&token='.newToken().'">'.$langs->trans('FinRepRulesExport').'</a>';
	print '</div>';
	print '<form method="POST" enctype="multipart/form-data" action="'.$self.'?set='.urlencode($setKey).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="import">';
	print $langs->trans('FinRepRulesImport', implode(';', $kind === 'class' ? $classCols : $natureCols)).' <input type="file" name="importfile" accept=".csv,text/csv"> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('FinRepRulesImportButton')).'"></form>';
	print '<p class="opacitymedium">'.$langs->trans('FinRepRulesShare').'</p>';
}

llxFooter();
$db->close();
