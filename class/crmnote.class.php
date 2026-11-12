<?php
declare(strict_types=1);

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

/**
 * \file    class/crmnote.class.php
 * \ingroup crm
 * \brief   From v1.2.0. Internal notes of a deal: who wrote what and when, edits with the earlier versions kept, and the files
 *          attached to a note. The same methods serve the deal card, the download page and the MCP tools.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once __DIR__.'/../lib/crm.lib.php';

/**
 * Notes of a deal, their revisions and their files. Files live under the documents directory of the module
 * (<documents>/crm/opportunity/<deal id>/<file id>_<name>); the tables hold the metadata.
 */
class CrmNote
{
	public const MAX_FILES_PER_NOTE = 10;
	public const MAX_TEXT = 20000;

	/** @var DoliDB */
	private $db;

	/** @var string Language key of the last error */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * True when the tables exist and schema step 4 has run, which copies the descriptions of the deals into notes: the tables
	 * alone are not enough, or the descriptions would look empty between their creation and the end of the step. Files are
	 * live before the module is enabled again, so until then the description is used.
	 */
	public static function supported(DoliDB $db): bool
	{
		static $tables = null;
		if ($tables === null) {
			$resql = $db->DDLDescTable($db->prefix().'crm_note', 'note');
			$tables = ($resql && $db->fetch_object($resql)) ? true : false;
		}
		return $tables && crmSchemaInstalled() >= 4;
	}

	/**
	 * True when each note is also an agenda event: the column fk_action exists and schema step 6 has run. Until then notes work
	 * without events.
	 */
	public static function eventsSupported(DoliDB $db): bool
	{
		static $column = null;
		if ($column === null) {
			$resql = $db->DDLDescTable($db->prefix().'crm_note', 'fk_action');
			$column = ($resql && $db->fetch_object($resql)) ? true : false;
		}
		return $column && crmSchemaInstalled() >= 6;
	}

	/**
	 * Largest file accepted, in bytes: the Dolibarr limit for documents (MAIN_UPLOAD_DOC, in kB), 2 MB when unset.
	 */
	public static function maxFileBytes(): int
	{
		$kb = getDolGlobalInt('MAIN_UPLOAD_DOC');
		return ($kb > 0 ? $kb : 2048) * 1024;
	}

	/**
	 * Clean a note: no markup, no control characters, line breaks kept, bounded.
	 */
	public static function cleanText(string $text): string
	{
		$text = str_replace(array("\r\n", "\r"), "\n", strip_tags($text));
		$text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text));
		return dol_substr($text, 0, self::MAX_TEXT);
	}

	/**
	 * The deal the note belongs to, when the user may read it: row with rowid, fk_user_resp, status; null otherwise.
	 */
	private function readableDeal(User $user, int $dealId): ?object
	{
		global $conf;
		$resql = $this->db->query('SELECT rowid, fk_user_resp, status, entity FROM '.$this->db->prefix().'crm_opportunity WHERE rowid = '.((int) $dealId).' AND entity = '.((int) $conf->entity));
		$deal = $resql ? $this->db->fetch_object($resql) : null;
		if (!$deal || !crmCanReadOwner($user, (int) $deal->fk_user_resp, (int) $deal->rowid)) {
			return null;
		}
		return $deal;
	}

	/**
	 * A note, with the author's name, when it belongs to a deal the user may read.
	 */
	public function fetch(User $user, int $noteId): ?object
	{
		global $conf;
		$sql = 'SELECT n.rowid, n.fk_opportunity, n.fk_user, n.datec, n.fk_user_modif, n.date_modif, n.note, u.firstname, u.lastname, u.login';
		$sql .= ' FROM '.$this->db->prefix().'crm_note as n LEFT JOIN '.$this->db->prefix().'user as u ON u.rowid = n.fk_user';
		$sql .= ' WHERE n.rowid = '.((int) $noteId).' AND n.entity = '.((int) $conf->entity);
		$resql = $this->db->query($sql);
		$note = $resql ? $this->db->fetch_object($resql) : null;
		if (!$note || $this->readableDeal($user, (int) $note->fk_opportunity) === null) {
			return null;
		}
		return $note;
	}

	/**
	 * The author of a note, or a user who reads every owner's deals, may edit it, with the right to write.
	 */
	public function canEdit(User $user, object $note): bool
	{
		// From v1.2.0 the right "note" (the outside agent) writes and edits their own notes; a user who may write deals also edits the
		// notes of others when they read every owner's deals.
		$own = (int) $note->fk_user === (int) $user->id;
		return ($user->hasRight('crm', 'write') || $user->hasRight('crm', 'note')) && ($own || ($user->hasRight('crm', 'write') && $user->hasRight('crm', 'readall')));
	}

	/**
	 * Notes of a deal, newest first, each with its files and the number of earlier versions.
	 *
	 * @return array<int,object> Rows of fetch() plus files (list of rows of fetchFile()) and revisions (int)
	 */
	public function fetchForOpportunity(User $user, int $dealId): array
	{
		global $conf;
		if ($this->readableDeal($user, $dealId) === null) {
			return array();
		}
		$prefix = $this->db->prefix();
		$sql = 'SELECT n.rowid, n.fk_opportunity, n.fk_user, n.datec, n.fk_user_modif, n.date_modif, n.note, u.firstname, u.lastname, u.login,';
		$sql .= ' (SELECT COUNT(*) FROM '.$prefix.'crm_note_revision as r WHERE r.fk_note = n.rowid) as revisions';
		$sql .= ' FROM '.$prefix.'crm_note as n LEFT JOIN '.$prefix.'user as u ON u.rowid = n.fk_user';
		$sql .= ' WHERE n.fk_opportunity = '.((int) $dealId).' AND n.entity = '.((int) $conf->entity).' ORDER BY n.datec DESC, n.rowid DESC';
		$notes = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$obj->files = array();
			$notes[(int) $obj->rowid] = $obj;
		}
		foreach ($this->fetchFilesForOpportunity($user, $dealId) as $file) {
			if (isset($notes[(int) $file->fk_note])) {
				$notes[(int) $file->fk_note]->files[] = $file;
			}
		}
		return array_values($notes);
	}

	/**
	 * Every file attached to a note of the deal, newest first.
	 *
	 * @return array<int,object> Rows with rowid, fk_note, fk_user, datec, filename, filesize, mimetype, firstname, lastname, login
	 */
	public function fetchFilesForOpportunity(User $user, int $dealId): array
	{
		global $conf;
		if ($this->readableDeal($user, $dealId) === null) {
			return array();
		}
		$sql = 'SELECT f.rowid, f.fk_opportunity, f.fk_note, f.fk_user, f.datec, f.filename, f.stored, f.filesize, f.mimetype, u.firstname, u.lastname, u.login';
		$sql .= ' FROM '.$this->db->prefix().'crm_note_file as f LEFT JOIN '.$this->db->prefix().'user as u ON u.rowid = f.fk_user';
		$sql .= ' WHERE f.fk_opportunity = '.((int) $dealId).' AND f.entity = '.((int) $conf->entity).' ORDER BY f.datec DESC, f.rowid DESC';
		$list = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$list[] = $obj;
		}
		return $list;
	}

	/**
	 * One file, when its deal may be read by the user.
	 */
	public function fetchFile(User $user, int $fileId): ?object
	{
		global $conf;
		$resql = $this->db->query('SELECT rowid, fk_opportunity, fk_note, fk_user, datec, filename, stored, filesize, mimetype FROM '.$this->db->prefix().'crm_note_file WHERE rowid = '.((int) $fileId).' AND entity = '.((int) $conf->entity));
		$file = $resql ? $this->db->fetch_object($resql) : null;
		if (!$file || $this->readableDeal($user, (int) $file->fk_opportunity) === null) {
			return null;
		}
		return $file;
	}

	/**
	 * Versions of a note, newest first: the current text, then the earlier ones, each with who wrote it and when.
	 *
	 * @return array<int,object> Rows with note, fk_user, datec, firstname, lastname, login, current (bool)
	 */
	public function fetchVersions(User $user, int $noteId): array
	{
		global $conf;
		$note = $this->fetch($user, $noteId);
		if ($note === null) {
			return array();
		}
		$authorId = ($note->fk_user_modif !== null && (int) $note->fk_user_modif > 0) ? (int) $note->fk_user_modif : (int) $note->fk_user;
		$when = ($note->date_modif !== null) ? $note->date_modif : $note->datec;
		$versions = array();
		$current = new stdClass();
		$current->note = $note->note;
		$current->fk_user = $authorId;
		$current->datec = $when;
		$current->current = true;
		$versions[] = $current;
		$sql = 'SELECT r.note, r.fk_user, r.datec FROM '.$this->db->prefix().'crm_note_revision as r';
		$sql .= ' WHERE r.fk_note = '.((int) $noteId).' AND r.entity = '.((int) $conf->entity).' ORDER BY r.datec DESC, r.rowid DESC';
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$obj->current = false;
			$versions[] = $obj;
		}
		$names = array();
		foreach ($versions as $version) {
			$uid = (int) $version->fk_user;
			if (!isset($names[$uid])) {
				$res = $this->db->query('SELECT firstname, lastname, login FROM '.$this->db->prefix().'user WHERE rowid = '.$uid);
				$names[$uid] = $res ? $this->db->fetch_object($res) : null;
			}
			$version->firstname = $names[$uid] ? $names[$uid]->firstname : '';
			$version->lastname = $names[$uid] ? $names[$uid]->lastname : '';
			$version->login = $names[$uid] ? $names[$uid]->login : '';
		}
		return $versions;
	}

	/**
	 * Add a note to a deal.
	 *
	 * @return int Id of the note, or -1 with the language key in ->error
	 */
	public function add(User $user, int $dealId, string $text, ?int $datec = null): int
	{
		global $conf;
		if ((!$user->hasRight('crm', 'write') && !$user->hasRight('crm', 'note')) || $this->readableDeal($user, $dealId) === null) {
			$this->error = 'CrmErrorNoteRights';
			return -1;
		}
		$text = self::cleanText($text);
		if ($text === '') {
			$this->error = 'CrmErrorNoteEmpty';
			return -1;
		}
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_note (entity, fk_opportunity, fk_user, datec, note) VALUES (';
		$sql .= ((int) $conf->entity).', '.((int) $dealId).', '.((int) $user->id).", '".$this->db->idate($datec ?? dol_now())."', '".$this->db->escape($text)."')";
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		$noteId = (int) $this->db->last_insert_id($this->db->prefix().'crm_note');
		// The note is also an agenda event on the third party and on the deal; the note is kept if the event cannot be made.
		if (self::eventsSupported($this->db)) {
			$this->createEventForNote($noteId);
		}
		return $noteId;
	}

	/**
	 * Make the agenda event "CRM note added" of a note, on its deal and on the third party of the deal: a done event of type
	 * "Other" (no new event type), dated like the note, in the name of the author, holding the text. The event is the one the
	 * deal history and the agenda of the third party show. The link is kept in llx_crm_note.fk_action.
	 *
	 * @return int Event id, or -1 (nothing is changed)
	 */
	public function createEventForNote(int $noteId): int
	{
		global $conf, $langs;
		require_once __DIR__.'/crmactivity.class.php';
		if (!self::eventsSupported($this->db)) {
			return -1;
		}
		$sql = 'SELECT n.rowid, n.fk_opportunity, n.fk_user, n.datec, n.note, o.fk_soc, o.fk_socpeople FROM '.$this->db->prefix().'crm_note as n';
		$sql .= ' INNER JOIN '.$this->db->prefix().'crm_opportunity as o ON o.rowid = n.fk_opportunity';
		$sql .= ' WHERE n.rowid = '.((int) $noteId).' AND n.fk_action IS NULL AND n.entity = '.((int) $conf->entity);
		$resql = $this->db->query($sql);
		$note = $resql ? $this->db->fetch_object($resql) : null;
		if (!$note) {
			return -1;
		}
		$author = new User($this->db);
		if ($author->fetch((int) $note->fk_user) <= 0) {
			return -1;
		}
		crmLoadLangs();
		$activity = new CrmActivity($this->db);
		$eventId = $activity->create($author, (int) $note->fk_opportunity, (int) $note->fk_soc, (int) $note->fk_socpeople, (int) $author->id, 'task', $langs->transnoentitiesnoconv('CrmNoteEventLabel'), (int) $this->db->jdate($note->datec), 100, (string) $note->note);
		if ($eventId <= 0) {
			dol_syslog('CRM note '.$noteId.': agenda event not made: '.$activity->error, LOG_WARNING);
			return -1;
		}
		$this->db->query('UPDATE '.$this->db->prefix().'crm_note SET fk_action = '.((int) $eventId).' WHERE rowid = '.((int) $noteId));
		return $eventId;
	}

	/**
	 * Keep the event of a note in step with its text (or make it for a note that has none).
	 */
	private function refreshEvent(User $user, int $noteId, string $text): void
	{
		if (!self::eventsSupported($this->db)) {
			return;
		}
		$resql = $this->db->query('SELECT fk_action FROM '.$this->db->prefix().'crm_note WHERE rowid = '.((int) $noteId));
		$row = $resql ? $this->db->fetch_object($resql) : null;
		if (!$row || $row->fk_action === null || (int) $row->fk_action <= 0) {
			$this->createEventForNote($noteId);
			return;
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$event = new ActionComm($this->db);
		if ($event->fetch((int) $row->fk_action) > 0) {
			$event->note_private = $text;
			$event->update($user);
		}
	}

	/**
	 * Change the text of a note. The earlier text is kept as a revision, with who wrote it and when. Nothing happens when the
	 * text is unchanged.
	 *
	 * @return int 1 if changed, 0 if the text is the same, -1 on error (language key in ->error)
	 */
	public function update(User $user, int $noteId, string $text): int
	{
		global $conf;
		$note = $this->fetch($user, $noteId);
		if ($note === null) {
			$this->error = 'CrmErrorNoteNotFound';
			return -1;
		}
		if (!$this->canEdit($user, $note)) {
			$this->error = 'CrmErrorNoteRights';
			return -1;
		}
		$text = self::cleanText($text);
		if ($text === '') {
			$this->error = 'CrmErrorNoteEmpty';
			return -1;
		}
		if ($text === (string) $note->note) {
			return 0;
		}
		$prefix = $this->db->prefix();
		$authorId = ($note->fk_user_modif !== null && (int) $note->fk_user_modif > 0) ? (int) $note->fk_user_modif : (int) $note->fk_user;
		$when = ($note->date_modif !== null) ? $note->date_modif : $note->datec;
		$this->db->begin();
		$ok = $this->db->query('INSERT INTO '.$prefix.'crm_note_revision (entity, fk_note, fk_user, datec, note) VALUES ('.((int) $conf->entity).', '.((int) $noteId).', '.$authorId.", '".$this->db->escape((string) $when)."', '".$this->db->escape((string) $note->note)."')");
		$ok = $ok && $this->db->query('UPDATE '.$prefix."crm_note SET note = '".$this->db->escape($text)."', fk_user_modif = ".((int) $user->id).", date_modif = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $noteId));
		if (!$ok) {
			$this->db->rollback();
			$this->error = crmDbError($this->db);
			return -1;
		}
		$this->db->commit();
		$this->refreshEvent($user, $noteId, $text);
		return 1;
	}

	/**
	 * Directory of the files of a deal.
	 */
	public function directory(int $dealId): string
	{
		global $conf;
		$root = isset($conf->crm->multidir_output[$conf->entity]) ? $conf->crm->multidir_output[$conf->entity] : DOL_DATA_ROOT.'/crm';
		return $root.'/opportunity/'.((int) $dealId);
	}

	/**
	 * Full path of a stored file.
	 */
	public function path(object $file): string
	{
		return $this->directory((int) $file->fk_opportunity).'/'.$file->stored;
	}

	/**
	 * Attach a file to a note. The file is moved from $source (an uploaded file, or a temporary file made by the caller).
	 *
	 * @param string $name     Name the file had where it came from
	 * @param string $source   Path of the file to move
	 * @param bool   $uploaded True for a file of $_FILES (move_uploaded_file), false for a file made by the caller (rename)
	 * @param int    $uploadError Upload error code of $_FILES, 0 otherwise
	 * @return int Id of the file, or -1 with the language key in ->error
	 */
	public function addFile(User $user, int $noteId, string $name, string $source, bool $uploaded, int $uploadError = 0): int
	{
		global $conf;
		$note = $this->fetch($user, $noteId);
		if ($note === null) {
			$this->error = 'CrmErrorNoteNotFound';
			return -1;
		}
		if (!$this->canEdit($user, $note)) {
			$this->error = 'CrmErrorNoteRights';
			return -1;
		}
		if ($uploadError !== 0) {
			// The PHP upload codes: the size ones have a CRM message, the others use the core message of the same problem.
			$causes = array(UPLOAD_ERR_PARTIAL => 'ErrorPartialFile', UPLOAD_ERR_NO_TMP_DIR => 'ErrorNoTmpDir', UPLOAD_ERR_CANT_WRITE => 'ErrorFailedToWriteInDir', UPLOAD_ERR_EXTENSION => 'ErrorUploadBlockedByAddon');
			dol_syslog('CRM note file "'.crmSingleLine($name, 100).'": PHP upload error code '.$uploadError, LOG_ERR);
			$this->error = ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) ? 'CrmErrorFileTooBig' : $this->coreError($causes[$uploadError] ?? '');
			return -1;
		}
		$size = (int) @filesize($source);
		if ($size <= 0) {
			$this->error = 'CrmErrorFileEmpty';
			return -1;
		}
		if ($size > self::maxFileBytes()) {
			$this->error = 'CrmErrorFileTooBig';
			return -1;
		}
		$count = $this->db->query('SELECT COUNT(*) as nb FROM '.$this->db->prefix().'crm_note_file WHERE fk_note = '.((int) $noteId));
		if ($count && (int) $this->db->fetch_object($count)->nb >= self::MAX_FILES_PER_NOTE) {
			$this->error = 'CrmErrorFileTooMany';
			return -1;
		}
		$clean = dol_sanitizeFileName(basename(str_replace('\\', '/', $name)));
		$clean = ltrim($clean, '.');
		if ($clean === '') {
			$this->error = 'CrmErrorFileUpload';
			return -1;
		}
		$clean = dol_substr($clean, 0, 150);
		$mime = (string) dol_mimetype($clean, '', 0);
		$prefix = $this->db->prefix();
		$sql = 'INSERT INTO '.$prefix.'crm_note_file (entity, fk_opportunity, fk_note, fk_user, datec, filename, stored, filesize, mimetype) VALUES (';
		$sql .= ((int) $conf->entity).', '.((int) $note->fk_opportunity).', '.((int) $noteId).', '.((int) $user->id).", '".$this->db->idate(dol_now())."', '".$this->db->escape($clean)."', '', ".$size.", '".$this->db->escape(dol_substr($mime, 0, 100))."')";
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		$fileId = (int) $this->db->last_insert_id($prefix.'crm_note_file');
		$dir = $this->directory((int) $note->fk_opportunity);
		$stored = $fileId.'_'.$clean;
		$moved = 0;
		$cause = 'ErrorDirNotWritable';
		if (dol_mkdir($dir) >= 0) {
			$moved = dol_move_uploaded_file($source, $dir.'/'.$stored, 1, 0, 0, 0, 'notefiles', $dir, $uploaded ? 0 : 1);
			$cause = is_string($moved) ? $moved : '';
		}
		if (!is_int($moved) || $moved <= 0) {
			// Say why: the cause is the core's own error key (antivirus, directory not writable...), kept in the log as well.
			dol_syslog('CRM note file "'.crmSingleLine($clean, 100).'" in '.$dir.' not saved: '.(is_string($moved) ? $moved : 'code '.$moved).', writable='.(is_writable($dir) ? 'yes' : 'no'), LOG_ERR);
			$this->db->query('DELETE FROM '.$prefix.'crm_note_file WHERE rowid = '.$fileId);
			$this->error = $this->coreError($cause);
			return -1;
		}
		if ($moved === 2) {
			$stored .= '.noexe';
		}
		$this->db->query('UPDATE '.$prefix."crm_note_file SET stored = '".$this->db->escape($stored)."' WHERE rowid = ".$fileId);
		return $fileId;
	}

	/**
	 * Language key to report: the core error key when it is a plain one (it is translated from the errors file), else the
	 * generic CRM message.
	 */
	private function coreError(string $key): string
	{
		global $langs;
		// Core messages that carry a %s the CRM cannot fill have their own text.
		$own = array('ErrorDirNotWritable' => 'CrmErrorFileDir', 'ErrorFailedToWriteInDir' => 'CrmErrorFileDir', 'ErrorNoTmpDir' => 'CrmErrorFileNoTmp');
		if (isset($own[$key])) {
			return $own[$key];
		}
		if (preg_match('/^Error[A-Za-z]{3,60}$/', $key)) {
			$langs->load('errors');
			return $key;
		}
		return 'CrmErrorFileUpload';
	}

	/**
	 * Attach a file given as bytes (MCP). The content is written to a temporary file first, then moved like an upload.
	 *
	 * @return int Id of the file, or -1 with the language key in ->error
	 */
	public function addFileContent(User $user, int $noteId, string $name, string $content): int
	{
		if ($content === '' || strlen($content) > self::maxFileBytes()) {
			$this->error = $content === '' ? 'CrmErrorFileEmpty' : 'CrmErrorFileTooBig';
			return -1;
		}
		$tmpDir = DOL_DATA_ROOT.'/crm/temp';
		if (dol_mkdir($tmpDir) < 0) {
			$this->error = 'CrmErrorFileUpload';
			return -1;
		}
		$tmp = $tmpDir.'/note_'.bin2hex(random_bytes(8));
		if (file_put_contents($tmp, $content) === false) {
			$this->error = 'CrmErrorFileUpload';
			return -1;
		}
		$result = $this->addFile($user, $noteId, $name, $tmp, false);
		if (file_exists($tmp)) {
			@unlink($tmp);
		}
		return $result;
	}

	/**
	 * Remove a file from a note (same right as editing the note).
	 *
	 * @return int 1 if removed, -1 on error
	 */
	public function deleteFile(User $user, int $fileId): int
	{
		$file = $this->fetchFile($user, $fileId);
		if ($file === null) {
			$this->error = 'CrmErrorNoteNotFound';
			return -1;
		}
		$note = $this->fetch($user, (int) $file->fk_note);
		if ($note === null || !$this->canEdit($user, $note)) {
			$this->error = 'CrmErrorNoteRights';
			return -1;
		}
		if (!$this->db->query('DELETE FROM '.$this->db->prefix().'crm_note_file WHERE rowid = '.((int) $fileId))) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		@unlink($this->path($file));
		return 1;
	}

	/**
	 * Remove the notes, revisions and files of a deal that is deleted.
	 */
	public function deleteForOpportunity(int $dealId): bool
	{
		if (!self::supported($this->db)) {
			return true;
		}
		$prefix = $this->db->prefix();
		$dealId = (int) $dealId;
		$ok = $this->db->query('DELETE FROM '.$prefix.'crm_note_revision WHERE fk_note IN (SELECT rowid FROM '.$prefix.'crm_note WHERE fk_opportunity = '.$dealId.')');
		$ok = $ok && $this->db->query('DELETE FROM '.$prefix.'crm_note_file WHERE fk_opportunity = '.$dealId);
		$ok = $ok && $this->db->query('DELETE FROM '.$prefix.'crm_note WHERE fk_opportunity = '.$dealId);
		if ($ok) {
			dol_delete_dir_recursive($this->directory($dealId));
		}
		return (bool) $ok;
	}

	/**
	 * The latest notes of a deal as plain text, oldest of those first, for the AI reply prompt.
	 *
	 * @return string[]
	 */
	public function latestTexts(int $dealId, int $limit = 3): array
	{
		global $conf;
		$sql = 'SELECT note FROM '.$this->db->prefix().'crm_note WHERE fk_opportunity = '.((int) $dealId).' AND entity = '.((int) $conf->entity);
		$sql .= ' ORDER BY datec DESC, rowid DESC'.$this->db->plimit(max(1, $limit), 0);
		$texts = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$texts[] = (string) $obj->note;
		}
		return array_reverse($texts);
	}
}
