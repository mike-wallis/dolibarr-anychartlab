<?php
/**
 * Any-Chart Reports Lab — Cash Flow statement (indirect method).
 *
 * Cash = the accounting accounts of Dolibarr's bank and cash accounts (Banks / Cash),
 * plus any account whose cash flow class is set to "cash". The cash movement of the
 * period is explained by the profit (income and expense accounts) and by the movement
 * of every other balance sheet account, each in its class: operating (working capital,
 * non-cash items such as depreciation), investing (fixed assets) or financing (loans,
 * equity). Because every entry balances, opening cash + these flows = closing cash for
 * any chart, as long as every account is in the statement.
 *
 * Opening-balance entries dated inside the period (journals of type "opening", and the
 * bank accounts' initial balances) are counted as opening balances, not as cash flows.
 * The window is the one of the Balance Sheet (after the last closed fiscal year).
 */

/**
 * Cash flow classes.
 *
 * @return array<string,string>
 */
function anychartlab_cf_classes()
{
	return array('CASH' => 'Cash', 'OPERATING' => 'Operating', 'INVESTING' => 'Investing', 'FINANCING' => 'Financing');
}

/**
 * Accounting accounts of Dolibarr's bank and cash accounts.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @return array<string,string> account number => bank account label
 */
function anychartlab_cf_bank_accounts($db, $entity)
{
	$out = array();
	$sql = "SELECT account_number, label FROM ".MAIN_DB_PREFIX."bank_account WHERE entity = ".((int) $entity)." AND account_number IS NOT NULL AND account_number <> ''";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$out[(string) $obj->account_number] = (string) $obj->label;
	}
	return $out;
}

/**
 * Cash flow class of an account: the one set on the Account natures page, else a
 * suggestion from Dolibarr's bank accounts, the account's nature, the chart (French
 * PCG classes) and words in its label or its parents' labels.
 *
 * @param object $acc
 * @param array  $accounts From anychartlab_load_accounts()
 * @param array  $banks    From anychartlab_cf_bank_accounts()
 * @param string $pcgversion
 * @return array{0:string,1:string} class (CASH, OPERATING, INVESTING, FINANCING or PL for income/expense), 'set' or 'auto'
 */
function anychartlab_cf_class($acc, $accounts, $banks, $pcgversion)
{
	if (!empty($acc->cf_class) && isset(anychartlab_cf_classes()[$acc->cf_class])) {
		return array($acc->cf_class, 'set');
	}
	return array(anychartlab_cf_suggest($acc, $accounts, $banks, $pcgversion), 'auto');
}

/**
 * Suggested cash flow class (see anychartlab_cf_class()).
 *
 * @return string
 */
function anychartlab_cf_suggest($acc, $accounts, $banks, $pcgversion)
{
	$number = (string) $acc->account_number;
	if (isset($banks[$number])) {
		return 'CASH';
	}
	if (anychartlab_is_pl((string) $acc->nature)) {
		return 'PL';
	}
	// French PCG: by class of account
	if (stripos($pcgversion, 'PCG') === 0) {
		foreach (array('512' => 'CASH', '514' => 'CASH', '517' => 'CASH', '53' => 'CASH', '28' => 'OPERATING', '29' => 'OPERATING', '2' => 'INVESTING',
			'10' => 'FINANCING', '11' => 'FINANCING', '12' => 'FINANCING', '13' => 'FINANCING', '16' => 'FINANCING', '17' => 'FINANCING', '455' => 'FINANCING') as $prefix => $class) {
			if (strpos($number, (string) $prefix) === 0) {
				return $class;
			}
		}
	}
	// Labels of the account and its parents
	$labels = array();
	$current = $acc;
	$seen = array();
	while ($current && !isset($seen[(int) $current->rowid])) {
		$seen[(int) $current->rowid] = true;
		$labels[] = (string) $current->label;
		$current = (!empty($current->account_parent) ? ($accounts['byRowid'][(int) $current->account_parent] ?? null) : null);
	}
	$text = ' '.implode(' | ', $labels).' ';
	if (preg_match('/deprecia|amortis|amortiz|impairment|d[ée]pr[ée]ciation/i', (string) $acc->label)) {
		return 'OPERATING';	// non-cash: its movement adds back the expense
	}
	if ($acc->nature === 'EQUITY') {
		return 'FINANCING';
	}
	if ($acc->nature === 'LIABILITY' && preg_match('/loan|borrow|mortgage|financ|lease|hire purchase|debenture|overdraft|director|shareholder|emprunt|dette financi/i', $text)) {
		return 'FINANCING';
	}
	if ($acc->nature === 'ASSET' && preg_match('/loan|director|shareholder/i', $text)) {
		return 'FINANCING';
	}
	if ($acc->nature === 'ASSET' && preg_match('/deprecia|amortis|amortiz/i', $text) === 0
		&& preg_match('/plant|equipment|vehicle|motor|\bvan\b|\bcar\b|truck|furniture|fixture|fitting|building|\bland\b|property|computer|leasehold|improvement|intangible|goodwill|patent|trademark|software|investment|non.?current|fixed asset|at cost|write.?off|stamp duty|immobilis/i', $text)) {
		return 'INVESTING';
	}
	if ($acc->nature === 'ASSET' && preg_match('/at cost|write.?off|goodwill|stamp duty|vehicle|\bvan\b|equipment|furniture|fixture/i', $text)) {
		return 'INVESTING';	// a fixed asset group whose parent mentions depreciation
	}
	if ($acc->nature === 'ASSET') {
		foreach ($accounts['byRowid'] as $child) {
			if ((int) $child->account_parent === (int) $acc->rowid && preg_match('/at cost/i', (string) $child->label)) {
				return 'INVESTING';	// e.g. a vehicle account with sub-accounts "At cost" and "Accumulated depreciation"
			}
		}
	}
	return 'OPERATING';
}

/**
 * Net debit (debit - credit) per account over a period, split between opening-balance
 * entries (journal of type 9 "opening", or a bank account's initial balance) and the others.
 *
 * @param DoliDB   $db
 * @param int      $entity
 * @param int|null $from
 * @param int      $to
 * @return array{opening:array<string,float>,moves:array<string,float>,openingEntries:int}
 */
function anychartlab_cf_movements($db, $entity, $from, $to)
{
	$out = array('opening' => array(), 'moves' => array(), 'openingEntries' => 0);
	$sql = "SELECT b.numero_compte, SUM(b.debit) as debit, SUM(b.credit) as credit, COUNT(*) as n,";
	$sql .= " (CASE WHEN j.nature = 9 OR bk.fk_type = 'SOLD' THEN 1 ELSE 0 END) as isopening";
	$sql .= " FROM ".MAIN_DB_PREFIX."accounting_bookkeeping as b";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."accounting_journal as j ON j.code = b.code_journal AND j.entity = b.entity";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank as bk ON (b.doc_type = 'bank' AND bk.rowid = b.fk_doc)";
	$sql .= " WHERE b.entity = ".((int) $entity);
	if ($from !== null) {
		$sql .= " AND b.doc_date >= '".$db->idate($from)."'";
	}
	$sql .= " AND b.doc_date <= '".$db->idate($to)."'";
	$sql .= " GROUP BY b.numero_compte, isopening";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_print_error($db);
		return $out;
	}
	while ($obj = $db->fetch_object($resql)) {
		$key = ((int) $obj->isopening ? 'opening' : 'moves');
		$out[$key][(string) $obj->numero_compte] = ($out[$key][(string) $obj->numero_compte] ?? 0) + (float) $obj->debit - (float) $obj->credit;
		if ((int) $obj->isopening) {
			$out['openingEntries'] += (int) $obj->n;
		}
	}
	return $out;
}

/**
 * Build the Cash Flow statement.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param array  $accounts From anychartlab_load_accounts()
 * @param string $pcgversion
 * @param int    $from
 * @param int    $to
 * @return array
 */
function anychartlab_cf_build($db, $entity, $accounts, $pcgversion, $from, $to)
{
	$window = anychartlab_window_start($db, $entity, $to);
	$start = $window['start'];
	$cut = ($start !== null && $start > $from);
	$moveFrom = ($cut ? $start : $from);
	$before = ($cut ? array() : anychartlab_balances($db, $entity, $start, $moveFrom - 1));
	$m = anychartlab_cf_movements($db, $entity, $moveFrom, $to);
	$banks = anychartlab_cf_bank_accounts($db, $entity);

	$cf = array(
		'window' => $window, 'cut' => $cut, 'moveFrom' => $moveFrom, 'openingEntries' => $m['openingEntries'],
		'cash' => array(), 'openingCash' => 0.0, 'closingCash' => 0.0, 'cashMove' => 0.0,
		'profit' => 0.0, 'plAccounts' => 0,
		'sections' => array('OPERATING' => array(), 'INVESTING' => array(), 'FINANCING' => array(), 'OTHER' => array()),
		'totals' => array('OPERATING' => 0.0, 'INVESTING' => 0.0, 'FINANCING' => 0.0, 'OTHER' => 0.0),
		'clearing' => array(),
	);
	$numbers = array_unique(array_merge(array_keys($before), array_keys($m['opening']), array_keys($m['moves'])));
	foreach ($numbers as $number) {
		$number = (string) $number;
		$opening = ($before[$number] ?? 0.0) + ($m['opening'][$number] ?? 0.0);
		$move = ($m['moves'][$number] ?? 0.0);
		$acc = $accounts['byNumber'][$number] ?? null;
		$class = ($acc ? anychartlab_cf_class($acc, $accounts, $banks, $pcgversion) : array('OTHER', 'auto'));
		if ($class[0] === 'CASH') {
			$cf['cash'][] = array('number' => $number, 'label' => ($acc ? $acc->label : ($banks[$number] ?? '')), 'opening' => $opening, 'closing' => $opening + $move, 'source' => $class[1]);
			$cf['openingCash'] += $opening;
			$cf['closingCash'] += $opening + $move;
			$cf['cashMove'] += $move;
			continue;
		}
		if (abs($move) < 0.005) {
			continue;
		}
		if ($acc && anychartlab_is_pl((string) $acc->nature)) {
			$cf['profit'] += -$move;
			$cf['plAccounts']++;
			continue;
		}
		$section = $class[0];
		$note = '';
		if ($acc === null) {
			$section = 'OTHER';
			$note = 'not in the active chart';
		} elseif ($acc->nature === null) {
			$section = 'OTHER';
			$note = 'unclassified: set its nature';
		} elseif ($acc->nature === 'EXCLUDED') {
			$section = 'OTHER';
			$note = 'nature EXCLUDED';
		} elseif ($acc->nature === 'CLEARING') {
			$note = 'clearing account';
			$cf['clearing'][] = $number;
		} elseif ($class[0] === 'OPERATING' && preg_match('/deprecia|amortis|amortiz|impairment/i', (string) $acc->label)) {
			$note = 'non-cash: added back';
		}
		if (!isset($cf['sections'][$section])) {
			$section = 'OPERATING';
		}
		$line = array('acc' => ($acc ?: (object) array('rowid' => 0, 'account_number' => $number, 'label' => '', 'account_parent' => 0, 'nature' => null)), 'amount' => -$move, 'note' => $note, 'section' => $section, 'auto' => ($class[1] === 'auto'));
		$cf['sections'][$section][] = $line;
		$cf['totals'][$section] += -$move;
	}
	foreach ($cf['sections'] as &$lines) {
		usort($lines, function ($a, $b) {
			return strcmp($a['acc']->account_number, $b['acc']->account_number);
		});
	}
	unset($lines);
	usort($cf['cash'], function ($a, $b) {
		return strcmp($a['number'], $b['number']);
	});
	$cf['totals']['OPERATING'] += $cf['profit'];
	$cf['netFlow'] = $cf['totals']['OPERATING'] + $cf['totals']['INVESTING'] + $cf['totals']['FINANCING'] + $cf['totals']['OTHER'];
	$cf['difference'] = $cf['openingCash'] + $cf['netFlow'] - $cf['closingCash'];
	return $cf;
}

/**
 * Report rows (cells: account, label, nature, note, amount), for screen, CSV and PDF.
 *
 * @param array  $cf       From anychartlab_cf_build()
 * @param array  $accounts From anychartlab_load_accounts()
 * @param string $view     detailed or summary
 * @return array
 */
function anychartlab_cf_rows($cf, $accounts, $view)
{
	$titles = array('OPERATING' => 'Cash flows from operating activities', 'INVESTING' => 'Cash flows from investing activities', 'FINANCING' => 'Cash flows from financing activities', 'OTHER' => 'Accounts not in a class (unclassified, excluded or not in the chart)');
	$rows = array();
	foreach ($titles as $code => $title) {
		if ($code === 'OTHER' && !$cf['sections']['OTHER']) {
			continue;
		}
		$rows[] = anychartlab_r(array($title, '', '', '', ''), 'section');
		if ($code === 'OPERATING') {
			$rows[] = anychartlab_r(array('', 'Profit for the period', '', $cf['plAccounts'].' P&L accounts', price2num($cf['profit'], 'MT')), 'account');
			if ($cf['sections']['OPERATING']) {
				$rows[] = anychartlab_r(array('', 'Adjustments (non-cash items, working capital)', '', '', ''), 'heading');
			}
		}
		$rows = array_merge($rows, anychartlab_groups_csv(anychartlab_group_lines($cf['sections'][$code], $accounts), $view, null, ''));
		$rows[] = anychartlab_r(array('', 'Net cash from '.strtolower(str_replace('Cash flows from ', '', $title)), '', '', price2num($cf['totals'][$code], 'MT')), 'total');
	}
	$rows[] = anychartlab_r(array('', 'Net increase (decrease) in cash', '', '', price2num($cf['netFlow'], 'MT')), 'grandtotal');
	$rows[] = anychartlab_r(array('', 'Cash at the beginning of the period', '', '', price2num($cf['openingCash'], 'MT')), 'account');
	$rows[] = anychartlab_r(array('', 'Cash at the end of the period', '', '', price2num($cf['openingCash'] + $cf['netFlow'], 'MT')), 'grandtotal');
	$rows[] = anychartlab_r(array('', (abs($cf['difference']) < 0.005 ? 'Check: = cash accounts at the end' : 'CHECK FAILED: difference with cash accounts'), '', '', price2num($cf['difference'], 'MT')), 'grandtotal');
	return $rows;
}
