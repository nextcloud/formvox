<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\DAV;

use OCA\FormVox\DAV\FormBusy;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Server;

/**
 * A busy form over WebDAV is 503 with Retry-After, as the API answers it.
 *
 * @group DB
 */
class FormBusyIntegrationTest extends TestCase {
	public function testItIs503WithRetryAfter(): void {
		$busy = new FormBusy('The form is busy, please try again');

		$this->assertSame(503, $busy->getHTTPCode());
		$this->assertSame(['Retry-After' => '2'], $busy->getHTTPHeaders($this->createMock(Server::class)));
	}
}
