<?php
declare(strict_types=1);

/**
 * \file    class/pipeline.class.php
 * \ingroup crm
 */

require_once __DIR__.'/stage.class.php';

/**
 * Named pipeline.
 */
class Pipeline
{
	/** @var DoliDB */
	public $db;
	/** @var int */
	public $id = 0;
	/** @var string */
	public $ref = '';
	/** @var string */
	public $label = '';
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
	 * @param int $id Pipeline id
	 * @return int 1 if found, 0 if not, -1 on error
	 */
	public function fetch(int $id): int
	{
		$sql = 'SELECT * FROM '.$this->db->prefix().'crm_pipeline WHERE rowid = '.((int) $id);
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
		$this->id = (int) $obj->rowid;
		$this->ref = (string) $obj->ref;
		$this->label = (string) $obj->label;
		$this->active = (int) $obj->active;
		return 1;
	}

	/**
	 * Active pipelines of the entity.
	 *
	 * @param bool $onlyActive When false, inactive pipelines are included
	 * @return array<int,Pipeline>
	 */
	public function fetchAll(bool $onlyActive = true): array
	{
		global $conf;
		$list = array();
		$sql = 'SELECT * FROM '.$this->db->prefix().'crm_pipeline WHERE entity = '.((int) $conf->entity);
		if ($onlyActive) {
			$sql .= ' AND active = 1';
		}
		$sql .= ' ORDER BY label ASC';
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$pipeline = new Pipeline($this->db);
				$pipeline->id = (int) $obj->rowid;
				$pipeline->ref = (string) $obj->ref;
				$pipeline->label = (string) $obj->label;
				$pipeline->active = (int) $obj->active;
				$list[] = $pipeline;
			}
		}
		return $list;
	}

	/**
	 * @param User $user Creator
	 * @return int Row id, or -1
	 */
	public function create(User $user): int
	{
		global $conf;
		$now = $this->db->idate(dol_now());
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_pipeline (entity, ref, label, active, datec, fk_user_creat) VALUES (';
		$sql .= ((int) $conf->entity).", '".$this->db->escape($this->ref)."', '".$this->db->escape($this->label)."', ".((int) $this->active).", '".$now."', ".((int) $user->id).')';
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->id = (int) $this->db->last_insert_id($this->db->prefix().'crm_pipeline');
		return $this->id;
	}

	/**
	 * Number of active pipelines of the entity.
	 */
	public function activeCount(): int
	{
		return count($this->fetchAll(true));
	}

	/**
	 * True when a pipeline of the entity already uses this ref.
	 */
	public function refExists(string $ref): bool
	{
		global $conf;
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'crm_pipeline';
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND ref = '".$this->db->escape($ref)."'";
		$resql = $this->db->query($sql);
		return ($resql && $this->db->num_rows($resql) > 0);
	}

	/**
	 * True when the pipeline has an active stage that is neither won nor lost, the one a new deal starts in.
	 */
	public function hasOpenStage(): bool
	{
		foreach ((new Stage($this->db))->fetchAll((int) $this->id) as $stage) {
			if ((int) $stage->active === 1 && (int) $stage->is_won === 0 && (int) $stage->is_lost === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Copy the stages of another pipeline into this one. Probabilities, colours, delays and flags come along.
	 *
	 * @param int $sourceId Pipeline to copy from
	 * @return int Number of stages copied, -1 on error
	 */
	public function copyStagesFrom(int $sourceId): int
	{
		$source = new Pipeline($this->db);
		if ($this->id <= 0 || $source->fetch($sourceId) <= 0) {
			$this->error = 'CrmErrorPipelineNotFound';
			return -1;
		}
		$copied = 0;
		foreach ((new Stage($this->db))->fetchAll($sourceId) as $one) {
			$stage = new Stage($this->db);
			$stage->fk_pipeline = (int) $this->id;
			$stage->label = (string) $one->label;
			$stage->position = (int) $one->position;
			$stage->color = (string) $one->color;
			$stage->probability = (int) $one->probability;
			$stage->rotting_days = (int) $one->rotting_days;
			$stage->forecast_category = (string) $one->forecast_category;
			$stage->is_won = (int) $one->is_won;
			$stage->is_lost = (int) $one->is_lost;
			$stage->active = (int) $one->active;
			$stage->is_reply = (int) $one->is_reply;
			if ($stage->create() <= 0) {
				$this->error = $stage->error;
				return -1;
			}
			$copied++;
		}
		return $copied;
	}

	/**
	 * Create a pipeline, or rename and activate or deactivate an existing one. The ref never changes.
	 * Used by the setup screen and by the MCP tool.
	 *
	 * @param User   $user     User saving
	 * @param int    $id       Pipeline id, 0 to create
	 * @param string $ref      Reference, only used on creation (A-Z, 0-9, underscore and dash)
	 * @param string $label    Label
	 * @param int    $active   1 active, 0 inactive
	 * @param int    $copyFrom On creation, pipeline whose stages are copied. 0 for an empty pipeline.
	 * @return int Pipeline id, -1 on error with a language key in ->error
	 */
	public function save(User $user, int $id, string $ref, string $label, int $active, int $copyFrom = 0): int
	{
		$label = trim($label);
		if ($label === '') {
			$this->error = 'CrmErrorPipelineLabelRequired';
			return -1;
		}
		if ($id > 0) {
			if ($this->fetch($id) <= 0) {
				$this->error = 'CrmErrorPipelineNotFound';
				return -1;
			}
			$active = $active ? 1 : 0;
			if ($active === 0 && (int) $this->active === 1 && $this->activeCount() <= 1) {
				$this->error = 'CrmErrorLastPipeline';
				return -1;
			}
			$this->label = $label;
			$this->active = $active;
			$sql = 'UPDATE '.$this->db->prefix()."crm_pipeline SET label = '".$this->db->escape($this->label)."', active = ".((int) $this->active);
			$sql .= ' WHERE rowid = '.((int) $this->id);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			return (int) $this->id;
		}
		$ref = strtoupper((string) preg_replace('/[^A-Za-z0-9_\-]/', '', $ref));
		if ($ref === '') {
			$this->error = 'CrmErrorPipelineRefRequired';
			return -1;
		}
		if ($this->refExists($ref)) {
			$this->error = 'CrmErrorPipelineRefExists';
			return -1;
		}
		$this->ref = $ref;
		$this->label = $label;
		$this->active = 1;
		if ($this->create($user) <= 0) {
			return -1;
		}
		if ($copyFrom > 0 && $this->copyStagesFrom($copyFrom) < 0) {
			return -1;
		}
		return (int) $this->id;
	}
}
