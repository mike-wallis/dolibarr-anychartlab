<?php
/**
 * Any-Chart Reports Lab — several periods side by side.
 *
 * Each period is built exactly like the single-period report (same rows, same
 * layout, same view), then the rows of all periods are merged into one table:
 * a row that only exists in some periods (an account with no movement in a month)
 * keeps its place and is blank where it has no amount. Quarters and years follow
 * the fiscal year (Setup > Company: SOCIETE_FISCAL_MONTH_START).
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * Maximum number of period columns (beyond that the PDF is unreadable).
 */
define('ANYCHARTLAB_MAX_PERIODS', 13);

/**
 * Timestamp of 00:00:00 on the 1st of a month; month may be outside 1..12.
 *
 * @param int $month
 * @param int $year
 * @return int
 */
function anychartlab_month_start($month, $year)
{
	while ($month > 12) {
		$month -= 12;
		$year++;
	}
	while ($month < 1) {
		$month += 12;
		$year--;
	}
	return dol_mktime(0, 0, 0, $month, 1, $year);
}

/**
 * Fiscal year label of the fiscal year starting at a date: "2025-26", or "2026"
 * when the fiscal year is the calendar year.
 *
 * @param int $fyStart
 * @return string
 */
function anychartlab_fy_label($fyStart)
{
	$y = (int) dol_print_date($fyStart, '%Y');
	if (max(1, getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1)) === 1) {
		return (string) $y;
	}
	return $y.'-'.substr((string) ($y + 1), -2);
}

/**
 * Split a date range into periods.
 *
 * @param int    $from
 * @param int    $to
 * @param string $mode months, quarters or years
 * @return array<int,array{from:int,to:int,label:string,title:string}> Empty when the range is empty
 */
function anychartlab_split_periods($from, $to, $mode)
{
	$fm = max(1, getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1));
	$out = array();
	$cursor = $from;
	$guard = 0;
	while ($cursor <= $to && $guard++ < 400) {
		$y = (int) dol_print_date($cursor, '%Y');
		$m = (int) dol_print_date($cursor, '%m');
		if ($mode === 'months') {
			$start = anychartlab_month_start($m, $y);
			$next = anychartlab_month_start($m + 1, $y);
			$label = dol_print_date($start, '%b %Y');
		} else {
			$fyStart = anychartlab_fiscal_year_start($cursor);
			if ($mode === 'quarters') {
				$offset = ($m - $fm + 12) % 12;	// months since the fiscal year started
				$q = intdiv($offset, 3);
				$start = anychartlab_month_start($m - ($offset % 3), $y);
				$next = anychartlab_month_start($m - ($offset % 3) + 3, $y);
				$label = 'Q'.($q + 1).' '.anychartlab_fy_label($fyStart);
			} else {
				$start = $fyStart;
				$next = anychartlab_month_start($fm, (int) dol_print_date($fyStart, '%Y') + 1);
				$label = 'FY '.anychartlab_fy_label($fyStart);
			}
		}
		$pFrom = max($from, $start);
		$pTo = min($to, $next - 1);
		if ($pFrom > $start || $pTo < $next - 1) {
			$label .= ' *';	// part period
		}
		$out[] = array('from' => $pFrom, 'to' => $pTo, 'label' => $label, 'title' => dol_print_date($pFrom, 'day').' to '.dol_print_date($pTo, 'day'));
		$cursor = $next;
	}
	return $out;
}

/**
 * True when a range is whole calendar months.
 *
 * @param int $from
 * @param int $to
 * @return int Number of months, 0 when it is not whole months
 */
function anychartlab_whole_months($from, $to)
{
	if ((int) dol_print_date($from, '%d') !== 1 || dol_print_date($from, '%H%M%S') !== '000000') {
		return 0;
	}
	$next = dol_time_plus_duree($to, 1, 'd');
	if ((int) dol_print_date($next, '%d') !== 1) {
		return 0;
	}
	$months = ((int) dol_print_date($next, '%Y') - (int) dol_print_date($from, '%Y')) * 12 + (int) dol_print_date($next, '%m') - (int) dol_print_date($from, '%m');
	return max(0, $months);
}

/**
 * Short label for a range: "Sep 2026", "Q1 2026-27", "FY 2025-26", or the dates.
 *
 * @param int $from
 * @param int $to
 * @return string
 */
function anychartlab_period_label($from, $to)
{
	$n = anychartlab_whole_months($from, $to);
	if ($n === 1) {
		return dol_print_date($from, '%b %Y');
	}
	$fm = max(1, getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1));
	$m = (int) dol_print_date($from, '%m');
	if ($n === 3 && (($m - $fm + 12) % 3) === 0) {
		$p = anychartlab_split_periods($from, $to, 'quarters');
		if (count($p) === 1) {
			return $p[0]['label'];
		}
	}
	if ($n === 12 && $m === $fm) {
		return 'FY '.anychartlab_fy_label($from);
	}
	return dol_print_date($from, 'day').' - '.dol_print_date($to, 'day');
}

/**
 * The period to compare with.
 *
 * @param int    $from
 * @param int    $to
 * @param string $mode prev (the same length just before: previous month / quarter / year
 *                     for whole months, else the same number of days) or lastyear
 * @return array{from:int,to:int}
 */
function anychartlab_compare_period($from, $to, $mode)
{
	if ($mode === 'lastyear') {
		return array('from' => dol_time_plus_duree($from, -1, 'y'), 'to' => dol_time_plus_duree($to, -1, 'y'));
	}
	$n = anychartlab_whole_months($from, $to);
	if ($n > 0) {
		$start = anychartlab_month_start((int) dol_print_date($from, '%m') - $n, (int) dol_print_date($from, '%Y'));
		return array('from' => $start, 'to' => $from - 1);
	}
	$days = (int) round(($to - $from + 1) / 86400);
	return array('from' => dol_time_plus_duree($from, -$days, 'd'), 'to' => $from - 1);
}

/**
 * Rows of the Income Statement for one period, in the export format of the
 * single-period report (cells: account, label, nature, note, amount).
 *
 * @param DoliDB      $db
 * @param int         $entity
 * @param array       $accounts  From anychartlab_load_accounts()
 * @param int         $from
 * @param int         $to
 * @param object|null $layout
 * @param string      $view      detailed or summary
 * @param int         $hideEmpty
 * @param array|null  $entries   Entries by account (detailed view), null = none
 * @return array{rows:array,is:array,laid:array|null}
 */
function anychartlab_is_rows($db, $entity, $accounts, $from, $to, $layout, $view, $hideEmpty, $entries = null)
{
	$is = anychartlab_is_from_balances($accounts, anychartlab_balances($db, $entity, $from, $to));
	$rows = array();
	$laid = null;
	if ($layout) {
		$alllines = array_merge($is['sections']['INCOME'], $is['sections']['EXPENSE']);
		$laid = anychartlab_apply_layout($layout, $alllines, array('RESULT' => $is['result']));
		$hidden = ($hideEmpty ? anychartlab_layout_hidden($laid) : array());
		$rows = anychartlab_layout_csv($laid, $accounts, $view, $entries, $hidden);
		if ($laid['unmatched']) {
			$rows[] = anychartlab_r(array('Not in layout', '', '', '', ''), 'section');
			$sum = 0.0;
			foreach ($laid['unmatched'] as $l) {
				$rows[] = anychartlab_r(array($l['acc']->account_number, $l['acc']->label, (string) $l['acc']->nature, 'placed as '.$l['section'], price2num($l['amount'], 'MT')), 'account');
				$sum += $l['amount'];
			}
			$rows[] = anychartlab_r(array('', 'Total not in layout', '', '', price2num($sum, 'MT')), 'total');
		}
	} else {
		foreach (array('INCOME' => 'Income', 'EXPENSE' => 'Expenses') as $code => $title) {
			$rows[] = anychartlab_r(array($title, '', '', '', ''), 'section');
			$rows = array_merge($rows, anychartlab_groups_csv(anychartlab_group_lines($is['sections'][$code], $accounts), $view, $entries, $code));
			$rows[] = anychartlab_r(array('', 'Total '.strtolower($title), '', '', price2num($is['totals'][$code], 'MT')), 'total');
		}
		$rows[] = anychartlab_r(array('', 'Net result (income - expenses)', '', '', price2num($is['result'], 'MT')), 'grandtotal');
	}
	return array('rows' => $rows, 'is' => $is, 'laid' => $laid);
}

/**
 * Merge the rows of several periods into one table.
 *
 * Rows are matched on kind + account + label (with an occurrence number for repeats).
 * The first list gives the order; a row missing from it is inserted after the last
 * row it follows in its own period.
 *
 * @param array<int,array> $lists Rows per column (rows from anychartlab_is_rows())
 * @return array<int,array{kind:string,cells:array,amounts:array<int,float|null>}>
 */
function anychartlab_merge_period_rows($lists)
{
	$merged = array();
	$n = count($lists);
	foreach ($lists as $col => $rows) {
		$index = array();
		foreach ($merged as $i => $m) {
			$index[$m['key']] = $i;
		}
		$seen = array();
		$last = -1;
		foreach ($rows as $r) {
			$base = $r['kind'].'|'.$r['cells'][0].'|'.$r['cells'][1];
			$seen[$base] = ($seen[$base] ?? 0) + 1;
			$key = $base.'|'.$seen[$base];
			$amount = ((string) $r['cells'][4] === '' ? null : (float) $r['cells'][4]);
			if (isset($index[$key])) {
				$last = $index[$key];
				$merged[$last]['amounts'][$col] = $amount;
				continue;
			}
			$new = array('key' => $key, 'kind' => $r['kind'], 'cells' => $r['cells'], 'amounts' => array_fill(0, $n, null));
			$new['amounts'][$col] = $amount;
			array_splice($merged, $last + 1, 0, array($new));
			$last++;
			$index = array();
			foreach ($merged as $i => $m) {
				$index[$m['key']] = $i;
			}
		}
	}
	return $merged;
}

/**
 * Change and change % between two amounts (for comparison columns).
 *
 * @param float|null $current
 * @param float|null $previous
 * @return array{0:float|null,1:float|null} change, change in % (null when the previous amount is zero)
 */
function anychartlab_change($current, $previous)
{
	if ($current === null && $previous === null) {
		return array(null, null);
	}
	$change = (float) $current - (float) $previous;
	$pct = (abs((float) $previous) >= 0.005 ? $change / abs((float) $previous) * 100 : null);
	return array($change, $pct);
}

/**
 * Rows of the Balance Sheet at one date, in the export format of the single-date
 * report (cells: account, label, nature, note, amount).
 *
 * @param DoliDB      $db
 * @param int         $entity
 * @param array       $accounts  From anychartlab_load_accounts()
 * @param int         $asof
 * @param object|null $layout
 * @param string      $view      detailed or summary
 * @param int         $hideEmpty
 * @param array|null  $entries   Entries by account (detailed view), null = none
 * @param bool        $columns   true when several dates are side by side: the balance check
 *                               line then has the same label whatever its result
 * @return array{rows:array,bs:array,laid:array|null}
 */
function anychartlab_bs_rows($db, $entity, $accounts, $asof, $layout, $view, $hideEmpty, $entries = null, $columns = false)
{
	$window = anychartlab_window_start($db, $entity, $asof);
	$bs = anychartlab_bs_from_balances($accounts, anychartlab_balances($db, $entity, $window['start'], $asof), $window);
	$rows = array();
	$laid = null;
	if ($layout) {
		$alllines = array_merge($bs['sections']['ASSET'], $bs['sections']['LIABILITY'], $bs['sections']['EQUITY']);
		$laid = anychartlab_apply_layout($layout, $alllines, array('RESULT' => $bs['result']));
		$hidden = ($hideEmpty ? anychartlab_layout_hidden($laid) : array());
		$rows = anychartlab_layout_csv($laid, $accounts, $view, $entries, $hidden);
		if ($laid['unmatched']) {
			$rows[] = anychartlab_r(array('Not in layout', '', '', '', ''), 'section');
			$sum = 0.0;
			foreach ($laid['unmatched'] as $l) {
				$rows[] = anychartlab_r(array($l['acc']->account_number, $l['acc']->label, (string) $l['acc']->nature, 'placed as '.$l['section'], price2num($l['amount'], 'MT')), 'account');
				$sum += $l['amount'];
			}
			$rows[] = anychartlab_r(array('', 'Total not in layout', '', '', price2num($sum, 'MT')), 'total');
		}
	} else {
		foreach (array('ASSET' => 'Assets', 'LIABILITY' => 'Liabilities', 'EQUITY' => 'Equity') as $code => $title) {
			$rows[] = anychartlab_r(array($title, '', '', '', ''), 'section');
			$rows = array_merge($rows, anychartlab_groups_csv(anychartlab_group_lines($bs['sections'][$code], $accounts), $view, $entries, $code));
			if ($code === 'EQUITY') {
				$rows[] = anychartlab_r(array('', 'Result of unclosed periods', '', '', price2num($bs['result'], 'MT')), 'account');
				$rows[] = anychartlab_r(array('', 'Total equity', '', '', price2num($bs['totals']['EQUITY'] + $bs['result'], 'MT')), 'total');
			} else {
				$rows[] = anychartlab_r(array('', 'Total '.strtolower($title), '', '', price2num($bs['totals'][$code], 'MT')), 'total');
			}
		}
		$rows[] = anychartlab_r(array('', 'Total liabilities + equity', '', '', price2num($bs['total_liab_equity'], 'MT')), 'grandtotal');
	}
	if ($columns) {
		$check = 'Check: assets - (liabilities + equity), should be 0';
	} else {
		$check = (abs($bs['difference']) < 0.005 ? 'Check: assets = liabilities + equity' : 'CHECK FAILED: assets - (liabilities + equity)');
	}
	$rows[] = anychartlab_r(array('', $check, '', '', price2num($bs['difference'], 'MT')), 'grandtotal');
	return array('rows' => $rows, 'bs' => $bs, 'laid' => $laid);
}

/**
 * Balance Sheet dates for columns: the end of each month / fiscal quarter / fiscal
 * year from $from, the last one being $asof itself.
 *
 * @param int    $from
 * @param int    $asof
 * @param string $mode months, quarters or years
 * @return int[]
 */
function anychartlab_bs_dates($from, $asof, $mode)
{
	$dates = array();
	foreach (anychartlab_split_periods($from, $asof, $mode) as $p) {
		$dates[] = $p['to'];
	}
	return $dates;
}

/**
 * Date to compare a Balance Sheet with.
 *
 * @param int    $asof
 * @param string $mode prevmonth (end of the previous month), prevfy (end of the last
 *                     fiscal year) or lastyear (same date a year earlier)
 * @return int
 */
function anychartlab_bs_compare_date($asof, $mode)
{
	if ($mode === 'lastyear') {
		return dol_time_plus_duree($asof, -1, 'y');
	}
	if ($mode === 'prevfy') {
		return anychartlab_fiscal_year_start($asof) - 1;
	}
	return anychartlab_month_start((int) dol_print_date($asof, '%m'), (int) dol_print_date($asof, '%Y')) - 1;
}

/**
 * Value of one column of a merged row: an amount, or the change / change % between
 * the columns whose amounts are at index 0 (current) and 1 (comparison).
 *
 * @param array $m   Merged row
 * @param array $def Column: label, title, src (index of the amounts, or 'chg' / 'pct')
 * @return float|null
 */
function anychartlab_matrix_cell($m, $def)
{
	if ($def['src'] === 'chg' || $def['src'] === 'pct') {
		$c = anychartlab_change($m['amounts'][0], $m['amounts'][1]);
		return ($def['src'] === 'chg' ? $c[0] : $c[1]);
	}
	return $m['amounts'][$def['src']];
}

/**
 * Display text of one column value.
 *
 * @param float|null $v
 * @param array      $def
 * @return string
 */
function anychartlab_matrix_text($v, $def)
{
	if ($v === null) {
		return '';
	}
	return ($def['src'] === 'pct' ? number_format($v, 1).'%' : anychartlab_price($v));
}

/**
 * Print a table with several amount columns.
 *
 * @param array $merged   From anychartlab_merge_period_rows()
 * @param array $colDefs  Column definitions (see anychartlab_matrix_cell())
 * @param array $accounts From anychartlab_load_accounts() (links to the ledger)
 * @return void
 */
function anychartlab_matrix_print($merged, $colDefs, $accounts)
{
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>Account</th><th>Label</th>';
	foreach ($colDefs as $def) {
		print '<th class="right nowraponall" title="'.dol_escape_htmltag($def['title']).'">'.dol_escape_htmltag($def['label']).'</th>';
	}
	print '</tr>';
	foreach ($merged as $m) {
		$kind = $m['kind'];
		$trClass = ($kind === 'section' ? 'liste_titre' : (in_array($kind, array('total', 'grandtotal')) ? 'liste_total' : 'oddeven'));
		$acc = (string) $m['cells'][0];
		print '<tr class="'.$trClass.' acl-'.$kind.'">';
		if ($kind === 'section') {
			print '<td colspan="2">'.dol_escape_htmltag($acc.($m['cells'][1] !== '' ? ' '.$m['cells'][1] : '')).'</td>';
		} else {
			$link = ($acc !== '' && in_array($kind, array('account', 'child')) && isset($accounts['byNumber'][$acc]));
			print '<td class="nowraponall">'.($link ? '<a href="'.anychartlab_ledger_url($acc).'" target="_blank">'.dol_escape_htmltag($acc).'</a>' : dol_escape_htmltag($acc)).'</td>';
			print '<td>'.dol_escape_htmltag($m['cells'][1]).'</td>';
		}
		foreach ($colDefs as $def) {
			print '<td class="right amount nowraponall">'.anychartlab_matrix_text(anychartlab_matrix_cell($m, $def), $def).'</td>';
		}
		print '</tr>';
	}
	print '</table></div>';
}

/**
 * Send a table with several amount columns as CSV (action export) or PDF, and exit.
 *
 * @param string $action   export, pdf or pdfpreview
 * @param string $name     File name without extension
 * @param string $title
 * @param string $subtitle
 * @param array  $merged
 * @param array  $colDefs
 * @return void
 */
function anychartlab_matrix_send($action, $name, $title, $subtitle, $merged, $colDefs)
{
	if ($action === 'export') {
		$csv = array();
		foreach ($merged as $m) {
			$line = array($m['cells'][0], $m['cells'][1]);
			foreach ($colDefs as $def) {
				$v = anychartlab_matrix_cell($m, $def);
				$line[] = ($v === null ? '' : ($def['src'] === 'pct' ? round($v, 1) : price2num($v, 'MT')));
			}
			$csv[] = $line;
		}
		anychartlab_send_csv('anychartlab-'.$name, array_merge(array('account', 'label'), array_column($colDefs, 'label')), $csv);
	}
	$n = count($colDefs);
	$showAcc = ($n <= 6 && anychartlab_cfg('PDF_SHOW_ACCOUNT_COL') !== '0');
	$amountW = min(0.14, ($n > 10 ? 0.74 : 0.66) / $n);
	$columns = array(array('title' => 'Account', 'width' => ($showAcc ? 0.09 : 0), 'align' => 'L', 'indent' => 1));
	$columns[] = array('title' => 'Label', 'width' => 1 - ($showAcc ? 0.09 : 0) - $n * $amountW, 'align' => 'L', 'indent' => 1);
	foreach ($colDefs as $def) {
		$columns[] = array('title' => $def['label'], 'width' => $amountW, 'align' => 'R');
	}
	$pdfRows = array();
	foreach ($merged as $m) {
		$cells = array((string) $m['cells'][0], (string) $m['cells'][1]);
		if (!$showAcc && $m['kind'] !== 'section') {
			$cells[1] = trim(($cells[0] !== '' ? $cells[0].' ' : '').$cells[1]);
		}
		foreach ($colDefs as $def) {
			$cells[] = anychartlab_matrix_text(anychartlab_matrix_cell($m, $def), $def);
		}
		$pdfRows[] = array('kind' => $m['kind'], 'cells' => $cells);
	}
	anychartlab_pdf_table($name, $title, $subtitle, $columns, $pdfRows, $action === 'pdfpreview', ($n > 3 ? 'L' : ''), ($n > 10 ? 6.5 : ($n > 8 ? 7 : ($n > 5 ? 8 : 0))));
}
