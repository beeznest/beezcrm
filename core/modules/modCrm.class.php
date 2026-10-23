<?php
/* Copyright (C) 2026 CRM contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

/**
 * \file    core/modules/modCrm.class.php
 * \ingroup crm
 * \brief   Descriptor of the CRM external module.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Sales pipeline module. The technical name is lowercase because Dolibarr derives paths from it.
 */
class modCrm extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 104801;
		$this->rights_class = 'crm';
		$this->family = 'crm';
		$this->module_position = '90';
		$this->name = 'crm';
		$this->description = 'ModuleCrmDesc';
		$this->descriptionlong = 'ModuleCrmDesc';
		$this->editor_name = 'BeezNest Belgium';
		$this->editor_url = 'https://beeznest.com';
		$this->version = '1.2.0';
		$this->const_name = 'MAIN_MODULE_'.dol_strtoupper($this->name);
		$this->picto = 'fa-handshake';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array('aimcp', 'emailcollectorcard', 'emailcollector', 'searchform'),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@crm');
		$this->hidden = 0;
		$this->depends = array('modSociete', 'modAgenda');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('crm@crm');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(21, 0);
		$this->need_javascript_ajax = 0;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();
		// Constants are created by crmEnsureConstants() so a later disable does not reset them.
		$this->const = array();
		$this->tabs = array();
		$this->dictionaries = array();

		$this->boxes = array(
			0 => array(
				'file' => 'crmwidget.php@crm',
				'note' => 'CrmBoxTitle',
				'enabledbydefaulton' => 'Home',
			),
		);

		$this->cronjobs = array(
			0 => array(
				'label' => 'CrmOwnerReminders:crm@crm',
				'jobtype' => 'method',
				'class' => '/crm/class/crmcron.class.php',
				'objectname' => 'CrmCron',
				'method' => 'runOwnerReminders',
				'parameters' => '',
				'comment' => 'CrmCronReminderComment',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 1,
				'test' => 'isModEnabled("crm")',
				'priority' => 50,
			),
			1 => array(
				'label' => 'CrmCustomerSequences:crm@crm',
				'jobtype' => 'method',
				'class' => '/crm/class/crmcron.class.php',
				'objectname' => 'CrmCron',
				'method' => 'runSequences',
				'parameters' => '',
				'comment' => 'CrmCronSequenceComment',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 1,
				'test' => 'isModEnabled("crm")',
				'priority' => 50,
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = 10480101;
		$this->rights[$r][1] = 'CrmRightRead';
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = 10480102;
		$this->rights[$r][1] = 'CrmRightWrite';
		$this->rights[$r][4] = 'write';
		$r++;
		$this->rights[$r][0] = 10480103;
		$this->rights[$r][1] = 'CrmRightDelete';
		$this->rights[$r][4] = 'delete';
		$r++;
		$this->rights[$r][0] = 10480104;
		$this->rights[$r][1] = 'CrmRightReadAll';
		$this->rights[$r][4] = 'readall';
		$r++;
		$this->rights[$r][0] = 10480105;
		$this->rights[$r][1] = 'CrmRightActivity';
		$this->rights[$r][4] = 'activity';
		$r++;
		$this->rights[$r][0] = 10480106;
		$this->rights[$r][1] = 'CrmRightSequence';
		$this->rights[$r][4] = 'sequence';
		$r++;
		$this->rights[$r][0] = 10480107;
		$this->rights[$r][1] = 'CrmRightReport';
		$this->rights[$r][4] = 'report';
		$r++;
		$this->rights[$r][0] = 10480108;
		$this->rights[$r][1] = 'CrmRightSetup';
		$this->rights[$r][4] = 'setup';

		$canRead = '$user->hasRight("crm", "read") || $user->hasRight("crm", "readall")';
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => '',
			'type' => 'top',
			'titre' => 'CRM',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'crm',
			'leftmenu' => '',
			'url' => '/crm/index.php',
			'langs' => 'crm@crm',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('crm')",
			'perms' => $canRead,
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=crm',
			'type' => 'left',
			'titre' => 'CrmHome',
			'mainmenu' => 'crm',
			'leftmenu' => 'crmhome',
			'url' => '/crm/index.php',
			'langs' => 'crm@crm',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('crm')",
			'perms' => $canRead,
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=crm',
			'type' => 'left',
			'titre' => 'CrmPipeline',
			'mainmenu' => 'crm',
			'leftmenu' => 'crmpipeline',
			'url' => '/crm/pipeline.php',
			'langs' => 'crm@crm',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('crm')",
			'perms' => $canRead,
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=crm',
			'type' => 'left',
			'titre' => 'CrmOpportunities',
			'mainmenu' => 'crm',
			'leftmenu' => 'crmopportunity',
			'url' => '/crm/opportunity_list.php',
			'langs' => 'crm@crm',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('crm')",
			'perms' => $canRead,
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=crm,fk_leftmenu=crmopportunity',
			'type' => 'left',
			'titre' => 'CrmNewOpportunity',
			'mainmenu' => 'crm',
			'leftmenu' => 'crmopportunitynew',
			'url' => '/crm/opportunity_card.php?action=create',
			'langs' => 'crm@crm',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('crm')",
			'perms' => '$user->hasRight("crm", "write")',
			'target' => '',
			'user' => 0,
		);
		// Automated outreach come right after Opportunities, which they work on, then Activities and Reports.
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=crm',
			'type' => 'left',
			'titre' => 'CrmSequences',
			'mainmenu' => 'crm',
			'leftmenu' => 'crmsequence',
			'url' => '/crm/sequence_list.php',
			'langs' => 'crm@crm',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('crm')",
			'perms' => $canRead,
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=crm',
			'type' => 'left',
			'titre' => 'CrmActivities',
			'mainmenu' => 'crm',
			'leftmenu' => 'crmactivity',
			'url' => '/crm/activity_list.php',
			'langs' => 'crm@crm',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('crm')",
			'perms' => $canRead,
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=crm',
			'type' => 'left',
			'titre' => 'CrmReports',
			'mainmenu' => 'crm',
			'leftmenu' => 'crmreport',
			'url' => '/crm/report.php',
			'langs' => 'crm@crm',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('crm')",
			'perms' => '$user->hasRight("crm", "report")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Enable the module. Tables stay on disable. Seed runs only when the entity has no pipeline.
	 *
	 * @param string $options Options
	 * @return int 1 if OK, 0 or -1 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/crm/sql/');
		if ($result < 0) {
			return -1;
		}
		$this->remove($options);
		require_once __DIR__.'/../../lib/crm.lib.php';
		$upgrade = crmUpgradeSchema($this->db);
		if ($upgrade['error'] !== '') {
			$this->error = $upgrade['error'];
			return -1;
		}
		crmEnsureConstants($this->db);
		crmSeedIfEmpty($this->db);
		crmEnsureBuiltinSources($this->db);
		return $this->_init(array(), $options);
	}

	/**
	 * Disable the module. Menus, rights, the box and the cron jobs go away. Deals and agenda events stay.
	 *
	 * @param string $options Options
	 * @return int 1 if OK, 0 or -1 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
