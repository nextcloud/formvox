<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\SetupCheck;

use OCA\FormVox\Pdf\PdfMailPolicy;
use OCA\FormVox\SetupCheck\PdfConfirmationCheck;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

class PdfConfirmationCheckTest extends TestCase {
	private function check(bool $shared): PdfConfirmationCheck {
		$policy = $this->createMock(PdfMailPolicy::class);
		$policy->method('sharedCache')->willReturn($shared);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToDocs')->willReturnCallback(fn (string $key) => 'https://docs.example.com/' . $key);
		return new PdfConfirmationCheck($policy, $l, $urls);
	}

	public function testWithASharedCacheAllIsWell(): void {
		$this->assertSame(SetupResult::SUCCESS, $this->check(true)->run()->getSeverity());
	}

	/** As Nextcloud tells about a missing memory cache: a note with the performance docs, not a warning. */
	public function testWithoutOneTheAdministratorIsTold(): void {
		$result = $this->check(false)->run();

		$this->assertSame(SetupResult::INFO, $result->getSeverity());
		$this->assertStringContainsString('Redis or Memcached', (string)$result->getDescription());
		$this->assertSame('https://docs.example.com/admin-performance', $result->getLinkToDoc());
	}

	public function testItIsASystemCheck(): void {
		$this->assertSame('system', $this->check(true)->getCategory());
		$this->assertSame('FormVox PDF for anonymous respondents', $this->check(true)->getName());
	}
}
