<?php
/**
 * Any-Chart Reports Lab — Dolibarr module descriptor.
 *
 * PROTOTYPE / test harness for the account "nature" design proposed on
 * Dolibarr/dolibarr#31760 (Balance Sheet / Income Statement for any chart of
 * accounts). Each account gets a nature (asset, liability, equity, income,
 * expense, clearing, excluded), an optional alternate nature used when its
 * balance is on the opposite side, and a contra flag. Reports are built from
 * those natures instead of hardcoded pcg_type values or account-number ranges.
 *
 * Natures live in this module's own table (llx_anychartlab_nature): no core
 * table is altered, so it is safe to try on a copy of real data and uninstalls
 * cleanly. The intent is to validate the design and collect per-country
 * mappings before proposing it for Dolibarr core (htdocs/accountancy/).
 *
 * See docs/decisions/anychartlab-plan.md in the source repository.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modAnychartlab extends DolibarrModules
{
	public function __construct($db)
	{
		parent::__construct($db);

		$this->numero          = 500023;
		$this->rights_class    = 'anychartlab';
		$this->family          = 'financial';
		$this->picto           = 'accountancy';
		$this->name            = 'Any-Chart Reports Lab (prototype)';
		$this->description     = 'PROTOTYPE for Dolibarr issue #31760: classify every account by nature (asset, liability, equity, income, expense…), then build a Balance Sheet and Income Statement that work with any chart of accounts. Export your mapping as CSV to share it.';
		$this->version         = '0.1';
		$this->const_name      = 'MAIN_MODULE_ANYCHARTLAB';
		$this->editor_name     = 'Dolibarr User Australia';
		$this->editor_url      = 'mailto:dolibarruseraustralia@gmail.com';
		$this->config_page_url = array('setup.php@anychartlab');
		$this->depends         = array('modAccounting');
		$this->need_dolibarr_version = array(23, 0);

		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=accountancy',
			'type'     => 'left',
			'titre'    => 'Any-Chart Reports Lab',
			'mainmenu' => 'accountancy',
			'leftmenu' => 'anychartlab',
			'url'      => '/custom/anychartlab/balancesheet.php?mainmenu=accountancy&leftmenu=anychartlab',
			'langs'    => '',
			'position' => 960,
			'enabled'  => 'isModEnabled("anychartlab")',
			'perms'    => '$user->hasRight("anychartlab", "lire")',
			'target'   => '',
			'user'     => 0,
		);
		$pages = array(
			array('Balance Sheet', 'balancesheet.php', 'anychartlab_bs', 961, 'lire'),
			array('Income Statement', 'incomestatement.php', 'anychartlab_is', 962, 'lire'),
			array('Accounts Receivable', 'aged.php?type=ar', 'anychartlab_ar', 963, 'lire'),
			array('Accounts Payable', 'aged.php?type=ap', 'anychartlab_ap', 964, 'lire'),
			// "Setup" opens a sub-menu: its pages are only listed once it has been clicked
			array('Setup', 'admin/setup.php', 'anychartlab_setupmenu', 966, 'setup'),
		);
		foreach ($pages as $p) {
			$this->menu[$r++] = array(
				'fk_menu'  => 'fk_mainmenu=accountancy,fk_leftmenu=anychartlab',
				'type'     => 'left',
				'titre'    => $p[0],
				'mainmenu' => 'accountancy',
				'leftmenu' => $p[2],
				'url'      => '/custom/anychartlab/'.$p[1].(strpos($p[1], '?') === false ? '?' : '&').'mainmenu=accountancy&leftmenu='.($p[2] === 'anychartlab_setupmenu' ? 'anychartlab_setupmenu' : 'anychartlab'),
				'langs'    => '',
				'position' => $p[3],
				'enabled'  => 'isModEnabled("anychartlab")',
				'perms'    => '$user->hasRight("anychartlab", "'.$p[4].'")',
				'target'   => '',
				'user'     => 0,
			);
		}
		$setupPages = array(
			array('Account natures', 'admin/setup.php', 'anychartlab_setup', 967),
			array('Classification check', 'check.php', 'anychartlab_check', 968),
			array('Layouts', 'admin/layouts.php', 'anychartlab_layouts', 969),
			array('Display', 'admin/display.php', 'anychartlab_display', 970),
		);
		foreach ($setupPages as $p) {
			$this->menu[$r++] = array(
				'fk_menu'  => 'fk_mainmenu=accountancy,fk_leftmenu=anychartlab_setupmenu',
				'type'     => 'left',
				'titre'    => $p[0],
				'mainmenu' => 'accountancy',
				'leftmenu' => $p[2],
				'url'      => '/custom/anychartlab/'.$p[1].'?mainmenu=accountancy&leftmenu=anychartlab_setupmenu',
				'langs'    => '',
				'position' => $p[3],
				'enabled'  => 'isModEnabled("anychartlab") && $leftmenu=="anychartlab_setupmenu"',
				'perms'    => '$user->hasRight("anychartlab", "setup")',
				'target'   => '',
				'user'     => 0,
			);
		}

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = 502301;
		$this->rights[$r][1] = 'View Any-Chart Lab reports (full company financials)';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'lire';
		$r++;
		$this->rights[$r][0] = 502302;
		$this->rights[$r][1] = 'Edit account natures (Any-Chart Lab)';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'setup';
		$r++;
	}

	public function init($options = '')
	{
		$result = $this->_load_tables('/anychartlab/sql/');
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
