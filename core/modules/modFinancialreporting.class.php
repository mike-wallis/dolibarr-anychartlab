<?php
/**
 * Financial Reporting — Dolibarr module descriptor.
 *
 * Balance Sheet, Income Statement, Trial Balance, Cash Flow and aged receivables /
 * payables that work with any chart of accounts and any country. Each account gets a
 * nature (asset, liability, equity, income, expense, clearing, excluded), an optional
 * alternate nature used when its balance is on the opposite side, and a contra flag;
 * reports are built from those natures, not from hardcoded pcg_type values or
 * account-number ranges. A setup wizard builds the income statement as a Dolibarr
 * personalised report from a country template.
 *
 * Natures and advanced layouts live in this module's own tables (llx_financialreporting_*).
 * The wizard writes NEW Dolibarr personalised reports (core's personalised group tables);
 * it never changes existing ones, the chart or the ledger.
 *
 * Goal: a free module on DoliStore, then Dolibarr core (issue Dolibarr/dolibarr#31760).
 * Formerly the prototype "Any-Chart Reports Lab" (anychartlab), renamed 2026-10-12.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modFinancialreporting extends DolibarrModules
{
	public function __construct($db)
	{
		parent::__construct($db);

		$this->numero          = 500023;
		$this->rights_class    = 'financialreporting';
		$this->family          = 'financial';
		$this->picto           = 'accountancy';
		$this->name            = 'Financial Reporting';
		$this->description     = 'Balance Sheet, Income Statement, Trial Balance, Cash Flow and aged receivables/payables for any chart of accounts and any country. A wizard builds your income statement as a Dolibarr personalised report from a country template. Development version, feedback welcome on Dolibarr issue #31760.';
		$this->version         = '0.3';
		$this->const_name      = 'MAIN_MODULE_FINANCIALREPORTING';
		$this->editor_name     = 'Dolibarr User Australia';
		$this->editor_url      = 'mailto:dolibarruseraustralia@gmail.com';
		$this->config_page_url = array('start.php@financialreporting');
		$this->depends         = array('modAccounting');
		$this->need_dolibarr_version = array(23, 0);
		$this->langfiles       = array('financialreporting@financialreporting');

		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=accountancy',
			'type'     => 'left',
			'titre'    => 'Financial Reporting',
			'mainmenu' => 'accountancy',
			'leftmenu' => 'financialreporting',
			'url'      => '/custom/financialreporting/balancesheet.php?mainmenu=accountancy&leftmenu=financialreporting',
			'langs'    => '',
			'position' => 960,
			'enabled'  => 'isModEnabled("financialreporting")',
			'perms'    => '$user->hasRight("financialreporting", "lire")',
			'target'   => '',
			'user'     => 0,
		);
		$pages = array(
			array('Trial Balance', 'trialbalance.php', 'financialreporting_tb', 961, 'lire'),
			array('Balance Sheet', 'balancesheet.php', 'financialreporting_bs', 962, 'lire'),
			array('Income Statement', 'incomestatement.php', 'financialreporting_is', 963, 'lire'),
			array('Cash Flow', 'cashflow.php', 'financialreporting_cf', 964, 'lire'),
			array('Accounts Receivable', 'aged.php?type=ar', 'financialreporting_ar', 965, 'lire'),
			array('Accounts Payable', 'aged.php?type=ap', 'financialreporting_ap', 966, 'lire'),
			// "Setup" opens a sub-menu: its pages are only listed once it has been clicked
			array('Setup', 'admin/start.php', 'financialreporting_setupmenu', 967, 'setup'),
		);
		foreach ($pages as $p) {
			$this->menu[$r++] = array(
				'fk_menu'  => 'fk_mainmenu=accountancy,fk_leftmenu=financialreporting',
				'type'     => 'left',
				'titre'    => $p[0],
				'mainmenu' => 'accountancy',
				'leftmenu' => $p[2],
				'url'      => '/custom/financialreporting/'.$p[1].(strpos($p[1], '?') === false ? '?' : '&').'mainmenu=accountancy&leftmenu='.($p[2] === 'financialreporting_setupmenu' ? 'financialreporting_setupmenu' : 'financialreporting'),
				'langs'    => '',
				'position' => $p[3],
				'enabled'  => 'isModEnabled("financialreporting")',
				'perms'    => '$user->hasRight("financialreporting", "'.$p[4].'")',
				'target'   => '',
				'user'     => 0,
			);
		}
		$setupPages = array(
			array('FinRepStartMenu', 'admin/start.php', 'financialreporting_start', 968),
			array('FinRepWizMenu', 'admin/wizard.php', 'financialreporting_wizard', 969),
			array('Account natures', 'admin/setup.php', 'financialreporting_setup', 970),
			array('FinRepRulesMenu', 'admin/rules.php', 'financialreporting_rules', 971),
			array('Classification check', 'check.php', 'financialreporting_check', 972),
			array('Advanced layouts', 'admin/layouts.php', 'financialreporting_layouts', 973),
			array('Display', 'admin/display.php', 'financialreporting_display', 974),
		);
		foreach ($setupPages as $p) {
			$this->menu[$r++] = array(
				'fk_menu'  => 'fk_mainmenu=accountancy,fk_leftmenu=financialreporting_setupmenu',
				'type'     => 'left',
				'titre'    => $p[0],
				'mainmenu' => 'accountancy',
				'leftmenu' => $p[2],
				'url'      => '/custom/financialreporting/'.$p[1].'?mainmenu=accountancy&leftmenu=financialreporting_setupmenu',
				'langs'    => 'financialreporting@financialreporting',
				'position' => $p[3],
				'enabled'  => 'isModEnabled("financialreporting") && $leftmenu=="financialreporting_setupmenu"',
				'perms'    => '$user->hasRight("financialreporting", "setup")',
				'target'   => '',
				'user'     => 0,
			);
		}

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = 502301;
		$this->rights[$r][1] = 'View financial reports (full company financials)';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'lire';
		$r++;
		$this->rights[$r][0] = 502302;
		$this->rights[$r][1] = 'Set up financial reporting (account natures, report wizard, layouts)';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'setup';
		$r++;
	}

	public function init($options = '')
	{
		$result = $this->_load_tables('/financialreporting/sql/');
		if ($result < 0) {
			return -1;
		}
		return $this->_init(array(), $options);
	}

	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
