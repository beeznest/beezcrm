<?php
declare(strict_types=1);

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

/**
 * \file    class/crmmailfile.class.php
 * \ingroup crm
 * \brief   From v1.2.0. Files attached to an e-mail written in the e-mail editor. They are attached to the planned draft, sent with
 *          the message by Dolibarr, and kept on disk (<documents>/crm/opportunity/<deal id>/mail/<file id>_<name>) so that the
 *          message history of the deal can show and download them. When the draft is replaced by the sent message, the files
 *          follow it (repoint()). Attachments of messages found in the archive mailbox are not stored.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once __DIR__.'/../lib/crm.lib.php';
require_once __DIR__.'/crmnote.class.php';
require_once __DIR__.'/crmactivity.class.php';

/**
 * Attachments of the e-mails of a deal.
 */
class CrmMailFile
{
	public const MAX_FILES = 10;
	/** Largest total of one e-mail, in bytes: mail servers refuse bigger messages. */
	public const MAX_TOTAL = 20971520;

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
	 * The deal, when the user may read it: row with rowid, fk_user_resp; null otherwise.
	 */
	private function readableDeal(User $user, int $dealId): ?object
	{
		global $conf;
		$resql = $this->db->query('SELECT rowid, fk_user_resp FROM '.$this->db->prefix().'crm_opportunity WHERE rowid = '.((int) $dealId).' AND entity = '.((int) $conf->entity));
		$deal = $resql ? $this->db->fetch_object($resql) : null;
		return ($deal && crmCanReadOwner($user, (int) $deal->fk_user_resp)) ? $deal : null;
	}

	/**
	 * Directory of the attachments of a deal.
	 */
	public function directory(int $dealId): string
	{
		return (new CrmNote($this->db))->directory($dealId).'/mail';
	}

	/**
	 * Full path of a stored file.
	 */
	public function path(object $file): string
	{
		return $this->directory((int) $file->fk_opportunity).'/'.$file->stored;
	}

	/**
	 * The files attached to one event (the draft, or the sent message), oldest first.
	 *
	 * @return array<int,object> Rows with rowid, fk_opportunity, fk_action, fk_user, datec, filename, stored, filesize, mimetype
	 */
	public function fetchForAction(int $eventId): array
	{
		global $conf;
		if (!crmMailFileSupported($this->db) || $eventId <= 0) {
			return array();
		}
		$sql = 'SELECT rowid, fk_opportunity, fk_action, fk_user, datec, filename, stored, filesize, mimetype FROM '.$this->db->prefix().'crm_mail_file';
		$sql .= ' WHERE fk_action = '.((int) $eventId).' AND entity = '.((int) $conf->entity).' ORDER BY rowid';
		$list = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$list[] = $obj;
		}
		return $list;
	}

	/**
	 * The attachments of every message of a deal, by event: event id => files. Empty when the user may not read the deal.
	 *
	 * @return array<int,array<int,object>>
	 */
	public function fetchForOpportunity(User $user, int $dealId): array
	{
		global $conf;
		if (!crmMailFileSupported($this->db) || $this->readableDeal($user, $dealId) === null) {
			return array();
		}
		$sql = 'SELECT rowid, fk_opportunity, fk_action, fk_user, datec, filename, stored, filesize, mimetype FROM '.$this->db->prefix().'crm_mail_file';
		$sql .= ' WHERE fk_opportunity = '.((int) $dealId).' AND entity = '.((int) $conf->entity).' ORDER BY rowid';
		$map = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$map[(int) $obj->fk_action][] = $obj;
		}
		return $map;
	}

	/**
	 * One file, when its deal may be read by the user.
	 */
	public function fetchFile(User $user, int $fileId): ?object
	{
		global $conf;
		if (!crmMailFileSupported($this->db)) {
			return null;
		}
		$resql = $this->db->query('SELECT rowid, fk_opportunity, fk_action, fk_user, datec, filename, stored, filesize, mimetype FROM '.$this->db->prefix().'crm_mail_file WHERE rowid = '.((int) $fileId).' AND entity = '.((int) $conf->entity));
		$file = $resql ? $this->db->fetch_object($resql) : null;
		return ($file && $this->readableDeal($user, (int) $file->fk_opportunity) !== null) ? $file : null;
	}

	/**
	 * Attach a file to a planned e-mail (a draft). The user needs the right to act on next steps, and a deal they may read.
	 *
	 * @param string $name        Name the file had where it came from
	 * @param string $source      Path of the file to move
	 * @param int    $uploadError Upload error code of $_FILES, 0 otherwise
	 * @param bool   $uploaded    True for a file of $_FILES (move_uploaded_file), false for a file made by the caller (rename)
	 * @return int Id of the file, or -1 with a language key in ->error
	 */
	public function add(User $user, int $dealId, int $draftEventId, string $name, string $source, int $uploadError = 0, bool $uploaded = true): int
	{
		global $conf;
		if (!crmMailFileSupported($this->db)) {
			$this->error = 'CrmErrorMailFileSupport';
			return -1;
		}
		if (!$user->hasRight('crm', 'activity') || $this->readableDeal($user, $dealId) === null) {
			$this->error = 'CrmErrorNoteRights';
			return -1;
		}
		// The event must be a planned e-mail of this deal: a sent message does not get new files.
		$resql = $this->db->query('SELECT id FROM '.$this->db->prefix()."actioncomm WHERE id = ".((int) $draftEventId)." AND elementtype = '".$this->db->escape(CrmActivity::ELEMENT_TYPE)."' AND fk_element = ".((int) $dealId)." AND code = 'AC_EMAIL' AND percent < 100");
		if (!$resql || $this->db->num_rows($resql) === 0) {
			$this->error = 'CrmErrorNoteNotFound';
			return -1;
		}
		if ($uploadError !== 0) {
			$causes = array(UPLOAD_ERR_PARTIAL => 'ErrorPartialFile', UPLOAD_ERR_NO_TMP_DIR => 'CrmErrorFileNoTmp', UPLOAD_ERR_CANT_WRITE => 'CrmErrorFileDir', UPLOAD_ERR_EXTENSION => 'ErrorUploadBlockedByAddon');
			dol_syslog('CRM mail file "'.crmSingleLine($name, 100).'": PHP upload error code '.$uploadError, LOG_ERR);
			$this->error = ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) ? 'CrmErrorFileTooBig' : ($causes[$uploadError] ?? 'CrmErrorFileUpload');
			if (strncmp($this->error, 'Error', 5) === 0) {
				global $langs;
				$langs->load('errors');
			}
			return -1;
		}
		$size = (int) @filesize($source);
		if ($size <= 0) {
			$this->error = 'CrmErrorFileEmpty';
			return -1;
		}
		if ($size > CrmNote::maxFileBytes()) {
			$this->error = 'CrmErrorFileTooBig';
			return -1;
		}
		$existing = $this->fetchForAction($draftEventId);
		$total = $size;
		foreach ($existing as $file) {
			$total += (int) $file->filesize;
		}
		if (count($existing) >= self::MAX_FILES) {
			$this->error = 'CrmErrorMailFilesTooMany';
			return -1;
		}
		if ($total > self::MAX_TOTAL) {
			$this->error = 'CrmErrorMailFilesTotal';
			return -1;
		}
		$clean = ltrim(dol_sanitizeFileName(basename(str_replace('\\', '/', $name))), '.');
		if ($clean === '') {
			$this->error = 'CrmErrorFileUpload';
			return -1;
		}
		$clean = dol_substr($clean, 0, 150);
		$mime = (string) dol_mimetype($clean, '', 0);
		$prefix = $this->db->prefix();
		$sql = 'INSERT INTO '.$prefix.'crm_mail_file (entity, fk_opportunity, fk_action, fk_user, datec, filename, stored, filesize, mimetype) VALUES (';
		$sql .= ((int) $conf->entity).', '.((int) $dealId).', '.((int) $draftEventId).', '.((int) $user->id).", '".$this->db->idate(dol_now())."', '".$this->db->escape($clean)."', '', ".$size.", '".$this->db->escape(dol_substr($mime, 0, 100))."')";
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		$fileId = (int) $this->db->last_insert_id($prefix.'crm_mail_file');
		$dir = $this->directory($dealId);
		$stored = $fileId.'_'.$clean;
		$moved = 0;
		if (dol_mkdir($dir) >= 0) {
			$moved = dol_move_uploaded_file($source, $dir.'/'.$stored, 1, 0, 0, 0, 'mailfiles', $dir, $uploaded ? 0 : 1);
		}
		if (!is_int($moved) || $moved <= 0) {
			dol_syslog('CRM mail file "'.crmSingleLine($clean, 100).'" in '.$dir.' not saved: '.(is_string($moved) ? $moved : 'code '.$moved).', writable='.(is_writable($dir) ? 'yes' : 'no'), LOG_ERR);
			$this->db->query('DELETE FROM '.$prefix.'crm_mail_file WHERE rowid = '.$fileId);
			$this->error = (is_string($moved) && preg_match('/^(ErrorDirNotWritable|ErrorFailedToWriteInDir)$/', $moved)) ? 'CrmErrorFileDir' : 'CrmErrorFileUpload';
			return -1;
		}
		if ($moved === 2) {
			$stored .= '.noexe';
		}
		$this->db->query('UPDATE '.$prefix."crm_mail_file SET stored = '".$this->db->escape($stored)."' WHERE rowid = ".$fileId);
		return $fileId;
	}

	/**
	 * Remove a file from a planned e-mail (disk and row).
	 *
	 * @return int 1 if removed, -1 with a language key in ->error
	 */
	public function remove(User $user, int $fileId, int $draftEventId): int
	{
		$file = $this->fetchFile($user, $fileId);
		if ($file === null || (int) $file->fk_action !== $draftEventId || !$user->hasRight('crm', 'activity')) {
			$this->error = 'CrmErrorNoteNotFound';
			return -1;
		}
		// Only a draft still being written loses a file: what was sent keeps the record of what was sent.
		$resql = $this->db->query('SELECT id FROM '.$this->db->prefix().'actioncomm WHERE id = '.((int) $draftEventId).' AND percent < 100');
		if (!$resql || $this->db->num_rows($resql) === 0) {
			$this->error = 'CrmErrorNoteRights';
			return -1;
		}
		if (!$this->db->query('DELETE FROM '.$this->db->prefix().'crm_mail_file WHERE rowid = '.((int) $fileId))) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		@unlink($this->path($file));
		return 1;
	}

	/**
	 * What CMailFile needs to attach the files of a draft: path, mime type and name for each.
	 *
	 * @return array<int,array{path:string,mime:string,name:string}>
	 */
	public function forSend(int $draftEventId): array
	{
		$list = array();
		foreach ($this->fetchForAction($draftEventId) as $file) {
			$path = $this->path($file);
			if (is_file($path)) {
				$list[] = array('path' => $path, 'mime' => ((string) $file->mimetype !== '' ? (string) $file->mimetype : 'application/octet-stream'), 'name' => (string) $file->filename);
			}
		}
		return $list;
	}

	/**
	 * The files follow the message: the draft is replaced by the done e-mail, which has another event id.
	 */
	public function repoint(int $fromEventId, int $toEventId): void
	{
		if (!crmMailFileSupported($this->db) || $fromEventId <= 0 || $toEventId <= 0) {
			return;
		}
		$this->db->query('UPDATE '.$this->db->prefix().'crm_mail_file SET fk_action = '.((int) $toEventId).' WHERE fk_action = '.((int) $fromEventId));
	}

	/**
	 * A discarded draft takes its files with it.
	 */
	public function deleteForAction(int $eventId): void
	{
		foreach ($this->fetchForAction($eventId) as $file) {
			@unlink($this->path($file));
			$this->db->query('DELETE FROM '.$this->db->prefix().'crm_mail_file WHERE rowid = '.((int) $file->rowid));
		}
	}

	/**
	 * A deleted deal takes the attachments of its e-mails with it (rows and directory).
	 */
	public function deleteForOpportunity(int $dealId): bool
	{
		if (!crmMailFileSupported($this->db)) {
			return true;
		}
		$ok = $this->db->query('DELETE FROM '.$this->db->prefix().'crm_mail_file WHERE fk_opportunity = '.((int) $dealId));
		if ($ok) {
			dol_delete_dir_recursive($this->directory($dealId));
		}
		return (bool) $ok;
	}
}
