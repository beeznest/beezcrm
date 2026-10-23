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

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

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
		require_once __DIR__.'/mcp/crmnotetool.class.php';
		require_once __DIR__.'/mcp/crmactivitytool.class.php';
		require_once __DIR__.'/mcp/crmsequencetool.class.php';
		require_once __DIR__.'/mcp/crmreporttool.class.php';

		// One array of tools, itself inside the results array: the Dolibarr 24.0.0 loader skips a bare tool object in the
		// results (core develop accepts both), so a flat list would register nothing on 24.0.0.
		$this->results = array(array(
			new CrmDictionaryTool($db, $user, $conf),
			new CrmOpportunityTool($db, $user, $conf),
			new CrmNoteTool($db, $user, $conf),
			new CrmActivityTool($db, $user, $conf),
			new CrmSequenceTool($db, $user, $conf),
			new CrmReportTool($db, $user, $conf),
		));
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
			'auto' => crmIsAutomaticMail($header),
			'authresults' => crmHeaderValue($header, 'Authentication-Results'),
		));
		dol_syslog('CRM e-mail archive: '.$result['status'].' deal='.$result['deal'].' event='.$result['event'], LOG_INFO);
		if ($result['status'] === 'error') {
			$this->resprints = $archive->error;
			return -1;
		}
		return 0;
	}

	/**
	 * Add the CRM opportunities to the entries of the general search box. The search box lists core modules by
	 * itself; an external module has to add its own entry through this hook.
	 *
	 * @param array<string,mixed> $parameters Hook parameters: search_boxvalue, arrayresult
	 * @param object              $object     Not used
	 * @param string              $action     Not used
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0 to append to the core entries
	 */
	public function addSearchEntry($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;
		if (!isModEnabled('crm') || !is_object($user) || !($user->hasRight('crm', 'read') || $user->hasRight('crm', 'readall'))) {
			return 0;
		}
		$langs->load('crm@crm');
		$search = (string) ($parameters['search_boxvalue'] ?? '');
		$url = dol_buildpath('/crm/opportunity_list.php', 1).'?status=all'.($search !== '' ? '&search_all='.urlencode($search) : '');
		$label = $langs->trans('CrmSearchInto', $search);
		$this->results = array('searchintocrmopportunity' => array(
			'position' => getDolGlobalInt('SEARCHFORM_POSITION_CRM', 42),
			'shortcut' => 'O',
			'img' => 'fa-handshake',
			'label' => $label,
			'text' => img_picto('', 'fa-handshake', 'class="pictofixedwidth"').' '.$label,
			'url' => $url,
		));
		return 0;
	}

	/**
	 * From v1.2.0. Third party card (context thirdpartycomm, the Prospect tab): a row "CRM opportunity" in the table of the card, with a link
	 * to each deal the user may read, or a link to create one for a prospect that has none.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param object              $object     The third party
	 * @param string              $action     Action of the card
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0 to keep the core rows
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user, $db;
		if (!$this->onThirdpartyView($parameters, $object, (string) $action)) {
			return 0;
		}
		require_once __DIR__.'/crmaccountview.class.php';
		$langs->load('crm@crm');
		$deals = (new CrmAccountView($db))->fetchDeals($user, (int) $object->id);
		$base = dol_buildpath('/crm/opportunity_card.php', 1);
		$cell = '';
		foreach (array_slice($deals, 0, 3) as $deal) {
			$stage = ((string) $deal->status === 'open')
				? crmStageLabel((string) $deal->stage_label)
				: $langs->trans($deal->status === 'won' ? 'CrmWon' : 'CrmLost');
			$cell .= '<div><a href="'.dol_escape_htmltag($base.'?id='.((int) $deal->rowid)).'">'.img_picto('', 'fa-handshake', 'class="pictofixedwidth"').dol_escape_htmltag((string) $deal->ref).'</a> '.dol_escape_htmltag((string) $deal->label).' <span class="opacitymedium">('.dol_escape_htmltag($stage).')</span></div>';
		}
		if (count($deals) > 3) {
			$more = dol_buildpath('/crm/opportunity_list.php', 1).'?status=all&search_all='.urlencode((string) $object->name);
			$cell .= '<div><a href="'.dol_escape_htmltag($more).'">'.dol_escape_htmltag($langs->transnoentities('CrmAccountDealsMore', count($deals) - 3)).'</a></div>';
		}
		if (count($deals) === 0) {
			if (!$user->hasRight('crm', 'write') || empty($object->prospect)) {
				return 0;
			}
			$cell = '<a href="'.dol_escape_htmltag($base.'?action=create&socid='.((int) $object->id)).'">'.img_picto('', 'fa-plus-circle', 'class="pictofixedwidth"').dol_escape_htmltag($langs->transnoentities('CrmNewOpportunity')).'</a>';
		}
		$this->resprints = '<tr><td>'.dol_escape_htmltag($langs->trans('CrmAccountDeals')).'</td><td>'.$cell.'</td></tr>';
		return 0;
	}

	/**
	 * From v1.2.0. Prospect tab of the third party: the CRM history block, added to the lists of recent objects at the bottom
	 * of the right column (core prints what the hook puts in resprints).
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param object              $object     The third party
	 * @param string              $action     Not used
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0
	 */
	public function addMoreRecentObjects($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user, $db;
		if (!$this->onThirdpartyView($parameters, $object, (string) $action)) {
			return 0;
		}
		require_once __DIR__.'/crmaccountview.class.php';
		$langs->load('crm@crm');
		$view = new CrmAccountView($db);
		$rows = $view->fetchTimeline($user, (int) $object->id, 5);
		if (count($rows) === 0 && count($view->fetchDeals($user, (int) $object->id)) === 0) {
			return 0;
		}
		$full = dol_buildpath('/crm/account_history.php', 1).'?socid='.((int) $object->id);
		$link = dolGetButtonTitle($langs->trans('CrmAccountHistoryFull'), '', 'fa fa-bars imgforviewmode', $full);
		$html = load_fiche_titre($langs->trans('CrmAccountHistory'), '<div class="nowraponall">'.$link.'</div>', '');
		$html .= '<div class="div-table-responsive-no-min"><table class="centpercent noborder">';
		$html .= '<tr class="liste_titre"><td>'.$langs->trans('CrmDate').'</td><td>'.$langs->trans('CrmOpportunity').'</td><td>'.$langs->trans('CrmAccountHistoryWhat').'</td><td>'.$langs->trans('CrmOwner').'</td></tr>';
		$html .= crmAccountTimelineRows($rows);
		$html .= '</table></div><br>';
		$this->resprints = $html;
		return 0;
	}

	/**
	 * True when the hook runs on the view of a third party card for a user who may read deals.
	 */
	private function onThirdpartyView($parameters, $object, string $action): bool
	{
		global $user;
		return is_array($parameters) && (($parameters['currentcontext'] ?? '') === 'thirdpartycomm')
			&& is_object($object) && ($object->element ?? '') === 'societe' && (int) ($object->id ?? 0) > 0
			&& !in_array($action, array('create', 'edit', 'update', 'add'), true)
			&& isModEnabled('crm') && ($user->hasRight('crm', 'read') || $user->hasRight('crm', 'readall'));
	}
}
