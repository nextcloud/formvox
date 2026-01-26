<?php

declare(strict_types=1);

namespace OCA\FormVox\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FormVox\Listener\LoadFilesPluginListener;
use OCA\FormVox\Listener\FormCopiedListener;
use OCA\FormVox\Listener\RegisterDavPluginListener;
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

        // Register Files app integration listener
        $context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesPluginListener::class);

        // Register listener to clean form data when copied
        $context->registerEventListener(NodeCopiedEvent::class, FormCopiedListener::class);

        // Register DAV plugin to hide .fvform files from sync clients
        $context->registerEventListener(SabrePluginAuthInitEvent::class, RegisterDavPluginListener::class);
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
