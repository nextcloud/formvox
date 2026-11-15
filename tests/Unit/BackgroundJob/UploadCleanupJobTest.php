<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\BackgroundJob;

use OCA\FormVox\BackgroundJob\UploadCleanupJob;
use OCA\FormVox\Service\FormBrokenException;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\ResponseStore;
use OCA\FormVox\Store\StoredForm;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A day after a fill's first upload, its files go unless a stored response names them: files sent without a response
 * cannot fill the owner's storage (review 3a-5). Checked and removed while the form's row is held.
 */
class UploadCleanupJobTest extends TestCase {
	private const GROUP = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';

	/** @var list<int> seconds after which the job asked to run again */
	private array $again = [];
	/** Still in its file: not in the database yet. */
	private bool $inFile = false;
	private ?\Throwable $convertFails = null;
	private ?\Throwable $checkFails = null;
	/** @var list<File> the files moved into the database */
	private array $converted = [];
	/** Whether the form's row is held now. */
	private bool $held = false;
	/** @var list<bool> for each removal, whether the form's row was held */
	private array $removedWhileHeld = [];
	private File $file;
	private StoredForm $stored;
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->file = $this->createMock(File::class);
		$this->stored = new StoredForm(3, 'uuid', 7, 1);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/** @param array<string, true> $groupsInResponses the upload folders the stored responses name */
	private function run_(array $groupsInResponses, UploadService $uploads, array $argument = ['fileId' => 7, 'group' => self::GROUP]): void {
		$locator = $this->createMock(FormFileLocator::class);
		$locator->method('getFileByIdPublicForWrite')->willReturn($this->file);
		$forms = $this->createMock(FormStore::class);
		$forms->method('byFileId')->willReturnCallback(fn () => $this->inFile ? null : $this->stored);
		$converter = $this->createMock(FormConverter::class);
		$converter->method('convert')->willReturnCallback(function (File $file): StoredForm {
			$this->converted[] = $file;
			if ($this->convertFails !== null) {
				throw $this->convertFails;
			}
			$this->inFile = false;
			return $this->stored;
		});
		$responses = $this->createMock(ResponseStore::class);
		$responses->method('whileHeld')->willReturnCallback(function (StoredForm $stored, callable $work): void {
			$this->assertSame($this->stored, $stored);
			$this->held = true;
			try {
				$work();
			} finally {
				$this->held = false;
			}
		});
		$responses->method('uploadGroups')->willReturnCallback(function (int $formId) use ($groupsInResponses): array {
			$this->assertSame([3, true], [$formId, $this->held]);
			if ($this->checkFails !== null) {
				throw $this->checkFails;
			}
			return $groupsInResponses;
		});
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('scheduleAfter')->willReturnCallback(function (string $class, int $runAfter): void {
			$this->again[] = $runAfter - time();
		});
		$job = new UploadCleanupJob($this->createMock(ITimeFactory::class), $locator, $uploads, $jobs, $this->logger, $forms, $responses, $converter);
		(new \ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}

	/** Removes the fill's folder once, from this form's uploads. */
	private function removesOnce(): UploadService {
		$uploads = $this->createMock(UploadService::class);
		$uploads->expects($this->once())->method('deleteResponseUploads')->with($this->file, self::GROUP)->willReturnCallback(function (): void {
			$this->removedWhileHeld[] = $this->held;
		});
		return $uploads;
	}

	private function removesNothing(): UploadService {
		$uploads = $this->createMock(UploadService::class);
		$uploads->expects($this->never())->method('deleteResponseUploads');
		return $uploads;
	}

	/** Removed while the form's row is held: a response stored at that moment keeps its files. */
	public function testFilesNoResponseNamesGo(): void {
		$this->run_(['6f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f' => true], $this->removesOnce());

		$this->assertSame([[true], []], [$this->removedWhileHeld, $this->converted]);
	}

	public function testFilesOfAStoredResponseStay(): void {
		$this->run_([self::GROUP => true], $this->removesNothing());
	}

	/** A form still in its file moves into the database first, its uploads with it, and is checked there. */
	public function testAFormStillInItsFileMovesInFirst(): void {
		$this->inFile = true;

		$this->run_([], $this->removesOnce());

		$this->assertSame([[$this->file], [true]], [$this->converted, $this->removedWhileHeld]);
	}

	/** Another request is moving the form in: checked again in ten minutes. */
	public function testAFormBusyMovingInIsCheckedAgainLater(): void {
		$this->inFile = true;
		$this->convertFails = new FormLockedException('busy');

		$this->run_([], $this->removesNothing());

		$this->assertCount(1, $this->again);
		$this->assertGreaterThanOrEqual(598, $this->again[0]);
		$this->assertLessThanOrEqual(600, $this->again[0]);
	}

	/** Waiting does not mend a file that holds no form: its files stay rather than go unchecked. */
	public function testAFileThatHoldsNoFormKeepsTheFiles(): void {
		$this->inFile = true;
		$this->convertFails = new FormBrokenException();
		$this->logger->expects($this->once())->method('warning');

		$this->run_([], $this->removesNothing());

		$this->assertSame([], $this->again);
	}

	/** @return array<string, array{string}> */
	public static function passingFailures(): array {
		return ['moving it in' => ['convert'], 'checking it' => ['check']];
	}

	/**
	 * The job is gone once it runs: a failure that may pass is checked again in an hour, or the fill's files would stay
	 * for good (review of the 3a fixes).
	 *
	 * @dataProvider passingFailures
	 */
	public function testAFailedCheckComesBackInAnHour(string $when): void {
		$this->inFile = $when === 'convert';
		if ($when === 'convert') {
			$this->convertFails = new \RuntimeException('storage away');
		} else {
			$this->checkFails = new \RuntimeException('Deadlock found when trying to get lock');
		}
		$this->logger->expects($this->once())->method('warning');

		$this->run_([], $this->removesNothing());

		$this->assertCount(1, $this->again);
		$this->assertGreaterThanOrEqual(3598, $this->again[0]);
		$this->assertLessThanOrEqual(3600, $this->again[0]);
	}

	public function testAGroupThatIsNoUploadIdIsLeftAlone(): void {
		$this->run_([], $this->removesNothing(), ['fileId' => 7, 'group' => '../../other']);

		$this->assertSame([], $this->converted);
	}
}
