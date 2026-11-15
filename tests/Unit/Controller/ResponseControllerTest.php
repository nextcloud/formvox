<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\ResponseController;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\RequireFormPermission;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\ResponseService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/** Access and error mapping live in FormAccessMiddleware; these pin what the endpoints do once let through. */
class ResponseControllerTest extends TestCase {
	private FormAccessService $access;
	private ResponseService $responseService;

	protected function setUp(): void {
		parent::setUp();
		$this->access = $this->createMock(FormAccessService::class);
		$this->access->method('getFile')->willReturn($this->createMock(File::class));
		$this->responseService = $this->createMock(ResponseService::class);
	}

	private ?\OCA\FormVox\Pdf\ResponsePdf $pdfs = null;
	private ?\OCA\FormVox\Service\FormRepository $formRepository = null;

	private function controller(): ResponseController {
		return new ResponseController(
			$this->createMock(IRequest::class),
			$this->responseService,
			$this->access,
			$this->formRepository ?? $this->createMock(\OCA\FormVox\Service\FormRepository::class),
			$this->pdfs ?? $this->createMock(\OCA\FormVox\Pdf\ResponsePdf::class),
		);
	}

	// ---- getResponsePdf() --------------------------------------------------

	/** A form that keeps no PDFs gives none, also to a direct request: 409, and nothing is made. */
	public function testThePdfDownloadIsRefusedWhereTheFormKeepsNone(): void {
		$this->formRepository = $this->createMock(\OCA\FormVox\Service\FormRepository::class);
		$this->formRepository->method('loadForm')->willReturn(['title' => 'T', 'settings' => ['confirmationEmailPdf' => true]]);
		$this->pdfs = $this->createMock(\OCA\FormVox\Pdf\ResponsePdf::class);
		$this->pdfs->expects($this->never())->method('download');

		try {
			$this->controller()->getResponsePdf(1, 'r1');
			$this->fail('a PDF was given');
		} catch (\OCA\FormVox\Exception\RequestException $e) {
			$this->assertSame(Http::STATUS_CONFLICT, $e->getStatus());
			$this->assertSame('PDF generation is disabled for this form', $e->getMessage());
		}
	}

	// ---- getResponses() ----------------------------------------------------

	/** Read lets one open the form; the responses need Nextcloud's leave to see a shared file's content. */
	public function testListingResponsesNeedsTheRightToSeeTheContent(): void {
		$attributes = (new \ReflectionMethod(ResponseController::class, 'getResponses'))->getAttributes(RequireFormPermission::class);

		$this->assertSame(FormPermission::VIEW_CONTENT, $attributes[0]->newInstance()->permission);
	}

	public function testGetResponsesReturnsResponsesAndSummary(): void {
		$this->responseService->expects($this->once())->method('getResponses')
			->with(1, '2026-10-05')
			->willReturn([['id' => 'r1']]);
		$this->responseService->method('getSummary')->with(1)->willReturn(['count' => 1]);

		$resp = $this->controller()->getResponses(1, '2026-10-05');
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame([['id' => 'r1']], $data['responses']);
		$this->assertSame(['count' => 1], $data['summary']);
	}

	public function testGetResponsesLetsExceptionsPropagate(): void {
		$this->responseService->method('getResponses')->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('boom');
		$this->controller()->getResponses(1);
	}

	// ---- deleteAllResponses() ----------------------------------------------

	/** The same delete path as one response, so integrations hear about each one (PO). */
	public function testDeleteAllResponsesGoesThroughTheSharedDeletePath(): void {
		$this->responseService->expects($this->once())->method('deleteAllResponses')->with(1, $this->isInstanceOf(File::class));

		$resp = $this->controller()->deleteAllResponses(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	public function testDeleteAllResponsesLetsExceptionsPropagate(): void {
		$this->responseService->method('deleteAllResponses')->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('boom');
		$this->controller()->deleteAllResponses(1);
	}

	// ---- deleteResponse() --------------------------------------------------

	/** One delete path for web and API, so integrations hear about it too (L33). */
	public function testDeleteResponseGoesThroughTheSharedDeletePath(): void {
		$this->responseService->expects($this->once())->method('deleteResponse')->with(1, $this->isInstanceOf(File::class), 'r1');

		$resp = $this->controller()->deleteResponse(1, 'r1');
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	public function testDeleteResponseLetsMissingResponsePropagate(): void {
		$this->responseService->method('deleteResponse')
			->willThrowException(new NotFoundException());

		$this->expectException(NotFoundException::class);
		$this->controller()->deleteResponse(1, 'missing');
	}
}
