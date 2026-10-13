<?php
/**
 * Financial Reporting — account classes.
 *
 * Model (docs/decisions/Setup-Explanation-logic.md): account type (= nature) → account
 * class → account, with reporting groups (Dolibarr personalised groups) for presentation.
 * Dolibarr has no class field, so the class is kept next to the nature in
 * llx_financialreporting_account_nature (columns class, class_source). Exactly one class
 * per account, from a fixed country-neutral list per nature, checked against IFRS, the EU
 * accounting directive, France (PCG) and SYSCOHADA (see the design doc, "Account class").
 */

/**
 * Classes of each nature, in display order (non-current first). CASH belongs to ASSET and
 * LIABILITY: a bank account keeps class CASH whichever side its balance is on.
 *
 * @return array<string,string[]> nature => class codes
 */
function financialreporting_classes()
{
	return array(
		'ASSET'     => array('NONCURRENT_ASSET', 'CURRENT_ASSET', 'CASH'),
		'LIABILITY' => array('NONCURRENT_LIABILITY', 'CURRENT_LIABILITY', 'CASH'),
		'EQUITY'    => array('EQUITY'),
		'INCOME'    => array('REVENUE', 'OTHER_OPERATING_INCOME', 'FINANCIAL_INCOME', 'NONRECURRING_INCOME'),
		'EXPENSE'   => array('COST_OF_SALES', 'EMPLOYEE_COST', 'DEPRECIATION', 'OPERATING_EXPENSE', 'FINANCE_COST', 'NONRECURRING_EXPENSE', 'INCOME_TAX'),
		'CLEARING'  => array('CLEARING'),
		'EXCLUDED'  => array('EXCLUDED'),
	);
}

/**
 * Class used when nothing more specific is known (marked source 'default', for review).
 *
 * @param string|null $nature
 * @return string|null
 */
function financialreporting_default_class($nature)
{
	$d = array('ASSET' => 'CURRENT_ASSET', 'LIABILITY' => 'CURRENT_LIABILITY', 'EQUITY' => 'EQUITY', 'INCOME' => 'REVENUE', 'EXPENSE' => 'OPERATING_EXPENSE', 'CLEARING' => 'CLEARING', 'EXCLUDED' => 'EXCLUDED');
	return $d[(string) $nature] ?? null;
}

/**
 * @param string|null $nature
 * @param string|null $class
 * @return bool True when the class belongs to the nature
 */
function financialreporting_class_valid($nature, $class)
{
	$all = financialreporting_classes();
	if ($class === null || !isset($all[(string) $nature])) {
		return false;
	}
	return in_array(financialreporting_base_class($class), $all[(string) $nature], true);
}

/**
 * The user's class structure (labels, hidden base classes, added classes), read once per page.
 *
 * @param bool $reload Read again (after saving)
 * @return array{types:array<string,string>,labels:array<string,string>,hidden:array<string,bool>,custom:array<string,array{base:string,label:string,position:int}>}
 */
function financialreporting_structure($reload = false)
{
	global $db, $conf;
	static $cache = null;
	if ($cache !== null && !$reload) {
		return $cache;
	}
	$cache = array('types' => array(), 'labels' => array(), 'hidden' => array(), 'custom' => array());
	if (!is_object($db)) {
		return $cache;
	}
	$resql = $db->query("SELECT kind, code, base_code, label, hidden, position FROM ".MAIN_DB_PREFIX."financialreporting_class WHERE entity = ".((int) $conf->entity)." ORDER BY position, rowid");
	while ($resql && ($obj = $db->fetch_object($resql))) {
		if ($obj->kind === 'type') {
			if ((string) $obj->label !== '') {
				$cache['types'][$obj->code] = $obj->label;
			}
		} elseif ($obj->base_code !== null && $obj->base_code !== '') {
			$cache['custom'][$obj->code] = array('base' => $obj->base_code, 'label' => (string) $obj->label, 'position' => (int) $obj->position);
		} else {
			if ((string) $obj->label !== '') {
				$cache['labels'][$obj->code] = $obj->label;
			}
			if ((int) $obj->hidden) {
				$cache['hidden'][$obj->code] = true;
			}
		}
	}
	return $cache;
}

/**
 * Base class of a class: itself for a base class, the base it belongs to for an added class.
 *
 * @param string|null $class
 * @return string|null
 */
function financialreporting_base_class($class)
{
	if ($class === null) {
		return null;
	}
	$st = financialreporting_structure();
	return (isset($st['custom'][$class]) ? $st['custom'][$class]['base'] : $class);
}

/**
 * Classes offered for a nature, in display order: each base class followed by the classes
 * added under it. Hidden base classes are left out, unless asked for.
 *
 * @param string $nature
 * @param bool   $withHidden
 * @return string[]
 */
function financialreporting_classes_for($nature, $withHidden = false)
{
	$st = financialreporting_structure();
	$out = array();
	foreach (financialreporting_classes()[(string) $nature] ?? array() as $base) {
		if ($withHidden || empty($st['hidden'][$base])) {
			$out[] = $base;
		}
		foreach ($st['custom'] as $code => $c) {
			if ($c['base'] === $base) {
				$out[] = $code;
			}
		}
	}
	return $out;
}

/**
 * Base classes that can't be hidden: the default (catch-all) class of each nature.
 *
 * @param string $class
 * @return bool
 */
function financialreporting_class_required($class)
{
	foreach (array_keys(financialreporting_classes()) as $nat) {
		if (financialreporting_default_class($nat) === $class) {
			return true;
		}
	}
	return false;
}

/**
 * Class of an account: its own when it belongs to its nature, else null.
 *
 * @param object $acc
 * @return string|null
 */
function financialreporting_account_class($acc)
{
	return (financialreporting_class_valid($acc->nature, $acc->class ?? null) ? $acc->class : null);
}

/**
 * Translated label of a class.
 *
 * @param string $class
 * @return string
 */
function financialreporting_class_label($class)
{
	global $langs;
	$st = financialreporting_structure();
	if (isset($st['custom'][$class])) {
		return $st['custom'][$class]['label'];
	}
	if (isset($st['labels'][$class])) {
		return $st['labels'][$class];
	}
	$langs->load('financialreporting@financialreporting');
	$key = 'FinRepClass_'.$class;
	$t = $langs->transnoentitiesnoconv($key);
	return ($t !== $key ? $t : ucfirst(strtolower(str_replace('_', ' ', $class))));
}

/**
 * Set the class of one account (the nature row must exist; accounts without a nature have no class).
 *
 * @param DoliDB      $db
 * @param User        $user
 * @param int         $entity
 * @param int         $accountid
 * @param string|null $class
 * @param string      $source chart, generic, parent, default, manual
 * @return int <0 on error
 */
function financialreporting_save_class($db, $user, $entity, $accountid, $class, $source)
{
	$sql = "UPDATE ".MAIN_DB_PREFIX."financialreporting_account_nature SET class = ".($class ? "'".$db->escape($class)."'" : "NULL");
	$sql .= ", class_source = ".($class ? "'".$db->escape($source)."'" : "NULL").", fk_user_modif = ".((int) $user->id);
	$sql .= " WHERE entity = ".((int) $entity)." AND fk_accounting_account = ".((int) $accountid);
	return $db->query($sql) ? 1 : -1;
}

/**
 * Read a class rule file (seed/classes/*.csv). Columns: nature;pcg_type;account_prefix;label_contains;class;comment.
 * Match columns as in nature rules (several values separated by |, empty = any); nature filters
 * which accounts a rule applies to. First match wins.
 *
 * @param string $file
 * @return array<int,array<string,string>>
 */
function financialreporting_load_class_rules($file)
{
	$rules = array();
	foreach (financialreporting_load_rules_raw($file) as $r) {
		if (($r['class'] ?? '') !== '') {
			$rules[] = $r;
		}
	}
	return $rules;
}

/**
 * Rows of a semicolon rule file (header line, '#' comments), no filtering.
 *
 * @param string $file
 * @return array<int,array<string,string>>
 */
function financialreporting_load_rules_raw($file)
{
	$rows = array();
	if ($file === '' || !is_readable($file)) {
		return $rows;
	}
	$fh = fopen($file, 'r');
	$header = null;
	while (($line = fgets($fh)) !== false) {
		$line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));
		if ($line === '' || $line[0] === '#') {
			continue;
		}
		$cols = str_getcsv($line, ';', '"', '\\');
		if ($header === null) {
			$header = array_map('trim', $cols);
			continue;
		}
		$r = array();
		foreach ($header as $i => $name) {
			$r[$name] = trim((string) ($cols[$i] ?? ''));
		}
		$rows[] = $r;
	}
	fclose($fh);
	return $rows;
}

/**
 * Suggested class rule file for the chart (catalogue kind "class": chart, then country), or ''.
 *
 * @param string $pcgversion
 * @return string File name in seed/classes/
 */
function financialreporting_suggest_class_rules($pcgversion)
{
	foreach (financialreporting_catalog_ranked('class', $pcgversion) as $e) {
		if ($e['match'] !== '') {
			return $e['file'];
		}
	}
	return '';
}

/**
 * Fill the class of every account that has a nature but no valid class, never overwriting a
 * valid class: 0. the user's own rules, 1. the chart's class rules, 2. generic class rules, 3. the parent account's
 * class (same nature), 4. the class of its children when they all agree, 5. the default class
 * of the nature (source 'default', for review).
 *
 * @param DoliDB $db
 * @param User   $user
 * @param int    $entity
 * @param string $pcgversion
 * @return array{user:int,chart:int,generic:int,parent:int,children:int,default:int,chartfile:string}
 */
function financialreporting_apply_default_classes($db, $user, $entity, $pcgversion)
{
	$counts = array('user' => 0, 'chart' => 0, 'generic' => 0, 'parent' => 0, 'children' => 0, 'default' => 0, 'chartfile' => '');
	$accounts = financialreporting_load_accounts($db, $entity, $pcgversion);
	$dir = financialreporting_seed_dir().'/classes';
	$chartfile = financialreporting_suggest_class_rules($pcgversion);
	$counts['chartfile'] = $chartfile;
	$steps = array(
		'user'    => financialreporting_user_rules($db, $entity, 'class'),	// My rules (Setup > Rule sets) first
		'chart'   => financialreporting_load_class_rules($chartfile !== '' ? $dir.'/'.$chartfile : ''),
		'generic' => financialreporting_load_class_rules($dir.'/generic.csv'),
	);
	$todo = function ($acc) {
		return $acc->nature !== null && financialreporting_account_class($acc) === null;
	};
	$db->begin();
	foreach ($steps as $source => $rules) {
		foreach ($accounts['byRowid'] as $acc) {
			if (!$todo($acc)) {
				continue;
			}
			foreach ($rules as $rule) {
				$natures = array_filter(array_map('trim', explode('|', strtoupper($rule['nature'] ?? ''))));
				$class = strtoupper($rule['class']);
				if (($natures && !in_array($acc->nature, $natures, true)) || !financialreporting_class_valid($acc->nature, $class)) {
					continue;
				}
				if (financialreporting_rule_matches($rule, $acc)) {
					$acc->class = $class;
					financialreporting_save_class($db, $user, $entity, $acc->rowid, $class, $source);
					$counts[$source]++;
					break;
				}
			}
		}
	}
	do {
		$changed = 0;
		foreach ($accounts['byRowid'] as $acc) {
			if (!$todo($acc) || empty($acc->account_parent)) {
				continue;
			}
			$parent = $accounts['byRowid'][(int) $acc->account_parent] ?? null;
			if ($parent && $parent->nature === $acc->nature && financialreporting_account_class($parent) !== null) {
				$acc->class = $parent->class;
				financialreporting_save_class($db, $user, $entity, $acc->rowid, $acc->class, 'parent');
				$counts['parent']++;
				$changed++;
			}
		}
	} while ($changed > 0);
	// a parent (header) account with no class of its own takes its children's class when they all agree
	$children = array();
	foreach ($accounts['byRowid'] as $acc) {
		if (!empty($acc->account_parent)) {
			$children[(int) $acc->account_parent][] = $acc;
		}
	}
	foreach ($accounts['byRowid'] as $acc) {
		if (!$todo($acc) || empty($children[(int) $acc->rowid])) {
			continue;
		}
		$found = array();
		foreach ($children[(int) $acc->rowid] as $child) {
			$cc = financialreporting_account_class($child);
			if ($child->nature === $acc->nature && $cc !== null) {
				$found[$cc] = true;	// children with no class yet don't count
			}
		}
		if (count($found) === 1) {
			$acc->class = key($found);
			financialreporting_save_class($db, $user, $entity, $acc->rowid, $acc->class, 'children');
			$counts['children'] = ($counts['children'] ?? 0) + 1;
		}
	}
	do {
		$changed = 0;
		foreach ($accounts['byRowid'] as $acc) {
			if (!$todo($acc) || empty($acc->account_parent)) {
				continue;
			}
			$parent = $accounts['byRowid'][(int) $acc->account_parent] ?? null;
			if ($parent && $parent->nature === $acc->nature && financialreporting_account_class($parent) !== null) {
				$acc->class = $parent->class;
				financialreporting_save_class($db, $user, $entity, $acc->rowid, $acc->class, 'parent');
				$counts['parent']++;
				$changed++;
			}
		}
	} while ($changed > 0);
	foreach ($accounts['byRowid'] as $acc) {
		if ($todo($acc)) {
			$acc->class = financialreporting_default_class($acc->nature);
			// types with one class only (equity, clearing, excluded): nothing to review
			$only = (count(financialreporting_classes()[$acc->nature] ?? array()) === 1);
			financialreporting_save_class($db, $user, $entity, $acc->rowid, $acc->class, $only ? 'generic' : 'default');
			$counts[$only ? 'generic' : 'default']++;
		}
	}
	$db->commit();
	return $counts;
}

/**
 * Balance sheet order of the classes on one side: non-current first (IFRS IAS 1 examples,
 * EU directive, France, OHADA) or current first, cash first (common in Australia, the US,
 * Canada, New Zealand). Display setting BS_ORDER: '' = by company country, current, noncurrent.
 *
 * @param string $side ASSET or LIABILITY
 * @return string[]
 */
function financialreporting_bs_class_order($side)
{
	$order = financialreporting_cfg('BS_ORDER');
	if ($order === '') {
		$order = (in_array(financialreporting_country_code(), array('AU', 'US', 'CA', 'NZ'), true) ? 'current' : 'noncurrent');
	}
	$list = ($side === 'ASSET' ? array('NONCURRENT_ASSET', 'CURRENT_ASSET', 'CASH') : array('NONCURRENT_LIABILITY', 'CURRENT_LIABILITY', 'CASH'));
	return ($order === 'current' ? array_reverse($list) : $list);
}

/**
 * Split the lines of a balance sheet side by class, in display order. A line placed on the
 * other side by its alternate nature, or a clearing account, takes the matching class of the
 * side it is shown on (current asset <-> current liability…).
 *
 * @param array  $lines Statement lines of the side (array('acc', 'amount', …))
 * @param string $side  ASSET or LIABILITY
 * @return array<string,array{lines:array,total:float,sub:array}> base class => lines, total and sub-groups
 *         ('' = lines of the base class itself, else one per class the user added under it)
 */
function financialreporting_bs_split($lines, $side)
{
	$mirror = array(
		'CURRENT_ASSET' => 'CURRENT_LIABILITY', 'CURRENT_LIABILITY' => 'CURRENT_ASSET',
		'NONCURRENT_ASSET' => 'NONCURRENT_LIABILITY', 'NONCURRENT_LIABILITY' => 'NONCURRENT_ASSET',
	);
	$out = array();
	foreach (financialreporting_bs_class_order($side) as $c) {
		$out[$c] = array('lines' => array(), 'total' => 0.0, 'sub' => array());
	}
	foreach ($lines as $l) {
		$class = financialreporting_account_class($l['acc']);
		$base = financialreporting_base_class($class);
		if ($base !== null && !financialreporting_class_valid($side, $base)) {
			$base = $mirror[$base] ?? null;
			$class = $base;	// shown on the other side: the user's own class doesn't apply there
		}
		if ($base === null || !isset($out[$base])) {
			$base = $class = financialreporting_default_class($side);
		}
		$out[$base]['lines'][] = $l;
		$out[$base]['total'] += $l['amount'];
		$key = ($class !== $base ? $class : '');
		if (!isset($out[$base]['sub'][$key])) {
			$out[$base]['sub'][$key] = array('lines' => array(), 'total' => 0.0);
		}
		$out[$base]['sub'][$key]['lines'][] = $l;
		$out[$base]['sub'][$key]['total'] += $l['amount'];
	}
	foreach ($out as &$g) {
		// lines of the base class itself first, then the user's classes in structure order
		$order = array_flip(array_merge(array(''), array_keys(financialreporting_structure()['custom'])));
		uksort($g['sub'], function ($a, $b) use ($order) {
			return ($order[$a] ?? 999) <=> ($order[$b] ?? 999);
		});
	}
	unset($g);
	return array_filter($out, function ($g) {
		return (bool) $g['lines'];
	});
}

/**
 * What the company country's rules require for each class (seed/classes/frameworks/), IFRS
 * when the country has no entry of its own.
 *
 * @return array{file:string,label:string,review:string,notes:string,rows:array<string,array{heading:string,required:bool,note:string}>}
 */
function financialreporting_framework()
{
	$file = 'IFRS.csv';
	foreach (financialreporting_catalog_ranked('framework', '') as $e) {
		if ($e['match'] === 'country') {
			$file = $e['file'];
			break;
		}
	}
	$path = financialreporting_seed_dir().'/classes/frameworks/'.$file;
	$fw = array('file' => $file, 'label' => $file, 'review' => 'unreviewed', 'notes' => '', 'rows' => array());
	$fh = @fopen($path, 'r');
	if ($fh) {
		while (($line = fgets($fh)) !== false) {
			if (preg_match('/^#\s*(label|review|notes):\s*(.+)$/', trim($line), $m)) {
				$fw[$m[1]] = trim($m[2]);
			}
		}
		fclose($fh);
	}
	foreach (financialreporting_load_rules_raw($path) as $r) {
		if (($r['class'] ?? '') !== '') {
			$fw['rows'][$r['class']] = array('heading' => (string) ($r['heading'] ?? ''), 'required' => (strtolower((string) ($r['required'] ?? '')) === 'yes'), 'note' => (string) ($r['note'] ?? ''));
		}
	}
	return $fw;
}

/**
 * Number of accounts in each class (added classes counted on their own).
 *
 * @param array $accounts From financialreporting_load_accounts()
 * @return array<string,int>
 */
function financialreporting_class_counts($accounts)
{
	$out = array();
	foreach ($accounts['byRowid'] as $acc) {
		$c = financialreporting_account_class($acc);
		if ($c !== null) {
			$out[$c] = ($out[$c] ?? 0) + 1;
		}
	}
	return $out;
}

/**
 * Save one row of the user's structure (insert or update).
 *
 * @param DoliDB      $db
 * @param User        $user
 * @param int         $entity
 * @param string      $kind     type or class
 * @param string      $code
 * @param string|null $label    '' or null = default label
 * @param int         $hidden
 * @param string|null $base     added classes: their base class
 * @return int <0 on error
 */
function financialreporting_save_structure($db, $user, $entity, $kind, $code, $label, $hidden = 0, $base = null)
{
	$label = trim((string) $label);
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."financialreporting_class WHERE entity = ".((int) $entity)." AND kind = '".$db->escape($kind)."' AND code = '".$db->escape($code)."'");
	$obj = ($resql ? $db->fetch_object($resql) : null);
	$vals = "label = ".($label !== '' ? "'".$db->escape(dol_trunc($label, 255, 'right', 'UTF-8', 1))."'" : "NULL").", hidden = ".($hidden ? 1 : 0).", fk_user_modif = ".((int) $user->id);
	if ($obj) {
		$sql = "UPDATE ".MAIN_DB_PREFIX."financialreporting_class SET ".$vals." WHERE rowid = ".((int) $obj->rowid);
	} else {
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."financialreporting_class (entity, kind, code, base_code, label, hidden, position, fk_user_modif) VALUES (";
		$sql .= ((int) $entity).", '".$db->escape($kind)."', '".$db->escape($code)."', ".($base ? "'".$db->escape($base)."'" : "NULL").", ";
		$sql .= ($label !== '' ? "'".$db->escape(dol_trunc($label, 255, 'right', 'UTF-8', 1))."'" : "NULL").", ".($hidden ? 1 : 0).", ".((int) dol_now()).", ".((int) $user->id).")";
	}
	return $db->query($sql) ? 1 : -1;
}

/**
 * Add a class under a base class. Its code is U_ + the label, made unique.
 *
 * @param DoliDB $db
 * @param User   $user
 * @param int    $entity
 * @param string $base
 * @param string $label
 * @return string New class code, '' on error
 */
function financialreporting_add_class($db, $user, $entity, $base, $label)
{
	$label = trim($label);
	$valid = false;
	foreach (financialreporting_classes() as $list) {
		if (in_array($base, $list, true)) {
			$valid = true;
		}
	}
	if (!$valid || $label === '') {
		return '';
	}
	$stem = 'U_'.substr(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', dol_string_unaccent($label))), 0, 24);
	$code = $stem;
	$n = 1;
	$st = financialreporting_structure(true);
	while (isset($st['custom'][$code]) || isset(array_flip(array_merge(...array_values(financialreporting_classes())))[$code])) {
		$code = $stem.'_'.(++$n);
	}
	return financialreporting_save_structure($db, $user, $entity, 'class', $code, $label, 0, $base) > 0 ? $code : '';
}

/**
 * Delete a class the user added. Refused while accounts use it.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param string $code
 * @return int 1 deleted, 0 refused, <0 error
 */
function financialreporting_delete_class($db, $entity, $code)
{
	$resql = $db->query("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."financialreporting_account_nature WHERE entity = ".((int) $entity)." AND class = '".$db->escape($code)."'");
	$obj = ($resql ? $db->fetch_object($resql) : null);
	if (!$obj || (int) $obj->nb > 0) {
		return 0;
	}
	return $db->query("DELETE FROM ".MAIN_DB_PREFIX."financialreporting_class WHERE entity = ".((int) $entity)." AND kind = 'class' AND code = '".$db->escape($code)."' AND base_code IS NOT NULL") ? 1 : -1;
}

/**
 * Move a class the user added to another base class of the same type. Its accounts follow
 * (they keep the class, which now adds up into the new base).
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param string $code
 * @param string $newBase
 * @return int <0 on error, 0 refused (other type)
 */
function financialreporting_move_class($db, $entity, $code, $newBase)
{
	$st = financialreporting_structure();
	if (!isset($st['custom'][$code])) {
		return 0;
	}
	$old = $st['custom'][$code]['base'];
	foreach (financialreporting_classes() as $list) {
		if (in_array($old, $list, true) && in_array($newBase, $list, true)) {
			return $db->query("UPDATE ".MAIN_DB_PREFIX."financialreporting_class SET base_code = '".$db->escape($newBase)."' WHERE entity = ".((int) $entity)." AND kind = 'class' AND code = '".$db->escape($code)."'") ? 1 : -1;
		}
	}
	return 0;
}

