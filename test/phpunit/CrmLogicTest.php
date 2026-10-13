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
}
