<?php
declare(strict_types=1);

/**
 * \file    test/cli/agent_check.php
 * \ingroup crm
 * \brief   From v1.2.0. The outside agent (rights assigned, note, mystep): the deals they see (those with a step of theirs not done),
 *          their steps, who may be assigned a step, what they may change, their notes, and the e-mail to the assignee. Users are
 *          real rows of a rolled-back transaction with their rights loaded as Dolibarr loads them. See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/crmactivity.class.php';
require_once __DIR__.'/../../class/crmnote.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
$p = $db->prefix();
$rightId = $db->query('SELECT id FROM '.$p."rights_def WHERE module = 'crm' AND perms = 'mystep'");
crmCliCheck('the new rights exist (enable the module again)', $rightId && $db->num_rows($rightId) === 1);
if (!$rightId || $db->num_rows($rightId) !== 1) {
	crmCliFinish();
}
$db->begin();
$entity = (int) $conf->entity;
$ids = array('read' => 10480101, 'write' => 10480102, 'activity' => 10480105, 'assigned' => 10480109, 'note' => 10480110, 'mystep' => 10480111);
$mkUser = function (string $login, array $perms) use ($db, $p, $entity, $ids): User {
	$db->query('INSERT INTO '.$p."user (entity, login, lastname, firstname, email, statut, datec) VALUES (".$entity.", '".$db->escape($login)."', 'Z".$db->escape($login)."', 'T', '".$db->escape($login)."@example.com', 1, '".$db->idate(dol_now())."')");
	$id = (int) $db->last_insert_id($p.'user');
	foreach ($perms as $perm) {
		$db->query('INSERT INTO '.$p.'user_rights (entity, fk_user, fk_id) VALUES ('.$entity.', '.$id.', '.$ids[$perm].')');
	}
	$u = new User($db);
	$u->fetch($id);
	$u->loadRights();
	return $u;
};
$agent = $mkUser('zagent', array('assigned', 'note', 'mystep'));
$sales = $mkUser('zsales', array('read', 'write', 'activity'));
$other = $mkUser('znorights', array());
crmCliCheck('the agent has the three new rights and no more', $agent->hasRight('crm', 'assigned') && $agent->hasRight('crm', 'note') && $agent->hasRight('crm', 'mystep') && !$agent->hasRight('crm', 'read') && !$agent->hasRight('crm', 'write') && !$agent->hasRight('crm', 'activity') && !$agent->hasRight('crm', 'sequence'));

$pipelineId = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$mkDeal = function (string $label, int $owner) use ($db, $user, $pipelineId): Opportunity {
	$d = new Opportunity($db);
	$d->label = $label;
	$d->fk_pipeline = $pipelineId;
	$d->fk_user_resp = $owner;
	$d->create($user);
	return $d;
};
$d1 = $mkDeal('ZAGENTDEAL1', (int) $sales->id);
$d2 = $mkDeal('ZAGENTDEAL2', (int) $sales->id);
$steps = new CrmActivity($db);
// The salesperson assigns a call to the agent on the first deal, keeps one for themselves, and has one on the second deal.
$s1 = $steps->createStep($sales, (int) $d1->id, 'call', 'Call the prospect', dol_now() + 3600, (int) $agent->id);
$s2 = $steps->createStep($sales, (int) $d1->id, 'task', 'Prepare the offer', dol_now() + 7200, (int) $sales->id);
$s3 = $steps->createStep($sales, (int) $d2->id, 'call', 'Other deal call', dol_now() + 3600, (int) $sales->id);
crmCliCheck('a salesperson creates steps and assigns one to the agent', $s1 > 0 && $s2 > 0 && $s3 > 0 && (int) $db->fetch_object($db->query('SELECT fk_user_action FROM '.$p.'actioncomm WHERE id = '.$s1))->fk_user_action === (int) $agent->id);
crmCliCheck('the default assignee is the owner of the deal', ($e = $steps->createStep($sales, (int) $d1->id, 'task', 'Default assignee', dol_now() + 9000)) > 0 && (int) $db->fetch_object($db->query('SELECT fk_user_action FROM '.$p.'actioncomm WHERE id = '.$e))->fk_user_action === (int) $sales->id);

// What the agent sees.
crmCliCheck('the agent reads the deal that has a step of theirs not done', crmCanReadOwner($agent, (int) $sales->id, (int) $d1->id));
crmCliCheck('and not the other deal', !crmCanReadOwner($agent, (int) $sales->id, (int) $d2->id) && !crmCanReadOwner($agent, (int) $sales->id));
$list = (new Opportunity($db))->fetchList($agent, '', 0, 0, '', 0);
$listed = array_map(function ($r) {
	return (int) $r->rowid;
}, $list);
crmCliCheck('their list of deals has only that deal', in_array((int) $d1->id, $listed, true) && !in_array((int) $d2->id, $listed, true));
$mine = $steps->fetchList($agent, 'planned', 0, 0, 0, 'date', 'ASC');
crmCliCheck('their list of steps has only the steps assigned to them', count($mine) === 1 && (int) $mine[0]->rowid === $s1 && trim((string) $mine[0]->assignee_login) === 'zagent');
crmCliCheck('a user with no CRM right sees nothing', !crmCanReadOwner($other, (int) $sales->id, (int) $d1->id) && (new Opportunity($db))->countList($other) === 0);
$onDeal = array_map(function ($r) {
	return (int) $r->rowid;
}, $steps->fetchForOpportunity((int) $d1->id));
crmCliCheck('the steps of the deal list their assignee', in_array($s1, $onDeal, true) && in_array($s2, $onDeal, true) && trim((string) $steps->fetchForOpportunity((int) $d1->id)[0]->assignee_login) !== '');

// What the agent may change.
crmCliCheck('may change: own step yes, a colleague\'s no, a salesperson any', CrmActivity::mayChangeStep($agent, (int) $agent->id) && !CrmActivity::mayChangeStep($agent, (int) $sales->id) && CrmActivity::mayChangeStep($sales, (int) $agent->id) && !CrmActivity::mayChangeStep($other, (int) $other->id));
crmCliCheck('the agent cannot complete a step that is not theirs', $steps->complete($agent, $s2, (int) $d1->id) < 0 && $steps->error === 'CrmErrorStepRights');
crmCliCheck('nor delete one (not even their own)', $steps->deleteStep($agent, $s1, (int) $d1->id) < 0 && $steps->error === 'CrmErrorStepRights');
$when = dol_now() + 86400 * 2;
crmCliCheck('the agent reschedules their step: the date changes, title and assignee do not', $steps->updateStep($agent, $s1, 'meeting', 'Hacked title', $when, (int) $d1->id, (int) $sales->id) === 1
	&& (string) $db->fetch_object($db->query('SELECT label FROM '.$p.'actioncomm WHERE id = '.$s1))->label === 'Call the prospect'
	&& (int) $db->fetch_object($db->query('SELECT fk_user_action FROM '.$p.'actioncomm WHERE id = '.$s1))->fk_user_action === (int) $agent->id
	&& abs((int) $db->jdate($db->fetch_object($db->query('SELECT datep FROM '.$p.'actioncomm WHERE id = '.$s1))->datep) - $when) < 5);
crmCliCheck('a salesperson reschedules, renames and reassigns', $steps->updateStep($sales, $s1, 'call', 'Call again', $when, (int) $d1->id, (int) $sales->id) === 1
	&& (string) $db->fetch_object($db->query('SELECT label FROM '.$p.'actioncomm WHERE id = '.$s1))->label === 'Call again'
	&& (int) $db->fetch_object($db->query('SELECT fk_user_action FROM '.$p.'actioncomm WHERE id = '.$s1))->fk_user_action === (int) $sales->id);
$s4 = $steps->createStep($sales, (int) $d1->id, 'call', 'Second call for the agent', dol_now() + 5000, (int) $agent->id);

// Creating steps and choosing who does them.
$assignable = $steps->assignableUsers($sales, (int) $d1->id);
crmCliCheck('a salesperson may assign to the agent and to colleagues, the owner first', isset($assignable[(int) $agent->id]) && isset($assignable[(int) $sales->id]) && !isset($assignable[(int) $other->id]) && array_key_first($assignable) === (int) $sales->id);
$agentChoices = $steps->assignableUsers($agent, (int) $d1->id);
crmCliCheck('the agent may only choose themselves or the owner', array_keys($agentChoices) === array((int) $sales->id, (int) $agent->id) || (count($agentChoices) === 2 && isset($agentChoices[(int) $agent->id]) && isset($agentChoices[(int) $sales->id])));
$a1 = $steps->createStep($agent, (int) $d1->id, 'call', 'Agent follow-up', dol_now() + 4000, (int) $agent->id);
$a2 = $steps->createStep($agent, (int) $d1->id, 'call', 'Agent hands over to the owner', dol_now() + 4100, (int) $sales->id);
$a3 = $steps->createStep($agent, (int) $d1->id, 'call', 'Agent assigns to a third person', dol_now() + 4200, (int) $other->id);
crmCliCheck('the agent creates a step for themselves and for the owner, not for anybody else', $a1 > 0 && $a2 > 0 && $a3 < 0 && $steps->error === 'CrmErrorAssignee');
crmCliCheck('the agent cannot create a step on a deal they do not see', $steps->createStep($agent, (int) $d2->id, 'call', 'x', dol_now() + 4300, (int) $agent->id) < 0 && $steps->error === 'CrmErrorEventNotFound');
crmCliCheck('a user without the rights creates nothing', $steps->createStep($other, (int) $d1->id, 'call', 'x', dol_now() + 4300, (int) $sales->id) < 0 && $steps->error === 'CrmErrorStepRights');
crmCliCheck('a salesperson cannot assign to a user who may not handle steps', $steps->createStep($sales, (int) $d1->id, 'call', 'x', dol_now() + 4300, (int) $other->id) < 0 && $steps->error === 'CrmErrorAssignee');

// Completing: the deal leaves the agent's view when their last step is done.
crmCliCheck('the agent completes their own steps', $steps->complete($agent, $s4, (int) $d1->id) === 1 && $steps->complete($agent, $a1, (int) $d1->id) === 1);
crmCliCheck('with no step of theirs left to do, the deal is no longer theirs to read', !crmCanReadOwner($agent, (int) $sales->id, (int) $d1->id));
$s5 = $steps->createStep($sales, (int) $d1->id, 'call', 'Third call for the agent', dol_now() + 6000, (int) $agent->id);

// Notes.
$notes = new CrmNote($db);
$n1 = $notes->add($agent, (int) $d1->id, 'Agent note after the call');
crmCliCheck('the agent adds a note on a deal they see', $n1 > 0);
crmCliCheck('and edits their own note', $notes->update($agent, $n1, 'Agent note, corrected') === 1);
$ownerNote = $notes->add($sales, (int) $d1->id, 'Note of the salesperson');
crmCliCheck('but not the note of somebody else', $notes->update($agent, $ownerNote, 'Changed by the agent') < 0 && $notes->error === 'CrmErrorNoteRights');
crmCliCheck('the agent reads the notes of the deal', count($notes->fetchForOpportunity($agent, (int) $d1->id)) === 2);
crmCliCheck('no note on a deal they do not see', $notes->add($agent, (int) $d2->id, 'x') < 0 && $notes->error === 'CrmErrorNoteRights');
crmCliCheck('a user without the note right adds none', $notes->add($other, (int) $d1->id, 'x') < 0);

// The e-mail to the assignee (it follows the reminder dry-run: simulated here, nothing is sent).
$conf->global->CRM_REMINDER_DRY_RUN = '1';
$conf->global->CRM_ASSIGN_NOTIFY = '1';
$steps->notifyAssignee($sales, (int) $agent->id, $s5, (int) $d1->id, 'Third call for the agent', dol_now() + 6000);
crmCliCheck('the notification runs in dry-run without sending or failing', true);
$conf->global->CRM_ASSIGN_NOTIFY = '0';
$steps->notifyAssignee($sales, (int) $agent->id, $s5, (int) $d1->id, 'x', dol_now());
crmCliCheck('and does nothing when it is switched off', true);

// The page right: the outside agent may open Home, Activities and the deal card, and nothing else.
crmCliCheck('the agent is let in by the pages that allow them, not by the others', !crmCanReadOwner($agent, 0) && ($agent->hasRight('crm', 'assigned') && !$agent->hasRight('crm', 'read')));

$db->rollback();
crmCliFinish();
