<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Pdf;

use OCA\FormVox\BackgroundJob\SideEffectsJob;
use OCA\FormVox\Controller\ExportController;
use OCA\FormVox\Controller\ExternalApiController;
use OCA\FormVox\Controller\IntegrationController;
use OCA\FormVox\Controller\ResponseController;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Pdf\ResponsePdf;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponseOutbox;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\WebhookService;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\OutboxStore;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\IRequest;
use OCP\Server;

/**
 * "Keep a PDF of every response" is a form's own switch, on a real Nextcloud: on, the background job keeps a PDF right
 * after every submission, with or without a webhook or email, but not for an import; off, no PDF is made for a webhook,
 * which goes with pdf null, and the API, the download and the ZIP answer 409.
 *
 * @group DB
 */
class KeepResponsePdfIntegrationTest extends IntegrationTestCase {
	private const IMPORTED = '3f2a9c1e-77b0-4c1d-9e2a-0b1c2d3e4f61';

	private function form(array $settings): File {
		return $this->writeFormFile($this->userFolder, 'Keep PDF ' . uniqid(), [
			'settings' => $settings + ['allow_multiple' => true, 'anonymous' => false, 'public_token' => 'tok', 'notify_owner' => false],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
		]);
	}

	private function submit(int $fileId): array {
		return Server::get(ResponseService::class)->submitAuthenticated($fileId, Server::get(FormRepository::class)->loadPublic($fileId), ['q1' => 'Bob'], 'formvox-int-respondent', 'Bob');
	}

	/** The response as it is stored now, whose revision names its PDF. */
	private function stored(int $fileId, string $responseId): array {
		$repository = Server::get(FormRepository::class);
		$response = $repository->response($fileId, $repository->load($fileId), $responseId);
		$this->assertNotNull($response);
		return $response;
	}

	private function runJob(int $fileId, ?SideEffectsJob $job = null): void {
		$job ??= Server::get(SideEffectsJob::class);
		$job->setArgument(['fileId' => $fileId]);
		$job->start(Server::get(IJobList::class));
	}

	/** @return list<array> */
	private function outbox(int $fileId): array {
		$stored = Server::get(FormStore::class)->byFileId($fileId);
		$this->assertNotNull($stored, 'the form is in the database');
		return Server::get(OutboxStore::class)->entries($stored->id);
	}

	/** Nextcloud's job, with a webhook receiver that records what it is given instead of sending it. */
	private function jobWithWebhooks(array &$pdfBlocks): SideEffectsJob {
		$webhooks = $this->createMock(WebhookService::class);
		$webhooks->method('trigger')->willReturnCallback(function (int $fileId, array $form, string $event, array $data, ?array $only, ?string $eventId, ?array $pdf = null) use (&$pdfBlocks): array {
			$pdfBlocks[] = $pdf;
			return [];
		});
		return new SideEffectsJob(
			Server::get(\OCP\AppFramework\Utility\ITimeFactory::class),
			Server::get(\OCA\FormVox\Service\FormFileLocator::class),
			Server::get(\OCA\FormVox\Service\FormLock::class),
			Server::get(ResponseOutbox::class),
			$webhooks,
			Server::get(\OCA\FormVox\Service\ResponseNotificationService::class),
			Server::get(\OCA\FormVox\Service\ConfirmationMail::class),
			Server::get(ResponsePdf::class),
			Server::get(\OCP\IURLGenerator::class),
			Server::get(\OCP\ICacheFactory::class),
			Server::get(\Psr\Log\LoggerInterface::class),
			Server::get(FormStore::class),
			Server::get(OutboxStore::class),
			Server::get(\OCA\FormVox\Store\ResponseStore::class),
			Server::get(\OCA\FormVox\Service\FormReader::class),
			Server::get(\OCA\FormVox\Store\FormConverter::class),
		);
	}

	/** The external API as a client calls it: no session, the key in the header. */
	private function api(string $key): ExternalApiController {
		Server::get(\OCP\IUserSession::class)->setUser(null);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name) => $name === 'X-FormVox-API-Key' ? $key : '');
		return new ExternalApiController(
			$request,
			Server::get(FormRepository::class),
			Server::get(ResponseService::class),
			Server::get(\OCA\FormVox\Service\ApiKeyService::class),
			Server::get(\OCA\FormVox\Service\AccountAccess::class),
			Server::get(\OCA\FormVox\Security\FormSanitizer::class),
			Server::get(\OCP\L10N\IFactory::class)->get('formvox'),
			Server::get(ResponsePdf::class),
		);
	}

	public function testSwitchedOnTheJobKeepsAPdfRightAfterASubmissionWithoutWebhookOrEmail(): void {
		$file = $this->form(['keepResponsePdf' => true]);
		$fileId = $file->getId();
		$response = $this->submit($fileId);
		$this->assertSame([[ResponseOutbox::PDF]], array_column($this->outbox($fileId), 'todo'), 'the PDF alone waits');

		$this->runJob($fileId);

		$this->assertSame([], $this->outbox($fileId), 'crossed off');
		$this->assertInstanceOf(File::class, Server::get(ResponsePdf::class)->stored($file, $this->stored($fileId, $response['id'])));
	}

	public function testSwitchedOffAWebhookGoesWithPdfNullAndNothingIsStored(): void {
		$file = $this->form(['webhooks' => [['id' => 'wh1', 'url' => 'http://127.0.0.1:9/hook', 'secret' => 'whsec', 'created_by' => $this->ownerUid, 'enabled' => true]]]);
		$fileId = $file->getId();
		$response = $this->submit($fileId);
		$this->assertSame([[ResponseOutbox::WEBHOOKS]], array_column($this->outbox($fileId), 'todo'), 'no PDF waits');
		$pdfBlocks = [];

		$this->runJob($fileId, $this->jobWithWebhooks($pdfBlocks));

		$this->assertSame([null], $pdfBlocks);
		$this->assertSame([], $this->outbox($fileId), 'sent at once, not held for a PDF');
		$this->assertNull(Server::get(ResponsePdf::class)->stored($file, $this->stored($fileId, $response['id'])));
	}

	public function testAnImportGetsNoPdfOnAFormThatKeepsThem(): void {
		$file = $this->form(['keepResponsePdf' => true]);
		$fileId = $file->getId();

		$imported = Server::get(ResponsePersistenceService::class)->appendImported($fileId, [
			'id' => self::IMPORTED, 'submitted_at' => date('c'), 'respondent' => ['type' => 'anonymous'], 'answers' => ['q1' => 'Old'],
		]);
		$this->runJob($fileId);

		$this->assertSame([], $this->outbox($fileId), 'nothing was set off');
		$this->assertNull(Server::get(ResponsePdf::class)->stored($file, $this->stored($fileId, $imported['id'])));
	}

	/** Every way to a PDF answers the same 409, so a direct request renders nothing either. */
	public function testSwitchedOffEveryWayToAPdfAnswers409(): void {
		$file = $this->form([]);
		$fileId = $file->getId();
		$response = $this->submit($fileId);
		$key = Server::get(IntegrationController::class)->createApiKey($fileId, 'IT ' . uniqid(), ['read_form', 'read_responses'])->getData()['key'];

		$refused = [];
		foreach ([
			'api' => fn () => $this->api($key)->getResponsePdf($fileId, $response['id']),
			'download' => fn () => Server::get(ResponseController::class)->getResponsePdf($fileId, $response['id']),
			'zip' => fn () => Server::get(ExportController::class)->exportPdfs($fileId),
		] as $way => $ask) {
			$this->loginAs($this->ownerUid);
			try {
				$ask();
				$this->fail("$way gave a PDF of a form that keeps none");
			} catch (RequestException $e) {
				$refused[$way] = [$e->getStatus(), $e->getReason(), $e->getMessage()];
			}
		}

		$this->assertSame(array_fill_keys(['api', 'download', 'zip'], [Http::STATUS_CONFLICT, 'pdf_disabled', 'PDF generation is disabled for this form']), $refused);
		$this->assertNull(Server::get(ResponsePdf::class)->stored($file, $this->stored($fileId, $response['id'])));
	}
}
