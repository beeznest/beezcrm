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
		// One line, no markup: labels, subjects, references and codes are printed in titles and sent in mail headers.
		return crmSingleLine(strip_tags((string) $args[$key]));
	}

	/**
	 * A free text of several lines (a description, a note): no markup, line breaks kept.
	 *
	 * @param array<string,mixed> $args Arguments
	 */
	protected function argText(array $args, string $key): string
	{
		if (!isset($args[$key]) || is_array($args[$key])) {
			return '';
		}
		$text = str_replace(array("\r\n", "\r"), "\n", strip_tags((string) $args[$key]));
		return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text));
	}

	/**
	 * The body of an e-mail step: plain text, or HTML cleaned of scripts, event handlers and active content.
	 *
	 * @param array<string,mixed> $args Arguments
	 */
	protected function argBody(array $args, string $key): string
	{
		if (!isset($args[$key]) || is_array($args[$key])) {
			return '';
		}
		$body = trim((string) $args[$key]);
		return crmLooksLikeHtml($body) ? dol_htmlwithnojs($body) : $this->argText($args, $key);
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
	 * The tools of the class, without the delete tools while deleting through MCP is not allowed (setting CRM_MCP_ALLOW_DELETE).
	 * Each tool class lists its tools in crmDefinitions(), delete tools included; every tool whose name starts with `delete_` is
	 * held back here, so a new delete tool is gated just by being named so.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function getDefinitions(): array
	{
		$definitions = $this->crmDefinitions();
		if ($this->deleteAllowed()) {
			return $definitions;
		}
		return array_values(array_filter($definitions, static function (array $definition): bool {
			return strncmp((string) ($definition['name'] ?? ''), 'delete_', 7) !== 0;
		}));
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	abstract protected function crmDefinitions(): array;

	/**
	 * From v1.2.0. True when an administrator allowed the MCP tools that delete CRM records (setting CRM_MCP_ALLOW_DELETE, off by
	 * default, neither readable nor writable through MCP). Every tool named `delete_*` is only listed (getDefinitions()) when this is
	 * true and refuses (denyMissingRights()) when it is not, so one switch covers them all, the present ones and the future ones.
	 */
	protected function deleteAllowed(): bool
	{
		return getDolGlobalInt('CRM_MCP_ALLOW_DELETE') === 1;
	}

	/**
	 * The refusal a gated delete tool returns while the setting is off.
	 *
	 * @return array{error:string}
	 */
	protected function deleteNotAllowed(): array
	{
		return $this->fail('Deleting records through MCP is switched off: an administrator has to allow it in CRM → Setup → Parameters');
	}

	/**
	 * @return array{error:string}
	 */
	protected function fail(string $message): array
	{
		return array('error' => strncmp($message, 'CrmError', 8) === 0 ? crmErrorText($message) : $message);
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
		// Every tool starts with this check: a delete tool is refused whatever its rights, while the setting is off.
		if (strncmp($toolName, 'delete_', 7) === 0 && !$this->deleteAllowed()) {
			return $this->deleteNotAllowed();
		}
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
