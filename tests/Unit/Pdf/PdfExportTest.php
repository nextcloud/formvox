<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Pdf;

use OCA\FormVox\BackgroundJob\PdfPrepareJob;
use OCA\FormVox\Pdf\PdfExport;
use OCA\FormVox\Pdf\ResponsePdf;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every response's PDF for one ZIP: kept ones are read while it is sent, missing ones are made while the time allows,
 * the rest in the background, and the job for that is asked for once.
 */
class PdfExportTest extends TestCase {
	private const R1 = '11111111111111111111111111111111';
	private const R2 = '22222222222222222222222222222222';
	private const R3 = '33333333333333333333333333333333';

	private ResponsePdf $pdfs;
	private IJobList $jobList;
	private File $form;
	/** @var array<string, string> response id => the kept PDF */
	private array $kept = [];
	/** @var list<string> response ids whose PDF was made */
	private array $made = [];
	/** @var list<string> response ids whose PDF cannot be made */
	private array $failing = [];
	/** How many kept PDFs were opened. */
	private int $opened = 0;
	private string $zone = 'UTC';
	/** Made but not kept: the store fails. */
	private bool $notKept = false;
	/** Seconds each of() takes. */
	private float $slow = 0.0;
	/** @var list<string> response ids deleted while the ZIP was made */
	private array $deleted = [];
	/** @var list<string> response ids whose kept PDF was removed */
	private array $forgotten = [];

	protected function setUp(): void {
		parent::setUp();
		// Entry names are in the server's zone, which Nextcloud keeps at UTC.
		$this->zone = date_default_timezone_get();
		date_default_timezone_set('UTC');
		$this->form = $this->createMock(File::class);
		$this->form->method('getId')->willReturn(7);
		$this->jobList = $this->createMock(IJobList::class);
		$this->pdfs = $this->createMock(ResponsePdf::class);
		$this->pdfs->method('stored')->willReturnCallback(function (File $form, array $response) {
			$pdf = $this->kept[$response['id']] ?? null;
			if ($pdf === null) {
				return null;
			}
			$file = $this->createMock(File::class);
			$file->method('getSize')->willReturn(strlen($pdf));
			$file->method('fopen')->willReturnCallback(function () use ($pdf) {
				$this->opened++;
				$stream = fopen('php://memory', 'w+b');
				fwrite($stream, $pdf);
				rewind($stream);
				return $stream;
			});
			return $file;
		});
		$this->pdfs->method('home')->willReturnArgument(0);
		$this->pdfs->method('forget')->willReturnCallback(function (File $form, string $id): void {
			$this->forgotten[] = $id;
		});
		$this->pdfs->method('fileName')->willReturnCallback(fn (array $form, array $response): string => 'Form_2026-10-27_' . $response['id'] . '.pdf');
		$this->pdfs->method('of')->willReturnCallback(function (File $form, array $f, array $response) {
			usleep((int)($this->slow * 1000000));
			if (in_array($response['id'], $this->failing, true)) {
				throw new \RuntimeException('out of memory');
			}
			$this->made[] = $response['id'];
			if (!$this->notKept) {
				$this->kept[$response['id']] ??= '%PDF-made-' . $response['id'];
			}
			return '%PDF-made-' . $response['id'];
		});
	}

	protected function tearDown(): void {
		date_default_timezone_set($this->zone);
		parent::tearDown();
	}

	private function export(float $budget = 20.0): PdfExport {
		$repository = $this->createMock(\OCA\FormVox\Service\FormRepository::class);
		$repository->method('response')->willReturnCallback(fn (int $fileId, array $form, string $id) => in_array($id, $this->deleted, true) ? null : ['id' => $id]);
		return new PdfExport($this->pdfs, $this->jobList, $this->createMock(LoggerInterface::class), $budget, $repository);
	}

	/** @param list<string> $ids */
	private function formWith(array $ids): array {
		return ['responses' => array_map(fn (string $id) => ['id' => $id, 'submitted_at' => '2026-10-27T09:42:00+00:00'], $ids)];
	}

	/** @param list<array{name: string, stream: resource, size: int, time: int}> $entries */
	private static function contents(array $entries): array {
		$out = [];
		foreach ($entries as $entry) {
			$out[$entry['name']] = [stream_get_contents($entry['stream']), $entry['size']];
		}
		return $out;
	}

	public function testKeptPdfsAndThoseMadeInTimeAllGoIn(): void {
		$this->kept = [self::R1 => '%PDF-kept-' . self::R1];
		$this->jobList->expects($this->never())->method('add');

		$export = $this->export()->entries($this->form, $this->formWith([self::R1, self::R2]));

		$this->assertSame([0, 0], [$export['pending'], $export['failed']]);
		$this->assertSame(0, $this->opened, 'a kept PDF is opened only when the ZIP reads it');
		$this->assertSame([
			'Form_2026-10-27_' . self::R1 . '.pdf' => ['%PDF-kept-' . self::R1, 42],
			'Form_2026-10-27_' . self::R2 . '.pdf' => ['%PDF-made-' . self::R2, 42],
		], self::contents($export['entries']));
		$this->assertSame([self::R2], $this->made, 'a kept one is not made again');
		$this->assertSame(1, $this->opened, 'and closed again once read');
	}

	/** Kept PDFs are found where they are kept, also for someone the form is shared with. */
	public function testKeptPdfsAreLookedForWhereTheyAreKept(): void {
		$home = $this->createMock(File::class);
		$pdfs = $this->createMock(ResponsePdf::class);
		$pdfs->method('home')->with($this->identicalTo($this->form))->willReturn($home);
		$pdfs->expects($this->exactly(2))->method('stored')->with($this->identicalTo($home), $this->anything())->willReturn(null);
		$pdfs->expects($this->once())->method('of')->with($this->identicalTo($home))->willReturn('%PDF-');

		(new PdfExport($pdfs, $this->jobList, $this->createMock(LoggerInterface::class)))->entries($this->form, $this->formWith([self::R1]));
	}

	/** Out of time: kept ones still go in, the rest is counted and made in the background, the job asked for once. */
	public function testWhatCannotBeMadeInTimeIsMadeInTheBackground(): void {
		$this->kept = [self::R2 => '%PDF-kept-' . self::R2];
		$this->jobList->method('has')->with(PdfPrepareJob::class, ['fileId' => 7])->willReturn(false);
		$this->jobList->expects($this->once())->method('add')->with(PdfPrepareJob::class, ['fileId' => 7]);

		$export = $this->export(0.0)->entries($this->form, $this->formWith([self::R1, self::R2, self::R3]));

		$this->assertSame(2, $export['pending']);
		$this->assertSame(['Form_2026-10-27_' . self::R2 . '.pdf'], array_keys(self::contents($export['entries'])));
		$this->assertSame([], $this->made);
	}

	public function testTheJobIsNotAskedForTwice(): void {
		$this->jobList->method('has')->willReturn(true);
		$this->jobList->expects($this->never())->method('add');

		$this->assertSame(1, $this->export(0.0)->entries($this->form, $this->formWith([self::R1]))['pending']);
	}

	/**
	 * Every response has a row, also one without a PDF; each PDF's checksum is of the bytes the ZIP got, taken as it read
	 * them, so the manifest is read last and was the size it said.
	 */
	public function testTheManifestNamesEveryResponseLast(): void {
		$this->kept = [self::R1 => '%PDF-kept-' . self::R1];
		$this->failing = [self::R3];
		$responses = $this->formWith([self::R1, self::R2, self::R3]);
		$responses['responses'][] = ['id' => '=cmd|calc', 'submitted_at' => '2026-10-27T09:42:00+00:00'];
		$this->failing[] = '=cmd|calc';

		$export = $this->export()->entries($this->form, $responses);
		self::contents($export['entries']);
		$manifest = (string)stream_get_contents($export['manifest']['stream']);

		$this->assertSame('manifest.csv', $export['manifest']['name']);
		$this->assertSame(strlen($manifest), $export['manifest']['size']);
		$this->assertSame(implode("\n", [
			'response_id,file,status,sha256,size',
			self::R1 . ',Form_2026-10-27_' . self::R1 . '.pdf,included,' . hash('sha256', '%PDF-kept-' . self::R1) . ',42',
			self::R2 . ',Form_2026-10-27_' . self::R2 . '.pdf,included,' . hash('sha256', '%PDF-made-' . self::R2) . ',42',
			self::R3 . ',,failed,,',
			"'=cmd|calc,,failed,,",
		]) . "\n", $manifest);
	}

	public function testAPdfStillBeingMadeIsPendingInTheManifest(): void {
		$this->jobList->method('has')->willReturn(true);

		$export = $this->export(0.0)->entries($this->form, $this->formWith([self::R1]));

		$this->assertSame("response_id,file,status,sha256,size\n" . self::R1 . ",,pending,,\n", stream_get_contents($export['manifest']['stream']));
	}

	/** A response deleted while the ZIP was made does not keep the PDF made for it; kept ones are not touched. */
	public function testAResponseDeletedMeanwhileLosesThePdfMadeForIt(): void {
		$this->kept = [self::R1 => '%PDF-kept-' . self::R1];
		$this->deleted = [self::R1, self::R2];

		$this->export()->entries($this->form, $this->formWith([self::R1, self::R2, self::R3]));

		$this->assertSame([self::R2], $this->forgotten);
	}

	/** A repeated id is one response: one PDF, one row. */
	public function testARepeatedIdIsOnePdf(): void {
		$export = $this->export()->entries($this->form, $this->formWith([self::R1, self::R1]));

		$this->assertSame(['Form_2026-10-27_' . self::R1 . '.pdf'], array_keys(self::contents($export['entries'])));
		$this->assertSame([self::R1], $this->made);
		$this->assertSame(2, substr_count((string)stream_get_contents($export['manifest']['stream']), "\n"), 'the header and one row');
	}

	/** An id from a file made elsewhere is no file name, and the store keeps no PDF for it: it is not made on every export. */
	public function testAnIdTheStoreRefusesIsNotMade(): void {
		$export = $this->export()->entries($this->form, $this->formWith(['../../evil', self::R1]));

		$this->assertSame([self::R1], $this->made);
		$this->assertSame(['Form_2026-10-27_' . self::R1 . '.pdf'], array_keys(self::contents($export['entries'])));
		$this->assertSame([0, 1], [$export['pending'], $export['failed']]);
	}

	/** One made now that could not be kept: the rest would not be either, so it is not promised as coming. */
	public function testWhatCannotBeKeptIsNotPromised(): void {
		$this->notKept = true;
		$this->slow = 0.05;
		$this->jobList->expects($this->never())->method('add');

		$export = $this->export(0.02)->entries($this->form, $this->formWith([self::R1, self::R2, self::R3]));

		$this->assertSame([self::R1], $this->made);
		$this->assertSame([0, 2], [$export['pending'], $export['failed']]);
	}

	/** One that cannot be made fails the download no more, and is not promised as still being made: it is counted apart. */
	public function testOneThatCannotBeMadeIsCountedApart(): void {
		$this->failing = [self::R1];
		$this->jobList->expects($this->never())->method('add');

		$export = $this->export()->entries($this->form, $this->formWith([self::R1, self::R2]));

		$this->assertSame([0, 1], [$export['pending'], $export['failed']]);
		$this->assertSame(['Form_2026-10-27_' . self::R2 . '.pdf'], array_keys(self::contents($export['entries'])));
	}
}
