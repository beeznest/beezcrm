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
 * \file    class/mcp/crmactivitytool.class.php
 * \ingroup crm
 * \brief   MCP tools for agenda events linked to a deal.
 */

require_once __DIR__.'/crmmcptool.class.php';

/**
 * Next steps are ActionComm rows. Completing one sets the percentage to 100.
 */
class CrmActivityTool extends CrmMcpTool
{
	/**
	 * @return list<array<string,mixed>>
	 */
	protected function crmDefinitions(): array
	{
		return array(
			$this->definition('list_crm_activities', 'List CRM agenda events. scope is overdue, today, planned or done. Pass id to list one deal.', array(
				'id' => array('type' => 'integer', 'description' => 'Opportunity id. Omit to list across deals.'),
				'scope' => array('type' => 'string', 'enum' => array('overdue', 'today', 'planned', 'done')),
			)),
			$this->definition('create_crm_activity', 'Create a call, meeting, task or logged e-mail on a deal. A future unfinished event is a next step.', array(
				'id' => array('type' => 'integer'),
				'kind' => array('type' => 'string', 'enum' => array('call', 'meeting', 'task', 'email')),
				'label' => array('type' => 'string'),
				'date' => array('type' => 'string', 'description' => 'YYYY-MM-DD or unix time'),
				'percentage' => array('type' => 'integer', 'description' => '0 for a next step, 100 for a completed step'),
			), array('id', 'kind', 'label', 'date')),
			$this->definition('change_crm_activity_to_done', 'Mark a CRM agenda event done.', array(
				'event_id' => array('type' => 'integer'),
			), array('event_id')),
			$this->definition('update_crm_activity', 'Change a planned CRM step: its date and, for a call, meeting or task, its kind and title. A planned e-mail only changes date. A done step cannot be changed.', array(
				'event_id' => array('type' => 'integer'),
				'date' => array('type' => 'string', 'description' => 'YYYY-MM-DD or unix time'),
				'kind' => array('type' => 'string', 'enum' => array('call', 'meeting', 'task')),
				'label' => array('type' => 'string'),
			), array('event_id', 'date')),
			$this->definition('delete_crm_activity', 'Delete a planned CRM step. A done step is history and cannot be deleted.', array(
				'event_id' => array('type' => 'integer'),
			), array('event_id')),
		);
	}

	/**
	 * @return array<int,array<int,string>>
	 */
	public function getRequiredRights(string $toolName)
	{
		if ($toolName === 'list_crm_activities') {
			return array(array('crm', 'read'));
		}
		return array(array('crm', 'activity'));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	public function writeConfirmationPreview(string $toolName, array $args)
	{
		if ($toolName === 'list_crm_activities') {
			return self::NO_WRITE;
		}
		if ($toolName === 'change_crm_activity_to_done') {
			return 'Mark CRM agenda event '.$this->argInt($args, 'event_id').' as done.';
		}
		if ($toolName === 'update_crm_activity') {
			return 'Change the planned CRM agenda event '.$this->argInt($args, 'event_id').'.';
		}
		if ($toolName === 'delete_crm_activity') {
			return 'Delete the planned CRM agenda event '.$this->argInt($args, 'event_id').'.';
		}
		return 'Create a '.$this->argString($args, 'kind').' "'.$this->argString($args, 'label').'" on CRM deal '.$this->argInt($args, 'id').'.';
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	public function execute(string $toolName, array $args)
	{
		$denied = $this->denyMissingRights($toolName);
		if ($denied !== null) {
			return $denied;
		}
		require_once __DIR__.'/../crmactivity.class.php';
		$activity = new CrmActivity($this->db);
		if ($toolName === 'list_crm_activities') {
			$id = $this->argInt($args, 'id');
			if ($id > 0) {
				$deal = $this->loadDeal($id);
				if ($deal === null) {
					return $this->fail('Deal not found');
				}
				$rows = array();
				foreach ($activity->fetchForOpportunity($id) as $event) {
					$rows[] = array(
						'id' => (int) $event->rowid,
						'label' => (string) $event->label,
						'date' => (string) $event->datep,
						'percent' => (int) $event->percent,
						'type' => (string) $event->code,
						'opportunity_id' => $id,
					);
				}
				return $rows;
			}
			$scope = $this->argString($args, 'scope');
			if ($scope === '') {
				$scope = 'planned';
			}
			$rows = array();
			foreach ($activity->fetchList($this->user, $scope) as $event) {
				$rows[] = array(
					'id' => (int) $event->rowid,
					'label' => (string) $event->label,
					'date' => (string) $event->datep,
					'percent' => (int) $event->percent,
					'opportunity_id' => (int) $event->fk_element,
					'ref' => (string) $event->ref,
					'deal' => (string) $event->deal_label,
					'account' => (string) $event->soc_name,
				);
			}
			return $rows;
		}
		if ($toolName === 'create_crm_activity') {
			$deal = $this->loadDeal($this->argInt($args, 'id'));
			if ($deal === null) {
				return $this->fail('Deal not found');
			}
			$date = $this->argDate($args, 'date');
			if ($date <= 0) {
				return $this->fail('A date is required');
			}
			if (!in_array($this->argString($args, 'kind'), array('call', 'meeting', 'task', 'email'), true)) {
				return $this->fail('kind must be call, meeting, task or email');
			}
			// A next step is 0, a completed one 100; nothing else is meaningful here.
			$percentage = (isset($args['percentage']) && $this->argInt($args, 'percentage') >= 100) ? 100 : 0;
			$eventId = $activity->create($this->user, (int) $deal->id, (int) $deal->fk_soc, (int) $deal->fk_socpeople, (int) $deal->fk_user_resp, $this->argString($args, 'kind'), $this->argString($args, 'label'), $date, $percentage);
			if ($eventId <= 0) {
				return $this->fail($activity->error !== '' ? $activity->error : 'Could not create the event');
			}
			return array(array('event_id' => $eventId, 'opportunity_id' => (int) $deal->id));
		}
		if ($toolName === 'change_crm_activity_to_done') {
			$eventId = $this->argInt($args, 'event_id');
			require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
			$event = new ActionComm($this->db);
			if ($event->fetch($eventId) <= 0) {
				return $this->fail('Event not found');
			}
			if ((string) $event->elementtype !== CrmActivity::ELEMENT_TYPE) {
				return $this->fail('Event is not a CRM step');
			}
			$deal = $this->loadDeal((int) $event->fk_element);
			if ($deal === null) {
				return $this->fail('Deal not found');
			}
			if ($activity->complete($this->user, $eventId) <= 0) {
				return $this->fail($activity->error !== '' ? $activity->error : 'Could not complete the event');
			}
			return array(array('event_id' => $eventId, 'percent' => 100));
		}
		if ($toolName === 'update_crm_activity') {
			$date = $this->argDate($args, 'date');
			if ($date <= 0) {
				return $this->fail('A date is required');
			}
			$eventId = $this->argInt($args, 'event_id');
			if ($activity->updateStep($this->user, $eventId, $this->argString($args, 'kind'), $this->argString($args, 'label'), $date) <= 0) {
				return $this->fail(crmErrorText($activity->error !== '' ? $activity->error : 'Error'));
			}
			return array(array('event_id' => $eventId, 'updated' => 1));
		}
		if ($toolName === 'delete_crm_activity') {
			$eventId = $this->argInt($args, 'event_id');
			if ($activity->deleteStep($this->user, $eventId) <= 0) {
				return $this->fail(crmErrorText($activity->error !== '' ? $activity->error : 'Error'));
			}
			return array(array('event_id' => $eventId, 'deleted' => 1));
		}
		return $this->fail('Unknown tool');
	}
}
