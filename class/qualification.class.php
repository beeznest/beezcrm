<?php
declare(strict_types=1);

/**
 * \file    class/qualification.class.php
 * \ingroup crm
 * \brief   Checklist of buying signals. The score is the sum of the confirmed items.
 */

require_once __DIR__.'/../lib/crmlogic.lib.php';

/**
 * Qualification item and the answers stored on a deal.
 */
class Qualification
{
	/** @var DoliDB */
	public $db;
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
	 * Active checklist rows.
	 *
	 * @return array<int,object>
	 */
	public function fetchItems(): array
	{
		global $conf;
		$sql = 'SELECT * FROM '.$this->db->prefix().'crm_qualification WHERE entity = '.((int) $conf->entity).' AND active = 1 ORDER BY position ASC';
		return $this->rows($sql);
	}

	/**
	 * Items with the answer for one deal.
	 *
	 * @return array<int,array{id:int,code:string,label:string,points:int,answer:int}>
	 */
	public function fetchForOpportunity(int $opportunityId): array
	{
		global $conf;
		$sql = 'SELECT q.rowid, q.code, q.label, q.points, COALESCE(a.answer, 0) as answer';
		$sql .= ' FROM '.$this->db->prefix().'crm_qualification as q';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'crm_opportunity_qualification as a ON a.fk_qualification = q.rowid AND a.fk_opportunity = '.((int) $opportunityId);
		$sql .= ' WHERE q.entity = '.((int) $conf->entity).' AND q.active = 1';
		$sql .= ' ORDER BY q.position ASC';
		$out = array();
		foreach ($this->rows($sql) as $obj) {
			$out[] = array(
				'id' => (int) $obj->rowid,
				'code' => (string) $obj->code,
				'label' => (string) $obj->label,
				'points' => (int) $obj->points,
				'answer' => (int) $obj->answer,
			);
		}
		return $out;
	}

	/**
	 * @param array<int,array{points:int,answer:int}> $items
	 */
	public function score(array $items): int
	{
		return crmScore($items);
	}

	/**
	 * Labels still unanswered.
	 *
	 * @param array<int,array{label:string,answer:int}> $items
	 * @return string[]
	 */
	public function missing(array $items): array
	{
		$missing = array();
		foreach ($items as $item) {
			if (empty($item['answer'])) {
				$missing[] = (string) $item['label'];
			}
		}
		return $missing;
	}

	/**
	 * Save yes/no answers. Keys are qualification ids.
	 *
	 * @param User             $user User answering
	 * @param int              $opportunityId Deal id
	 * @param array<int,int>   $answers qualification id => 0|1
	 * @return int 1 if OK, -1 on error
	 */
	public function saveAnswers(User $user, int $opportunityId, array $answers): int
	{
		global $conf;
		$now = $this->db->idate(dol_now());
		foreach ($this->fetchItems() as $item) {
			$id = (int) $item->rowid;
			$answer = !empty($answers[$id]) ? 1 : 0;
			$sql = 'INSERT INTO '.$this->db->prefix().'crm_opportunity_qualification (entity, fk_opportunity, fk_qualification, answer, datec, fk_user) VALUES (';
			$sql .= ((int) $conf->entity).', '.((int) $opportunityId).', '.$id.', '.$answer.", '".$now."', ".((int) $user->id).')';
			$sql .= ' ON DUPLICATE KEY UPDATE answer = '.$answer.", datec = '".$now."', fk_user = ".((int) $user->id);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		return 1;
	}

	/**
	 * @return int Row id, or -1
	 */
	public function create(string $code, string $label, int $points, int $position): int
	{
		global $conf;
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_qualification (entity, code, label, points, position, active) VALUES (';
		$sql .= ((int) $conf->entity).", '".$this->db->escape($code)."', '".$this->db->escape($label)."', ".((int) $points).', '.((int) $position).', 1)';
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return (int) $this->db->last_insert_id($this->db->prefix().'crm_qualification');
	}

	/**
	 * @param string $sql Query
	 * @return array<int,object>
	 */
	private function rows(string $sql): array
	{
		$list = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$list[] = $obj;
			}
		}
		return $list;
	}
}
