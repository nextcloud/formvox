<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Command;

use OCA\FormVox\Command\Unlock;
use OCA\FormVox\Service\FormLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** `occ formvox:unlock` frees a form whose writer was killed, its outbox sender too (review 3a-2). */
class UnlockTest extends TestCase {
	public function testItFreesTheFormAndItsSender(): void {
		$lock = $this->createMock(FormLock::class);
		$lock->expects($this->exactly(2))->method('unlock')->willReturnCallback(fn (int $fileId, string $purpose) => $fileId === 7 && $purpose === 'form');
		$tester = new CommandTester(new Unlock($lock));

		$tester->execute(['fileId' => '7']);

		$this->assertStringContainsString('Form 7 is free again.', $tester->getDisplay());
	}

	/** It says the form is free only once it is: a release that failed is no success (review of the 3a fixes). */
	public function testItSaysWhenTheFormStaysLocked(): void {
		$lock = $this->createMock(FormLock::class);
		$lock->method('unlock')->willReturn(true);
		$lock->method('isHeld')->willReturnCallback(fn (int $fileId, string $purpose) => $purpose === 'form');
		$tester = new CommandTester(new Unlock($lock));

		$this->assertSame(1, $tester->execute(['fileId' => '7']));
		$this->assertStringContainsString('Form 7 is still locked', $tester->getDisplay());
	}

	public function testItSaysWhenNothingWasLocked(): void {
		$lock = $this->createMock(FormLock::class);
		$lock->method('unlock')->willReturn(false);
		$tester = new CommandTester(new Unlock($lock));

		$tester->execute(['fileId' => '7']);

		$this->assertStringContainsString('Form 7 was not locked.', $tester->getDisplay());
	}
}
