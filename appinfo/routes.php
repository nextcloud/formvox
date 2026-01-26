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
    ],
];
