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
$crmNames = array_values(array_filter($names, static fn($name) => strpos($name, 'crm_') === 0));

crmCliCheck('the core tools are there', count($names) > count($crmNames));
crmCliCheck('the CRM tools are registered ('.count($crmNames).')', count($crmNames) >= 50);
crmCliCheck('a tool of each class is listed', count(array_intersect($crmNames, array('crm_list_pipelines', 'crm_list_opportunities', 'crm_list_activities', 'crm_list_sequences', 'crm_get_dashboard'))) === 5);
crmCliCheck('no tool name is registered twice', count($names) === count(array_unique($names)));

crmCliFinish();
