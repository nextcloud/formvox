<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\SetupCheck;

use OCA\FormVox\SetupCheck\BackgroundJobsCheck;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

/**
 * Emails, webhooks and notifications about new responses go out from background jobs (7a, 3a-4); without the system
 * cron they can be late, and the admin overview says so.
 */
class BackgroundJobsCheckTest extends TestCase {
	private function check(string $mode): SetupResult {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('core', 'backgroundjobs_mode', 'ajax')->willReturn($mode);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		return (new BackgroundJobsCheck($config, $l))->run();
	}

	public function testWithTheSystemCronAllIsWell(): void {
		$this->assertSame(SetupResult::SUCCESS, $this->check('cron')->getSeverity());
	}

	/** @return array<string, array{string}> */
	public static function otherModes(): array {
		return ['AJAX' => ['ajax'], 'Webcron' => ['webcron']];
	}

	/**
	 * @dataProvider otherModes
	 */
	public function testOtherwiseTheAdminIsWarned(string $mode): void {
		$result = $this->check($mode);

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('Cron', (string)$result->getDescription());
	}
}
