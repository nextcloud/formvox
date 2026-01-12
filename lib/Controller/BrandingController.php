<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\BrandingService;

class BrandingController extends Controller
{
    private BrandingService $brandingService;

    public function __construct(
        IRequest $request,
        BrandingService $brandingService
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->brandingService = $brandingService;
    }

    /**
     * Get branding settings (admin only)
     */
    public function get(): DataResponse
    {
        return new DataResponse($this->brandingService->getBranding());
    }

    /**
     * Save layout (admin only)
     */
    public function saveLayout(array $layout): DataResponse
    {
        $branding = $this->brandingService->saveLayout($layout);
        return new DataResponse($branding);
    }

    /**
     * Save global styles (admin only)
     */
    public function saveStyles(array $globalStyles): DataResponse
    {
        $branding = $this->brandingService->saveGlobalStyles($globalStyles);
        return new DataResponse($branding);
    }

    /**
     * Upload block image (admin only)
     */
    public function uploadBlockImage(string $blockId): DataResponse
    {
        $file = $this->request->getUploadedFile('image');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return new DataResponse(
                ['error' => 'No file uploaded'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $allowedTypes = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            return new DataResponse(
                ['error' => 'Invalid file type. Allowed: PNG, JPEG, SVG, GIF, WebP'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $maxSize = 2 * 1024 * 1024; // 2MB
        if ($file['size'] > $maxSize) {
            return new DataResponse(
                ['error' => 'File too large. Maximum size: 2MB'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $imageId = $this->brandingService->saveBlockImage($blockId, $file['tmp_name'], $file['type']);
        return new DataResponse(['imageId' => $imageId]);
    }

    /**
     * Delete block image (admin only)
     */
    public function deleteBlockImage(string $blockId): DataResponse
    {
        $this->brandingService->deleteBlockImage($blockId);
        return new DataResponse(['success' => true]);
    }

    /**
     * Serve block image (public access)
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function blockImage(string $blockId): DataDisplayResponse
    {
        $image = $this->brandingService->getBlockImage($blockId);
        if ($image === null) {
            return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
        }

        $response = new DataDisplayResponse($image['content']);
        $response->addHeader('Content-Type', $image['mimeType']);
        $response->cacheFor(3600); // Cache for 1 hour
        return $response;
    }
}
