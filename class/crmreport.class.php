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

/**
 * \file    class/crmreport.class.php
 * \ingroup crm
 * \brief   Hygiene lists and pipeline reports. Lost deals are left out of the forecast.
 */

require_once __DIR__.'/../lib/crm.lib.php';
require_once __DIR__.'/crmactivity.class.php';

/**
 * Forecast, cohort conversion and the work-to-do lists.
 */
class CrmReport
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
	 * Work to do for the deals the caller may see.
	 *
	 * @return array{no_next:array<int,object>,overdue:array<int,object>,past_close:array<int,object>,rotting:array<int,object>}
	 */
	public function hygiene(User $user): array
	{
		return $this->classifyOpenDeals(crmOwnerSql($user, 'o'), $user, 0);
	}

	/**
	 * Same lists for one owner. Used by the home box and the reminder, which stay on the current user.
	 *
	 * @return array{no_next:array<int,object>,overdue:array<int,object>,past_close:array<int,object>,rotting:array<int,object>}
	 */
	public function hygieneForOwner(int $ownerId): array
	{
		global $user;
		$fragment = ' AND o.fk_user_resp = '.((int) $ownerId);
		return $this->classifyOpenDeals($fragment, $user, $ownerId);
	}

	/**
	 * Open amount by expected-close month and forecast category.
	 * Dates before today are omitted here; the hygiene list shows them.
	 *
	 * @return array{months:array<string,array<string,array{amount:float,weighted:float}>>,unscheduled:array<string,array{amount:float,weighted:float}>}
	 */
	public function forecast(User $user, int $months = 0): array
	{
		global $conf;
		if ($months <= 0) {
			$months = getDolGlobalInt('CRM_FORECAST_MONTHS', 3);
		}
		if ($months <= 0) {
			$months = 3;
		}
		$parts = dol_getdate(dol_now());
		$windowEnd = (int) dol_mktime(0, 0, 0, (int) $parts['mon'] + $months, 1, (int) $parts['year']);
		$today = crmTodayStart();
		$empty = array('amount' => 0.0, 'weighted' => 0.0);
		$categories = array('pipeline', 'upside', 'commit');
		$out = array('months' => array(), 'unscheduled' => array());
		foreach ($categories as $category) {
			$out['unscheduled'][$category] = $empty;
		}

		$sql = 'SELECT o.amount, o.date_close_expected, st.probability, st.forecast_category';
		$sql .= ' FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= ' INNER JOIN '.$this->db->prefix().'crm_stage as st ON st.rowid = o.fk_stage';
		$sql .= " WHERE o.entity = ".((int) $conf->entity)." AND o.status = 'open'";
		$sql .= " AND st.forecast_category IN ('pipeline', 'upside', 'commit')";
		$sql .= crmOwnerSql($user, 'o');
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $out;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$category = (string) $obj->forecast_category;
			if (!isset($out['unscheduled'][$category])) {
				continue;
			}
			$weighted = crmWeighted((float) $obj->amount, (int) $obj->probability);
			$close = $this->db->jdate($obj->date_close_expected);
			if (empty($close)) {
				$out['unscheduled'][$category]['amount'] += (float) $obj->amount;
				$out['unscheduled'][$category]['weighted'] += $weighted;
				continue;
			}
			if ($close < $today || $close >= $windowEnd) {
				continue;
			}
			$month = dol_print_date((int) $close, '%Y-%m');
			if (!isset($out['months'][$month])) {
				$out['months'][$month] = array();
				foreach ($categories as $name) {
					$out['months'][$month][$name] = array('amount' => 0.0, 'weighted' => 0.0);
				}
			}
			$out['months'][$month][$category]['amount'] += (float) $obj->amount;
			$out['months'][$month][$category]['weighted'] += $weighted;
		}
		ksort($out['months']);
		return $out;
	}

	/**
	 * How many deals created in the period later reached each stage.
	 *
	 * @return array{cohort:int,stages:array<int,array{id:int,label:string,reached:int,rate:float}>}
	 */
	public function conversion(User $user, int $pipelineId, int $fromTs, int $toTs): array
	{
		global $conf;
		$out = array('cohort' => 0, 'stages' => array());
		if ($pipelineId <= 0) {
			return $out;
		}
		require_once __DIR__.'/stage.class.php';
		$stage = new Stage($this->db);
		$stages = $stage->fetchAll($pipelineId);
		$sql = 'SELECT o.rowid FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= ' WHERE o.entity = '.((int) $conf->entity).' AND o.fk_pipeline = '.((int) $pipelineId);
		$sql .= " AND o.datec >= '".$this->db->idate($fromTs)."' AND o.datec < '".$this->db->idate($toTs)."'";
		$sql .= crmOwnerSql($user, 'o');
		$ids = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$ids[] = (int) $obj->rowid;
			}
		}
		$out['cohort'] = count($ids);
		$reached = array();
		if (count($ids) > 0) {
			$sqlHist = 'SELECT fk_stage_to, COUNT(DISTINCT fk_opportunity) as nb';
			$sqlHist .= ' FROM '.$this->db->prefix().'crm_stage_history';
			$sqlHist .= ' WHERE fk_opportunity IN ('.implode(',', $ids).')';
			$sqlHist .= ' GROUP BY fk_stage_to';
			$resHist = $this->db->query($sqlHist);
			if ($resHist) {
				while ($obj = $this->db->fetch_object($resHist)) {
					$reached[(int) $obj->fk_stage_to] = (int) $obj->nb;
				}
			}
		}
		foreach ($stages as $one) {
			$count = isset($reached[(int) $one->id]) ? $reached[(int) $one->id] : 0;
			$rate = ($out['cohort'] > 0) ? ($count / $out['cohort']) : 0.0;
			$out['stages'][] = array(
				'id' => (int) $one->id,
				'label' => (string) $one->label,
				'reached' => $count,
				'rate' => $rate,
			);
		}
		return $out;
	}

	/**
	 * won / (won + lost) by close date.
	 *
	 * @return array{won:int,lost:int,rate:float}
	 */
	public function winRate(User $user, int $fromTs, int $toTs): array
	{
		global $conf;
		$out = array('won' => 0, 'lost' => 0, 'rate' => 0.0);
		$sql = 'SELECT status, COUNT(rowid) as nb FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= ' WHERE o.entity = '.((int) $conf->entity);
		$sql .= " AND o.status IN ('won', 'lost')";
		$sql .= " AND o.date_close >= '".$this->db->idate($fromTs)."' AND o.date_close < '".$this->db->idate($toTs)."'";
		$sql .= crmOwnerSql($user, 'o');
		$sql .= ' GROUP BY status';
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if ((string) $obj->status === 'won') {
					$out['won'] = (int) $obj->nb;
				} elseif ((string) $obj->status === 'lost') {
					$out['lost'] = (int) $obj->nb;
				}
			}
		}
		$total = $out['won'] + $out['lost'];
		if ($total > 0) {
			$out['rate'] = $out['won'] / $total;
		}
		return $out;
	}

	/**
	 * Mean days from creation to close, won deals only.
	 *
	 * @return array{days:float,count:int}
	 */
	public function cycleTime(User $user, int $fromTs, int $toTs): array
	{
		global $conf;
		$sql = 'SELECT datec, date_close FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= " WHERE o.entity = ".((int) $conf->entity)." AND o.status = 'won'";
		$sql .= " AND o.date_close >= '".$this->db->idate($fromTs)."' AND o.date_close < '".$this->db->idate($toTs)."'";
		$sql .= crmOwnerSql($user, 'o');
		$sum = 0.0;
		$count = 0;
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$created = (int) $this->db->jdate($obj->datec);
				$closed = (int) $this->db->jdate($obj->date_close);
				if ($created <= 0 || $closed < $created) {
					continue;
				}
				$sum += ($closed - $created) / 86400;
				$count++;
			}
		}
		return array('days' => ($count > 0 ? $sum / $count : 0.0), 'count' => $count);
	}

	/**
	 * @return array<int,array{label:string,nb:int,amount:float}>
	 */
	public function lossReasons(User $user, int $fromTs, int $toTs): array
	{
		global $conf;
		$sql = 'SELECT COALESCE(r.label, \'\') as label, COUNT(o.rowid) as nb, SUM(o.amount) as amount';
		$sql .= ' FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'crm_loss_reason as r ON r.rowid = o.fk_loss_reason';
		$sql .= " WHERE o.entity = ".((int) $conf->entity)." AND o.status = 'lost'";
		$sql .= " AND o.date_close >= '".$this->db->idate($fromTs)."' AND o.date_close < '".$this->db->idate($toTs)."'";
		$sql .= crmOwnerSql($user, 'o');
		$sql .= ' GROUP BY r.label ORDER BY nb DESC';
		return $this->grouped($sql);
	}

	/**
	 * @return array<int,array{label:string,nb_open:int,amount_open:float,weighted:float,nb_won:int,nb_lost:int}>
	 */
	public function byOwner(User $user, int $fromTs, int $toTs): array
	{
		return $this->byDimension($user, $fromTs, $toTs, 'owner');
	}

	/**
	 * @return array<int,array{label:string,nb_open:int,amount_open:float,weighted:float,nb_won:int,nb_lost:int}>
	 */
	public function bySource(User $user, int $fromTs, int $toTs): array
	{
		return $this->byDimension($user, $fromTs, $toTs, 'source');
	}

	/**
	 * @param string $ownerSql Fragment starting with AND, or empty
	 * @return array{no_next:array<int,object>,overdue:array<int,object>,past_close:array<int,object>,rotting:array<int,object>}
	 */
	private function classifyOpenDeals(string $ownerSql, User $user, int $forceOwner): array
	{
		global $conf;
		$today = crmTodayStart();
		$now = dol_now();
		$out = array('no_next' => array(), 'overdue' => array(), 'past_close' => array(), 'rotting' => array());
		$sql = 'SELECT o.rowid, o.ref, o.label, o.amount, o.datec, o.date_close_expected, o.fk_user_resp, s.nom as soc_name, st.label as stage_label, st.rotting_days,';
		$sql .= ' (SELECT MAX(a.datep) FROM '.$this->db->prefix()."actioncomm as a WHERE a.elementtype = '".$this->db->escape(CrmActivity::ELEMENT_TYPE)."' AND a.fk_element = o.rowid AND a.percent >= 100) as last_done,";
		$sql .= ' (SELECT MIN(a.datep) FROM '.$this->db->prefix()."actioncomm as a WHERE a.elementtype = '".$this->db->escape(CrmActivity::ELEMENT_TYPE)."' AND a.fk_element = o.rowid AND a.percent < 100 AND a.datep >= '".$this->db->idate($today)."') as next_date";
		$sql .= ' FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'societe as s ON s.rowid = o.fk_soc';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'crm_stage as st ON st.rowid = o.fk_stage';
		$sql .= " WHERE o.entity = ".((int) $conf->entity)." AND o.status = 'open'";
		$sql .= $ownerSql;
		$sql .= ' ORDER BY o.date_close_expected ASC, o.ref ASC';
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if (empty($obj->next_date)) {
					$out['no_next'][] = $obj;
				}
				$expected = $this->db->jdate($obj->date_close_expected);
				if (!empty($expected) && $expected < $today) {
					$out['past_close'][] = $obj;
				}
				$last = !empty($obj->last_done) ? (int) $this->db->jdate($obj->last_done) : 0;
				$created = (int) $this->db->jdate($obj->datec);
				if (crmIsRotting($last, $created, (int) $obj->rotting_days, $now)) {
					$out['rotting'][] = $obj;
				}
			}
		}
		$activity = new CrmActivity($this->db);
		$out['overdue'] = $activity->fetchList($user, 'overdue', $forceOwner);
		return $out;
	}

	/**
	 * @param string $dimension owner|source
	 * @return array<int,array{label:string,nb_open:int,amount_open:float,weighted:float,nb_won:int,nb_lost:int}>
	 */
	private function byDimension(User $user, int $fromTs, int $toTs, string $dimension): array
	{
		global $conf;
		if ($dimension === 'owner') {
			$labelSql = "TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')))";
			$join = ' LEFT JOIN '.$this->db->prefix().'user as u ON u.rowid = o.fk_user_resp';
			$group = 'o.fk_user_resp, u.firstname, u.lastname';
		} else {
			$labelSql = "COALESCE(src.label, '')";
			$join = ' LEFT JOIN '.$this->db->prefix().'crm_source as src ON src.rowid = o.fk_source';
			$group = 'o.fk_source, src.label';
		}
		$rows = array();
		$sql = 'SELECT '.$labelSql.' as label, COUNT(o.rowid) as nb_open, SUM(o.amount) as amount_open, SUM(o.amount * st.probability / 100) as weighted';
		$sql .= ' FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= ' INNER JOIN '.$this->db->prefix().'crm_stage as st ON st.rowid = o.fk_stage';
		$sql .= $join;
		$sql .= " WHERE o.entity = ".((int) $conf->entity)." AND o.status = 'open'";
		$sql .= crmOwnerSql($user, 'o');
		$sql .= ' GROUP BY '.$group;
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$label = trim((string) $obj->label);
				$rows[$label] = array(
					'label' => $label,
					'nb_open' => (int) $obj->nb_open,
					'amount_open' => (float) $obj->amount_open,
					'weighted' => (float) $obj->weighted,
					'nb_won' => 0,
					'nb_lost' => 0,
				);
			}
		}
		$sqlClosed = 'SELECT '.$labelSql.' as label, o.status, COUNT(o.rowid) as nb';
		$sqlClosed .= ' FROM '.$this->db->prefix().'crm_opportunity as o';
		$sqlClosed .= $join;
		$sqlClosed .= ' WHERE o.entity = '.((int) $conf->entity);
		$sqlClosed .= " AND o.status IN ('won', 'lost')";
		$sqlClosed .= " AND o.date_close >= '".$this->db->idate($fromTs)."' AND o.date_close < '".$this->db->idate($toTs)."'";
		$sqlClosed .= crmOwnerSql($user, 'o');
		$sqlClosed .= ' GROUP BY '.$group.', o.status';
		$resClosed = $this->db->query($sqlClosed);
		if ($resClosed) {
			while ($obj = $this->db->fetch_object($resClosed)) {
				$label = trim((string) $obj->label);
				if (!isset($rows[$label])) {
					$rows[$label] = array(
						'label' => $label,
						'nb_open' => 0,
						'amount_open' => 0.0,
						'weighted' => 0.0,
						'nb_won' => 0,
						'nb_lost' => 0,
					);
				}
				if ((string) $obj->status === 'won') {
					$rows[$label]['nb_won'] = (int) $obj->nb;
				} else {
					$rows[$label]['nb_lost'] = (int) $obj->nb;
				}
			}
		}
		return array_values($rows);
	}

	/**
	 * @return array<int,array{label:string,nb:int,amount:float}>
	 */
	private function grouped(string $sql): array
	{
		$list = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$list[] = array(
					'label' => (string) $obj->label,
					'nb' => (int) $obj->nb,
					'amount' => (float) $obj->amount,
				);
			}
		}
		return $list;
	}
}
