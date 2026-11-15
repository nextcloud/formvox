<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Pdf;

use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\OutboxStore;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\IRequest;
use OCP\Server;

/**
 * A signed-in respondent is written to at their account's address, from the background job: the account travels with
 * what the submission sets off, through the database, and never into the response of an anonymous form.
 *
 * @group DB
 */
class ConfirmationAccountIntegrationTest extends IntegrationTestCase {
	public function testTheSignedInRespondentsAccountTravelsWithTheOutboxEntryOnly(): void {
		$file = $this->writeFormFile($this->userFolder, 'Account ' . uniqid(), [
			'settings' => ['allow_multiple' => true, 'anonymous' => true, 'public_token' => 'tok', 'sendConfirmationEmail' => true, 'notify_owner' => false],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Email', 'useAsRespondentEmail' => true]],
		]);
		$fileId = $file->getId();
		$repository = Server::get(FormRepository::class);

		$response = Server::get(ResponseService::class)->submitAnonymousWithForm($fileId, $repository->loadPublic($fileId), ['q1' => 'typed@example.org'], Server::get(IRequest::class), null, null, null, 'formvox-int-respondent');

		$stored = Server::get(FormStore::class)->byFileId($fileId);
		$this->assertNotNull($stored, 'the form is in the database');
		$entries = Server::get(OutboxStore::class)->entries($stored->id);
		$this->assertCount(1, $entries);
		$this->assertTrue($entries[0]['signedIn'] ?? false);
		$this->assertSame('formvox-int-respondent', $entries[0]['account'] ?? null, 'the account is with the entry');
		$this->assertStringNotContainsString('formvox-int-respondent', json_encode($repository->load($fileId)['responses'][0]), 'and not in the response');
		$this->assertSame('anonymous', $response['respondent']['type']);
	}
}
