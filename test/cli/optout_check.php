<?php
declare(strict_types=1);

/**
 * \file    test/cli/optout_check.php
 * \ingroup crm
 * \brief   From v1.2.0. The public "not interested" link: the link of a deal (stable, only the hash of the token is stored), and
 *          what recording the request does (lost stage, reason, done event, outreach stopped, closed deals untouched,
 *          wrong tokens refused). Rolled back. See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/stage.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/sequence.class.php';
require_once __DIR__.'/../../class/crmoptout.class.php';

global $langs;
/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
crmCliCheck('the table exists and schema step 5 ran (enable the module again)', CrmOptOut::supported($db));
if (!CrmOptOut::supported($db)) {
	crmCliFinish();
}
$db->begin();
$prefix = $db->prefix();
$entity = (int) $conf->entity;
$optOut = new CrmOptOut($db);
$pipelineId = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$mkDeal = function (string $label) use ($db, $user, $pipelineId): Opportunity {
	$d = new Opportunity($db);
	$d->label = $label;
	$d->fk_pipeline = $pipelineId;
	$d->fk_user_resp = (int) $user->id;
	$d->create($user);
	return $d;
};
$tokenOf = function (string $url): string {
	return (string) preg_replace('/^.*optout\.php\?h=/', '', $url);
};

$res = $db->query('SELECT rowid FROM '.$prefix.'crm_loss_reason WHERE entity = '.$entity." AND code = 'NOTINTERESTED'");
crmCliCheck('the loss reason "Not interested" exists', $res && $db->num_rows($res) === 1);
crmCliCheck('running its step again adds nothing', crmSchemaStep5($db) && $db->num_rows($db->query('SELECT rowid FROM '.$prefix.'crm_loss_reason WHERE entity = '.$entity." AND code = 'NOTINTERESTED'")) === 1);

$deal = $mkDeal('ZOPTOUT');
$url = $optOut->urlForDeal((int) $deal->id);
$token = $tokenOf($url);
crmCliCheck('the link is an address of the page with one hash', strpos($url, 'optout.php?h=') !== false && crmOptOutHashValid($token) && substr_count($url, '?') === 1 && strpos($url, '&') === false);
crmCliCheck('the same deal always gets the same link', $optOut->urlForDeal((int) $deal->id) === $url);
$stored = $db->fetch_object($db->query('SELECT token_hash FROM '.$prefix.'crm_optout WHERE fk_opportunity = '.((int) $deal->id)));
crmCliCheck('only the SHA-256 of the token is stored', $stored && $stored->token_hash === hash('sha256', $token) && $stored->token_hash !== $token);
$other = $mkDeal('ZOPTOUT2');
crmCliCheck('another deal gets another link', $tokenOf($optOut->urlForDeal((int) $other->id)) !== $token);
crmCliCheck('a link cannot be made for a deal that does not exist', $optOut->urlForDeal(99999999) === '');
crmCliCheck('find() knows the token and nothing else', $optOut->find($token) !== null && (int) $optOut->find($token)->fk_opportunity === (int) $deal->id
	&& $optOut->find(strtoupper($token)) === null && $optOut->find(str_repeat('0', 64)) === null && $optOut->find($token.'0') === null && $optOut->find("' OR '1'='1") === null);
crmCliCheck('a wrong token records nothing', $optOut->record(str_repeat('0', 64)) === 'invalid' && $optOut->record('abc') === 'invalid');
crmCliCheck('the text of the link is the one asked for', crmOptOutLinkText((int) $deal->id) !== '' && strpos(crmOptOutLinkText((int) $deal->id), 'contact') !== false);

// The language of the link: the contact's, else the third party's, else the current one.
$db->query('INSERT INTO '.$prefix."societe (nom, entity, default_lang, datec) VALUES ('ZOPTLANGSOC', ".$entity.", 'en_US', '".$db->idate(dol_now())."')");
$langSoc = (int) $db->last_insert_id($prefix.'societe');
$db->query('INSERT INTO '.$prefix."socpeople (fk_soc, entity, lastname, default_lang, datec, fk_user_creat) VALUES (".$langSoc.', '.$entity.", 'ZOPTLANG', '', '".$db->idate(dol_now())."', ".((int) $user->id).')');
$langContact = (int) $db->last_insert_id($prefix.'socpeople');
$langDeal = $mkDeal('ZOPTLANGDEAL');
$db->query('UPDATE '.$prefix.'crm_opportunity SET fk_soc = '.$langSoc.', fk_socpeople = '.$langContact.' WHERE rowid = '.((int) $langDeal->id));
$tr = function (string $code) use ($conf): string {
	$t = new Translate('', $conf);
	$t->setDefaultLang($code);
	$t->load('crm@crm');
	return $t->transnoentitiesnoconv('CrmOptOutLink');
};
crmCliCheck('the two languages give different texts (the check is meaningful)', $tr('fr_FR') !== $tr('en_US'));
crmCliCheck('without a language on the contact, the language of the third party', crmOptOutLinkText((int) $langDeal->id) === $tr('en_US'));
$db->query('UPDATE '.$prefix."socpeople SET default_lang = 'fr_FR' WHERE rowid = ".$langContact);
crmCliCheck('with a language on the contact, the contact wins over the third party', crmOptOutLinkText((int) $langDeal->id) === $tr('fr_FR'));
$db->query('UPDATE '.$prefix."socpeople SET default_lang = 'xx' WHERE rowid = ".$langContact);
crmCliCheck('a language that is not a language code is ignored', crmOptOutLinkText((int) $langDeal->id) === $tr('en_US'));
$db->query('UPDATE '.$prefix."societe SET default_lang = '' WHERE rowid = ".$langSoc);
crmCliCheck('with no language anywhere, the current language', crmOptOutLinkText((int) $langDeal->id) === $langs->transnoentitiesnoconv('CrmOptOutLink'));

// An outreach running on the deal.
$sequence = new Sequence($db);
$sequence->ref = 'ZOPTSEQ';
$sequence->label = 'Z';
$sequence->create($user);
$sequence->addStep('task', 0, 'a', '');
$sequence->enrol((int) $deal->id);
$running = function () use ($db, $prefix, $deal): int {
	return (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix."crm_sequence_enrol WHERE fk_opportunity = ".((int) $deal->id)." AND status = 'running'"))->nb;
};
crmCliCheck('the outreach is running', $running() === 1);

crmCliCheck('the request is recorded', $optOut->record($token) === 'recorded');
$row = $db->fetch_object($db->query('SELECT o.status, o.date_close, r.code FROM '.$prefix.'crm_opportunity o LEFT JOIN '.$prefix.'crm_loss_reason r ON r.rowid = o.fk_loss_reason WHERE o.rowid = '.((int) $deal->id)));
crmCliCheck('the deal is lost, with the reason "Not interested" and a close date', $row && $row->status === 'lost' && $row->code === 'NOTINTERESTED' && $row->date_close !== null);
$hist = $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix.'crm_stage_history WHERE fk_opportunity = '.((int) $deal->id).' AND fk_user = '.((int) $user->id)));
crmCliCheck('the move is in the stage history', (int) $hist->nb >= 2);
$eventLabel = $db->escape(crmSingleLine(strip_tags($langs->transnoentitiesnoconv('CrmOptOutEvent')), 255));
$eventCount = function () use ($db, $prefix, $deal, $eventLabel): int {
	return (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix."actioncomm WHERE elementtype = 'opportunity@crm' AND fk_element = ".((int) $deal->id)." AND percent = 100 AND label = '".$eventLabel."'"))->nb;
};
crmCliCheck('a done event on the deal says why', $eventCount() === 1);
crmCliCheck('the outreach is stopped, with the reason', $running() === 0 && $db->fetch_object($db->query('SELECT stop_reason FROM '.$prefix.'crm_sequence_enrol WHERE fk_opportunity = '.((int) $deal->id)))->stop_reason === 'optout');
crmCliCheck('the link is marked used', $db->fetch_object($db->query('SELECT date_used FROM '.$prefix.'crm_optout WHERE fk_opportunity = '.((int) $deal->id)))->date_used !== null);
crmCliCheck('using it again changes nothing', $optOut->record($token) === 'already' && $eventCount() === 1);

// A closed deal is never changed by a link.
$wonStage = 0;
foreach ((new Stage($db))->fetchAll($pipelineId) as $stage) {
	if ((int) $stage->is_won === 1 && $wonStage === 0) {
		$wonStage = (int) $stage->id;
	}
}
$won = $mkDeal('ZOPTWON');
$wonToken = $tokenOf($optOut->urlForDeal((int) $won->id));
$won->moveToStage($user, $wonStage);
crmCliCheck('a won deal stays won', $optOut->record($wonToken) === 'already' && $db->fetch_object($db->query('SELECT status FROM '.$prefix.'crm_opportunity WHERE rowid = '.((int) $won->id)))->status === 'won');

// Deleting the deal... the link goes with it (nothing orphaned is usable).
$gone = $mkDeal('ZOPTGONE');
$goneToken = $tokenOf($optOut->urlForDeal((int) $gone->id));
$gone->delete($user);
crmCliCheck('the link of a deleted deal is refused', $optOut->record($goneToken) === 'invalid');

// The editor can leave the link out of one message: the parameter exists, the link stays the default everywhere else.
require_once __DIR__.'/../../class/crmemaildraft.class.php';
$sendParams = (new ReflectionFunction('crmSendMail'))->getParameters();
$last = end($sendParams);
crmCliCheck('crmSendMail has the optOutLink parameter, true by default', $last->getName() === 'optOutLink' && $last->getDefaultValue() === true);
$draftParams = (new ReflectionMethod('CrmEmailDraft', 'send'))->getParameters();
$lastDraft = end($draftParams);
crmCliCheck('CrmEmailDraft::send passes it on, true by default', $lastDraft->getName() === 'optOutLink' && $lastDraft->getDefaultValue() === true);
$source = (string) file_get_contents(__DIR__.'/../../class/sequence.class.php').(string) file_get_contents(__DIR__.'/../../class/crmactivity.class.php');
crmCliCheck('outreach and reminders never pass false', strpos($source, 'optOutLink') === false);

// The rate limit of the public page: 10 unknown links or 120 requests per 10 minutes and per address, state in small files.
require_once __DIR__.'/../../class/crmoptoutlimit.class.php';
$limitDir = sys_get_temp_dir().'/crm_optout_limit_'.bin2hex(random_bytes(4));
$t = 1000000;
$one = new CrmOptOutLimit('203.0.113.7', $limitDir);
$allowed = 0;
for ($i = 0; $i < 10; $i++) {
	$allowed += $one->enter($t + $i) ? 1 : 0;
	$one->fail($t + $i);
}
crmCliCheck('ten unknown links in a row are answered', $allowed === 10);
crmCliCheck('the eleventh request is refused', $one->enter($t + 11) === false);
crmCliCheck('another address is not affected', (new CrmOptOutLimit('203.0.113.8', $limitDir))->enter($t + 11) === true);
crmCliCheck('the same address is served again once the window has passed', $one->enter($t + 11 + $one->window()) === true);
$files = glob($limitDir.'/ip_*.json');
crmCliCheck('one file per address, named by a hash, with no address in it', count($files) === 2 && strpos((string) file_get_contents($files[0]), '203.0.113') === false && strpos($files[0], '203.0.113') === false);
$many = new CrmOptOutLimit('203.0.113.9', $limitDir);
$ok = 0;
for ($i = 0; $i < 130; $i++) {
	$ok += $many->enter($t + $i) ? 1 : 0;
}
crmCliCheck('120 requests per window at most, known links included', $ok === 120);
crmCliCheck('an empty address turns the limit off', (new CrmOptOutLimit('', $limitDir))->enter($t) === true);
$conf->global->CRM_OPTOUT_RATE_FAILS = '0';
$conf->global->CRM_OPTOUT_RATE_ALL = '0';
$free = new CrmOptOutLimit('203.0.113.10', $limitDir);
for ($i = 0; $i < 300; $i++) {
	$free->fail($t + $i);
}
crmCliCheck('0 turns each limit off', $free->enter($t + 301) === true);
$conf->global->CRM_OPTOUT_RATE_FAILS = '';
$conf->global->CRM_OPTOUT_RATE_ALL = '';
$blocked = new CrmOptOutLimit('203.0.113.11', '/proc/crm_not_writable');
crmCliCheck('an unwritable folder lets the page work', $blocked->enter($t) === true);
foreach ((array) glob($limitDir.'/*') as $file) {
	@unlink($file);
}
@rmdir($limitDir);
crmCliCheck('a log shows the last 4 characters of a link only', crmOptOutTokenTail($goneToken) === '...'.substr($goneToken, -4) && strlen(crmOptOutTokenTail($goneToken)) === 7);

$db->rollback();
crmCliFinish();
