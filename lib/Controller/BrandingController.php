<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\L10n\Translatable;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\RequireFormPermission;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormRepository;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IMimeTypeDetector;
use OCP\IRequest;
use OCP\IUserSession;

class BrandingController extends Controller {
	/** What a branding image may be: not SVG, which can carry script (secu#54, PO 6). */
	private const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
	private const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		private BrandingService $brandingService,
		private FormAccessService $access,
		private FormRepository $formRepository,
		private IMimeTypeDetector $mimeTypeDetector,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The uploaded image, by what the file is rather than what the browser says it is.
	 *
	 * @return array{string, string} its temporary path and type
	 * @throws RequestException
	 */
	private function uploadedImage(string $blockId): array {
		$this->requireBlockId($blockId);
		$file = $this->request->getUploadedFile('image');
		if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK) {
			// TRANSLATORS: Error when a branding image upload carried no file
			throw RequestException::invalid(Translatable::t('No file was uploaded'));
		}
		if ((int)($file['size'] ?? 0) > self::MAX_IMAGE_BYTES) {
			// TRANSLATORS: Error when a branding image is too large
			throw RequestException::invalid(Translatable::t('The image is larger than 2 MB'));
		}
		$type = $this->mimeTypeDetector->detectContent((string)$file['tmp_name']);
		if (!in_array($type, self::IMAGE_TYPES, true)) {
			// TRANSLATORS: Error when a branding image is not one of the allowed formats
			throw RequestException::invalid(Translatable::t('Use a PNG, JPEG, GIF or WebP image'));
		}
		return [(string)$file['tmp_name'], $type];
	}

	/** It names the image file, so only a plain name. */
	private function requireBlockId(string $blockId): void {
		if (preg_match('/^[\w-]{1,64}$/', $blockId) !== 1) {
			throw RequestException::notFound(Translatable::t('Not found'));
		}
	}

	private function imageResponse(?array $image): DataDisplayResponse {
		if ($image === null) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
		$response = new DataDisplayResponse($image['content']);
		$response->addHeader('Content-Type', $image['mimeType']);
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		if ($image['mimeType'] === 'image/svg+xml') {
			// One from before SVG was refused: still shown in a page, but never opened as one, where it could run script.
			$response->addHeader('Content-Disposition', 'attachment; filename="image.svg"');
		}
		$response->cacheFor(3600);
		return $response;
	}

	/**
	 * Get branding settings (admin only)
	 */
	public function get(): DataResponse {
		return new DataResponse($this->brandingService->getBranding());
	}

	/**
	 * Save layout (admin only)
	 */
	public function saveLayout(array $layout): DataResponse {
		$branding = $this->brandingService->saveLayout($layout);
		return new DataResponse($branding);
	}

	/**
	 * Save global styles (admin only)
	 */
	public function saveStyles(array $globalStyles): DataResponse {
		$branding = $this->brandingService->saveGlobalStyles($globalStyles);
		return new DataResponse($branding);
	}

	/**
	 * Upload block image (admin only)
	 */
	public function uploadBlockImage(string $blockId): DataResponse {
		[$path, $type] = $this->uploadedImage($blockId);
		return new DataResponse(['imageId' => $this->brandingService->saveBlockImage($blockId, $path, $type)]);
	}

	/**
	 * Delete block image (admin only)
	 */
	public function deleteBlockImage(string $blockId): DataResponse {
		$this->brandingService->deleteBlockImage($blockId);
		return new DataResponse(['success' => true]);
	}

	/**
	 * Serve block image (public access)
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function blockImage(string $blockId): DataDisplayResponse {
		$this->requireBlockId($blockId);
		return $this->imageResponse($this->brandingService->getBlockImage($blockId));
	}

	/**
	 * Per-form branding image, stored in .formvox-branding-{fileId}/ next to the .fvform.
	 */
	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::EDIT)]
	public function uploadFormBlockImage(int $fileId, string $blockId): DataResponse {
		[$path, $type] = $this->uploadedImage($blockId);
		return new DataResponse(['imageId' => $this->brandingService->saveFormBlockImage($this->access->getFile($fileId), $blockId, $path, $type)]);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::EDIT)]
	public function deleteFormBlockImage(int $fileId, string $blockId): DataResponse {
		$this->requireBlockId($blockId);
		$this->brandingService->deleteFormBlockImage($this->access->getFile($fileId), $blockId);
		return new DataResponse(['success' => true]);
	}

	/**
	 * A form's own image: with its link's token, as the public page asks for it, or for whoever may open the form
	 * (the editor, the results). Without either, any form's images could be had by its id (qual#87, secu#95).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function formBlockImage(int $fileId, string $blockId): DataDisplayResponse {
		$this->requireBlockId($blockId);
		if (!$this->mayViewFormImage($fileId)) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
		return $this->imageResponse($this->brandingService->getFormBlockImage($fileId, $blockId));
	}

	private function mayViewFormImage(int $fileId): bool {
		$token = (string)$this->request->getParam('token', '');
		try {
			if ($token !== '') {
				$stored = $this->formRepository->loadPublic($fileId)['settings']['public_token'] ?? null;
				return is_string($stored) && hash_equals($stored, $token);
			}
			if ($this->userSession->getUser() === null) {
				return false;
			}
			$this->access->require($fileId, FormPermission::READ);
			return true;
		} catch (FormLockedException $busy) {
			// A busy form is a 503 to retry, not an image that is not there.
			throw $busy;
		} catch (\Throwable) {
			return false;
		}
	}
}
