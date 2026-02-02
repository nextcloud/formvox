<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class MicrosoftFormsAuthService
{
    private const OAUTH_AUTHORIZE_URL = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize';
    private const OAUTH_TOKEN_URL = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token';

    // Microsoft Forms API scope - this is the key to accessing the undocumented Forms API
    // The .default scope requests all permissions that have been configured for the app
    // Reference: https://jackparker.co.uk/blog/unlocking-the-hidden-microsoft-forms-api-with-powershell-azure-app-registration/
    private const SCOPES = [
        'https://forms.office.com/.default',
        'offline_access',
    ];

    public function __construct(
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private ISecureRandom $secureRandom,
        private ICrypto $crypto,
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    public function getClientId(): string
    {
        return $this->config->getAppValue(Application::APP_ID, 'ms_client_id', '');
    }

    public function getClientSecret(): string
    {
        $encrypted = $this->config->getAppValue(Application::APP_ID, 'ms_client_secret', '');
        if (empty($encrypted)) {
            return '';
        }
        try {
            return $this->crypto->decrypt($encrypted);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to decrypt MS client secret', ['exception' => $e]);
            return '';
        }
    }

    public function getTenantId(): string
    {
        return $this->config->getAppValue(Application::APP_ID, 'ms_tenant_id', 'common');
    }

    public function isConfigured(): bool
    {
        return !empty($this->getClientId()) && !empty($this->getClientSecret());
    }

    public function setClientId(string $clientId): void
    {
        $this->config->setAppValue(Application::APP_ID, 'ms_client_id', $clientId);
    }

    public function setClientSecret(string $clientSecret): void
    {
        $encrypted = $this->crypto->encrypt($clientSecret);
        $this->config->setAppValue(Application::APP_ID, 'ms_client_secret', $encrypted);
    }

    public function setTenantId(string $tenantId): void
    {
        $this->config->setAppValue(Application::APP_ID, 'ms_tenant_id', $tenantId);
    }

    public function getRedirectUri(): string
    {
        return $this->urlGenerator->linkToRouteAbsolute('formvox.import.msAuthCallback');
    }

    public function getAuthorizationUrl(string $userId): string
    {
        $state = $this->generateState($userId);

        $params = [
            'client_id' => $this->getClientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->getRedirectUri(),
            'response_mode' => 'query',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'prompt' => 'select_account',
        ];

        $url = str_replace('{tenant}', $this->getTenantId(), self::OAUTH_AUTHORIZE_URL);

        return $url . '?' . http_build_query($params);
    }

    public function exchangeCodeForToken(string $code): array
    {
        $url = str_replace('{tenant}', $this->getTenantId(), self::OAUTH_TOKEN_URL);

        $client = $this->clientService->newClient();

        try {
            $response = $client->post($url, [
                'body' => [
                    'client_id' => $this->getClientId(),
                    'client_secret' => $this->getClientSecret(),
                    'code' => $code,
                    'redirect_uri' => $this->getRedirectUri(),
                    'grant_type' => 'authorization_code',
                    'scope' => implode(' ', self::SCOPES),
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if (isset($body['error'])) {
                $error = $body['error'] ?? 'Unknown error';
                $errorDesc = $body['error_description'] ?? '';
                $this->logger->error('Token exchange failed', [
                    'error' => $error,
                    'description' => $errorDesc,
                ]);
                throw new \RuntimeException("Token exchange failed: $error - $errorDesc");
            }

            return [
                'access_token' => $body['access_token'],
                'refresh_token' => $body['refresh_token'] ?? '',
                'expires_in' => (int) ($body['expires_in'] ?? 3600),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Token exchange error', ['exception' => $e]);
            throw new \RuntimeException('Failed to exchange code for token: ' . $e->getMessage());
        }
    }

    public function refreshToken(string $refreshToken): array
    {
        $url = str_replace('{tenant}', $this->getTenantId(), self::OAUTH_TOKEN_URL);

        $client = $this->clientService->newClient();

        try {
            $response = $client->post($url, [
                'body' => [
                    'client_id' => $this->getClientId(),
                    'client_secret' => $this->getClientSecret(),
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                    'scope' => implode(' ', self::SCOPES),
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if (isset($body['error'])) {
                $error = $body['error'] ?? 'Unknown error';
                $errorDesc = $body['error_description'] ?? '';
                $this->logger->error('Token refresh failed', [
                    'error' => $error,
                    'description' => $errorDesc,
                ]);
                throw new \RuntimeException("Token refresh failed: $error - $errorDesc");
            }

            return [
                'access_token' => $body['access_token'],
                'refresh_token' => $body['refresh_token'] ?? $refreshToken,
                'expires_in' => (int) ($body['expires_in'] ?? 3600),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Token refresh error', ['exception' => $e]);
            throw new \RuntimeException('Failed to refresh token: ' . $e->getMessage());
        }
    }

    /**
     * Get a valid access token for a user, refreshing if needed
     */
    public function getValidAccessToken(string $userId): ?string
    {
        $accessToken = $this->getUserAccessToken($userId);
        if ($accessToken === null) {
            return null;
        }

        $expiresAt = (int) $this->config->getUserValue($userId, Application::APP_ID, 'ms_token_expires', '0');

        // Refresh if expired or expiring within 5 minutes
        if ($expiresAt < time() + 300) {
            $refreshToken = $this->getUserRefreshToken($userId);
            if (empty($refreshToken)) {
                $this->deleteUserTokens($userId);
                return null;
            }

            try {
                $newTokenData = $this->refreshToken($refreshToken);
                $this->saveUserTokens(
                    $userId,
                    $newTokenData['access_token'],
                    $newTokenData['refresh_token'],
                    $newTokenData['expires_in']
                );
                return $newTokenData['access_token'];
            } catch (\Exception $e) {
                $this->logger->error('Failed to refresh token', [
                    'userId' => $userId,
                    'exception' => $e,
                ]);
                $this->deleteUserTokens($userId);
                return null;
            }
        }

        return $accessToken;
    }

    /**
     * Save tokens for a user
     */
    public function saveUserTokens(string $userId, string $accessToken, string $refreshToken, int $expiresIn): void
    {
        $expiresAt = time() + $expiresIn;

        $this->config->setUserValue(
            $userId,
            Application::APP_ID,
            'ms_access_token',
            $this->crypto->encrypt($accessToken)
        );
        $this->config->setUserValue(
            $userId,
            Application::APP_ID,
            'ms_refresh_token',
            $this->crypto->encrypt($refreshToken)
        );
        $this->config->setUserValue(
            $userId,
            Application::APP_ID,
            'ms_token_expires',
            (string) $expiresAt
        );
    }

    /**
     * Get the user's access token
     */
    public function getUserAccessToken(string $userId): ?string
    {
        $encrypted = $this->config->getUserValue($userId, Application::APP_ID, 'ms_access_token', '');
        if (empty($encrypted)) {
            return null;
        }
        try {
            return $this->crypto->decrypt($encrypted);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the user's refresh token
     */
    public function getUserRefreshToken(string $userId): ?string
    {
        $encrypted = $this->config->getUserValue($userId, Application::APP_ID, 'ms_refresh_token', '');
        if (empty($encrypted)) {
            return null;
        }
        try {
            return $this->crypto->decrypt($encrypted);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if user is connected to Microsoft
     */
    public function isUserConnected(string $userId): bool
    {
        return $this->getUserAccessToken($userId) !== null;
    }

    /**
     * Delete user tokens (disconnect)
     */
    public function deleteUserTokens(string $userId): void
    {
        $this->config->deleteUserValue($userId, Application::APP_ID, 'ms_access_token');
        $this->config->deleteUserValue($userId, Application::APP_ID, 'ms_refresh_token');
        $this->config->deleteUserValue($userId, Application::APP_ID, 'ms_token_expires');
        $this->config->deleteUserValue($userId, Application::APP_ID, 'oauth_state');
        $this->config->deleteUserValue($userId, Application::APP_ID, 'oauth_state_time');
    }

    /**
     * Generate OAuth state for CSRF protection
     */
    private function generateState(string $userId): string
    {
        $state = $this->secureRandom->generate(32);
        $this->config->setUserValue($userId, Application::APP_ID, 'oauth_state', $state);
        $this->config->setUserValue($userId, Application::APP_ID, 'oauth_state_time', (string) time());
        return $state;
    }

    /**
     * Validate OAuth state
     */
    public function validateState(string $userId, string $state): bool
    {
        $storedState = $this->config->getUserValue($userId, Application::APP_ID, 'oauth_state', '');
        $stateTime = (int) $this->config->getUserValue($userId, Application::APP_ID, 'oauth_state_time', '0');

        // Clean up
        $this->config->deleteUserValue($userId, Application::APP_ID, 'oauth_state');
        $this->config->deleteUserValue($userId, Application::APP_ID, 'oauth_state_time');

        // State expires after 10 minutes
        if (time() - $stateTime > 600) {
            return false;
        }

        return hash_equals($storedState, $state);
    }
}
