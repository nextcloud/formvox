<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\BackgroundJob;

use OCA\FormVox\BackgroundJob\PdfPrepareJob;
use OCA\FormVox\Pdf\ResponsePdf;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Makes the PDFs a ZIP download had no time for, a run at a time, and comes back only while it keeps some. */
class PdfPrepareJobTest extends TestCase {
	private const R1 = '11111111111111111111111111111111';
	private const R2 = '22222222222222222222222222222222';
	private const R3 = '33333333333333333333333333333333';

	private FormFileLocator $locator;
	private FormRepository $repository;
	private IJobList $jobList;
	private array $form = [];
	/** @var array<string, true> response ids with a kept PDF */
	private array $kept = [];
	/** @var list<string> response ids passed to of() */
	private array $made = [];
	/** @var list<string> */
	private array $failing = [];
	/** Made but not kept: the store refuses it. */
	private bool $notKept = false;
	/** Seconds each of() takes. */
	private float $slow = 0.0;
	/** Seconds each look for a kept PDF takes. */
	private float $slowToFind = 0.0;
	/** @var list<string> response ids deleted while the job ran */
	private array $deleted = [];
	/** @var list<string> response ids whose PDF was removed */
	private array $forgotten = [];

	protected function setUp(): void {
		parent::setUp();
		$this->locator = $this->createMock(FormFileLocator::class);
		$this->repository = $this->createMock(FormRepository::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->locator->method('getFileByIdPublicForWrite')->willReturn($this->createMock(File::class));
		$this->repository->method('readForm')->willReturnCallback(fn () => $this->form);
		$this->repository->method('eachResponse')->willReturnCallback(fn () => $this->form['responses'] ?? []);
		$this->repository->method('response')->willReturnCallback(fn (int $fileId, array $form, string $id) => in_array($id, $this->deleted, true) ? null : ['id' => $id]);
	}

	private function pdfs(): ResponsePdf {
		$pdfs = $this->createMock(ResponsePdf::class);
		$pdfs->method('stored')->willReturnCallback(function (File $f, array $response) {
			usleep((int)($this->slowToFind * 1000000));
			return isset($this->kept[$response['id']]) ? $this->createMock(File::class) : null;
		});
		$pdfs->method('of')->willReturnCallback(function (File $f, array $form, array $response) {
			$this->made[] = $response['id'];
			usleep((int)($this->slow * 1000000));
			if (in_array($response['id'], $this->failing, true)) {
				throw new \RuntimeException('cannot render');
			}
			if (!$this->notKept) {
				$this->kept[$response['id']] = true;
			}
			return '%PDF-';
		});
		$pdfs->method('forget')->willReturnCallback(function (File $f, string $id): void {
			$this->forgotten[] = $id;
		});
		return $pdfs;
	}

	private function runJob(float $seconds = 60.0): void {
		$job = new PdfPrepareJob($this->createMock(ITimeFactory::class), $this->locator, $this->repository, $this->pdfs(), $this->jobList, $this->createMock(LoggerInterface::class), $seconds);
		(new \ReflectionMethod($job, 'run'))->invoke($job, ['fileId' => 7]);
	}

	/** @param list<string> $ids */
	private function responses(array $ids): void {
		$this->form = ['settings' => ['keepResponsePdf' => true], 'responses' => array_map(fn (string $id) => ['id' => $id], $ids)];
	}

	/** Switched off since the ZIP asked for them: nothing is made, and the job does not come back. */
	public function testAFormThatKeepsNoPdfIsLeftAlone(): void {
		$this->responses([self::R1, self::R2]);
		$this->form['settings'] = [];
		$this->jobList->expects($this->never())->method('scheduleAfter');

		$this->runJob();

		$this->assertSame([], $this->made);
	}

	public function testItMakesEveryMissingPdfAndNoKeptOneAgain(): void {
		$this->responses([self::R1, self::R2, self::R3]);
		$this->kept = [self::R2 => true];
		$this->jobList->expects($this->never())->method('scheduleAfter');

		$this->runJob();

		$this->assertSame([self::R1, self::R3], $this->made);
		$this->assertEqualsCanonicalizing([self::R1, self::R2, self::R3], array_keys($this->kept));
	}

	public function testOutOfTimeItComesBackForTheRest(): void {
		$this->responses([self::R1, self::R2]);
		$this->slow = 0.02;
		$this->jobList->expects($this->once())->method('scheduleAfter')->with(PdfPrepareJob::class, $this->greaterThan(time()), ['fileId' => 7]);

		$this->runJob(0.01);

		$this->assertSame([self::R1], $this->made);
	}

	/** PDFs that fail, slowly, do not bring it back for good. */
	public function testFailuresDoNotBringItBack(): void {
		$this->responses([self::R1, self::R2, self::R3]);
		$this->failing = [self::R1, self::R2, self::R3];
		$this->slow = 0.02;
		$this->jobList->expects($this->never())->method('scheduleAfter');

		$this->jobList->expects($this->never())->method('add');

		$this->runJob(0.03);

		$this->assertNotSame([], $this->made, 'it tried, then ran out of time');
		$this->assertSame([], $this->kept);
	}

	/** Made is not kept: a full quota or a name the store refuses would have it make the same PDFs again for good. */
	public function testPdfsItCannotKeepDoNotBringItBack(): void {
		$this->responses([self::R1, self::R2, self::R3]);
		$this->notKept = true;
		$this->slow = 0.02;
		$this->jobList->expects($this->never())->method('scheduleAfter');
		$this->jobList->expects($this->never())->method('add');

		$this->runJob(0.03);

		$this->assertNotSame([], $this->made);
	}

	/** Finding the kept ones takes none of its time: on a form with many, it still makes the missing ones. */
	public function testItsTimeCountsFromTheFirstPdfItMakes(): void {
		$this->responses([self::R1, self::R2, self::R3]);
		$this->kept = [self::R1 => true, self::R2 => true];
		$this->slowToFind = 0.03;

		$this->runJob(0.05);

		$this->assertSame([self::R3], $this->made);
	}

	/** A response deleted while it ran does not keep the PDF made for it, also when its time ran out. */
	public function testAResponseDeletedMeanwhileLosesThePdfMadeForIt(): void {
		$this->responses([self::R1, self::R2, self::R3]);
		$this->deleted = [self::R1];

		$this->runJob();
		$this->assertSame([self::R1], $this->forgotten);

		$this->forgotten = [];
		$this->kept = [];
		$this->made = [];
		$this->slow = 0.02;
		$this->runJob(0.01);
		$this->assertSame([self::R1], $this->made, 'out of time after the first');
		$this->assertSame([self::R1], $this->forgotten);
	}

	/** A repeated id is made once, and an id the store refuses not at all: neither brings it back for good. */
	public function testRepeatedAndRefusedIdsAreLeft(): void {
		$this->responses([self::R1, self::R1, '../../evil', self::R2]);
		// Twins of other answers have other revisions: each would push out the other's PDF.
		$this->notKept = true;

		$this->runJob();

		$this->assertSame([self::R1, self::R2], $this->made);
	}

	public function testABusyFormIsTriedAgainInAMinute(): void {
		$this->repository = $this->createMock(FormRepository::class);
		$this->repository->method('readForm')->willThrowException(new FormLockedException('busy'));
		$this->jobList->expects($this->once())->method('scheduleAfter')->with(PdfPrepareJob::class, $this->greaterThan(time() + 50), ['fileId' => 7]);

		$this->runJob();
	}

	public function testAFormThatIsGoneEndsIt(): void {
		$this->locator = $this->createMock(FormFileLocator::class);
		$this->locator->method('getFileByIdPublicForWrite')->willThrowException(new NotFoundException());
		$this->jobList->expects($this->never())->method('add');
		$this->jobList->expects($this->never())->method('scheduleAfter');

		$this->runJob();
	}
}
