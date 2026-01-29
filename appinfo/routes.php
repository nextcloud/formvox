<?php

declare(strict_types=1);

return [
    'routes' => [
        // Page routes
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#editor', 'url' => '/edit/{fileId}', 'verb' => 'GET'],
        ['name' => 'page#results', 'url' => '/results/{fileId}', 'verb' => 'GET'],

        // Public routes (anonymous access via fileId + token)
        ['name' => 'public#showForm', 'url' => '/public/{fileId}/{token}', 'verb' => 'GET'],
        ['name' => 'public#authenticate', 'url' => '/public/{fileId}/{token}', 'verb' => 'POST'],
        ['name' => 'public#submit', 'url' => '/public/{fileId}/{token}/submit', 'verb' => 'POST'],
        ['name' => 'public#uploadFile', 'url' => '/public/{fileId}/{token}/upload', 'verb' => 'POST'],

        // Embed routes (frameable version for iframes)
        ['name' => 'public#embedForm', 'url' => '/embed/{fileId}/{token}', 'verb' => 'GET'],
        ['name' => 'public#embedAuthenticate', 'url' => '/embed/{fileId}/{token}', 'verb' => 'POST'],

        // API routes - Forms
        ['name' => 'api#list', 'url' => '/api/forms', 'verb' => 'GET'],
        ['name' => 'api#create', 'url' => '/api/forms', 'verb' => 'POST'],
        ['name' => 'api#get', 'url' => '/api/form/{fileId}', 'verb' => 'GET'],
        ['name' => 'api#update', 'url' => '/api/form/{fileId}', 'verb' => 'PUT'],
        ['name' => 'api#delete', 'url' => '/api/form/{fileId}', 'verb' => 'DELETE'],
        ['name' => 'api#setFavorite', 'url' => '/api/form/{fileId}/favorite', 'verb' => 'POST'],

        // API routes - Responses
        ['name' => 'api#getResponses', 'url' => '/api/form/{fileId}/responses', 'verb' => 'GET'],
        ['name' => 'api#deleteAllResponses', 'url' => '/api/form/{fileId}/responses', 'verb' => 'DELETE'],
        ['name' => 'api#deleteResponse', 'url' => '/api/form/{fileId}/responses/{responseId}', 'verb' => 'DELETE'],

        // API routes - Export
        ['name' => 'api#exportCsv', 'url' => '/api/form/{fileId}/export/csv', 'verb' => 'GET'],
        ['name' => 'api#exportJson', 'url' => '/api/form/{fileId}/export/json', 'verb' => 'GET'],
        ['name' => 'api#exportExcel', 'url' => '/api/form/{fileId}/export/xlsx', 'verb' => 'GET'],

        // API routes - Index management
        ['name' => 'api#rebuildIndex', 'url' => '/api/form/{fileId}/rebuild-index', 'verb' => 'POST'],

        // API routes - File permissions
        ['name' => 'file_permission#getPermissions', 'url' => '/api/permissions/{fileId}', 'verb' => 'GET'],

        // API routes - User/Group search for access restrictions
        ['name' => 'api#searchSharees', 'url' => '/api/sharees', 'verb' => 'GET'],

        // API routes - File uploads
        ['name' => 'api#downloadUpload', 'url' => '/api/form/{fileId}/uploads/{responseId}/{filename}', 'verb' => 'GET'],
        ['name' => 'api#downloadAllUploads', 'url' => '/api/form/{fileId}/uploads', 'verb' => 'GET'],

        // Branding routes (admin only, except images which are public)
        ['name' => 'branding#get', 'url' => '/api/branding', 'verb' => 'GET'],
        ['name' => 'branding#saveLayout', 'url' => '/api/branding/layout', 'verb' => 'PUT'],
        ['name' => 'branding#saveStyles', 'url' => '/api/branding/styles', 'verb' => 'PUT'],
        ['name' => 'branding#uploadBlockImage', 'url' => '/api/branding/image/{blockId}', 'verb' => 'POST'],
        ['name' => 'branding#deleteBlockImage', 'url' => '/api/branding/image/{blockId}', 'verb' => 'DELETE'],
        ['name' => 'branding#blockImage', 'url' => '/branding/image/{blockId}', 'verb' => 'GET'],

        // Statistics routes (admin only)
        ['name' => 'statistics#getStatistics', 'url' => '/api/statistics', 'verb' => 'GET'],
        ['name' => 'statistics#getTelemetry', 'url' => '/api/statistics/telemetry', 'verb' => 'GET'],
        ['name' => 'statistics#setTelemetry', 'url' => '/api/statistics/telemetry', 'verb' => 'POST'],
        ['name' => 'statistics#sendTelemetry', 'url' => '/api/statistics/telemetry/send', 'verb' => 'POST'],

        // Settings routes (admin only)
        ['name' => 'settings#saveEmbed', 'url' => '/api/settings/embed', 'verb' => 'POST'],

        // API key management (authenticated users)
        ['name' => 'integration#createApiKey', 'url' => '/api/form/{fileId}/api-keys', 'verb' => 'POST'],
        ['name' => 'integration#deleteApiKey', 'url' => '/api/form/{fileId}/api-keys/{keyId}', 'verb' => 'DELETE'],
        ['name' => 'integration#getApiPermissions', 'url' => '/api/integration/permissions', 'verb' => 'GET'],

        // Webhook management (authenticated users)
        ['name' => 'integration#createWebhook', 'url' => '/api/form/{fileId}/webhooks', 'verb' => 'POST'],
        ['name' => 'integration#updateWebhook', 'url' => '/api/form/{fileId}/webhooks/{webhookId}', 'verb' => 'PUT'],
        ['name' => 'integration#deleteWebhook', 'url' => '/api/form/{fileId}/webhooks/{webhookId}', 'verb' => 'DELETE'],
        ['name' => 'integration#getWebhookEvents', 'url' => '/api/integration/webhook-events', 'verb' => 'GET'],

        // External API v1 (API key authenticated)
        ['name' => 'external_api#getForm', 'url' => '/api/v1/external/forms/{fileId}', 'verb' => 'GET'],
        ['name' => 'external_api#getSchema', 'url' => '/api/v1/external/forms/{fileId}/schema', 'verb' => 'GET'],
        ['name' => 'external_api#getResponses', 'url' => '/api/v1/external/forms/{fileId}/responses', 'verb' => 'GET'],
        ['name' => 'external_api#getResponse', 'url' => '/api/v1/external/forms/{fileId}/responses/{responseId}', 'verb' => 'GET'],
        ['name' => 'external_api#createResponse', 'url' => '/api/v1/external/forms/{fileId}/responses', 'verb' => 'POST'],
        ['name' => 'external_api#updateResponse', 'url' => '/api/v1/external/forms/{fileId}/responses/{responseId}', 'verb' => 'PUT'],
        ['name' => 'external_api#deleteResponse', 'url' => '/api/v1/external/forms/{fileId}/responses/{responseId}', 'verb' => 'DELETE'],
    ],
];
