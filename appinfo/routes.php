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
        ['name' => 'public#showResults', 'url' => '/public/{fileId}/{token}/results', 'verb' => 'GET'],

        // API routes - Forms
        ['name' => 'api#list', 'url' => '/api/forms', 'verb' => 'GET'],
        ['name' => 'api#create', 'url' => '/api/forms', 'verb' => 'POST'],
        ['name' => 'api#get', 'url' => '/api/form/{fileId}', 'verb' => 'GET'],
        ['name' => 'api#update', 'url' => '/api/form/{fileId}', 'verb' => 'PUT'],
        ['name' => 'api#delete', 'url' => '/api/form/{fileId}', 'verb' => 'DELETE'],

        // API routes - Responses
        ['name' => 'api#getResponses', 'url' => '/api/form/{fileId}/responses', 'verb' => 'GET'],
        ['name' => 'api#deleteResponse', 'url' => '/api/form/{fileId}/responses/{responseId}', 'verb' => 'DELETE'],

        // API routes - Export
        ['name' => 'api#exportCsv', 'url' => '/api/form/{fileId}/export/csv', 'verb' => 'GET'],
        ['name' => 'api#exportJson', 'url' => '/api/form/{fileId}/export/json', 'verb' => 'GET'],
        ['name' => 'api#exportExcel', 'url' => '/api/form/{fileId}/export/xlsx', 'verb' => 'GET'],

        // API routes - Index management
        ['name' => 'api#rebuildIndex', 'url' => '/api/form/{fileId}/rebuild-index', 'verb' => 'POST'],

        // Branding routes (admin only, except images which are public)
        ['name' => 'branding#get', 'url' => '/api/branding', 'verb' => 'GET'],
        ['name' => 'branding#saveLayout', 'url' => '/api/branding/layout', 'verb' => 'PUT'],
        ['name' => 'branding#saveStyles', 'url' => '/api/branding/styles', 'verb' => 'PUT'],
        ['name' => 'branding#uploadBlockImage', 'url' => '/api/branding/image/{blockId}', 'verb' => 'POST'],
        ['name' => 'branding#deleteBlockImage', 'url' => '/api/branding/image/{blockId}', 'verb' => 'DELETE'],
        ['name' => 'branding#blockImage', 'url' => '/branding/image/{blockId}', 'verb' => 'GET'],
    ],
];
