<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Store;

use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Security\FormSecrets;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormLock;
use OCA\FormVox\Service\FormLockManager;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormFolders;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\ResponseStore;
use OCA\FormVox\Store\SecretStore;
use OCA\FormVox\Store\StoredForm;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A tidy that fails is logged, every run again, until it succeeds: the log line must never carry the file it could not
 * tidy, which holds every response and the link token, and an exception's trace would.
 */
class FormConverterTidyTest extends TestCase {
	private const TOKEN = 'tok-public-4f2a9c';

	/** @var list<array{0: string, 1: array}> */
	private array $logged = [];

	/** A converter whose tidy fails as $failure says, on a form whose file is only to be tidied. */
	private function tidy(\Throwable $failure): void {
		$lockManager = $this->createMock(FormLockManager::class);
		$lockManager->method('mutateFormFileWithLock')->willReturnCallback(function () use ($failure): void {
			// As Nextcloud throws from inside a write: the file's bytes are an argument on the stack.
			$this->refuse('{"settings":{"public_token":"' . self::TOKEN . '"},"responses":[{"answers":{"q":"a private answer"}}]}', $failure);
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			});
		}
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->willThrowException(new NotFoundException());
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(7);
		$file->method('getParent')->willReturn($parent);
		$locator = $this->createMock(FormFileLocator::class);
		$locator->method('getFileByIdPublic')->willReturn($file);
		$converter = new FormConverter($this->createMock(FormStore::class), $this->createMock(ResponseStore::class), $this->createMock(SecretStore::class),
			$this->createMock(FormSecrets::class), $this->createMock(FormFolders::class), $this->createMock(FormLock::class), $lockManager, $locator, $logger);

		$converter->settle($file, new StoredForm(1, 'u', 7, 3, 0, '', null, false, true, false, 1700000000));
	}

	private function refuse(string $bytes, \Throwable $failure): void {
		throw $failure;
	}

	public function testAFailedTidyLogsNeitherTheExceptionNorTheFile(): void {
		$this->tidy(new \RuntimeException('Could not write form file (fileId 7): the write failed', 0, new \RuntimeException('file_put_contents failed')));

		$this->assertCount(1, $this->logged);
		[$message, $context] = $this->logged[0];
		$this->assertArrayNotHasKey('exception', $context);
		$this->assertStringContainsString('could not be tidied now: RuntimeException: Could not write form file (fileId 7): the write failed (file_put_contents failed)', $message);
		$this->assertStringNotContainsString(self::TOKEN, $message);
		$this->assertStringNotContainsString('a private answer', $message);
	}

	/** Out of room is expected on a full account and is said in a line, as the next run tries again. */
	public function testAFullStorageIsLoggedInALineWithoutTheException(): void {
		$this->tidy(RequestException::storageFull());

		$this->assertCount(1, $this->logged);
		[$message, $context] = $this->logged[0];
		$this->assertArrayNotHasKey('exception', $context);
		$this->assertStringContainsString('is tidied later', $message);
		$this->assertStringNotContainsString(self::TOKEN, $message);
	}
}
