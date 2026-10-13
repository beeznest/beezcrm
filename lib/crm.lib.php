<?php
declare(strict_types=1);

/**
 * CRM helpers that need Dolibarr. Pure rules live in crmlogic.lib.php.
 */

require_once __DIR__.'/crmlogic.lib.php';

/**
 * Load the module language.
 */
function crmLoadLangs(): void
{
	global $langs;
	$langs->load('crm@crm');
}

/**
 * Label of a built-in stage in the current language. A renamed or custom stage stays as stored.
 */
function crmStageLabel(string $label): string
{
	global $langs;
	$map = array(
		'New' => 'CrmStageNew',
		'Qualified' => 'CrmStageQualified',
		'Proposal sent' => 'CrmStageProposalSent',
		'Negotiation' => 'CrmStageNegotiation',
		'Won' => 'CrmStageWon',
		'Lost' => 'CrmStageLost',
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
 * Sender used by sequences and owner reminders.
 */
function crmSender(): string
{
	global $mysoc;
	$from = getDolGlobalString('CRM_MAIL_FROM');
	if ($from === '') {
		$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
	}
	if ($from === '' && !empty($mysoc->email)) {
		$from = (string) $mysoc->email;
	}
	return $from;
}

/**
 * Send one plain-text message through Dolibarr.
 *
 * With a deal id the message carries the deal tracking id (see crmTrackId) and is copied to the archive
 * mailbox, when one is set. Messages without a deal, such as owner reminders, are never copied.
 *
 * @param int $dealId Deal the message belongs to, 0 for none
 * @return array{ok:bool,error:string,msgid:string,from:string}
 */
function crmSendMail(string $to, string $subject, string $body, int $dealId = 0): array
{
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
	$mail = new CMailFile($subject, $to, $from, $body, array(), array(), array(), $cc, $bcc, 0, 0, '', '', $trackId);
	if ($mail->sendfile()) {
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
	$sql = "SELECT ref FROM ".$db->prefix()."crm_sequence WHERE entity = ".((int) $conf->entity)." ORDER BY ref DESC";
	$sql .= $db->plimit(1, 0);
	$resql = $db->query($sql);
	$next = 1;
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj && !empty($obj->ref)) {
			$next = ((int) substr((string) $obj->ref, 3)) + 1;
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
 * Insert or update a source or a loss reason.
 *
 * @return int Row id, or -1
 */
function crmSaveDictionary(DoliDB $db, string $which, int $id, string $code, string $label, int $position, int $active): int
{
	global $conf;
	$table = crmDictionaryTable($which);
	if ($table === '' || $code === '' || $label === '') {
		return -1;
	}
	if ($id > 0) {
		$sql = 'UPDATE '.$db->prefix().$table.' SET';
		$sql .= " code = '".$db->escape($code)."', label = '".$db->escape($label)."', position = ".((int) $position).', active = '.((int) $active);
		$sql .= ' WHERE rowid = '.((int) $id).' AND entity = '.((int) $conf->entity);
		if (!$db->query($sql)) {
			return -1;
		}
		return $id;
	}
	$sql = 'INSERT INTO '.$db->prefix().$table.' (entity, code, label, position, active) VALUES (';
	$sql .= ((int) $conf->entity).", '".$db->escape($code)."', '".$db->escape($label)."', ".((int) $position).', '.((int) $active).')';
	if (!$db->query($sql)) {
		return -1;
	}
	return (int) $db->last_insert_id($db->prefix().$table);
}

/**
 * @return int Row id, or -1
 */
function crmSaveQualificationItem(DoliDB $db, int $id, string $code, string $label, int $points, int $position, int $active): int
{
	global $conf;
	if ($code === '' || $label === '') {
		return -1;
	}
	if ($id > 0) {
		$sql = 'UPDATE '.$db->prefix().'crm_qualification SET';
		$sql .= " code = '".$db->escape($code)."', label = '".$db->escape($label)."', points = ".((int) $points);
		$sql .= ', position = '.((int) $position).', active = '.((int) $active);
		$sql .= ' WHERE rowid = '.((int) $id).' AND entity = '.((int) $conf->entity);
		if (!$db->query($sql)) {
			return -1;
		}
		return $id;
	}
	$sql = 'INSERT INTO '.$db->prefix().'crm_qualification (entity, code, label, points, position, active) VALUES (';
	$sql .= ((int) $conf->entity).", '".$db->escape($code)."', '".$db->escape($label)."', ".((int) $points).', '.((int) $position).', '.((int) $active).')';
	if (!$db->query($sql)) {
		return -1;
	}
	return (int) $db->last_insert_id($db->prefix().'crm_qualification');
}
