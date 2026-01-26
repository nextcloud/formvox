<?php

declare(strict_types=1);

namespace OCA\FormVox\DAV;

use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Sabre WebDAV plugin that hides .fvform files from sync clients.
 *
 * This plugin intercepts PROPFIND responses and removes .fvform files
 * from directory listings when the request comes from a sync client
 * (desktop, iOS, or Android).
 */
class HideFormFilesPlugin extends ServerPlugin
{
    private ?Server $server = null;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getPluginName(): string
    {
        return 'formvox-hide-files';
    }

    public function initialize(Server $server): void
    {
        $this->server = $server;

        // Hook into beforeWriteContent to filter response before it's sent
        // Priority 1 ensures we run early in the chain
        $server->on('afterMethod:PROPFIND', [$this, 'filterPropfindResponse'], 1);
    }

    /**
     * Check if the request is from a sync client (desktop, iOS, or Android)
     */
    private function isSyncClient(string $userAgent): bool
    {
        // Nextcloud desktop client (official regex)
        if (preg_match(IRequest::USER_AGENT_CLIENT_DESKTOP, $userAgent)) {
            return true;
        }

        // Nextcloud iOS client (official regex)
        if (preg_match(IRequest::USER_AGENT_CLIENT_IOS, $userAgent)) {
            return true;
        }

        // Nextcloud Android client (official regex)
        if (preg_match(IRequest::USER_AGENT_CLIENT_ANDROID, $userAgent)) {
            return true;
        }

        // Fallback: check for common sync client identifiers in user-agent
        // This catches variations that don't match the official regex
        $syncClientPatterns = [
            '/Nextcloud-iOS/i',
            '/Nextcloud-android/i',
            '/ownCloud-iOS/i',
            '/ownCloud-android/i',
            '/mirall\//i',  // Desktop client identifier
            '/csyncoC\//i', // Alternative desktop identifier
        ];

        foreach ($syncClientPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter the PROPFIND response to remove .fvform files
     */
    public function filterPropfindResponse(RequestInterface $request, ResponseInterface $response): void
    {
        // Get User-Agent from the Sabre request (not Nextcloud IRequest)
        $userAgent = $request->getHeader('User-Agent') ?? '';

        // Only filter for sync clients
        if (!$this->isSyncClient($userAgent)) {
            return;
        }

        $this->logger->debug('FormVox: Filtering PROPFIND for sync client', [
            'user_agent' => $userAgent,
            'path' => $request->getPath(),
        ]);
        // Only process successful PROPFIND responses (207 Multi-Status)
        if ($response->getStatus() !== 207) {
            return;
        }

        // Get the response body - handle both string and stream
        $body = $response->getBody();
        $bodyString = '';

        if (is_resource($body)) {
            // Stream - rewind and read
            rewind($body);
            $bodyString = stream_get_contents($body);
        } elseif (is_string($body)) {
            $bodyString = $body;
        } elseif ($body !== null) {
            // Try getBodyAsString as fallback
            $bodyString = $response->getBodyAsString();
        }

        if (empty($bodyString)) {
            $this->logger->debug('FormVox: Empty body', [
                'path' => $request->getPath(),
                'body_type' => gettype($body),
            ]);
            return;
        }

        // Parse the XML response
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (@$dom->loadXML($bodyString) === false) {
            return;
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('d', 'DAV:');

        // Find all <d:response> elements
        $responses = $xpath->query('//d:response');
        $removed = false;

        foreach ($responses as $responseNode) {
            // Get the href for this response
            $hrefNodes = $xpath->query('d:href', $responseNode);
            if ($hrefNodes->length === 0) {
                continue;
            }

            $href = $hrefNodes->item(0)->textContent;

            // Check if this is a .fvform file
            if (preg_match('/\.fvform$/i', urldecode($href))) {
                // Remove this response from the XML
                $responseNode->parentNode->removeChild($responseNode);
                $removed = true;
            }
        }

        // If we removed anything, update the response body
        if ($removed) {
            $this->logger->debug('FormVox: Removed .fvform files from PROPFIND response', [
                'path' => $request->getPath(),
            ]);
            $response->setBody($dom->saveXML());
        }
    }
}
