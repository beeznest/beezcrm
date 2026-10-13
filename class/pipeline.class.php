<?php
declare(strict_types=1);

/**
 * \file    class/pipeline.class.php
 * \ingroup crm
 */

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
}
