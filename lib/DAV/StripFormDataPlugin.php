<?php

declare(strict_types=1);

namespace OCA\FormVox\DAV;

use OCA\FormVox\AppInfo\Application;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Sabre DAV plugin that strips sensitive data (responses, settings, etc.)
 * from .fvform files when they are downloaded.
 *
 * This ensures that when users download or sync form files, they only get
 * the form structure (questions) without any response data.
 */
class StripFormDataPlugin extends ServerPlugin
{
    private Server $server;

    public function initialize(Server $server): void
    {
        $this->server = $server;

        // Hook into the response, after the file content is set but before it's sent
        $server->on('afterMethod:GET', [$this, 'afterGet'], 90);
    }

    /**
     * Called after a GET request - modify response body for .fvform files
     */
    public function afterGet(RequestInterface $request, ResponseInterface $response): void
    {
        // Only process successful responses
        $status = $response->getStatus();
        if ($status < 200 || $status >= 300) {
            return;
        }

        // Check if this is a .fvform file
        $path = $request->getPath();
        if (!$this->isFormFile($path)) {
            return;
        }

        // Get the response body
        $body = $response->getBody();
        if ($body === null) {
            return;
        }

        // Read the content
        if (is_resource($body)) {
            $content = stream_get_contents($body);
            // Reset stream position if possible
            if (is_resource($body)) {
                rewind($body);
            }
        } else {
            $content = (string)$body;
        }

        if (empty($content)) {
            return;
        }

        // Try to parse as JSON
        $form = json_decode($content, true);
        if ($form === null || !is_array($form)) {
            return;
        }

        // Strip the sensitive data
        $strippedForm = $this->stripFormData($form);
        $strippedContent = json_encode($strippedForm, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Update the response with stripped content
        $response->setBody($strippedContent);
        $response->setHeader('Content-Length', (string)strlen($strippedContent));
    }

    /**
     * Check if the path is a .fvform file
     */
    private function isFormFile(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $extension === Application::FILE_EXTENSION;
    }

    /**
     * Strip sensitive data from form, keeping only the structure
     */
    private function stripFormData(array $form): array
    {
        // Remove all responses
        $form['responses'] = [];

        // Remove index data
        $form['_index'] = [
            '_checksum' => '',
            'response_count' => 0,
            'last_response_at' => null,
            'fingerprints' => [],
            'user_ids' => [],
            'by_date' => [],
            'answer_counts' => [],
        ];

        // Reset settings but keep form behavior settings
        // Note: This explicitly excludes sensitive data like api_keys, webhooks,
        // share tokens, passwords, and access restrictions
        $form['settings'] = [
            // Keep these settings (form behavior)
            'anonymous' => $form['settings']['anonymous'] ?? true,
            'allow_multiple' => $form['settings']['allow_multiple'] ?? false,
            'require_login' => $form['settings']['require_login'] ?? false,
            // Everything else is stripped:
            // - public_token (share links)
            // - share_password_hash (passwords)
            // - share_expires_at (expiration)
            // - allowed_users, allowed_groups (access restrictions)
            // - api_keys (API credentials)
            // - webhooks (webhook configurations)
        ];

        // Remove form-specific branding (use admin defaults)
        unset($form['branding']);

        // Remove permissions (will use file permissions)
        unset($form['permissions']);

        // Update timestamps
        $form['downloaded_at'] = date('c');

        return $form;
    }

    public function getPluginName(): string
    {
        return 'formvox-strip-data';
    }

    public function getPluginInfo(): array
    {
        return [
            'name' => $this->getPluginName(),
            'description' => 'Strips sensitive data from FormVox files on download',
        ];
    }
}
