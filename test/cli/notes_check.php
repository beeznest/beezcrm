<?php
declare(strict_types=1);

/**
 * \file    test/cli/notes_check.php
 * \ingroup crm
 * \brief   From v1.2.0. Internal notes: the schema step that turns descriptions into notes, add, edit with versions, rights,
 *          files (stored, listed, removed, refused when too big or empty), cascade on delete. Rolled back; the files written
 *          to the documents directory are removed by the check itself. See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/crmnote.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
crmCliCheck('the note tables exist (enable the module again)', CrmNote::supported($db));
if (!CrmNote::supported($db)) {
	crmCliFinish();
}
crmCliCheck('schema version 4 is installed', crmSchemaInstalled() >= 4);
$installedVersion = $conf->global->CRM_SCHEMA_VERSION;
$conf->global->CRM_SCHEMA_VERSION = '3';
crmCliCheck('with the tables but before step 4 ran, notes are not used (the description is)', !CrmNote::supported($db));
$conf->global->CRM_SCHEMA_VERSION = $installedVersion;
crmCliCheck('after step 4, notes are used', CrmNote::supported($db));
$db->begin();
$prefix = $db->prefix();
$pipelineId = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$mkDeal = function (string $label, string $description = '') use ($db, $user, $pipelineId): Opportunity {
	$d = new Opportunity($db);
	$d->label = $label;
	$d->fk_pipeline = $pipelineId;
	$d->fk_user_resp = (int) $user->id;
	$d->description = $description;
	$d->create($user);
	return $d;
};
$notes = new CrmNote($db);

// Schema step 4.
$legacy = $mkDeal('ZNOTELEGACY', "Old description\nline two");
$count = function (int $dealId) use ($db, $prefix): int {
	return (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix.'crm_note WHERE fk_opportunity = '.$dealId))->nb;
};
crmCliCheck('a new deal has no note', $count((int) $legacy->id) === 0);
crmCliCheck('the schema step runs', crmSchemaStep4($db));
$rows = $notes->fetchForOpportunity($user, (int) $legacy->id);
crmCliCheck('the description became the first note, by the creator', count($rows) === 1 && $rows[0]->note === "Old description\nline two" && (int) $rows[0]->fk_user === (int) $user->id);
crmSchemaStep4($db);
crmCliCheck('running the step again adds nothing', $count((int) $legacy->id) === 1);

// Add and read.
$deal = $mkDeal('ZNOTEDEAL');
crmCliCheck('the deal was created', (int) $deal->id > 0);
crmCliCheck('an empty note is refused', $notes->add($user, (int) $deal->id, "  <b></b> \n") < 0 && $notes->error === 'CrmErrorNoteEmpty');
$first = $notes->add($user, (int) $deal->id, 'First <script>x</script>note', dol_now() - 7200);
$second = $notes->add($user, (int) $deal->id, 'Second note');
crmCliCheck('two notes added', $first > 0 && $second > 0);
$list = $notes->fetchForOpportunity($user, (int) $deal->id);
crmCliCheck('newest first, markup stripped, author and date kept', count($list) === 2 && (int) $list[0]->rowid === $second && $list[1]->note === 'First xnote' && (int) $list[1]->fk_user === (int) $user->id && $list[1]->datec !== null);

// Edit and versions.
crmCliCheck('the same text is not a change', $notes->update($user, $first, 'First xnote') === 0 && (int) $notes->fetchForOpportunity($user, (int) $deal->id)[1]->revisions === 0);
crmCliCheck('an edit is saved', $notes->update($user, $first, 'First note, corrected') === 1);
crmCliCheck('a second edit is saved', $notes->update($user, $first, 'First note, corrected twice') === 1);
$versions = $notes->fetchVersions($user, $first);
crmCliCheck('three versions, current first, with their authors', count($versions) === 3 && $versions[0]->current === true && $versions[0]->note === 'First note, corrected twice' && $versions[1]->note === 'First note, corrected' && $versions[2]->note === 'First xnote' && (int) $versions[2]->fk_user === (int) $user->id);
$edited = $notes->fetch($user, $first);
crmCliCheck('the note records who and when it was last edited', (int) $edited->fk_user_modif === (int) $user->id && $edited->date_modif !== null);

// Rights.
$other = new User($db);
$other->id = (int) $user->id + 100000;
$other->admin = 0;
$other->rights = new stdClass();
$other->rights->crm = new stdClass();
$other->rights->crm->read = 1;
$other->rights->crm->write = 1;
crmCliCheck('a user who may not read the deal sees no note', $notes->fetchForOpportunity($other, (int) $deal->id) === array() && $notes->fetch($other, $first) === null);
$other->rights->crm->readall = 1;
crmCliCheck('with "read all" the notes are visible and can be edited', $notes->fetch($other, $first) !== null && $notes->update($other, $first, 'Edited by a manager') === 1);
unset($other->rights->crm->write);
crmCliCheck('without the right to write nothing is added or edited', $notes->add($other, (int) $deal->id, 'x') < 0 && $notes->update($other, $first, 'y') < 0 && $notes->error === 'CrmErrorNoteRights');

// Files.
$fileId = $notes->addFileContent($user, $first, '../evil name.pdf', "%PDF-1.4 test");
crmCliCheck('a file is attached', $fileId > 0);
$file = $notes->fetchFile($user, $fileId);
crmCliCheck('its name is cleaned and its size kept', $file && strpos((string) $file->filename, '/') === false && strpos((string) $file->filename, '..') === false && (int) $file->filesize === 13);
crmCliCheck('it is on disk, in the directory of the deal', $file && is_file($notes->path($file)) && strpos($notes->path($file), '/opportunity/'.((int) $deal->id).'/') !== false);
$php = $notes->addFileContent($user, $first, 'shell.php', '<?php echo 1;');
$phpFile = $notes->fetchFile($user, $php);
crmCliCheck('a file that could run is stored with .noexe', $php > 0 && $phpFile && substr((string) $phpFile->stored, -6) === '.noexe');
crmCliCheck('an empty file is refused', $notes->addFileContent($user, $first, 'a.txt', '') < 0 && $notes->error === 'CrmErrorFileEmpty');
crmCliCheck('a file over the limit is refused', $notes->addFileContent($user, $first, 'big.bin', str_repeat('a', CrmNote::maxFileBytes() + 1)) < 0 && $notes->error === 'CrmErrorFileTooBig');
$all = $notes->fetchFilesForOpportunity($user, (int) $deal->id);
crmCliCheck('the deal lists its files', count($all) === 2 && (int) $all[0]->fk_note === $first);
crmCliCheck('a note carries its files', count($notes->fetchForOpportunity($user, (int) $deal->id)[1]->files) === 2);
$limited = new User($db);
$limited->id = (int) $user->id + 100001;
$limited->admin = 0;
$limited->rights = new stdClass();
$limited->rights->crm = new stdClass();
$limited->rights->crm->read = 1;
crmCliCheck('a user who may not read the deal cannot open its files', $notes->fetchFile($limited, $fileId) === null);
$path = $notes->path($file);
crmCliCheck('removing a file removes it from disk too', $notes->deleteFile($user, $fileId) === 1 && !is_file($path) && $notes->fetchFile($user, $fileId) === null);

// Cascade.
$dir = $notes->directory((int) $deal->id);
crmCliCheck('deleting the deal removes notes, versions and files', $deal->delete($user) === 1
	&& (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix.'crm_note WHERE fk_opportunity = '.((int) $deal->id)))->nb === 0
	&& (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix.'crm_note_revision WHERE fk_note = '.$first))->nb === 0
	&& (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix.'crm_note_file WHERE fk_opportunity = '.((int) $deal->id)))->nb === 0
	&& !is_dir($dir));

$db->rollback();
crmCliFinish();
