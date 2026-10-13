<?php
declare(strict_types=1);

/**
 * \file    class/crmaireply.class.php
 * \ingroup crm
 * \brief   Ask the AI service configured in Dolibarr to propose the next e-mail of a deal.
 */

require_once __DIR__.'/../lib/crm.lib.php';
require_once __DIR__.'/crmactivity.class.php';

/**
 * The module has no AI client of its own: it builds a prompt and hands it to Dolibarr's Ai class, so the provider,
 * key, model and privacy redaction are the ones set in the AI module. Nothing is sent by this class to the customer;
 * it returns a text for the salesperson to read and edit.
 */
class CrmAiReply
{
	/** @var DoliDB */
	public $db;
	/** @var string Provider message, or a language key, when suggest() fails */
	public $error = '';
	/** @var string Text sent to the service on the last call, after any redaction */
	public $lastInstructions = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * True when the AI module is on and an administrator has switched the e-mail assistant on. It is off until then,
	 * because the exchange with a customer goes to an external provider.
	 */
	public static function isAvailable(): bool
	{
		return isModEnabled('ai') && getDolGlobalInt('CRM_AI_EMAIL_ASSIST', 0) !== 0;
	}

	/**
	 * Prompt offered to the salesperson: the one the administrator saved, else the built-in one.
	 */
	public static function defaultPrompt(): string
	{
		global $langs;
		$saved = trim(getDolGlobalString('CRM_AI_EMAIL_PROMPT'));
		if ($saved !== '') {
			return $saved;
		}
		crmLoadLangs();
		return $langs->transnoentitiesnoconv('CrmAiDefaultPrompt');
	}

	/**
	 * Name of the AI service the text will be sent to, for the notice shown to the salesperson.
	 */
	public static function serviceLabel(): string
	{
		require_once DOL_DOCUMENT_ROOT.'/ai/lib/ai.lib.php';
		$service = getDolGlobalString('AI_API_SERVICE', 'chatgpt');
		$list = getListOfAIServices();
		$label = isset($list[$service]['label']) ? (string) $list[$service]['label'] : $service;
		if ($service === 'custom') {
			$model = getDolGlobalString('AI_API_CUSTOM_MODEL_TEXT');
			$url = (string) parse_url(getDolGlobalString('AI_API_CUSTOM_URL'), PHP_URL_HOST);
			$label .= ($url !== '' ? ' ('.$url.($model !== '' ? ', '.$model : '').')' : '');
		}
		return $label;
	}

	/**
	 * Everything sent to the AI service: the prompt, a summary of the deal, the e-mails exchanged so far, the current
	 * draft and the output rules.
	 *
	 * @param int    $dealId  Deal id
	 * @param string $prompt  Prompt written or edited by the salesperson
	 * @param string $subject Subject of the draft
	 * @param string $body    Current text of the draft
	 * @return array{text:string,names:string[]} text, and the names to mask when redaction is on
	 */
	public function buildInstructions(int $dealId, string $prompt, string $subject, string $body): array
	{
		global $conf, $langs;
		crmLoadLangs();
		$sql = 'SELECT o.ref, o.label, o.amount, o.date_close_expected, o.description, st.label as stage_label, st.probability,';
		$sql .= ' s.nom as soc_name, sp.firstname, sp.lastname, u.firstname as owner_firstname, u.lastname as owner_lastname';
		$sql .= ' FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'crm_stage as st ON st.rowid = o.fk_stage';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'societe as s ON s.rowid = o.fk_soc';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'socpeople as sp ON sp.rowid = o.fk_socpeople';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'user as u ON u.rowid = o.fk_user_resp';
		$sql .= ' WHERE o.rowid = '.((int) $dealId).' AND o.entity = '.((int) $conf->entity);
		$resql = $this->db->query($sql);
		$deal = $resql ? $this->db->fetch_object($resql) : null;
		if (!$deal) {
			return array('text' => '', 'names' => array());
		}
		$contact = trim((string) $deal->firstname.' '.(string) $deal->lastname);
		$owner = trim((string) $deal->owner_firstname.' '.(string) $deal->owner_lastname);
		$expected = !empty($deal->date_close_expected) ? dol_print_date($this->db->jdate($deal->date_close_expected), 'day') : '-';

		$messages = array();
		foreach ((new CrmActivity($this->db))->fetchEmailThread($dealId, 10) as $row) {
			$messages[] = array(
				'date' => dol_print_date($this->db->jdate($row->datep), 'dayhour'),
				'direction' => ((string) $row->code === 'AC_EMAIL_IN') ? 'in' : 'out',
				'subject' => (string) ($row->email_subject !== null && $row->email_subject !== '' ? $row->email_subject : $row->label),
				'body' => crmLooksLikeHtml((string) $row->note) ? crmHtmlToText((string) $row->note) : (string) $row->note,
			);
		}
		$thread = crmThreadText($messages, 1500, 10000, 'WE SENT', 'THE CUSTOMER WROTE', '[Older messages left out]');
		if ($thread === '') {
			$thread = 'No e-mail has been exchanged yet.';
		}

		$text = trim($prompt)."\n\n";
		$text .= "=== Deal ===\n";
		$text .= 'Reference: '.$deal->ref."\n";
		$text .= 'Title: '.$deal->label."\n";
		$text .= 'Account: '.$deal->soc_name."\n";
		$text .= 'Contact: '.($contact !== '' ? $contact : '-')."\n";
		$text .= 'Stage: '.crmDictLabel((string) $deal->stage_label).' ('.((int) $deal->probability)."%)\n";
		$text .= 'Amount: '.price((float) $deal->amount)."\n";
		$text .= 'Expected close: '.$expected."\n";
		$text .= 'Salesperson, author of our e-mails: '.($owner !== '' ? $owner : '-')."\n";
		if (trim((string) $deal->description) !== '') {
			$text .= 'Notes: '.crmCompactMessage((string) $deal->description, 1000)."\n";
		}
		$text .= "\n=== E-mails exchanged so far, oldest first ===\n".$thread."\n";
		if (trim(crmLooksLikeHtml($body) ? crmHtmlToText($body) : $body) !== '') {
			$text .= "\n=== Current draft of our next e-mail (improve it or replace it) ===\n";
			$draftText = crmLooksLikeHtml($body) ? crmHtmlToText($body) : $body;
			$text .= 'Subject: '.trim($subject)."\n".crmCompactMessage($draftText, 3000)."\n";
		}
		$text .= "\n=== Output rules ===\n";
		$text .= "Write only the body of the next e-mail from us, as plain text: no subject line, no explanation, no markdown. Use the language of the exchange.\n";
		return array('text' => $text, 'names' => array((string) $deal->soc_name, $contact, $owner));
	}

	/**
	 * Ask the AI service for the next e-mail.
	 *
	 * @param int           $dealId    Deal id
	 * @param string        $prompt    Prompt written or edited by the salesperson
	 * @param string        $subject   Subject of the draft
	 * @param string        $body      Current text of the draft
	 * @param callable|null $generator Replaces the call to the AI service, for tests: fn(string): string|array
	 * @return string|null The proposed text, null on failure with the reason in ->error
	 */
	public function suggest(int $dealId, string $prompt, string $subject, string $body, ?callable $generator = null): ?string
	{
		$built = $this->buildInstructions($dealId, $prompt, $subject, $body);
		if ($built['text'] === '') {
			$this->error = 'CrmErrorAiNoDeal';
			return null;
		}
		$text = $built['text'];
		$guard = null;
		$nameMap = array();
		if (getDolGlobalInt('AI_PRIVACY_REDACTION', 0)) {
			// The redaction policy of the AI module applies to this call like to every other: names and identifiers
			// are replaced by placeholders before the text leaves, and put back in the answer.
			require_once DOL_DOCUMENT_ROOT.'/ai/class/privacy_guard.class.php';
			$guard = new PrivacyGuard();
			$text = $guard->mask($text);
			if (method_exists($guard, 'maskNames')) {
				$text = $guard->maskNames($text, $built['names']);
			} else {
				// Dolibarr 24.0.0 has mask() but not maskNames(): the names are masked here, with the same kind of placeholder.
				$text = $this->maskNamesLocally($text, $built['names'], $nameMap);
			}
			if (strpos($text, '[[') !== false) {
				$text .= "\nTokens such as [[REF_1]] are privacy-masked real values: keep them verbatim in the e-mail.";
			}
		}
		$this->lastInstructions = $text;

		if ($generator !== null) {
			$answer = $generator($text);
		} else {
			require_once DOL_DOCUMENT_ROOT.'/ai/class/ai.class.php';
			$ai = new Ai($this->db);
			$answer = $ai->generateContent($text, 'auto', 'textgenerationemail', '');
		}
		if (is_array($answer)) {
			$this->error = !empty($answer['message']) ? (string) $answer['message'] : 'CrmErrorAiNoAnswer';
			return null;
		}
		$answer = (string) $answer;
		if ($guard !== null) {
			$answer = $guard->unmaskAiResponse($answer);
			foreach ($nameMap as $token => $original) {
				$answer = str_replace(array($token, substr($token, 2, -2)), $original, $answer);
			}
		}
		$answer = $this->clean($answer);
		if ($answer === '') {
			$this->error = 'CrmErrorAiNoAnswer';
			return null;
		}
		return $answer;
	}

	/**
	 * Replace each name by a placeholder, longest first, ignoring case. Names under four characters are left alone,
	 * as they would shred unrelated words.
	 *
	 * @param string               $text    Text to mask
	 * @param string[]             $names   Names to look for
	 * @param array<string,string> $map     Receives placeholder => original name
	 */
	private function maskNamesLocally(string $text, array $names, array &$map): string
	{
		$clean = array();
		foreach ($names as $name) {
			$name = trim($name);
			if (dol_strlen($name) >= 4) {
				$clean[] = $name;
			}
		}
		usort($clean, static function (string $a, string $b): int {
			return strlen($b) <=> strlen($a);
		});
		$salt = substr(md5(uniqid('', true)), 0, 4);
		foreach ($clean as $index => $name) {
			$token = '[[NAME_'.($index + 1).$salt.']]';
			$count = 0;
			$text = (string) preg_replace('/'.preg_quote($name, '/').'/iu', $token, $text, -1, $count);
			if ($count > 0) {
				$map[$token] = $name;
			}
		}
		return $text;
	}

	/**
	 * The answer as plain text: no HTML, no code fence around it.
	 */
	private function clean(string $answer): string
	{
		// Paragraph and line breaks written as HTML become line feeds before the tags are removed.
		$answer = (string) preg_replace('#<br\s*/?>|</p>|</div>|</li>|</h[1-6]>#i', "\n", $answer);
		$answer = dol_string_nohtmltag($answer, 0);
		$answer = dol_html_entity_decode($answer, ENT_QUOTES | ENT_HTML5);
		$answer = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($answer));
		return trim(str_replace(array("\r\n", "\r"), "\n", $answer));
	}
}
