<?php
/**
 * Any-Chart Reports Lab — shared functions.
 *
 * Everything that knows about natures lives here, so the pages stay thin and the
 * logic can later be lifted into core (htdocs/accountancy/) in one piece.
 *
 * Nature model (design draft on Dolibarr/dolibarr#31760, refined by prior art):
 *   nature      ASSET, LIABILITY, EQUITY, INCOME, EXPENSE, CLEARING, EXCLUDED, or NULL (= unclassified)
 *   nature_alt  optional nature used when the balance is on the opposite side of the
 *               nature's normal side (Tryton's debit/credit type). Replaces BIFUNCTIONAL.
 *   contra      1 = contra account: stays on its nature's side, shown as a deduction.
 *               Replaces CONTRA_ASSET (and covers contra-liability/equity/income too).
 *
 * Balances are always computed as debit - credit ("net debit") and turned into a
 * presentation amount only when placed on a statement side.
 */

/**
 * @return array<string,string> nature code => label, in display order
 */
function anychartlab_natures()
{
	return array(
		'ASSET'     => 'Asset',
		'LIABILITY' => 'Liability',
		'EQUITY'    => 'Equity',
		'INCOME'    => 'Income',
		'EXPENSE'   => 'Expense',
		'CLEARING'  => 'Clearing / suspense',
		'EXCLUDED'  => 'Excluded (totals, memo, off-balance)',
	);
}

/**
 * Normal balance side of a nature: 'D' (debit) or 'C' (credit). '' if it has none.
 *
 * @param string|null $nature
 * @return string
 */
function anychartlab_normal_side($nature)
{
	if (in_array($nature, array('ASSET', 'EXPENSE', 'CLEARING'))) {
		return 'D';
	}
	if (in_array($nature, array('LIABILITY', 'EQUITY', 'INCOME'))) {
		return 'C';
	}
	return '';
}

/**
 * @param string|null $nature
 * @return bool True for natures reported on the income statement
 */
function anychartlab_is_pl($nature)
{
	return in_array($nature, array('INCOME', 'EXPENSE'));
}

/**
 * pcg_version (chart code) of the chart of accounts selected in Accounting setup.
 *
 * @param DoliDB $db
 * @return string '' if none selected
 */
function anychartlab_active_chart($db)
{
	$sql = "SELECT pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE rowid = ".((int) getDolGlobalInt('CHARTOFACCOUNTS'));
	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		return (string) $obj->pcg_version;
	}
	return '';
}

/**
 * Load the accounts of one chart with their nature row (if any).
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param string $pcgversion
 * @return array{byRowid: array<int,object>, byNumber: array<string,object>}
 */
function anychartlab_load_accounts($db, $entity, $pcgversion)
{
	$byRowid = array();
	$byNumber = array();
	$sql = "SELECT a.rowid, a.account_number, a.label, a.pcg_type, a.account_parent, a.active,";
	$sql .= " n.nature, n.nature_alt, n.contra, n.source";
	$sql .= " FROM ".MAIN_DB_PREFIX."accounting_account as a";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."anychartlab_nature as n ON n.fk_accounting_account = a.rowid AND n.entity = ".((int) $entity);
	$sql .= " WHERE a.entity = ".((int) $entity);
	$sql .= " AND a.fk_pcg_version = '".$db->escape($pcgversion)."'";
	$sql .= " ORDER BY a.account_number";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$obj->account_number = (string) $obj->account_number;
			$obj->nature = ($obj->nature === '' ? null : $obj->nature);
			$obj->nature_alt = ($obj->nature_alt === '' ? null : $obj->nature_alt);
			$obj->contra = (int) $obj->contra;
			$byRowid[(int) $obj->rowid] = $obj;
			$byNumber[$obj->account_number] = $obj;
		}
	} else {
		dol_print_error($db);
	}
	return array('byRowid' => $byRowid, 'byNumber' => $byNumber);
}

/**
 * Insert or update the nature of one account.
 *
 * @param DoliDB      $db
 * @param User        $user
 * @param int         $entity
 * @param int         $accountid  llx_accounting_account.rowid
 * @param string|null $nature
 * @param string|null $natureAlt
 * @param int         $contra
 * @param string      $source     chart, generic, parent, manual, import
 * @return int <0 on error
 */
function anychartlab_save_nature($db, $user, $entity, $accountid, $nature, $natureAlt, $contra, $source)
{
	$natures = anychartlab_natures();
	$nature = (isset($natures[(string) $nature]) ? $nature : null);
	$natureAlt = (isset($natures[(string) $natureAlt]) && $natureAlt !== $nature ? $natureAlt : null);

	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."anychartlab_nature WHERE entity = ".((int) $entity)." AND fk_accounting_account = ".((int) $accountid);
	$resql = $db->query($sql);
	if (!$resql) {
		return -1;
	}
	$obj = $db->fetch_object($resql);
	$values = "nature = ".($nature ? "'".$db->escape($nature)."'" : "NULL");
	$values .= ", nature_alt = ".($natureAlt ? "'".$db->escape($natureAlt)."'" : "NULL");
	$values .= ", contra = ".($contra ? 1 : 0);
	$values .= ", source = '".$db->escape($source)."'";
	$values .= ", fk_user_modif = ".((int) $user->id);
	if ($obj) {
		$sql = "UPDATE ".MAIN_DB_PREFIX."anychartlab_nature SET ".$values." WHERE rowid = ".((int) $obj->rowid);
	} else {
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."anychartlab_nature (entity, fk_accounting_account, nature, nature_alt, contra, source, fk_user_modif) VALUES (";
		$sql .= ((int) $entity).", ".((int) $accountid).", ".($nature ? "'".$db->escape($nature)."'" : "NULL").", ".($natureAlt ? "'".$db->escape($natureAlt)."'" : "NULL");
		$sql .= ", ".($contra ? 1 : 0).", '".$db->escape($source)."', ".((int) $user->id).")";
	}
	return $db->query($sql) ? 1 : -1;
}

/**
 * Remove all natures of one chart (start again).
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param string $pcgversion
 * @return int Number of rows deleted, <0 on error
 */
function anychartlab_reset($db, $entity, $pcgversion)
{
	$sql = "DELETE FROM ".MAIN_DB_PREFIX."anychartlab_nature WHERE entity = ".((int) $entity);
	$sql .= " AND fk_accounting_account IN (SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_account WHERE entity = ".((int) $entity)." AND fk_pcg_version = '".$db->escape($pcgversion)."')";
	$resql = $db->query($sql);
	return $resql ? $db->affected_rows($resql) : -1;
}

/**
 * Directory holding the rule files (seed/generic.csv, seed/<pcg_version>.csv).
 *
 * @return string
 */
function anychartlab_seed_dir()
{
	return dirname(__DIR__).'/seed';
}

/**
 * Read a rule file. Format: semicolon-separated, header line, '#' comments.
 * Columns: pcg_type;account_prefix;label_contains;nature;nature_alt;contra;comment
 * Each match column may hold several values separated by '|'. Empty = any.
 *
 * @param string $file
 * @return array<int,array<string,string>> Rules in file order (first match wins)
 */
function anychartlab_load_rules($file)
{
	$rules = array();
	if (!is_readable($file)) {
		return $rules;
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
		$rule = array();
		foreach ($header as $i => $name) {
			$rule[$name] = trim((string) ($cols[$i] ?? ''));
		}
		if (!empty($rule['nature'])) {
			$rules[] = $rule;
		}
	}
	fclose($fh);
	return $rules;
}

/**
 * @param array<string,string> $rule
 * @param object               $account
 * @return bool
 */
function anychartlab_rule_matches($rule, $account)
{
	if (($rule['pcg_type'] ?? '') !== '') {
		$ok = false;
		foreach (explode('|', $rule['pcg_type']) as $v) {
			if (strcasecmp(trim($v), trim((string) $account->pcg_type)) === 0) {
				$ok = true;
			}
		}
		if (!$ok) {
			return false;
		}
	}
	if (($rule['account_prefix'] ?? '') !== '') {
		$ok = false;
		foreach (explode('|', $rule['account_prefix']) as $v) {
			$v = trim($v);
			if ($v !== '' && strpos($account->account_number, $v) === 0) {
				$ok = true;
			}
		}
		if (!$ok) {
			return false;
		}
	}
	if (($rule['label_contains'] ?? '') !== '') {
		$ok = false;
		foreach (explode('|', $rule['label_contains']) as $v) {
			$v = trim($v);
			if ($v !== '' && stripos((string) $account->label, $v) !== false) {
				$ok = true;
			}
		}
		if (!$ok) {
			return false;
		}
	}
	return true;
}

/**
 * "Apply default natures": classify every still-unclassified account of the chart,
 * in three steps, never overwriting a nature already set:
 *   1. the chart's own rules  (seed/<pcg_version>.csv)   source 'chart'
 *   2. the generic rules       (seed/generic.csv)         source 'generic'
 *   3. inherit from the parent account                    source 'parent'
 *
 * @param DoliDB $db
 * @param User   $user
 * @param int    $entity
 * @param string $pcgversion
 * @param  string|null $ruleFile Rule file in seed/ for step 1; null = suggested one, '' = none (generic rules only)
 * @return array{chart:int,generic:int,parent:int,remaining:int,chartfile:string}
 */
function anychartlab_apply_defaults($db, $user, $entity, $pcgversion, $ruleFile = null)
{
	$counts = array('chart' => 0, 'generic' => 0, 'parent' => 0, 'remaining' => 0, 'chartfile' => '');
	$accounts = anychartlab_load_accounts($db, $entity, $pcgversion);

	if ($ruleFile === null) {
		$sug = anychartlab_suggest_ruleset($pcgversion);
		$ruleFile = ($sug ? $sug['file'] : '');
	}
	$chartfile = ($ruleFile !== '' ? anychartlab_seed_dir().'/'.basename($ruleFile) : '');
	$counts['chartfile'] = ($chartfile !== '' && is_readable($chartfile) ? basename($chartfile) : '');
	$steps = array(
		'chart'   => anychartlab_load_rules($chartfile),
		'generic' => anychartlab_load_rules(anychartlab_seed_dir().'/generic.csv'),
	);

	$db->begin();
	foreach ($steps as $source => $rules) {
		foreach ($accounts['byRowid'] as $acc) {
			if ($acc->nature !== null) {
				continue;
			}
			foreach ($rules as $rule) {
				if (anychartlab_rule_matches($rule, $acc)) {
					$acc->nature = strtoupper($rule['nature']);
					$acc->nature_alt = (($rule['nature_alt'] ?? '') !== '' ? strtoupper($rule['nature_alt']) : null);
					$acc->contra = (int) ($rule['contra'] ?? 0);
					anychartlab_save_nature($db, $user, $entity, $acc->rowid, $acc->nature, $acc->nature_alt, $acc->contra, $source);
					$counts[$source]++;
					break;
				}
			}
		}
	}

	// Step 3: inherit from parent, repeated so that several levels resolve
	do {
		$changed = 0;
		foreach ($accounts['byRowid'] as $acc) {
			if ($acc->nature !== null || empty($acc->account_parent)) {
				continue;
			}
			$parent = $accounts['byRowid'][(int) $acc->account_parent] ?? null;
			if ($parent && $parent->nature !== null) {
				$acc->nature = $parent->nature;
				$acc->nature_alt = $parent->nature_alt;
				$acc->contra = 0;	// contra is specific to an account, never inherited
				anychartlab_save_nature($db, $user, $entity, $acc->rowid, $acc->nature, $acc->nature_alt, 0, 'parent');
				$counts['parent']++;
				$changed++;
			}
		}
	} while ($changed > 0);
	$db->commit();

	foreach ($accounts['byRowid'] as $acc) {
		if ($acc->nature === null) {
			$counts['remaining']++;
		}
	}
	return $counts;
}

/**
 * Code of the original design draft that matches the refined model, for the
 * side-by-side comparison (BIFUNCTIONAL / CONTRA_ASSET vs nature_alt / contra).
 *
 * @param object $acc
 * @return string
 */
function anychartlab_draft_code($acc)
{
	if ($acc->nature === null) {
		return 'UNCLASSIFIED';
	}
	if ($acc->contra && $acc->nature === 'ASSET') {
		return 'CONTRA_ASSET';
	}
	if ($acc->contra) {
		return $acc->nature.' (no draft code for contra)';
	}
	if ($acc->nature_alt !== null) {
		$pair = array($acc->nature, $acc->nature_alt);
		sort($pair);
		if ($pair === array('ASSET', 'LIABILITY')) {
			return 'BIFUNCTIONAL';
		}
		return $acc->nature.' (no draft code for alt '.$acc->nature_alt.')';
	}
	return $acc->nature;
}

/**
 * Nature that applies to a balance: the alternate nature when the balance is on the
 * opposite side of the nature's normal side, otherwise the nature itself.
 *
 * @param object $acc
 * @param float  $netdebit debit - credit
 * @return string|null
 */
function anychartlab_effective_nature($acc, $netdebit)
{
	if ($acc->nature === null) {
		return null;
	}
	if ($acc->nature_alt !== null && !$acc->contra && abs($netdebit) >= 0.005) {
		$side = anychartlab_normal_side($acc->nature);
		if (($side === 'D' && $netdebit < 0) || ($side === 'C' && $netdebit > 0)) {
			return $acc->nature_alt;
		}
	}
	return $acc->nature;
}

/**
 * Net debit (debit - credit) per account number over a period.
 *
 * @param DoliDB   $db
 * @param int      $entity
 * @param int|null $from  Timestamp (inclusive) or null for no lower bound
 * @param int      $to    Timestamp (inclusive)
 * @return array<string,float>
 */
function anychartlab_balances($db, $entity, $from, $to)
{
	$out = array();
	$sql = "SELECT numero_compte, SUM(debit) as debit, SUM(credit) as credit";
	$sql .= " FROM ".MAIN_DB_PREFIX."accounting_bookkeeping";
	$sql .= " WHERE entity = ".((int) $entity);
	if ($from !== null) {
		$sql .= " AND doc_date >= '".$db->idate($from)."'";
	}
	$sql .= " AND doc_date <= '".$db->idate($to)."'";
	$sql .= " GROUP BY numero_compte";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out[(string) $obj->numero_compte] = (float) $obj->debit - (float) $obj->credit;
		}
	} else {
		dol_print_error($db);
	}
	return $out;
}

/**
 * Start of the Balance Sheet window: the day after the last closed fiscal year that
 * ends before the date (closure already carried those periods forward as opening
 * entries), or null when no fiscal year has been closed (= all entries).
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param int    $asof
 * @return array{start:int|null,label:string,warnings:string[]}
 */
function anychartlab_window_start($db, $entity, $asof)
{
	$res = array('start' => null, 'label' => 'all entries (no closed fiscal year)', 'warnings' => array());
	$lastClosedEnd = null;
	$years = array();
	$sql = "SELECT label, date_start, date_end, statut FROM ".MAIN_DB_PREFIX."accounting_fiscalyear WHERE entity = ".((int) $entity)." ORDER BY date_start";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$years[] = $obj;
			$end = $db->jdate($obj->date_end);
			if ((int) $obj->statut === 1 && $end < $asof && ($lastClosedEnd === null || $end > $lastClosedEnd)) {
				$lastClosedEnd = $end;
				$res['label'] = 'entries after the closure of '.$obj->label;
			}
		}
	}
	if ($lastClosedEnd !== null) {
		$res['start'] = dol_time_plus_duree($lastClosedEnd, 1, 'd');
		foreach ($years as $obj) {
			if ((int) $obj->statut !== 1 && $db->jdate($obj->date_end) <= $lastClosedEnd) {
				$res['warnings'][] = 'Fiscal year "'.$obj->label.'" is not closed but ends before a closed one. Closure only carries forward the closed year\'s own movements, so its result may be missing.';
			}
		}
	}
	return $res;
}

/**
 * First day of the fiscal year containing a date (SOCIETE_FISCAL_MONTH_START).
 *
 * @param int $date
 * @return int
 */
function anychartlab_fiscal_year_start($date)
{
	$startMonth = max(1, getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1));
	$y = (int) dol_print_date($date, '%Y');
	$m = (int) dol_print_date($date, '%m');
	return dol_mktime(0, 0, 0, $startMonth, 1, ($m >= $startMonth ? $y : $y - 1));
}

/**
 * Build the Balance Sheet at a date (design draft §6).
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param string $pcgversion
 * @param int    $asof
 * @return array
 */
function anychartlab_build_balance_sheet($db, $entity, $pcgversion, $asof)
{
	$accounts = anychartlab_load_accounts($db, $entity, $pcgversion);
	$window = anychartlab_window_start($db, $entity, $asof);
	$balances = anychartlab_balances($db, $entity, $window['start'], $asof);
	return anychartlab_bs_from_balances($accounts, $balances, $window);
}

/**
 * Balance Sheet from net debits per account (used by the report, and by tests with sample figures).
 *
 * @param array $accounts From anychartlab_load_accounts()
 * @param array $balances account_number => debit - credit
 * @param array $window   From anychartlab_window_start()
 * @return array
 */
function anychartlab_bs_from_balances($accounts, $balances, $window)
{
	$bs = array(
		'accounts' => $accounts,
		'window' => $window,
		'sections' => array('ASSET' => array(), 'LIABILITY' => array(), 'EQUITY' => array()),
		'totals' => array('ASSET' => 0.0, 'LIABILITY' => 0.0, 'EQUITY' => 0.0),
		'result' => 0.0, 'result_lines' => 0,
		'unclassified' => array(), 'unknown' => array(), 'excluded' => 0.0,
		'clearing' => array(),
	);

	foreach ($balances as $number => $netdebit) {
		if (abs($netdebit) < 0.005) {
			continue;
		}
		$acc = $accounts['byNumber'][(string) $number] ?? null;
		if ($acc === null) {
			$bs['unknown'][] = array('account_number' => (string) $number, 'netdebit' => $netdebit);
			continue;
		}
		$eff = anychartlab_effective_nature($acc, $netdebit);
		if ($eff === null) {
			$bs['unclassified'][] = array('acc' => $acc, 'netdebit' => $netdebit);
			continue;
		}
		if ($eff === 'EXCLUDED') {
			$bs['excluded'] += $netdebit;
			continue;
		}
		if (anychartlab_is_pl($eff)) {
			$bs['result'] += -$netdebit;
			$bs['result_lines']++;
			continue;
		}
		$note = '';
		if ($eff === 'CLEARING') {
			$side = ($netdebit >= 0 ? 'ASSET' : 'LIABILITY');
			$note = 'clearing account with a balance';
			$bs['clearing'][] = array('acc' => $acc, 'netdebit' => $netdebit);
		} else {
			$side = $eff;
			if ($eff !== $acc->nature) {
				$note = 'shown as '.strtolower($eff).' because its balance is on the other side';
			}
		}
		if ($acc->contra) {
			$note = 'contra: deduction';
		}
		$amount = ($side === 'ASSET' ? $netdebit : -$netdebit);
		$bs['sections'][$side][] = array('acc' => $acc, 'amount' => $amount, 'note' => $note, 'netdebit' => $netdebit, 'section' => $side);
		$bs['totals'][$side] += $amount;
	}
	foreach ($bs['sections'] as &$lines) {
		usort($lines, function ($a, $b) {
			return strcmp($a['acc']->account_number, $b['acc']->account_number);
		});
	}
	unset($lines);

	$bs['total_liab_equity'] = $bs['totals']['LIABILITY'] + $bs['totals']['EQUITY'] + $bs['result'];
	$bs['difference'] = $bs['totals']['ASSET'] - $bs['total_liab_equity'];
	$unexplained = 0.0;
	foreach ($bs['unclassified'] as $u) {
		$unexplained += $u['netdebit'];
	}
	foreach ($bs['unknown'] as $u) {
		$unexplained += $u['netdebit'];
	}
	// Double entry: sum of all net debits is zero, so the difference equals minus the
	// net debit of what was left out (unclassified, unknown, excluded).
	$bs['explained_by'] = -($unexplained + $bs['excluded']);
	return $bs;
}

/**
 * Build the Income Statement over a period.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param string $pcgversion
 * @param int    $from
 * @param int    $to
 * @return array
 */
function anychartlab_build_income_statement($db, $entity, $pcgversion, $from, $to)
{
	$accounts = anychartlab_load_accounts($db, $entity, $pcgversion);
	$balances = anychartlab_balances($db, $entity, $from, $to);
	return anychartlab_is_from_balances($accounts, $balances);
}

/**
 * Income Statement from net debits per account (used by the report, and by tests with sample figures).
 *
 * @param array $accounts From anychartlab_load_accounts()
 * @param array $balances account_number => debit - credit
 * @return array
 */
function anychartlab_is_from_balances($accounts, $balances)
{
	$is = array(
		'accounts' => $accounts,
		'sections' => array('INCOME' => array(), 'EXPENSE' => array()),
		'totals' => array('INCOME' => 0.0, 'EXPENSE' => 0.0),
		'unclassified' => array(), 'unknown' => array(),
	);
	foreach ($balances as $number => $netdebit) {
		if (abs($netdebit) < 0.005) {
			continue;
		}
		$acc = $accounts['byNumber'][(string) $number] ?? null;
		if ($acc === null) {
			$is['unknown'][] = array('account_number' => (string) $number, 'netdebit' => $netdebit);
			continue;
		}
		$eff = anychartlab_effective_nature($acc, $netdebit);
		if ($eff === null) {
			$is['unclassified'][] = array('acc' => $acc, 'netdebit' => $netdebit);
			continue;
		}
		if (!anychartlab_is_pl($eff)) {
			continue;
		}
		$amount = ($eff === 'INCOME' ? -$netdebit : $netdebit);
		$note = ($acc->contra ? 'contra: deduction' : ($eff !== $acc->nature ? 'shown as '.strtolower($eff).' because its balance is on the other side' : ''));
		$is['sections'][$eff][] = array('acc' => $acc, 'amount' => $amount, 'note' => $note, 'netdebit' => $netdebit, 'section' => $eff);
		$is['totals'][$eff] += $amount;
	}
	foreach ($is['sections'] as &$lines) {
		usort($lines, function ($a, $b) {
			return strcmp($a['acc']->account_number, $b['acc']->account_number);
		});
	}
	unset($lines);
	$is['result'] = $is['totals']['INCOME'] - $is['totals']['EXPENSE'];
	return $is;
}

/**
 * Link to the core ledger for one account.
 *
 * @param string $accountNumber
 * @return string
 */
function anychartlab_ledger_url($accountNumber)
{
	return DOL_URL_ROOT.'/accountancy/bookkeeping/listbyaccount.php?search_accountancy_code_start='.urlencode($accountNumber).'&search_accountancy_code_end='.urlencode($accountNumber);
}

/**
 * Stream rows as a semicolon CSV download and exit.
 *
 * @param string   $filename
 * @param string[] $header
 * @param array    $rows
 * @return void
 */
function anychartlab_send_csv($filename, $header, $rows)
{
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="'.dol_sanitizeFileName($filename).'.csv"');
	$out = fopen('php://output', 'w');
	fwrite($out, "\xEF\xBB\xBF");
	fputcsv($out, $header, ';', '"', '\\');
	foreach ($rows as $row) {
		fputcsv($out, (isset($row['cells']) ? $row['cells'] : $row), ';', '"', '\\');
	}
	fclose($out);
	exit;
}

/**
 * Common header: module title, chart in use, links between the pages.
 *
 * @param string $current file name of the current page
 * @param string $pcgversion
 * @return void
 */
function anychartlab_print_nav($current, $pcgversion)
{
	$pages = array(
		'balancesheet.php' => 'Balance Sheet',
		'incomestatement.php' => 'Income Statement',
		'aged.php?type=ar' => 'Receivables',
		'aged.php?type=ap' => 'Payables',
		'check.php' => 'Classification check',
		'admin/setup.php' => 'Account natures',
		'admin/layouts.php' => 'Layouts',
		'admin/display.php' => 'Display',
	);
	$base = dol_buildpath('/anychartlab/', 1);
	print '<div class="opacitymedium" style="margin-bottom:8px">Any-Chart Reports Lab <b>(prototype, Dolibarr issue <a href="https://github.com/Dolibarr/dolibarr/issues/31760" target="_blank" rel="noopener">#31760</a>)</b> · chart: <b>'.dol_escape_htmltag($pcgversion ?: 'none selected').'</b> · ';
	$links = array();
	foreach ($pages as $file => $label) {
		$links[] = ($file === $current ? '<b>'.$label.'</b>' : '<a href="'.$base.$file.'">'.$label.'</a>');
	}
	print implode(' | ', $links).'</div>';
}

/**
 * Format an amount for display, rounded to the currency precision (avoids
 * float artefacts such as 7.44000000).
 *
 * @param float $amount
 * @return string
 */
function anychartlab_price($amount)
{
	$dec = (anychartlab_cfg('DECIMALS') === '0' ? 0 : 2);
	$amount = round((float) price2num($amount, 'MT'), $dec);
	if (abs($amount) < 0.5 / pow(10, $dec)) {
		$amount = 0.0;	// avoid "-0.00"
	}
	$txt = price(abs($amount), 0, '', 1, $dec, $dec);
	if ($amount < 0) {
		return (anychartlab_cfg('NEG_FORMAT') === 'paren' ? '('.$txt.')' : '-'.$txt);
	}
	return $txt;
}

/**
 * Top-level ancestor of an account (walks account_parent until a row has no parent).
 *
 * @param array  $accounts Result of anychartlab_load_accounts()
 * @param object $acc
 * @return object
 */
function anychartlab_top_account($accounts, $acc)
{
	$current = $acc;
	$seen = array();
	while (!empty($current->account_parent) && !isset($seen[(int) $current->account_parent])) {
		$seen[(int) $current->account_parent] = true;
		$parent = $accounts['byRowid'][(int) $current->account_parent] ?? null;
		if ($parent === null) {
			break;
		}
		$current = $parent;
	}
	return $current;
}

/**
 * Group statement lines under their top-level parent account.
 *
 * @param array $lines    Lines of one section: each array('acc' => object, 'amount' => float, 'note' => string)
 * @param array $accounts Result of anychartlab_load_accounts()
 * @return array<int,array{top:object,lines:array,subtotal:float}> sorted by parent account number
 */
function anychartlab_group_lines($lines, $accounts)
{
	$groups = array();
	foreach ($lines as $l) {
		$top = anychartlab_top_account($accounts, $l['acc']);
		$key = $top->account_number;
		if (!isset($groups[$key])) {
			$groups[$key] = array('top' => $top, 'lines' => array(), 'subtotal' => 0.0);
		}
		$groups[$key]['lines'][] = $l;
		$groups[$key]['subtotal'] += $l['amount'];
	}
	uksort($groups, function ($a, $b) {
		return strcmp((string) $a, (string) $b);
	});
	return array_values($groups);
}

/**
 * Print the rows of one statement section.
 * Detailed view: a parent with several posting accounts gets a heading, its
 * accounts indented below, then a subtotal. Summary view: one row per parent.
 *
 * @param array  $groups    Result of anychartlab_group_lines()
 * @param string $view      'detailed' or 'summary'
 * @param int    $showDraft 1 to show the design-draft code column
 * @param array|null $entries Entries by account number (detailed view only), null = no entries
 * @param string $section   Statement section code, for the sign of entries
 * @return void
 */
function anychartlab_print_groups($groups, $view, $showDraft, $entries = null, $section = '')
{
	$empty = '<td></td>'.($showDraft ? '<td></td>' : '');
	foreach ($groups as $g) {
		$top = $g['top'];
		$single = (count($g['lines']) === 1 && $g['lines'][0]['acc']->rowid === $top->rowid);
		if ($view === 'summary' || $single) {
			$l = ($single ? $g['lines'][0] : null);
			print '<tr class="oddeven acl-account"><td class="nowraponall"><a href="'.anychartlab_ledger_url($top->account_number).'" target="_blank">'.dol_escape_htmltag($top->account_number).'</a></td>';
			print '<td>'.dol_escape_htmltag($top->label).($view === 'summary' && !$single ? ' <span class="opacitymedium">('.count($g['lines']).' account'.(count($g['lines']) > 1 ? 's' : '').')</span>' : '').'</td>';
			print '<td class="opacitymedium">'.dol_escape_htmltag($l ? $l['note'] : '').'</td>';
			if ($showDraft) {
				print '<td>'.dol_escape_htmltag($l ? anychartlab_draft_code($l['acc']) : '').'</td>';
			}
			print '<td class="right amount">'.anychartlab_price($g['subtotal']).'</td></tr>';
			if ($single && $view !== 'summary' && $entries !== null) {
				anychartlab_print_entries($entries, $top->account_number, ($g['lines'][0]['section'] ?? $section), $showDraft, $top->label);
			}
			continue;
		}
		print '<tr class="oddeven acl-parent"><td class="nowraponall">'.dol_escape_htmltag($top->account_number).'</td><td>'.dol_escape_htmltag($top->label).'</td>'.$empty.'<td></td></tr>';
		foreach ($g['lines'] as $l) {
			$acc = $l['acc'];
			print '<tr class="oddeven acl-child"><td class="nowraponall"><a href="'.anychartlab_ledger_url($acc->account_number).'" target="_blank">'.dol_escape_htmltag($acc->account_number).'</a></td>';
			print '<td>'.dol_escape_htmltag($acc->label).'</td><td class="opacitymedium">'.dol_escape_htmltag($l['note']).'</td>';
			if ($showDraft) {
				print '<td>'.dol_escape_htmltag(anychartlab_draft_code($acc)).'</td>';
			}
			print '<td class="right amount" style="padding-right:2em">'.anychartlab_price($l['amount']).'</td></tr>';
			if ($entries !== null) {
				anychartlab_print_entries($entries, $acc->account_number, ($l['section'] ?? $section), $showDraft, $acc->label);
			}
		}
		print '<tr class="oddeven acl-subtotal"><td></td><td class="right">Subtotal '.dol_escape_htmltag($top->account_number).'</td>'.$empty.'<td class="right amount">'.anychartlab_price($g['subtotal']).'</td></tr>';
	}
}

/**
 * CSV rows for one statement section, following the same view.
 *
 * @param array  $groups Result of anychartlab_group_lines()
 * @param string $view   'detailed' or 'summary'
 * @return array
 */
function anychartlab_groups_csv($groups, $view, $entries = null, $section = '')
{
	$rows = array();
	foreach ($groups as $g) {
		$top = $g['top'];
		$single = (count($g['lines']) === 1 && $g['lines'][0]['acc']->rowid === $top->rowid);
		if ($view === 'summary' || $single) {
			$rows[] = anychartlab_r(array($top->account_number, $top->label, ($single ? (string) $top->nature : ''), ($single ? $g['lines'][0]['note'] : ''), price2num($g['subtotal'], 'MT')), 'account');
			if ($single && $view !== 'summary' && $entries !== null) {
				$rows = array_merge($rows, anychartlab_entries_csv($entries, $top->account_number, ($g['lines'][0]['section'] ?? $section), $top->label));
			}
			continue;
		}
		$rows[] = anychartlab_r(array($top->account_number, $top->label, '', '', ''), 'parent');
		foreach ($g['lines'] as $l) {
			$rows[] = anychartlab_r(array($l['acc']->account_number, $l['acc']->label, (string) $l['acc']->nature, $l['note'], price2num($l['amount'], 'MT')), 'child');
			if ($entries !== null) {
				$rows = array_merge($rows, anychartlab_entries_csv($entries, $l['acc']->account_number, ($l['section'] ?? $section), $l['acc']->label));
			}
		}
		$rows[] = anychartlab_r(array('', 'Subtotal '.$top->account_number, '', '', price2num($g['subtotal'], 'MT')), 'subtotal');
	}
	return $rows;
}

/**
 * Individual ledger entries per account over a period (for "Show entries").
 * Party: subledger label, else the customer/supplier name from its code, else the code.
 *
 * @param DoliDB   $db
 * @param int      $entity
 * @param int|null $from
 * @param int      $to
 * @return array<string,array<int,array{date:int,ref:string,journal:string,party:string,label:string,debit:float,credit:float}>>
 */
function anychartlab_load_entries($db, $entity, $from, $to)
{
	$out = array();
	$sql = "SELECT ab.numero_compte, ab.doc_date, ab.doc_ref, ab.code_journal, ab.label_operation, ab.debit, ab.credit,";
	$sql .= " COALESCE(NULLIF(ab.subledger_label, ''), sc.nom, sf.nom, ab.thirdparty_code, '') as party";
	$sql .= " FROM ".MAIN_DB_PREFIX."accounting_bookkeeping as ab";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as sc ON sc.code_client = ab.thirdparty_code AND ab.thirdparty_code <> ''";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as sf ON sf.code_fournisseur = ab.thirdparty_code AND ab.thirdparty_code <> ''";
	$sql .= " WHERE ab.entity = ".((int) $entity);
	if ($from !== null) {
		$sql .= " AND ab.doc_date >= '".$db->idate($from)."'";
	}
	$sql .= " AND ab.doc_date <= '".$db->idate($to)."'";
	$sql .= " ORDER BY ab.numero_compte, ab.doc_date, ab.piece_num, ab.rowid";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out[(string) $obj->numero_compte][] = array(
				'date' => $db->jdate($obj->doc_date),
				'ref' => (string) $obj->doc_ref,
				'journal' => (string) $obj->code_journal,
				'party' => (string) $obj->party,
				'label' => (string) $obj->label_operation,
				'debit' => (float) $obj->debit,
				'credit' => (float) $obj->credit,
			);
		}
	} else {
		dol_print_error($db);
	}
	return $out;
}

/**
 * Amount of one entry as shown in a statement section (same sign as the section's lines).
 *
 * @param string $section ASSET, LIABILITY, EQUITY, INCOME or EXPENSE
 * @param array  $e       Entry from anychartlab_load_entries()
 * @return float
 */
function anychartlab_entry_amount($section, $e)
{
	return (in_array($section, array('ASSET', 'EXPENSE')) ? $e['debit'] - $e['credit'] : $e['credit'] - $e['debit']);
}

/**
 * Print the entry rows of one account (detailed view with "Show entries").
 *
 * @param array  $entries   All entries, by account number
 * @param string $number    Account number
 * @param string $section   Statement section code
 * @param int    $showDraft
 * @return void
 */
function anychartlab_print_entries($entries, $number, $section, $showDraft, $accLabel = '')
{
	foreach ($entries[$number] ?? array() as $e) {
		$desc = anychartlab_entry_description($e, $accLabel);
		print '<tr class="oddeven acl-entry"><td class="nowraponall">'.dol_print_date($e['date'], 'day').'</td>';
		print '<td>'.dol_escape_htmltag($e['ref']).($e['journal'] !== '' ? ' <span class="opacitymedium">('.dol_escape_htmltag($e['journal']).')</span>' : '').'</td>';
		print '<td class="tdoverflowmax300" title="'.dol_escape_htmltag($desc).'">'.dol_escape_htmltag($desc).'</td>'.($showDraft ? '<td></td>' : '');
		print '<td class="right amount">'.anychartlab_price(anychartlab_entry_amount($section, $e)).'</td></tr>';
	}
}

/**
 * CSV rows for the entries of one account.
 *
 * @param array  $entries
 * @param string $number
 * @param string $section
 * @return array
 */
function anychartlab_entries_csv($entries, $number, $section, $accLabel = '')
{
	$rows = array();
	foreach ($entries[$number] ?? array() as $e) {
		$rows[] = anychartlab_r(array(dol_print_date($e['date'], '%Y-%m-%d'), $e['ref'].($e['journal'] !== '' ? ' ('.$e['journal'].')' : ''), anychartlab_entry_description($e, $accLabel), '', price2num(anychartlab_entry_amount($section, $e), 'MT')), 'entry');
	}
	return $rows;
}

/**
 * Short description of an entry: the party, then whatever the ledger label adds.
 * Dolibarr's labels read "Party (truncated) - Ref - Account label", which repeats
 * what is already shown, so those parts are dropped.
 *
 * @param array  $e        Entry from anychartlab_load_entries()
 * @param string $accLabel Label of the account the entry is shown under
 * @return string
 */
function anychartlab_entry_description($e, $accLabel)
{
	$keep = array();
	$label = $e['label'];
	if (trim($accLabel) !== '') {
		$label = trim(str_ireplace(trim($accLabel), '', $label), " -");	// the account label may itself contain ' - '
	}
	foreach (explode(' - ', $label) as $part) {
		$part = trim($part);
		if ($part === '' || $part === $e['ref'] || strcasecmp($part, trim($accLabel)) === 0
			|| ($e['party'] !== '' && stripos($e['party'], $part) === 0)) {
			continue;
		}
		$keep[] = $part;
	}
	return trim($e['party'].($e['party'] !== '' && $keep ? ' · ' : '').implode(' - ', $keep));
}

/**
 * A row for exports: cells (account, label, nature, note, amount) and its kind,
 * used by the PDF for styling: section, heading, parent, account, child, entry,
 * subtotal, total, grandtotal.
 *
 * @param array  $cells
 * @param string $kind
 * @return array{cells:array,kind:string}
 */
function anychartlab_r($cells, $kind)
{
	return array('cells' => $cells, 'kind' => $kind);
}

/**
 * Stream a statement as PDF (download, or inline for the preview) and exit.
 * Columns: account, label, note, amount (the nature column of the CSV is left out);
 * the account and note columns can be switched off in Display.
 *
 * @param string $filename
 * @param string $title
 * @param string $subtitle
 * @param array  $rows     Rows from anychartlab_r() (cells: account, label, nature, note, amount)
 * @param bool   $inline   true = show in the browser (preview)
 * @return void
 */
function anychartlab_send_pdf($filename, $title, $subtitle, $rows, $inline)
{
	$showAcc = (anychartlab_cfg('PDF_SHOW_ACCOUNT_COL') !== '0');
	$showNote = (anychartlab_cfg('PDF_SHOW_NOTE_COL') !== '0');
	$f = array($showAcc ? 0.15 : 0.0, 0.45, $showNote ? 0.22 : 0.0, 0.18);
	$f[1] += (1 - array_sum($f));
	$columns = array(
		array('title' => 'Account', 'width' => $f[0], 'align' => 'L', 'indent' => 1),
		array('title' => 'Label', 'width' => $f[1], 'align' => 'L', 'indent' => 1),
		array('title' => 'Note', 'width' => $f[2], 'align' => 'L'),
		array('title' => 'Amount', 'width' => $f[3], 'align' => 'R'),
	);
	$out = array();
	foreach ($rows as $row) {
		$kind = $row['kind'] ?? 'account';
		$c = $row['cells'];
		$note = ($kind === 'entry' ? (string) $c[2] : (string) $c[3]);	// entries carry their description in the third column
		$cells = array((string) $c[0], (string) $c[1], $note, ((string) $c[4] === '' ? '' : anychartlab_price($c[4])));
		if (!$showAcc && $kind !== 'section') {
			// account number moves in front of the label when the account column is off
			$cells[1] = trim(($kind === 'entry' ? $cells[0].'  ' : ($cells[0] !== '' ? $cells[0].' ' : '')).$cells[1]);
		}
		$out[] = array('kind' => $kind, 'cells' => $cells);
	}
	anychartlab_pdf_table($filename, $title, $subtitle, $columns, $out, $inline);
}

/**
 * Stream any report table as PDF and exit. Page, fonts, header, footer and line
 * styles come from the Display settings.
 *
 * @param string $filename
 * @param string $title
 * @param string $subtitle
 * @param array  $columns  Each array('title' => string, 'width' => fraction of the width, 'align' => L|R, 'indent' => 1 if indented lines shift this column); width 0 = hidden
 * @param array  $rows     Each array('kind' => line kind, 'cells' => display strings, one per column)
 * @param bool   $inline   true = show in the browser (preview)
 * @param string $orientation '' = Display setting, or force P / L (wide reports)
 * @return void
 */
function anychartlab_pdf_table($filename, $title, $subtitle, $columns, $rows, $inline, $orientation = '')
{
	global $conf, $langs, $mysoc;
	require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

	$paper = anychartlab_cfg('PDF_PAPER');
	if ($orientation !== 'L' && $orientation !== 'P') {
		$orientation = (anychartlab_cfg('PDF_ORIENTATION') === 'L' ? 'L' : 'P');
	}
	$margin = max(5, min(40, (float) anychartlab_cfg('PDF_MARGIN')));
	$base = max(6, min(14, (float) anychartlab_cfg('PDF_FONTSIZE')));
	$titleSize = max(8, min(28, (float) anychartlab_cfg('PDF_TITLESIZE')));
	$font = (anychartlab_cfg('PDF_FONT') !== '' ? anychartlab_cfg('PDF_FONT') : pdf_getPDFFont($langs));

	$format = ($paper !== '' ? $paper : getDolGlobalString('MAIN_PDF_FORMAT', 'A4'));
	$pdf = pdf_getInstance($format, 'mm', $orientation);
	$pdf->setPrintHeader(false);
	$pdf->setPrintFooter(false);
	$pdf->SetFont($font);
	$pdf->SetMargins($margin, $margin, $margin);
	$pdf->SetAutoPageBreak(false, $margin + 5);
	$pdf->SetTitle($title);
	$pdf->SetCreator('Dolibarr '.DOL_VERSION.' - Any-Chart Reports Lab');

	$left = $margin;
	$printWidth = $pdf->getPageWidth() - 2 * $margin;
	$sumf = 0.0;
	foreach ($columns as $col) {
		$sumf += (float) $col['width'];
	}
	$w = array();
	$aligns = array();
	$colTitles = array();
	foreach ($columns as $i => $col) {
		$w[$i] = ($sumf > 0 ? (float) $col['width'] / $sumf : 0) * $printWidth;
		$aligns[$i] = ($col['align'] === 'R' ? 'R' : 'L');
		$colTitles[$i] = $col['title'];
	}
	// right-aligned (amount) columns at the end: where "rule over amount" is drawn
	$firstAmount = count($w);
	for ($i = count($w) - 1; $i >= 0 && $aligns[$i] === 'R'; $i--) {
		$firstAmount = $i;
	}
	$xAmount = $left;
	for ($i = 0; $i < $firstAmount; $i++) {
		$xAmount += $w[$i];
	}
	$bottom = $pdf->getPageHeight() - $margin - 5;

	$logo = '';
	if (anychartlab_cfg('PDF_SHOW_LOGO') === '1' && !empty($mysoc->logo)) {
		$candidate = $conf->mycompany->dir_output.'/logos/'.$mysoc->logo;
		if (is_readable($candidate)) {
			$logo = $candidate;
		}
	}
	$heading = (anychartlab_cfg('PDF_SHOW_COMPANY') === '1' ? (trim(anychartlab_cfg('PDF_COMPANY_TEXT')) !== '' ? trim(anychartlab_cfg('PDF_COMPANY_TEXT')) : (string) ($mysoc->name ?? '')) : '');
	$showPeriod = (anychartlab_cfg('PDF_SHOW_PERIOD') !== '0');

	$header = function () use ($pdf, $left, $printWidth, $title, $subtitle, $w, $aligns, $colTitles, $base, $titleSize, $logo, $heading, $showPeriod) {
		$pdf->AddPage();
		$y = $pdf->getMargins()['top'];
		if ($logo !== '') {
			$pdf->Image($logo, $left + $printWidth - 40, $y, 0, 14, '', '', '', true, 300, '', false, false, 0, 'RT');
		}
		if ($heading !== '') {
			$pdf->SetFont('', 'B', $base + 4);
			$pdf->SetXY($left, $y);
			$pdf->Cell($printWidth - ($logo !== '' ? 45 : 0), 6, $heading, 0, 1, 'L');
			$y += 7;
		}
		$pdf->SetFont('', 'B', $titleSize);
		$pdf->SetXY($left, $y);
		$pdf->Cell($printWidth - ($logo !== '' ? 45 : 0), $titleSize * 0.45, $title, 0, 1, 'L');
		$y += $titleSize * 0.5;
		if ($showPeriod && $subtitle !== '') {
			$pdf->SetFont('', '', $base + 1);
			$pdf->SetXY($left, $y);
			$pdf->Cell(0, 5, $subtitle, 0, 1, 'L');
			$y += 6;
		}
		$y = max($y + 3, $logo !== '' ? $pdf->getMargins()['top'] + 17 : 0);
		$pdf->SetFont('', 'B', $base);
		$pdf->SetFillColor(235, 235, 235);
		$x = $left;
		foreach ($colTitles as $i => $col) {
			if ($w[$i] > 0) {
				$pdf->SetXY($x, $y);
				$pdf->Cell($w[$i], 6, anychartlab_pdf_fit($pdf, $col, $w[$i]), 0, 0, $aligns[$i], true);
			}
			$x += $w[$i];
		}
		return $y + 8;
	};

	$y = $header();
	foreach ($rows as $row) {
		$kind = $row['kind'] ?? 'account';
		$st = anychartlab_style($kind);
		$cells = array();
		foreach (array_keys($w) as $i) {
			$cells[$i] = (string) ($row['cells'][$i] ?? '');
		}
		if ($st['upper']) {
			$cells[0] = mb_strtoupper($cells[0]);
			$cells[1] = mb_strtoupper($cells[1] ?? '');
		}
		$size = max(5, $base + (float) $st['size']);
		$h = $size * 0.6;
		if (in_array($kind, array('section', 'heading'))) {
			$y += 1.5;
		}
		$keep = (in_array($kind, array('section', 'heading', 'parent')) ? 3 * $h : $h);	// keep headings with the lines below them
		if ($y + $keep > $bottom) {
			$y = $header();
		}
		$pdf->SetFont(($st['font'] !== '' ? $st['font'] : $font), ($st['bold'] ? 'B' : '').($st['italic'] ? 'I' : ''), $size);
		$rgb = sscanf($st['colour'], '#%02x%02x%02x');
		$pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
		$right = $left + array_sum($w);
		if ($st['line'] === 'amount') {
			$pdf->Line($xAmount, $y, $right, $y);
		} elseif ($st['line'] === 'full') {
			$pdf->Line($left + $w[0], $y, $right, $y);
		} elseif ($st['line'] === 'double') {
			$pdf->Line($left, $y - 0.6, $right, $y - 0.6);
			$pdf->Line($left, $y, $right, $y);
		}
		if ($st['shade']) {
			$pdf->SetFillColor(240, 240, 240);
			$pdf->SetXY($left, $y);
			$pdf->Cell(array_sum($w), $h, '', 0, 0, 'L', true);
		}
		$indent = (float) $st['indent'];
		if ($kind === 'section') {
			// a heading: its text runs across all text columns, amounts stay in their columns
			$labelWidth = $xAmount - $left - $indent;
			$pdf->SetXY($left + $indent, $y);
			$pdf->Cell($labelWidth, $h, anychartlab_pdf_fit($pdf, trim($cells[0].' '.($cells[1] ?? '')), $labelWidth), 0, 0, 'L');
			$x = $xAmount;
			for ($i = $firstAmount; $i < count($w); $i++) {
				$pdf->SetXY($x, $y);
				$pdf->Cell($w[$i], $h, $cells[$i], 0, 0, 'R');
				$x += $w[$i];
			}
			$y += $h;
			continue;
		}
		$x = $left;
		foreach ($cells as $i => $text) {
			$width = $w[$i];
			if ($width <= 0) {
				continue;
			}
			$shift = (!empty($columns[$i]['indent']) ? $indent : 0);
			$pdf->SetXY($x + $shift, $y);
			$width -= $shift;
			if ($aligns[$i] === 'R') {
				$width -= $indent / 2;
			}
			$pdf->Cell(max(1, $width), $h, anychartlab_pdf_fit($pdf, $text, max(1, $width)), 0, 0, $aligns[$i]);
			$x += $w[$i];
		}
		$y += $h;
	}
	$pdf->SetTextColor(0);
	$footer = trim(anychartlab_cfg('PDF_FOOTER_TEXT'));
	$showDate = (anychartlab_cfg('PDF_SHOW_PRINTDATE') !== '0');
	$showPage = (anychartlab_cfg('PDF_SHOW_PAGENUM') !== '0');
	$n = $pdf->getNumPages();
	for ($p = 1; $p <= $n; $p++) {
		$pdf->setPage($p);
		$parts = array();
		if ($footer !== '') {
			$parts[] = $footer;
		}
		if ($showDate) {
			$parts[] = 'printed '.dol_print_date(dol_now(), 'dayhour');
		}
		if ($showPage) {
			$parts[] = 'page '.$p.' / '.$n;
		}
		if ($parts) {
			$pdf->SetFont('', '', max(6, $base - 1.5));
			$pdf->SetXY($left, $pdf->getPageHeight() - $margin);
			$pdf->Cell($printWidth, 5, implode(' - ', $parts), 0, 0, 'C');
		}
	}
	$pdf->Output(dol_sanitizeFileName($filename).'.pdf', $inline ? 'I' : 'D');
	exit;
}

/**
 * Shorten text with an ellipsis so it fits a PDF cell.
 *
 * @param TCPDF  $pdf
 * @param string $text
 * @param float  $widthMm
 * @return string
 */
function anychartlab_pdf_fit($pdf, $text, $widthMm)
{
	$available = $widthMm - 1.5;
	if ($pdf->GetStringWidth($text) <= $available) {
		return $text;
	}
	while ($text !== '' && $pdf->GetStringWidth($text.'…') > $available) {
		$text = mb_substr($text, 0, -1);
	}
	return $text.'…';
}

/**
 * Display settings of the module, with their defaults.
 * Stored as module constants ANYCHARTLAB_<KEY> (Accounting > Any-Chart Reports Lab > Display).
 *
 * @return array<string,string> key => default
 */
function anychartlab_display_defaults()
{
	return array(
		'PDF_PAPER' => '',				// '' = Dolibarr default (MAIN_PDF_FORMAT), A4, LETTER, LEGAL
		'PDF_ORIENTATION' => 'P',
		'PDF_MARGIN' => '15',
		'PDF_FONT' => '',				// '' = Dolibarr default PDF font
		'PDF_FONTSIZE' => '9',
		'PDF_TITLESIZE' => '15',
		'PDF_SHOW_COMPANY' => '1',
		'PDF_COMPANY_TEXT' => '',		// '' = company name from Setup > Company
		'PDF_SHOW_LOGO' => '0',
		'PDF_SHOW_PERIOD' => '1',
		'PDF_FOOTER_TEXT' => 'Any-Chart Reports Lab (prototype)',
		'PDF_SHOW_PRINTDATE' => '1',
		'PDF_SHOW_PAGENUM' => '1',
		'PDF_SHOW_ACCOUNT_COL' => '1',
		'PDF_SHOW_NOTE_COL' => '1',
		'NEG_FORMAT' => 'minus',		// minus: -1,234.56 / paren: (1,234.56)
		'DECIMALS' => '2',				// 2 or 0 (whole currency units)
		'DEFAULT_VIEW' => 'detailed',
		'DEFAULT_HIDE_EMPTY' => '0',
		'DEFAULT_SHOW_ENTRIES' => '0',
		'AGED_ORDER' => 'oldest',		// ageing columns: oldest first (90+ ... not due) or newest first
	);
}

/**
 * Value of one display setting.
 *
 * @param string $key Key of anychartlab_display_defaults()
 * @return string
 */
function anychartlab_cfg($key)
{
	$defaults = anychartlab_display_defaults();
	$name = 'ANYCHARTLAB_'.$key;
	if (getDolGlobalString($name) === '' && !isset($GLOBALS['conf']->global->$name)) {
		return (string) ($defaults[$key] ?? '');
	}
	return getDolGlobalString($name);
}

/**
 * Kinds of report line that can be styled (Display > Line styles).
 *
 * @return array<string,string> kind => label
 */
function anychartlab_style_kinds()
{
	return array(
		'section' => 'Heading (layout heading / statement section)',
		'heading' => 'Group of accounts title',
		'parent' => 'Parent account',
		'account' => 'Account line',
		'child' => 'Sub-account',
		'entry' => 'Ledger entry',
		'subtotal' => 'Parent subtotal',
		'total' => 'Group total',
		'grandtotal' => 'Formula / statement total',
	);
}

/**
 * Default style of each kind of line (reproduces the original look).
 * size = points added to the base font size; indent in mm; line = none, amount, full, double.
 *
 * @return array<string,array<string,mixed>>
 */
function anychartlab_style_defaults()
{
	$d = array('font' => '', 'size' => 0, 'bold' => 0, 'italic' => 0, 'upper' => 0, 'indent' => 0, 'line' => 'none', 'shade' => 0, 'colour' => '#000000');
	return array(
		'section' => array_merge($d, array('size' => 1, 'bold' => 1, 'shade' => 1)),
		'heading' => array_merge($d, array('bold' => 1)),
		'parent' => array_merge($d, array('bold' => 1)),
		'account' => $d,
		'child' => array_merge($d, array('indent' => 4)),
		'entry' => array_merge($d, array('size' => -1.5, 'indent' => 8, 'colour' => '#6e6e6e')),
		'subtotal' => array_merge($d, array('bold' => 1, 'line' => 'amount')),
		'total' => array_merge($d, array('bold' => 1, 'line' => 'amount')),
		'grandtotal' => array_merge($d, array('bold' => 1, 'line' => 'full')),
	);
}

/**
 * Style of one kind of line: defaults merged with the saved setting ANYCHARTLAB_STYLE_<KIND> (JSON).
 *
 * @param string $kind
 * @return array<string,mixed>
 */
function anychartlab_style($kind)
{
	static $cache = array();
	if (isset($cache[$kind])) {
		return $cache[$kind];
	}
	$defaults = anychartlab_style_defaults();
	$style = $defaults[$kind] ?? $defaults['account'];
	$saved = json_decode(getDolGlobalString('ANYCHARTLAB_STYLE_'.strtoupper($kind)), true);
	if (is_array($saved)) {
		foreach ($style as $k => $v) {
			if (array_key_exists($k, $saved)) {
				$style[$k] = $saved[$k];
			}
		}
	}
	$style['size'] = max(-6, min(10, (float) $style['size']));
	$style['indent'] = max(0, min(30, (float) $style['indent']));
	if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $style['colour'])) {
		$style['colour'] = '#000000';
	}
	$cache[$kind] = $style;
	return $style;
}

/**
 * CSS font stack for a PDF font name.
 *
 * @param string $font
 * @return string
 */
function anychartlab_css_font($font)
{
	$map = array('helvetica' => 'Helvetica, Arial, sans-serif', 'times' => '"Times New Roman", Times, serif', 'courier' => '"Courier New", Courier, monospace', 'dejavusans' => '"DejaVu Sans", Verdana, sans-serif', 'freesans' => 'FreeSans, Arial, sans-serif');
	return $map[$font] ?? '';
}

/**
 * Print the CSS that applies the line styles to the report tables on screen.
 *
 * @return void
 */
function anychartlab_print_style_css()
{
	$css = '';
	foreach (array_keys(anychartlab_style_kinds()) as $kind) {
		$s = anychartlab_style($kind);
		$sel = 'table.noborder tr.acl-'.$kind;
		$rules = 'font-weight:'.($s['bold'] ? 'bold' : 'normal').' !important;';
		$rules .= 'font-style:'.($s['italic'] ? 'italic' : 'normal').' !important;';
		$rules .= ($s['upper'] ? 'text-transform:uppercase;' : '');
		$rules .= ((float) $s['size'] != 0 ? 'font-size:calc(1em + '.((float) $s['size']).'pt) !important;' : '');
		$rules .= 'color:'.$s['colour'].' !important;';
		$rules .= (anychartlab_css_font($s['font']) !== '' ? 'font-family:'.anychartlab_css_font($s['font']).' !important;' : '');
		$rules .= ($s['shade'] ? 'background-color:#f0f0f0 !important;' : '');
		$css .= $sel.' td, '.$sel.' th {'.$rules.'}'."\n";
		$css .= $sel.' td a {color:inherit !important;}'."\n";
		if ((float) $s['indent'] > 0) {
			$css .= $sel.' td:nth-child(1), '.$sel.' td:nth-child(2) {padding-left:calc(6px + '.((float) $s['indent']).'mm) !important;}'."\n";
			$css .= $sel.' td.amount {padding-right:calc(6px + '.((float) $s['indent'] / 2).'mm) !important;}'."\n";
		}
		if ($s['line'] === 'amount') {
			$css .= $sel.' td.amount {border-top:1px solid #888 !important;}'."\n";
		} elseif ($s['line'] === 'full') {
			$css .= $sel.' td {border-top:1px solid #888 !important;}'."\n";
		} elseif ($s['line'] === 'double') {
			$css .= $sel.' td {border-top:3px double #555 !important;}'."\n";
		}
	}
	print '<style>'."\n".$css.'</style>'."\n";
}

/**
 * The module's catalogue (seed/catalog.csv): rule sets and layout files, with the
 * country and charts each one suits.
 *
 * @return array<int,array{kind:string,file:string,countries:string[],charts:string[],label:string}>
 */
function anychartlab_catalog()
{
	$out = array();
	$fh = @fopen(anychartlab_seed_dir().'/catalog.csv', 'r');
	if (!$fh) {
		return $out;
	}
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
		foreach ($header as $i => $n) {
			$r[$n] = trim((string) ($cols[$i] ?? ''));
		}
		$out[] = array(
			'kind' => $r['kind'] ?? '',
			'file' => basename($r['file'] ?? ''),
			'countries' => array_filter(array_map('trim', explode('|', strtoupper($r['country'] ?? '')))),
			'charts' => array_filter(array_map('trim', explode('|', $r['charts'] ?? ''))),
			'label' => $r['label'] ?? '',
		);
	}
	fclose($fh);
	return $out;
}

/**
 * Country code of the company (Setup > Company).
 *
 * @return string e.g. FR, AU
 */
function anychartlab_country_code()
{
	global $mysoc;
	return strtoupper((string) ($mysoc->country_code ?? ''));
}

/**
 * Catalogue entries of a kind, best match first: made for the active chart, then for
 * the company country, then the others. Each entry gets 'match' = chart, country or ''.
 *
 * @param string $kind       nature or layout
 * @param string $pcgversion
 * @return array
 */
function anychartlab_catalog_ranked($kind, $pcgversion)
{
	$country = anychartlab_country_code();
	$ranked = array('chart' => array(), 'country' => array(), '' => array());
	foreach (anychartlab_catalog() as $e) {
		if ($e['kind'] !== $kind) {
			continue;
		}
		$match = (in_array($pcgversion, $e['charts'], true) ? 'chart' : (in_array($country, $e['countries'], true) ? 'country' : ''));
		$e['match'] = $match;
		$ranked[$match][] = $e;
	}
	return array_merge($ranked['chart'], $ranked['country'], $ranked['']);
}

/**
 * Suggested rule set for the active chart: catalogue entry for the chart, else for the
 * country, else a legacy seed/<chart>.csv file, else none (generic rules only).
 *
 * @param string $pcgversion
 * @return array{file:string,label:string,match:string}|null
 */
function anychartlab_suggest_ruleset($pcgversion)
{
	foreach (anychartlab_catalog_ranked('nature', $pcgversion) as $e) {
		if ($e['match'] !== '') {
			return array('file' => $e['file'], 'label' => $e['label'], 'match' => $e['match']);
		}
	}
	$legacy = dol_sanitizeFileName($pcgversion).'.csv';
	if ($pcgversion !== '' && is_readable(anychartlab_seed_dir().'/'.$legacy)) {
		return array('file' => $legacy, 'label' => $legacy, 'match' => 'chart');
	}
	return null;
}

/**
 * Suggested layout files (chart match first, then country match).
 *
 * @param string $pcgversion
 * @return array
 */
function anychartlab_suggest_layouts($pcgversion)
{
	$out = array();
	foreach (anychartlab_catalog_ranked('layout', $pcgversion) as $e) {
		if ($e['match'] !== '') {
			$out[] = $e;
		}
	}
	return $out;
}
