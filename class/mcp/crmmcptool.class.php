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
 * \file    class/mcp/crmmcptool.class.php
 * \ingroup crm
 * \brief   Shared helpers for CRM MCP tools.
 */

require_once DOL_DOCUMENT_ROOT.'/ai/class/mcptool.class.php';
require_once __DIR__.'/../../lib/crm.lib.php';
require_once __DIR__.'/../opportunity.class.php';

/**
 * Base class. Reads return NO_WRITE. Writes name the record in the confirmation text.
 */
abstract class CrmMcpTool extends McpTool
{
	/**
	 * @param DoliDB $db   Database
	 * @param User   $user Caller
	 * @param Conf   $conf Configuration
	 */
	public function __construct($db, $user, $conf)
	{
		parent::__construct($db, $user, $conf);
	}

	/**
	 * @return string[]
	 */
	public function getCategories(): array
	{
		return array('commercial');
	}

	/**
	 * @param array<string,mixed> $properties Schema properties
	 * @param string[]            $required   Required names
	 * @return array<string,mixed>
	 */
	protected function definition(string $name, string $description, array $properties, array $required = array()): array
	{
		$schema = array(
			'type' => 'object',
			'properties' => $properties,
		);
		if (count($required) > 0) {
			$schema['required'] = $required;
		}
		return array(
			'name' => $name,
			'description' => $description,
			'inputSchema' => $schema,
		);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	protected function argInt(array $args, string $key): int
	{
		return isset($args[$key]) ? (int) $args[$key] : 0;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	protected function argString(array $args, string $key): string
	{
		if (!isset($args[$key]) || is_array($args[$key])) {
			return '';
		}
		return trim((string) $args[$key]);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	protected function argDate(array $args, string $key): int
	{
		if (!isset($args[$key]) || $args[$key] === '' || $args[$key] === null) {
			return 0;
		}
		if (is_numeric($args[$key])) {
			return (int) $args[$key];
		}
		$ts = dol_stringtotime((string) $args[$key]);
		return $ts ? (int) $ts : 0;
	}

	/**
	 * @return array{error:string}
	 */
	protected function fail(string $message): array
	{
		return array('error' => $message);
	}

	/**
	 * Check the rights this tool declares, from inside the tool. Dolibarr 25 checks them before it calls a tool; Dolibarr
	 * 24.0.0 checks nothing, so a caller authenticated as a user without the CRM right could otherwise run a write tool.
	 * Done twice, it costs nothing on 25 and closes the gap on 24.
	 *
	 * @return array{error:string}|null null when every declared right is held
	 */
	protected function denyMissingRights(string $toolName): ?array
	{
		$declared = $this->getRequiredRights($toolName);
		if (!is_array($declared)) {
			return $this->fail('Permission denied');
		}
		foreach ($declared as $right) {
			$right = (array) $right;
			$module = (string) ($right[0] ?? '');
			$perm = (string) ($right[1] ?? '');
			$subperm = (string) ($right[2] ?? '');
			if ($module === '' || !$this->user->hasRight($module, $perm, $subperm)) {
				return $this->fail('Permission denied: the right '.$module.'/'.$perm.($subperm !== '' ? '/'.$subperm : '').' is required');
			}
		}
		return null;
	}

	/**
	 * Empty when the caller may see this owner.
	 */
	protected function denyOwner(int $ownerId): string
	{
		if (crmCanReadOwner($this->user, $ownerId)) {
			return '';
		}
		return 'Permission denied for this owner';
	}

	/**
	 * Load a deal the caller may see.
	 */
	protected function loadDeal(int $id): ?Opportunity
	{
		require_once __DIR__.'/../opportunity.class.php';
		$deal = new Opportunity($this->db);
		if ($id <= 0 || $deal->fetch($id) <= 0) {
			return null;
		}
		if ($this->denyOwner((int) $deal->fk_user_resp) !== '') {
			return null;
		}
		return $deal;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array{0:int,1:int}
	 */
	protected function range(array $args): array
	{
		$from = $this->argDate($args, 'from');
		$to = $this->argDate($args, 'to');
		if ($from <= 0) {
			$parts = dol_getdate(dol_now());
			$from = (int) dol_mktime(0, 0, 0, 1, 1, (int) $parts['year']);
		}
		if ($to <= 0) {
			$to = crmTodayStart() + 86400;
		} elseif ($to <= $from) {
			$to = $from + 86400;
		}
		return array($from, $to);
	}
}
