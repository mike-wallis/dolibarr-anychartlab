<?php
/**
 * Any-Chart Reports Lab — statement layouts.
 *
 * A layout is an ordered list of lines:
 *   heading  a title (e.g. "Current assets")
 *   group    takes accounts: explicitly assigned ones, and/or by nature and
 *            account-number prefixes (Odoo-style: "11,12,!1301,512D")
 *   formula  a value computed from earlier line codes (e.g. "REVENUE - COGS")
 *
 * Each account goes to the first line that takes it (explicit assignments first,
 * then rules by position). Accounts no line takes are reported as "Not in layout",
 * never dropped.
 *
 * Layouts live in this module's own tables. Dolibarr's personalised groups
 * (llx_c_accounting_report / llx_c_accounting_category) are only READ, to make a
 * copy. Nothing is ever written to them.
 */

/**
 * @return array<string,string>
 */
function anychartlab_layout_types()
{
	return array('heading' => 'Heading', 'group' => 'Group of accounts', 'formula' => 'Formula');
}

/**
 * @return array<string,string>
 */
function anychartlab_layout_signs()
{
	return array('natural' => 'Natural (by nature)', 'cd' => 'Credit − debit', 'dc' => 'Debit − credit');
}

/**
 * @param DoliDB $db
 * @param int    $entity
 * @param string $statement '' for all, BS or IS
 * @return array<int,object>
 */
function anychartlab_layouts_list($db, $entity, $statement = '')
{
	$out = array();
	$sql = "SELECT l.rowid, l.code, l.label, l.statement, l.country_code, l.source, l.source_ref, (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."anychartlab_layout_line as ll WHERE ll.fk_layout = l.rowid) as nblines";
	$sql .= " FROM ".MAIN_DB_PREFIX."anychartlab_layout as l WHERE l.entity = ".((int) $entity);
	if ($statement !== '') {
		$sql .= " AND l.statement = '".$db->escape($statement)."'";
	}
	$sql .= " ORDER BY l.statement, l.label";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out[(int) $obj->rowid] = $obj;
		}
	}
	return $out;
}

/**
 * Load a layout with its lines and explicit account assignments.
 *
 * @param DoliDB $db
 * @param int    $id
 * @param int    $entity
 * @return object|null  ->lines (ordered), ->explicit [lineid => [accountid,...]]
 */
function anychartlab_layout_fetch($db, $id, $entity)
{
	$resql = $db->query("SELECT rowid, code, label, statement, country_code, source, source_ref FROM ".MAIN_DB_PREFIX."anychartlab_layout WHERE rowid = ".((int) $id)." AND entity = ".((int) $entity));
	$layout = ($resql ? $db->fetch_object($resql) : null);
	if (!$layout) {
		return null;
	}
	$layout->lines = array();
	$layout->explicit = array();
	$resql = $db->query("SELECT rowid, position, code, label, type, natures, accounts, formula, sign FROM ".MAIN_DB_PREFIX."anychartlab_layout_line WHERE fk_layout = ".((int) $id)." ORDER BY position, rowid");
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$layout->lines[] = $obj;
			$layout->explicit[(int) $obj->rowid] = array();
		}
	}
	$resql = $db->query("SELECT la.fk_layout_line, la.fk_accounting_account FROM ".MAIN_DB_PREFIX."anychartlab_layout_account as la INNER JOIN ".MAIN_DB_PREFIX."anychartlab_layout_line as ll ON ll.rowid = la.fk_layout_line WHERE ll.fk_layout = ".((int) $id));
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$layout->explicit[(int) $obj->fk_layout_line][] = (int) $obj->fk_accounting_account;
		}
	}
	return $layout;
}

/**
 * Create an empty layout. The code is made unique within the entity.
 *
 * @return int new id, <0 on error
 */
function anychartlab_layout_create($db, $entity, $code, $label, $statement, $country, $source, $sourceRef)
{
	$code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $code) ?: 'LAYOUT');
	$base = substr($code, 0, 28);
	$n = 1;
	while (true) {
		$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."anychartlab_layout WHERE entity = ".((int) $entity)." AND code = '".$db->escape($code)."'");
		if (!$resql || !$db->fetch_object($resql)) {
			break;
		}
		$code = $base.'-'.(++$n);
	}
	$sql = "INSERT INTO ".MAIN_DB_PREFIX."anychartlab_layout (entity, code, label, statement, country_code, source, source_ref) VALUES (";
	$sql .= ((int) $entity).", '".$db->escape($code)."', '".$db->escape($label ?: $code)."', '".($statement === 'IS' ? 'IS' : 'BS')."', ";
	$sql .= ($country !== '' ? "'".$db->escape($country)."'" : "NULL").", '".$db->escape($source)."', ".($sourceRef !== '' ? "'".$db->escape($sourceRef)."'" : "NULL").")";
	if (!$db->query($sql)) {
		return -1;
	}
	return (int) $db->last_insert_id(MAIN_DB_PREFIX."anychartlab_layout");
}

/**
 * Normalise line data coming from a form, a file or a copy.
 *
 * @param array $d
 * @return array
 */
function anychartlab_layout_clean_line($d)
{
	$types = anychartlab_layout_types();
	$signs = anychartlab_layout_signs();
	$natures = anychartlab_natures();
	$nat = array();
	foreach (preg_split('/[|,; ]+/', strtoupper((string) ($d['natures'] ?? ''))) as $v) {
		if (isset($natures[$v])) {
			$nat[] = $v;
		}
	}
	return array(
		'position' => (int) ($d['position'] ?? 0),
		'code' => strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($d['code'] ?? ''))),
		'label' => trim((string) ($d['label'] ?? '')),
		'type' => (isset($types[$d['type'] ?? '']) ? $d['type'] : 'group'),
		'natures' => implode('|', array_unique($nat)),
		'accounts' => trim(preg_replace('/\s+/', '', (string) ($d['accounts'] ?? ''))),
		'formula' => trim((string) ($d['formula'] ?? '')),
		'sign' => (isset($signs[$d['sign'] ?? '']) ? $d['sign'] : 'natural'),
	);
}

/**
 * Insert (id = 0) or update a layout line.
 *
 * @return int line id, <0 on error
 */
function anychartlab_layout_save_line($db, $layoutId, $lineId, $data)
{
	$d = anychartlab_layout_clean_line($data);
	if ($d['code'] === '') {
		$d['code'] = 'L'.($d['position'] ?: mt_rand(100, 999));
	}
	$set = "position = ".((int) $d['position']).", code = '".$db->escape($d['code'])."', label = '".$db->escape($d['label'] ?: $d['code'])."', type = '".$db->escape($d['type'])."'";
	$set .= ", natures = ".($d['natures'] !== '' ? "'".$db->escape($d['natures'])."'" : "NULL");
	$set .= ", accounts = ".($d['accounts'] !== '' ? "'".$db->escape($d['accounts'])."'" : "NULL");
	$set .= ", formula = ".($d['formula'] !== '' ? "'".$db->escape($d['formula'])."'" : "NULL");
	$set .= ", sign = '".$db->escape($d['sign'])."'";
	if ($lineId > 0) {
		return $db->query("UPDATE ".MAIN_DB_PREFIX."anychartlab_layout_line SET ".$set." WHERE rowid = ".((int) $lineId)." AND fk_layout = ".((int) $layoutId)) ? $lineId : -1;
	}
	$sql = "INSERT INTO ".MAIN_DB_PREFIX."anychartlab_layout_line (fk_layout, position, code, label, type, natures, accounts, formula, sign) VALUES (";
	$sql .= ((int) $layoutId).", ".((int) $d['position']).", '".$db->escape($d['code'])."', '".$db->escape($d['label'] ?: $d['code'])."', '".$db->escape($d['type'])."', ";
	$sql .= ($d['natures'] !== '' ? "'".$db->escape($d['natures'])."'" : "NULL").", ".($d['accounts'] !== '' ? "'".$db->escape($d['accounts'])."'" : "NULL").", ";
	$sql .= ($d['formula'] !== '' ? "'".$db->escape($d['formula'])."'" : "NULL").", '".$db->escape($d['sign'])."')";
	if (!$db->query($sql)) {
		return -1;
	}
	return (int) $db->last_insert_id(MAIN_DB_PREFIX."anychartlab_layout_line");
}

/**
 * Replace the explicit accounts of a line.
 *
 * @param DoliDB $db
 * @param int    $lineId
 * @param int[]  $accountIds
 * @return void
 */
function anychartlab_layout_set_explicit($db, $lineId, $accountIds)
{
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."anychartlab_layout_account WHERE fk_layout_line = ".((int) $lineId));
	foreach (array_unique(array_map('intval', $accountIds)) as $aid) {
		if ($aid > 0) {
			$db->query("INSERT INTO ".MAIN_DB_PREFIX."anychartlab_layout_account (fk_layout_line, fk_accounting_account) VALUES (".((int) $lineId).", ".$aid.")");
		}
	}
}

/**
 * @return void
 */
function anychartlab_layout_delete_line($db, $layoutId, $lineId)
{
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."anychartlab_layout_account WHERE fk_layout_line = ".((int) $lineId));
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."anychartlab_layout_line WHERE rowid = ".((int) $lineId)." AND fk_layout = ".((int) $layoutId));
}

/**
 * @return void
 */
function anychartlab_layout_delete($db, $entity, $layoutId)
{
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."anychartlab_layout_account WHERE fk_layout_line IN (SELECT rowid FROM ".MAIN_DB_PREFIX."anychartlab_layout_line WHERE fk_layout = ".((int) $layoutId).")");
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."anychartlab_layout_line WHERE fk_layout = ".((int) $layoutId));
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."anychartlab_layout WHERE rowid = ".((int) $layoutId)." AND entity = ".((int) $entity));
}

/**
 * Renumber positions 10, 20, 30… keeping the current order.
 *
 * @return void
 */
function anychartlab_layout_renumber($db, $layoutId)
{
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."anychartlab_layout_line WHERE fk_layout = ".((int) $layoutId)." ORDER BY position, rowid");
	$pos = 10;
	$ids = array();
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$ids[] = (int) $obj->rowid;
	}
	foreach ($ids as $id) {
		$db->query("UPDATE ".MAIN_DB_PREFIX."anychartlab_layout_line SET position = ".$pos." WHERE rowid = ".$id);
		$pos += 10;
	}
}

/**
 * Personalised reports defined in Dolibarr core (read only).
 *
 * @return array<int,string> rowid => label
 */
function anychartlab_core_reports($db, $entity)
{
	$out = array();
	$resql = $db->query("SELECT rowid, code, label FROM ".MAIN_DB_PREFIX."c_accounting_report WHERE entity IN (0, ".((int) $entity).") AND active = 1 ORDER BY rowid");
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$out[(int) $obj->rowid] = $obj->code.' - '.$obj->label;
	}
	return $out;
}

/**
 * Copy one of Dolibarr's personalised reports into a lab layout.
 * Each personalised group becomes a group line with its assigned accounts (from
 * both the per-account column and the multi-report link table); each calculated
 * group becomes a formula line. Core data is only read.
 *
 * @return int new layout id, <0 on error
 */
function anychartlab_layout_copy_core($db, $entity, $pcgversion, $reportId, $statement)
{
	$resql = $db->query("SELECT code, label FROM ".MAIN_DB_PREFIX."c_accounting_report WHERE rowid = ".((int) $reportId));
	$rep = ($resql ? $db->fetch_object($resql) : null);
	if (!$rep) {
		return -1;
	}
	$layoutId = anychartlab_layout_create($db, $entity, 'CORE-'.$rep->code.'-'.$statement, $rep->label.' (copy of personalised report)', $statement, '', 'core', 'c_accounting_report #'.((int) $reportId));
	if ($layoutId < 0) {
		return -1;
	}
	$sql = "SELECT rowid, code, label, sens, category_type, formula, position FROM ".MAIN_DB_PREFIX."c_accounting_category";
	$sql .= " WHERE fk_report = ".((int) $reportId)." AND entity IN (0, ".((int) $entity).") AND active = 1 ORDER BY position, rowid";
	$cats = array();
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$cats[] = $obj;
	}
	$pos = 10;
	foreach ($cats as $cat) {
		$isFormula = ((int) $cat->category_type === 1);
		$lineId = anychartlab_layout_save_line($db, $layoutId, 0, array(
			'position' => $pos,
			'code' => $cat->code,
			'label' => $cat->label,
			'type' => ($isFormula ? 'formula' : 'group'),
			'formula' => ($isFormula ? $cat->formula : ''),
			'sign' => ((int) $cat->sens === 1 ? 'dc' : 'cd'),	// core: 0 = credit - debit, 1 = debit - credit
		));
		$pos += 10;
		if ($isFormula || $lineId < 0) {
			continue;
		}
		$ids = array();
		$sql = "SELECT aa.rowid FROM ".MAIN_DB_PREFIX."accounting_account as aa WHERE aa.entity = ".((int) $entity)." AND aa.fk_pcg_version = '".$db->escape($pcgversion)."'";
		$sql .= " AND (aa.fk_accounting_category = ".((int) $cat->rowid);
		$sql .= " OR aa.rowid IN (SELECT aca.fk_accounting_account FROM ".MAIN_DB_PREFIX."accounting_category_account as aca WHERE aca.fk_accounting_category = ".((int) $cat->rowid)."))";
		$r2 = $db->query($sql);
		while ($r2 && ($o2 = $db->fetch_object($r2))) {
			$ids[] = (int) $o2->rowid;
		}
		anychartlab_layout_set_explicit($db, $lineId, $ids);
	}
	return $layoutId;
}

/**
 * Columns of the layout CSV format (export, import and seed/layouts files).
 *
 * @return string[]
 */
function anychartlab_layout_csv_columns()
{
	return array('layout_code', 'layout_label', 'statement', 'country', 'position', 'code', 'label', 'type', 'natures', 'accounts', 'formula', 'sign', 'explicit_accounts');
}

/**
 * Read a layout CSV (semicolon, header line, '#' comments).
 *
 * @param string $file
 * @return array<int,array<string,string>>
 */
function anychartlab_layout_read_csv($file)
{
	$rows = array();
	$fh = @fopen($file, 'r');
	if (!$fh) {
		return $rows;
	}
	$header = null;
	while (($line = fgets($fh)) !== false) {
		$line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));
		if ($line === '' || $line[0] === '#') {
			continue;
		}
		$cols = str_getcsv($line, (substr_count($line, ';') >= substr_count($line, ',') ? ';' : ','), '"', '\\');
		if ($header === null) {
			$header = array_map('strtolower', array_map('trim', $cols));
			continue;
		}
		$row = array();
		foreach ($header as $i => $name) {
			$row[$name] = trim((string) ($cols[$i] ?? ''));
		}
		$rows[] = $row;
	}
	fclose($fh);
	return $rows;
}

/**
 * Create layouts from CSV rows (one or several layouts, grouped by layout_code).
 * explicit_accounts holds account numbers separated by '|', matched in the active chart.
 *
 * @return array{created:int[],errors:string[]}
 */
function anychartlab_layout_import($db, $entity, $pcgversion, $rows, $source, $sourceRef)
{
	$res = array('created' => array(), 'errors' => array());
	$accounts = anychartlab_load_accounts($db, $entity, $pcgversion);
	$byCode = array();
	foreach ($rows as $r) {
		if (($r['layout_code'] ?? '') === '' || ($r['type'] ?? '') === '') {
			continue;
		}
		$byCode[$r['layout_code']][] = $r;
	}
	if (!$byCode) {
		$res['errors'][] = 'No layout lines found. The file needs the columns '.implode(';', anychartlab_layout_csv_columns()).'.';
		return $res;
	}
	foreach ($byCode as $code => $lines) {
		$first = $lines[0];
		$layoutId = anychartlab_layout_create($db, $entity, $code, $first['layout_label'] ?? $code, strtoupper($first['statement'] ?? 'BS'), $first['country'] ?? '', $source, $sourceRef);
		if ($layoutId < 0) {
			$res['errors'][] = 'Could not create layout '.$code.'.';
			continue;
		}
		foreach ($lines as $l) {
			$lineId = anychartlab_layout_save_line($db, $layoutId, 0, $l);
			if ($lineId > 0 && ($l['explicit_accounts'] ?? '') !== '') {
				$ids = array();
				foreach (explode('|', $l['explicit_accounts']) as $num) {
					$acc = $accounts['byNumber'][trim($num)] ?? null;
					if ($acc) {
						$ids[] = (int) $acc->rowid;
					} elseif (trim($num) !== '') {
						$res['errors'][] = 'Account '.trim($num).' (line '.$l['code'].') is not in chart '.$pcgversion.'.';
					}
				}
				anychartlab_layout_set_explicit($db, $lineId, $ids);
			}
		}
		$res['created'][] = $layoutId;
	}
	return $res;
}

/**
 * Rows of a layout in the CSV format.
 *
 * @return array
 */
function anychartlab_layout_export_rows($layout, $accounts)
{
	$rows = array();
	foreach ($layout->lines as $l) {
		$nums = array();
		foreach ($layout->explicit[(int) $l->rowid] ?? array() as $aid) {
			if (isset($accounts['byRowid'][$aid])) {
				$nums[] = $accounts['byRowid'][$aid]->account_number;
			}
		}
		$rows[] = array($layout->code, $layout->label, $layout->statement, (string) $layout->country_code, (int) $l->position, $l->code, $l->label, $l->type, (string) $l->natures, (string) $l->accounts, (string) $l->formula, $l->sign, implode('|', $nums));
	}
	return $rows;
}

/**
 * Layout files shipped with the module (seed/layouts/*.csv).
 *
 * @return array<string,string> filename => first comment line or filename
 */
function anychartlab_layout_files()
{
	$out = array();
	foreach (glob(anychartlab_seed_dir().'/layouts/*.csv') ?: array() as $f) {
		$desc = basename($f);
		$fh = fopen($f, 'r');
		$first = $fh ? fgets($fh) : '';
		if ($fh) {
			fclose($fh);
		}
		if (strpos((string) $first, '#') === 0) {
			$desc = basename($f).' — '.trim(substr($first, 1));
		}
		$out[basename($f)] = $desc;
	}
	return $out;
}

/**
 * Does an account-prefix spec take this account?
 * Spec: items separated by , | ; or spaces. "11" = starts with 11; "!1301" = never;
 * "512D" / "512C" = only when the balance is in debit / credit. Empty spec = any.
 *
 * @param string     $spec
 * @param string     $number
 * @param float|null $netdebit null = ignore D/C (static matching)
 * @return bool
 */
function anychartlab_spec_matches($spec, $number, $netdebit)
{
	$items = preg_split('/[,|; ]+/', (string) $spec, -1, PREG_SPLIT_NO_EMPTY);
	$hasInclude = false;
	$included = false;
	foreach ($items as $item) {
		if ($item[0] === '!') {
			if (strpos($number, substr($item, 1)) === 0) {
				return false;
			}
			continue;
		}
		$hasInclude = true;
		$side = '';
		$last = strtoupper(substr($item, -1));
		if (strlen($item) > 1 && ($last === 'D' || $last === 'C')) {
			$side = $last;
			$item = substr($item, 0, -1);
		}
		if (strpos($number, $item) === 0) {
			if ($side === '' || $netdebit === null || ($side === 'D' && $netdebit > 0) || ($side === 'C' && $netdebit < 0)) {
				$included = true;
			}
		}
	}
	return $hasInclude ? $included : true;
}

/**
 * Does a group line take an account by its rules (natures + prefixes)?
 *
 * @param object     $line
 * @param string     $nature   Nature used for this account (effective nature or section)
 * @param string     $number
 * @param float|null $netdebit
 * @return bool
 */
function anychartlab_line_rule_matches($line, $nature, $number, $netdebit)
{
	if ((string) $line->natures === '' && (string) $line->accounts === '') {
		return false;	// only explicit accounts
	}
	if ((string) $line->natures !== '' && !in_array($nature, explode('|', $line->natures))) {
		return false;
	}
	return anychartlab_spec_matches((string) $line->accounts, $number, $netdebit);
}

/**
 * Evaluate a formula of line codes: + - * / ( ), numbers, codes. No eval().
 *
 * @param string               $formula
 * @param array<string,float>  $values
 * @param string[]             $errors  Unknown codes are added here
 * @return float
 */
function anychartlab_formula_eval($formula, $values, &$errors)
{
	preg_match_all('/\s*([A-Za-z_][A-Za-z0-9_]*|\d+(?:\.\d+)?|[-+*\/()])/', (string) $formula, $m);
	$st = array('t' => $m[1], 'p' => 0);
	return (float) anychartlab_fx_expr($st, $values, $errors);
}

/**
 * expr := term (('+'|'-') term)*
 *
 * @return float
 */
function anychartlab_fx_expr(&$st, $values, &$errors)
{
	$v = anychartlab_fx_term($st, $values, $errors);
	while (in_array($st['t'][$st['p']] ?? null, array('+', '-'), true)) {
		$op = $st['t'][$st['p']++];
		$r = anychartlab_fx_term($st, $values, $errors);
		$v = ($op === '+' ? $v + $r : $v - $r);
	}
	return $v;
}

/**
 * term := factor (('*'|'/') factor)*
 *
 * @return float
 */
function anychartlab_fx_term(&$st, $values, &$errors)
{
	$v = anychartlab_fx_factor($st, $values, $errors);
	while (in_array($st['t'][$st['p']] ?? null, array('*', '/'), true)) {
		$op = $st['t'][$st['p']++];
		$r = anychartlab_fx_factor($st, $values, $errors);
		$v = ($op === '*' ? $v * $r : ($r != 0 ? $v / $r : 0.0));
	}
	return $v;
}

/**
 * factor := '-' factor | '(' expr ')' | number | code
 *
 * @return float
 */
function anychartlab_fx_factor(&$st, $values, &$errors)
{
	$t = $st['t'][$st['p']++] ?? null;
	if ($t === null) {
		return 0.0;
	}
	if ($t === '-') {
		return -anychartlab_fx_factor($st, $values, $errors);
	}
	if ($t === '(') {
		$v = anychartlab_fx_expr($st, $values, $errors);
		$st['p']++;	// closing parenthesis
		return $v;
	}
	if (is_numeric($t)) {
		return (float) $t;
	}
	$code = strtoupper($t);
	if (!array_key_exists($code, $values)) {
		$errors[] = $code;
		return 0.0;
	}
	return (float) $values[$code];
}

/**
 * Lay out statement lines.
 *
 * @param object $layout From anychartlab_layout_fetch()
 * @param array  $lines  Statement lines: array('acc','amount','note','netdebit','section')
 * @param array  $extra  Built-in values usable in formulas, e.g. array('RESULT' => 123.45)
 * @return array{rows:array,unmatched:array,values:array,errors:string[]}
 */
function anychartlab_apply_layout($layout, $lines, $extra)
{
	$res = array('rows' => array(), 'unmatched' => array(), 'values' => array(), 'errors' => array());
	$taken = array();	// line index => layout line rowid
	foreach ($lines as $i => $l) {
		foreach ($layout->lines as $ll) {
			if ($ll->type === 'group' && in_array((int) $l['acc']->rowid, $layout->explicit[(int) $ll->rowid] ?? array())) {
				$taken[$i] = (int) $ll->rowid;
				break;
			}
		}
		if (isset($taken[$i])) {
			continue;
		}
		foreach ($layout->lines as $ll) {
			if ($ll->type === 'group' && anychartlab_line_rule_matches($ll, $l['section'], $l['acc']->account_number, $l['netdebit'])) {
				$taken[$i] = (int) $ll->rowid;
				break;
			}
		}
		if (!isset($taken[$i])) {
			$res['unmatched'][] = $l;
		}
	}
	$values = array();
	foreach ($extra as $k => $v) {
		$values[strtoupper($k)] = (float) $v;
	}
	foreach ($layout->lines as $ll) {
		$row = array('line' => $ll, 'lines' => array(), 'total' => null);
		if ($ll->type === 'group') {
			$total = 0.0;
			foreach ($lines as $i => $l) {
				if (($taken[$i] ?? 0) !== (int) $ll->rowid) {
					continue;
				}
				if ($ll->sign === 'cd') {
					$l['amount'] = -$l['netdebit'];
					$l['section'] = 'INCOME';	// entries shown credit - debit
				} elseif ($ll->sign === 'dc') {
					$l['amount'] = $l['netdebit'];
					$l['section'] = 'EXPENSE';	// entries shown debit - credit
				}
				$row['lines'][] = $l;
				$total += $l['amount'];
			}
			$row['total'] = $total;
			$values[strtoupper($ll->code)] = $total;
		} elseif ($ll->type === 'formula') {
			$errs = array();
			$row['total'] = anychartlab_formula_eval($ll->formula, $values, $errs);
			foreach ($errs as $e) {
				$res['errors'][] = 'Line '.$ll->code.': unknown code "'.$e.'" in formula (codes must be defined on an earlier line'.(isset($extra['RESULT']) ? '; RESULT is the result of unclosed periods' : '').').';
			}
			$values[strtoupper($ll->code)] = $row['total'];
		}
		$res['rows'][] = $row;
	}
	$res['values'] = $values;
	return $res;
}

/**
 * Print a laid-out statement into an open table (columns: account, label, note, [draft], amount).
 *
 * @return void
 */
function anychartlab_print_layout($result, $accounts, $view, $showDraft, $entries, $hidden = array())
{
	$cols = ($showDraft ? 4 : 3);
	foreach ($result['rows'] as $row) {
		$ll = $row['line'];
		if (isset($hidden[(int) $ll->rowid])) {
			continue;
		}
		if ($ll->type === 'heading') {
			print '<tr class="liste_titre acl-section"><th colspan="'.($cols + 1).'">'.dol_escape_htmltag($ll->label).'</th></tr>';
			continue;
		}
		if ($ll->type === 'formula') {
			print '<tr class="liste_total acl-grandtotal"><td colspan="'.$cols.'">'.dol_escape_htmltag($ll->label).' <span class="opacitymedium" style="font-weight:normal">= '.dol_escape_htmltag((string) $ll->formula).'</span></td><td class="right amount">'.anychartlab_price($row['total']).'</td></tr>';
			continue;
		}
		if ($view === 'summary') {
			print '<tr class="oddeven acl-account"><td colspan="'.$cols.'">'.dol_escape_htmltag($ll->label).' <span class="opacitymedium">('.count($row['lines']).' account'.(count($row['lines']) === 1 ? '' : 's').')</span></td><td class="right amount">'.anychartlab_price($row['total']).'</td></tr>';
			continue;
		}
		print '<tr class="oddeven acl-heading"><td colspan="'.($cols + 1).'">'.dol_escape_htmltag($ll->label).'</td></tr>';
		if ($row['lines']) {
			anychartlab_print_groups(anychartlab_group_lines($row['lines'], $accounts), 'detailed', $showDraft, $entries, '');
		} else {
			print '<tr class="oddeven"><td></td><td colspan="'.($cols).'" class="opacitymedium">No account with a balance.</td></tr>';
		}
		print '<tr class="oddeven acl-total"><td></td><td colspan="'.($cols - 1).'" class="right">Total '.dol_escape_htmltag($ll->label).'</td><td class="right amount">'.anychartlab_price($row['total']).'</td></tr>';
	}
}

/**
 * CSV rows of a laid-out statement.
 *
 * @return array
 */
function anychartlab_layout_csv($result, $accounts, $view, $entries, $hidden = array())
{
	$rows = array();
	foreach ($result['rows'] as $row) {
		$ll = $row['line'];
		if (isset($hidden[(int) $ll->rowid])) {
			continue;
		}
		if ($ll->type === 'heading') {
			$rows[] = anychartlab_r(array($ll->label, '', '', '', ''), 'section');
		} elseif ($ll->type === 'formula') {
			$rows[] = anychartlab_r(array('', $ll->label, '', '', price2num($row['total'], 'MT')), 'grandtotal');
		} elseif ($view === 'summary') {
			$rows[] = anychartlab_r(array('', $ll->label, '', '', price2num($row['total'], 'MT')), 'account');
		} else {
			$rows[] = anychartlab_r(array('', $ll->label, '', '', ''), 'heading');
			$rows = array_merge($rows, anychartlab_groups_csv(anychartlab_group_lines($row['lines'], $accounts), 'detailed', $entries, ''));
			$rows[] = anychartlab_r(array('', 'Total '.$ll->label, '', '', price2num($row['total'], 'MT')), 'total');
		}
	}
	return $rows;
}

/**
 * Lines to hide with "Hide empty lines": groups that take no account with a balance,
 * formulas that are zero because everything they refer to is hidden, and headings
 * with no visible group below them (until the next heading).
 *
 * @param array $result From anychartlab_apply_layout()
 * @return array<int,bool> layout line rowid => true
 */
function anychartlab_layout_hidden($result)
{
	$hidden = array();
	$hiddenCodes = array();
	foreach ($result['rows'] as $row) {
		$ll = $row['line'];
		if ($ll->type === 'group' && !$row['lines']) {
			$hidden[(int) $ll->rowid] = true;
			$hiddenCodes[strtoupper($ll->code)] = true;
		} elseif ($ll->type === 'formula' && abs((float) $row['total']) < 0.005) {
			preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', (string) $ll->formula, $m);
			$refs = array_unique(array_map('strtoupper', $m[0]));
			if ($refs && count(array_intersect_key(array_flip($refs), $hiddenCodes)) === count($refs)) {
				$hidden[(int) $ll->rowid] = true;
				$hiddenCodes[strtoupper($ll->code)] = true;
			}
		}
	}
	$heading = null;
	$visibleGroup = false;
	foreach ($result['rows'] as $row) {
		$ll = $row['line'];
		if ($ll->type === 'heading') {
			if ($heading !== null && !$visibleGroup) {
				$hidden[(int) $heading->rowid] = true;
			}
			$heading = $ll;
			$visibleGroup = false;
		} elseif ($ll->type === 'group' && !isset($hidden[(int) $ll->rowid])) {
			$visibleGroup = true;
		}
	}
	if ($heading !== null && !$visibleGroup) {
		$hidden[(int) $heading->rowid] = true;
	}
	return $hidden;
}

/**
 * Print the "Not in layout" section.
 *
 * @return void
 */
function anychartlab_print_unmatched($result, $statementLabel)
{
	if (!$result['unmatched']) {
		return;
	}
	$sum = 0.0;
	print '<br><div class="warning">'.count($result['unmatched']).' account(s) with a balance are not taken by any line of this layout. They are listed here, not dropped. Add a line (or a catch-all line by nature) in the layout editor.</div>';
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>Account</th><th>Label</th><th>Placed as</th><th class="right">Amount</th></tr>';
	foreach ($result['unmatched'] as $l) {
		$sum += $l['amount'];
		print '<tr class="oddeven"><td><a href="'.anychartlab_ledger_url($l['acc']->account_number).'" target="_blank">'.dol_escape_htmltag($l['acc']->account_number).'</a></td><td>'.dol_escape_htmltag($l['acc']->label).'</td><td>'.dol_escape_htmltag($l['section']).'</td><td class="right amount">'.anychartlab_price($l['amount']).'</td></tr>';
	}
	print '<tr class="liste_total"><td colspan="3">Not in layout</td><td class="right amount">'.anychartlab_price($sum).'</td></tr></table>';
}

/**
 * Static check of a layout against the active chart (for the editor): how many
 * accounts each group line would take (ignoring balances and D/C suffixes), and
 * which accounts of the statement's natures no line takes.
 *
 * @return array{counts:array<int,int>,unmatched:object[],dupcodes:string[]}
 */
function anychartlab_layout_static_check($layout, $accounts)
{
	$natures = ($layout->statement === 'IS' ? array('INCOME', 'EXPENSE') : array('ASSET', 'LIABILITY', 'EQUITY', 'CLEARING'));
	$counts = array();
	$unmatched = array();
	foreach ($layout->lines as $ll) {
		$counts[(int) $ll->rowid] = 0;
	}
	foreach ($accounts['byRowid'] as $acc) {
		$cands = array_filter(array($acc->nature, $acc->nature_alt));
		if (!array_intersect($cands, $natures)) {
			continue;
		}
		$hit = 0;
		foreach ($layout->lines as $ll) {
			if ($ll->type === 'group' && in_array((int) $acc->rowid, $layout->explicit[(int) $ll->rowid] ?? array())) {
				$hit = (int) $ll->rowid;
				break;
			}
		}
		if (!$hit) {
			foreach ($layout->lines as $ll) {
				foreach ($cands as $nat) {
					if ($ll->type === 'group' && anychartlab_line_rule_matches($ll, $nat === 'CLEARING' ? 'ASSET' : $nat, $acc->account_number, null)) {
						$hit = (int) $ll->rowid;
						break 2;
					}
				}
			}
		}
		if ($hit) {
			$counts[$hit]++;
		} else {
			$unmatched[] = $acc;
		}
	}
	$seen = array();
	$dup = array();
	foreach ($layout->lines as $ll) {
		if ($ll->type !== 'heading' && isset($seen[$ll->code])) {
			$dup[] = $ll->code;
		}
		$seen[$ll->code] = 1;
	}
	return array('counts' => $counts, 'unmatched' => $unmatched, 'dupcodes' => array_unique($dup));
}
