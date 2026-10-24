<?php
declare(strict_types=1);

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

/**
 * CRM helpers that need Dolibarr. Pure rules live in crmlogic.lib.php.
 */

require_once __DIR__.'/crmlogic.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * Load the module language.
 */
function crmLoadLangs(): void
{
	global $langs;
	$langs->load('crm@crm');
}

/**
 * Label of a built-in entry (pipeline, stage, source, loss reason, qualification item) in the current language.
 * The match is on the English label the seed inserted, so an entry an administrator renamed or created stays as stored.
 */
function crmDictLabel(string $label): string
{
	global $langs;
	$map = array(
		'Commercial pipeline' => 'CrmPipelineCommercial',
		'New' => 'CrmStageNew',
		'Replied to outreach' => 'CrmStageReplied',
		'Qualified' => 'CrmStageQualified',
		'Proposal sent' => 'CrmStageProposalSent',
		'Negotiation' => 'CrmStageNegotiation',
		'Won' => 'CrmStageWon',
		'Lost' => 'CrmStageLost',
		'Website' => 'CrmSourceWeb',
		'Referral' => 'CrmSourceReferral',
		'Inbound call' => 'CrmSourceCall',
		'Event' => 'CrmSourceEvent',
		'Partner' => 'CrmSourcePartner',
		'Prospecting' => 'CrmSourceProspecting',
		'Inbound e-mail' => 'CrmSourceInboundEmail',
		'Price' => 'CrmReasonPrice',
		'No budget' => 'CrmReasonNoBudget',
		'Competitor' => 'CrmReasonCompetitor',
		'Timing' => 'CrmReasonTiming',
		'No decision' => 'CrmReasonNoDecision',
		'No answer' => 'CrmReasonNoAnswer',
		'Not interested' => 'CrmReasonNotInterested',
		'Need confirmed' => 'CrmQualNeed',
		'Budget confirmed' => 'CrmQualBudget',
		'Decision maker identified' => 'CrmQualDecision',
		'Timeline confirmed' => 'CrmQualTimeline',
	);
	if (!isset($map[$label])) {
		return $label;
	}
	crmLoadLangs();
	$translated = $langs->transnoentities($map[$label]);
	if ($translated === '' || $translated === $map[$label]) {
		return $label;
	}
	return $translated;
}

/**
 * Label of a built-in stage in the current language. A renamed or custom stage stays as stored.
 */
function crmStageLabel(string $label): string
{
	return crmDictLabel($label);
}

/**
 * An explanation, shown or hidden. A text of more than 12 words is an explanation an experienced user does
 * not need on every visit: it is printed with the class crm-help, which the stylesheet hides until the "?" button of the
 * page is clicked. A shorter text stays visible. A page that printed at least one hidden explanation calls
 * crmHelpToggle() before its footer, which adds that button.
 *
 * @param string $html   The text, as HTML
 * @param string $tag    p, span, div or ul
 * @param bool   $break  For a span put after a field: also hide (or print) the line break before it
 */
function crmHelp(string $html, string $tag = 'p', bool $break = false): string
{
	global $crmHelpCount;
	$long = crmWordCount($html) > 12;
	if ($long) {
		$crmHelpCount = (int) $crmHelpCount + 1;
	}
	$class = 'opacitymedium'.($long ? ' crm-help' : '');
	return ($break ? '<br'.($long ? ' class="crm-help"' : '').'>' : '').'<'.$tag.' class="'.$class.'">'.$html.'</'.$tag.'>';
}

/**
 * Add the "?" button that shows and hides the explanations of the page, in the top right corner of its title bar. Call it
 * before llxFooter(). Nothing is printed when the page has no hidden explanation.
 */
function crmHelpToggle(): void
{
	global $crmHelpCount, $langs;
	if ((int) $crmHelpCount <= 0) {
		return;
	}
	$label = $langs->transnoentities('CrmHelpToggle');
	$config = array('label' => $label, 'icon' => img_picto($label, 'fa-question-circle', 'class="valignmiddle"'));
	print '<script nonce="'.dol_escape_htmltag(getNonce()).'">(function () {';
	print 'var cfg = '.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP).';';
	print 'var button = document.createElement("button");';
	print 'button.type = "button"; button.className = "crm-help-toggle"; button.title = cfg.label; button.setAttribute("aria-label", cfg.label); button.setAttribute("aria-pressed", "false"); button.innerHTML = cfg.icon;';
	print 'button.addEventListener("click", function () { var on = document.body.classList.toggle("crm-show-help"); button.setAttribute("aria-pressed", on ? "true" : "false"); });';
	print 'var bar = document.querySelector(".table-fiche-title");';
	print 'if (bar && bar.rows && bar.rows.length > 0) {';
	print 'var cell = bar.querySelector("td.col-right");';
	print 'if (!cell) { cell = bar.rows[0].insertCell(-1); cell.className = "nobordernopadding valignmiddle right col-right"; }';
	print 'cell.appendChild(button);';
	print '} else { button.className += " crm-help-toggle-fixed"; document.body.appendChild(button); }';
	print '}());</script>';
}

/**
 * An action shown as an icon instead of a text link. The label is the tooltip and the accessible name of the link.
 *
 * @param string $url    Address, with its token when the action changes data
 * @param string $picto  Dolibarr picto name: edit, delete, check, or fa-xxx
 * @param string $label  What the action does, already translated
 */
function crmIconLink(string $url, string $picto, string $label): string
{
	return '<a class="crm-icon-action" href="'.dol_escape_htmltag($url).'" title="'.dol_escape_htmltag($label).'" aria-label="'.dol_escape_htmltag($label).'">'.img_picto($label, $picto).'</a>';
}

/**
 * Pages call this after the bootstrap. It refuses a disabled module and a user with no read right.
 */
function crmStartPage(): void
{
	crmLoadLangs();
	if (!isModEnabled('crm')) {
		accessforbidden();
	}
	crmRequireRead();
}

/**
 * True when the user may see this owner's deals.
 */
function crmCanReadOwner(User $user, int $ownerId): bool
{
	if ($user->hasRight('crm', 'readall')) {
		return true;
	}
	if ($user->hasRight('crm', 'read') && $ownerId === (int) $user->id) {
		return true;
	}
	return false;
}

/**
 * SQL fragment limiting deals to the caller. Empty when the caller may see every owner.
 */
function crmOwnerSql(User $user, string $alias = 'o'): string
{
	if ($user->hasRight('crm', 'readall')) {
		return '';
	}
	return ' AND '.$alias.'.fk_user_resp = '.((int) $user->id);
}

/**
 * Caller may open the CRM screens.
 */
function crmRequireRead(): void
{
	global $user;
	if (!$user->hasRight('crm', 'read') && !$user->hasRight('crm', 'readall')) {
		accessforbidden();
	}
}

/**
 * Unix timestamp of today at 00:00 in the Dolibarr timezone.
 */
function crmTodayStart(): int
{
	$parts = dol_getdate(dol_now());
	return (int) dol_mktime(0, 0, 0, (int) $parts['mon'], (int) $parts['mday'], (int) $parts['year']);
}

/**
 * Where the sender address comes from: crm (the setting "Sender address"), main (the sender of Dolibarr's e-mail
 * setup), company (the e-mail of the company), or an empty string when none is set.
 */
function crmSenderSource(): string
{
	global $mysoc;
	if (getDolGlobalString('CRM_MAIL_FROM') !== '') {
		return 'crm';
	}
	if (getDolGlobalString('MAIN_MAIL_EMAIL_FROM') !== '') {
		return 'main';
	}
	if (!empty($mysoc->email)) {
		return 'company';
	}
	return '';
}

/**
 * Sender of every e-mail the module sends: the e-mail editor, sequences and owner reminders.
 */
function crmSender(): string
{
	global $mysoc;
	switch (crmSenderSource()) {
		case 'crm':
			return crmSingleLine(getDolGlobalString('CRM_MAIL_FROM'));
		case 'main':
			return crmSingleLine(getDolGlobalString('MAIN_MAIL_EMAIL_FROM'));
		case 'company':
			return crmSingleLine((string) $mysoc->email);
	}
	return '';
}

/**
 * The mailboxes of the e-mail collector module that collect leads: active collectors that have the operation "create a
 * lead" (type project) or the reference of the example Collect_Leads, whose login is an e-mail address. The login of a
 * collector is the mailbox it reads, so it is an address that e-mail can be sent from.
 *
 * @return array<int,array{id:int,ref:string,address:string}>
 */
function crmLeadCollectorMailboxes(DoliDB $db): array
{
	if (!isModEnabled('emailcollector')) {
		return array();
	}
	$prefix = $db->prefix();
	$sql = 'SELECT c.rowid, c.ref, c.login FROM '.$prefix.'emailcollector_emailcollector as c';
	$sql .= ' WHERE c.entity IN ('.getEntity('emailcollector').') AND c.status = 1';
	$sql .= " AND (c.ref = 'Collect_Leads' OR EXISTS (SELECT 1 FROM ".$prefix."emailcollector_emailcollectoraction as a";
	$sql .= " WHERE a.fk_emailcollector = c.rowid AND a.type = 'project' AND a.status = 1))";
	$sql .= ' ORDER BY c.rowid ASC';
	$list = array();
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$address = trim((string) $obj->login);
			if ($address !== '' && isValidEmail($address)) {
				$list[] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'address' => $address);
			}
		}
	}
	return $list;
}

/**
 * The addresses a salesperson may send a message from, by choice code: "default" (the sender of the module, see
 * crmSender()), "user" (the e-mail of the user) and "lead<id>" (the mailbox of a lead collector). An address that appears
 * twice is listed once, under the first choice; an empty or invalid one is left out. Nothing else can be chosen: a
 * posted code is looked up here, never taken as an address.
 *
 * @return array<string,array{address:string,kind:string,ref:string}>
 */
function crmSenderChoices(DoliDB $db, User $user): array
{
	$candidates = array('default' => array(crmSender(), 'default', ''));
	$candidates['user'] = array(trim((string) $user->email), 'user', '');
	foreach (crmLeadCollectorMailboxes($db) as $mailbox) {
		$candidates['lead'.$mailbox['id']] = array($mailbox['address'], 'lead', $mailbox['ref']);
	}
	$choices = array();
	$seen = array();
	foreach ($candidates as $code => $candidate) {
		$address = $candidate[0];
		if ($address === '' || !isValidEmail($address) || isset($seen[strtolower($address)])) {
			continue;
		}
		$seen[strtolower($address)] = true;
		$choices[$code] = array('address' => $address, 'kind' => $candidate[1], 'ref' => $candidate[2]);
	}
	return $choices;
}

/**
 * The choice to preselect: the one the user took last (kept in their own settings) when it is still offered, else the
 * default one, else the first available.
 *
 * @param array<string,array{address:string,kind:string,ref:string}> $choices
 */
function crmSenderChoiceFor(array $choices, string $requested, string $remembered): string
{
	foreach (array($requested, $remembered, 'default') as $code) {
		if ($code !== '' && isset($choices[$code])) {
			return $code;
		}
	}
	return (string) (array_key_first($choices) ?? '');
}

/**
 * True when a sequence e-mail step is turned into a draft for the salesperson instead of being sent by the cron.
 * Anything other than "send" means draft, so a missing or mistyped value never sends mail by itself.
 */
function crmSequenceDraftMode(): bool
{
	return getDolGlobalString('CRM_SEQUENCE_EMAIL_MODE') !== 'send';
}

/**
 * Whether one e-mail step is a draft for the salesperson (true) or is sent by the job (false). A step can force its own
 * mode with 'draft' or 'send'; any other value, the empty string included, follows the global setting.
 */
function crmStepDraftMode(string $stepMode): bool
{
	if ($stepMode === 'send') {
		return false;
	}
	if ($stepMode === 'draft') {
		return true;
	}
	return crmSequenceDraftMode();
}

/**
 * A status of the sequence journal, in the language of the user: sent, simulated, draft or error. Anything else is shown as stored.
 */
function crmLogStatusLabel(string $status): string
{
	global $langs;
	crmLoadLangs();
	$keys = array('sent' => 'CrmLogStatusSent', 'simulated' => 'CrmLogStatusSimulated', 'draft' => 'CrmLogStatusDraft', 'error' => 'CrmLogStatusError');
	return isset($keys[$status]) ? $langs->trans($keys[$status]) : $status;
}

/**
 * Status of an enrolment in the language of the user, with the reason when it stopped: "Stopped (the prospect replied)".
 * Anything unknown is shown as stored.
 */
function crmEnrolStatusLabel(string $status, string $reason): string
{
	global $langs;
	crmLoadLangs();
	$statuses = array('running' => 'CrmEnrolRunning', 'done' => 'CrmEnrolDone', 'stopped' => 'CrmEnrolStopped');
	$reasons = array('replied' => 'CrmEnrolReasonReplied', 'won' => 'CrmEnrolReasonWon', 'lost' => 'CrmEnrolReasonLost', 'manual' => 'CrmEnrolReasonManual', 'optout' => 'CrmEnrolReasonOptOut');
	$label = isset($statuses[$status]) ? $langs->trans($statuses[$status]) : $status;
	if ($status === 'stopped' && isset($reasons[$reason])) {
		$label .= ' ('.$langs->trans($reasons[$reason]).')';
	}
	return $label;
}

/**
 * What caused a journal line: the step of the sequence and the day of the sequence, counted from the enrolment. The step
 * number is the one recorded with the line; for an older line it is the current position of the step, or "?" when the step is gone.
 *
 * @param object $line Journal line from Sequence::fetchLog() or fetchLogForOpportunity()
 */
function crmLogWhy(object $line): string
{
	global $db, $langs;
	crmLoadLangs();
	$position = (int) ($line->step_position ?? 0);
	if ($position <= 0) {
		$position = (int) ($line->current_step_position ?? 0);
	}
	$day = crmSequenceDay((int) $db->jdate($line->date_enrol), (int) $db->jdate($line->date_send));
	return $langs->trans('CrmLogWhy', $position > 0 ? (string) $position : '?', (string) $day);
}

/**
 * Link to the page that shows the e-mail of a journal line, as an icon.
 */
function crmLogViewLink(int $logId): string
{
	global $langs;
	crmLoadLangs();
	return crmIconLink(dol_buildpath('/crm/sequence_log_view.php', 1).'?id='.$logId, 'fa-eye', $langs->trans('CrmViewEmail'));
}

/**
 * Version of the database schema this code expects. It is a whole number that grows by one with each schema change, and it
 * is not the version of the module: several releases can share a schema version. Each number has a step in
 * crmSchemaSteps(); the installed number is kept in the constant CRM_SCHEMA_VERSION.
 */
function crmSchemaCurrent(): int
{
	return 5;
}

/**
 * The schema steps, by the schema version they lead to. A step must be safe to run on a database that already holds its
 * result: a new installation creates the latest tables from sql/llx_crm.sql and then runs every step, and an installation
 * whose constant was lost runs them again. So a step looks before it changes (does the column exist, is the value
 * already converted) and never assumes a clean starting point.
 *
 * @return array<int,array{label:string,run:callable}>
 */
function crmSchemaSteps(): array
{
	return array(
		1 => array('label' => 'Column send_mode of the sequence steps', 'run' => 'crmSchemaStep1'),
		2 => array('label' => 'Content of the e-mails of the sequence journal', 'run' => 'crmSchemaStep2'),
		3 => array('label' => 'Stage "Replied to outreach" and the column is_reply of the stages', 'run' => 'crmSchemaStep3'),
		4 => array('label' => 'Internal notes: the description of a deal becomes its first note', 'run' => 'crmSchemaStep4'),
		5 => array('label' => 'Public "not interested" link: loss reason "Not interested"', 'run' => 'crmSchemaStep5'),
	);
}

/**
 * Schema version 1: the column send_mode of llx_crm_sequence_step, the e-mail mode of a step.
 */
function crmSchemaStep1(DoliDB $db): bool
{
	$table = $db->prefix().'crm_sequence_step';
	$resql = $db->DDLDescTable($table, 'send_mode');
	if ($resql && $db->fetch_object($resql)) {
		return true;
	}
	// DDLAddField cannot write DEFAULT '' (it skips an empty default), so the statement is written here.
	$sql = 'ALTER TABLE '.$table." ADD COLUMN send_mode varchar(8) DEFAULT '' NOT NULL".($db->type === 'pgsql' ? '' : ' AFTER body');
	if (!$db->query($sql)) {
		dol_syslog('CRM: could not add the column send_mode to '.$table.': '.$db->lasterror(), LOG_ERR);
		return false;
	}
	return true;
}

/**
 * Schema version 2: the journal of the sequences keeps the e-mail itself (sender, text with the placeholders replaced, its
 * format, where the text comes from) and the position of the step that made it, so it can be read again later.
 */
function crmSchemaStep2(DoliDB $db): bool
{
	$table = $db->prefix().'crm_sequence_log';
	$columns = array(
		'from_email' => 'varchar(255) DEFAULT NULL',
		'body' => 'text',
		'body_format' => "varchar(8) DEFAULT '' NOT NULL",
		'step_position' => 'integer DEFAULT NULL',
		'content_source' => "varchar(16) DEFAULT '' NOT NULL",
	);
	foreach ($columns as $name => $definition) {
		$resql = $db->DDLDescTable($table, $name);
		if ($resql && $db->fetch_object($resql)) {
			continue;
		}
		if (!$db->query('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$definition)) {
			dol_syslog('CRM: could not add the column '.$name.' to '.$table.': '.$db->lasterror(), LOG_ERR);
			return false;
		}
	}
	return true;
}

/**
 * Schema version 3: the column is_reply of llx_crm_stage marks the stage a deal moves to when the prospect replies, and
 * every pipeline that has no such stage gets one, "Replied to outreach", right after its first open stage. A pipeline of an
 * installation that is created later gets it from the seed. Safe to run twice: a pipeline that has a reply stage is left alone.
 */
function crmSchemaStep3(DoliDB $db): bool
{
	global $conf;
	$table = $db->prefix().'crm_stage';
	$resql = $db->DDLDescTable($table, 'is_reply');
	if (!($resql && $db->fetch_object($resql))) {
		if (!$db->query('ALTER TABLE '.$table." ADD COLUMN is_reply smallint DEFAULT 0 NOT NULL")) {
			dol_syslog('CRM: could not add the column is_reply to '.$table.': '.$db->lasterror(), LOG_ERR);
			return false;
		}
	}
	$pipelines = $db->query('SELECT rowid, entity FROM '.$db->prefix().'crm_pipeline');
	if (!$pipelines) {
		return false;
	}
	while ($pipeline = $db->fetch_object($pipelines)) {
		crmEnsureReplyStage($db, (int) $pipeline->rowid, (int) $pipeline->entity);
	}
	return true;
}

/**
 * Schema version 4 (from v1.2.0): internal notes. The tables llx_crm_note, llx_crm_note_revision and llx_crm_note_file come from
 * sql/llx_crm.sql, which Dolibarr runs before this step; the step checks they exist, then turns the description of every deal
 * that has one and no note yet into its first note, written by the creator of the deal (or its owner) on the creation date.
 * The column llx_crm_opportunity.description is kept (a column is never dropped) but is no longer read or written.
 * Safe to run twice: a deal that already has a note is left alone.
 */
function crmSchemaStep4(DoliDB $db): bool
{
	$prefix = $db->prefix();
	$resql = $db->DDLDescTable($prefix.'crm_note', 'note');
	if (!($resql && $db->fetch_object($resql))) {
		dol_syslog('CRM: the table '.$prefix.'crm_note does not exist, enable the module again', LOG_ERR);
		return false;
	}
	$sql = 'INSERT INTO '.$prefix.'crm_note (entity, fk_opportunity, fk_user, datec, note)';
	$sql .= ' SELECT o.entity, o.rowid, CASE WHEN o.fk_user_creat > 0 THEN o.fk_user_creat ELSE o.fk_user_resp END, o.datec, o.description';
	$sql .= ' FROM '.$prefix.'crm_opportunity as o';
	$sql .= " WHERE o.description IS NOT NULL AND o.description <> '' AND NOT EXISTS (SELECT 1 FROM ".$prefix.'crm_note as n WHERE n.fk_opportunity = o.rowid)';
	if (!$db->query($sql)) {
		dol_syslog('CRM: could not move the descriptions to notes: '.$db->lasterror(), LOG_ERR);
		return false;
	}
	return true;
}

/**
 * Make sure an entity has the loss reason "Not interested" (code NOTINTERESTED), after the others. A reason the administrator
 * renamed or turned off keeps its row: only a missing code is added.
 */
function crmEnsureNotInterestedReason(DoliDB $db, int $entity): void
{
	$resql = $db->query('SELECT rowid FROM '.$db->prefix().'crm_loss_reason WHERE entity = '.$entity." AND code = 'NOTINTERESTED'");
	if (!$resql || $db->num_rows($resql) > 0) {
		return;
	}
	$position = 0;
	$resMax = $db->query('SELECT MAX(position) as mx FROM '.$db->prefix().'crm_loss_reason WHERE entity = '.$entity);
	if ($resMax && ($obj = $db->fetch_object($resMax)) && $obj->mx !== null && $obj->mx !== '') {
		$position = ((int) $obj->mx) + 10;
	}
	$db->query('INSERT INTO '.$db->prefix().'crm_loss_reason (entity, code, label, position, active) VALUES ('.$entity.", 'NOTINTERESTED', 'Not interested', ".$position.', 1)');
}

/**
 * Schema version 5 (from v1.2.0): the table llx_crm_optout comes from sql/llx_crm.sql, which Dolibarr runs before this step; the
 * step checks it exists and adds the loss reason "Not interested" to every entity that has a pipeline. Safe to run twice.
 */
function crmSchemaStep5(DoliDB $db): bool
{
	$resql = $db->DDLDescTable($db->prefix().'crm_optout', 'token_hash');
	if (!($resql && $db->fetch_object($resql))) {
		dol_syslog('CRM: the table '.$db->prefix().'crm_optout does not exist, enable the module again', LOG_ERR);
		return false;
	}
	$entities = $db->query('SELECT DISTINCT entity FROM '.$db->prefix().'crm_pipeline');
	while ($entities && ($obj = $db->fetch_object($entities))) {
		crmEnsureNotInterestedReason($db, (int) $obj->entity);
	}
	return true;
}

/**
 * Give a pipeline its reply stage when it has none: "Replied to outreach", an open stage placed after the first open stage
 * (between the first and the second open stage when there is room). A pipeline without an open stage is left alone.
 *
 * @return int Id of the stage created, 0 when nothing was needed or possible
 */
function crmEnsureReplyStage(DoliDB $db, int $pipelineId, int $entity): int
{
	$prefix = $db->prefix();
	$resql = $db->query('SELECT rowid FROM '.$prefix.'crm_stage WHERE fk_pipeline = '.$pipelineId.' AND is_reply = 1');
	if (!$resql || $db->num_rows($resql) > 0) {
		return 0;
	}
	$open = array();
	$resql = $db->query('SELECT position FROM '.$prefix.'crm_stage WHERE fk_pipeline = '.$pipelineId.' AND is_won = 0 AND is_lost = 0 ORDER BY position ASC, rowid ASC');
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$open[] = (int) $obj->position;
	}
	if (count($open) === 0) {
		return 0;
	}
	$position = isset($open[1]) ? intdiv($open[0] + $open[1], 2) : $open[0] + 1;
	$sql = 'INSERT INTO '.$prefix.'crm_stage (entity, fk_pipeline, label, position, color, probability, rotting_days, forecast_category, is_won, is_lost, active, is_reply) VALUES (';
	$sql .= $entity.', '.$pipelineId.", 'Replied to outreach', ".$position.", '#20c997', 15, 10, 'pipeline', 0, 0, 1, 1)";
	if (!$db->query($sql)) {
		return 0;
	}
	return (int) $db->last_insert_id($prefix.'crm_stage');
}

/**
 * True when the stage table has its is_reply column, so the code still works on an installation where the module has not
 * been re-enabled since the column was added.
 */
function crmStageReplySupported(DoliDB $db): bool
{
	static $supported = null;
	if ($supported === null) {
		$resql = $db->DDLDescTable($db->prefix().'crm_stage', 'is_reply');
		$supported = ($resql && $db->fetch_object($resql)) ? true : false;
	}
	return $supported;
}

/**
 * True when the journal table has its content columns, so a page or the job still works on an installation where the
 * module has not been re-enabled since they were added.
 */
function crmLogContentSupported(DoliDB $db): bool
{
	static $supported = null;
	if ($supported === null) {
		$resql = $db->DDLDescTable($db->prefix().'crm_sequence_log', 'body');
		$supported = ($resql && $db->fetch_object($resql)) ? true : false;
	}
	return $supported;
}

/**
 * Schema version installed on this entity: 0 when the module was never enabled here, or enabled before the version was kept.
 */
function crmSchemaInstalled(): int
{
	return getDolGlobalInt('CRM_SCHEMA_VERSION', 0);
}

/**
 * True when the code expects a newer schema than the one installed: the module has been updated and must be disabled and
 * enabled again, which runs the missing steps.
 */
function crmSchemaPending(): bool
{
	return crmSchemaInstalled() < crmSchemaCurrent();
}

/**
 * Run, in order, the schema steps newer than the installed version, and keep the version after each one that works. It
 * stops at the first step that fails, so the next enable starts again from there.
 *
 * @param array<int,array{label:string,run:callable}>|null $steps     Steps by version, null for the real ones (tests pass their own)
 * @param int|null                                         $installed Installed version, null to read the constant
 * @return array{ran:int[],error:string} The versions that were applied, and the reason when a step failed
 */
function crmUpgradeSchema(DoliDB $db, ?array $steps = null, ?int $installed = null): array
{
	global $conf;
	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	$steps = ($steps === null) ? crmSchemaSteps() : $steps;
	ksort($steps);
	$installed = ($installed === null) ? crmSchemaInstalled() : $installed;
	$out = array('ran' => array(), 'error' => '');
	foreach ($steps as $version => $step) {
		if ($version <= $installed) {
			continue;
		}
		$ok = false;
		$reason = '';
		try {
			$ok = (bool) call_user_func($step['run'], $db);
			$reason = $db->lasterror();
		} catch (Throwable $e) {
			$reason = $e->getMessage();
			dol_syslog('CRM: schema step '.$version.' failed: '.$reason, LOG_ERR);
		}
		if (!$ok) {
			$out['error'] = 'CRM schema step '.$version.' ('.$step['label'].') failed'.($reason !== '' ? ': '.$reason : '');
			return $out;
		}
		dolibarr_set_const($db, 'CRM_SCHEMA_VERSION', (string) $version, 'chaine', 0, '', (int) $conf->entity);
		$installed = $version;
		$out['ran'][] = $version;
	}
	return $out;
}

/**
 * The notice shown to a person who may configure the module when it has been updated and its schema is behind.
 */
function crmSchemaWarning(): string
{
	global $langs, $user;
	if (!crmSchemaPending() || !$user->hasRight('crm', 'setup')) {
		return '';
	}
	return '<div class="warning">'.$langs->trans('CrmSchemaPending', crmSchemaInstalled(), crmSchemaCurrent()).'</div>';
}

/**
 * True when the step table has its send_mode column, so a page or a tool can still work on an installation where the
 * module has not been re-enabled since the column was added.
 */
function crmStepModeSupported(DoliDB $db): bool
{
	static $supported = null;
	if ($supported === null) {
		$resql = $db->DDLDescTable($db->prefix().'crm_sequence_step', 'send_mode');
		$supported = ($resql && $db->fetch_object($resql)) ? true : false;
	}
	return $supported;
}

/**
 * From v1.2.0. The text of the "not interested" link of the e-mails of a deal, in the language of its third party when it has one,
 * else in the current language.
 */
function crmOptOutLinkText(int $dealId): string
{
	global $conf, $langs, $db;
	$lang = '';
	$resql = $db->query('SELECT s.default_lang FROM '.$db->prefix().'crm_opportunity as o INNER JOIN '.$db->prefix().'societe as s ON s.rowid = o.fk_soc WHERE o.rowid = '.((int) $dealId));
	if ($resql && ($obj = $db->fetch_object($resql)) && is_string($obj->default_lang) && preg_match('/^[a-z]{2}_[A-Z]{2}$/', $obj->default_lang)) {
		$lang = $obj->default_lang;
	}
	if ($lang !== '' && $lang !== $langs->defaultlang) {
		$other = new Translate('', $conf);
		$other->setDefaultLang($lang);
		$other->load('crm@crm');
		return $other->transnoentitiesnoconv('CrmOptOutLink');
	}
	crmLoadLangs();
	return $langs->transnoentitiesnoconv('CrmOptOutLink');
}

/**
 * Send one plain-text message through Dolibarr.
 *
 * With a deal id the message carries the deal tracking id (see crmTrackId) and is copied to the archive
 * mailbox, when one is set. Messages without a deal, such as owner reminders, are never copied.
 *
 * @param int  $dealId Deal the message belongs to, 0 for none
 * @param bool $html   True when $body is HTML. It is then sent as multipart/alternative: the HTML and a plain-text
 *                     version made from it, so a client that shows no HTML still reads the message
 * @param string $from Sender address, empty for the sender of the module (crmSender())
 * @param string $copyToMe Address that gets a hidden copy (Bcc) of this message, in addition to the archive mailbox; empty for none
 * @return array{ok:bool,error:string,msgid:string,from:string}
 */
function crmSendMail(string $to, string $subject, string $body, int $dealId = 0, bool $html = false, string $from = '', string $copyToMe = ''): array
{
	global $conf;
	require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
	require_once __DIR__.'/../class/crmoptout.class.php';
	// A sender is chosen by the caller among the addresses crmSenderChoices() offers; without one, or with one that is not an
	// address, the sender of the module is used.
	$from = trim($from);
	if ($from === '' || !isValidEmail($from)) {
		$from = crmSender();
	}
	// Nothing that goes into a header may carry a line break: it would add a header (a Reply-To, a Bcc).
	$subject = crmSingleLine($subject, 255);
	$to = crmSingleLine($to);
	$from = crmSingleLine($from);
	if ($from === '' || $to === '') {
		return array('ok' => false, 'error' => 'Missing sender or recipient', 'msgid' => '', 'from' => $from);
	}
	// The archive mailbox (for a message about a deal) and, on request, a hidden copy for the sender.
	$copies = crmCopyRecipients(crmSingleLine(getDolGlobalString('CRM_ARCHIVE_MAILBOX')), getDolGlobalString('CRM_ARCHIVE_COPY'), $copyToMe, $dealId > 0);
	$cc = $copies['cc'];
	$bcc = $copies['bcc'];
	$trackId = ($dealId > 0) ? crmTrackId($dealId) : '';
	if ($html) {
		$body = crmHtmlForMail($body);
	}
	// From v1.2.0, every e-mail sent for a deal ends with the public "Not interested, don't contact me again soon." link, whoever
	// wrote the text (a person, an outreach step, the AI): it is added here, at the one place all of them go through.
	if ($dealId > 0 && CrmOptOut::supported($GLOBALS['db'])) {
		$optOutUrl = (new CrmOptOut($GLOBALS['db']))->urlForDeal($dealId);
		if ($optOutUrl === '') {
			return array('ok' => false, 'error' => 'CrmErrorOptOutLink', 'msgid' => '', 'from' => $from);
		}
		$body = crmOptOutFooter($body, $optOutUrl, crmOptOutLinkText($dealId), $html);
	} elseif ($dealId > 0) {
		dol_syslog('CRM: e-mail for deal '.$dealId.' sent without the "not interested" link: enable the module again (schema step 5)', LOG_WARNING);
	}
	// With the "mail" send method Dolibarr adds the plain-text alternative only when this constant is set. It is forced
	// for this message alone, in memory: the other send methods always add it, and nothing is saved.
	$previousMultiPart = $conf->global->MAIN_MAIL_USE_MULTI_PART ?? null;
	if ($html) {
		$conf->global->MAIN_MAIL_USE_MULTI_PART = 1;
	}
	$mail = new CMailFile($subject, $to, $from, $body, array(), array(), array(), $cc, $bcc, 0, $html ? 1 : 0, '', '', $trackId);
	$sent = $mail->sendfile();
	if ($html) {
		if ($previousMultiPart === null) {
			unset($conf->global->MAIN_MAIL_USE_MULTI_PART);
		} else {
			$conf->global->MAIN_MAIL_USE_MULTI_PART = $previousMultiPart;
		}
	}
	if ($sent) {
		return array('ok' => true, 'error' => '', 'msgid' => (string) $mail->msgid, 'from' => $from);
	}
	return array('ok' => false, 'error' => (string) $mail->error, 'msgid' => '', 'from' => $from);
}

/**
 * Default constants. Existing values are left as the admin set them.
 */
function crmEnsureConstants(DoliDB $db): void
{
	global $conf;
	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	$defaults = array(
		'CRM_ROTTING_DAYS' => '15',
		'CRM_FORECAST_MONTHS' => '3',
		'CRM_MAX_EMAILS_PER_RUN' => '50',
		'CRM_MAIL_FROM' => '',
		'CRM_SEQUENCES_ON' => '0',
		'CRM_DRY_RUN' => '1',
		'CRM_REMINDERS_ON' => '1',
		'CRM_REMINDER_DRY_RUN' => '1',
		'CRM_REQUIRE_NEXT_STEP' => '1',
		'CRM_REPLY_MOVES_DEAL' => '1',
		'CRM_SEQUENCE_EMAIL_MODE' => 'draft',
		'CRM_AI_EMAIL_ASSIST' => '0',
		'CRM_AI_EMAIL_PROMPT' => '',
		'CRM_AI_TIMEOUT' => '120',
		'CRM_AI_MAX_PER_HOUR' => '30',
		'CRM_ARCHIVE_MAILBOX' => '',
		'CRM_ARCHIVE_COPY' => 'bcc',
		'CRM_ARCHIVE_REQUIRE_AUTH' => '0',
		'CRM_OPTOUT_CONFIRM' => '1',
		'CRM_CATEGORY_SINGLE' => '',
		// Read by the theme: without it the top menu icon is overridden by a generic glyph.
		'MAIN_MODULE_CRM_ICON' => 'fa-handshake',
	);
	foreach ($defaults as $name => $value) {
		// $conf->global is an object. property_exists keeps an explicit empty value such as CRM_MAIL_FROM.
		if (!is_object($conf->global) || !property_exists($conf->global, $name)) {
			dolibarr_set_const($db, $name, $value, 'chaine', 0, '', (int) $conf->entity);
		}
	}
}

/**
 * Insert the sample pipeline, stages, sources, qualification and loss reasons once per entity.
 */
function crmSeedIfEmpty(DoliDB $db): void
{
	global $conf, $user;
	$entity = (int) $conf->entity;
	$sql = 'SELECT COUNT(rowid) as nb FROM '.$db->prefix().'crm_pipeline WHERE entity = '.$entity;
	$resql = $db->query($sql);
	if (!$resql) {
		return;
	}
	$obj = $db->fetch_object($resql);
	if ($obj && (int) $obj->nb > 0) {
		return;
	}
	$now = $db->idate(dol_now());
	$userId = (is_object($user) ? (int) $user->id : 0);

	$db->query("INSERT INTO ".$db->prefix()."crm_pipeline (entity, ref, label, active, datec, fk_user_creat) VALUES (".$entity.", 'COMMERCIAL', '".$db->escape('Commercial pipeline')."', 1, '".$now."', ".$userId.")");
	$pipelineId = (int) $db->last_insert_id($db->prefix().'crm_pipeline');
	if ($pipelineId <= 0) {
		return;
	}

	$stages = array(
		array('New', 0, '#6c757d', 10, 10, 'pipeline', 0, 0),
		array('Replied to outreach', 5, '#20c997', 15, 10, 'pipeline', 0, 0, 1),
		array('Qualified', 10, '#0d6efd', 25, 15, 'pipeline', 0, 0),
		array('Proposal sent', 20, '#fd7e14', 50, 20, 'upside', 0, 0),
		array('Negotiation', 30, '#6f42c1', 75, 10, 'commit', 0, 0),
		array('Won', 40, '#198754', 100, 0, 'closed', 1, 0),
		array('Lost', 50, '#dc3545', 0, 0, 'lost', 0, 1),
	);
	foreach ($stages as $stage) {
		$sqlStage = "INSERT INTO ".$db->prefix()."crm_stage (entity, fk_pipeline, label, position, color, probability, rotting_days, forecast_category, is_won, is_lost, active, is_reply) VALUES (";
		$sqlStage .= $entity.", ".$pipelineId.", '".$db->escape($stage[0])."', ".((int) $stage[1]).", '".$db->escape($stage[2])."', ".((int) $stage[3]).", ".((int) $stage[4]).", '".$db->escape($stage[5])."', ".((int) $stage[6]).", ".((int) $stage[7]).", 1, ".((int) ($stage[8] ?? 0)).")";
		$db->query($sqlStage);
	}

	$sources = array(
		array('WEB', 'Website'),
		array('REFERRAL', 'Referral'),
		array('CALL', 'Inbound call'),
		array('EVENT', 'Event'),
		array('PARTNER', 'Partner'),
		array('PROSPECTING', 'Prospecting'),
		array('INBOUND_EMAIL', 'Inbound e-mail'),
	);
	$position = 0;
	foreach ($sources as $source) {
		$db->query("INSERT INTO ".$db->prefix()."crm_source (entity, code, label, position, active) VALUES (".$entity.", '".$db->escape($source[0])."', '".$db->escape($source[1])."', ".$position.", 1)");
		$position += 10;
	}

	$reasons = array(
		array('PRICE', 'Price'),
		array('NOBUDGET', 'No budget'),
		array('COMPETITOR', 'Competitor'),
		array('TIMING', 'Timing'),
		array('NODECISION', 'No decision'),
		array('NOANSWER', 'No answer'),
		array('NOTINTERESTED', 'Not interested'),
	);
	$position = 0;
	foreach ($reasons as $reason) {
		$db->query("INSERT INTO ".$db->prefix()."crm_loss_reason (entity, code, label, position, active) VALUES (".$entity.", '".$db->escape($reason[0])."', '".$db->escape($reason[1])."', ".$position.", 1)");
		$position += 10;
	}

	$items = array(
		array('NEED', 'Need confirmed', 25),
		array('BUDGET', 'Budget confirmed', 25),
		array('DECISION', 'Decision maker identified', 25),
		array('TIMELINE', 'Timeline confirmed', 25),
	);
	$position = 0;
	foreach ($items as $item) {
		$db->query("INSERT INTO ".$db->prefix()."crm_qualification (entity, code, label, points, position, active) VALUES (".$entity.", '".$db->escape($item[0])."', '".$db->escape($item[1])."', ".((int) $item[2]).", ".$position.", 1)");
		$position += 10;
	}
}

/**
 * Left-menu tabs are the module menu. Card sub-tabs are returned here.
 *
 * @return array<int,array<int,string>>
 */
function crmCardTabs(int $id, string $current): array
{
	global $langs;
	$head = array();
	$head[0][0] = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.$id;
	$head[0][1] = $langs->trans('CrmOpportunity');
	$head[0][2] = 'card';
	return $head;
}

/**
 * Next free OPPYYYY-nnnn reference for the current entity.
 */
function crmNextOpportunityRef(DoliDB $db): string
{
	global $conf;
	$year = dol_print_date(dol_now(), '%Y');
	$prefix = 'OPP'.$year.'-';
	$sql = "SELECT ref FROM ".$db->prefix()."crm_opportunity";
	$sql .= " WHERE entity = ".((int) $conf->entity);
	$sql .= " AND ref LIKE '".$db->escape($prefix)."%'";
	$sql .= " ORDER BY ref DESC";
	$sql .= $db->plimit(1, 0);
	$resql = $db->query($sql);
	$next = 1;
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj && !empty($obj->ref)) {
			$next = ((int) substr((string) $obj->ref, strlen($prefix))) + 1;
		}
	}
	return $prefix.sprintf('%04d', $next);
}

/**
 * Next free SEQnnnn reference.
 */
function crmNextSequenceRef(DoliDB $db): string
{
	global $conf;
	// A sequence can be renamed with any reference, so only the generated ones (SEQ + digits) give the next number.
	$sql = "SELECT ref FROM ".$db->prefix()."crm_sequence WHERE entity = ".((int) $conf->entity)." AND ref LIKE 'SEQ%'";
	$resql = $db->query($sql);
	$next = 1;
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$suffix = substr((string) $obj->ref, 3);
			if (ctype_digit($suffix) && ((int) $suffix) >= $next) {
				$next = ((int) $suffix) + 1;
			}
		}
	}
	return 'SEQ'.sprintf('%04d', $next);
}

/**
 * Translate a class error when it is a language key.
 */
function crmErrorText(string $error): string
{
	global $langs, $user;
	if ($error === '') {
		return '';
	}
	crmLoadLangs();
	$translated = $langs->trans($error);
	if ($translated !== '' && $translated !== $error) {
		return $translated;
	}
	// Not a language key: a message from the database, the mail server or the AI service. It can hold markup and details
	// about the installation, so it goes to the log, and only an administrator sees it, escaped.
	dol_syslog('CRM error: '.crmSingleLine($error, 500), LOG_WARNING);
	return (is_object($user) && !empty($user->admin)) ? dol_escape_htmltag($error) : $langs->trans('CrmErrorGeneric');
}

/**
 * The error of the database as a language key, after writing the detail to the log: the detail (table and column names,
 * values) is for an administrator reading the log, not for the person who clicked.
 */
function crmDbError(DoliDB $db): string
{
	dol_syslog('CRM database error: '.crmSingleLine((string) $db->lasterror(), 500), LOG_ERR);
	return 'CrmErrorDatabase';
}

/**
 * Refuse a request that asks for an action without the token of the session. Dolibarr checks the token of a POST and, at
 * its default level, of a GET whose parameter is called "action"; it does not see a button name such as act_send, and an
 * installation may lower its level. A page calls this once it knows its action: the pure display actions are exempt, every
 * other one must carry the token, in a form or in the link.
 *
 * @param string   $action         Action of the request
 * @param string[] $displayActions Actions of this page that only show something (a form, a confirmation)
 */
function crmRequireToken(string $action, array $displayActions = array()): void
{
	if (in_array($action, array_merge(array('', 'view', 'create', 'edit'), $displayActions), true)) {
		return;
	}
	$token = (string) GETPOST('token', 'alphanohtml');
	if ($token === '' || !hash_equals((string) currentToken(), $token)) {
		dol_syslog('CRM: action '.crmSingleLine($action, 40).' refused, token missing or invalid', LOG_WARNING);
		accessforbidden('Bad token');
	}
}

/**
 * Caller must hold this CRM permission.
 */
function crmRequireRight(string $perm): void
{
	global $user;
	if (!$user->hasRight('crm', $perm)) {
		accessforbidden();
	}
}

/**
 * source or loss_reason. Anything else is refused.
 */
function crmDictionaryTable(string $which): string
{
	if ($which === 'source') {
		return 'crm_source';
	}
	if ($which === 'loss_reason') {
		return 'crm_loss_reason';
	}
	return '';
}

/**
 * @return array<int,object>
 */
function crmFetchDictionary(DoliDB $db, string $which, bool $onlyActive = false): array
{
	global $conf;
	$table = crmDictionaryTable($which);
	if ($table === '') {
		return array();
	}
	if ($which === 'source') {
		crmEnsureBuiltinSources($db);
	}
	$sql = 'SELECT * FROM '.$db->prefix().$table.' WHERE entity = '.((int) $conf->entity);
	if ($onlyActive) {
		$sql .= ' AND active = 1';
	}
	$sql .= ' ORDER BY position ASC, label ASC';
	$list = array();
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$list[] = $obj;
		}
	}
	return $list;
}

/**
 * Insert builtin sources that were added after the first seed. A renamed or disabled row is left as the admin set it.
 */
function crmEnsureBuiltinSources(DoliDB $db): void
{
	global $conf;
	$entity = (int) $conf->entity;
	$code = 'INBOUND_EMAIL';
	$sql = 'SELECT rowid FROM '.$db->prefix().'crm_source WHERE entity = '.$entity." AND code = '".$db->escape($code)."'";
	$resql = $db->query($sql);
	if (!$resql || $db->num_rows($resql) > 0) {
		return;
	}
	$position = 60;
	$sqlMax = 'SELECT MAX(position) as mx FROM '.$db->prefix().'crm_source WHERE entity = '.$entity;
	$resMax = $db->query($sqlMax);
	if ($resMax && ($obj = $db->fetch_object($resMax)) && $obj->mx !== null && $obj->mx !== '') {
		$position = ((int) $obj->mx) + 10;
	}
	$db->query('INSERT INTO '.$db->prefix()."crm_source (entity, code, label, position, active) VALUES (".$entity.", '".$db->escape($code)."', '".$db->escape('Inbound e-mail')."', ".$position.', 1)');
}

/**
 * True when another row of the table already uses this code.
 */
function crmCodeExists(DoliDB $db, string $table, string $code, int $exceptId): bool
{
	global $conf;
	$sql = 'SELECT rowid FROM '.$db->prefix().$table.' WHERE entity = '.((int) $conf->entity)." AND code = '".$db->escape($code)."' AND rowid <> ".((int) $exceptId);
	$resql = $db->query($sql);
	return ($resql && $db->num_rows($resql) > 0);
}

/**
 * Insert or update a source or a loss reason.
 *
 * @param int $active 1 or 0, or -1 to leave an existing row as it is (a new row is then active)
 * @return int Row id, -1 on error, -2 when the code is already used
 */
function crmSaveDictionary(DoliDB $db, string $which, int $id, string $code, string $label, int $position, int $active): int
{
	global $conf;
	$table = crmDictionaryTable($which);
	if ($table === '' || $code === '' || $label === '') {
		return -1;
	}
	if (crmCodeExists($db, $table, $code, $id)) {
		return -2;
	}
	if ($id > 0) {
		$sql = 'UPDATE '.$db->prefix().$table.' SET';
		$sql .= " code = '".$db->escape($code)."', label = '".$db->escape($label)."', position = ".((int) $position);
		if ($active >= 0) {
			$sql .= ', active = '.($active ? 1 : 0);
		}
		$sql .= ' WHERE rowid = '.((int) $id).' AND entity = '.((int) $conf->entity);
		if (!$db->query($sql)) {
			return -1;
		}
		return $id;
	}
	$sql = 'INSERT INTO '.$db->prefix().$table.' (entity, code, label, position, active) VALUES (';
	$sql .= ((int) $conf->entity).", '".$db->escape($code)."', '".$db->escape($label)."', ".((int) $position).', '.($active === 0 ? 0 : 1).')';
	if (!$db->query($sql)) {
		return -1;
	}
	return (int) $db->last_insert_id($db->prefix().$table);
}

/**
 * @param int $active 1 or 0, or -1 to leave an existing row as it is (a new row is then active)
 * @return int Row id, -1 on error, -2 when the code is already used
 */
function crmSaveQualificationItem(DoliDB $db, int $id, string $code, string $label, int $points, int $position, int $active): int
{
	global $conf;
	if ($code === '' || $label === '') {
		return -1;
	}
	if (crmCodeExists($db, 'crm_qualification', $code, $id)) {
		return -2;
	}
	if ($id > 0) {
		$sql = 'UPDATE '.$db->prefix().'crm_qualification SET';
		$sql .= " code = '".$db->escape($code)."', label = '".$db->escape($label)."', points = ".((int) $points);
		$sql .= ', position = '.((int) $position);
		if ($active >= 0) {
			$sql .= ', active = '.($active ? 1 : 0);
		}
		$sql .= ' WHERE rowid = '.((int) $id).' AND entity = '.((int) $conf->entity);
		if (!$db->query($sql)) {
			return -1;
		}
		return $id;
	}
	$sql = 'INSERT INTO '.$db->prefix().'crm_qualification (entity, code, label, points, position, active) VALUES (';
	$sql .= ((int) $conf->entity).", '".$db->escape($code)."', '".$db->escape($label)."', ".((int) $points).', '.((int) $position).', '.($active === 0 ? 0 : 1).')';
	if (!$db->query($sql)) {
		return -1;
	}
	return (int) $db->last_insert_id($db->prefix().'crm_qualification');
}

/**
 * Table of a setup list: source, loss_reason or qualification. Empty when unknown.
 */
function crmItemTable(string $kind): string
{
	if ($kind === 'qualification') {
		return 'crm_qualification';
	}
	return crmDictionaryTable($kind);
}

/**
 * How many records point at a source, a loss reason or a qualification item.
 */
function crmItemUsage(DoliDB $db, string $kind, int $id): int
{
	$prefix = $db->prefix();
	if ($kind === 'source') {
		$sql = 'SELECT COUNT(rowid) as nb FROM '.$prefix.'crm_opportunity WHERE fk_source = '.((int) $id);
	} elseif ($kind === 'loss_reason') {
		$sql = 'SELECT COUNT(rowid) as nb FROM '.$prefix.'crm_opportunity WHERE fk_loss_reason = '.((int) $id);
	} elseif ($kind === 'qualification') {
		$sql = 'SELECT COUNT(rowid) as nb FROM '.$prefix.'crm_opportunity_qualification WHERE fk_qualification = '.((int) $id);
	} else {
		return 0;
	}
	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		return (int) $obj->nb;
	}
	return 0;
}

/**
 * Turn a source, a loss reason or a qualification item on or off.
 */
function crmSetItemActive(DoliDB $db, string $kind, int $id, int $active): bool
{
	global $conf;
	$table = crmItemTable($kind);
	if ($table === '') {
		return false;
	}
	$sql = 'UPDATE '.$db->prefix().$table.' SET active = '.($active ? 1 : 0).' WHERE rowid = '.((int) $id).' AND entity = '.((int) $conf->entity);
	return (bool) $db->query($sql);
}

/**
 * Delete a source, a loss reason or a qualification item that nothing uses. A used one must be turned off instead.
 *
 * @return string '' when deleted, else a language key of the error
 */
function crmDeleteItem(DoliDB $db, string $kind, int $id): string
{
	global $conf;
	$table = crmItemTable($kind);
	if ($table === '' || $id <= 0) {
		return 'Error';
	}
	if (crmItemUsage($db, $kind, $id) > 0) {
		return 'CrmErrorItemInUse';
	}
	if (!$db->query('DELETE FROM '.$db->prefix().$table.' WHERE rowid = '.((int) $id).' AND entity = '.((int) $conf->entity))) {
		return 'Error';
	}
	return '';
}

/**
 * From v1.2.0. The rows of the CRM history of a third party as table rows: date, deal, what happened, who.
 *
 * @param array<int,object> $rows Rows of CrmAccountView::fetchTimeline()
 * @return string HTML, escaped
 */
function crmAccountTimelineRows(array $rows): string
{
	global $db, $langs;
	$kinds = array('AC_TEL' => 'CrmCall', 'AC_RDV' => 'CrmMeeting', 'AC_OTH' => 'CrmTask', 'AC_EMAIL' => 'CrmEmail', 'AC_EMAIL_IN' => 'CrmEmail');
	$html = '';
	if (count($rows) === 0) {
		return '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('CrmNoRows').'</span></td></tr>';
	}
	foreach ($rows as $row) {
		if ($row->kind === 'stage') {
			$what = $langs->trans('CrmStage').': '.trim(crmStageLabel((string) $row->label_from).' → '.crmStageLabel((string) $row->label_to), ' →');
		} else {
			$kind = isset($kinds[(string) $row->event_code]) ? $langs->trans($kinds[(string) $row->event_code]).': ' : '';
			$what = $kind.(string) $row->event_label;
		}
		$who = trim((string) ($row->firstname ?? '').' '.(string) ($row->lastname ?? ''));
		$html .= '<tr class="oddeven">';
		$html .= '<td class="nowraponall">'.dol_print_date($db->jdate($row->dt), 'dayhour').'</td>';
		$html .= '<td><a href="'.dol_escape_htmltag(dol_buildpath('/crm/opportunity_card.php', 1).'?id='.((int) $row->deal_id)).'">'.dol_escape_htmltag((string) $row->deal_ref).'</a></td>';
		$html .= '<td>'.dol_escape_htmltag($what).'</td>';
		$html .= '<td>'.dol_escape_htmltag($who).'</td>';
		$html .= '</tr>';
	}
	return $html;
}

/**
 * From v1.2.0. A drop-down of the categories of third parties, as "Parent >> Child", to filter a list. Empty without the
 * Tags/Categories module or without any category.
 *
 * @param array<int,array{id:int,label:string,color:string,fk_parent:int,parent_label:string}> $available CrmCategory::fetchAvailable()
 */
function crmCategorySelect(string $name, int $selected, array $available, string $emptyLabel): string
{
	if (count($available) === 0) {
		return '';
	}
	$path = function (array $row) use ($available): string {
		$label = $row['label'];
		$seen = array();
		while ($row['fk_parent'] > 0 && isset($available[$row['fk_parent']]) && !in_array($row['fk_parent'], $seen, true)) {
			$seen[] = $row['fk_parent'];
			$row = $available[$row['fk_parent']];
			$label = $row['label'].' >> '.$label;
		}
		return $label;
	};
	$options = array();
	foreach ($available as $row) {
		$options[(int) $row['id']] = $path($row);
	}
	asort($options);
	$html = '<select name="'.dol_escape_htmltag($name).'" class="minwidth150"><option value="0">'.dol_escape_htmltag($emptyLabel).'</option>';
	foreach ($options as $id => $label) {
		$html .= '<option value="'.$id.'"'.($id === $selected ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
	}
	return $html.'</select>';
}
