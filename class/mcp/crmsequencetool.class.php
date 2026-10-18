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
 * \file    class/mcp/crmsequencetool.class.php
 * \ingroup crm
 * \brief   MCP tools for automated outreach. Mail goes to the deal contact only.
 */

require_once __DIR__.'/crmmcptool.class.php';

/**
 * Cadence tools. A missing contact address does not advance the step.
 */
class CrmSequenceTool extends CrmMcpTool
{
	/**
	 * @return list<array<string,mixed>>
	 */
	public function getDefinitions(): array
	{
		return array(
			$this->definition('list_crm_sequences', 'List automated outreach.', array()),
			$this->definition('get_crm_sequence', 'Get one sequence with its steps, enrolments and journal.', array(
				'id' => array('type' => 'integer'),
			), array('id')),
			$this->definition('create_crm_sequence', 'Create a sequence. It sends nothing until CRM_SEQUENCES_ON is on.', array(
				'label' => array('type' => 'string'),
				'active' => array('type' => 'integer'),
				'stop_on_reply' => array('type' => 'integer'),
				'stop_on_won' => array('type' => 'integer'),
				'stop_on_lost' => array('type' => 'integer'),
			), array('label')),
			$this->definition('update_crm_sequence', 'Change the reference, label, active flag or stop rules of a sequence. Only the fields given are changed. Steps and enrolments are untouched: a running enrolment goes on, and one on an inactive sequence waits until the sequence is active again.', array(
				'id' => array('type' => 'integer'),
				'ref' => array('type' => 'string', 'description' => 'Letters, digits, underscore, dash; unique; stored in capitals.'),
				'label' => array('type' => 'string'),
				'active' => array('type' => 'integer'),
				'stop_on_reply' => array('type' => 'integer'),
				'stop_on_won' => array('type' => 'integer'),
				'stop_on_lost' => array('type' => 'integer'),
			), array('id')),
			$this->definition('add_crm_sequence_step', 'Add a step. delay_days is counted from the enrolment date, not from the previous step.', array(
				'id' => array('type' => 'integer', 'description' => 'Sequence id'),
				'action' => array('type' => 'string', 'enum' => array('email', 'call', 'task')),
				'delay_days' => array('type' => 'integer'),
				'subject' => array('type' => 'string'),
				'body' => array('type' => 'string', 'description' => 'Placeholders: __DEAL__ __REF__ __AMOUNT__ __CONTACT__ __CUSTOMER__'),
				'send_mode' => array('type' => 'string', 'enum' => array('', 'draft', 'send'), 'description' => 'E-mail steps only. draft: the step becomes a planned e-mail for the salesperson to review and send. send: the job sends it (dry-run applies). Empty: follow the global setting CRM_SEQUENCE_EMAIL_MODE.'),
			), array('id', 'action')),
			$this->definition('update_crm_sequence_step', 'Change a step of a sequence. Only the fields given are changed. The step keeps its position. An enrolment already waiting for this step keeps the due date it was given, so a new delay applies to the steps scheduled from now on; the kind, subject, body and send_mode apply the next time the step runs.', array(
				'id' => array('type' => 'integer', 'description' => 'Sequence id'),
				'step_id' => array('type' => 'integer'),
				'action' => array('type' => 'string', 'enum' => array('email', 'call', 'task')),
				'delay_days' => array('type' => 'integer'),
				'subject' => array('type' => 'string'),
				'body' => array('type' => 'string', 'description' => 'Plain text or HTML. Placeholders: __DEAL__ __REF__ __AMOUNT__ __CONTACT__ __CUSTOMER__'),
				'send_mode' => array('type' => 'string', 'enum' => array('', 'draft', 'send'), 'description' => 'E-mail steps only. Empty follows the global setting.'),
			), array('id', 'step_id')),
			$this->definition('delete_crm_sequence_step', 'Delete one step.', array(
				'id' => array('type' => 'integer'),
				'step_id' => array('type' => 'integer'),
			), array('id', 'step_id')),
			$this->definition('add_crm_sequence_enrolment', 'Enrol a deal. The first step is scheduled from now plus its delay.', array(
				'id' => array('type' => 'integer'),
				'opportunity_id' => array('type' => 'integer'),
			), array('id', 'opportunity_id')),
			$this->definition('change_crm_sequence_enrolment_to_stopped', 'Stop one enrolment.', array(
				'enrol_id' => array('type' => 'integer'),
			), array('enrol_id')),
			$this->definition('update_crm_opportunity_replied', 'Stop an enrolment because the contact replied, and move its deal to the reply stage of its pipeline (Replied to outreach) when it is earlier in the pipeline. The module does not read the mailbox.', array(
				'enrol_id' => array('type' => 'integer'),
			), array('enrol_id')),
		);
	}

	/**
	 * @return array<int,array<int,string>>
	 */
	public function getRequiredRights(string $toolName)
	{
		if ($toolName === 'list_crm_sequences' || $toolName === 'get_crm_sequence') {
			return array(array('crm', 'read'));
		}
		if ($toolName === 'update_crm_opportunity_replied') {
			// It moves the deal to another stage: a write on deals, as well as the outreach right.
			return array(array('crm', 'sequence'), array('crm', 'write'));
		}
		return array(array('crm', 'sequence'));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	public function writeConfirmationPreview(string $toolName, array $args)
	{
		if ($toolName === 'list_crm_sequences' || $toolName === 'get_crm_sequence') {
			return self::NO_WRITE;
		}
		if ($toolName === 'create_crm_sequence') {
			return 'Create CRM sequence "'.$this->argString($args, 'label').'".';
		}
		if ($toolName === 'update_crm_sequence') {
			return 'Update CRM sequence '.$this->argInt($args, 'id').'.';
		}
		if ($toolName === 'update_crm_sequence_step') {
			return 'Update step '.$this->argInt($args, 'step_id').' of CRM sequence '.$this->argInt($args, 'id').'.';
		}
		if ($toolName === 'add_crm_sequence_enrolment') {
			return 'Enrol CRM deal '.$this->argInt($args, 'opportunity_id').' on sequence '.$this->argInt($args, 'id').'.';
		}
		if ($toolName === 'update_crm_opportunity_replied') {
			return 'Mark enrolment '.$this->argInt($args, 'enrol_id').' as replied and stop it.';
		}
		if ($toolName === 'change_crm_sequence_enrolment_to_stopped') {
			return 'Stop CRM sequence enrolment '.$this->argInt($args, 'enrol_id').'.';
		}
		return 'Update CRM sequence '.$this->argInt($args, 'id').'.';
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
		require_once __DIR__.'/../sequence.class.php';
		$sequence = new Sequence($this->db);
		if ($toolName === 'list_crm_sequences') {
			$rows = array();
			foreach ($sequence->fetchAll() as $obj) {
				$rows[] = array(
					'id' => (int) $obj->rowid,
					'ref' => (string) $obj->ref,
					'label' => (string) $obj->label,
					'active' => (int) $obj->active,
					'steps' => (int) $obj->nb_step,
					'enrolments' => (int) $obj->nb_enrol,
				);
			}
			return $rows;
		}
		if ($toolName === 'get_crm_sequence') {
			if ($sequence->fetch($this->argInt($args, 'id')) <= 0) {
				return $this->fail('Sequence not found');
			}
			return array($this->detail($sequence));
		}
		if ($toolName === 'create_crm_sequence') {
			$sequence->label = $this->argString($args, 'label');
			$sequence->active = isset($args['active']) ? $this->argInt($args, 'active') : 1;
			$sequence->stop_on_reply = isset($args['stop_on_reply']) ? $this->argInt($args, 'stop_on_reply') : 1;
			$sequence->stop_on_won = isset($args['stop_on_won']) ? $this->argInt($args, 'stop_on_won') : 1;
			$sequence->stop_on_lost = isset($args['stop_on_lost']) ? $this->argInt($args, 'stop_on_lost') : 1;
			$id = $sequence->create($this->user);
			if ($id <= 0) {
				return $this->fail($sequence->error !== '' ? $sequence->error : 'Create failed');
			}
			return array(array('id' => $id, 'ref' => $sequence->ref));
		}
		if ($sequence->fetch($this->argInt($args, 'id')) <= 0 && $toolName !== 'change_crm_sequence_enrolment_to_stopped' && $toolName !== 'update_crm_opportunity_replied') {
			return $this->fail('Sequence not found');
		}
		if ($toolName === 'update_crm_sequence') {
			if (isset($args['ref'])) {
				$sequence->ref = $this->argString($args, 'ref');
			}
			if (isset($args['label'])) {
				$sequence->label = $this->argString($args, 'label');
			}
			foreach (array('active', 'stop_on_reply', 'stop_on_won', 'stop_on_lost') as $flag) {
				if (isset($args[$flag])) {
					$sequence->$flag = $this->argInt($args, $flag) ? 1 : 0;
				}
			}
			if ($sequence->update() <= 0) {
				return $this->fail(crmErrorText($sequence->error !== '' ? $sequence->error : 'Error'));
			}
			return array($this->detail($sequence));
		}
		if ($toolName === 'add_crm_sequence_step') {
			$action = $this->argString($args, 'action');
			if (!in_array($action, array('email', 'call', 'task'), true)) {
				return $this->fail('Step action must be email, call or task');
			}
			$stepId = $sequence->addStep($action, $this->argInt($args, 'delay_days'), $this->argString($args, 'subject'), $this->argBody($args, 'body'), $this->argString($args, 'send_mode'));
			if ($stepId <= 0) {
				return $this->fail($sequence->error !== '' ? $sequence->error : 'Could not add the step');
			}
			return array(array('step_id' => $stepId, 'sequence_id' => (int) $sequence->id));
		}
		if ($toolName === 'update_crm_sequence_step') {
			$current = $sequence->fetchStep($this->argInt($args, 'step_id'));
			if ($current === null) {
				return $this->fail('Step not found');
			}
			$result = $sequence->updateStep(
				(int) $current->rowid,
				isset($args['action']) ? $this->argString($args, 'action') : (string) $current->action,
				isset($args['delay_days']) ? $this->argInt($args, 'delay_days') : (int) $current->delay_days,
				isset($args['subject']) ? $this->argString($args, 'subject') : (string) $current->subject,
				isset($args['body']) ? $this->argBody($args, 'body') : (string) $current->body,
				isset($args['send_mode']) ? $this->argString($args, 'send_mode') : (string) ($current->send_mode ?? '')
			);
			if ($result <= 0) {
				return $this->fail(crmErrorText($sequence->error !== '' ? $sequence->error : 'Error'));
			}
			return array(array('step_id' => (int) $current->rowid, 'sequence_id' => (int) $sequence->id));
		}
		if ($toolName === 'delete_crm_sequence_step') {
			if ($sequence->deleteStep($this->argInt($args, 'step_id')) <= 0) {
				return $this->fail($sequence->error !== '' ? $sequence->error : 'Could not delete the step');
			}
			return array(array('step_id' => $this->argInt($args, 'step_id'), 'deleted' => 1));
		}
		if ($toolName === 'add_crm_sequence_enrolment') {
			$deal = $this->loadDeal($this->argInt($args, 'opportunity_id'));
			if ($deal === null) {
				return $this->fail('Deal not found');
			}
			$enrolId = $sequence->enrol((int) $deal->id);
			if ($enrolId <= 0) {
				return $this->fail($sequence->error !== '' ? $sequence->error : 'Enrolment failed');
			}
			return array(array('enrol_id' => $enrolId, 'opportunity_id' => (int) $deal->id));
		}
		if ($toolName === 'change_crm_sequence_enrolment_to_stopped' || $toolName === 'update_crm_opportunity_replied') {
			$enrolId = $this->argInt($args, 'enrol_id');
			if (!$this->enrolVisible($enrolId)) {
				return $this->fail('Enrolment not found');
			}
			$reason = ($toolName === 'update_crm_opportunity_replied') ? 'replied' : 'manual';
			if (($reason === 'replied' ? $sequence->markReplied($this->user, $enrolId) : $sequence->stopEnrolment($enrolId, $reason)) <= 0) {
				return $this->fail($sequence->error !== '' ? $sequence->error : 'Could not stop the enrolment');
			}
			return array(array('enrol_id' => $enrolId, 'status' => 'stopped', 'stop_reason' => $reason));
		}
		return $this->fail('Unknown tool');
	}

	/**
	 * @return array<string,mixed>
	 */
	private function detail(Sequence $sequence): array
	{
		$steps = array();
		foreach ($sequence->fetchSteps() as $step) {
			$steps[] = array(
				'id' => (int) $step->rowid,
				'position' => (int) $step->position,
				'action' => (string) $step->action,
				'delay_days' => (int) $step->delay_days,
				'subject' => (string) $step->subject,
				'body' => (string) $step->body,
				'send_mode' => (string) ($step->send_mode ?? ''),
			);
		}
		$enrolments = array();
		foreach ($sequence->fetchEnrolments($this->user) as $enrol) {
			$enrolments[] = array(
				'id' => (int) $enrol->rowid,
				'opportunity_id' => (int) $enrol->fk_opportunity,
				'ref' => (string) $enrol->ref,
				'deal' => (string) $enrol->deal_label,
				'status' => (string) $enrol->status,
				'current_step' => (int) $enrol->current_step,
				'date_next' => (string) $enrol->date_next,
				'stop_reason' => (string) $enrol->stop_reason,
			);
		}
		$enrolments = $this->filterEnrolments($enrolments);
		$visible = array();
		foreach ($enrolments as $enrol) {
			$visible[(int) $enrol['id']] = true;
		}
		$log = array();
		foreach ($sequence->fetchLog($this->user) as $line) {
			if (!isset($visible[(int) $line->fk_enrol])) {
				continue;
			}
			$log[] = array(
				'id' => (int) $line->rowid,
				'enrol_id' => (int) $line->fk_enrol,
				'date' => (string) $line->date_send,
				'email' => (string) $line->email,
				'subject' => (string) $line->subject,
				'status' => (string) $line->status,
				'error' => (string) $line->error_msg,
			);
		}
		return array(
			'id' => (int) $sequence->id,
			'ref' => $sequence->ref,
			'label' => $sequence->label,
			'active' => (int) $sequence->active,
			'stop_on_reply' => (int) $sequence->stop_on_reply,
			'stop_on_won' => (int) $sequence->stop_on_won,
			'stop_on_lost' => (int) $sequence->stop_on_lost,
			'steps' => $steps,
			'enrolments' => $enrolments,
			'log' => $log,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $enrolments
	 * @return array<int,array<string,mixed>>
	 */
	private function filterEnrolments(array $enrolments): array
	{
		if ($this->user->hasRight('crm', 'readall')) {
			return $enrolments;
		}
		$kept = array();
		foreach ($enrolments as $enrol) {
			if ($this->enrolVisible((int) $enrol['id'])) {
				$kept[] = $enrol;
			}
		}
		return $kept;
	}

	private function enrolVisible(int $enrolId): bool
	{
		return (new Sequence($this->db))->enrolmentVisible($this->user, $enrolId);
	}
}
