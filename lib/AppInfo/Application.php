<?php

declare(strict_types=1);

namespace OCA\FormVox\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FormVox\Listener\LoadFilesPluginListener;
use OCA\FormVox\Listener\FormCopiedListener;
use OCA\FormVox\Listener\FormDeletedListener;
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
        // Note: registerPreviewProvider expects a regex pattern, not a plain MIME type
        $context->registerPreviewProvider(FormPreview::class, '/application\/x-fvform/');

        // Register Files app integration listener
        $context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesPluginListener::class);

        // Register listener to clean form data when copied
        $context->registerEventListener(NodeCopiedEvent::class, FormCopiedListener::class);

        // Register listener to delete uploads folder when form is deleted
        $context->registerEventListener(NodeDeletedEvent::class, FormDeletedListener::class);

        // Register DAV plugin to hide .fvform files from sync clients
        $context->registerEventListener(SabrePluginAuthInitEvent::class, RegisterDavPluginListener::class);
    }

    public function boot(IBootContext $context): void
    {
        // MIME type registration is handled by appinfo/mimetypemapping.json
        // and the config/mimetypemapping.json (written by RegisterMimeType repair step).
        // Do NOT call registerType()/registerTypeArray() here — it populates
        // MimeTypeDetector::$mimeTypes before loadMappings() runs, which
        // causes loadMappings() to skip loading all core defaults, breaking
        // mimetype detection for every other file type (images, PDFs, etc.).
    }
}
