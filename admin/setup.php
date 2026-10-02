<?php
/**
 * Any-Chart Reports Lab — account natures (setup).
 *
 * Lists every account of the active chart with its nature, alternate nature,
 * contra flag and where the value came from. Lets the user:
 *  - apply the default rules (chart rules, then generic rules, then parent),
 *  - edit natures inline or in bulk,
 *  - export the mapping as CSV (to share it) and import a corrected one,
 *  - reset and start again.
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
require_once dirname(__DIR__).'/lib/anychartlab.lib.php';
require_once dirname(__DIR__).'/lib/cashflow.lib.php';

if (!$user->hasRight('anychartlab', 'setup') && !$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$searchNature = GETPOST('search_nature', 'aZ09');
$searchPcgType = GETPOST('search_pcgtype', 'alphanohtml');
$searchAccount = GETPOST('search_account', 'alphanohtml');
$showDraft = GETPOSTINT('show_draft');
if ($searchNature === '-1') {
	$searchNature = '';	// empty choice of selectarray()
}
if ($searchPcgType === '-1') {
	$searchPcgType = '';
}

$entity = (int) $conf->entity;
$pcgversion = anychartlab_active_chart($db);
$natures = anychartlab_natures();
$backparams = 'search_nature='.urlencode($searchNature).'&search_pcgtype='.urlencode($searchPcgType).'&search_account='.urlencode($searchAccount).'&show_draft='.$showDraft;

/*
 * Actions
 */

if ($pcgversion !== '') {
	if ($action === 'apply' || $action === 'suggest') {
		$ruleFile = ($action === 'suggest' ? null : basename(GETPOST('ruleset', 'alphanohtml')));
		if ($ruleFile !== null && $ruleFile === '_generic') {
			$ruleFile = '';
		}
		$c = anychartlab_apply_defaults($db, $user, $entity, $pcgversion, $ruleFile);
		if ($action === 'suggest') {
			require_once dirname(__DIR__).'/lib/layout.lib.php';
			$existing = array();
			foreach (anychartlab_layouts_list($db, $entity) as $lo) {
				$existing[$lo->source_ref] = 1;
			}
			$loaded = array();
			foreach (anychartlab_suggest_layouts($pcgversion) as $e) {
				if (!isset($existing[$e['file']])) {
					$r = anychartlab_layout_import($db, $entity, $pcgversion, anychartlab_layout_read_csv(anychartlab_seed_dir().'/layouts/'.$e['file']), 'file', $e['file']);
					if ($r['created']) {
						$loaded[] = $e['label'];
					}
				}
			}
			if ($loaded) {
				setEventMessages('Layouts loaded: '.implode(', ', $loaded).'. Choose them with the Layout selector on the Balance Sheet / Income Statement.', null);
			}
		}
		setEventMessages('Default natures applied: '.$c['chart'].' by chart rules'.($c['chartfile'] ? ' ('.$c['chartfile'].')' : ' (no rule file for this chart)').', '.$c['generic'].' by generic rules, '.$c['parent'].' from the parent account. Still unclassified: '.$c['remaining'].'.', null, $c['remaining'] ? 'warnings' : 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?'.$backparams);
		exit;
	}

	if ($action === 'confirm_reset' && GETPOST('confirm', 'alpha') === 'yes') {
		$n = anychartlab_reset($db, $entity, $pcgversion);
		setEventMessages($n.' account natures removed.', null);
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}

	if ($action === 'save') {
		$accounts = anychartlab_load_accounts($db, $entity, $pcgversion);
		$postNature = GETPOST('nature', 'array');
		$postAlt = GETPOST('nature_alt', 'array');
		$postContra = GETPOST('contra', 'array');
		$postCf = GETPOST('cf_class', 'array');
		$toselect = GETPOST('toselect', 'array:int');
		$bulkNature = GETPOST('bulk_nature', 'aZ09');
		$changed = 0;
		$db->begin();
		foreach ($postNature as $rowid => $nature) {
			$acc = $accounts['byRowid'][(int) $rowid] ?? null;
			if (!$acc) {
				continue;
			}
			$nature = (isset($natures[$nature]) ? $nature : null);
			$alt = (isset($postAlt[$rowid]) && isset($natures[$postAlt[$rowid]]) ? $postAlt[$rowid] : null);
			$contra = !empty($postContra[$rowid]) ? 1 : 0;
			if ($bulkNature !== '' && in_array((int) $rowid, $toselect)) {
				$nature = ($bulkNature === '_none' ? null : (isset($natures[$bulkNature]) ? $bulkNature : $nature));
			}
			if ($nature !== $acc->nature || $alt !== $acc->nature_alt || $contra !== (int) $acc->contra) {
				anychartlab_save_nature($db, $user, $entity, (int) $rowid, $nature, $alt, $contra, 'manual');
				$changed++;
			}
			if (isset($postCf[$rowid])) {
				$cf = (isset(anychartlab_cf_classes()[$postCf[$rowid]]) ? $postCf[$rowid] : null);
				if ($cf !== $acc->cf_class) {
					anychartlab_save_cf_class($db, $user, $entity, (int) $rowid, $cf);
					$changed++;
				}
			}
		}
		$db->commit();
		setEventMessages($changed.' account(s) updated.', null);
		header('Location: '.$_SERVER['PHP_SELF'].'?'.$backparams);
		exit;
	}

	if ($action === 'export') {
		$accounts = anychartlab_load_accounts($db, $entity, $pcgversion);
		$rows = array();
		foreach ($accounts['byRowid'] as $acc) {
			$rows[] = array($acc->account_number, $acc->label, $acc->pcg_type, (string) $acc->nature, (string) $acc->nature_alt, (int) $acc->contra, (string) $acc->source, (string) $acc->cf_class);
		}
		anychartlab_send_csv('anychartlab-natures-'.$pcgversion, array('account_number', 'label', 'pcg_type', 'nature', 'nature_alt', 'contra', 'source', 'cf_class'), $rows);
	}

	if ($action === 'import' && !empty($_FILES['importfile']['tmp_name'])) {
		$accounts = anychartlab_load_accounts($db, $entity, $pcgversion);
		$fh = fopen($_FILES['importfile']['tmp_name'], 'r');
		$header = null;
		$done = 0;
		$unknown = array();
		$invalid = array();
		$db->begin();
		while (($line = fgets($fh)) !== false) {
			$line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));
			if ($line === '' || $line[0] === '#') {
				continue;
			}
			$cols = str_getcsv($line, (substr_count($line, ';') >= substr_count($line, ',') ? ';' : ','), '"', '\\');
			if ($header === null) {
				$header = array_flip(array_map('strtolower', array_map('trim', $cols)));
				if (!isset($header['account_number']) || !isset($header['nature'])) {
					setEventMessages('The file must have at least the columns account_number and nature (as produced by Export).', null, 'errors');
					break;
				}
				continue;
			}
			$number = trim((string) ($cols[$header['account_number']] ?? ''));
			$acc = $accounts['byNumber'][$number] ?? null;
			if (!$acc) {
				$unknown[] = $number;
				continue;
			}
			$nature = strtoupper(trim((string) ($cols[$header['nature']] ?? '')));
			$alt = isset($header['nature_alt']) ? strtoupper(trim((string) ($cols[$header['nature_alt']] ?? ''))) : '';
			$contra = isset($header['contra']) ? (int) ($cols[$header['contra']] ?? 0) : 0;
			if (($nature !== '' && !isset($natures[$nature])) || ($alt !== '' && !isset($natures[$alt]))) {
				$invalid[] = $number;
				continue;
			}
			anychartlab_save_nature($db, $user, $entity, (int) $acc->rowid, ($nature ?: null), ($alt ?: null), $contra, 'import');
			if (isset($header['cf_class'])) {
				anychartlab_save_cf_class($db, $user, $entity, (int) $acc->rowid, strtoupper(trim((string) ($cols[$header['cf_class']] ?? ''))));
			}
			$done++;
		}
		fclose($fh);
		$db->commit();
		if ($header !== null) {
			setEventMessages($done.' account(s) imported.'.($unknown ? ' Not in this chart: '.implode(', ', array_slice($unknown, 0, 20)).(count($unknown) > 20 ? '…' : '').'.' : '').($invalid ? ' Invalid nature: '.implode(', ', array_slice($invalid, 0, 20)).'.' : ''), null, ($unknown || $invalid) ? 'warnings' : 'mesgs');
		}
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}

/*
 * View
 */

$form = new Form($db);
llxHeader('', 'Account natures', '', '', 0, 0, '', '', '', 'mod-anychartlab page-setup');

print load_fiche_titre('Account natures', '', 'accountancy');
anychartlab_print_nav('admin/setup.php', $pcgversion);

if ($pcgversion === '') {
	print '<div class="warning">No chart of accounts is selected. Choose one in Accounting &gt; Setup &gt; Chart of accounts first.</div>';
	llxFooter();
	$db->close();
	exit;
}

if ($action === 'reset') {
	print $form->formconfirm($_SERVER['PHP_SELF'], 'Reset all natures', 'Remove the nature of every account of chart '.dol_escape_htmltag($pcgversion).'? Nothing in the accounting itself is changed.', 'confirm_reset', '', 0, 1);
}

$accounts = anychartlab_load_accounts($db, $entity, $pcgversion);
$banks = anychartlab_cf_bank_accounts($db, $entity);
$counts = array('_none' => 0);
$pcgtypes = array();
foreach ($accounts['byRowid'] as $acc) {
	$key = ($acc->nature ?? '_none');
	$counts[$key] = ($counts[$key] ?? 0) + 1;
	$pcgtypes[(string) $acc->pcg_type] = (string) $acc->pcg_type;
}
ksort($pcgtypes);

$classified = 0;
foreach ($accounts['byRowid'] as $acc) {
	if ($acc->nature !== null) {
		$classified++;
	}
}
if ($classified === 0) {
	$sug = anychartlab_suggest_ruleset($pcgversion);
	$sugLayouts = anychartlab_suggest_layouts($pcgversion);
	print '<div class="info" style="border-left:4px solid #2a7">';
	print '<b>Detected:</b> company country <b>'.dol_escape_htmltag(anychartlab_country_code() ?: '?').'</b>, chart <b>'.dol_escape_htmltag($pcgversion).'</b>.<br>';
	print '<b>Suggested:</b> rule set <b>'.dol_escape_htmltag($sug ? $sug['label'] : 'generic rules only').'</b>';
	if ($sugLayouts) {
		print ', layouts <b>'.dol_escape_htmltag(implode(', ', array_map(function ($e) {
			return $e['label'];
		}, $sugLayouts))).'</b>';
	}
	print '. <a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=suggest&token='.newToken().'">Apply suggestions</a></div>';
}
print '<div class="info">';
print 'Each account gets a <b>nature</b> (which statement and side it belongs to), an optional <b>alternate nature</b> used when its balance is on the other side (e.g. a bank account in overdraft becomes a liability), and a <b>contra</b> flag (stays on its side, shown as a deduction, e.g. accumulated depreciation). ';
print '<b>Apply default natures</b> fills empty natures from the rule files (this chart\'s rules, then generic rules, then the parent account). It never overwrites a nature already set. ';
print 'Please <b>export your mapping</b> once it is right for your country and share it on <a href="https://github.com/Dolibarr/dolibarr/issues/31760" target="_blank" rel="noopener">#31760</a>.';
print '</div>';

$summary = array();
foreach ($natures as $code => $label) {
	if (!empty($counts[$code])) {
		$summary[] = $label.': '.$counts[$code];
	}
}
print '<p>'.count($accounts['byRowid']).' accounts in chart <b>'.dol_escape_htmltag($pcgversion).'</b>. '.implode(' · ', $summary).($counts['_none'] ? ' · <span class="error">Unclassified: '.$counts['_none'].'</span>' : '').'</p>';

// Toolbar
print '<div class="tabsAction" style="text-align:left">';
$suggestion = anychartlab_suggest_ruleset($pcgversion);
$rulesetOptions = array('_generic' => 'Generic rules only');
foreach (anychartlab_catalog_ranked('nature', $pcgversion) as $e) {
	$rulesetOptions[$e['file']] = $e['label'].($e['match'] === 'chart' ? ' (made for chart '.$pcgversion.')' : ($e['match'] === 'country' ? ' (for your country)' : ''));
}
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" style="display:inline-block;margin-right:8px">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="apply">';
print 'Rule set '.$form->selectarray('ruleset', $rulesetOptions, $suggestion ? $suggestion['file'] : '_generic', 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').' ';
print '<input type="submit" class="butAction" value="Apply default natures"></form>';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=export&token='.newToken().'">Export mapping (CSV)</a>';
print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?action=reset&token='.newToken().'">Reset all</a>';
print '</div>';

print '<form method="POST" enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'" style="margin-bottom:12px">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="import">';
print 'Import a mapping (CSV with columns account_number;nature;nature_alt;contra and optionally cf_class, as produced by Export): ';
print '<input type="file" name="importfile" accept=".csv,text/csv"> <input type="submit" class="button small" value="Import">';
print '</form>';

// Filters + list
$natureOptions = array('' => '');
foreach ($natures as $code => $label) {
	$natureOptions[$code] = $code;
}
$filterOptions = array('_none' => 'Unclassified') + $natures;

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="natureform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre_filter">';
print '<td></td>';
print '<td><input type="text" class="flat maxwidth100" name="search_account" value="'.dol_escape_htmltag($searchAccount).'" placeholder="number or label"></td>';
print '<td></td>';
print '<td>'.$form->selectarray('search_pcgtype', $pcgtypes, $searchPcgType, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
print '<td>'.$form->selectarray('search_nature', $filterOptions, $searchNature, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
print '<td colspan="'.($showDraft ? 5 : 4).'"><label><input type="checkbox" name="show_draft" value="1"'.($showDraft ? ' checked' : '').'> Show design-draft codes</label> ';
print '<input type="submit" class="button small" name="button_search" value="Filter" onclick="this.form.action.value=\'\';"></td>';
print '</tr>';
print '<tr class="liste_titre">';
print '<th class="center"><input type="checkbox" id="checkall" title="Select all"></th>';
print '<th>Account</th><th>Label</th><th>Group (pcg_type)</th><th>Nature</th><th>Alternate nature</th><th class="center">Contra</th><th title="Class of the account in the Cash Flow statement. Auto = suggested from the bank accounts and the label">Cash flow</th><th>Source</th>';
if ($showDraft) {
	print '<th>Design-draft code</th>';
}
print '</tr>';

$shown = 0;
foreach ($accounts['byRowid'] as $acc) {
	if ($searchNature !== '' && ($searchNature === '_none' ? $acc->nature !== null : $acc->nature !== $searchNature)) {
		continue;
	}
	if ($searchPcgType !== '' && $searchPcgType !== '-1' && (string) $acc->pcg_type !== $searchPcgType) {
		continue;
	}
	if ($searchAccount !== '' && stripos($acc->account_number.' '.$acc->label, $searchAccount) === false) {
		continue;
	}
	$shown++;
	print '<tr class="oddeven">';
	print '<td class="center"><input type="checkbox" class="checkforselect" name="toselect[]" value="'.$acc->rowid.'"></td>';
	print '<td class="nowraponall"><a href="'.anychartlab_ledger_url($acc->account_number).'" target="_blank">'.dol_escape_htmltag($acc->account_number).'</a></td>';
	print '<td class="tdoverflowmax300" title="'.dol_escape_htmltag($acc->label).'">'.dol_escape_htmltag($acc->label).'</td>';
	print '<td>'.dol_escape_htmltag((string) $acc->pcg_type).'</td>';
	print '<td>'.$form->selectarray('nature['.$acc->rowid.']', $natureOptions, (string) $acc->nature, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100'.($acc->nature === null ? ' error' : '')).'</td>';
	print '<td>'.$form->selectarray('nature_alt['.$acc->rowid.']', $natureOptions, (string) $acc->nature_alt, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100').'</td>';
	print '<td class="center"><input type="checkbox" name="contra['.$acc->rowid.']" value="1"'.($acc->contra ? ' checked' : '').'></td>';
	$cfSuggest = anychartlab_cf_suggest($acc, $accounts, $banks, $pcgversion);
	if ($cfSuggest === 'PL' && empty($acc->cf_class)) {
		print '<td class="opacitymedium">in the profit</td>';
	} else {
		$cfOptions = array('' => 'auto: '.strtolower($cfSuggest === 'PL' ? 'profit' : $cfSuggest));
		foreach (anychartlab_cf_classes() as $code => $label) {
			$cfOptions[$code] = $label;
		}
		print '<td>'.$form->selectarray('cf_class['.$acc->rowid.']', $cfOptions, (string) $acc->cf_class, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100').'</td>';
	}
	print '<td class="opacitymedium">'.dol_escape_htmltag((string) $acc->source).'</td>';
	if ($showDraft) {
		print '<td>'.dol_escape_htmltag(anychartlab_draft_code($acc)).'</td>';
	}
	print '</tr>';
}
if (!$shown) {
	print '<tr><td colspan="10" class="opacitymedium">No account matches the filter.</td></tr>';
}
print '</table></div>';

print '<div class="center" style="margin:10px 0">';
print 'Set nature of ticked accounts to '.$form->selectarray('bulk_nature', array('' => '', '_none' => '(clear)') + $natureOptions, '', 0, 0, 0, '', 0, 0, 0, '', 'minwidth100').' ';
print '<input type="submit" class="button button-save" value="Save changes">';
print '</div>';
print '</form>';

print '<script nonce="'.getNonce().'">$("#checkall").on("change", function() { $("input.checkforselect").prop("checked", this.checked); });</script>';

llxFooter();
$db->close();
