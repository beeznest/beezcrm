<?php
declare(strict_types=1);

/**
 * \file    test/cli/mail_attachments_check.php
 * \ingroup crm
 * \brief   From v1.2.0. Files attached to an e-mail written in the editor: kept on disk with the draft, limits and rights, handed to
 *          Dolibarr's mail class, following the message when the draft is replaced by the sent one, deleted with a discarded
 *          draft or a deleted deal. Rolled back; the files written are removed by the check. See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/crmactivity.class.php';
require_once __DIR__.'/../../class/crmemaildraft.class.php';
require_once __DIR__.'/../../class/crmmailfile.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
crmCliCheck('the table exists and schema step 8 ran (enable the module again)', crmMailFileSupported($db));
if (!crmMailFileSupported($db)) {
	crmCliFinish();
}
$db->begin();
$prefix = $db->prefix();
$files = new CrmMailFile($db);
$deal = new Opportunity($db);
$deal->label = 'ZMAILFILE';
$deal->fk_pipeline = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$deal->fk_user_resp = (int) $user->id;
$deal->create($user);
$dealId = (int) $deal->id;
$activity = new CrmActivity($db);
$draftId = $activity->create($user, $dealId, 0, 0, (int) $user->id, 'email', 'Draft with files', dol_now() + 3600, 0, 'Body');
$tmp = function (string $content): string {
	$path = tempnam(sys_get_temp_dir(), 'crmmail');
	file_put_contents($path, $content);
	return $path;
};

$pdf = $files->add($user, $dealId, $draftId, '../my offer.pdf', $tmp('%PDF-1.4 test'), 0, false);
crmCliCheck('a file is attached to the draft', $pdf > 0);
$row = $files->fetchFile($user, $pdf);
crmCliCheck('its name is cleaned, its size and type kept', $row && strpos((string) $row->filename, '/') === false && strpos((string) $row->filename, '..') === false && (int) $row->filesize === 13 && (string) $row->mimetype === 'application/pdf');
crmCliCheck('it is on disk, in the mail folder of the deal', $row && is_file($files->path($row)) && strpos($files->path($row), '/opportunity/'.$dealId.'/mail/') !== false);
$txt = $files->add($user, $dealId, $draftId, 'notes.txt', $tmp('hello'), 0, false);
crmCliCheck('a second file', $txt > 0 && count($files->fetchForAction($draftId)) === 2);

// What Dolibarr's mail class gets.
$send = $files->forSend($draftId);
crmCliCheck('the files are handed to the mail class: path, mime type, name', count($send) === 2 && is_file($send[0]['path']) && $send[0]['mime'] === 'application/pdf' && $send[0]['name'] === $row->filename);
$mail = new CMailFile('Subject', 'to@example.com', 'from@example.com', 'Body', array_column($send, 'path'), array_column($send, 'mime'), array_column($send, 'name'));
crmCliCheck('Dolibarr builds a message with the attachments', $mail->error === '' && count($mail->mimefilename_list ?? array()) === 2);

// Limits and rights.
crmCliCheck('an empty file is refused', $files->add($user, $dealId, $draftId, 'empty.txt', $tmp(''), 0, false) < 0 && $files->error === 'CrmErrorFileEmpty');
crmCliCheck('a file over the size limit is refused', $files->add($user, $dealId, $draftId, 'big.bin', $tmp(str_repeat('a', CrmNote::maxFileBytes() + 1)), 0, false) < 0 && $files->error === 'CrmErrorFileTooBig');
crmCliCheck('an upload error is reported', $files->add($user, $dealId, $draftId, 'x.txt', $tmp('x'), UPLOAD_ERR_INI_SIZE, false) < 0 && $files->error === 'CrmErrorFileTooBig');
crmCliCheck('a file cannot be attached to an event that is not a planned e-mail of the deal', $files->add($user, $dealId, 99999999, 'x.txt', $tmp('x'), 0, false) < 0);
$limited = new User($db);
$limited->id = (int) $user->id + 100000;
$limited->admin = 0;
$limited->rights = new stdClass();
$limited->rights->crm = new stdClass();
$limited->rights->crm->read = 1;
$limited->rights->crm->activity = 1;
crmCliCheck('a user who may not see the deal cannot attach to it or open its files', $files->add($limited, $dealId, $draftId, 'x.txt', $tmp('x'), 0, false) < 0 && $files->fetchFile($limited, $pdf) === null && $files->fetchForOpportunity($limited, $dealId) === array());
unset($limited->rights->crm->activity);
$limited->rights->crm->readall = 1;
crmCliCheck('without the right to act on steps nothing is attached', $files->add($limited, $dealId, $draftId, 'x.txt', $tmp('x'), 0, false) < 0);
for ($i = 0; $i < 12; $i++) {
	$files->add($user, $dealId, $draftId, 'f'.$i.'.txt', $tmp('x'), 0, false);
}
crmCliCheck('at most 10 files', count($files->fetchForAction($draftId)) === CrmMailFile::MAX_FILES && $files->error === 'CrmErrorMailFilesTooMany');

// Remove, then follow the message.
crmCliCheck('a file is removed from the draft, disk and row', $files->remove($user, $txt, $draftId) === 1 && $files->fetchFile($user, $txt) === null);
$draft = new CrmEmailDraft($db);
$draft->fetch($draftId);
$path = $files->path($files->fetchFile($user, $pdf));
crmCliCheck('the draft is registered as sent', $draft->markSentManually($user, 'to@example.com', 'Draft with files', 'Body') > 0);
$map = $files->fetchForOpportunity($user, $dealId);
$doneIds = array_keys($map);
crmCliCheck('the files follow the message: a new event, not the draft', count($doneIds) === 1 && $doneIds[0] !== $draftId && is_file($path) && $files->fetchFile($user, $pdf) !== null);
crmCliCheck('the message history reads that event', in_array($doneIds[0], array_map(function ($m) {
	return (int) $m->rowid;
}, $activity->fetchMessages($dealId)), true));
crmCliCheck('a sent message keeps its files: none can be removed', $files->remove($user, $pdf, $doneIds[0]) < 0 && is_file($path));

// A discarded draft takes its files.
$draft2 = $activity->create($user, $dealId, 0, 0, (int) $user->id, 'email', 'Second draft', dol_now() + 7200, 0, 'Body');
$other = $files->add($user, $dealId, $draft2, 'second.txt', $tmp('second'), 0, false);
$otherPath = $files->path($files->fetchFile($user, $other));
$d2 = new CrmEmailDraft($db);
$d2->fetch($draft2);
crmCliCheck('a discarded draft takes its files with it', $d2->discard($user) === 1 && !is_file($otherPath) && $files->fetchFile($user, $other) === null);

// A deleted deal takes everything.
$dir = $files->directory($dealId);
crmCliCheck('deleting the deal removes the attachments of its e-mails', $deal->delete($user) === 1 && !is_dir($dir)
	&& (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix.'crm_mail_file WHERE fk_opportunity = '.$dealId))->nb === 0);

$db->rollback();
crmCliFinish();
