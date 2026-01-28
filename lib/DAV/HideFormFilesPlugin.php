<?php

declare(strict_types=1);

namespace OCA\FormVox\DAV;

use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Sabre WebDAV plugin that hides .fvform files from all WebDAV clients.
 *
 * This plugin intercepts PROPFIND responses and removes .fvform files
 * from directory listings. Files remain accessible via Nextcloud web interface.
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
        $server->on('afterMethod:PROPFIND', [$this, 'filterPropfindResponse'], 1);
    }

    /**
     * Check if this request comes from the Nextcloud web interface.
     * The web interface sends a requesttoken header for CSRF protection.
     */
    private function isWebInterfaceRequest(RequestInterface $request): bool
    {
        // The Nextcloud web interface sends these headers for AJAX requests
        // External clients (sync, Sendent, other DAV clients) don't have these
        $requestToken = $request->getHeader('requesttoken');
        $ocsToken = $request->getHeader('OCS-APIREQUEST-TOKEN');

        return !empty($requestToken) || !empty($ocsToken);
    }

    /**
     * Filter the PROPFIND response to remove .fvform files
     */
    public function filterPropfindResponse(RequestInterface $request, ResponseInterface $response): void
    {
        // Allow Nextcloud web interface to see all files
        if ($this->isWebInterfaceRequest($request)) {
            return;
        }

        // Only process successful PROPFIND responses (207 Multi-Status)
        if ($response->getStatus() !== 207) {
            return;
        }

        // Get the response body - handle both string and stream
        $body = $response->getBody();
        $bodyString = '';

        if (is_resource($body)) {
            rewind($body);
            $bodyString = stream_get_contents($body);
        } elseif (is_string($body)) {
            $bodyString = $body;
        } elseif ($body !== null) {
            $bodyString = $response->getBodyAsString();
        }

        if (empty($bodyString)) {
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
            $hrefNodes = $xpath->query('d:href', $responseNode);
            if ($hrefNodes->length === 0) {
                continue;
            }

            $href = $hrefNodes->item(0)->textContent;

            // Check if this is a .fvform file
            if (preg_match('/\.fvform$/i', urldecode($href))) {
                $responseNode->parentNode->removeChild($responseNode);
                $removed = true;
            }
        }

        if ($removed) {
            $this->logger->debug('FormVox: Removed .fvform files from PROPFIND response', [
                'path' => $request->getPath(),
            ]);
            $response->setBody($dom->saveXML());
        }
    }
}
