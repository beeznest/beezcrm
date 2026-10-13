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
 * \file    class/actions_crm.class.php
 * \ingroup crm
 * \brief   Hooks: CRM tools on aimcp, and a deal e-mail operation for the Dolibarr e-mail collector.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Hook class. Dolibarr loads ActionsCrm from class/actions_crm.class.php.
 */
class ActionsCrm extends CommonHookActions
{
	/** @var DoliDB */
	public $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Give McpHandler one instance per tool class.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param object              $object     MCP handler
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0
	 */
	public function addMcpTools($parameters, &$object, &$action, $hookmanager)
	{
		if (!is_array($parameters) || (($parameters['currentcontext'] ?? '') !== 'aimcp')) {
			return 0;
		}
		$db = $parameters['db'] ?? $this->db;
		$user = $parameters['user'] ?? null;
		$conf = $parameters['conf'] ?? null;
		if (!is_object($user) || !is_object($conf)) {
			return 0;
		}

		require_once __DIR__.'/mcp/crmdictionarytool.class.php';
		require_once __DIR__.'/mcp/crmopportunitytool.class.php';
		require_once __DIR__.'/mcp/crmactivitytool.class.php';
		require_once __DIR__.'/mcp/crmsequencetool.class.php';
		require_once __DIR__.'/mcp/crmreporttool.class.php';

		$this->results = array(
			new CrmDictionaryTool($db, $user, $conf),
			new CrmOpportunityTool($db, $user, $conf),
			new CrmActivityTool($db, $user, $conf),
			new CrmSequenceTool($db, $user, $conf),
			new CrmReportTool($db, $user, $conf),
		);
		return 0;
	}

	/**
	 * Offer the CRM operation in the e-mail collector setup, next to the core ones.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param object              $object     Collector
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0 to append to the core list
	 */
	public function addMoreActionsEmailCollector($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;
		if (!isModEnabled('crm')) {
			return 0;
		}
		$langs->load('crm@crm');
		require_once __DIR__.'/crmmailarchive.class.php';
		$this->results = array(CrmMailArchive::COLLECTOR_OPERATION => 'CrmCollectorOperation');
		return 0;
	}

	/**
	 * Run the CRM operation on one message read by the e-mail collector. A message that cannot be tied to a
	 * deal is ignored without error, so the other operations of the collector are not disturbed.
	 *
	 * @param array<string,mixed> $parameters Hook parameters (header, messagetext, subject, from...)
	 * @param object              $object     Collector
	 * @param string              $action     Operation type
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0 when done or ignored, -1 on error
	 */
	public function doCollectImapOneCollector($parameters, &$object, &$action, $hookmanager)
	{
		global $user;
		require_once __DIR__.'/crmmailarchive.class.php';
		if ($action !== CrmMailArchive::COLLECTOR_OPERATION || !isModEnabled('crm')) {
			return 0;
		}
		$header = (string) ($parameters['header'] ?? '');
		$date = strtotime(crmHeaderValue($header, 'Date'));
		$from = (string) ($parameters['from'] ?? '');
		if ($from === '') {
			$from = crmHeaderValue($header, 'From');
		}
		$archive = new CrmMailArchive($this->db);
		$result = $archive->record($user, array(
			'msgid' => crmHeaderValue($header, 'Message-ID'),
			'in_reply_to' => crmHeaderValue($header, 'In-Reply-To'),
			'references' => crmHeaderValue($header, 'References'),
			'from' => $from,
			'to' => crmHeaderValue($header, 'To'),
			'cc' => crmHeaderValue($header, 'Cc'),
			'subject' => (string) ($parameters['subject'] ?? ''),
			'body' => (string) ($parameters['messagetext'] ?? ''),
			'date' => ($date === false) ? 0 : (int) $date,
		));
		dol_syslog('CRM e-mail archive: '.$result['status'].' deal='.$result['deal'].' event='.$result['event'], LOG_INFO);
		if ($result['status'] === 'error') {
			$this->resprints = $archive->error;
			return -1;
		}
		return 0;
	}
}
