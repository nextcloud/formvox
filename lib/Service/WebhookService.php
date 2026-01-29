<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Http\Client\IClientService;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class WebhookService
{
    private IClientService $clientService;
    private ISecureRandom $secureRandom;
    private LoggerInterface $logger;

    public function __construct(
        IClientService $clientService,
        ISecureRandom $secureRandom,
        LoggerInterface $logger
    ) {
        $this->clientService = $clientService;
        $this->secureRandom = $secureRandom;
        $this->logger = $logger;
    }

    /**
     * Generate a new webhook secret
     */
    public function generateSecret(): string
    {
        return 'whsec_' . $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
    }

    /**
     * Generate a webhook ID
     */
    public function generateId(): string
    {
        return 'wh_' . $this->secureRandom->generate(8, ISecureRandom::CHAR_ALPHANUMERIC);
    }

    /**
     * Trigger webhooks for an event
     */
    public function trigger(array $form, string $event, array $data): void
    {
        $webhooks = $form['settings']['webhooks'] ?? [];

        if (empty($webhooks)) {
            return;
        }

        foreach ($webhooks as $webhook) {
            if (!$this->shouldTrigger($webhook, $event)) {
                continue;
            }

            $this->send($webhook, $event, $data, $form);
        }
    }

    /**
     * Check if webhook should be triggered for this event
     */
    private function shouldTrigger(array $webhook, string $event): bool
    {
        if (!($webhook['enabled'] ?? true)) {
            return false;
        }

        $events = $webhook['events'] ?? [];

        // Empty events = all events
        if (empty($events)) {
            return true;
        }

        return in_array($event, $events, true);
    }

    /**
     * Send webhook request
     */
    private function send(array $webhook, string $event, array $data, array $form): void
    {
        $url = $webhook['url'] ?? '';
        if (empty($url)) {
            return;
        }

        $payload = [
            'event' => $event,
            'timestamp' => (new \DateTime())->format(\DateTime::ATOM),
            'form' => [
                'title' => $form['title'] ?? '',
            ],
            'data' => $data,
        ];

        $jsonPayload = json_encode($payload);

        // Calculate signature
        $secret = $webhook['secret'] ?? '';
        $signature = $this->calculateSignature($jsonPayload, $secret);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'FormVox-Webhook/1.0',
            'X-FormVox-Event' => $event,
            'X-FormVox-Signature' => $signature,
            'X-FormVox-Timestamp' => (string) time(),
        ];

        try {
            $client = $this->clientService->newClient();
            $client->post($url, [
                'headers' => $headers,
                'body' => $jsonPayload,
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);

            $this->logger->info('FormVox webhook sent', [
                'url' => $url,
                'event' => $event,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('FormVox webhook failed', [
                'url' => $url,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calculate HMAC signature for webhook payload
     */
    public function calculateSignature(string $payload, string $secret): string
    {
        if (empty($secret)) {
            return '';
        }

        $timestamp = time();
        $signedPayload = $timestamp . '.' . $payload;

        return 'v1=' . hash_hmac('sha256', $signedPayload, $secret);
    }

    /**
     * Verify webhook signature (for testing/documentation)
     */
    public function verifySignature(string $payload, string $signature, string $secret, int $timestamp): bool
    {
        if (empty($secret)) {
            return true;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = 'v1=' . hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Available webhook events
     */
    public static function getAvailableEvents(): array
    {
        return [
            'response.created' => 'When a new response is submitted',
            'response.updated' => 'When a response is updated',
            'response.deleted' => 'When a response is deleted',
        ];
    }
}
