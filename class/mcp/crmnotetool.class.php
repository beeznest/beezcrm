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

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

/**
 * \file    class/mcp/crmnotetool.class.php
 * \ingroup crm
 * \brief   From v1.2.0. MCP tools for the internal notes of a deal and their files. Same visibility as the deal tools.
 */

require_once __DIR__.'/crmmcptool.class.php';
require_once __DIR__.'/../crmnote.class.php';

/**
 * Notes of a deal: add, edit (the earlier text is kept), versions, files (base64 content).
 */
class CrmNoteTool extends CrmMcpTool
{
	private const READS = array('list_crm_opportunity_notes', 'list_crm_opportunity_files', 'list_crm_note_versions');

	/**
	 * @return list<array<string,mixed>>
	 */
	protected function crmDefinitions(): array
	{
		$file = array(
			'type' => 'object',
			'properties' => array(
				'filename' => array('type' => 'string'),
				'content_base64' => array('type' => 'string', 'description' => 'File content, base64. At most the Dolibarr document size limit (2 MB by default).'),
			),
			'required' => array('filename', 'content_base64'),
		);
		return array(
			$this->definition('list_crm_opportunity_notes', 'Internal notes of a deal, newest first: author, date, text, files and number of earlier versions.', array(
				'id' => array('type' => 'integer', 'description' => 'Opportunity id'),
			), array('id')),
			$this->definition('list_crm_opportunity_files', 'Every file attached to the notes of a deal: name, size, date, author.', array(
				'id' => array('type' => 'integer', 'description' => 'Opportunity id'),
			), array('id')),
			$this->definition('list_crm_note_versions', 'Versions of a note, current first, then the earlier ones with who wrote them and when.', array(
				'note_id' => array('type' => 'integer'),
			), array('note_id')),
			$this->definition('add_crm_opportunity_note', 'Add an internal note to a deal, with optional files. The caller is the author.', array(
				'id' => array('type' => 'integer', 'description' => 'Opportunity id'),
				'note' => array('type' => 'string'),
				'files' => array('type' => 'array', 'items' => $file),
			), array('id', 'note')),
			$this->definition('update_crm_note', 'Change the text of a note. The earlier text is kept in its history. Only the author, or a user who reads every owner, may.', array(
				'note_id' => array('type' => 'integer'),
				'note' => array('type' => 'string'),
			), array('note_id', 'note')),
			$this->definition('add_crm_note_file', 'Attach a file to a note.', array(
				'note_id' => array('type' => 'integer'),
				'filename' => array('type' => 'string'),
				'content_base64' => array('type' => 'string'),
			), array('note_id', 'filename', 'content_base64')),
			$this->definition('delete_crm_note_file', 'Remove a file from its note.', array(
				'file_id' => array('type' => 'integer'),
			), array('file_id')),
		);
	}

	/**
	 * @return array<int,array<int,string>>
	 */
	public function getRequiredRights(string $toolName)
	{
		return in_array($toolName, self::READS, true) ? array(array('crm', 'read')) : array(array('crm', 'write'));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	public function writeConfirmationPreview(string $toolName, array $args)
	{
		if (in_array($toolName, self::READS, true)) {
			return self::NO_WRITE;
		}
		if ($toolName === 'add_crm_opportunity_note') {
			return 'Add an internal note to CRM deal '.$this->argInt($args, 'id').' with '.(isset($args['files']) && is_array($args['files']) ? count($args['files']) : 0).' file(s).';
		}
		if ($toolName === 'update_crm_note') {
			return 'Change CRM note '.$this->argInt($args, 'note_id').'. The earlier text stays in its history.';
		}
		if ($toolName === 'add_crm_note_file') {
			return 'Attach file "'.$this->argString($args, 'filename').'" to CRM note '.$this->argInt($args, 'note_id').'.';
		}
		return 'Remove file '.$this->argInt($args, 'file_id').' from its CRM note.';
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	public function execute(string $toolName, array $args)
	{
		$denied = $this->denyMissingRights($toolName);
		if ($denied !== null) {
			return $denied;
		}
		if (!CrmNote::supported($this->db)) {
			return $this->fail('Notes need the module to be enabled again (schema step 4)');
		}
		$notes = new CrmNote($this->db);
		if ($toolName === 'list_crm_opportunity_notes') {
			if ($this->loadDeal($this->argInt($args, 'id')) === null) {
				return $this->fail('Deal not found');
			}
			$rows = array();
			foreach ($notes->fetchForOpportunity($this->user, $this->argInt($args, 'id')) as $note) {
				$files = array();
				foreach ($note->files as $file) {
					$files[] = $this->fileRow($file);
				}
				$rows[] = array(
					'id' => (int) $note->rowid,
					'author_id' => (int) $note->fk_user,
					'author' => trim((string) $note->firstname.' '.(string) $note->lastname),
					'date' => (string) $note->datec,
					'edited' => $note->date_modif !== null ? (string) $note->date_modif : '',
					'edited_by_id' => (int) $note->fk_user_modif,
					'note' => (string) $note->note,
					'earlier_versions' => (int) $note->revisions,
					'files' => $files,
				);
			}
			return $rows;
		}
		if ($toolName === 'list_crm_opportunity_files') {
			if ($this->loadDeal($this->argInt($args, 'id')) === null) {
				return $this->fail('Deal not found');
			}
			$rows = array();
			foreach ($notes->fetchFilesForOpportunity($this->user, $this->argInt($args, 'id')) as $file) {
				$rows[] = $this->fileRow($file);
			}
			return $rows;
		}
		if ($toolName === 'list_crm_note_versions') {
			$rows = array();
			foreach ($notes->fetchVersions($this->user, $this->argInt($args, 'note_id')) as $version) {
				$rows[] = array(
					'current' => (bool) $version->current,
					'author_id' => (int) $version->fk_user,
					'author' => trim((string) $version->firstname.' '.(string) $version->lastname),
					'date' => (string) $version->datec,
					'note' => (string) $version->note,
				);
			}
			return count($rows) > 0 ? $rows : $this->fail('Note not found');
		}
		if ($toolName === 'add_crm_opportunity_note') {
			if ($this->loadDeal($this->argInt($args, 'id')) === null) {
				return $this->fail('Deal not found');
			}
			$noteId = $notes->add($this->user, $this->argInt($args, 'id'), $this->argText($args, 'note'));
			if ($noteId <= 0) {
				return $this->fail($notes->error);
			}
			$added = array();
			$errors = array();
			foreach ((isset($args['files']) && is_array($args['files']) ? $args['files'] : array()) as $file) {
				$fileId = $this->attach($notes, $noteId, is_array($file) ? $file : array());
				if ($fileId > 0) {
					$added[] = $fileId;
				} else {
					$errors[] = crmErrorText($notes->error);
				}
			}
			return array(array('id' => $noteId, 'file_ids' => $added, 'file_errors' => $errors));
		}
		if ($toolName === 'update_crm_note') {
			$result = $notes->update($this->user, $this->argInt($args, 'note_id'), $this->argText($args, 'note'));
			if ($result < 0) {
				return $this->fail($notes->error);
			}
			return array(array('id' => $this->argInt($args, 'note_id'), 'changed' => $result));
		}
		if ($toolName === 'add_crm_note_file') {
			$fileId = $this->attach($notes, $this->argInt($args, 'note_id'), $args);
			if ($fileId <= 0) {
				return $this->fail($notes->error);
			}
			return array(array('file_id' => $fileId));
		}
		if ($toolName === 'delete_crm_note_file') {
			if ($notes->deleteFile($this->user, $this->argInt($args, 'file_id')) <= 0) {
				return $this->fail($notes->error);
			}
			return array(array('file_id' => $this->argInt($args, 'file_id'), 'deleted' => 1));
		}
		return $this->fail('Unknown tool');
	}

	/**
	 * @param array<string,mixed> $file filename and content_base64
	 */
	private function attach(CrmNote $notes, int $noteId, array $file): int
	{
		$content = base64_decode(isset($file['content_base64']) && is_string($file['content_base64']) ? $file['content_base64'] : '', true);
		if ($content === false) {
			$notes->error = 'CrmErrorFileUpload';
			return -1;
		}
		return $notes->addFileContent($this->user, $noteId, $this->argString($file, 'filename'), $content);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function fileRow(object $file): array
	{
		return array(
			'id' => (int) $file->rowid,
			'note_id' => (int) $file->fk_note,
			'filename' => (string) $file->filename,
			'size' => (int) $file->filesize,
			'date' => (string) $file->datec,
			'author_id' => (int) $file->fk_user,
			'mimetype' => (string) $file->mimetype,
		);
	}
}
