<?php

declare(strict_types=1);

namespace OCA\FormVox\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FormVox\Listener\LoadFilesPluginListener;
use OCA\FormVox\Listener\FormCopiedListener;
use OCA\FormVox\Listener\FormDeletedListener;
use OCA\FormVox\Listener\FormMovedListener;
use OCA\FormVox\Listener\RegisterDavPluginListener;
use OCA\FormVox\Notification\Notifier;
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

        // Register listener to move associated folders when form is moved
        $context->registerEventListener(NodeRenamedEvent::class, FormMovedListener::class);

        // Register notification handler
        $context->registerNotifierService(Notifier::class);

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

        // Allow external images in descriptions (markdown img support)
        $cspManager = $context->getServerContainer()->get(\OCP\Security\IContentSecurityPolicyManager::class);
        $csp = new \OCP\AppFramework\Http\ContentSecurityPolicy();
        $csp->addAllowedImageDomain('https:');
        $cspManager->addDefaultPolicy($csp);
    }
}
