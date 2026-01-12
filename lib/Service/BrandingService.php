<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\IConfig;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCA\FormVox\AppInfo\Application;

class BrandingService
{
    private IConfig $config;
    private IAppData $appData;

    private const DEFAULT_LAYOUT = [
        'header' => [],
        'footer' => [],
        'thankYou' => [
            [
                'id' => 'default-thankyou-heading',
                'type' => 'heading',
                'alignment' => 'center',
                'settings' => [
                    'level' => 'h1',
                    'text' => 'Thank you!',
                ],
            ],
            [
                'id' => 'default-thankyou-text',
                'type' => 'text',
                'alignment' => 'center',
                'settings' => [
                    'content' => 'Your response has been submitted successfully.',
                ],
            ],
        ],
    ];

    private const DEFAULT_GLOBAL_STYLES = [
        'primaryColor' => '#0082c9',
        'backgroundColor' => '#ffffff',
        'fontFamily' => 'default',
    ];

    public function __construct(IConfig $config, IAppData $appData)
    {
        $this->config = $config;
        $this->appData = $appData;
    }

    /**
     * Get all branding settings (layout + global styles)
     */
    public function getBranding(): array
    {
        // Get layout
        $layoutJson = $this->config->getAppValue(Application::APP_ID, 'branding_layout', '');
        $layout = $layoutJson ? json_decode($layoutJson, true) : self::DEFAULT_LAYOUT;

        // Get global styles
        $stylesJson = $this->config->getAppValue(Application::APP_ID, 'branding_globalStyles', '');
        $globalStyles = $stylesJson ? json_decode($stylesJson, true) : self::DEFAULT_GLOBAL_STYLES;

        // Add image URLs for blocks that need them
        $layout = $this->resolveImageUrls($layout);

        return [
            'layout' => $layout,
            'globalStyles' => $globalStyles,
        ];
    }

    /**
     * Save branding layout
     */
    public function saveLayout(array $layout): array
    {
        $this->config->setAppValue(Application::APP_ID, 'branding_layout', json_encode($layout));
        return $this->getBranding();
    }

    /**
     * Save global styles
     */
    public function saveGlobalStyles(array $styles): array
    {
        $this->config->setAppValue(Application::APP_ID, 'branding_globalStyles', json_encode($styles));
        return $this->getBranding();
    }

    /**
     * Resolve image URLs for logo and image blocks
     */
    private function resolveImageUrls(array $layout): array
    {
        foreach (['header', 'footer', 'thankYou'] as $zone) {
            if (!isset($layout[$zone])) {
                continue;
            }
            foreach ($layout[$zone] as &$block) {
                if (in_array($block['type'], ['logo', 'image']) && !empty($block['settings']['imageId'])) {
                    $block['settings']['imageUrl'] = $this->getBlockImageUrl($block['id']);
                }
            }
        }
        return $layout;
    }

    /**
     * Save uploaded image for a block
     */
    public function saveBlockImage(string $blockId, string $tmpPath, string $mimeType): string
    {
        try {
            $folder = $this->appData->getFolder('branding');
        } catch (NotFoundException $e) {
            $folder = $this->appData->newFolder('branding');
        }

        // Determine extension from mime type
        $extension = $this->getExtensionFromMimeType($mimeType);
        $filename = 'block_' . $blockId . '.' . $extension;

        // Delete old image if exists
        $this->deleteBlockImageFile($folder, $blockId);

        // Save new image
        $content = file_get_contents($tmpPath);
        $file = $folder->newFile($filename);
        $file->putContent($content);

        return $blockId;
    }

    /**
     * Delete image for a block
     */
    public function deleteBlockImage(string $blockId): void
    {
        try {
            $folder = $this->appData->getFolder('branding');
            $this->deleteBlockImageFile($folder, $blockId);
        } catch (NotFoundException $e) {
            // No folder or file to delete
        }
    }

    /**
     * Delete block image file from folder
     */
    private function deleteBlockImageFile(ISimpleFolder $folder, string $blockId): void
    {
        try {
            foreach ($folder->getDirectoryListing() as $file) {
                if (strpos($file->getName(), 'block_' . $blockId . '.') === 0) {
                    $file->delete();
                    break;
                }
            }
        } catch (NotFoundException $e) {
            // No file to delete
        }
    }

    /**
     * Get block image content for serving
     */
    public function getBlockImage(string $blockId): ?array
    {
        try {
            $folder = $this->appData->getFolder('branding');
            foreach ($folder->getDirectoryListing() as $file) {
                if (strpos($file->getName(), 'block_' . $blockId . '.') === 0) {
                    $mimeType = $this->getMimeTypeFromFilename($file->getName());
                    return [
                        'content' => $file->getContent(),
                        'mimeType' => $mimeType,
                    ];
                }
            }
        } catch (NotFoundException $e) {
            // No file found
        }
        return null;
    }

    /**
     * Get block image URL for frontend
     */
    private function getBlockImageUrl(string $blockId): string
    {
        return \OC::$server->getURLGenerator()->linkToRoute('formvox.branding.blockImage', ['blockId' => $blockId]);
    }

    /**
     * Get file extension from mime type
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/svg+xml' => 'svg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    /**
     * Get mime type from filename
     */
    private function getMimeTypeFromFilename(string $filename): string
    {
        if (str_ends_with($filename, '.jpg') || str_ends_with($filename, '.jpeg')) {
            return 'image/jpeg';
        } elseif (str_ends_with($filename, '.svg')) {
            return 'image/svg+xml';
        } elseif (str_ends_with($filename, '.gif')) {
            return 'image/gif';
        } elseif (str_ends_with($filename, '.webp')) {
            return 'image/webp';
        }
        return 'image/png';
    }
}
