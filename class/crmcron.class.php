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
 * \file    class/crmcron.class.php
 * \ingroup crm
 * \brief   Hourly jobs. One reminder per owner per day. Automated outreach stays off unless enabled.
 */

require_once __DIR__.'/../lib/crm.lib.php';
require_once __DIR__.'/crmreport.class.php';
require_once __DIR__.'/sequence.class.php';

/**
 * Dolibarr cron entry points. The class is constructed with the database only.
 */
class CrmCron
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $output = '';
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * E-mail each owner a digest of overdue steps, missing next steps, past closes and rotting deals.
	 *
	 * @return int 0
	 */
	public function runOwnerReminders(): int
	{
		global $conf, $langs;
		$this->output = '';
		if (!isModEnabled('crm')) {
			$this->output = 'CRM is disabled';
			return 0;
		}
		if (!getDolGlobalInt('CRM_REMINDERS_ON')) {
			$this->output = 'Owner reminders are off';
			return 0;
		}
		$langs->load('crm@crm');
		$dry = getDolGlobalInt('CRM_REMINDER_DRY_RUN');
		$day = dol_print_date(crmTodayStart(), '%Y-%m-%d');
		$entity = (int) $conf->entity;
		$sql = 'SELECT DISTINCT o.fk_user_resp, u.email, u.firstname, u.lastname';
		$sql .= ' FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= ' INNER JOIN '.$this->db->prefix().'user as u ON u.rowid = o.fk_user_resp';
		$sql .= " WHERE o.entity = ".$entity." AND o.status = 'open' AND u.statut = 1";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->output = $this->error;
			return 0;
		}
		$report = new CrmReport($this->db);
		$sent = 0;
		$skipped = 0;
		while ($owner = $this->db->fetch_object($resql)) {
			$ownerId = (int) $owner->fk_user_resp;
			if ($this->reminderAlreadyLogged($entity, $ownerId, $day)) {
				$skipped++;
				continue;
			}
			$hygiene = $report->hygieneForOwner($ownerId);
			$body = $this->reminderBody($hygiene);
			if ($body === '') {
				continue;
			}
			$email = trim((string) $owner->email);
			$subject = $langs->trans('CrmOwnerReminders').' '.$day;
			$status = 'sent';
			$error = '';
			if ($email === '') {
				$status = 'error';
				$error = 'Owner has no e-mail address';
			} elseif ($dry) {
				$status = 'simulated';
			} else {
				$result = crmSendMail($email, $subject, $body);
				if (!$result['ok']) {
					$status = 'error';
					$error = $result['error'];
				}
			}
			$this->insertReminderLog($entity, $ownerId, $day, $status, $error);
			if ($status === 'error') {
				$skipped++;
			} else {
				$sent++;
			}
			unset($name);
		}
		$this->output = 'Reminders '.($dry ? 'simulated' : 'sent').': '.$sent.'. Skipped: '.$skipped.'.';
		return 0;
	}

	/**
	 * Run due sequence steps. Does nothing while CRM_SEQUENCES_ON is off.
	 *
	 * @return int 0
	 */
	public function runSequences(): int
	{
		global $user, $langs;
		$this->output = '';
		if (!isModEnabled('crm')) {
			$this->output = 'CRM is disabled';
			return 0;
		}
		$langs->load('crm@crm');
		if (!getDolGlobalInt('CRM_SEQUENCES_ON')) {
			$this->output = 'Automated outreach is off';
			return 0;
		}
		$max = getDolGlobalInt('CRM_MAX_EMAILS_PER_RUN', 50);
		if ($max <= 0) {
			$max = 50;
		}
		$sequence = new Sequence($this->db);
		$stats = $sequence->runDue($user, $max);
		$dry = getDolGlobalInt('CRM_DRY_RUN') ? ' dry-run' : '';
		$this->output = 'Sequences'.$dry.': '.$stats['steps'].' steps, '.$stats['emails'].' e-mails, '.$stats['drafts'].' drafts, '.$stats['errors'].' errors.';
		if ($sequence->error !== '') {
			$this->error = $sequence->error;
		}
		return 0;
	}

	/**
	 * @param array{no_next:array<int,object>,overdue:array<int,object>,past_close:array<int,object>,rotting:array<int,object>} $hygiene
	 */
	private function reminderBody(array $hygiene): string
	{
		global $langs;
		$lines = array();
		$this->appendLines($lines, $langs->trans('CrmOverdue'), $hygiene['overdue'], true);
		$this->appendLines($lines, $langs->trans('CrmNoNextStep'), $hygiene['no_next'], false);
		$this->appendLines($lines, $langs->trans('CrmPastClose'), $hygiene['past_close'], false);
		$this->appendLines($lines, $langs->trans('CrmRotting'), $hygiene['rotting'], false);
		if (count($lines) === 0) {
			return '';
		}
		return implode("\n", $lines);
	}

	/**
	 * @param string[]           $lines
	 * @param array<int,object>  $rows
	 */
	private function appendLines(array &$lines, string $title, array $rows, bool $isEvent): void
	{
		if (count($rows) === 0) {
			return;
		}
		$lines[] = $title.' ('.count($rows).')';
		$shown = 0;
		foreach ($rows as $row) {
			if ($shown >= 30) {
				$lines[] = '- ...';
				break;
			}
			if ($isEvent) {
				$lines[] = '- '.(string) $row->ref.' | '.(string) $row->label.' | '.dol_print_date($this->db->jdate($row->datep), 'day');
			} else {
				$lines[] = '- '.(string) $row->ref.' | '.(string) $row->label.' | '.(string) $row->soc_name;
			}
			$shown++;
		}
		$lines[] = '';
	}

	private function reminderAlreadyLogged(int $entity, int $ownerId, string $day): bool
	{
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'crm_reminder_log';
		$sql .= ' WHERE entity = '.$entity.' AND fk_user = '.$ownerId." AND date_day = '".$this->db->escape($day)."'";
		$resql = $this->db->query($sql);
		return ($resql && $this->db->num_rows($resql) > 0);
	}

	private function insertReminderLog(int $entity, int $ownerId, string $day, string $status, string $error): void
	{
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_reminder_log (entity, fk_user, date_day, date_send, status, error_msg) VALUES (';
		$sql .= $entity.', '.$ownerId.", '".$this->db->escape($day)."', '".$this->db->idate(dol_now())."', '".$this->db->escape($status)."', '".$this->db->escape($error)."')";
		$this->db->query($sql);
	}
}
