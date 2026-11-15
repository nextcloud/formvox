<?php

declare(strict_types=1);

namespace OCA\FormVox\Pdf;

/**
 * A stream that opens what it reads only on the first read and closes it at its end: a ZIP of thousands of stored PDFs
 * takes all its streams up front, and would otherwise hold a file handle for each. It always gives exactly the size
 * the archive was told, as a tar needs: a file gone or changed meanwhile is padded with zeros or cut, and reported.
 */
final class LazyStream {
	private const SCHEME = 'formvox-lazy';

	/** @var array<string, array{\Closure(): mixed, int, ?\Closure(int): void, ?\HashContext}> */
	private static array $streams = [];
	private static int $next = 0;

	/** @var resource|null set by PHP for every stream wrapper */
	public $context;
	/** @var \Closure(): mixed|null */
	private ?\Closure $opener = null;
	/** @var \Closure(int): void|null */
	private ?\Closure $short = null;
	/** @var resource|null */
	private $inner = null;
	private ?\HashContext $hash = null;
	private int $left = 0;
	private bool $sourceDone = false;

	/**
	 * @param \Closure(): mixed $open gives the resource to read, or false
	 * @param int $size the bytes the stream gives, whatever the source holds
	 * @param \Closure(int): void|null $short told how many bytes the source lacked
	 * @param \HashContext|null $hash takes every byte the stream gives
	 * @return resource
	 */
	public static function open(\Closure $open, int $size, ?\Closure $short = null, ?\HashContext $hash = null) {
		if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
			stream_wrapper_register(self::SCHEME, self::class);
		}
		$key = (string)++self::$next;
		self::$streams[$key] = [$open, $size, $short, $hash];
		$stream = fopen(self::SCHEME . '://' . $key, 'rb');
		if ($stream === false) {
			unset(self::$streams[$key]);
			throw new \RuntimeException('Could not open a lazy stream');
		}
		return $stream;
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$key = substr($path, strlen(self::SCHEME) + 3);
		if (!isset(self::$streams[$key])) {
			return false;
		}
		[$this->opener, $this->left, $this->short, $this->hash] = self::$streams[$key];
		unset(self::$streams[$key]);
		return true;
	}

	public function stream_read(int $count): string {
		$count = min($count, $this->left);
		if ($count <= 0) {
			$this->closeSource();
			return '';
		}
		$data = $this->fromSource($count);
		if ($data === '') {
			// The source ended or failed early: zeros keep the size the archive was told.
			if ($this->short !== null) {
				($this->short)($this->left);
				$this->short = null;
			}
			$data = str_repeat("\0", $count);
		}
		$this->left -= strlen($data);
		if ($this->hash !== null) {
			hash_update($this->hash, $data);
		}
		if ($this->left === 0) {
			$this->closeSource();
		}
		return $data;
	}

	public function stream_eof(): bool {
		return $this->left <= 0;
	}

	public function stream_close(): void {
		$this->closeSource();
		$this->opener = null;
		$this->short = null;
		$this->hash = null;
	}

	/** @return array<int|string, int> */
	public function stream_stat(): array {
		return [];
	}

	private function fromSource(int $count): string {
		if ($this->sourceDone) {
			return '';
		}
		if ($this->inner === null) {
			try {
				$inner = $this->opener === null ? false : ($this->opener)();
			} catch (\Throwable) {
				$inner = false;
			}
			$this->opener = null;
			if (!is_resource($inner)) {
				$this->sourceDone = true;
				return '';
			}
			$this->inner = $inner;
		}
		try {
			$data = fread($this->inner, $count);
		} catch (\Throwable) {
			$data = false;
		}
		if ($data === false || $data === '') {
			$this->closeSource();
			return '';
		}
		return $data;
	}

	private function closeSource(): void {
		$inner = $this->inner;
		$this->inner = null;
		$this->sourceDone = true;
		if (is_resource($inner)) {
			fclose($inner);
		}
	}
}
