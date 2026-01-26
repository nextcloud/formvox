<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\File;
use OCA\FormVox\AppInfo\Application;

/**
 * Listener that cleans form data when a .fvform file is copied.
 * Removes responses, settings (tokens, passwords, etc.) but keeps questions.
 *
 * @template-implements IEventListener<NodeCopiedEvent>
 */
class FormCopiedListener implements IEventListener
{
    public function handle(Event $event): void
    {
        if (!($event instanceof NodeCopiedEvent)) {
            return;
        }

        $target = $event->getTarget();

        // Only process .fvform files
        if (!($target instanceof File)) {
            return;
        }

        $extension = pathinfo($target->getName(), PATHINFO_EXTENSION);
        if (strtolower($extension) !== Application::FILE_EXTENSION) {
            return;
        }

        try {
            // Get the actual file path on the filesystem to bypass Nextcloud's lock
            $storage = $target->getStorage();
            $internalPath = $target->getInternalPath();

            // Read the content directly from storage (bypasses lock)
            $content = $storage->file_get_contents($internalPath);
            $form = json_decode($content, true);

            if ($form === null) {
                return;
            }

            // Remove all responses
            $form['responses'] = [];

            // Remove index data
            $form['_index'] = [
                'fingerprints' => [],
                'userIds' => [],
            ];

            // Reset settings but keep form behavior settings
            $form['settings'] = [
                // Keep these settings (form behavior)
                'anonymous' => $form['settings']['anonymous'] ?? true,
                'allow_multiple' => $form['settings']['allow_multiple'] ?? false,
                'require_login' => $form['settings']['require_login'] ?? false,
                // Reset share-related settings
                'public_token' => null,
                'share_password_hash' => null,
                'share_expires_at' => null,
                // Reset access restrictions
                'allowed_users' => [],
                'allowed_groups' => [],
            ];

            // Remove form-specific branding (use admin defaults for new form)
            unset($form['branding']);

            // Update created_at to now, keep original as template reference
            $form['copied_from'] = $form['created_at'] ?? null;
            $form['created_at'] = date('c');
            $form['updated_at'] = date('c');

            // Write directly to storage (bypasses Nextcloud's lock)
            $storage->file_put_contents($internalPath, json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Touch the file to update mtime in cache
            $storage->touch($internalPath);

        } catch (\Exception $e) {
            // Silently fail - don't break the copy operation
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning(
                'FormVox: Failed to clean copied form: ' . $e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }
    }
}
