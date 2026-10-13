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
 * \file    class/mcp/crmreporttool.class.php
 * \ingroup crm
 * \brief   MCP tools for reports, settings and the two cron methods.
 */

require_once __DIR__.'/crmmcptool.class.php';

/**
 * Reports follow the caller. Settings and the reminder job need the setup right.
 */
class CrmReportTool extends CrmMcpTool
{
	/** @var string[] */
	private $settingKeys = array(
		'CRM_ROTTING_DAYS',
		'CRM_FORECAST_MONTHS',
		'CRM_MAX_EMAILS_PER_RUN',
		'CRM_MAIL_FROM',
		'CRM_SEQUENCES_ON',
		'CRM_SEQUENCE_EMAIL_MODE',
		'CRM_DRY_RUN',
		'CRM_REMINDERS_ON',
		'CRM_REMINDER_DRY_RUN',
		'CRM_REQUIRE_NEXT_STEP',
		'CRM_AI_EMAIL_ASSIST',
		'CRM_AI_EMAIL_PROMPT',
		'CRM_ARCHIVE_MAILBOX',
		'CRM_ARCHIVE_COPY',
	);

	/**
	 * @return list<array<string,mixed>>
	 */
	public function getDefinitions(): array
	{
		$range = array(
			'from' => array('type' => 'string', 'description' => 'Inclusive start, YYYY-MM-DD. Defaults to 1 January.'),
			'to' => array('type' => 'string', 'description' => 'Exclusive end, YYYY-MM-DD. Defaults to tomorrow.'),
			'pipeline_id' => array('type' => 'integer'),
		);
		return array(
			$this->definition('crm_get_dashboard', 'Counts of deals with no next step, overdue steps, past expected close and rotting deals, plus the forecast.', array()),
			$this->definition('crm_get_forecast', 'Open amount by expected-close month and category pipeline, upside or commit. Lost deals are excluded.', array(
				'months' => array('type' => 'integer'),
			)),
			$this->definition('crm_get_conversion', 'Cohort conversion from stage history for deals created in the period.', $range),
			$this->definition('crm_get_loss_reasons', 'Lost deals in the period, grouped by reason.', $range),
			$this->definition('crm_get_win_rate', 'Won divided by won plus lost, by close date.', $range),
			$this->definition('crm_get_cycle_time', 'Mean days from creation to close, won deals only.', $range),
			$this->definition('crm_get_by_owner', 'Open amount and closes in the period, by owner.', $range),
			$this->definition('crm_get_by_source', 'Open amount and closes in the period, by source.', $range),
			$this->definition('crm_get_settings', 'Read CRM constants.', array()),
			$this->definition('crm_set_settings', 'Change known CRM constants only.', array(
				'values' => array('type' => 'object', 'description' => 'Map of CRM_* constant name to value'),
			), array('values')),
			$this->definition('crm_run_sequences', 'Run due sequence steps now, same method as the cron job.', array()),
			$this->definition('crm_run_owner_reminders', 'Send or simulate the owner digest now, same method as the cron job.', array()),
		);
	}

	/**
	 * @return array<int,array<int,string>>
	 */
	public function getRequiredRights(string $toolName)
	{
		if ($toolName === 'crm_get_settings' || $toolName === 'crm_set_settings' || $toolName === 'crm_run_owner_reminders') {
			return array(array('crm', 'setup'));
		}
		if ($toolName === 'crm_run_sequences') {
			return array(array('crm', 'sequence'));
		}
		return array(array('crm', 'report'));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	public function writeConfirmationPreview(string $toolName, array $args)
	{
		if ($toolName === 'crm_set_settings') {
			$names = array();
			if (isset($args['values']) && is_array($args['values'])) {
				$names = array_keys($args['values']);
			}
			return 'Update CRM settings: '.implode(', ', $names).'.';
		}
		if ($toolName === 'crm_run_sequences') {
			$dry = getDolGlobalInt('CRM_DRY_RUN') ? 'Dry-run is on.' : 'Dry-run is off, so e-mail can be sent.';
			return 'Run CRM customer sequences now. '.$dry;
		}
		if ($toolName === 'crm_run_owner_reminders') {
			$dry = getDolGlobalInt('CRM_REMINDER_DRY_RUN') ? 'Dry-run is on.' : 'Dry-run is off, so e-mail can be sent.';
			return 'Run CRM owner reminders now. '.$dry;
		}
		return self::NO_WRITE;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	public function execute(string $toolName, array $args)
	{
		require_once __DIR__.'/../crmreport.class.php';
		$report = new CrmReport($this->db);
		if ($toolName === 'crm_get_dashboard') {
			$hygiene = $report->hygiene($this->user);
			return array(array(
				'no_next' => count($hygiene['no_next']),
				'overdue' => count($hygiene['overdue']),
				'past_close' => count($hygiene['past_close']),
				'rotting' => count($hygiene['rotting']),
				'forecast' => $this->forecastRows($report->forecast($this->user)),
			));
		}
		if ($toolName === 'crm_get_forecast') {
			return $this->forecastRows($report->forecast($this->user, $this->argInt($args, 'months')));
		}
		$range = $this->range($args);
		if ($toolName === 'crm_get_conversion') {
			$pipelineId = $this->argInt($args, 'pipeline_id');
			if ($pipelineId <= 0) {
				require_once __DIR__.'/../pipeline.class.php';
				$pipeline = new Pipeline($this->db);
				$list = $pipeline->fetchAll(true);
				$pipelineId = (count($list) > 0) ? (int) $list[0]->id : 0;
			}
			$result = $report->conversion($this->user, $pipelineId, $range[0], $range[1]);
			return array(array('pipeline_id' => $pipelineId, 'cohort' => $result['cohort'], 'stages' => $result['stages']));
		}
		if ($toolName === 'crm_get_loss_reasons') {
			return $report->lossReasons($this->user, $range[0], $range[1]);
		}
		if ($toolName === 'crm_get_win_rate') {
			return array($report->winRate($this->user, $range[0], $range[1]));
		}
		if ($toolName === 'crm_get_cycle_time') {
			return array($report->cycleTime($this->user, $range[0], $range[1]));
		}
		if ($toolName === 'crm_get_by_owner') {
			return $report->byOwner($this->user, $range[0], $range[1]);
		}
		if ($toolName === 'crm_get_by_source') {
			return $report->bySource($this->user, $range[0], $range[1]);
		}
		if ($toolName === 'crm_get_settings') {
			return array($this->currentSettings());
		}
		if ($toolName === 'crm_set_settings') {
			return $this->saveSettings($args);
		}
		if ($toolName === 'crm_run_sequences' || $toolName === 'crm_run_owner_reminders') {
			require_once __DIR__.'/../crmcron.class.php';
			$cron = new CrmCron($this->db);
			if ($toolName === 'crm_run_sequences') {
				$cron->runSequences();
			} else {
				$cron->runOwnerReminders();
			}
			return array(array('output' => $cron->output, 'error' => $cron->error));
		}
		return $this->fail('Unknown tool');
	}

	/**
	 * @param array{months:array<string,array<string,array{amount:float,weighted:float}>>,unscheduled:array<string,array{amount:float,weighted:float}>} $forecast
	 * @return array<int,array<string,mixed>>
	 */
	private function forecastRows(array $forecast): array
	{
		$rows = array();
		foreach ($forecast['months'] as $month => $categories) {
			foreach ($categories as $category => $values) {
				$rows[] = array(
					'month' => (string) $month,
					'category' => (string) $category,
					'amount' => $values['amount'],
					'weighted' => $values['weighted'],
				);
			}
		}
		foreach ($forecast['unscheduled'] as $category => $values) {
			$rows[] = array(
				'month' => '',
				'category' => (string) $category,
				'amount' => $values['amount'],
				'weighted' => $values['weighted'],
			);
		}
		return $rows;
	}

	/**
	 * @return array<string,string>
	 */
	private function currentSettings(): array
	{
		$out = array();
		foreach ($this->settingKeys as $key) {
			$out[$key] = getDolGlobalString($key);
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	private function saveSettings(array $args)
	{
		if (!isset($args['values']) || !is_array($args['values'])) {
			return $this->fail('values must be an object of CRM constants');
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$saved = array();
		foreach ($args['values'] as $name => $value) {
			$name = (string) $name;
			if (!in_array($name, $this->settingKeys, true)) {
				return $this->fail('Unknown setting '.$name);
			}
			dolibarr_set_const($this->db, $name, (string) $value, 'chaine', 0, '', (int) $this->conf->entity);
			$saved[$name] = (string) $value;
		}
		return array($saved);
	}
}
