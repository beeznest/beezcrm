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
 * \file    class/mcp/crmdictionarytool.class.php
 * \ingroup crm
 * \brief   MCP tools for pipelines, stages and dictionaries.
 */

require_once __DIR__.'/crmmcptool.class.php';

/**
 * Read dictionaries with the read right. Change them with the setup right.
 */
class CrmDictionaryTool extends CrmMcpTool
{
	/**
	 * @return list<array<string,mixed>>
	 */
	public function getDefinitions(): array
	{
		$id = array('id' => array('type' => 'integer'));
		$pipeline = array('pipeline_id' => array('type' => 'integer', 'description' => 'Pipeline id'));
		return array(
			$this->definition('list_crm_pipelines', 'List CRM pipelines. Call it before creating a deal: pipeline_id comes from here.', array('active_only' => array('type' => 'boolean'))),
			$this->definition('list_crm_stages', 'List stages of one pipeline, in board order, with probability, forecast category and won/lost flags. Stage ids are the values accepted when moving a deal.', $pipeline, array('pipeline_id')),
			$this->definition('list_crm_sources', 'List deal sources. Source ids are the values accepted as source_id when creating or updating a deal.', array()),
			$this->definition('list_crm_loss_reasons', 'List loss reasons. A reason id is required when marking a deal lost.', array()),
			$this->definition('list_crm_qualifications', 'List the active qualification checklist (id, code, label, points). Ids are the keys used by update_crm_qualification_answers.', array()),
			$this->definition('create_or_update_crm_pipeline', 'Create a pipeline, or rename and activate or deactivate one. The ref is fixed at creation. A new pipeline is empty unless copy_stages_from names a pipeline to copy the stages from; it needs an open, a won and a lost stage before deals can be created in it. The last active pipeline cannot be deactivated.', array(
				'id' => array('type' => 'integer', 'description' => 'Pipeline id. Omit to create.'),
				'ref' => array('type' => 'string', 'description' => 'Reference, required when creating: letters, digits, underscore, dash.'),
				'label' => array('type' => 'string'),
				'active' => array('type' => 'integer', 'description' => '1 active (default), 0 inactive. Only used when updating.'),
				'copy_stages_from' => array('type' => 'integer', 'description' => 'When creating: id of the pipeline whose stages are copied.'),
			), array('label')),
			$this->definition('create_or_update_crm_stage', 'Create or update a pipeline stage. Probability lives on the stage.', array(
				'id' => array('type' => 'integer', 'description' => 'Stage id. Omit to create.'),
				'pipeline_id' => array('type' => 'integer'),
				'label' => array('type' => 'string'),
				'position' => array('type' => 'integer'),
				'color' => array('type' => 'string'),
				'probability' => array('type' => 'integer'),
				'rotting_days' => array('type' => 'integer', 'description' => '0 disables rotting.'),
				'forecast_category' => array('type' => 'string', 'enum' => array('pipeline', 'upside', 'commit', 'closed', 'lost')),
				'is_won' => array('type' => 'integer'),
				'is_lost' => array('type' => 'integer'),
				'is_reply' => array('type' => 'integer', 'description' => '1 for the stage a deal moves to when the prospect replies (one per pipeline)'),
				'active' => array('type' => 'integer'),
			), array('pipeline_id', 'label')),
			$this->definition('delete_crm_stage', 'Delete a stage that contains no deals.', $id, array('id')),
			$this->definition('create_or_update_crm_source', 'Create or update a source.', $this->dictProps(), array('code', 'label')),
			$this->definition('create_or_update_crm_loss_reason', 'Create or update a loss reason.', $this->dictProps(), array('code', 'label')),
			$this->definition('create_or_update_crm_qualification', 'Create or update a qualification item. Points are summed, capped at 100.', array(
				'id' => array('type' => 'integer'),
				'code' => array('type' => 'string'),
				'label' => array('type' => 'string'),
				'points' => array('type' => 'integer'),
				'position' => array('type' => 'integer'),
				'active' => array('type' => 'integer'),
			), array('code', 'label')),
		);
	}

	/**
	 * @return array<int,array<int,string>>
	 */
	public function getRequiredRights(string $toolName)
	{
		$writes = array('create_or_update_crm_pipeline', 'create_or_update_crm_stage', 'delete_crm_stage', 'create_or_update_crm_source', 'create_or_update_crm_loss_reason', 'create_or_update_crm_qualification');
		if (in_array($toolName, $writes, true)) {
			return array(array('crm', 'setup'));
		}
		return array(array('crm', 'read'));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	public function writeConfirmationPreview(string $toolName, array $args)
	{
		if (strpos($toolName, 'list_crm_') === 0) {
			return self::NO_WRITE;
		}
		if ($toolName === 'create_or_update_crm_pipeline') {
			$id = $this->argInt($args, 'id');
			return ($id > 0 ? 'Update CRM pipeline '.$id : 'Create CRM pipeline "'.$this->argString($args, 'ref').'"').' with label "'.$this->argString($args, 'label').'".';
		}
		if ($toolName === 'delete_crm_stage') {
			return 'Delete CRM stage '.$this->argInt($args, 'id').'. Refused when the stage still contains deals.';
		}
		$label = $this->argString($args, 'label');
		return 'Save CRM dictionary entry "'.$label.'" via '.$toolName.'.';
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
		require_once __DIR__.'/../pipeline.class.php';
		require_once __DIR__.'/../stage.class.php';
		if ($toolName === 'list_crm_pipelines') {
			$pipeline = new Pipeline($this->db);
			$onlyActive = !isset($args['active_only']) || !empty($args['active_only']);
			$rows = array();
			foreach ($pipeline->fetchAll($onlyActive) as $one) {
				$rows[] = array('id' => (int) $one->id, 'ref' => $one->ref, 'label' => $one->label, 'active' => (int) $one->active);
			}
			return $rows;
		}
		if ($toolName === 'list_crm_stages') {
			$stage = new Stage($this->db);
			$rows = array();
			foreach ($stage->fetchAll($this->argInt($args, 'pipeline_id')) as $one) {
				$rows[] = $this->stageRow($one);
			}
			return $rows;
		}
		if ($toolName === 'list_crm_sources') {
			return $this->dictRows('source');
		}
		if ($toolName === 'list_crm_loss_reasons') {
			return $this->dictRows('loss_reason');
		}
		if ($toolName === 'list_crm_qualifications') {
			require_once __DIR__.'/../qualification.class.php';
			$rows = array();
			foreach ((new Qualification($this->db))->fetchItems(false) as $one) {
				$rows[] = array('id' => (int) $one->rowid, 'code' => (string) $one->code, 'label' => (string) $one->label, 'points' => (int) $one->points, 'position' => (int) $one->position, 'active' => (int) $one->active);
			}
			return $rows;
		}
		if ($toolName === 'create_or_update_crm_pipeline') {
			$pipeline = new Pipeline($this->db);
			$id = $pipeline->save($this->user, $this->argInt($args, 'id'), $this->argString($args, 'ref'), $this->argString($args, 'label'), isset($args['active']) ? $this->argInt($args, 'active') : 1, $this->argInt($args, 'copy_stages_from'));
			if ($id <= 0) {
				return $this->fail(crmErrorText($pipeline->error !== '' ? $pipeline->error : 'Error'));
			}
			return array(array('id' => $id, 'ref' => $pipeline->ref, 'label' => $pipeline->label, 'active' => (int) $pipeline->active));
		}
		if ($toolName === 'create_or_update_crm_stage') {
			return $this->saveStage($args);
		}
		if ($toolName === 'delete_crm_stage') {
			$stage = new Stage($this->db);
			if ($stage->fetch($this->argInt($args, 'id')) <= 0) {
				return $this->fail('Stage not found');
			}
			if ($stage->delete() <= 0) {
				return $this->fail(crmErrorText($stage->error));
			}
			return array(array('id' => (int) $stage->id, 'deleted' => 1));
		}
		if ($toolName === 'create_or_update_crm_source' || $toolName === 'create_or_update_crm_loss_reason') {
			$which = ($toolName === 'create_or_update_crm_source') ? 'source' : 'loss_reason';
			$id = crmSaveDictionary($this->db, $which, $this->argInt($args, 'id'), $this->argString($args, 'code'), $this->argString($args, 'label'), $this->argInt($args, 'position'), isset($args['active']) ? $this->argInt($args, 'active') : ($this->argInt($args, 'id') > 0 ? -1 : 1));
			if ($id <= 0) {
				return $this->fail($id === -2 ? crmErrorText('CrmErrorCodeExists') : 'Could not save the dictionary entry');
			}
			return array(array('id' => $id));
		}
		if ($toolName === 'create_or_update_crm_qualification') {
			$id = crmSaveQualificationItem($this->db, $this->argInt($args, 'id'), $this->argString($args, 'code'), $this->argString($args, 'label'), $this->argInt($args, 'points'), $this->argInt($args, 'position'), isset($args['active']) ? $this->argInt($args, 'active') : ($this->argInt($args, 'id') > 0 ? -1 : 1));
			if ($id <= 0) {
				return $this->fail($id === -2 ? crmErrorText('CrmErrorCodeExists') : 'Could not save the qualification item');
			}
			return array(array('id' => $id));
		}
		return $this->fail('Unknown tool');
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private function dictProps(): array
	{
		return array(
			'id' => array('type' => 'integer'),
			'code' => array('type' => 'string'),
			'label' => array('type' => 'string'),
			'position' => array('type' => 'integer'),
			'active' => array('type' => 'integer'),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function dictRows(string $which): array
	{
		$rows = array();
		foreach (crmFetchDictionary($this->db, $which, false) as $obj) {
			$rows[] = array(
				'id' => (int) $obj->rowid,
				'code' => (string) $obj->code,
				'label' => (string) $obj->label,
				'position' => (int) $obj->position,
				'active' => (int) $obj->active,
			);
		}
		return $rows;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function stageRow(Stage $stage): array
	{
		return array(
			'id' => (int) $stage->id,
			'pipeline_id' => (int) $stage->fk_pipeline,
			'label' => $stage->label,
			'position' => (int) $stage->position,
			'color' => $stage->color,
			'probability' => (int) $stage->probability,
			'rotting_days' => (int) $stage->rotting_days,
			'forecast_category' => $stage->forecast_category,
			'is_won' => (int) $stage->is_won,
			'is_lost' => (int) $stage->is_lost,
			'is_reply' => (int) $stage->is_reply,
			'active' => (int) $stage->active,
		);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	private function saveStage(array $args)
	{
		$stage = new Stage($this->db);
		$id = $this->argInt($args, 'id');
		if ($id > 0 && $stage->fetch($id) <= 0) {
			return $this->fail('Stage not found');
		}
		if ($id <= 0) {
			// A stage never changes pipeline: the one of an existing stage is kept, the one of a new stage must exist.
			$stage->fk_pipeline = $this->argInt($args, 'pipeline_id');
			$resql = $this->db->query('SELECT rowid FROM '.$this->db->prefix().'crm_pipeline WHERE rowid = '.((int) $stage->fk_pipeline).' AND entity = '.((int) $this->conf->entity));
			if (!$resql || $this->db->num_rows($resql) === 0) {
				return $this->fail('Pipeline not found');
			}
		}
		$stage->label = $this->argString($args, 'label');
		if (isset($args['position'])) {
			$stage->position = $this->argInt($args, 'position');
		}
		if (isset($args['color']) && $this->argString($args, 'color') !== '') {
			$stage->color = $this->argString($args, 'color');
		}
		if (isset($args['probability'])) {
			$stage->probability = $this->argInt($args, 'probability');
		}
		if (isset($args['rotting_days'])) {
			$stage->rotting_days = $this->argInt($args, 'rotting_days');
		}
		if (isset($args['forecast_category']) && $this->argString($args, 'forecast_category') !== '') {
			$stage->forecast_category = $this->argString($args, 'forecast_category');
		}
		if (isset($args['is_won'])) {
			$stage->is_won = $this->argInt($args, 'is_won') ? 1 : 0;
		}
		if (isset($args['is_lost'])) {
			$stage->is_lost = $this->argInt($args, 'is_lost') ? 1 : 0;
		}
		if (isset($args['is_reply'])) {
			$stage->is_reply = $this->argInt($args, 'is_reply') ? 1 : 0;
		}
		if (isset($args['active'])) {
			$stage->active = $this->argInt($args, 'active') ? 1 : 0;
		}
		$result = ($id > 0) ? $stage->update() : $stage->create();
		if ($result <= 0) {
			return $this->fail($stage->error !== '' ? $stage->error : 'Could not save the stage');
		}
		return array($this->stageRow($stage));
	}
}
