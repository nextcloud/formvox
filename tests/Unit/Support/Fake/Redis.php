<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Support\Fake;

use OCP\IMemcache;

/**
 * An in-memory stand-in for Nextcloud's Redis cache. Named Redis on purpose: FormLock keeps its key in the locking
 * cache only when every process shares it. Logs what FormLock does with it and can refuse a number of takes.
 */
class Redis implements IMemcache {
	/** @var array<string, mixed> */
	public array $store = [];
	/** @var list<string> */
	public array $log = [];
	/** Takes refused before any succeeds, as when another writer holds the form for a moment. */
	public int $refusals = 0;
	/** @var (\Closure(string, string): bool)|null the calls that throw, by method and key, as when the connection drops */
	public ?\Closure $failing = null;

	private function failIf(string $method, string $key): void {
		if ($this->failing !== null && ($this->failing)($method, $key)) {
			throw new \RuntimeException('Connection lost');
		}
	}

	public function get($key) {
		$this->failIf('get', (string)$key);
		return $this->store[$key] ?? null;
	}

	public function set($key, $value, $ttl = 0) {
		$this->store[$key] = $value;
		return true;
	}

	public function hasKey($key) {
		return array_key_exists($key, $this->store);
	}

	/** Whether it was there, as Redis and APCu report it (Memcached says true either way). */
	public function remove($key) {
		$was = array_key_exists($key, $this->store);
		unset($this->store[$key]);
		return $was;
	}

	public function clear($prefix = '') {
		foreach (array_keys($this->store) as $key) {
			if (str_starts_with($key, $prefix)) {
				unset($this->store[$key]);
			}
		}
		return true;
	}

	public static function isAvailable(): bool {
		return true;
	}

	public function add($key, $value, $ttl = 0) {
		$this->failIf('add', (string)$key);
		if ($this->refusals > 0 || array_key_exists($key, $this->store)) {
			$this->refusals = max(0, $this->refusals - 1);
			$this->log[] = 'refused';
			return false;
		}
		$this->store[$key] = $value;
		$this->log[] = 'acquire';
		return true;
	}

	public function inc($key, $step = 1) {
		$this->store[$key] = (int)($this->store[$key] ?? 0) + $step;
		return $this->store[$key];
	}

	public function dec($key, $step = 1) {
		$this->store[$key] = (int)($this->store[$key] ?? 0) - $step;
		return $this->store[$key];
	}

	public function cas($key, $old, $new) {
		if (($this->store[$key] ?? null) !== $old) {
			return false;
		}
		$this->store[$key] = $new;
		return true;
	}

	public function cad($key, $old) {
		if (($this->store[$key] ?? null) !== $old) {
			return false;
		}
		unset($this->store[$key]);
		$this->log[] = 'release';
		return true;
	}

	public function ncad(string $key, mixed $old): bool {
		if (($this->store[$key] ?? null) === $old) {
			return false;
		}
		unset($this->store[$key]);
		return true;
	}
}
