<?php
declare(strict_types=1);

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
			return getDolGlobalString('CRM_MAIL_FROM');
		case 'main':
			return getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		case 'company':
			return (string) $mysoc->email;
	}
	return '';
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
 * Version of the database schema this code expects. It is a whole number that grows by one with each schema change, and it
 * is not the version of the module: several releases can share a schema version. Each number has a step in
 * crmSchemaSteps(); the installed number is kept in the constant CRM_SCHEMA_VERSION.
 */
function crmSchemaCurrent(): int
{
	return 1;
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
 * Send one plain-text message through Dolibarr.
 *
 * With a deal id the message carries the deal tracking id (see crmTrackId) and is copied to the archive
 * mailbox, when one is set. Messages without a deal, such as owner reminders, are never copied.
 *
 * @param int  $dealId Deal the message belongs to, 0 for none
 * @param bool $html   True when $body is HTML. It is then sent as multipart/alternative: the HTML and a plain-text
 *                     version made from it, so a client that shows no HTML still reads the message
 * @return array{ok:bool,error:string,msgid:string,from:string}
 */
function crmSendMail(string $to, string $subject, string $body, int $dealId = 0, bool $html = false): array
{
	global $conf;
	require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
	$from = crmSender();
	if ($from === '' || $to === '') {
		return array('ok' => false, 'error' => 'Missing sender or recipient', 'msgid' => '', 'from' => $from);
	}
	$cc = '';
	$bcc = '';
	$archive = trim(getDolGlobalString('CRM_ARCHIVE_MAILBOX'));
	if ($dealId > 0 && $archive !== '') {
		if (getDolGlobalString('CRM_ARCHIVE_COPY') === 'cc') {
			$cc = $archive;
		} else {
			$bcc = $archive;
		}
	}
	$trackId = ($dealId > 0) ? crmTrackId($dealId) : '';
	if ($html) {
		$body = crmHtmlForMail($body);
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
		'CRM_SEQUENCE_EMAIL_MODE' => 'draft',
		'CRM_AI_EMAIL_ASSIST' => '0',
		'CRM_AI_EMAIL_PROMPT' => '',
		'CRM_AI_TIMEOUT' => '120',
		'CRM_ARCHIVE_MAILBOX' => '',
		'CRM_ARCHIVE_COPY' => 'bcc',
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
		array('Qualified', 10, '#0d6efd', 25, 15, 'pipeline', 0, 0),
		array('Proposal sent', 20, '#fd7e14', 50, 20, 'upside', 0, 0),
		array('Negotiation', 30, '#6f42c1', 75, 10, 'commit', 0, 0),
		array('Won', 40, '#198754', 100, 0, 'closed', 1, 0),
		array('Lost', 50, '#dc3545', 0, 0, 'lost', 0, 1),
	);
	foreach ($stages as $stage) {
		$sqlStage = "INSERT INTO ".$db->prefix()."crm_stage (entity, fk_pipeline, label, position, color, probability, rotting_days, forecast_category, is_won, is_lost, active) VALUES (";
		$sqlStage .= $entity.", ".$pipelineId.", '".$db->escape($stage[0])."', ".((int) $stage[1]).", '".$db->escape($stage[2])."', ".((int) $stage[3]).", ".((int) $stage[4]).", '".$db->escape($stage[5])."', ".((int) $stage[6]).", ".((int) $stage[7]).", 1)";
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
	global $langs;
	if ($error === '') {
		return '';
	}
	crmLoadLangs();
	$translated = $langs->trans($error);
	return ($translated === '' ? $error : $translated);
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
