<?php

declare(strict_types=1);

namespace OCA\FormVox\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\FormVox\Preview\FormPreview;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'formvox';
    public const MIME_TYPE = 'application/x-fvform';
    public const FILE_EXTENSION = 'fvform';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        // Register preview provider for .fvform files
        $context->registerPreviewProvider(FormPreview::class, self::MIME_TYPE);
    }

    public function boot(IBootContext $context): void
    {
        $this->registerMimeType();
    }

    private function registerMimeType(): void
    {
        $mimeTypeDetector = \OC::$server->getMimeTypeDetector();

        // Register the custom mime type for .fvform files
        $mimeTypeDetector->registerType(
            self::FILE_EXTENSION,
            self::MIME_TYPE
        );

        // Register mapping from extension to mime type
        $mappings = [
            self::FILE_EXTENSION => [self::MIME_TYPE],
        ];

        $mimeTypeDetector->registerTypeArray($mappings);
    }
}
