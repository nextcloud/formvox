<?php

declare(strict_types=1);

namespace OCA\FormVox\Pdf;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\BackgroundJob\PdfPrepareJob;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\UploadService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Every response's PDF for one ZIP. Kept PDFs are read only while the ZIP is sent; one still to be made is made now
 * while the time allows, the rest in the background, so a form with thousands of older responses never runs into a
 * proxy's timeout or the memory limit.
 */
class PdfExport {
	public function __construct(
		private ResponsePdf $pdfs,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private float $budgetSeconds = 20.0,
		private ?FormRepository $repository = null,
	) {
	}

	/**
	 * The entries for the ZIP; how many PDFs are still to be made, and how many could not be made; and manifest.csv, to
	 * go last: it gives each PDF's checksum, taken as the ZIP reads the PDFs.
	 *
	 * @param iterable<array>|null $responses read a page at a time; the form's own when null
	 * @return array{entries: list<array{name: string, stream: resource, size: int, time: int}>, pending: int, failed: int, manifest: array{name: string, stream: resource, size: int, time: int}}
	 */
	public function entries(File $formFile, array $form, ?iterable $responses = null): array {
		$home = $this->pdfs->home($formFile);
		$entries = [];
		$pending = 0;
		$failed = 0;
		/** @var list<array{0: string, 1: string, 2: string, 3: \HashContext|string, 4: string}> $rows */
		$rows = [];
		$seen = [];
		$made = [];
		// Once one made here could not be kept, the rest would not be either: they are not promised as coming.
		$unkept = false;
		$started = microtime(true);
		foreach ($responses ?? (is_array($form['responses'] ?? null) ? $form['responses'] : []) as $response) {
			if (!is_array($response) || !is_scalar($response['id'] ?? null) || isset($seen[(string)$response['id']])) {
				continue;
			}
			$id = (string)$response['id'];
			$seen[$id] = true;
			if (!UploadService::isValidResponseId($id)) {
				// From a file made elsewhere: the store keeps no PDF for it, which would be made on every export.
				$failed++;
				$rows[] = [$id, '', 'failed', '', ''];
				continue;
			}
			$when = strtotime((string)($response['submitted_at'] ?? '')) ?: time();
			$name = $this->pdfs->fileName($form, $response);
			$stored = $this->pdfs->stored($home, $response);
			if ($stored !== null) {
				$size = (int)$stored->getSize();
				$hash = hash_init('sha256');
				$entries[] = ['name' => $name, 'size' => $size, 'time' => $when, 'stream' => LazyStream::open(
					fn () => $stored->fopen('r'),
					$size,
					fn (int $missing) => $this->logger->warning('FormVox: the kept PDF ' . $name . ' of form ' . $formFile->getId() . ' changed or went during its download; ' . $missing . ' bytes of it are zeros', ['app' => Application::APP_ID]),
					$hash,
				)];
				$rows[] = [$id, $name, 'included', $hash, (string)$size];
				continue;
			}
			if (microtime(true) - $started >= $this->budgetSeconds) {
				if ($unkept) {
					$failed++;
					$rows[] = [$id, '', 'failed', '', ''];
				} else {
					$pending++;
					$rows[] = [$id, '', 'pending', '', ''];
				}
				continue;
			}
			try {
				$pdf = $this->pdfs->of($home, $form, $response);
			} catch (\Throwable $e) {
				$this->logger->warning('FormVox: the PDF of response ' . $response['id'] . ' of form ' . $formFile->getId() . ' could not be made: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
				$failed++;
				$rows[] = [$id, '', 'failed', '', ''];
				continue;
			}
			$made[] = $id;
			$unkept = $unkept || $this->pdfs->stored($home, $response) === null;
			$stream = self::temp($pdf);
			if ($stream === null) {
				$failed++;
				$rows[] = [$id, '', 'failed', '', ''];
				continue;
			}
			$entries[] = ['name' => $name, 'stream' => $stream, 'size' => strlen($pdf), 'time' => $when];
			$rows[] = [$id, $name, 'included', hash('sha256', $pdf), (string)strlen($pdf)];
		}
		if ($pending > 0) {
			$this->prepareLater((int)$formFile->getId());
		}
		$this->forgetGone($formFile, $form, $made);
		return ['entries' => $entries, 'pending' => $pending, 'failed' => $failed, 'manifest' => self::manifest($rows)];
	}

	/**
	 * One row for every response, also those without a PDF. Its size is known now: a checksum always has 64 characters;
	 * its text only when the ZIP reads it, after the PDFs whose checksums it gives.
	 *
	 * @param list<array{0: string, 1: string, 2: string, 3: \HashContext|string, 4: string}> $rows
	 * @return array{name: string, stream: resource, size: int, time: int}
	 */
	private static function manifest(array $rows): array {
		$csv = static function (bool $done) use ($rows): string {
			$lines = [['response_id', 'file', 'status', 'sha256', 'size']];
			foreach ($rows as [$id, $file, $status, $hash, $size]) {
				// As the CSV export: an id from a file made elsewhere is never a formula.
				$id = in_array($id[0] ?? '', ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $id : $id;
				$sha = $hash instanceof \HashContext ? ($done ? hash_final($hash) : str_repeat('0', 64)) : $hash;
				$lines[] = [$id, $file, $status, $sha, $size];
			}
			$out = fopen('php://temp', 'w+b');
			if ($out === false) {
				throw new \RuntimeException('No temporary stream for manifest.csv');
			}
			foreach ($lines as $line) {
				fputcsv($out, $line, ',', '"', '');
			}
			rewind($out);
			return (string)stream_get_contents($out);
		};
		$size = strlen($csv(false));
		return ['name' => 'manifest.csv', 'size' => $size, 'time' => time(), 'stream' => LazyStream::open(fn () => self::temp($csv(true)) ?? false, $size)];
	}

	/** @return resource|null */
	private static function temp(string $content) {
		$stream = fopen('php://temp', 'w+b');
		if ($stream === false) {
			return null;
		}
		fwrite($stream, $content);
		rewind($stream);
		return $stream;
	}

	/**
	 * A response deleted while this ran does not keep the PDF made for it.
	 *
	 * @param list<string> $made
	 */
	private function forgetGone(File $formFile, array $form, array $made): void {
		foreach ($made as $id) {
			if ($this->repository !== null && $this->repository->response((int)$formFile->getId(), $form, $id) === null) {
				$this->pdfs->forget($formFile, $id);
			}
		}
	}

	private function prepareLater(int $fileId): void {
		$argument = ['fileId' => $fileId];
		if (!$this->jobList->has(PdfPrepareJob::class, $argument)) {
			$this->jobList->add(PdfPrepareJob::class, $argument);
		}
	}
}
