<?php
declare(strict_types=1);

/**
 * \file    test/cli/internal_ref_check.php
 * \ingroup crm
 * \brief   From v1.2.0. The internal reference of a deal (number of its task in another system): saved on create and update, cleaned,
 *          searched, shown as a link only from a valid http(s) base address, and ignored before schema step 7. Rolled back.
 *          See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
crmCliCheck('the column exists and schema step 7 ran (enable the module again)', crmInternalRefSupported($db));
if (!crmInternalRefSupported($db)) {
	crmCliFinish();
}
$db->begin();
$prefix = $db->prefix();
$pipelineId = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$deal = new Opportunity($db);
$deal->label = 'ZINTREF';
$deal->fk_pipeline = $pipelineId;
$deal->fk_user_resp = (int) $user->id;
$deal->ref_internal = "  <i>TASK-4711</i>\n";
crmCliCheck('a deal is created with an internal reference', $deal->create($user) > 0);
$loaded = new Opportunity($db);
$loaded->fetch((int) $deal->id);
crmCliCheck('it is stored cleaned: one line, no markup', $loaded->ref_internal === 'TASK-4711');
$loaded->ref_internal = 'TASK-9/2 x';
crmCliCheck('it can be changed', $loaded->update($user) === 1);
$again = new Opportunity($db);
$again->fetch((int) $deal->id);
crmCliCheck('and read back', $again->ref_internal === 'TASK-9/2 x');
crmCliCheck('the list search finds a deal by its internal reference', in_array((int) $deal->id, array_map(function ($d) {
	return (int) $d->rowid;
}, $again->fetchList($user, '', 0, 0, 'TASK-9/2', 0)), true));
$again->ref_internal = '';
$again->update($user);
$empty = new Opportunity($db);
$empty->fetch((int) $deal->id);
crmCliCheck('an empty reference clears it (NULL)', $empty->ref_internal === '' && $db->fetch_object($db->query('SELECT ref_internal FROM '.$prefix.'crm_opportunity WHERE rowid = '.((int) $deal->id)))->ref_internal === null);
$long = new Opportunity($db);
$long->fetch((int) $deal->id);
$long->ref_internal = str_repeat('x', 400);
$long->update($user);
$long2 = new Opportunity($db);
$long2->fetch((int) $deal->id);
crmCliCheck('it is cut at 128 characters', strlen($long2->ref_internal) === 128);

crmCliCheck('a link needs a valid base address', crmInternalRefUrl('https://t.example.com/browse/', 'TASK-1') === 'https://t.example.com/browse/TASK-1' && crmInternalRefUrl('javascript:alert(1)//', 'TASK-1') === '');

// Before the schema step the column is not used (and nothing fails).
$installed = $conf->global->CRM_SCHEMA_VERSION;
$conf->global->CRM_SCHEMA_VERSION = '6';
$old = new Opportunity($db);
$old->label = 'ZINTREFOLD';
$old->fk_pipeline = $pipelineId;
$old->fk_user_resp = (int) $user->id;
$old->ref_internal = 'IGNORED-1';
$oldId = $old->create($user);
crmCliCheck('before step 7 a deal is still created, without the reference', !crmInternalRefSupported($db) && $oldId > 0 && $db->fetch_object($db->query('SELECT ref_internal FROM '.$prefix.'crm_opportunity WHERE rowid = '.$oldId))->ref_internal === null);
$conf->global->CRM_SCHEMA_VERSION = $installed;
crmCliCheck('the step is safe to run twice', crmSchemaStep7($db) && crmSchemaStep7($db));

$db->rollback();
crmCliFinish();
