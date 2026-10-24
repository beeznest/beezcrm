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
 * \file    class/mcp/crmopportunitytool.class.php
 * \ingroup crm
 * \brief   MCP tools for deals. Without readall, only the caller's deals are visible.
 */

require_once __DIR__.'/crmmcptool.class.php';

/**
 * Deal tools. The amount is not copied from a linked proposal.
 */
class CrmOpportunityTool extends CrmMcpTool
{
	/**
	 * @return list<array<string,mixed>>
	 */
	public function getDefinitions(): array
	{
		$dealId = array('id' => array('type' => 'integer', 'description' => 'Opportunity id'));
		return array(
			$this->definition('list_crm_opportunities', 'List deals visible to the caller. Use search_thirdparties for accounts.', array(
				'status' => array('type' => 'string', 'enum' => array('open', 'won', 'lost', '')),
				'pipeline_id' => array('type' => 'integer'),
				'owner_id' => array('type' => 'integer', 'description' => 'Dolibarr user id. Ignored without the readall right.'),
				'search' => array('type' => 'string'),
				'category_id' => array('type' => 'integer', 'description' => 'Only deals whose third party is in this category (or below). From v1.2.0.'),
				'limit' => array('type' => 'integer'),
			)),
			$this->definition('get_crm_opportunity', 'Get one deal, its qualification, proposals, history and agenda events.', $dealId, array('id')),
			$this->definition('list_crm_stage_history', 'Stage moves of one deal.', $dealId, array('id')),
			$this->definition('create_crm_opportunity', 'Create a deal in the first open stage. Owner is a numeric user id.', array(
				'label' => array('type' => 'string'),
				'pipeline_id' => array('type' => 'integer'),
				'socid' => array('type' => 'integer', 'description' => 'Third party id'),
				'contact_id' => array('type' => 'integer'),
				'owner_id' => array('type' => 'integer'),
				'amount' => array('type' => 'number'),
				'expected_close' => array('type' => 'string', 'description' => 'YYYY-MM-DD or unix time'),
				'source_id' => array('type' => 'integer'),
				'description' => array('type' => 'string', 'description' => 'First internal note of the deal (from v1.2.0; a description before)'),
			), array('label', 'pipeline_id')),
			$this->definition('update_crm_opportunity', 'Update deal fields. The stage is changed by change_crm_opportunity_stage.', array(
				'id' => array('type' => 'integer'),
				'label' => array('type' => 'string'),
				'socid' => array('type' => 'integer'),
				'contact_id' => array('type' => 'integer'),
				'owner_id' => array('type' => 'integer'),
				'amount' => array('type' => 'number'),
				'expected_close' => array('type' => 'string'),
				'source_id' => array('type' => 'integer'),
			), array('id')),
			$this->definition('change_crm_opportunity_stage', 'Move a deal to a stage of the same pipeline. An open move needs a future next step when that rule is on.', array(
				'id' => array('type' => 'integer'),
				'stage_id' => array('type' => 'integer'),
				'loss_reason_id' => array('type' => 'integer'),
				'note' => array('type' => 'string'),
			), array('id', 'stage_id')),
			$this->definition('change_crm_opportunity_to_won', 'Move the deal to the won stage of its pipeline.', array(
				'id' => array('type' => 'integer'),
				'note' => array('type' => 'string'),
			), array('id')),
			$this->definition('change_crm_opportunity_to_lost', 'Move the deal to the lost stage. A loss reason is required.', array(
				'id' => array('type' => 'integer'),
				'loss_reason_id' => array('type' => 'integer'),
				'note' => array('type' => 'string'),
			), array('id', 'loss_reason_id')),
			$this->definition('delete_crm_opportunity', 'Delete the deal and its CRM rows. Agenda events are kept.', $dealId, array('id')),
			$this->definition('add_crm_proposal_link', 'Link an existing proposal. The deal amount is left unchanged.', array(
				'id' => array('type' => 'integer'),
				'propal_id' => array('type' => 'integer'),
			), array('id', 'propal_id')),
			$this->definition('remove_crm_proposal_link', 'Remove a proposal link.', array(
				'id' => array('type' => 'integer'),
				'propal_id' => array('type' => 'integer'),
			), array('id', 'propal_id')),
			$this->definition('update_crm_opportunity_categories', 'Set the categories (tags) of the third party of a deal. From v1.2.0. Only categories of third parties; a one-answer group keeps one sub-category. Replaces the whole list.', array(
				'id' => array('type' => 'integer', 'description' => 'Opportunity id'),
				'category_ids' => array('type' => 'array', 'items' => array('type' => 'integer')),
			), array('id', 'category_ids')),
			$this->definition('update_crm_qualification_answers', 'Save yes/no answers. Keys are qualification ids.', array(
				'id' => array('type' => 'integer'),
				'answers' => array('type' => 'object', 'description' => 'Map of qualification id to 0 or 1'),
			), array('id', 'answers')),
		);
	}

	/**
	 * @return array<int,array<int,string>>
	 */
	public function getRequiredRights(string $toolName)
	{
		if ($toolName === 'delete_crm_opportunity') {
			return array(array('crm', 'delete'));
		}
		$reads = array('list_crm_opportunities', 'get_crm_opportunity', 'list_crm_stage_history');
		if (in_array($toolName, $reads, true)) {
			return array(array('crm', 'read'));
		}
		return array(array('crm', 'write'));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	public function writeConfirmationPreview(string $toolName, array $args)
	{
		$reads = array('list_crm_opportunities', 'get_crm_opportunity', 'list_crm_stage_history');
		if (in_array($toolName, $reads, true)) {
			return self::NO_WRITE;
		}
		$id = $this->argInt($args, 'id');
		if ($toolName === 'create_crm_opportunity') {
			return 'Create CRM deal "'.$this->argString($args, 'label').'" for amount '.$this->argString($args, 'amount').'.';
		}
		if ($toolName === 'delete_crm_opportunity') {
			return 'Delete CRM deal '.$id.'. Agenda events stay.';
		}
		if ($toolName === 'change_crm_opportunity_stage') {
			return 'Move CRM deal '.$id.' to stage '.$this->argInt($args, 'stage_id').'.';
		}
		if ($toolName === 'change_crm_opportunity_to_won') {
			return 'Mark CRM deal '.$id.' as won.';
		}
		if ($toolName === 'change_crm_opportunity_to_lost') {
			return 'Mark CRM deal '.$id.' as lost with reason '.$this->argInt($args, 'loss_reason_id').'.';
		}
		return 'Update CRM deal '.$id.' via '.$toolName.'.';
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
		require_once __DIR__.'/../opportunity.class.php';
		require_once __DIR__.'/../qualification.class.php';
		if ($toolName === 'list_crm_opportunities') {
			$deal = new Opportunity($this->db);
			$limit = $this->argInt($args, 'limit');
			if ($limit <= 0) {
				$limit = 100;
			}
			$limit = min($limit, 500);
			$rows = array();
			foreach ($deal->fetchList($this->user, $this->argString($args, 'status'), $this->argInt($args, 'pipeline_id'), $this->argInt($args, 'owner_id'), $this->argString($args, 'search'), $limit, 0, 'ref', 'DESC', $this->argInt($args, 'category_id')) as $obj) {
				$rows[] = $this->listRow($obj);
			}
			return $rows;
		}
		if ($toolName === 'get_crm_opportunity') {
			$deal = $this->loadDeal($this->argInt($args, 'id'));
			if ($deal === null) {
				return $this->fail('Deal not found');
			}
			return array($this->detailRow($deal));
		}
		if ($toolName === 'list_crm_stage_history') {
			$deal = $this->loadDeal($this->argInt($args, 'id'));
			if ($deal === null) {
				return $this->fail('Deal not found');
			}
			return $this->historyRows($deal);
		}
		if ($toolName === 'create_crm_opportunity') {
			return $this->createDeal($args);
		}
		if ($toolName === 'update_crm_opportunity') {
			return $this->updateDeal($args);
		}
		if ($toolName === 'change_crm_opportunity_stage') {
			return $this->moveDeal($this->argInt($args, 'id'), $this->argInt($args, 'stage_id'), $this->argInt($args, 'loss_reason_id'), $this->argText($args, 'note'));
		}
		if ($toolName === 'change_crm_opportunity_to_won' || $toolName === 'change_crm_opportunity_to_lost') {
			$deal = $this->loadDeal($this->argInt($args, 'id'));
			if ($deal === null) {
				return $this->fail('Deal not found');
			}
			$stageId = $this->flagStage((int) $deal->fk_pipeline, $toolName === 'change_crm_opportunity_to_won' ? 'won' : 'lost');
			if ($stageId <= 0) {
				return $this->fail('Pipeline has no matching stage');
			}
			return $this->moveDeal((int) $deal->id, $stageId, $this->argInt($args, 'loss_reason_id'), $this->argText($args, 'note'));
		}
		if ($toolName === 'delete_crm_opportunity') {
			$deal = $this->loadDeal($this->argInt($args, 'id'));
			if ($deal === null) {
				return $this->fail('Deal not found');
			}
			if ($deal->delete($this->user) <= 0) {
				return $this->fail($deal->error !== '' ? $deal->error : 'Delete failed');
			}
			return array(array('id' => $this->argInt($args, 'id'), 'deleted' => 1));
		}
		if ($toolName === 'add_crm_proposal_link' || $toolName === 'remove_crm_proposal_link') {
			$deal = $this->loadDeal($this->argInt($args, 'id'));
			if ($deal === null) {
				return $this->fail('Deal not found');
			}
			$propalId = $this->argInt($args, 'propal_id');
			$result = ($toolName === 'add_crm_proposal_link') ? $deal->linkProposal($this->user, $propalId) : $deal->unlinkProposal($propalId);
			if ($result <= 0) {
				return $this->fail($deal->error !== '' ? $deal->error : 'Proposal link failed');
			}
			return array(array('id' => (int) $deal->id, 'propal_id' => $propalId));
		}
		if ($toolName === 'update_crm_opportunity_categories') {
			require_once __DIR__.'/../crmcategory.class.php';
			$ids = array();
			foreach ((isset($args['category_ids']) && is_array($args['category_ids']) ? $args['category_ids'] : array()) as $categoryId) {
				$ids[] = (int) $categoryId;
			}
			$categories = new CrmCategory($this->db);
			$changes = $categories->setForDeal($this->user, $this->argInt($args, 'id'), $ids);
			if ($changes < 0) {
				return $this->fail($categories->error);
			}
			return array(array('id' => $this->argInt($args, 'id'), 'changes' => $changes));
		}
		if ($toolName === 'update_crm_qualification_answers') {
			$deal = $this->loadDeal($this->argInt($args, 'id'));
			if ($deal === null) {
				return $this->fail('Deal not found');
			}
			$answers = array();
			if (isset($args['answers']) && is_array($args['answers'])) {
				foreach ($args['answers'] as $key => $value) {
					$answers[(int) $key] = !empty($value) ? 1 : 0;
				}
			}
			$qual = new Qualification($this->db);
			if ($qual->saveAnswers($this->user, (int) $deal->id, $answers) <= 0) {
				return $this->fail($qual->error !== '' ? $qual->error : 'Qualification save failed');
			}
			$items = $qual->fetchForOpportunity((int) $deal->id);
			return array(array('id' => (int) $deal->id, 'score' => $qual->score($items), 'missing' => $qual->missing($items)));
		}
		return $this->fail('Unknown tool');
	}

	/**
	 * @param object $obj List row
	 * @return array<string,mixed>
	 */
	private function listRow($obj): array
	{
		return array(
			'id' => (int) $obj->rowid,
			'ref' => (string) $obj->ref,
			'label' => (string) $obj->label,
			'status' => (string) $obj->status,
			'socid' => (int) $obj->fk_soc,
			'account' => (string) $obj->soc_name,
			'contact_id' => (int) $obj->fk_socpeople,
			'stage_id' => (int) $obj->fk_stage,
			'stage' => (string) $obj->stage_label,
			'probability' => (int) $obj->probability,
			'amount' => (float) $obj->amount,
			'expected_close' => (string) $obj->date_close_expected,
			'owner_id' => (int) $obj->fk_user_resp,
			'pipeline_id' => (int) $obj->fk_pipeline,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function detailRow(Opportunity $deal): array
	{
		require_once __DIR__.'/../crmactivity.class.php';
		$qual = new Qualification($this->db);
		$items = $qual->fetchForOpportunity((int) $deal->id);
		$activity = new CrmActivity($this->db);
		$events = array();
		foreach ($activity->fetchForOpportunity((int) $deal->id) as $event) {
			$events[] = array(
				'id' => (int) $event->rowid,
				'label' => (string) $event->label,
				'date' => (string) $event->datep,
				'percent' => (int) $event->percent,
				'type' => (string) $event->code,
			);
		}
		$proposals = array();
		foreach ($deal->fetchProposals() as $propal) {
			$proposals[] = array(
				'id' => (int) $propal->rowid,
				'ref' => (string) $propal->ref,
				'total_ht' => (float) $propal->total_ht,
				'status' => (int) $propal->fk_statut,
			);
		}
		return array(
			'id' => (int) $deal->id,
			'ref' => (string) $deal->ref,
			'label' => (string) $deal->label,
			'status' => (string) $deal->status,
			'socid' => (int) $deal->fk_soc,
			'contact_id' => (int) $deal->fk_socpeople,
			'pipeline_id' => (int) $deal->fk_pipeline,
			'stage_id' => (int) $deal->fk_stage,
			'amount' => (float) $deal->amount,
			'expected_close' => $deal->date_close_expected > 0 ? dol_print_date((int) $deal->date_close_expected, '%Y-%m-%d') : '',
			'source_id' => (int) $deal->fk_source,
			'owner_id' => (int) $deal->fk_user_resp,
			'notes' => $this->noteRows((int) $deal->id, (string) $deal->description),
			'categories' => $this->categoryRows((int) $deal->fk_soc),
			'loss_reason_id' => (int) $deal->fk_loss_reason,
			'loss_note' => (string) $deal->loss_note,
			'score' => $qual->score($items),
			'missing' => $qual->missing($items),
			'qualification' => $items,
			'proposals' => $proposals,
			'history' => $this->historyRows($deal),
			'activities' => $events,
		);
	}

	/**
	 * The categories (tags) of the third party of a deal, for get_crm_opportunity.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function categoryRows(int $socId): array
	{
		require_once __DIR__.'/../crmcategory.class.php';
		$rows = array();
		foreach ((new CrmCategory($this->db))->fetchForThirdparties(array($socId))[$socId] ?? array() as $category) {
			$rows[] = array('label' => $category['label'], 'color' => $category['color']);
		}
		return $rows;
	}

	/**
	 * The notes of a deal for get_crm_opportunity (text only: files and versions have their own tools).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function noteRows(int $dealId, string $legacyDescription): array
	{
		require_once __DIR__.'/../crmnote.class.php';
		if (!CrmNote::supported($this->db)) {
			return trim($legacyDescription) !== '' ? array(array('note' => $legacyDescription)) : array();
		}
		$rows = array();
		foreach ((new CrmNote($this->db))->fetchForOpportunity($this->user, $dealId) as $note) {
			$rows[] = array('id' => (int) $note->rowid, 'author_id' => (int) $note->fk_user, 'date' => (string) $note->datec, 'note' => (string) $note->note, 'files' => count($note->files));
		}
		return $rows;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function historyRows(Opportunity $deal): array
	{
		$rows = array();
		foreach ($deal->fetchHistory() as $row) {
			$rows[] = array(
				'id' => (int) $row->rowid,
				'from' => (string) $row->from_label,
				'to' => (string) $row->to_label,
				'date' => (string) $row->datec,
				'user_id' => (int) $row->fk_user,
			);
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	private function createDeal(array $args)
	{
		$deal = new Opportunity($this->db);
		$deal->label = $this->argString($args, 'label');
		$deal->fk_pipeline = $this->argInt($args, 'pipeline_id');
		$deal->fk_soc = $this->argInt($args, 'socid');
		$deal->fk_socpeople = $this->argInt($args, 'contact_id');
		$owner = $this->argInt($args, 'owner_id');
		if ($owner <= 0 || !$this->user->hasRight('crm', 'readall')) {
			$owner = (int) $this->user->id;
		}
		$deal->fk_user_resp = $owner;
		$deal->amount = isset($args['amount']) ? (float) $args['amount'] : 0.0;
		$deal->date_close_expected = $this->argDate($args, 'expected_close');
		$deal->fk_source = $this->argInt($args, 'source_id');
		require_once __DIR__.'/../crmnote.class.php';
		$notesOn = CrmNote::supported($this->db);
		if (!$notesOn) {
			$deal->description = $this->argText($args, 'description');
		}
		$id = $deal->create($this->user);
		if ($id <= 0) {
			return $this->fail($deal->error !== '' ? $deal->error : 'Create failed');
		}
		if ($notesOn && $this->argText($args, 'description') !== '') {
			(new CrmNote($this->db))->add($this->user, $id, $this->argText($args, 'description'));
		}
		return array(array('id' => $id, 'ref' => (string) $deal->ref));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	private function updateDeal(array $args)
	{
		$deal = $this->loadDeal($this->argInt($args, 'id'));
		if ($deal === null) {
			return $this->fail('Deal not found');
		}
		if (isset($args['label'])) {
			$deal->label = $this->argString($args, 'label');
		}
		if (isset($args['socid'])) {
			$deal->fk_soc = $this->argInt($args, 'socid');
		}
		if (isset($args['contact_id'])) {
			$deal->fk_socpeople = $this->argInt($args, 'contact_id');
		}
		if (isset($args['owner_id'])) {
			$owner = $this->argInt($args, 'owner_id');
			if ($owner <= 0 || !$this->user->hasRight('crm', 'readall')) {
				$owner = (int) $this->user->id;
			}
			$deal->fk_user_resp = $owner;
		}
		if (isset($args['amount'])) {
			$deal->amount = (float) $args['amount'];
		}
		if (isset($args['expected_close'])) {
			$deal->date_close_expected = $this->argDate($args, 'expected_close');
		}
		if (isset($args['source_id'])) {
			$deal->fk_source = $this->argInt($args, 'source_id');
		}
		if ($deal->update($this->user) <= 0) {
			return $this->fail($deal->error !== '' ? $deal->error : 'Update failed');
		}
		return array(array('id' => (int) $deal->id, 'ref' => (string) $deal->ref));
	}

	/**
	 * @return array<int|string,mixed>
	 */
	private function moveDeal(int $id, int $stageId, int $lossReasonId, string $note)
	{
		$deal = $this->loadDeal($id);
		if ($deal === null) {
			return $this->fail('Deal not found');
		}
		if ($deal->moveToStage($this->user, $stageId, $lossReasonId, $note) <= 0) {
			return $this->fail(crmErrorText($deal->error));
		}
		return array(array('id' => (int) $deal->id, 'stage_id' => (int) $deal->fk_stage, 'status' => (string) $deal->status));
	}

	private function flagStage(int $pipelineId, string $flag): int
	{
		$column = ($flag === 'won') ? 'is_won' : 'is_lost';
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'crm_stage';
		$sql .= ' WHERE fk_pipeline = '.((int) $pipelineId).' AND '.$column.' = 1 AND active = 1';
		$sql .= ' ORDER BY position ASC';
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}
		return 0;
	}
}
