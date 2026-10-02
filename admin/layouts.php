<?php
/**
 * Any-Chart Reports Lab — statement layouts (list and on-screen editor).
 *
 * Layouts are stored in this module's own tables. They can be created empty,
 * copied from Dolibarr's personalised reports (read only, core is never written),
 * loaded from a layout file shipped in seed/layouts/, or imported from CSV, then
 * edited here and exported to share.
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
require_once dirname(__DIR__).'/lib/layout.lib.php';

if (!$user->hasRight('anychartlab', 'setup') && !$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$entity = (int) $conf->entity;
$pcgversion = anychartlab_active_chart($db);
$natures = anychartlab_natures();
$statements = array('BS' => 'Balance Sheet', 'IS' => 'Income Statement');
$self = $_SERVER['PHP_SELF'];

/*
 * Actions
 */

if ($action === 'create') {
	$newid = anychartlab_layout_create($db, $entity, GETPOST('new_code', 'alphanohtml') ?: 'MY-LAYOUT', GETPOST('new_label', 'alphanohtml'), GETPOST('new_statement', 'aZ09'), '', 'manual', '');
	header('Location: '.$self.($newid > 0 ? '?id='.$newid : ''));
	exit;
}
if ($action === 'copycore' && $pcgversion !== '') {
	$newid = anychartlab_layout_copy_core($db, $entity, $pcgversion, GETPOSTINT('core_report'), GETPOST('core_statement', 'aZ09') === 'IS' ? 'IS' : 'BS');
	setEventMessages($newid > 0 ? 'Personalised report copied into a new lab layout. Dolibarr\'s own personalised groups were only read, not changed.' : 'Copy failed.', null, $newid > 0 ? 'mesgs' : 'errors');
	header('Location: '.$self.($newid > 0 ? '?id='.$newid : ''));
	exit;
}
if ($action === 'loadfile' && $pcgversion !== '') {
	$file = basename(GETPOST('layout_file', 'alphanohtml'));
	$r = anychartlab_layout_import($db, $entity, $pcgversion, anychartlab_layout_read_csv(anychartlab_seed_dir().'/layouts/'.$file), 'file', $file);
	setEventMessages(count($r['created']).' layout(s) loaded from '.$file.'.', $r['errors'], $r['errors'] ? 'warnings' : 'mesgs');
	header('Location: '.$self.(count($r['created']) === 1 ? '?id='.$r['created'][0] : ''));
	exit;
}
if ($action === 'import' && $pcgversion !== '' && !empty($_FILES['importfile']['tmp_name'])) {
	$r = anychartlab_layout_import($db, $entity, $pcgversion, anychartlab_layout_read_csv($_FILES['importfile']['tmp_name']), 'import', dol_sanitizeFileName($_FILES['importfile']['name']));
	setEventMessages(count($r['created']).' layout(s) imported.', $r['errors'], $r['errors'] ? 'warnings' : 'mesgs');
	header('Location: '.$self);
	exit;
}
if ($action === 'confirm_delete' && GETPOST('confirm', 'alpha') === 'yes' && $id) {
	anychartlab_layout_delete($db, $entity, $id);
	setEventMessages('Layout deleted.', null);
	header('Location: '.$self);
	exit;
}
if (($action === 'export' || $action === 'duplicate') && $id) {
	$layout = anychartlab_layout_fetch($db, $id, $entity);
	if ($layout) {
		$rows = anychartlab_layout_export_rows($layout, anychartlab_load_accounts($db, $entity, $pcgversion));
		if ($action === 'export') {
			anychartlab_send_csv('anychartlab-layout-'.$layout->code, anychartlab_layout_csv_columns(), $rows);
		}
		foreach ($rows as &$r) {
			$r[0] = $layout->code.'-COPY';
			$r[1] = $layout->label.' (copy)';
		}
		unset($r);
		$assoc = array();
		foreach ($rows as $r) {
			$assoc[] = array_combine(anychartlab_layout_csv_columns(), $r);
		}
		$res2 = anychartlab_layout_import($db, $entity, $pcgversion, $assoc, 'manual', 'copy of '.$layout->code);
		header('Location: '.$self.($res2['created'] ? '?id='.$res2['created'][0] : ''));
		exit;
	}
}
if (($action === 'up' || $action === 'down') && $id) {
	$lineid = GETPOSTINT('lineid');
	$layout = anychartlab_layout_fetch($db, $id, $entity);
	if ($layout) {
		$ids = array_map(function ($l) {
			return (int) $l->rowid;
		}, $layout->lines);
		$k = array_search($lineid, $ids, true);
		$j = ($action === 'up' ? $k - 1 : $k + 1);
		if ($k !== false && isset($ids[$j])) {
			$tmp = $ids[$k];
			$ids[$k] = $ids[$j];
			$ids[$j] = $tmp;
			foreach ($ids as $i => $lid) {
				$db->query("UPDATE ".MAIN_DB_PREFIX."anychartlab_layout_line SET position = ".(($i + 1) * 10)." WHERE rowid = ".((int) $lid));
			}
		}
	}
	header('Location: '.$self.'?id='.$id.'#line'.$lineid);
	exit;
}
if ($action === 'save' && $id) {
	$layout = anychartlab_layout_fetch($db, $id, $entity);
	if ($layout) {
		$db->begin();
		$db->query("UPDATE ".MAIN_DB_PREFIX."anychartlab_layout SET label = '".$db->escape(GETPOST('label', 'alphanohtml') ?: $layout->label)."', statement = '".(GETPOST('statement', 'aZ09') === 'IS' ? 'IS' : 'BS')."', country_code = ".(GETPOST('country_code', 'aZ09') !== '' ? "'".$db->escape(GETPOST('country_code', 'aZ09'))."'" : "NULL")." WHERE rowid = ".((int) $id));
		$del = GETPOST('delete_line', 'array:int');
		foreach ($layout->lines as $l) {
			$lid = (int) $l->rowid;
			if (in_array($lid, $del)) {
				anychartlab_layout_delete_line($db, $id, $lid);
				continue;
			}
			$data = array(
				'position' => GETPOSTINT('position_'.$lid),
				'code' => GETPOST('code_'.$lid, 'alphanohtml'),
				'label' => GETPOST('label_'.$lid, 'alphanohtml'),
				'type' => GETPOST('type_'.$lid, 'aZ09'),
				'natures' => implode('|', GETPOST('natures_'.$lid, 'array:aZ09')),
				'accounts' => GETPOST('accounts_'.$lid, 'alphanohtml'),
				'formula' => GETPOST('formula_'.$lid, 'alphanohtml'),
				'sign' => GETPOST('sign_'.$lid, 'aZ09'),
			);
			anychartlab_layout_save_line($db, $id, $lid, $data);
			if ($data['type'] === 'group') {
				anychartlab_layout_set_explicit($db, $lid, GETPOST('explicit_'.$lid, 'array:int'));
			}
		}
		if (trim(GETPOST('label_new', 'alphanohtml')) !== '' || trim(GETPOST('code_new', 'alphanohtml')) !== '') {
			$lid = anychartlab_layout_save_line($db, $id, 0, array(
				'position' => GETPOSTINT('position_new') ?: 9999,
				'code' => GETPOST('code_new', 'alphanohtml'),
				'label' => GETPOST('label_new', 'alphanohtml'),
				'type' => GETPOST('type_new', 'aZ09'),
				'natures' => implode('|', GETPOST('natures_new', 'array:aZ09')),
				'accounts' => GETPOST('accounts_new', 'alphanohtml'),
				'formula' => GETPOST('formula_new', 'alphanohtml'),
				'sign' => GETPOST('sign_new', 'aZ09'),
			));
		}
		anychartlab_layout_renumber($db, $id);
		$db->commit();
		setEventMessages('Layout saved.', null);
	}
	header('Location: '.$self.'?id='.$id);
	exit;
}

/*
 * View
 */

$form = new Form($db);
llxHeader('', 'Statement layouts', '', '', 0, 0, '', '', '', 'mod-anychartlab page-layouts');
print load_fiche_titre('Statement layouts', '', 'accountancy');
anychartlab_print_nav('admin/layouts.php', $pcgversion);

if ($pcgversion === '') {
	print '<div class="warning">No chart of accounts is selected.</div>';
	llxFooter();
	exit;
}

$accounts = anychartlab_load_accounts($db, $entity, $pcgversion);
$layout = ($id ? anychartlab_layout_fetch($db, $id, $entity) : null);

if (!$layout) {
	// ---- List ----
	if ($action === 'delete') {
		print $form->formconfirm($self.'?id='.$id, 'Delete layout', 'Delete this lab layout? Dolibarr\'s personalised reports are not affected.', 'confirm_delete', '', 0, 1);
	}
	print '<div class="info">A <b>layout</b> arranges the Balance Sheet or Income Statement into the lines your country uses (e.g. current / non-current assets, gross profit). Layouts are stored in this lab only: copying a Dolibarr personalised report <b>reads</b> it and never changes it. Choose a layout on the Balance Sheet or Income Statement page with the <b>Layout</b> selector.</div>';

	$list = anychartlab_layouts_list($db, $entity);
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>Layout</th><th>Code</th><th>Statement</th><th>Source</th><th class="center">Lines</th><th></th></tr>';
	foreach ($list as $l) {
		$test = dol_buildpath('/anychartlab/'.($l->statement === 'IS' ? 'incomestatement.php' : 'balancesheet.php'), 1).'?layout='.$l->rowid;
		print '<tr class="oddeven"><td><a href="'.$self.'?id='.$l->rowid.'"><b>'.dol_escape_htmltag($l->label).'</b></a></td><td>'.dol_escape_htmltag($l->code).'</td><td>'.$statements[$l->statement].'</td>';
		print '<td class="opacitymedium">'.dol_escape_htmltag($l->source.($l->source_ref ? ': '.$l->source_ref : '')).'</td><td class="center">'.((int) $l->nblines).'</td>';
		print '<td class="right nowraponall"><a href="'.$self.'?id='.$l->rowid.'">Edit</a> · <a href="'.$test.'">View report</a> · <a href="'.$self.'?id='.$l->rowid.'&action=export&token='.newToken().'">Export</a> · <a href="'.$self.'?id='.$l->rowid.'&action=duplicate&token='.newToken().'">Duplicate</a> · <a href="'.$self.'?id='.$l->rowid.'&action=delete&token='.newToken().'">Delete</a></td></tr>';
	}
	if (!$list) {
		print '<tr><td colspan="6" class="opacitymedium">No layout yet. Create one below.</td></tr>';
	}
	print '</table><br>';

	print '<table class="noborder centpercent"><tr class="liste_titre"><th colspan="2">Create a layout</th></tr>';
	print '<tr class="oddeven"><td class="titlefield">Load a layout file</td><td><form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="loadfile">';
	$fileOptions = array();
$preselect = '';
foreach (anychartlab_catalog_ranked('layout', $pcgversion) as $e) {
	$fileOptions[$e['file']] = ($e['match'] !== '' ? '★ ' : '').$e['label'].($e['match'] === 'chart' ? ' (made for chart '.$pcgversion.')' : ($e['match'] === 'country' ? ' (for your country)' : ''));
	if ($preselect === '' && $e['match'] !== '') {
		$preselect = $e['file'];
	}
}
foreach (anychartlab_layout_files() as $f => $desc) {
	if (!isset($fileOptions[$f])) {
		$fileOptions[$f] = $desc;
	}
}
print $form->selectarray('layout_file', $fileOptions, $preselect, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').' <input type="submit" class="button small" value="Load"> <span class="opacitymedium">★ = suggested for your chart / country</span></form></td></tr>';
	print '<tr class="oddeven"><td>Copy a Dolibarr personalised report</td><td><form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="copycore">';
	print $form->selectarray('core_report', anychartlab_core_reports($db, $entity), '', 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').' as '.$form->selectarray('core_statement', $statements, 'IS').' <input type="submit" class="button small" value="Copy"> <span class="opacitymedium">(read only: your personalised groups are not changed)</span></form></td></tr>';
	print '<tr class="oddeven"><td>Import a layout CSV</td><td><form method="POST" enctype="multipart/form-data" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="import"><input type="file" name="importfile" accept=".csv,text/csv"> <input type="submit" class="button small" value="Import"></form></td></tr>';
	print '<tr class="oddeven"><td>New empty layout</td><td><form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="create">';
	print 'Name <input type="text" name="new_label" class="flat minwidth200" placeholder="e.g. My balance sheet"> Code <input type="text" name="new_code" class="flat maxwidth100" placeholder="MY-BS"> '.$form->selectarray('new_statement', $statements, 'BS').' <input type="submit" class="button small" value="Create"></form></td></tr>';
	print '</table>';
	llxFooter();
	$db->close();
	exit;
}

// ---- Editor ----
$check = anychartlab_layout_static_check($layout, $accounts);
$values = array('RESULT' => 0.0);
$formulaErrors = array();
foreach ($layout->lines as $l) {
	if ($l->type === 'formula') {
		$errs = array();
		anychartlab_formula_eval($l->formula, $values, $errs);
		foreach ($errs as $e) {
			$formulaErrors[] = 'Line '.$l->code.': unknown code "'.$e.'" (codes must be defined on an earlier line'.($layout->statement === 'BS' ? '; RESULT = result of unclosed periods' : '').').';
		}
	}
	if ($l->type !== 'heading') {
		$values[strtoupper($l->code)] = 0.0;
	}
}

$accountOptions = array();
foreach ($accounts['byRowid'] as $acc) {
	$accountOptions[(int) $acc->rowid] = $acc->account_number.' - '.$acc->label.($acc->nature ? ' ('.$acc->nature.')' : '');
}
$natureOptions = $natures;
$reportUrl = dol_buildpath('/anychartlab/'.($layout->statement === 'IS' ? 'incomestatement.php' : 'balancesheet.php'), 1).'?layout='.$layout->rowid;

print '<p><a href="'.$self.'">← All layouts</a> · <a href="'.$reportUrl.'"><b>View the report with this layout</b></a> · <a href="'.$self.'?id='.$layout->rowid.'&action=export&token='.newToken().'">Export CSV</a></p>';

// Checks
$problems = array();
if ($check['dupcodes']) {
	$problems[] = 'Duplicate line codes: '.implode(', ', $check['dupcodes']).'. Formulas will use the last one.';
}
$problems = array_merge($problems, $formulaErrors);
$catchalls = array();
foreach ($layout->lines as $l) {
	if ($l->type === 'group' && empty($check['counts'][(int) $l->rowid])) {
		$isCatchAll = ((string) $l->natures !== '' && trim(str_replace('!', '', preg_replace('/![^,|; ]+/', '', (string) $l->accounts)), ',|; ') === '' && empty($layout->explicit[(int) $l->rowid]));
		if ($isCatchAll) {
			$catchalls[] = $l->code;	// a safety net for accounts no earlier line takes: fine to be empty
		} else {
			$problems[] = 'Line '.$l->code.' ('.$l->label.') takes no account of this chart.';
		}
	}
}
if ($check['unmatched']) {
	$list = array();
	foreach (array_slice($check['unmatched'], 0, 25) as $acc) {
		$list[] = $acc->account_number.' '.$acc->label;
	}
	$problems[] = count($check['unmatched']).' account(s) of this chart are taken by no line: '.implode(', ', $list).(count($check['unmatched']) > 25 ? '…' : '').'. Add a catch-all line (a nature with no prefixes) at the end.';
}
if ($catchalls) {
	print '<div class="info">Catch-all line(s) '.dol_escape_htmltag(implode(', ', $catchalls)).' take no account right now: earlier lines take them all. They stay as a safety net (e.g. for a balance on its unusual side, or a new account).</div>';
}
print $problems ? '<div class="warning"><b>Checks against chart '.dol_escape_htmltag($pcgversion).':</b><ul><li>'.implode('</li><li>', array_map('dol_escape_htmltag', $problems)).'</li></ul></div>' : '<div class="ok">Checks against chart '.dol_escape_htmltag($pcgversion).': every account is taken by a line, every formula code is known.</div>';

print '<form method="POST" action="'.$self.'?id='.$layout->rowid.'" name="layoutform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="border centpercent"><tr><td class="titlefield">Name</td><td><input type="text" name="label" class="flat minwidth300" value="'.dol_escape_htmltag($layout->label).'"></td>';
print '<td>Code</td><td>'.dol_escape_htmltag($layout->code).'</td>';
print '<td>Statement</td><td>'.$form->selectarray('statement', $statements, $layout->statement).'</td>';
print '<td>Country</td><td><input type="text" name="country_code" class="flat maxwidth50" value="'.dol_escape_htmltag((string) $layout->country_code).'" placeholder="AU"></td></tr>';
print '<tr><td>Source</td><td colspan="7" class="opacitymedium">'.dol_escape_htmltag($layout->source.($layout->source_ref ? ': '.$layout->source_ref : '')).'</td></tr></table>';

print '<p class="opacitymedium">Each account goes to the <b>first</b> line that takes it: explicitly assigned accounts first, then rules in order. <b>Natures</b>: which natures the line takes (empty = any). <b>Accounts</b>: number prefixes separated by commas, <code>!1301</code> excludes, <code>1101D</code> / <code>2338C</code> only when the balance is in debit / credit (empty = all accounts of those natures). <b>Formula</b>: codes of earlier lines with + − * / ( )'.($layout->statement === 'BS' ? ', and <code>RESULT</code> = result of unclosed periods' : '').'. <b>Sign</b>: natural shows each account the way its nature reads (assets and expenses debit, the rest credit); credit − debit / debit − credit match Dolibarr personalised groups.</p>';

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Pos.</th><th>Code</th><th>Label</th><th>Type</th><th>Natures</th><th>Accounts (prefixes)</th><th>Formula</th><th>Sign</th><th>Explicit accounts</th><th class="center">Takes</th><th class="center">Delete</th><th></th></tr>';

$lineRow = function ($key, $l) use ($form, $natureOptions, $accountOptions, $layout, $check, $self) {
	$isNew = ($key === 'new');
	$type = $isNew ? 'group' : $l->type;
	print '<tr class="oddeven"'.($isNew ? '' : ' id="line'.$l->rowid.'"').'>';
	print '<td><input type="text" name="position_'.$key.'" class="flat width40" value="'.($isNew ? '' : (int) $l->position).'"'.($isNew ? ' placeholder="end"' : '').'></td>';
	print '<td><input type="text" name="code_'.$key.'" class="flat width75" value="'.($isNew ? '' : dol_escape_htmltag($l->code)).'"'.($isNew ? ' placeholder="NEW"' : '').'></td>';
	print '<td><input type="text" name="label_'.$key.'" class="flat minwidth150" value="'.($isNew ? '' : dol_escape_htmltag($l->label)).'"'.($isNew ? ' placeholder="Add a line…"' : '').'></td>';
	print '<td>'.$form->selectarray('type_'.$key, anychartlab_layout_types(), $type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100').'</td>';
	print '<td>'.$form->multiselectarray('natures_'.$key, $natureOptions, $isNew ? array() : array_filter(explode('|', (string) $l->natures)), 0, 0, 'minwidth150', 0, 0).'</td>';
	print '<td><input type="text" name="accounts_'.$key.'" class="flat minwidth100" value="'.($isNew ? '' : dol_escape_htmltag((string) $l->accounts)).'" placeholder="e.g. 11,12,!1301"></td>';
	print '<td><input type="text" name="formula_'.$key.'" class="flat minwidth100" value="'.($isNew ? '' : dol_escape_htmltag((string) $l->formula)).'" placeholder="e.g. REVENUE-COGS"></td>';
	print '<td>'.$form->selectarray('sign_'.$key, anychartlab_layout_signs(), $isNew ? 'natural' : $l->sign, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100').'</td>';
	if ($isNew) {
		print '<td class="opacitymedium">(after saving)</td><td></td><td></td><td></td>';
	} else {
		print '<td>'.($type === 'group' ? $form->multiselectarray('explicit_'.$l->rowid, $accountOptions, $layout->explicit[(int) $l->rowid] ?? array(), 0, 0, 'minwidth200', 0, 0) : '').'</td>';
		print '<td class="center">'.($type === 'group' ? (int) ($check['counts'][(int) $l->rowid] ?? 0) : '').'</td>';
		print '<td class="center"><input type="checkbox" name="delete_line[]" value="'.$l->rowid.'"></td>';
		print '<td class="nowraponall"><a href="'.$self.'?id='.$layout->rowid.'&action=up&lineid='.$l->rowid.'&token='.newToken().'" title="Move up">'.img_picto('', '1uparrow').'</a> <a href="'.$self.'?id='.$layout->rowid.'&action=down&lineid='.$l->rowid.'&token='.newToken().'" title="Move down">'.img_picto('', '1downarrow').'</a></td>';
	}
	print '</tr>';
};
foreach ($layout->lines as $l) {
	$lineRow((int) $l->rowid, $l);
}
$lineRow('new', null);
print '</table></div>';
print '<div class="center" style="margin:10px 0"><input type="submit" class="button button-save" value="Save layout"> <a class="button button-cancel" href="'.$self.'?id='.$layout->rowid.'">Cancel</a></div>';
print '</form>';

llxFooter();
$db->close();
