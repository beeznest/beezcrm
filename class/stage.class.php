<?php
declare(strict_types=1);

/**
 * \file    class/stage.class.php
 * \ingroup crm
 * \brief   One column of a pipeline. Probability lives here, not on the deal.
 */

/**
 * Pipeline stage.
 */
class Stage
{
	/** @var DoliDB */
	public $db;
	/** @var int */
	public $id = 0;
	/** @var int */
	public $entity = 0;
	/** @var int */
	public $fk_pipeline = 0;
	/** @var string */
	public $label = '';
	/** @var int */
	public $position = 0;
	/** @var string */
	public $color = '#6c757d';
	/** @var int */
	public $probability = 0;
	/** @var int */
	public $rotting_days = 15;
	/** @var string pipeline|upside|commit|closed|lost */
	public $forecast_category = 'pipeline';
	/** @var int */
	public $is_won = 0;
	/** @var int */
	public $is_lost = 0;
	/** @var int */
	public $active = 1;
	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param int $id Stage id
	 * @return int 1 if found, 0 if not, -1 on error
	 */
	public function fetch(int $id): int
	{
		$sql = 'SELECT * FROM '.$this->db->prefix().'crm_stage WHERE rowid = '.((int) $id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}
		global $conf;
		if ((int) $obj->entity !== (int) $conf->entity) {
			return 0;
		}
		$this->setFromObject($obj);
		return 1;
	}

	/**
	 * Won and lost stages are closed. Every other stage is an open column.
	 */
	public function isClosed(): bool
	{
		return ((int) $this->is_won === 1 || (int) $this->is_lost === 1);
	}

	/**
	 * Stages of one pipeline, ordered for the board.
	 *
	 * @return array<int,Stage>
	 */
	public function fetchAll(int $pipelineId, bool $onlyActive = false): array
	{
		global $conf;
		$list = array();
		$sql = 'SELECT * FROM '.$this->db->prefix().'crm_stage';
		$sql .= ' WHERE entity = '.((int) $conf->entity).' AND fk_pipeline = '.((int) $pipelineId).($onlyActive ? ' AND active = 1' : '');
		$sql .= ' ORDER BY position ASC, rowid ASC';
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$stage = new Stage($this->db);
				$stage->setFromObject($obj);
				$list[] = $stage;
			}
		}
		return $list;
	}

	/**
	 * @return int Row id, or -1
	 */
	public function create(): int
	{
		global $conf;
		if (!$this->validate()) {
			return -1;
		}
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_stage (entity, fk_pipeline, label, position, color, probability, rotting_days, forecast_category, is_won, is_lost, active) VALUES (';
		$sql .= ((int) $conf->entity).', '.((int) $this->fk_pipeline).", '".$this->db->escape($this->label)."', ".((int) $this->position);
		$sql .= ", '".$this->db->escape($this->color)."', ".((int) $this->probability).', '.((int) $this->rotting_days);
		$sql .= ", '".$this->db->escape($this->forecast_category)."', ".((int) $this->is_won).', '.((int) $this->is_lost).', '.((int) $this->active).')';
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->id = (int) $this->db->last_insert_id($this->db->prefix().'crm_stage');
		return $this->id;
	}

	/**
	 * A stage needs a label, and cannot be both won and lost.
	 */
	private function validate(): bool
	{
		if (trim($this->label) === '') {
			$this->error = 'CrmErrorLabelRequired';
			return false;
		}
		if ($this->is_won && $this->is_lost) {
			$this->error = 'CrmErrorStageWonAndLost';
			return false;
		}
		return true;
	}

	/**
	 * @return int 1 if OK, -1 on error
	 */
	public function update(): int
	{
		if (!$this->validate()) {
			return -1;
		}
		$sql = 'UPDATE '.$this->db->prefix().'crm_stage SET';
		$sql .= " label = '".$this->db->escape($this->label)."'";
		$sql .= ', position = '.((int) $this->position);
		$sql .= ", color = '".$this->db->escape($this->color)."'";
		$sql .= ', probability = '.((int) $this->probability);
		$sql .= ', rotting_days = '.((int) $this->rotting_days);
		$sql .= ", forecast_category = '".$this->db->escape($this->forecast_category)."'";
		$sql .= ', is_won = '.((int) $this->is_won);
		$sql .= ', is_lost = '.((int) $this->is_lost);
		$sql .= ', active = '.((int) $this->active);
		$sql .= ' WHERE rowid = '.((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Refuse to delete a stage that still holds deals.
	 *
	 * @return int 1 if deleted, -1 if refused or on error
	 */
	public function delete(): int
	{
		$sqlCount = 'SELECT COUNT(rowid) as nb FROM '.$this->db->prefix().'crm_opportunity WHERE fk_stage = '.((int) $this->id);
		$resql = $this->db->query($sqlCount);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj && (int) $obj->nb > 0) {
				$this->error = 'CrmErrorStageNotEmpty';
				return -1;
			}
		}
		$sqlHistory = 'SELECT COUNT(rowid) as nb FROM '.$this->db->prefix().'crm_stage_history WHERE fk_stage_from = '.((int) $this->id).' OR fk_stage_to = '.((int) $this->id);
		$resHistory = $this->db->query($sqlHistory);
		if ($resHistory) {
			$obj = $this->db->fetch_object($resHistory);
			if ($obj && (int) $obj->nb > 0) {
				$this->error = 'CrmErrorStageInHistory';
				return -1;
			}
		}
		if (!$this->db->query('DELETE FROM '.$this->db->prefix().'crm_stage WHERE rowid = '.((int) $this->id))) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * @param object $obj Database row
	 */
	private function setFromObject($obj): void
	{
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->fk_pipeline = (int) $obj->fk_pipeline;
		$this->label = (string) $obj->label;
		$this->position = (int) $obj->position;
		$this->color = (string) $obj->color;
		$this->probability = (int) $obj->probability;
		$this->rotting_days = (int) $obj->rotting_days;
		$this->forecast_category = (string) $obj->forecast_category;
		$this->is_won = (int) $obj->is_won;
		$this->is_lost = (int) $obj->is_lost;
		$this->active = (int) $obj->active;
	}
}
