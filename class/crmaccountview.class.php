<?php
declare(strict_types=1);

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

/**
 * \file    class/crmaccountview.class.php
 * \ingroup crm
 * \brief   From v1.2.0. What the CRM knows about a third party, for its card and for the CRM history page: its deals, and a
 *          timeline of stage moves and done agenda events. Reads only; nothing is stored. Visibility is the CRM's own
 *          (crmOwnerSql), whatever the agenda rights of the user are.
 */

require_once __DIR__.'/../lib/crm.lib.php';
require_once __DIR__.'/crmactivity.class.php';

/**
 * Deals and timeline of one third party.
 */
class CrmAccountView
{
	/** @var DoliDB */
	private $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Deals of the third party the user may read: open ones first, then the most recently created.
	 *
	 * @return array<int,object> Rows with rowid, ref, label, status, amount, stage_label
	 */
	public function fetchDeals(User $user, int $socId): array
	{
		global $conf;
		if ($socId <= 0) {
			return array();
		}
		$sql = 'SELECT o.rowid, o.ref, o.label, o.status, o.amount, s.label as stage_label';
		$sql .= ' FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'crm_stage as s ON s.rowid = o.fk_stage';
		$sql .= ' WHERE o.fk_soc = '.((int) $socId).' AND o.entity = '.((int) $conf->entity).crmOwnerSql($user, 'o');
		$sql .= " ORDER BY (o.status = 'open') DESC, o.datec DESC, o.rowid DESC";
		return $this->fetchRows($sql);
	}

	/**
	 * FROM of the timeline: the stage moves and the done events of the deals of the third party, as one list.
	 */
	private function timelineSql(User $user, int $socId): string
	{
		global $conf;
		$prefix = $this->db->prefix();
		$dealWhere = 'o.fk_soc = '.((int) $socId).' AND o.entity = '.((int) $conf->entity).crmOwnerSql($user, 'o');
		$sql = "SELECT 'stage' as kind, h.datec as dt, h.rowid as id, o.rowid as deal_id, o.ref as deal_ref,";
		$sql .= ' sf.label as label_from, st.label as label_to, NULL as event_label, NULL as event_code, h.fk_user as fk_user';
		$sql .= ' FROM '.$prefix.'crm_stage_history as h';
		$sql .= ' INNER JOIN '.$prefix.'crm_opportunity as o ON o.rowid = h.fk_opportunity';
		$sql .= ' LEFT JOIN '.$prefix.'crm_stage as sf ON sf.rowid = h.fk_stage_from';
		$sql .= ' LEFT JOIN '.$prefix.'crm_stage as st ON st.rowid = h.fk_stage_to';
		$sql .= ' WHERE '.$dealWhere;
		$sql .= ' UNION ALL';
		$sql .= " SELECT 'event' as kind, a.datep as dt, a.id as id, o.rowid as deal_id, o.ref as deal_ref,";
		$sql .= ' NULL as label_from, NULL as label_to, a.label as event_label, a.code as event_code, a.fk_user_action as fk_user';
		$sql .= ' FROM '.$prefix.'actioncomm as a';
		$sql .= ' INNER JOIN '.$prefix.'crm_opportunity as o ON o.rowid = a.fk_element';
		$sql .= " WHERE a.elementtype = '".$this->db->escape(CrmActivity::ELEMENT_TYPE)."' AND a.percent >= 100 AND ".$dealWhere;
		return $sql;
	}

	/**
	 * Number of lines of the timeline.
	 */
	public function countTimeline(User $user, int $socId): int
	{
		$resql = $this->db->query('SELECT COUNT(*) as nb FROM ('.$this->timelineSql($user, $socId).') as t');
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->nb;
		}
		return 0;
	}

	/**
	 * The latest lines of the timeline, newest first.
	 *
	 * @return array<int,object> Rows with kind (stage|event), dt, id, deal_id, deal_ref, label_from, label_to, event_label, event_code,
	 *                           fk_user, user_name
	 */
	public function fetchTimeline(User $user, int $socId, int $limit = 5, int $offset = 0): array
	{
		if ($socId <= 0) {
			return array();
		}
		$sql = 'SELECT t.*, u.firstname, u.lastname FROM ('.$this->timelineSql($user, $socId).') as t';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'user as u ON u.rowid = t.fk_user';
		$sql .= " ORDER BY t.dt DESC, t.kind ASC, t.id DESC";
		$sql .= $this->db->plimit(max(1, $limit), max(0, $offset));
		return $this->fetchRows($sql);
	}

	/**
	 * @return array<int,object>
	 */
	private function fetchRows(string $sql): array
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
