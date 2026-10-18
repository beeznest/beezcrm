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
declare(strict_types=1);

/**
 * \file    test/phpunit/CrmLogicTest.php
 * \ingroup crm
 * \brief   Pure rules. This file does not load Dolibarr and is not part of the core suite.
 */

require_once dirname(__FILE__).'/../../lib/crmlogic.lib.php';

use PHPUnit\Framework\TestCase;

/**
 * Placeholders, qualification score, cadence dates, rotting and weighted amount.
 */
class CrmLogicTest extends TestCase
{
	/**
	 * Unknown tokens stay in the text.
	 *
	 * @return void
	 */
	public function testPlaceholdersLeaveUnknownTokens()
	{
		$text = crmReplacePlaceholders('Hello __CONTACT__, deal __DEAL__ (__UNKNOWN__)', array(
			'CONTACT' => 'Ada',
			'DEAL' => 'OPP2026-0001',
		));
		$this->assertSame('Hello Ada, deal OPP2026-0001 (__UNKNOWN__)', $text);
	}

	/**
	 * Yes answers are summed and capped at 0..100.
	 *
	 * @return void
	 */
	public function testScoreIsCappedBetweenZeroAndOneHundred()
	{
		$over = array(
			array('points' => 80, 'answer' => 1),
			array('points' => 80, 'answer' => 1),
			array('points' => 40, 'answer' => 0),
		);
		$this->assertSame(100, crmScore($over));
		$this->assertSame(0, crmScore(array(array('points' => -10, 'answer' => 1))));
		$this->assertSame(25, crmScore(array(
			array('points' => 25, 'answer' => 1),
			array('points' => 25, 'answer' => 0),
		)));
	}

	/**
	 * Delay is counted from enrolment. A negative delay is treated as zero.
	 *
	 * @return void
	 */
	public function testDueTimestampCountsFromEnrolment()
	{
		$enrol = 1700000000;
		$this->assertSame($enrol + (3 * 86400), crmDueTimestamp($enrol, 3));
		$this->assertSame($enrol, crmDueTimestamp($enrol, 0));
		$this->assertSame($enrol, crmDueTimestamp($enrol, -2));
	}

	/**
	 * A delay of 0 disables rotting. The latest done activity wins over creation. The threshold is strict.
	 *
	 * @return void
	 */
	public function testRottingUsesLatestActivityAndAStrictDelay()
	{
		$created = 1000;
		$this->assertFalse(crmIsRotting(0, $created, 0, $created + (30 * 86400)));
		$this->assertFalse(crmIsRotting(0, $created, 10, $created + (10 * 86400)));
		$this->assertTrue(crmIsRotting(0, $created, 10, $created + (10 * 86400) + 1));
		$last = $created + (5 * 86400);
		$this->assertFalse(crmIsRotting($last, $created, 10, $last + (10 * 86400)));
		$this->assertTrue(crmIsRotting($last, $created, 10, $last + (10 * 86400) + 1));
		$this->assertFalse(crmIsRotting(0, 0, 10, $created + (30 * 86400)));
	}

	/**
	 * Weighted amount uses the stage probability, capped at 0..100.
	 *
	 * @return void
	 */
	public function testWeightedAmountCapsProbability()
	{
		$this->assertEquals(50.0, crmWeighted(200.0, 25));
		$this->assertEquals(0.0, crmWeighted(200.0, -5));
		$this->assertEquals(200.0, crmWeighted(200.0, 150));
	}

	/**
	 * The tracking id is read back from a Message-ID built the way CMailFile builds it, whatever the transport.
	 *
	 * @return void
	 */
	public function testDealIdIsReadFromTheMessageId()
	{
		$this->assertSame('crm42', crmTrackId(42));
		$this->assertSame(42, crmDealIdFromMessageId('65f1a2b3c4d5e6.12345678.SMTPs-dolibarr-crm42@crm.example.com'));
		$this->assertSame(7, crmDealIdFromMessageId('abc.phpmail-dolibarr-crm7@host'));
		$this->assertSame(0, crmDealIdFromMessageId('abc.phpmail-dolibarr-inv7@host'));
		$this->assertSame(0, crmDealIdFromMessageId('CAF=abc123@mail.gmail.com'));
	}

	/**
	 * Headers are matched by name, case-insensitively, and an absent header gives an empty string.
	 *
	 * @return void
	 */
	public function testHeaderValue()
	{
		$raw = "Date: Mon, 12 Oct 2026 10:00:00 +0000\nin-reply-to: <a@b>\nReferences: <a@b> <c@d>\nSubject: Re: X";
		$this->assertSame('<a@b>', crmHeaderValue($raw, 'In-Reply-To'));
		$this->assertSame('<a@b> <c@d>', crmHeaderValue($raw, 'References'));
		$this->assertSame('', crmHeaderValue($raw, 'Cc'));
	}

	/**
	 * References are split into ids without brackets or duplicates.
	 *
	 * @return void
	 */
	public function testMessageIdsAndAddresses()
	{
		$this->assertSame(array('a@b', 'c@d'), crmMessageIds('<a@b> <c@d>, <a@b>'));
		$this->assertSame(array(), crmMessageIds('no id here'));
		$this->assertSame(array('ada@example.com', 'bob@example.com'), crmAddressesIn('Ada <Ada@Example.com>, bob@example.com; ADA@example.com'));
	}

	/**
	 * The deal reference is found between square brackets, in replies as well, and added only once.
	 *
	 * @return void
	 */
	public function testDealReferenceInSubject()
	{
		$this->assertSame(array('OPP2026-0001'), crmDealRefsInSubject('Re: Offer [OPP2026-0001]'));
		$this->assertSame(array('OPP2026-0001', 'ABC-12'), crmDealRefsInSubject('[OPP2026-0001] Fwd: [ABC-12] [OPP2026-0001]'));
		$this->assertSame(array(), crmDealRefsInSubject('Offer (OPP2026-0001) [x]'));
		$this->assertSame('Offer [OPP2026-0001]', crmSubjectWithRef('Offer', 'OPP2026-0001'));
		$this->assertSame('Offer [OPP2026-0001]', crmSubjectWithRef('Offer [OPP2026-0001]', 'OPP2026-0001'));
		$this->assertSame('Offer', crmSubjectWithRef('Offer', ''));
	}

	/**
	 * Reply and forward prefixes, case and spacing do not make two subjects different.
	 *
	 * @return void
	 */
	public function testNormalizeSubject()
	{
		$this->assertSame('offer [opp2026-0001]', crmNormalizeSubject('RE:  Fwd: Offer   [OPP2026-0001]'));
		$this->assertSame(crmNormalizeSubject('Offer'), crmNormalizeSubject('re: OFFER'));
		$this->assertSame('reply to me', crmNormalizeSubject('Reply to me'));
	}

	/**
	 * The mailto link carries the archive copy, the subject and a CRLF body, all encoded.
	 *
	 * @return void
	 */
	public function testMailtoUrl()
	{
		$url = crmMailtoUrl('c@d.com', 'Offer [OPP1]', "Hi\nBye", '', 'crm@x.com');
		$this->assertSame('mailto:c%40d.com?bcc=crm%40x.com&subject=Offer%20%5BOPP1%5D&body=Hi%0D%0ABye', $url);
	}

	/**
	 * Quoted lines are dropped, blank runs collapsed, and a long text is cut with a marker.
	 *
	 * @return void
	 */
	public function testCompactMessage()
	{
		$text = "Thanks.\n\n\n\n> On Monday you wrote:\n> the quote\nSee you.   ";
		$this->assertSame("Thanks.\n\nSee you.", crmCompactMessage($text, 0));
		$this->assertSame("abcde\n[...]", crmCompactMessage('abcdefghij', 5));
	}

	/**
	 * The thread keeps the latest messages when it is too long, and says that older ones were left out.
	 *
	 * @return void
	 */
	public function testThreadTextDropsTheOldestFirst()
	{
		$messages = array(
			array('date' => 'd1', 'direction' => 'out', 'subject' => 'Offer', 'body' => str_repeat('a', 50)),
			array('date' => 'd2', 'direction' => 'in', 'subject' => 'Re: Offer', 'body' => str_repeat('b', 50)),
			array('date' => 'd3', 'direction' => 'out', 'subject' => 'Re: Offer', 'body' => str_repeat('c', 50)),
		);
		$all = crmThreadText($messages, 100, 10000, 'WE', 'THEY', 'OMITTED');
		$this->assertSame(1, preg_match('/^--- d1 \| WE \| Offer\n/', $all));
		$this->assertSame(1, preg_match('/--- d2 \| THEY \| Re: Offer\n/', $all));
		$short = crmThreadText($messages, 100, 150, 'WE', 'THEY', 'OMITTED');
		$this->assertSame(0, preg_match('/d1/', $short));
		$this->assertSame(1, preg_match('/^OMITTED\n/', $short));
		$this->assertSame(1, preg_match('/d3/', $short));
		$this->assertSame('', crmThreadText(array(), 100, 100, 'WE', 'THEY', 'OMITTED'));
	}

	/**
	 * Blocks and line breaks are followed by a line feed so the stripped version keeps its paragraphs.
	 *
	 * @return void
	 */
	public function testHtmlToTextKeepsParagraphs()
	{
		$html = '<p>Hello <strong>Ada</strong>,</p><p>Our offer:<br>20 licences &amp; support</p><ul><li>One</li><li>Two</li></ul>';
		$this->assertSame("Hello Ada,\n\nOur offer:\n20 licences & support\n\nOne\nTwo", crmHtmlToText($html));
		$this->assertSame('', crmHtmlToText(''));
		$this->assertSame("a\nb", crmHtmlToText("<div>a<br />b</div>"));
	}

	/**
	 * Plain text becomes escaped paragraphs, and what looks like HTML is recognised.
	 *
	 * @return void
	 */
	public function testTextToHtmlAndDetection()
	{
		$this->assertSame("<p>Hi &lt;Ada&gt;,<br>\nline two</p>\n<p>Bye &amp; thanks</p>", crmTextToHtml("Hi <Ada>,\nline two\n\nBye & thanks"));
		$this->assertSame('', crmTextToHtml("  \n "));
		$this->assertTrue(crmLooksLikeHtml('<p>x</p>'));
		$this->assertTrue(crmLooksLikeHtml('Hello<br>there'));
		$this->assertFalse(crmLooksLikeHtml("Plain text with a < b and c > d, and R&D"));
	}

	/**
	 * HTML that already has its own line breaks between paragraphs, as an AI model writes it, still gives paragraphs in the text.
	 *
	 * @return void
	 */
	public function testHtmlToTextWithExistingLineBreaks()
	{
		$html = "<p>Bonjour,</p>\n<p>Premier paragraphe<br>\nsuite</p>\n<p>Cordialement,</p>\n";
		$this->assertSame("Bonjour,\n\nPremier paragraphe\nsuite\n\nCordialement,", crmHtmlToText($html));
	}

	/**
	 * Words are counted without tags and entities; twelve is the longest text that stays visible.
	 *
	 * @return void
	 */
	public function testWordCount()
	{
		$this->assertSame(0, crmWordCount('  <br> '));
		$this->assertSame(3, crmWordCount('One <b>two</b>&nbsp;three'));
		$twelve = 'one two three four five six seven eight nine ten eleven twelve';
		$this->assertSame(12, crmWordCount($twelve));
		$this->assertSame(13, crmWordCount($twelve.' thirteen'));
		$this->assertSame(4, crmWordCount("Rédiger\n l'e-mail  du client"));
	}

	/**
	 * The AI time limit is kept between 10 and 600 seconds, and an empty value means 120.
	 *
	 * @return void
	 */
	public function testAiTimeoutSeconds()
	{
		$this->assertSame(120, crmAiTimeoutSeconds(0));
		$this->assertSame(120, crmAiTimeoutSeconds(-5));
		$this->assertSame(10, crmAiTimeoutSeconds(3));
		$this->assertSame(90, crmAiTimeoutSeconds(90));
		$this->assertSame(600, crmAiTimeoutSeconds(5000));
	}

	/**
	 * A call that ran up to the limit timed out; one that stopped early failed on the network.
	 *
	 * @return void
	 */
	public function testAiNoHttpKind()
	{
		$this->assertSame('timeout', crmAiNoHttpKind(120, 120));
		$this->assertSame('timeout', crmAiNoHttpKind(119, 120));
		$this->assertSame('network', crmAiNoHttpKind(5, 120));
		$this->assertSame('network', crmAiNoHttpKind(0, 30));
	}

	/**
	 * The day of a sequence counts whole days from the enrolment.
	 *
	 * @return void
	 */
	public function testSequenceDay()
	{
		$this->assertSame(0, crmSequenceDay(1000, 1000));
		$this->assertSame(0, crmSequenceDay(1000, 1000 + 86399));
		$this->assertSame(1, crmSequenceDay(1000, 1000 + 86400));
		$this->assertSame(3, crmSequenceDay(1000, 1000 + 3 * 86400 + 3600));
		$this->assertSame(0, crmSequenceDay(5000, 1000));
	}

	/**
	 * The archive mailbox and the sender's own copy: who is in Cc and Bcc, without duplicates.
	 *
	 * @return void
	 */
	public function testCopyRecipients()
	{
		$this->assertSame(array('cc' => '', 'bcc' => 'crm@example.com'), crmCopyRecipients('crm@example.com', 'bcc', '', true));
		$this->assertSame(array('cc' => 'crm@example.com', 'bcc' => ''), crmCopyRecipients('crm@example.com', 'cc', '', true));
		$this->assertSame(array('cc' => '', 'bcc' => 'crm@example.com,me@example.com'), crmCopyRecipients('crm@example.com', 'bcc', 'me@example.com', true));
		$this->assertSame(array('cc' => 'crm@example.com', 'bcc' => 'me@example.com'), crmCopyRecipients('crm@example.com', 'cc', 'me@example.com', true));
		$this->assertSame(array('cc' => '', 'bcc' => 'me@example.com'), crmCopyRecipients('crm@example.com', 'bcc', 'me@example.com', false));
		$this->assertSame(array('cc' => '', 'bcc' => 'me@example.com'), crmCopyRecipients('', 'bcc', 'me@example.com', true));
		$this->assertSame(array('cc' => '', 'bcc' => 'crm@example.com'), crmCopyRecipients('crm@example.com', 'bcc', 'CRM@example.com', true));
		$this->assertSame(array('cc' => '', 'bcc' => ''), crmCopyRecipients('', 'bcc', 'not an address', true));
		$this->assertSame(array('cc' => '', 'bcc' => 'a@example.com, me@example.com'), crmCopyRecipients('a@example.com, me@example.com', 'bcc', 'ME@example.com', true));
		$this->assertSame(array('cc' => '', 'bcc' => 'crm@example.com'), crmCopyRecipients('crm@example.com', 'bcc', 'x; Bcc: evil@example.com', true));
	}

	/**
	 * Stage order: by position, then by id.
	 *
	 * @return void
	 */
	public function testStageComesBefore()
	{
		$this->assertTrue(crmStageComesBefore(0, 5, 10, 2));
		$this->assertFalse(crmStageComesBefore(10, 2, 0, 5));
		$this->assertTrue(crmStageComesBefore(10, 2, 10, 3));
		$this->assertFalse(crmStageComesBefore(10, 3, 10, 3));
		$this->assertFalse(crmStageComesBefore(10, 4, 10, 3));
	}

	/**
	 * A value kept on one line, cut to a length.
	 *
	 * @return void
	 */
	public function testSingleLine()
	{
		$this->assertSame('Hello Reply-To: x@evil.tld', crmSingleLine("Hello\r\nReply-To: x@evil.tld"));
		$this->assertSame('a b', crmSingleLine("  a \t\t  b  "));
		$this->assertSame('abc', crmSingleLine('abcdef', 3));
		$this->assertSame('', crmSingleLine("\r\n"));
		$this->assertSame('é€', crmSingleLine('é€x', 2));
	}

	/**
	 * A sender is trusted when it is a known address or shares the domain of one, except on public mail services.
	 *
	 * @return void
	 */
	public function testSenderTrusted()
	{
		$known = array('Ada@Customer.com', 'bob@gmail.com');
		$this->assertTrue(crmSenderTrusted('ada@customer.com', $known));
		$this->assertTrue(crmSenderTrusted('colleague@customer.com', $known));
		$this->assertTrue(crmSenderTrusted('bob@gmail.com', $known));
		$this->assertFalse(crmSenderTrusted('stranger@gmail.com', $known));
		$this->assertFalse(crmSenderTrusted('x@evil.com', $known));
		$this->assertFalse(crmSenderTrusted('', $known));
		$this->assertFalse(crmSenderTrusted('ada@customer.com', array()));
		$this->assertFalse(crmSenderTrusted('x@customer.com.evil.com', $known));
	}

	/**
	 * Automatic mail: bounces, out-of-office replies, lists and bulk.
	 *
	 * @return void
	 */
	public function testAutomaticMail()
	{
		$this->assertTrue(crmIsAutomaticMail("From: a@b.c\nAuto-Submitted: auto-replied\n"));
		$this->assertFalse(crmIsAutomaticMail("From: a@b.c\nAuto-Submitted: no\n"));
		$this->assertTrue(crmIsAutomaticMail("Precedence: bulk\n"));
		$this->assertTrue(crmIsAutomaticMail("List-Id: <x.example.com>\n"));
		$this->assertTrue(crmIsAutomaticMail("From: Mail Delivery <MAILER-DAEMON@example.com>\n"));
		$this->assertTrue(crmIsAutomaticMail("Return-Path: <>\n"));
		$this->assertFalse(crmIsAutomaticMail("From: Ada <ada@customer.com>\nSubject: Re: offer\n"));
	}

	/**
	 * Authentication-Results passes, the date range and the rate limit.
	 *
	 * @return void
	 */
	public function testMailAuthDateAndRate()
	{
		$this->assertTrue(crmMailAuthPassed('mx.example.com; dkim=pass header.d=customer.com; spf=fail'));
		$this->assertTrue(crmMailAuthPassed('mx; spf=pass smtp.mailfrom=a@b.c'));
		$this->assertFalse(crmMailAuthPassed('mx; dkim=fail; spf=softfail'));
		$this->assertFalse(crmMailAuthPassed(''));
		$this->assertSame(1000, crmClampMailDate(1000, 2000));
		$this->assertSame(2000, crmClampMailDate(5000, 2000));
		$this->assertSame(2000, crmClampMailDate(0, 2000));
		$this->assertSame(2000000000, crmClampMailDate(100, 2000000000));
		$first = crmRateAllow(array(), 1000, 3600, 2);
		$this->assertTrue($first['allowed']);
		$second = crmRateAllow($first['timestamps'], 1010, 3600, 2);
		$this->assertTrue($second['allowed']);
		$third = crmRateAllow($second['timestamps'], 1020, 3600, 2);
		$this->assertFalse($third['allowed']);
		$later = crmRateAllow($third['timestamps'], 1000 + 3601, 3600, 2);
		$this->assertTrue($later['allowed']);
	}

	public function testNormalizeSetting(): void
	{
		$this->assertSame('1', crmNormalizeSetting('CRM_DRY_RUN', ' 1 '));
		$this->assertNull(crmNormalizeSetting('CRM_DRY_RUN', 'yes'));
		$this->assertSame('50', crmNormalizeSetting('CRM_MAX_EMAILS_PER_RUN', '050'));
		$this->assertNull(crmNormalizeSetting('CRM_MAX_EMAILS_PER_RUN', '501'));
		$this->assertNull(crmNormalizeSetting('CRM_AI_TIMEOUT', '5'));
		$this->assertSame('', crmNormalizeSetting('CRM_ROTTING_DAYS', ''));
		$this->assertSame('send', crmNormalizeSetting('CRM_SEQUENCE_EMAIL_MODE', 'send'));
		$this->assertNull(crmNormalizeSetting('CRM_SEQUENCE_EMAIL_MODE', 'fire'));
		$this->assertSame('crm@example.com', crmNormalizeSetting('CRM_MAIL_FROM', 'crm@example.com'));
		$this->assertSame('', crmNormalizeSetting('CRM_ARCHIVE_MAILBOX', ''));
		$this->assertNull(crmNormalizeSetting('CRM_MAIL_FROM', "crm@example.com\nBcc: a@b.example"));
		$this->assertNull(crmNormalizeSetting('CRM_MAIL_FROM', 'a@b.example,c@d.example'));
		$this->assertSame('Be brief.', crmNormalizeSetting('CRM_AI_EMAIL_PROMPT', '<b>Be brief.</b>'));
		$this->assertNull(crmNormalizeSetting('CRM_AI_EMAIL_PROMPT', str_repeat('x', 4001)));
		$this->assertNull(crmNormalizeSetting('MAIN_ANYTHING', '1'));
	}
}
