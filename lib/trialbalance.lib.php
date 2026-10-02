<?php
/**
 * Any-Chart Reports Lab — Trial Balance.
 *
 * Every account with a balance or a movement: opening balance, debits and credits of
 * the period, closing balance. Entries are taken from the same window as the Balance
 * Sheet (after the last closed fiscal year, whose closure re-posted its balances as
 * opening entries), so the closing balances are the Balance Sheet at the end date.
 * Each account shows its nature (the alternate nature when the balance is on the other
 * side), and the list can be grouped by nature with subtotals.
 */

/**
 * Debit and credit totals per account over a period.
 *
 * @param DoliDB   $db
 * @param int      $entity
 * @param int|null $from null = from the first entry
 * @param int      $to
 * @return array<string,array{0:float,1:float}> account number => [debit, credit]
 */
function anychartlab_tb_movements($db, $entity, $from, $to)
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
			$out[(string) $obj->numero_compte] = array((float) $obj->debit, (float) $obj->credit);
		}
	} else {
		dol_print_error($db);
	}
	return $out;
}

/**
 * Build the Trial Balance.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param array  $accounts From anychartlab_load_accounts()
 * @param int    $from
 * @param int    $to
 * @return array{lines:array,totals:array,window:array,movementFrom:int,cut:bool}
 */
function anychartlab_tb_build($db, $entity, $accounts, $from, $to)
{
	$window = anychartlab_window_start($db, $entity, $to);
	$start = $window['start'];
	// A closure inside the period: movements before the window start are already in the
	// opening entries the closure posted, so the period's movements start at the window.
	$cut = ($start !== null && $start > $from);
	$movementFrom = ($cut ? $start : $from);
	$opening = ($cut ? array() : anychartlab_tb_movements($db, $entity, $start, $movementFrom - 1));
	$moves = anychartlab_tb_movements($db, $entity, $movementFrom, $to);

	$lines = array();
	$totals = array('opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'closing_dr' => 0.0, 'closing_cr' => 0.0);
	foreach (array_unique(array_merge(array_keys($opening), array_keys($moves))) as $number) {
		$number = (string) $number;
		$open = (isset($opening[$number]) ? $opening[$number][0] - $opening[$number][1] : 0.0);
		$debit = ($moves[$number][0] ?? 0.0);
		$credit = ($moves[$number][1] ?? 0.0);
		$closing = $open + $debit - $credit;
		if (abs($open) < 0.005 && abs($debit) < 0.005 && abs($credit) < 0.005) {
			continue;
		}
		$acc = $accounts['byNumber'][$number] ?? null;
		if ($acc === null) {
			$group = 'UNKNOWN';
			$note = 'not in the active chart';
		} else {
			$eff = anychartlab_effective_nature($acc, $closing);
			$group = ($eff === null ? 'UNCLASSIFIED' : $eff);
			$note = ($eff === null ? 'unclassified' : ($eff !== $acc->nature ? 'shown as '.strtolower($eff).' (balance on the other side)' : ($acc->contra ? 'contra' : '')));
		}
		$lines[] = array(
			'number' => $number, 'acc' => $acc, 'label' => ($acc ? $acc->label : ''), 'group' => $group, 'note' => $note,
			'opening' => $open, 'debit' => $debit, 'credit' => $credit, 'closing' => $closing,
		);
		$totals['opening'] += $open;
		$totals['debit'] += $debit;
		$totals['credit'] += $credit;
		$totals[$closing >= 0 ? 'closing_dr' : 'closing_cr'] += abs($closing);
	}
	usort($lines, function ($a, $b) {
		return strcmp($a['number'], $b['number']);
	});
	return array('lines' => $lines, 'totals' => $totals, 'window' => $window, 'movementFrom' => $movementFrom, 'cut' => $cut);
}

/**
 * Group labels, in report order.
 *
 * @return array<string,string>
 */
function anychartlab_tb_groups()
{
	return array(
		'ASSET' => 'Assets', 'LIABILITY' => 'Liabilities', 'EQUITY' => 'Equity',
		'INCOME' => 'Income', 'EXPENSE' => 'Expenses', 'CLEARING' => 'Clearing / suspense',
		'EXCLUDED' => 'Excluded', 'UNCLASSIFIED' => 'Unclassified (no nature)', 'UNKNOWN' => 'Not in the active chart',
	);
}

/**
 * Report rows: kind + cells (account, label, nature, note, opening, debit, credit, closing debit, closing credit).
 * Amount cells are floats, or '' when there is nothing to show.
 *
 * @param array  $tb      From anychartlab_tb_build()
 * @param string $groupBy none or nature
 * @return array
 */
function anychartlab_tb_rows($tb, $groupBy)
{
	$line = function ($l) {
		return anychartlab_r(array(
			$l['number'], $l['label'], ($l['group'] === 'UNKNOWN' || $l['group'] === 'UNCLASSIFIED' ? '' : $l['group']), $l['note'],
			$l['opening'], $l['debit'], $l['credit'], ($l['closing'] >= 0 ? $l['closing'] : ''), ($l['closing'] < 0 ? -$l['closing'] : ''),
		), 'account');
	};
	$sum = function ($lines) {
		$t = array(0.0, 0.0, 0.0, 0.0, 0.0);
		foreach ($lines as $l) {
			$t[0] += $l['opening'];
			$t[1] += $l['debit'];
			$t[2] += $l['credit'];
			$t[$l['closing'] >= 0 ? 3 : 4] += abs($l['closing']);
		}
		return $t;
	};
	$rows = array();
	if ($groupBy === 'nature') {
		foreach (anychartlab_tb_groups() as $code => $title) {
			$lines = array_values(array_filter($tb['lines'], function ($l) use ($code) {
				return $l['group'] === $code;
			}));
			if (!$lines) {
				continue;
			}
			$rows[] = anychartlab_r(array($title, '', '', '', '', '', '', '', ''), 'section');
			foreach ($lines as $l) {
				$rows[] = $line($l);
			}
			$t = $sum($lines);
			$net = $t[3] - $t[4];
			$rows[] = anychartlab_r(array('', 'Total '.strtolower($title), '', '', $t[0], $t[1], $t[2], ($net >= 0 ? $net : ''), ($net < 0 ? -$net : '')), 'total');
		}
	} else {
		foreach ($tb['lines'] as $l) {
			$rows[] = $line($l);
		}
	}
	$t = $tb['totals'];
	$rows[] = anychartlab_r(array('', 'Total', '', '', $t['opening'], $t['debit'], $t['credit'], $t['closing_dr'], $t['closing_cr']), 'grandtotal');
	return $rows;
}
