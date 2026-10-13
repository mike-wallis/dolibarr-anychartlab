<?php
/**
 * Financial Reporting — report setup wizard, built on Dolibarr's personalised groups.
 *
 * A wizard TEMPLATE (seed/templates/*.csv) describes one country's statement as data:
 * sections (wizard steps), the groups of accounts in each, and formula lines. The
 * wizard turns a template into a DRAFT (kept in the session while the user edits it),
 * then writes the draft as a new Dolibarr personalised report:
 *   llx_c_accounting_report          the report
 *   llx_c_accounting_category        one row per group or formula; prefix rules in range_account
 *   llx_accounting_category_account  the accounts of each group (link table, multi-report system)
 * plus this module's own llx_financialreporting_report / llx_financialreporting_report_group (statement type,
 * template section of each group), which core has no place for.
 *
 * It never writes accounting_account.fk_accounting_category (the older one-group-per-account
 * link), so reports built in core's old system keep their accounts. Ledger and chart are
 * only read. See docs/decisions/financialreporting-core-groups-wizard.md.
 */

/**
 * Section types a template can use, with the sign that shows them as positive amounts.
 *
 * @return array<string,string> type => cd (credit - debit) or dc (debit - credit)
 */
function financialreporting_wizard_section_types()
{
	return array('INCOME' => 'cd', 'EXPENSE' => 'dc');
}

/**
 * Wizard templates shipped with the module, best match first (chart, then country, then
 * the others), plus any file in seed/templates/ that the catalogue doesn't list.
 *
 * @param string $pcgversion
 * @return array<int,array{file:string,label:string,match:string}>
 */
function financialreporting_template_files($pcgversion)
{
	$dir = financialreporting_seed_dir().'/templates';
	$out = array();
	$seen = array();
	foreach (financialreporting_catalog_ranked('template', $pcgversion) as $e) {
		if (is_readable($dir.'/'.$e['file'])) {
			$out[] = array('file' => $e['file'], 'label' => $e['label'], 'match' => $e['match']);
			$seen[$e['file']] = true;
		}
	}
	foreach (glob($dir.'/*.csv') ?: array() as $f) {
		if (!isset($seen[basename($f)])) {
			$tpl = financialreporting_template_read(basename($f));
			$out[] = array('file' => basename($f), 'label' => ($tpl ? $tpl['label'] : basename($f)), 'match' => '');
		}
	}
	return $out;
}

/**
 * Read a wizard template.
 *
 * @param string $file File name in seed/templates/
 * @return array|null array{file,label,statement,review,sections:array<int,array{code,label,type,lines:array}>}
 */
function financialreporting_template_read($file)
{
	$path = financialreporting_seed_dir().'/templates/'.basename($file);
	$fh = @fopen($path, 'r');
	if (!$fh) {
		return null;
	}
	$tpl = array('file' => basename($file), 'label' => basename($file), 'statement' => 'IS', 'review' => '', 'sections' => array());
	$labels = array();
	$header = null;
	$rows = array();
	while (($line = fgets($fh)) !== false) {
		$line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));
		if ($line === '') {
			continue;
		}
		if ($line[0] === '#') {
			if (preg_match('/^#\s*section\s+([A-Za-z0-9_]+):\s*(.+)$/', $line, $m)) {
				$labels[strtoupper($m[1])] = trim($m[2]);
			} elseif (preg_match('/^#\s*(label|statement|review):\s*(.+)$/', $line, $m)) {
				$tpl[$m[1]] = trim($m[2]);
			}
			continue;
		}
		$cols = str_getcsv($line, ';', '"', '\\');
		if ($header === null) {
			$header = array_map('strtolower', array_map('trim', $cols));
			continue;
		}
		$r = array();
		foreach ($header as $i => $name) {
			$r[$name] = trim((string) ($cols[$i] ?? ''));
		}
		$rows[] = $r;
	}
	fclose($fh);
	$tpl['statement'] = (strtoupper($tpl['statement']) === 'BS' ? 'BS' : 'IS');

	$types = financialreporting_wizard_section_types();
	$index = array();
	foreach ($labels as $code => $label) {
		$index[$code] = count($tpl['sections']);
		$tpl['sections'][] = array('code' => $code, 'label' => $label, 'type' => '', 'lines' => array());
	}
	foreach ($rows as $r) {
		$sec = strtoupper($r['section'] ?? '');
		if ($sec === '') {
			continue;
		}
		if (!isset($index[$sec])) {
			$index[$sec] = count($tpl['sections']);
			$tpl['sections'][] = array('code' => $sec, 'label' => $sec, 'type' => '', 'lines' => array());
		}
		$s = &$tpl['sections'][$index[$sec]];
		$stype = strtoupper($r['section_type'] ?? '');
		if ($s['type'] === '' && isset($types[$stype])) {
			$s['type'] = $stype;
		}
		$s['lines'][] = financialreporting_wizard_clean_line($r, $stype !== '' ? $stype : $s['type']);
		unset($s);
	}
	return $tpl;
}

/**
 * Country notes of a template (seed/templates/<name>.notes.md), as HTML.
 *
 * @param string $file Template file name
 * @return string '' if there are none
 */
function financialreporting_template_notes($file)
{
	$path = financialreporting_seed_dir().'/templates/'.preg_replace('/\.csv$/', '', basename($file)).'.notes.md';
	if (!is_readable($path)) {
		return '';
	}
	require_once DOL_DOCUMENT_ROOT.'/core/lib/parsemd.lib.php';
	return dolMd2Html((string) file_get_contents($path));
}

/**
 * Normalise a draft line (from a template row or a form).
 *
 * @param array  $d
 * @param string $sectionType Type of the line's own section_type, or of its section
 * @return array{code:string,label:string,type:string,stype:string,natures:string,accounts:string,formula:string,sign:string}
 */
function financialreporting_wizard_clean_line($d, $sectionType)
{
	$types = financialreporting_wizard_section_types();
	$natures = financialreporting_natures();
	$nat = array();
	foreach (preg_split('/[|,; ]+/', strtoupper((string) ($d['natures'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $v) {
		if (isset($natures[$v])) {
			$nat[] = $v;
		}
	}
	$sign = strtolower((string) ($d['sign'] ?? ''));
	return array(
		'code' => substr(strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($d['code'] ?? ''))), 0, 16),
		'label' => trim((string) ($d['label'] ?? '')),
		'type' => (($d['type'] ?? '') === 'formula' ? 'formula' : 'group'),
		'stype' => (isset($types[$sectionType]) ? $sectionType : ''),
		'natures' => implode('|', array_unique($nat)),
		'accounts' => trim(preg_replace('/\s+/', '', (string) ($d['accounts'] ?? ''))),
		'formula' => trim((string) ($d['formula'] ?? '')),
		'sign' => (in_array($sign, array('cd', 'dc'), true) ? $sign : ''),
	);
}

/**
 * Natures a group line takes: its own list, else its section type.
 *
 * @param array $line
 * @return string[]
 */
function financialreporting_wizard_line_natures($line)
{
	if ($line['natures'] !== '') {
		return explode('|', $line['natures']);
	}
	return ($line['stype'] !== '' ? array($line['stype']) : array());
}

/**
 * Sign of a group line: its own, else the one that shows its section type positive.
 *
 * @param array $line
 * @return string cd or dc
 */
function financialreporting_wizard_line_sign($line)
{
	if ($line['sign'] !== '') {
		return $line['sign'];
	}
	$types = financialreporting_wizard_section_types();
	return $types[$line['stype']] ?? 'cd';
}

/**
 * How well a prefix spec takes an account number (static: D/C suffixes are ignored).
 *
 * @param string $spec   e.g. "60,61,!6037" (empty = catch-all)
 * @param string $number
 * @return int -1 = not taken, 0 = taken by a catch-all, n = length of the longest matching prefix
 */
function financialreporting_spec_prefix_length($spec, $number)
{
	$items = preg_split('/[,|; ]+/', (string) $spec, -1, PREG_SPLIT_NO_EMPTY);
	$best = -1;
	$hasInclude = false;
	foreach ($items as $item) {
		if ($item[0] === '!') {
			if (strpos($number, substr($item, 1)) === 0) {
				return -1;
			}
			continue;
		}
		$hasInclude = true;
		$last = strtoupper(substr($item, -1));
		if (strlen($item) > 1 && ($last === 'D' || $last === 'C')) {
			$item = substr($item, 0, -1);
		}
		if (strpos($number, $item) === 0) {
			$best = max($best, strlen($item));
		}
	}
	return $hasInclude ? $best : 0;
}

/**
 * Natures of the statement a draft builds.
 *
 * @param string $statement IS or BS
 * @return string[]
 */
function financialreporting_wizard_statement_natures($statement)
{
	return ($statement === 'BS' ? array('ASSET', 'LIABILITY', 'EQUITY') : array('INCOME', 'EXPENSE'));
}

/**
 * Assign the chart's accounts to the draft's groups. For each account, its nature is tried
 * first, then its alternate nature; among the groups that take that nature, the one with the
 * longest matching prefix wins (catch-alls only take what is left; ties go to the earlier line).
 *
 * @param array $draft
 * @param array $accounts From financialreporting_load_accounts()
 * @return array{byLine:array<string,int[]>,unassigned:object[],unclassified:object[]}
 */
function financialreporting_wizard_assign($draft, $accounts)
{
	$res = array('byLine' => array(), 'unassigned' => array(), 'unclassified' => array());
	$groups = array();
	foreach ($draft['sections'] as $s) {
		foreach ($s['lines'] as $l) {
			if ($l['type'] === 'group' && $l['code'] !== '') {
				$groups[] = $l;
				$res['byLine'][$l['code']] = array();
			}
		}
	}
	$wanted = financialreporting_wizard_statement_natures($draft['statement']);
	foreach ($accounts['byRowid'] as $acc) {
		if ($acc->nature === null) {
			$res['unclassified'][] = $acc;
			continue;
		}
		$cands = array_values(array_intersect(array_filter(array($acc->nature, $acc->nature_alt)), $wanted));
		if (!$cands) {
			continue;
		}
		$hit = null;
		foreach ($cands as $nat) {
			$bestLen = -1;
			foreach ($groups as $g) {
				if (!in_array($nat, financialreporting_wizard_line_natures($g), true)) {
					continue;
				}
				$len = financialreporting_spec_prefix_length($g['accounts'], $acc->account_number);
				if ($len > $bestLen) {
					$bestLen = $len;
					$hit = $g['code'];
				}
			}
			if ($hit !== null) {
				break;
			}
		}
		if ($hit !== null) {
			$res['byLine'][$hit][] = (int) $acc->rowid;
		} elseif (in_array($acc->nature, $wanted, true)) {
			$res['unassigned'][] = $acc;
		}
	}
	return $res;
}

/**
 * Problems that block saving: bad or duplicate codes, formulas using unknown codes or
 * codes defined later.
 *
 * @param array $draft
 * @return string[]
 */
function financialreporting_wizard_errors($draft)
{
	global $langs;
	$errors = array();
	$known = array();
	$seen = array();
	$nbGroups = 0;
	foreach ($draft['sections'] as $s) {
		foreach ($s['lines'] as $l) {
			if ($l['code'] === '') {
				$errors[] = $langs->trans('FinRepWizErrNoCode', $s['label'], $l['label'] !== '' ? $l['label'] : '?');
				continue;
			}
			if (isset($seen[$l['code']])) {
				$errors[] = $langs->trans('FinRepWizErrDupCode', $l['code']);
			}
			$seen[$l['code']] = true;
			if ($l['type'] === 'formula') {
				$errs = array();
				financialreporting_formula_eval($l['formula'], $known, $errs);
				foreach (array_unique($errs) as $e) {
					$errors[] = $langs->trans('FinRepWizErrFormulaCode', $l['code'], $e);
				}
				if ($l['formula'] === '') {
					$errors[] = $langs->trans('FinRepWizErrFormulaEmpty', $l['code']);
				}
			} else {
				$nbGroups++;
				if (!financialreporting_wizard_line_natures($l)) {
					$errors[] = $langs->trans('FinRepWizErrNoNature', $l['code']);
				}
			}
			$known[$l['code']] = 0.0;
		}
	}
	if (!$nbGroups) {
		$errors[] = $langs->trans('FinRepWizErrNoGroup');
	}
	return $errors;
}

/**
 * Groups that no formula line uses: their amounts would never reach the result.
 *
 * @param array $draft
 * @return string[] codes
 */
function financialreporting_wizard_unused_groups($draft)
{
	$used = array();
	$groups = array();
	foreach ($draft['sections'] as $s) {
		foreach ($s['lines'] as $l) {
			if ($l['type'] === 'formula') {
				preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $l['formula'], $m);
				foreach ($m[0] as $c) {
					$used[strtoupper($c)] = true;
				}
			} elseif ($l['code'] !== '') {
				$groups[] = $l['code'];
			}
		}
	}
	return array_values(array_filter($groups, function ($c) use ($used) {
		return !isset($used[$c]);
	}));
}

/**
 * The draft as a layout object (see layout.lib.php), so the existing report code can
 * preview it. Each section becomes a heading; group lines take only their assigned accounts.
 *
 * @param array $draft
 * @param array $assign From financialreporting_wizard_assign()
 * @return object
 */
function financialreporting_wizard_layout($draft, $assign)
{
	$layout = (object) array('rowid' => 0, 'code' => 'WIZARD', 'label' => $draft['label'], 'statement' => $draft['statement'], 'country_code' => '', 'source' => 'wizard', 'source_ref' => $draft['template'], 'lines' => array(), 'explicit' => array());
	$id = 0;
	$pos = 0;
	foreach ($draft['sections'] as $s) {
		$layout->lines[] = (object) array('rowid' => --$id, 'position' => ($pos += 10), 'code' => 'H_'.$s['code'], 'label' => $s['label'], 'type' => 'heading', 'natures' => '', 'accounts' => '', 'formula' => '', 'sign' => 'natural');
		foreach ($s['lines'] as $l) {
			$line = (object) array('rowid' => --$id, 'position' => ($pos += 10), 'code' => $l['code'], 'label' => $l['label'], 'type' => $l['type'], 'natures' => '', 'accounts' => '', 'formula' => $l['formula'], 'sign' => ($l['type'] === 'group' ? financialreporting_wizard_line_sign($l) : 'natural'));
			$layout->lines[] = $line;
			$layout->explicit[$id] = ($l['type'] === 'group' ? ($assign['byLine'][$l['code']] ?? array()) : array());
		}
	}
	return $layout;
}

/**
 * Write the draft as a new Dolibarr personalised report, in one transaction.
 *
 * @param DoliDB $db
 * @param User   $user
 * @param int    $entity
 * @param array  $draft
 * @param array  $assign From financialreporting_wizard_assign()
 * @return int New llx_c_accounting_report rowid, <0 on error
 */
function financialreporting_wizard_save($db, $user, $entity, $draft, $assign)
{
	global $mysoc;
	$countryId = (int) ($mysoc->country_id ?? 0);

	// Report code: unique within the entity, at most 16 characters (uk_c_accounting_report)
	$base = 'ACL-'.$draft['statement'];
	$code = $base;
	$n = 1;
	while (true) {
		$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."c_accounting_report WHERE entity = ".((int) $entity)." AND code = '".$db->escape($code)."'");
		if (!$resql || !$db->fetch_object($resql)) {
			break;
		}
		$code = $base.'-'.(++$n);
	}

	$db->begin();
	$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_accounting_report (entity, code, label, fk_country, active) VALUES (";
	$sql .= ((int) $entity).", '".$db->escape($code)."', '".$db->escape(dol_trunc($draft['label'], 255, 'right', 'UTF-8', 1))."', ".$countryId.", 1)";
	if (!$db->query($sql)) {
		$db->rollback();
		return -1;
	}
	$reportId = (int) $db->last_insert_id(MAIN_DB_PREFIX."c_accounting_report");

	$pos = 0;
	foreach ($draft['sections'] as $s) {
		foreach ($s['lines'] as $l) {
			$pos += 10;
			$isFormula = ($l['type'] === 'formula');
			$sens = ($isFormula ? 0 : (financialreporting_wizard_line_sign($l) === 'dc' ? 1 : 0));	// core: 0 = credit - debit, 1 = debit - credit
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_accounting_category (entity, fk_report, code, label, range_account, sens, category_type, formula, position, fk_country, active) VALUES (";
			$sql .= ((int) $entity).", ".$reportId.", '".$db->escape($l['code'])."', '".$db->escape(dol_trunc($l['label'] !== '' ? $l['label'] : $l['code'], 255, 'right', 'UTF-8', 1))."', ";
			$sql .= "'".$db->escape($isFormula ? '' : $l['accounts'])."', ".$sens.", ".($isFormula ? 1 : 0).", '".$db->escape($isFormula ? $l['formula'] : '')."', ".$pos.", ".$countryId.", 1)";
			if (!$db->query($sql)) {
				$db->rollback();
				return -2;
			}
			$catId = (int) $db->last_insert_id(MAIN_DB_PREFIX."c_accounting_category");
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."financialreporting_report_group (fk_category, fk_report, section, section_label, section_type, natures) VALUES (";
			$sql .= $catId.", ".$reportId.", '".$db->escape($s['code'])."', '".$db->escape(dol_trunc($s['label'], 255, 'right', 'UTF-8', 1))."', '".$db->escape($s['type'])."', ";
			$sql .= ($isFormula ? "NULL" : "'".$db->escape(implode('|', financialreporting_wizard_line_natures($l)))."'").")";
			if (!$db->query($sql)) {
				$db->rollback();
				return -3;
			}
			if ($isFormula) {
				continue;
			}
			foreach ($assign['byLine'][$l['code']] ?? array() as $aid) {
				if (!$db->query("INSERT INTO ".MAIN_DB_PREFIX."accounting_category_account (fk_accounting_category, fk_accounting_account) VALUES (".$catId.", ".((int) $aid).")")) {
					$db->rollback();
					return -4;
				}
			}
		}
	}
	$sql = "INSERT INTO ".MAIN_DB_PREFIX."financialreporting_report (entity, fk_report, statement, template, datec, fk_user_creat) VALUES (";
	$sql .= ((int) $entity).", ".$reportId.", '".$db->escape($draft['statement'])."', '".$db->escape($draft['template'])."', '".$db->idate(dol_now())."', ".((int) $user->id).")";
	if (!$db->query($sql)) {
		$db->rollback();
		return -5;
	}
	$db->commit();
	return $reportId;
}

/**
 * Dolibarr personalised reports of the entity, with what the wizard knows about them.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @return array<int,object> rowid => (rowid, code, label, active, statement|null, template|null, nbcat, nbacc)
 */
function financialreporting_coreports($db, $entity)
{
	$out = array();
	$sql = "SELECT r.rowid, r.code, r.label, r.active, m.statement, m.template, m.datec,";
	$sql .= " (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."c_accounting_category as c WHERE c.fk_report = r.rowid AND c.entity = r.entity) as nbcat";
	$sql .= " FROM ".MAIN_DB_PREFIX."c_accounting_report as r";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."financialreporting_report as m ON m.fk_report = r.rowid AND m.entity = r.entity";
	$sql .= " WHERE r.entity = ".((int) $entity)." AND r.active = 1";
	$sql .= " ORDER BY m.rowid IS NULL, r.label";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$out[(int) $obj->rowid] = $obj;
	}
	return $out;
}

/**
 * A Dolibarr personalised report as a layout object, read live (nothing is copied).
 * Lines follow core's own selection (active groups of the report for the company country
 * or for all countries); accounts come from the link table and from the older
 * one-group-per-account column. For wizard-built reports, each template section starts
 * with a heading.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param int    $reportId
 * @param string $pcgversion
 * @return object|null
 */
function financialreporting_coreport_layout($db, $entity, $reportId, $pcgversion)
{
	global $mysoc;
	$resql = $db->query("SELECT r.rowid, r.code, r.label, m.statement, m.template FROM ".MAIN_DB_PREFIX."c_accounting_report as r LEFT JOIN ".MAIN_DB_PREFIX."financialreporting_report as m ON m.fk_report = r.rowid AND m.entity = r.entity WHERE r.rowid = ".((int) $reportId)." AND r.entity = ".((int) $entity));
	$rep = ($resql ? $db->fetch_object($resql) : null);
	if (!$rep) {
		return null;
	}
	$layout = (object) array('rowid' => 'core:'.((int) $rep->rowid), 'code' => $rep->code, 'label' => $rep->label, 'statement' => ($rep->statement ?: 'IS'), 'country_code' => '', 'source' => 'core', 'source_ref' => 'c_accounting_report #'.((int) $rep->rowid), 'wizard' => ($rep->statement !== null), 'lines' => array(), 'explicit' => array());

	$sql = "SELECT c.rowid, c.code, c.label, c.sens, c.category_type, c.formula, c.position, g.section, g.section_label";
	$sql .= " FROM ".MAIN_DB_PREFIX."c_accounting_category as c";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."financialreporting_report_group as g ON g.fk_category = c.rowid";
	$sql .= " WHERE c.fk_report = ".((int) $rep->rowid)." AND c.entity = ".((int) $entity)." AND c.active = 1";
	$sql .= " AND (c.fk_country = ".((int) ($mysoc->country_id ?? 0))." OR c.fk_country = 0)";
	$sql .= " ORDER BY c.position, c.rowid";
	$resql = $db->query($sql);
	$section = null;
	$hid = 0;
	$catIds = array();
	while ($resql && ($obj = $db->fetch_object($resql))) {
		if ($obj->section !== null && $obj->section !== $section) {
			$section = $obj->section;
			$layout->lines[] = (object) array('rowid' => --$hid, 'position' => (int) $obj->position, 'code' => 'H_'.$obj->section, 'label' => $obj->section_label, 'type' => 'heading', 'natures' => '', 'accounts' => '', 'formula' => '', 'sign' => 'natural');
		}
		$isFormula = ((int) $obj->category_type === 1);
		$layout->lines[] = (object) array('rowid' => (int) $obj->rowid, 'position' => (int) $obj->position, 'code' => $obj->code, 'label' => $obj->label, 'type' => ($isFormula ? 'formula' : 'group'), 'natures' => '', 'accounts' => '', 'formula' => ($isFormula ? (string) $obj->formula : ''), 'sign' => ((int) $obj->sens === 1 ? 'dc' : 'cd'));
		$layout->explicit[(int) $obj->rowid] = array();
		if (!$isFormula) {
			$catIds[] = (int) $obj->rowid;
		}
	}
	if ($catIds) {
		$in = implode(',', $catIds);
		$sql = "SELECT aca.fk_accounting_category as cat, aa.rowid FROM ".MAIN_DB_PREFIX."accounting_category_account as aca";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."accounting_account as aa ON aa.rowid = aca.fk_accounting_account";
		$sql .= " WHERE aca.fk_accounting_category IN (".$db->sanitize($in).") AND aa.entity = ".((int) $entity)." AND aa.fk_pcg_version = '".$db->escape($pcgversion)."'";
		$sql .= " UNION SELECT aa.fk_accounting_category as cat, aa.rowid FROM ".MAIN_DB_PREFIX."accounting_account as aa";
		$sql .= " WHERE aa.fk_accounting_category IN (".$db->sanitize($in).") AND aa.entity = ".((int) $entity)." AND aa.fk_pcg_version = '".$db->escape($pcgversion)."'";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$layout->explicit[(int) $obj->cat][] = (int) $obj->rowid;
		}
	}
	return $layout;
}

/**
 * Delete a wizard-built report: its groups, their account links and the module's data.
 * Refused for reports the wizard didn't build, and when accounts are linked to its groups
 * through the older one-group-per-account column (the wizard never writes that column).
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param int    $reportId
 * @return int 1 = deleted, 0 = refused, <0 = error
 */
function financialreporting_coreport_delete($db, $entity, $reportId)
{
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."financialreporting_report WHERE entity = ".((int) $entity)." AND fk_report = ".((int) $reportId));
	if (!$resql || !$db->fetch_object($resql)) {
		return 0;
	}
	$cats = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_accounting_category WHERE fk_report = ".((int) $reportId)." AND entity = ".((int) $entity);
	$resql = $db->query("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."accounting_account WHERE fk_accounting_category IN (".$cats.")");
	$obj = ($resql ? $db->fetch_object($resql) : null);
	if (!$obj || (int) $obj->nb > 0) {
		return 0;
	}
	$db->begin();
	$ok = $db->query("DELETE FROM ".MAIN_DB_PREFIX."accounting_category_account WHERE fk_accounting_category IN (".$cats.")")
		&& $db->query("DELETE FROM ".MAIN_DB_PREFIX."financialreporting_report_group WHERE fk_report = ".((int) $reportId))
		&& $db->query("DELETE FROM ".MAIN_DB_PREFIX."c_accounting_category WHERE fk_report = ".((int) $reportId)." AND entity = ".((int) $entity))
		&& $db->query("DELETE FROM ".MAIN_DB_PREFIX."c_accounting_report WHERE rowid = ".((int) $reportId)." AND entity = ".((int) $entity))
		&& $db->query("DELETE FROM ".MAIN_DB_PREFIX."financialreporting_report WHERE fk_report = ".((int) $reportId)." AND entity = ".((int) $entity));
	if (!$ok) {
		$db->rollback();
		return -1;
	}
	$db->commit();
	return 1;
}

/**
 * Rebuild the draft of a wizard-built report from core (groups, prefix rules in
 * range_account) and the module's section data.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param int    $reportId
 * @return array|null draft, with 'catIds' => [code => category rowid]
 */
function financialreporting_coreport_draft($db, $entity, $reportId)
{
	$resql = $db->query("SELECT r.label, m.statement, m.template FROM ".MAIN_DB_PREFIX."c_accounting_report as r INNER JOIN ".MAIN_DB_PREFIX."financialreporting_report as m ON m.fk_report = r.rowid AND m.entity = r.entity WHERE r.rowid = ".((int) $reportId)." AND r.entity = ".((int) $entity));
	$rep = ($resql ? $db->fetch_object($resql) : null);
	if (!$rep) {
		return null;
	}
	$draft = array('template' => (string) $rep->template, 'statement' => $rep->statement, 'label' => $rep->label, 'sections' => array(), 'catIds' => array());
	$sql = "SELECT c.rowid, c.code, c.label, c.range_account, c.sens, c.category_type, c.formula, g.section, g.section_label, g.section_type, g.natures";
	$sql .= " FROM ".MAIN_DB_PREFIX."c_accounting_category as c INNER JOIN ".MAIN_DB_PREFIX."financialreporting_report_group as g ON g.fk_category = c.rowid";
	$sql .= " WHERE c.fk_report = ".((int) $reportId)." AND c.entity = ".((int) $entity)." ORDER BY c.position, c.rowid";
	$resql = $db->query($sql);
	$index = array();
	while ($resql && ($obj = $db->fetch_object($resql))) {
		if (!isset($index[$obj->section])) {
			$index[$obj->section] = count($draft['sections']);
			$draft['sections'][] = array('code' => $obj->section, 'label' => $obj->section_label, 'type' => $obj->section_type, 'lines' => array());
		}
		$isFormula = ((int) $obj->category_type === 1);
		$draft['sections'][$index[$obj->section]]['lines'][] = financialreporting_wizard_clean_line(array(
			'code' => $obj->code, 'label' => $obj->label, 'type' => ($isFormula ? 'formula' : 'group'),
			'natures' => (string) $obj->natures, 'accounts' => (string) $obj->range_account, 'formula' => (string) $obj->formula,
			'sign' => ($isFormula ? '' : ((int) $obj->sens === 1 ? 'dc' : 'cd')),
		), $isFormula ? '' : $obj->section_type);
		$draft['catIds'][$obj->code] = (int) $obj->rowid;
	}
	return $draft;
}

/**
 * Re-apply the prefix rules of a wizard-built report: accounts that belong to no group of
 * the report yet (e.g. accounts created since) are linked to the group their rules give.
 * Accounts already in a group are never moved.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param int    $reportId
 * @param array  $accounts From financialreporting_load_accounts()
 * @return int Number of accounts linked, <0 on error
 */
function financialreporting_coreport_reapply($db, $entity, $reportId, $accounts)
{
	$draft = financialreporting_coreport_draft($db, $entity, $reportId);
	if (!$draft || !$draft['catIds']) {
		return -1;
	}
	$linked = array();
	$in = implode(',', array_map('intval', $draft['catIds']));
	$resql = $db->query("SELECT fk_accounting_account FROM ".MAIN_DB_PREFIX."accounting_category_account WHERE fk_accounting_category IN (".$db->sanitize($in).")");
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$linked[(int) $obj->fk_accounting_account] = true;
	}
	$assign = financialreporting_wizard_assign($draft, $accounts);
	$n = 0;
	$db->begin();
	foreach ($assign['byLine'] as $code => $ids) {
		foreach ($ids as $aid) {
			if (isset($linked[$aid]) || empty($draft['catIds'][$code])) {
				continue;
			}
			if (!$db->query("INSERT INTO ".MAIN_DB_PREFIX."accounting_category_account (fk_accounting_category, fk_accounting_account) VALUES (".((int) $draft['catIds'][$code]).", ".((int) $aid).")")) {
				$db->rollback();
				return -2;
			}
			$n++;
		}
	}
	$db->commit();
	return $n;
}
