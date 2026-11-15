<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\BrandingController;
use OCA\FormVox\Middleware\ExceptionMiddleware;
use OCA\FormVox\Security\FormNotFoundException;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * BrandingController: an image is what its content is, never SVG (secu#54); a form's own image is shown with
 * its link's token or to whoever may open the form (qual#87); an older SVG is never opened as a page.
 */
class BrandingControllerTest extends TestCase {
	private IRequest $request;
	private BrandingService $brandingService;
	private FormAccessService $access;
	private FormRepository $formRepository;
	private IMimeTypeDetector $mimeTypeDetector;
	private IUserSession $userSession;
	private File $formFile;
	private string $detected = 'image/png';

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->brandingService = $this->createMock(BrandingService::class);
		$this->access = $this->createMock(FormAccessService::class);
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->mimeTypeDetector = $this->createMock(IMimeTypeDetector::class);
		$this->mimeTypeDetector->method('detectContent')->willReturnCallback(fn () => $this->detected);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->formFile = $this->createMock(File::class);
		$this->access->method('getFile')->with(7)->willReturn($this->formFile);
	}

	private function controller(): BrandingController {
		return new BrandingController($this->request, $this->brandingService, $this->access, $this->formRepository, $this->mimeTypeDetector, $this->userSession);
	}

	/** Call an endpoint as Nextcloud's dispatcher does: what it throws is answered by ExceptionMiddleware. */
	private function respond(string $method, mixed ...$args): Response {
		$controller = $this->controller();
		try {
			return $controller->$method(...$args);
		} catch (\Exception $e) {
			return (new ExceptionMiddleware($this->request, $this->createMock(IConfig::class), new EnglishL10N(), $this->createMock(LoggerInterface::class)))->afterException($controller, $method, $e);
		}
	}

	/** An uploaded file, whatever type the browser claims for it. */
	private function upload(string $claimed = 'image/png', int $size = 1024): array {
		return ['name' => 'logo.png', 'type' => $claimed, 'tmp_name' => '/tmp/phpupload', 'error' => UPLOAD_ERR_OK, 'size' => $size];
	}

	// ---- get() -------------------------------------------------------------

	public function testGetReturnsBranding(): void {
		$this->brandingService->method('getBranding')->willReturn(['layout' => ['blocks' => []]]);
		$resp = $this->controller()->get();
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['layout' => ['blocks' => []]], $resp->getData());
	}

	// ---- saveLayout() ------------------------------------------------------

	public function testSaveLayoutReturnsSavedBranding(): void {
		$layout = ['blocks' => [['id' => 'b1']]];
		$this->brandingService->expects($this->once())->method('saveLayout')
			->with($layout)->willReturn(['layout' => $layout]);
		$resp = $this->controller()->saveLayout($layout);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['layout' => $layout], $resp->getData());
	}

	// ---- saveStyles() ------------------------------------------------------

	public function testSaveStylesReturnsSavedBranding(): void {
		$styles = ['color' => '#fff'];
		$this->brandingService->expects($this->once())->method('saveGlobalStyles')
			->with($styles)->willReturn(['globalStyles' => $styles]);
		$resp = $this->controller()->saveStyles($styles);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['globalStyles' => $styles], $resp->getData());
	}

	// ---- uploads: one check for both ----------------------------------------

	/**
	 * @return array<string, array{string, list<mixed>, string}>
	 */
	public static function uploads(): array {
		return [
			'the default branding' => ['uploadBlockImage', ['b1'], 'saveBlockImage'],
			"a form's branding" => ['uploadFormBlockImage', [7, 'b1'], 'saveFormBlockImage'],
		];
	}

	/**
	 * @dataProvider uploads
	 */
	public function testNoFileIsRefused(string $endpoint, array $args, string $save): void {
		$this->request->method('getUploadedFile')->willReturn(null);
		$this->brandingService->expects($this->never())->method($save);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->respond($endpoint, ...$args)->getStatus());
	}

	/**
	 * secu#54: an SVG can carry script, whatever the browser calls it.
	 *
	 * @dataProvider uploads
	 */
	public function testAnSvgIsRefusedEvenWhenTheBrowserCallsItAPng(string $endpoint, array $args, string $save): void {
		$this->request->method('getUploadedFile')->willReturn($this->upload('image/png'));
		$this->detected = 'image/svg+xml';
		$this->brandingService->expects($this->never())->method($save);

		$resp = $this->respond($endpoint, ...$args);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame('Use a PNG, JPEG, GIF or WebP image', $resp->getData()['error']);
	}

	/**
	 * @dataProvider uploads
	 */
	public function testAnImageIsStoredAsWhatItIsNotWhatTheBrowserSays(string $endpoint, array $args, string $save): void {
		$this->request->method('getUploadedFile')->willReturn($this->upload('application/octet-stream'));
		$this->detected = 'image/webp';
		$this->brandingService->expects($this->once())->method($save)
			->with(...($save === 'saveBlockImage' ? ['b1', '/tmp/phpupload', 'image/webp'] : [$this->identicalTo($this->formFile), 'b1', '/tmp/phpupload', 'image/webp']))
			->willReturn('img-1');

		$this->assertSame(['imageId' => 'img-1'], $this->respond($endpoint, ...$args)->getData());
	}

	/**
	 * @dataProvider uploads
	 */
	public function testAnImageOverTwoMegabytesIsRefused(string $endpoint, array $args, string $save): void {
		$this->request->method('getUploadedFile')->willReturn($this->upload('image/png', 2 * 1024 * 1024 + 1));
		$this->brandingService->expects($this->never())->method($save);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->respond($endpoint, ...$args)->getStatus());
	}

	/** It names the stored file, so only a plain name. */
	public function testABlockIdThatIsNoPlainNameIsNotFound(): void {
		$this->brandingService->expects($this->never())->method('getBlockImage');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->respond('blockImage', '../x')->getStatus());
	}

	// ---- deleteFormBlockImage() --------------------------------------------

	public function testDeleteFormBlockImageDeletesFromTheFormFile(): void {
		$this->brandingService->expects($this->once())->method('deleteFormBlockImage')
			->with($this->identicalTo($this->formFile), 'b1');
		$resp = $this->controller()->deleteFormBlockImage(7, 'b1');
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	// ---- serving -----------------------------------------------------------

	public function testTheDefaultBrandingImageIsServedSafely(): void {
		$this->brandingService->method('getBlockImage')->with('b1')->willReturn(['content' => 'BYTES', 'mimeType' => 'image/png']);

		$resp = $this->controller()->blockImage('b1');

		$this->assertSame('image/png', $resp->getHeaders()['Content-Type'] ?? null);
		$this->assertSame('nosniff', $resp->getHeaders()['X-Content-Type-Options'] ?? null);
		$this->assertStringStartsWith('inline', $resp->getHeaders()['Content-Disposition'] ?? 'inline');
	}

	/** An SVG from before is still shown in a page, but opening it downloads it: no script on Nextcloud's domain. */
	public function testAnOlderSvgIsNeverOpenedAsAPage(): void {
		$this->brandingService->method('getBlockImage')->willReturn(['content' => '<svg/>', 'mimeType' => 'image/svg+xml']);

		$resp = $this->controller()->blockImage('b1');

		$this->assertStringStartsWith('attachment', $resp->getHeaders()['Content-Disposition'] ?? '');
	}

	private function storedLink(string $token = 'TOKEN'): void {
		$this->formRepository->method('loadPublic')->with(7)->willReturn(['settings' => ['public_token' => $token]]);
		$this->brandingService->method('getFormBlockImage')->with(7, 'b1')->willReturn(['content' => 'BYTES', 'mimeType' => 'image/gif']);
	}

	public function testAFormImageIsShownWithTheLinksToken(): void {
		$this->storedLink();
		$this->request->method('getParam')->willReturnCallback(fn ($key, $default = null) => $key === 'token' ? 'TOKEN' : $default);

		$resp = $this->controller()->formBlockImage(7, 'b1');

		$this->assertInstanceOf(DataDisplayResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame('image/gif', $resp->getHeaders()['Content-Type'] ?? null);
	}

	/** qual#87: by its id alone, or with another token, nobody gets a form's images. */
	public function testAFormImageIsNotShownWithoutTheTokenToSomeoneWhoMayNotOpenTheForm(): void {
		$this->storedLink();
		$this->request->method('getParam')->willReturnCallback(fn ($key, $default = null) => $key === 'token' ? 'GUESS' : $default);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->formBlockImage(7, 'b1')->getStatus());
	}

	public function testAFormImageIsNotShownToAVisitorWithoutAToken(): void {
		$this->storedLink();
		$this->request->method('getParam')->willReturnCallback(fn ($key, $default = null) => $default);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->formBlockImage(7, 'b1')->getStatus());
	}

	/** The editor and the results show it to whoever may open the form. */
	public function testAFormImageIsShownToWhoeverMayOpenTheForm(): void {
		$this->storedLink();
		$this->request->method('getParam')->willReturnCallback(fn ($key, $default = null) => $default);
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->access->expects($this->once())->method('require')->with(7, 'read')->willReturn($this->formFile);

		$this->assertSame(Http::STATUS_OK, $this->controller()->formBlockImage(7, 'b1')->getStatus());
	}

	public function testAFormImageIsNotShownToSomeoneWhoMayNotOpenTheForm(): void {
		$this->storedLink();
		$this->request->method('getParam')->willReturnCallback(fn ($key, $default = null) => $default);
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->access->method('require')->willThrowException(new FormNotFoundException('Form not found'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->formBlockImage(7, 'b1')->getStatus());
	}

	/** A busy form is a 503 to retry, not an image that is not there (review 3a-3). */
	public function testAFormImageOfABusyFormIsNoNotFound(): void {
		$this->formRepository->method('loadPublic')->willThrowException(new \OCA\FormVox\Service\FormLockedException('Form 7 stayed unreadable'));
		$this->request->method('getParam')->willReturnCallback(fn ($key, $default = null) => $key === 'token' ? 'TOKEN' : $default);

		$this->expectException(\OCA\FormVox\Service\FormLockedException::class);
		$this->controller()->formBlockImage(7, 'b1');
	}
}
