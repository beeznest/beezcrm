<?php
declare(strict_types=1);

/**
 * \file    test/cli/mcp_tools_registration_check.php
 * \ingroup crm
 * \brief   The CRM tools are registered by the MCP handler of the core it runs against. Run it against every core the
 *          module supports (see "Core compatibility" in AGENTS.md): the 24.0.0 loader only accepts the tools wrapped in
 *          an array, a flat list of tool objects registers nothing there. Read-only. See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
require_once DOL_DOCUMENT_ROOT.'/ai/class/mcp.class.php';

print 'Dolibarr '.DOL_VERSION."\n";
$handler = new McpHandler($db, $user, $conf, McpHandler::CTX_MCP_SERVER);
// Newer cores load the tools when asked, older ones in the constructor.
if ((new ReflectionMethod($handler, 'loadTools'))->isPublic()) {
	$handler->loadTools();
}
$names = array();
foreach ($handler->getToolsSchemaUnfiltered() as $definition) {
	$names[] = (string) ($definition['name'] ?? '');
}
$crmNames = array_values(array_filter($names, static fn($name) => strpos($name, '_crm_') !== false));

crmCliCheck('the core tools are there', count($names) > count($crmNames));
crmCliCheck('the CRM tools are registered ('.count($crmNames).')', count($crmNames) >= 49);
crmCliCheck('a tool of each class is listed', count(array_intersect($crmNames, array('list_crm_pipelines', 'list_crm_opportunities', 'list_crm_activities', 'list_crm_sequences', 'get_crm_dashboard'))) === 5);
crmCliCheck('no tool name is registered twice', count($names) === count(array_unique($names)));

// The tool access screen of Dolibarr labels a tool "Modify" when its name starts with one of these verbs, and its "View
// only" preset allows every other name. A tool that writes must start with one, a tool that only reads must not. Which
// tool writes is known from its confirmation preview, an API that only cores from 25 have.
$loaded = new ReflectionProperty($handler, 'loadedTools');
$loaded->setAccessible(true);
if (defined('McpTool::NO_WRITE')) {
	$writeVerbs = '/^(create|update|delete|add|remove|change|write|edit|validate|pay|send)/i';
	$misnamed = array();
	$writes = 0;
	foreach ($loaded->getValue($handler) as $tool) {
		foreach ($tool->getDefinitions() as $definition) {
			$name = (string) $definition['name'];
			if (strpos($name, '_crm_') === false) {
				continue;
			}
			$writes += $tool->writeConfirmationPreview($name, array()) !== McpTool::NO_WRITE ? 1 : 0;
			if ((bool) preg_match($writeVerbs, $name) !== ($tool->writeConfirmationPreview($name, array()) !== McpTool::NO_WRITE)) {
				$misnamed[] = $name;
			}
		}
	}
	crmCliCheck('a tool that writes starts with a write verb and a read tool does not'.($misnamed ? ' ('.implode(', ', $misnamed).')' : ''), count($misnamed) === 0 && $writes === 35);
} else {
	print "SKIP names against the write verbs: this core has no write confirmation API (run it on a 25 core too)\n";
}

// Every registered name reaches code of its own: called without arguments, a tool answers with its own error, never with
// "Unknown tool". The two tools that run the scheduled jobs are left out. A transaction undoes anything a call did.
$db->begin();
$unknown = array();
foreach ($loaded->getValue($handler) as $tool) {
	foreach ($tool->getDefinitions() as $definition) {
		$name = (string) $definition['name'];
		if (strpos($name, '_crm_') === false || strpos($name, 'send_crm_') === 0) {
			continue;
		}
		$result = $tool->execute($name, array());
		if (is_array($result) && ($result['error'] ?? '') === 'Unknown tool') {
			$unknown[] = $name;
		}
	}
}
crmCliCheck('every tool name is handled by its class'.($unknown ? ' (unknown: '.implode(', ', $unknown).')' : ''), count($unknown) === 0);

crmCliFinish();
