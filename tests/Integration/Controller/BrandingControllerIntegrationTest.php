<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Controller;

use OCA\FormVox\Controller\BrandingController;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormFactory;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A form's branding image against a real Nextcloud: its own detector decides what an upload is (secu#54), and
 * a visitor sees the image with the form's link token only (qual#87).
 *
 * @group DB
 */
class BrandingControllerIntegrationTest extends IntegrationTestCase {
	private const TOKEN = 'fvit-branding-token';

	private IRequest $request;
	/** @var array<string, mixed> */
	private array $params = [];
	private ?array $upload = null;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getUploadedFile')->willReturnCallback(fn () => $this->upload);
		$this->request->method('getParam')->willReturnCallback(fn ($key, $default = null) => $this->params[$key] ?? $default);
	}

	private function controller(): BrandingController {
		return new BrandingController(
			$this->request,
			$this->getService(BrandingService::class),
			$this->getService(FormAccessService::class),
			$this->getService(FormRepository::class),
			$this->getService(IMimeTypeDetector::class),
			$this->getService(IUserSession::class),
		);
	}

	private function form(): File {
		/** @var FormFactory $factory */
		$factory = $this->getService(FormFactory::class);
		$settings = $factory->createFormStructure('Branding', $this->ownerUid)['settings'];
		return $this->writeFormFile($this->userFolder, 'Branding Form', ['settings' => ['public_token' => self::TOKEN] + $settings]);
	}

	/**
	 * The smallest real image of each kind, as a browser would send it.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function images(): array {
		return [
			'PNG' => ['image/png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')],
			'GIF' => ['image/gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')],
			'JPEG' => ['image/jpeg', base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=')],
			'WebP' => ['image/webp', base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==')],
		];
	}

	/**
	 * @dataProvider images
	 */
	public function testARealImageIsTakenAsWhatItIs(string $type, string $bytes): void {
		$form = $this->form();
		$this->upload = $this->makeUploadedFile('logo', $bytes, 'application/octet-stream');

		$this->controller()->uploadFormBlockImage($form->getId(), 'logo1');

		$image = $this->getService(BrandingService::class)->getFormBlockImage($form->getId(), 'logo1');
		$this->assertSame($type, $image['mimeType'] ?? null);
		$this->assertSame($bytes, $image['content']);
	}

	public function testAnSvgCalledAPngIsRefused(): void {
		$form = $this->form();
		$this->upload = $this->makeUploadedFile('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/png');

		try {
			$this->controller()->uploadFormBlockImage($form->getId(), 'logo1');
			$this->fail('An SVG was taken');
		} catch (RequestException $e) {
			$this->assertSame(Http::STATUS_BAD_REQUEST, $e->getStatus());
		}
		$this->assertNull($this->getService(BrandingService::class)->getFormBlockImage($form->getId(), 'logo1'));
	}

	public function testAVisitorSeesTheImageWithTheLinksTokenOnly(): void {
		$form = $this->form();
		$this->requirePublicResolvable($form->getId());
		[, $png] = self::images()['PNG'];
		$this->upload = $this->makeUploadedFile('logo.png', $png, 'image/png');
		$this->controller()->uploadFormBlockImage($form->getId(), 'logo1');
		$this->logout();

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->formBlockImage($form->getId(), 'logo1')->getStatus());
		$this->params = ['token' => 'not-' . self::TOKEN];
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->formBlockImage($form->getId(), 'logo1')->getStatus());
		$this->params = ['token' => self::TOKEN];
		$response = $this->controller()->formBlockImage($form->getId(), 'logo1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($png, $response->render());
	}

	public function testWhoeverMayOpenTheFormSeesTheImageWithoutAToken(): void {
		$form = $this->form();
		[, $png] = self::images()['PNG'];
		$this->upload = $this->makeUploadedFile('logo.png', $png, 'image/png');
		$this->controller()->uploadFormBlockImage($form->getId(), 'logo1');

		$this->assertSame(Http::STATUS_OK, $this->controller()->formBlockImage($form->getId(), 'logo1')->getStatus());
		$this->switchToUser('formvox-int-stranger');
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->formBlockImage($form->getId(), 'logo1')->getStatus());
	}
}
