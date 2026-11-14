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

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

/**
 * \file    class/crmoptoutlimit.class.php
 * \ingroup crm
 * \brief   From v1.2.0. Rate limit of the public page optout.php, per remote address, kept in small files (no table, no session).
 */

require_once __DIR__.'/../lib/crmlogic.lib.php';

/**
 * Two sliding windows per address: every request, and the requests with an unknown or malformed link. The state is one small
 * file per address under `<documents>/crm/optout_limit/`, named after a hash of the address (the address itself is not stored).
 * When the folder cannot be written the limit is skipped (the page must keep working), and a line is written to the log.
 *
 * The address is REMOTE_ADDR, never a header the visitor can set: behind a reverse proxy the web server has to restore the real
 * address (nginx realip, Apache mod_remoteip), see the administrator guide.
 */
class CrmOptOutLimit
{
	/** @var string */
	private $file = '';
	/** @var string */
	private $dir;
	/** @var int Seconds */
	private $window;
	/** @var int Requests per window, 0 for no limit */
	private $maxAll;
	/** @var int Unknown links per window, 0 for no limit */
	private $maxFail;

	/**
	 * @param string      $address Remote address, '' to turn the limit off
	 * @param string|null $dir     Folder of the state files, null for the one under the documents
	 */
	public function __construct(string $address, ?string $dir = null)
	{
		$this->window = max(1, getDolGlobalInt('CRM_OPTOUT_RATE_WINDOW', 600));
		$this->maxAll = max(0, getDolGlobalInt('CRM_OPTOUT_RATE_ALL', 120));
		$this->maxFail = max(0, getDolGlobalInt('CRM_OPTOUT_RATE_FAILS', 10));
		$this->dir = $dir ?? (DOL_DATA_ROOT.'/crm/optout_limit');
		if ($address !== '' && ($this->maxAll > 0 || $this->maxFail > 0)) {
			$this->file = rtrim($this->dir, '/').'/ip_'.hash('sha256', $address).'.json';
		}
	}

	public function window(): int
	{
		return $this->window;
	}

	/**
	 * Count one request and say whether it may go on. A request over the limit is refused and not counted, so the window
	 * empties by itself once the visitor stops.
	 */
	public function enter(?int $now = null): bool
	{
		$now = $now ?? time();
		$allowed = true;
		$this->update($now, function (array $state) use ($now, &$allowed): array {
			if (crmRateExceeded($state['fail'], $now, $this->window, $this->maxFail) || crmRateExceeded($state['all'], $now, $this->window, $this->maxAll)) {
				$allowed = false;
				return $state;
			}
			$state['all'][] = $now;
			return $state;
		});
		return $allowed;
	}

	/**
	 * Count a request that named no known link.
	 */
	public function fail(?int $now = null): void
	{
		$now = $now ?? time();
		$this->update($now, static function (array $state) use ($now): array {
			$state['fail'][] = $now;
			return $state;
		});
	}

	/**
	 * @param callable(array{all:array<int,int>,fail:array<int,int>}):array{all:array<int,int>,fail:array<int,int>} $change
	 */
	private function update(int $now, callable $change): void
	{
		if ($this->file === '') {
			return;
		}
		if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
			$this->unwritable();
			return;
		}
		$handle = @fopen($this->file, 'c+');
		if ($handle === false) {
			$this->unwritable();
			return;
		}
		if (flock($handle, LOCK_EX)) {
			$decoded = json_decode((string) stream_get_contents($handle), true);
			$decoded = is_array($decoded) ? $decoded : array();
			$state = array(
				'all' => crmRatePrune(is_array($decoded['all'] ?? null) ? $decoded['all'] : array(), $now, $this->window),
				'fail' => crmRatePrune(is_array($decoded['fail'] ?? null) ? $decoded['fail'] : array(), $now, $this->window),
			);
			$state = $change($state);
			ftruncate($handle, 0);
			rewind($handle);
			fwrite($handle, (string) json_encode($state));
			fflush($handle);
			flock($handle, LOCK_UN);
		}
		fclose($handle);
		// Now and then, forget the addresses that have not come back for a whole window.
		if (random_int(1, 50) === 1) {
			$this->purge($now);
		}
	}

	private function purge(int $now): void
	{
		foreach ((array) glob(rtrim($this->dir, '/').'/ip_*.json') as $file) {
			$modified = @filemtime($file);
			if ($modified !== false && $modified < $now - $this->window - 60) {
				@unlink($file);
			}
		}
	}

	private function unwritable(): void
	{
		static $said = false;
		if (!$said) {
			$said = true;
			dol_syslog('CRM opt-out: the folder of the rate limit is not writable, the limit is skipped', LOG_WARNING);
		}
	}
}
