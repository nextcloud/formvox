<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;

/**
 * One writer per form at a time. A key of our own, valued with its holder (token, host, pid) and released only by that
 * holder: Nextcloud's own locks have no owner check, so a holder that lost its lock could free its successor's
 * (spike M2/A2). On Redis or Memcached the key lives in the locking cache and expires by itself; otherwise (APCu,
 * which web and cron do not share, or no cache) a row in oc_preferences that every process sees. A waiting writer
 * retries with jittered backoff until its deadline. A holder is taken over only when it is gone: expired, past its
 * time, or a dead process on this host; `occ formvox:unlock` frees one by hand.
 */
class FormLock {
	private const KEY = 'formvox/form/';
	/** A write takes seconds at most; a holder still there after this is stuck or dead. */
	private const HOLD_SECONDS = 120;
	private const ROW_USER = '__formvox_lock__';

	/** @var array<string, string> held keys and their values, released when PHP stops on a fatal error */
	private array $held = [];
	private bool $releaseOnShutdown = false;
	/** This machine and PID namespace; '-' where that cannot be told. */
	private static ?string $hostId = null;
	/** The shared locking cache, false when there is none and the row is used. */
	private IMemcache|false|null $cache = null;

	public function __construct(
		private ICacheFactory $cacheFactory,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $purpose what the lock is for: one writer per form, one sender of its outbox
	 * @return string the token that releases it
	 * @throws FormLockedException the form stayed busy until the deadline
	 */
	public function acquire(int $fileId, float $deadline, string $purpose = 'form'): string {
		$key = $this->key($fileId, $purpose);
		$token = bin2hex(random_bytes(8));
		$value = time() . ':' . $token . ':' . self::host() . ':' . getmypid();
		for ($attempt = 0; ; $attempt++) {
			if ($this->tryAcquire($key, $value)) {
				$this->held[$key] = $value;
				$this->releaseHeldOnShutdown();
				return $token;
			}
			if (microtime(true) >= $deadline) {
				throw new FormLockedException('Form ' . $fileId . ' stayed busy');
			}
			self::backoff($attempt, $deadline);
		}
	}

	public function release(int $fileId, string $token, string $purpose = 'form'): void {
		$key = $this->key($fileId, $purpose);
		$value = $this->held[$key] ?? null;
		if ($value === null || explode(':', $value)[1] !== $token) {
			return;
		}
		unset($this->held[$key]);
		$this->releaseKey($key, $value);
	}

	/** Whether the lock is still ours: one that expired while its holder stalled may have a new holder by now. */
	public function stillHeld(int $fileId, string $token, string $purpose = 'form'): bool {
		$key = $this->key($fileId, $purpose);
		$value = $this->held[$key] ?? null;
		if ($value === null) {
			return false;
		}
		$parts = explode(':', $value);
		// Past its time anyone may take it over, as an expired key: a row never expires by itself (review of 3b-3b).
		return $parts[1] === $token && time() - (int)$parts[0] < self::HOLD_SECONDS && $this->current($key) === $value;
	}

	/** Whether anyone holds the form now. */
	public function isHeld(int $fileId, string $purpose = 'form'): bool {
		return $this->current($this->key($fileId, $purpose)) !== null;
	}

	/** Whether someone else holds the form now, a holder that is not gone: a save may be under way. */
	public function heldByAnother(int $fileId, string $purpose = 'form'): bool {
		$key = $this->key($fileId, $purpose);
		$value = $this->current($key);
		return $value !== null && $value !== ($this->held[$key] ?? null) && !$this->holderIsGone($value);
	}

	/**
	 * Frees a form whose holder cannot release it (a killed process), whoever holds it.
	 *
	 * @return bool whether it was held
	 */
	public function unlock(int $fileId, string $purpose = 'form'): bool {
		$key = $this->key($fileId, $purpose);
		$value = $this->current($key);
		if ($value === null) {
			return false;
		}
		$this->releaseKey($key, $value);
		return true;
	}

	/** Up to 100 ms, spread at random so waiting writers do not all try again at once; never past the deadline. */
	public static function backoff(int $attempt, float $deadline): void {
		$capMs = min(100, 10 * (2 ** min($attempt, 4)));
		$waitUs = random_int(5, $capMs) * 1000;
		$leftUs = (int)(($deadline - microtime(true)) * 1_000_000);
		usleep(max(0, min($waitUs, $leftUs)));
	}

	private function key(int $fileId, string $purpose): string {
		return self::KEY . ($purpose === 'form' ? '' : $purpose . '/') . $fileId;
	}

	private function tryAcquire(string $key, string $value): bool {
		$cache = $this->cache();
		if ($cache !== false) {
			if ($cache->add($key, $value, self::HOLD_SECONDS)) {
				return true;
			}
		} else {
			$row = $this->insertRow($key, $value);
			if ($row === 'taken') {
				return true;
			}
			if ($row === 'stuck') {
				// A takeover would only wait on the same stuck lock again.
				return false;
			}
		}
		$seen = $this->current($key);
		if ($seen !== null && $this->holderIsGone($seen)) {
			$this->logger->warning('FormVox: took over the lock on a form from a holder that is gone (' . $key . ')', ['app' => Application::APP_ID]);
			// Only the value seen is removed: a holder that came in meanwhile keeps its lock.
			$this->releaseKey($key, $seen);
		}
		return false;
	}

	private function releaseKey(string $key, string $value): void {
		try {
			$cache = $this->cache();
			if ($cache !== false) {
				$cache->cad($key, $value);
			} else {
				$this->deleteRow($key, $value);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: could not release the lock on a form: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	private function current(string $key): ?string {
		$cache = $this->cache();
		$value = $cache !== false ? $cache->get($key) : $this->rowValue($key);
		return is_string($value) ? $value : null;
	}

	/** Past its time, or a process on this host that no longer exists. Another host's process is never declared dead. */
	private function holderIsGone(string $value): bool {
		$parts = explode(':', $value);
		if (count($parts) < 4) {
			return true;
		}
		[$since, , $host, $pid] = $parts;
		if (time() - (int)$since >= self::HOLD_SECONDS) {
			return true;
		}
		return self::deadHere($host, (int)$pid);
	}

	/** This machine, as a holder names it. */
	public static function machine(): string {
		return self::host();
	}

	/** A process of this machine that no longer exists; another machine's, or this process, is never declared dead. */
	public static function deadHere(string $host, int $pid): bool {
		if ($host === '-' || $host !== self::host() || $pid === getmypid() || !function_exists('posix_kill')) {
			return false;
		}
		// No such process; a process of another user (EPERM) is alive.
		return !posix_kill($pid, 0) && posix_get_last_error() === 3;
	}

	/** The locking cache when every process shares it (Redis, Memcached); false for APCu, which web and cron do not share. */
	private function cache(): IMemcache|false {
		if ($this->cache === null) {
			$cache = $this->cacheFactory->createLocking('formvox_lock');
			$class = substr((string)strrchr('\\' . get_class($cache), '\\'), 1);
			$this->cache = in_array($class, ['Redis', 'Memcached'], true) ? $cache : false;
		}
		return $this->cache;
	}

	/**
	 * This machine and PID namespace, so a pid is only judged where it means something. Without /proc (another system,
	 * a hardened container) it cannot be told, and no holder is declared dead by its pid (review of the 3a fixes).
	 */
	private static function host(): string {
		if (self::$hostId === null) {
			$boot = @file_get_contents('/proc/sys/kernel/random/boot_id');
			$namespace = @readlink('/proc/self/ns/pid');
			self::$hostId = !is_string($boot) || trim($boot) === '' || !is_string($namespace) ? '-'
				: substr(hash('sha256', gethostname() . '|' . trim($boot) . '|' . $namespace), 0, 16);
		}
		return self::$hostId;
	}

	/** @return 'taken'|'held'|'stuck' stuck: the database kept the row locked past its lock wait timeout */
	private function insertRow(string $key, string $value): string {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('preferences')->values([
			'userid' => $qb->createNamedParameter(self::ROW_USER),
			'appid' => $qb->createNamedParameter(Application::APP_ID),
			'configkey' => $qb->createNamedParameter($key),
			'configvalue' => $qb->createNamedParameter($value),
		]);
		try {
			$qb->executeStatement();
			return 'taken';
		} catch (\OCP\DB\Exception $e) {
			// Held; or two writers racing for the row on MySQL/MariaDB, where InnoDB may pick one as a deadlock victim:
			// that one waits like any other (review of the 3a fixes).
			if (in_array($e->getReason(), [\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, \OCP\DB\Exception::REASON_DEADLOCK], true)) {
				return 'held';
			}
			// Only after the database's own wait (50 s on InnoDB, 60 s on SQLite): something is stuck; the form is busy,
			// and the log says why.
			if ($e->getReason() === \OCP\DB\Exception::REASON_LOCK_WAIT_TIMEOUT) {
				$this->logger->warning('FormVox: the database kept the lock row ' . $key . ' locked past its lock wait timeout: a stuck transaction, or deadlock detection off', ['app' => Application::APP_ID, 'exception' => $e]);
				return 'stuck';
			}
			throw $e;
		}
	}

	private function rowValue(string $key): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('configvalue')->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter(self::ROW_USER)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return is_string($value) ? $value : null;
	}

	/** Only the row with this value; LIKE without wildcards compares a text column on every database, Oracle included. */
	private function deleteRow(string $key, string $value): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter(self::ROW_USER)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->like('configvalue', $qb->createNamedParameter($this->db->escapeLikeParameter($value))));
		$qb->executeStatement();
	}

	/**
	 * A fatal error skips finally blocks but not shutdown functions. Our keys are not Nextcloud's, so its releaseAll
	 * leaves them alone, and the release is owner-checked: it never frees a successor's lock.
	 */
	private function releaseHeldOnShutdown(): void {
		if ($this->releaseOnShutdown) {
			return;
		}
		$this->releaseOnShutdown = true;
		register_shutdown_function(function (): void {
			foreach ($this->held as $key => $value) {
				$this->releaseKey($key, $value);
			}
			$this->held = [];
		});
	}
}
