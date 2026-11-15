<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Service\AccountAccess;
use OCA\FormVox\Service\IntegrationStatus;
use PHPUnit\Framework\TestCase;

class IntegrationStatusTest extends TestCase {
	public function testEachKeyAndWebhookSaysWhetherItsCreatorCanStillUseTheForm(): void {
		$access = $this->createMock(AccountAccess::class);
		$access->expects($this->exactly(3))->method('status')->willReturnCallback(fn (?string $uid) => match ($uid) {
			'anna' => ['status' => AccountAccess::STATUS_OK, 'displayName' => 'Anna'],
			'bob' => ['status' => AccountAccess::STATUS_NO_ACCESS, 'displayName' => 'Bob'],
		});

		$form = (new IntegrationStatus($access))->annotate(5, ['settings' => [
			'api_keys' => [['id' => 'k1', 'created_by' => 'anna'], ['id' => 'k2', 'created_by' => 'bob']],
			'webhooks' => [['id' => 'w1', 'created_by' => 'bob']],
		]]);

		$this->assertSame(['ok', 'no_access'], array_column($form['settings']['api_keys'], 'creator_status'));
		$this->assertSame('Bob', $form['settings']['webhooks'][0]['creator_name']);
	}

	/** A webhook sends the responses out, so it is checked as an export is: its creator must be allowed to download. */
	public function testAWebhookWhoseCreatorMayReadButNotDownloadIsInactive(): void {
		$access = $this->createMock(AccountAccess::class);
		$access->method('status')->willReturnCallback(fn (?string $uid, int $fileId, string $permission) => [
			'status' => $permission === FormPermission::DOWNLOAD ? AccountAccess::STATUS_NO_ACCESS : AccountAccess::STATUS_OK,
			'displayName' => 'Carl',
		]);

		$form = (new IntegrationStatus($access))->annotate(5, ['settings' => [
			'api_keys' => [['id' => 'k1', 'created_by' => 'carl']],
			'webhooks' => [['id' => 'w1', 'created_by' => 'carl']],
		]]);

		$this->assertSame('ok', $form['settings']['api_keys'][0]['creator_status']);
		$this->assertSame('no_access', $form['settings']['webhooks'][0]['creator_status']);
	}

	public function testAFormWithoutIntegrationsIsUnchanged(): void {
		$form = ['title' => 'T', 'settings' => ['allow_multiple' => true]];

		$this->assertSame($form, (new IntegrationStatus($this->createMock(AccountAccess::class)))->annotate(5, $form));
	}
}
