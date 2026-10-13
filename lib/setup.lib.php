<?php
/**
 * Financial Reporting — setup status, used by the Getting started wizard and by the
 * report pages (setup gate).
 *
 * Setup is worked out live, not stored as a flag, so it reopens by itself when, for
 * example, a new account gets movements before it has a nature.
 *   Required: Accounting module on, a chart selected, the company country set, and a
 *             nature and a class on every account that has ledger movements.
 *   Recommended (not required): no clearing account with a balance, no balance on the
 *             opposite side of its nature, an income statement report built with the wizard.
 */

/**
 * Sort balances into the problems the Classification check lists.
 *
 * @param array $accounts From financialreporting_load_accounts()
 * @param array $balances account_number => debit - credit
 * @return array{unclassified:array,clearing:array,opposite:array,unknown:array} rows: array(account|number, netdebit)
 */
function financialreporting_classify_balances($accounts, $balances)
{
	$res = array('unclassified' => array(), 'clearing' => array(), 'opposite' => array(), 'unknown' => array());
	foreach ($balances as $number => $netdebit) {
		if (abs($netdebit) < 0.005) {
			continue;
		}
		$acc = $accounts['byNumber'][(string) $number] ?? null;
		if (!$acc) {
			$res['unknown'][] = array((string) $number, $netdebit);
			continue;
		}
		if ($acc->nature === null) {
			$res['unclassified'][] = array($acc, $netdebit);
			continue;
		}
		if ($acc->nature === 'CLEARING') {
			$res['clearing'][] = array($acc, $netdebit);
			continue;
		}
		$side = financialreporting_normal_side($acc->nature);
		if ($side !== '' && $acc->nature_alt === null && !$acc->contra && (($side === 'D' && $netdebit < 0) || ($side === 'C' && $netdebit > 0))) {
			$res['opposite'][] = array($acc, $netdebit);
		}
	}
	return $res;
}

/**
 * Accounts of the chart that have ledger movements (any date), with their movement count.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @return array<string,int> account_number => number of entries
 */
function financialreporting_accounts_with_movements($db, $entity)
{
	$out = array();
	$resql = $db->query("SELECT numero_compte, COUNT(*) as nb FROM ".MAIN_DB_PREFIX."accounting_bookkeeping WHERE entity = ".((int) $entity)." GROUP BY numero_compte");
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$out[(string) $obj->numero_compte] = (int) $obj->nb;
	}
	return $out;
}

/**
 * Where setup stands.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @return array{accounting:bool,pcgversion:string,country:string,fiscalmonth:int,nbaccounts:int,nbclassified:int,nbmoved:int,unclassified:object[],checks:array|null,nbreports:int,started:bool,complete:bool,accounts:array|null}
 */
function financialreporting_setup_status($db, $entity)
{
	$st = array(
		'accounting' => isModEnabled('accounting'),
		'pcgversion' => financialreporting_active_chart($db),
		'country' => financialreporting_country_code(),
		'fiscalmonth' => getDolGlobalInt('SOCIETE_FISCAL_MONTH_START'),
		'nbaccounts' => 0, 'nbclassified' => 0, 'nbmoved' => 0,
		'unclassified' => array(), 'classmissing' => array(), 'classreview' => array(), 'clearingsuggest' => array(), 'checks' => null, 'nbreports' => 0,
		'started' => false, 'complete' => false, 'accounts' => null,
	);
	if ($st['pcgversion'] !== '') {
		$accounts = financialreporting_load_accounts($db, $entity, $st['pcgversion']);
		$st['accounts'] = $accounts;
		$moved = financialreporting_accounts_with_movements($db, $entity);
		foreach ($accounts['byRowid'] as $acc) {
			$st['nbaccounts']++;
			if ($acc->nature !== null) {
				$st['nbclassified']++;
			} elseif (isset($moved[$acc->account_number])) {
				$st['unclassified'][] = $acc;
			}
			if (isset($moved[$acc->account_number])) {
				$st['nbmoved']++;
				if ($acc->nature !== null && financialreporting_account_class($acc) === null) {
					$st['classmissing'][] = $acc;
				}
			}
			// classes worth a look: set by default where current / non-current matters
			if (in_array($acc->nature, array('ASSET', 'LIABILITY'), true) && financialreporting_account_class($acc) !== null && $acc->class_source === 'default') {
				$st['classreview'][] = $acc;
			}
		}
		foreach (financialreporting_dolibarr_clearing_accounts() as $number => $why) {
			$acc = $accounts['byNumber'][$number] ?? null;
			if ($acc && $acc->nature !== null && $acc->nature !== 'CLEARING') {
				$st['clearingsuggest'][] = array($acc, $why);
			}
		}
		$now = dol_now();
		$window = financialreporting_window_start($db, $entity, $now);
		$st['checks'] = financialreporting_classify_balances($accounts, financialreporting_balances($db, $entity, $window['start'], $now));
		$resql = $db->query("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."financialreporting_report WHERE entity = ".((int) $entity));
		$obj = ($resql ? $db->fetch_object($resql) : null);
		$st['nbreports'] = ($obj ? (int) $obj->nb : 0);
	}
	$st['started'] = ($st['nbclassified'] > 0);
	$st['complete'] = ($st['accounting'] && $st['pcgversion'] !== '' && $st['country'] !== '' && $st['started'] && !$st['unclassified'] && !$st['classmissing']);
	return $st;
}

/**
 * Setup gate for the report pages, printed under the page header. While setup is not
 * complete it shows what is missing with a button to the Getting started wizard (or, for
 * users without the setup permission, who to ask). When no account has a nature yet there
 * is nothing to report, so the page stops there.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param bool   $block  Stop the page when nothing is set up yet
 * @return void
 */
function financialreporting_setup_gate($db, $entity, $block = true)
{
	global $user, $langs;
	$langs->load('financialreporting@financialreporting');
	$st = financialreporting_setup_status($db, $entity);
	if ($st['complete']) {
		return;
	}
	$canSetup = ($user->hasRight('financialreporting', 'setup') || $user->admin);
	$button = ($canSetup ? ' <a class="button small" href="'.dol_buildpath('/financialreporting/admin/start.php', 1).'">'.$langs->trans('FinRepStartContinue').'</a>' : ' '.$langs->trans('FinRepStartAskAdmin'));
	if (!$st['started'] || $st['pcgversion'] === '' || !$st['accounting']) {
		print '<div class="info" style="border-left:4px solid #2a7"><b>'.$langs->trans('FinRepStartNotSetUp').'</b> '.$langs->trans('FinRepStartNotSetUpHelp').$button.'</div>';
		if ($block) {
			llxFooter();
			$db->close();
			exit;
		}
		return;
	}
	$missing = array();
	if ($st['country'] === '') {
		$missing[] = $langs->trans('FinRepStartMissingCountry');
	}
	if ($st['unclassified']) {
		$missing[] = $langs->trans('FinRepStartMissingNatures', count($st['unclassified']));
	}
	if ($st['classmissing']) {
		$missing[] = $langs->trans('FinRepStartMissingClasses', count($st['classmissing']));
	}
	print '<div class="warning"><b>'.$langs->trans('FinRepStartIncomplete').'</b> '.implode(' ', $missing).$button.'</div>';
}
