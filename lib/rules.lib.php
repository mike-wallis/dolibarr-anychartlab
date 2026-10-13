<?php
/**
 * Financial Reporting — rule sets: the ones shipped with the module (read only, seed/*.csv,
 * seed/classes/*.csv) and the user's own rules ("My rules", llx_financialreporting_rule).
 *
 * Order when natures / classes are applied (only accounts still empty are filled):
 *   natures: Dolibarr's clearing accounts, My rules, the chart's rule set, generic rules, parent
 *   classes: My rules, the chart's class rules, generic class rules, parent / children, default
 */

/**
 * The rule sets that can be viewed: My rules first, then the shipped files.
 *
 * @param string $pcgversion
 * @return array<string,array{kind:string,label:string,file:string,match:string}> key => set
 */
function financialreporting_rule_sets($pcgversion)
{
	global $langs;
	$langs->load('financialreporting@financialreporting');
	$sets = array(
		'user:nature' => array('kind' => 'nature', 'label' => $langs->transnoentitiesnoconv('FinRepRulesMyNature'), 'file' => '', 'match' => 'user'),
		'user:class' => array('kind' => 'class', 'label' => $langs->transnoentitiesnoconv('FinRepRulesMyClass'), 'file' => '', 'match' => 'user'),
	);
	foreach (financialreporting_catalog_ranked('nature', $pcgversion) as $e) {
		$sets['nature:'.$e['file']] = array('kind' => 'nature', 'label' => $langs->transnoentitiesnoconv('FinRepRulesTypes').': '.$e['label'], 'file' => $e['file'], 'match' => $e['match']);
	}
	$sets['nature:generic.csv'] = array('kind' => 'nature', 'label' => $langs->transnoentitiesnoconv('FinRepRulesTypes').': '.$langs->transnoentitiesnoconv('FinRepStartGenericOnly'), 'file' => 'generic.csv', 'match' => 'generic');
	foreach (financialreporting_catalog_ranked('class', $pcgversion) as $e) {
		$sets['class:'.$e['file']] = array('kind' => 'class', 'label' => $langs->transnoentitiesnoconv('FinRepRulesClasses').': '.$e['label'], 'file' => 'classes/'.$e['file'], 'match' => $e['match']);
	}
	$sets['class:generic.csv'] = array('kind' => 'class', 'label' => $langs->transnoentitiesnoconv('FinRepRulesClasses').': '.$langs->transnoentitiesnoconv('FinRepStartGenericOnly'), 'file' => 'classes/generic.csv', 'match' => 'generic');
	return $sets;
}

/**
 * Rules of a set, as arrays in the seed file format (pcg_type, account_prefix,
 * label_contains, nature, nature_alt, contra, class, comment; class rules use nature as filter).
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param string $key   Key from financialreporting_rule_sets()
 * @param bool   $activeOnly
 * @return array<int,array<string,string>>
 */
function financialreporting_rule_set_rules($db, $entity, $key, $activeOnly = true)
{
	if (strpos($key, 'user:') === 0) {
		return financialreporting_user_rules($db, $entity, substr($key, 5), $activeOnly);
	}
	list($kind, $file) = explode(':', $key, 2);
	$path = financialreporting_seed_dir().'/'.($kind === 'class' ? 'classes/' : '').basename($file);
	$out = array();
	foreach (financialreporting_load_rules_raw($path) as $r) {
		if (($kind === 'class' && ($r['class'] ?? '') !== '') || ($kind === 'nature' && ($r['nature'] ?? '') !== '')) {
			$out[] = $r;
		}
	}
	return $out;
}

/**
 * The user's own rules of one kind, in order.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param string $kind nature or class
 * @param bool   $activeOnly
 * @return array<int,array<string,string>> seed-format arrays plus rowid, active, position
 */
function financialreporting_user_rules($db, $entity, $kind, $activeOnly = true)
{
	$out = array();
	$sql = "SELECT rowid, position, pcg_type, account_prefix, label_contains, natures, nature, nature_alt, contra, class, comment, active FROM ".MAIN_DB_PREFIX."financialreporting_rule";
	$sql .= " WHERE entity = ".((int) $entity)." AND kind = '".$db->escape($kind === 'class' ? 'class' : 'nature')."'".($activeOnly ? " AND active = 1" : "")." ORDER BY position, rowid";
	$resql = $db->query($sql);
	while ($resql && ($o = $db->fetch_object($resql))) {
		$out[] = array(
			'rowid' => (int) $o->rowid, 'position' => (int) $o->position, 'active' => (int) $o->active,
			'pcg_type' => (string) $o->pcg_type, 'account_prefix' => (string) $o->account_prefix, 'label_contains' => (string) $o->label_contains,
			// class rules keep the natures filter in "nature", like the seed files
			'nature' => ($kind === 'class' ? (string) $o->natures : (string) $o->nature),
			'nature_alt' => (string) $o->nature_alt, 'contra' => (string) (int) $o->contra, 'class' => (string) $o->class, 'comment' => (string) $o->comment,
		);
	}
	return $out;
}

/**
 * Insert or update one of the user's rules.
 *
 * @param DoliDB $db
 * @param User   $user
 * @param int    $entity
 * @param string $kind nature or class
 * @param int    $rowid 0 = new
 * @param array  $r     seed-format array (class rules: nature = natures filter), plus position, active
 * @return int <0 on error
 */
function financialreporting_save_user_rule($db, $user, $entity, $kind, $rowid, $r)
{
	$natures = financialreporting_natures_default();
	$clean = function ($v) {
		return trim(preg_replace('/\s*\|\s*/', '|', (string) $v), " |");
	};
	$isClass = ($kind === 'class');
	$nature = strtoupper(trim((string) ($r['nature'] ?? '')));
	$filter = array();
	foreach (explode('|', $nature) as $n) {
		if (isset($natures[trim($n)])) {
			$filter[] = trim($n);
		}
	}
	$alt = strtoupper(trim((string) ($r['nature_alt'] ?? '')));
	$class = strtoupper(trim((string) ($r['class'] ?? '')));
	$f = array(
		'position' => (int) ($r['position'] ?? 0),
		'pcg_type' => $clean($r['pcg_type'] ?? ''),
		'account_prefix' => $clean($r['account_prefix'] ?? ''),
		'label_contains' => $clean($r['label_contains'] ?? ''),
		'natures' => ($isClass ? implode('|', $filter) : ''),
		'nature' => (!$isClass && isset($natures[$nature]) ? $nature : ''),
		'nature_alt' => (!$isClass && isset($natures[$alt]) && $alt !== $nature ? $alt : ''),
		'contra' => (!$isClass && !empty($r['contra']) ? 1 : 0),
		'class' => ($isClass ? $class : ''),
		'comment' => trim((string) ($r['comment'] ?? '')),
		'active' => (isset($r['active']) ? (int) (bool) $r['active'] : 1),
	);
	if ((!$isClass && $f['nature'] === '') || ($isClass && $f['class'] === '')) {
		return 0;	// a rule must set something
	}
	$q = function ($v) use ($db) {
		return ($v === '' ? "NULL" : "'".$db->escape(dol_trunc((string) $v, 255, 'right', 'UTF-8', 1))."'");
	};
	$set = "position = ".$f['position'].", pcg_type = ".$q($f['pcg_type']).", account_prefix = ".$q($f['account_prefix']).", label_contains = ".$q($f['label_contains']);
	$set .= ", natures = ".$q($f['natures']).", nature = ".$q($f['nature']).", nature_alt = ".$q($f['nature_alt']).", contra = ".$f['contra'].", class = ".$q($f['class']);
	$set .= ", comment = ".$q($f['comment']).", active = ".$f['active'].", fk_user_modif = ".((int) $user->id);
	if ($rowid > 0) {
		return $db->query("UPDATE ".MAIN_DB_PREFIX."financialreporting_rule SET ".$set." WHERE rowid = ".((int) $rowid)." AND entity = ".((int) $entity)) ? 1 : -1;
	}
	$sql = "INSERT INTO ".MAIN_DB_PREFIX."financialreporting_rule (entity, kind, position, pcg_type, account_prefix, label_contains, natures, nature, nature_alt, contra, class, comment, active, fk_user_modif) VALUES (";
	$sql .= ((int) $entity).", '".($isClass ? 'class' : 'nature')."', ".$f['position'].", ".$q($f['pcg_type']).", ".$q($f['account_prefix']).", ".$q($f['label_contains']).", ".$q($f['natures']).", ".$q($f['nature']).", ".$q($f['nature_alt']).", ".$f['contra'].", ".$q($f['class']).", ".$q($f['comment']).", ".$f['active'].", ".((int) $user->id).")";
	return $db->query($sql) ? 1 : -1;
}

/**
 * Does a rule apply to an account? Nature rules: match columns. Class rules: also the natures
 * filter, and the class must belong to the account's nature.
 *
 * @param array  $rule
 * @param object $acc
 * @param string $kind
 * @return bool
 */
function financialreporting_rule_applies($rule, $acc, $kind)
{
	if ($kind === 'class') {
		$filter = array_filter(array_map('trim', explode('|', strtoupper((string) ($rule['nature'] ?? '')))));
		if ($acc->nature === null || ($filter && !in_array($acc->nature, $filter, true)) || !financialreporting_class_valid($acc->nature, strtoupper((string) $rule['class']))) {
			return false;
		}
	}
	return financialreporting_rule_matches($rule, $acc);
}

/**
 * For each rule of a set, the accounts it would take (first match wins within the set), and
 * which of them are still empty (the only ones Apply would fill).
 *
 * @param array  $rules
 * @param array  $accounts From financialreporting_load_accounts()
 * @param string $kind
 * @return array<int,array{all:object[],empty:object[]}> rule index => accounts
 */
function financialreporting_rule_matches_per_rule($rules, $accounts, $kind)
{
	$out = array();
	foreach ($rules as $i => $r) {
		$out[$i] = array('all' => array(), 'empty' => array());
	}
	foreach ($accounts['byRowid'] as $acc) {
		foreach ($rules as $i => $r) {
			if (financialreporting_rule_applies($r, $acc, $kind)) {
				$out[$i]['all'][] = $acc;
				$isEmpty = ($kind === 'class' ? financialreporting_account_class($acc) === null : $acc->nature === null);
				if ($isEmpty) {
					$out[$i]['empty'][] = $acc;
				}
				break;
			}
		}
	}
	return $out;
}
