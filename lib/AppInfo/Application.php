<?php

declare(strict_types=1);

namespace OCA\FormVox\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FormVox\Listener\AccountDeletedListener;
use OCA\FormVox\Listener\AiTaskFailedListener;
use OCA\FormVox\Listener\AiTaskSuccessfulListener;
use OCA\FormVox\Listener\FormCopiedListener;
use OCA\FormVox\Listener\FormDeletedListener;
use OCA\FormVox\Listener\FormMovedListener;
use OCA\FormVox\Listener\FormRemovedListener;
use OCA\FormVox\Listener\FormVersionListener;
use OCA\FormVox\Listener\FormWrittenListener;
use OCA\FormVox\Listener\HiddenShareListener;
use OCA\FormVox\Listener\LoadFilesPluginListener;
use OCA\FormVox\Listener\PublicFormZipListener;
use OCA\FormVox\Listener\RegisterDavPluginListener;
use OCA\FormVox\Middleware\ExceptionMiddleware;
use OCA\FormVox\Middleware\FormAccessMiddleware;
use OCA\FormVox\Middleware\PublicShareMiddleware;
use OCA\FormVox\Notification\Notifier;
use OCA\FormVox\Preview\FormPreview;
use OCA\FormVox\Service\FormTrash;
use OCA\FormVox\SetupCheck\BackgroundJobsCheck;
use OCA\FormVox\SetupCheck\DatabaseCheck;
use OCA\FormVox\SetupCheck\PdfConfirmationCheck;
use OCA\FormVox\Versions\FilesVersionCleaner;
use OCA\FormVox\Versions\IFormVersionCleaner;
use OCA\FormVox\Versions\NullVersionCleaner;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BeforeSabrePubliclyLoadedEvent;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'formvox';
	public const MIME_TYPE = 'application/x-fvform';
	public const FILE_EXTENSION = 'fvform';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Register preview provider for .fvform files
		// Note: registerPreviewProvider expects a regex pattern, not a plain MIME type
		$context->registerPreviewProvider(FormPreview::class, '/application\/x-fvform/');

		// Register Files app integration listener
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesPluginListener::class);

		// Enforces #[RequireFormPermission] on controller methods
		$context->registerMiddleware(ExceptionMiddleware::class);
		// After ExceptionMiddleware, so its refusals unwind into it.
		$context->registerMiddleware(FormAccessMiddleware::class);
		// For every app: Nextcloud's own public share pages must not reach a form's own folders.
		$context->registerMiddleware(PublicShareMiddleware::class, true);

		// Register listener to clean form data when copied
		$context->registerEventListener(NodeCopiedEvent::class, FormCopiedListener::class);

		// A form file written outside FormVox keeps the server's data (WebDAV PUT has its own plugin, for the error).
		$context->registerEventListener(BeforeNodeWrittenEvent::class, FormWrittenListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, FormWrittenListener::class);
		$context->registerEventListener(BeforeNodeRenamedEvent::class, FormWrittenListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, FormWrittenListener::class);

		// A response leaves no version; a change to the form does. Nextcloud 32/33 name the event, 34+ type it.
		$context->registerEventListener('OCA\Files_Versions::createVersion', FormVersionListener::class);
		$context->registerEventListener('OCA\Files_Versions\Events\CreateVersionEvent', FormVersionListener::class);

		// Register listener to delete uploads folder when form is deleted
		$context->registerEventListener(NodeDeletedEvent::class, FormDeletedListener::class);

		// A form's file deleted for good takes its responses and files along, at the end of that request.
		$context->registerEventListener(BeforeNodeDeletedEvent::class, FormRemovedListener::class);
		$context->registerEventListener(CacheEntryRemovedEvent::class, FormRemovedListener::class);
		// A deleted account's forms go with it: Nextcloud empties its home without a word per file.
		$context->registerEventListener(BeforeUserDeletedEvent::class, AccountDeletedListener::class);
		$context->registerEventListener(UserDeletedEvent::class, AccountDeletedListener::class);

		// Register listener to move associated folders when form is moved
		$context->registerEventListener(BeforeNodeRenamedEvent::class, FormMovedListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, FormMovedListener::class);

		// Register notification handler
		$context->registerNotifierService(Notifier::class);

		// Emails, webhooks and notifications about new responses go out from background jobs.
		$context->registerSetupCheck(BackgroundJobsCheck::class);
		$context->registerSetupCheck(PdfConfirmationCheck::class);
		$context->registerSetupCheck(DatabaseCheck::class);

		// Async AI form generation: pick up TaskProcessing task completion
		$context->registerEventListener(TaskSuccessfulEvent::class, AiTaskSuccessfulListener::class);
		$context->registerEventListener(TaskFailedEvent::class, AiTaskFailedListener::class);

		// Hide forms from sync clients; strip them on every WebDAV entry point, public links included.
		$context->registerEventListener(SabrePluginAuthInitEvent::class, RegisterDavPluginListener::class);
		$context->registerEventListener(SabrePluginAddEvent::class, RegisterDavPluginListener::class);
		$context->registerEventListener(BeforeSabrePubliclyLoadedEvent::class, RegisterDavPluginListener::class);
		// After files_sharing's own zip listener (priority 5), which approves public zips.
		$context->registerEventListener(BeforeZipCreatedEvent::class, PublicFormZipListener::class, -10);
		// A form's own folders are not shared by link, mail, other server, Talk or Teams.
		$context->registerEventListener(BeforeShareCreatedEvent::class, HiddenShareListener::class);

		// Bind the form version cleaner to the real implementation when the
		// files_versions app is available, otherwise a no-op. FormLockManager
		// type-hints the interface; this alias tells the DI container which
		// implementation to construct (auto-wiring cannot resolve an interface).
		$context->registerService(IFormVersionCleaner::class, static function ($c): IFormVersionCleaner {
			// An interface: class_exists() was always false here, so versions were never cleaned (qual#12).
			if (interface_exists(\OCA\Files_Versions\Versions\IVersionManager::class)) {
				return $c->get(FilesVersionCleaner::class);
			}
			return $c->get(NullVersionCleaner::class);
		});
	}

	public function boot(IBootContext $context): void {
		// A form back from the trash takes its folders along; both Nextcloud's and the team folders' trash send this.
		\OCP\Util::connectHook('\OCA\Files_Trashbin\Trashbin', 'post_restore', FormTrash::class, 'onRestore');

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
