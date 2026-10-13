<?php
/**
 * Financial Reporting — "How Financial Reporting works", shown in Getting started.
 *
 * The chart examples are real accounts from charts shipped with Dolibarr
 * (htdocs/install/mysql/data/llx_accounting_account_*.sql), the same eight kinds of account
 * on each card so they line up. The
 * report structures summarise the country frameworks of seed/classes/frameworks/ and the
 * report templates (not reviewed by an accountant: shown with the standard caution).
 */

/**
 * Sample accounts per shipped chart: kind => array(number, label, Dolibarr group).
 *
 * @return array<string,array{title:string,chart:string,rows:array<string,array{0:string,1:string,2:string}>}>
 */
function financialreporting_explain_charts()
{
	return array(
		'FR' => array('title' => 'France', 'chart' => 'PCG25-DEV', 'rows' => array(
			'capital' => array('101', 'Capital', 'CAPIT'),
			'vehicle' => array('2182', 'Matériel de transport', 'IMMO'),
			'customers' => array('411', 'Clients', 'THIRDPARTY'),
			'suppliers' => array('401', 'Fournisseurs', 'THIRDPARTY'),
			'bank' => array('512', 'Banques', 'FINAN'),
			'purchases' => array('607', 'Achats de marchandises', 'EXPENSE'),
			'wages' => array('641', 'Rémunérations du personnel', 'EXPENSE'),
			'sales' => array('707', 'Ventes de marchandises', 'INCOME'),
		)),
		'US' => array('title' => 'United States', 'chart' => 'US-GAAP-BASIC', 'rows' => array(
			'capital' => array('311', 'Equity At Par (Issued Capital)', 'EQUITY'),
			'vehicle' => array('153', 'Machinery And Equipment', 'ASSETS'),
			'customers' => array('121', 'Accounts, Notes And Loans Receivable', 'ASSETS'),
			'suppliers' => array('211', 'Trade Payables', 'LIABILITIES'),
			'bank' => array('111', 'Cash And Cash Equivalents', 'ASSETS'),
			'purchases' => array('511', 'Material And Merchandise', 'EXPENSES'),
			'wages' => array('512', 'Employee Benefits', 'EXPENSES'),
			'sales' => array('411', 'Goods', 'REVENUE'),
		)),
		'DE' => array('title' => 'Germany', 'chart' => 'SKR03', 'rows' => array(
			'capital' => array('0800', 'Gezeichnetes Kapital', '0: Anlage- und Kapitalkonten'),
			'vehicle' => array('0320', 'Pkw', '0: Anlage- und Kapitalkonten'),
			'customers' => array('1400', 'Forderungen aus Lieferungen und Leistungen', '1: Finanz- und Privatkonten'),
			'suppliers' => array('1600', 'Verbindlichkeiten aus Lieferungen und Leistungen', '1: Finanz- und Privatkonten'),
			'bank' => array('1200', 'Bank', '1: Finanz- und Privatkonten'),
			'purchases' => array('3400', 'Wareneingang 19 % Vorsteuer', '3: Wareneingangs- und Bestandskonten'),
			'wages' => array('4120', 'Gehälter', '4: Betriebliche Aufwendungen'),
			'sales' => array('8400', 'Erlöse 19 % USt', '8: Erlöskonten'),
		)),
		'SN' => array('title' => 'Senegal (OHADA)', 'chart' => 'SYSCOHADA-SN', 'rows' => array(
			'capital' => array('101', 'Capital social', 'CAPIT'),
			'vehicle' => array('245', 'Matériel de transport', 'IMMO'),
			'customers' => array('411', 'Clients', 'THIRDPARTY'),
			'suppliers' => array('401', 'Fournisseurs, dettes en comptes', 'THIRDPARTY'),
			'bank' => array('521', 'Banques locales', 'FINAN'),
			'purchases' => array('601', 'Achats de marchandises', 'EXPENSE'),
			'wages' => array('661', 'Rémunérations directes versées au personnel national', 'EXPENSE'),
			'sales' => array('701', 'Ventes de marchandises', 'INCOME'),
		)),
	);
}

/**
 * Report structures by country (income statement, main lines in order).
 *
 * @return array<string,array{title:string,basis:string,statutory:bool,lines:string[]}>
 */
function financialreporting_explain_reports()
{
	return array(
		'FR' => array('title' => 'France', 'basis' => 'PCG — compte de résultat par nature', 'statutory' => true, 'lines' => array(
			'Produits d\'exploitation', 'Charges d\'exploitation', '= Résultat d\'exploitation', 'Produits et charges financiers', '= Résultat financier', 'Produits et charges exceptionnels', '= Résultat exceptionnel', 'Participation, impôts sur les bénéfices', '= Bénéfice ou perte')),
		'AU' => array('title' => 'Australia', 'basis' => 'Special purpose report (common for small companies); AASB 101 for general purpose', 'statutory' => false, 'lines' => array(
			'Revenue', 'Cost of sales', '= Gross profit', 'Other income', 'Expenses (employment, operating, other)', '= Net profit before tax', 'Income tax expense', '= Net profit after tax')),
		'DE' => array('title' => 'Germany', 'basis' => 'HGB § 275 (2) — Gesamtkostenverfahren', 'statutory' => true, 'lines' => array(
			'1. Umsatzerlöse', '2. Bestandsveränderungen', '5. Materialaufwand', '6. Personalaufwand', '7. Abschreibungen', '8. Sonstige betriebliche Aufwendungen', '13. Zinsen und ähnliche Aufwendungen', '14. Steuern vom Einkommen und vom Ertrag', '17. Jahresüberschuss / Jahresfehlbetrag')),
		'OHADA' => array('title' => 'OHADA (17 countries)', 'basis' => 'SYSCOHADA révisé — soldes successifs', 'statutory' => true, 'lines' => array(
			'Marge commerciale', 'Valeur ajoutée', 'Excédent brut d\'exploitation', 'Résultat d\'exploitation', 'Résultat financier', '= Résultat des activités ordinaires', 'Résultat hors activités ordinaires (HAO)', 'Impôt sur le résultat', '= Résultat net')),
		'US' => array('title' => 'United States', 'basis' => 'US GAAP — multi-step (no mandated layout for private companies)', 'statutory' => false, 'lines' => array(
			'Revenue', 'Cost of sales', '= Gross profit', 'Selling, general and administrative', '= Operating income', 'Other income and expense', '= Income before income taxes', 'Income tax expense', '= Net income')),
	);
}

/**
 * Print the explanation panel.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @param array  $accounts From financialreporting_load_accounts()
 * @param bool   $open     Open (step 1) or collapsed
 * @return void
 */
function financialreporting_print_explain($db, $entity, $accounts, $open)
{
	global $langs, $mysoc;
	$langs->load('financialreporting@financialreporting');
	$kinds = array('capital', 'vehicle', 'customers', 'suppliers', 'bank', 'purchases', 'wages', 'sales');
	$natures = financialreporting_natures();

	print '<style>
.frx{margin:6px 0 14px;padding:4px 12px;border:1px solid var(--colortopbordertitle1,#ddd);border-radius:8px}
.frx summary{cursor:pointer;font-weight:bold;padding:6px 0}
.frx h4{margin:14px 0 4px}
.frx-cards{display:flex;gap:12px;overflow-x:auto;padding:4px 0 10px}
.frx-card{flex:0 0 250px;border:1px solid var(--colortopbordertitle1,#ddd);border-radius:8px;overflow:hidden;font-size:.88em;background:var(--colorbackbody,#fff)}
.frx-card.own{border:2px solid var(--colortextlink,#2a6)}
.frx-card .h{padding:6px 8px;font-weight:bold;background:var(--colorbacktitle1,#f0f0f0)}
.frx-card .h span{font-weight:normal;opacity:.7}
.frx-card table{width:100%;border-collapse:collapse}
.frx-card th{font-weight:normal;text-align:left;opacity:.7;padding:3px 8px;border-bottom:1px solid #ddd}
.frx-card td{padding:3px 8px;vertical-align:top;border-bottom:1px solid #eee}
.frx-card td.n{white-space:nowrap;font-weight:bold}
.frx-card td.g{font-family:monospace;font-size:.95em;color:#7a4b00}
.frx-card td.t{color:#1d6b1d}
.frx-card tr.hi td{background:#fff3c4}
.frx-card ul.lines{margin:6px 0 8px;padding-left:10px;list-style:none}
.frx-card ul.lines li{padding:1px 0}
.frx-card li.tot{font-weight:bold}
.frx-steps{counter-reset:s;list-style:none;padding-left:0}
.frx-steps li{counter-increment:s;margin:6px 0;padding-left:30px;position:relative}
.frx-steps li::before{content:counter(s);position:absolute;left:0;top:0;width:22px;height:22px;border-radius:11px;background:var(--colortextlink,#2a6);color:#fff;text-align:center;font-weight:bold;line-height:22px}
</style>';
	print '<details class="frx" id="how"'.($open ? ' open' : '').'><summary>'.$langs->trans('FinRepXTitle').'</summary>';

	// 1. charts differ
	print '<h4>1. '.$langs->trans('FinRepXChartsTitle').'</h4><p>'.$langs->trans('FinRepXChartsHelp').'</p>';
	print '<div class="frx-cards">';
	foreach (financialreporting_explain_charts() as $c) {
		print '<div class="frx-card"><div class="h">'.dol_escape_htmltag($c['title']).' <span>'.dol_escape_htmltag($c['chart']).'</span></div>';
		print '<table><tr><th>'.$langs->trans('FinRepXAccount').'</th><th>'.$langs->trans('FinRepXGroup').'</th></tr>';
		foreach ($kinds as $k) {
			$r = $c['rows'][$k];
			print '<tr'.($r[0] === '512' ? ' class="hi"' : '').'><td><span class="n">'.dol_escape_htmltag($r[0]).'</span> '.dol_escape_htmltag($r[1]).'</td><td class="g">'.dol_escape_htmltag($r[2]).'</td></tr>';
		}
		print '</table></div>';
	}
	print '</div>';
	print '<p class="opacitymedium">'.$langs->trans('FinRepXChartsNote').'</p>';

	// 2. Dolibarr's fields
	print '<h4>2. '.$langs->trans('FinRepXDolibarrTitle').'</h4><p>'.$langs->trans('FinRepXDolibarrHelp').'</p>';

	// 3. report structures differ
	print '<h4>3. '.$langs->trans('FinRepXReportsTitle').'</h4><p>'.$langs->trans('FinRepXReportsHelp').'</p>';
	print '<div class="frx-cards">';
	foreach (financialreporting_explain_reports() as $code => $r) {
		$mine = ($code === strtoupper((string) ($mysoc->country_code ?? '')));
		print '<div class="frx-card'.($mine ? ' own' : '').'"><div class="h">'.dol_escape_htmltag($r['title']).' <span>'.($r['statutory'] ? $langs->trans('FinRepXStatutory') : $langs->trans('FinRepXNotPrescribed')).'</span></div>';
		print '<div style="padding:4px 8px" class="opacitymedium">'.dol_escape_htmltag($r['basis']).'</div><ul class="lines">';
		foreach ($r['lines'] as $l) {
			$tot = (strpos($l, '= ') === 0);
			print '<li'.($tot ? ' class="tot"' : '').'>'.dol_escape_htmltag($l).'</li>';
		}
		print '</ul></div>';
	}
	print '</div>';
	print '<p class="opacitymedium">'.$langs->trans('FinRepXReportsCaution').'</p>';

	// 4. how this module solves it
	print '<h4>4. '.$langs->trans('FinRepXSolveTitle').'</h4>';
	print '<ol class="frx-steps"><li>'.$langs->trans('FinRepXSolve1').'</li><li>'.$langs->trans('FinRepXSolve2').'</li><li>'.$langs->trans('FinRepXSolve3').'</li><li>'.$langs->trans('FinRepXSolve4').'</li><li>'.$langs->trans('FinRepXSolve5').'</li></ol>';
	print '</details>';
}
