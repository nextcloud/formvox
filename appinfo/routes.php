<?php

declare(strict_types=1);

return [
    'routes' => [
        // Page routes
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#editor', 'url' => '/edit/{fileId}', 'verb' => 'GET'],
        ['name' => 'page#respond', 'url' => '/respond/{fileId}', 'verb' => 'GET'],
        ['name' => 'page#results', 'url' => '/results/{fileId}', 'verb' => 'GET'],

        // Public routes (anonymous access via share token)
        ['name' => 'public#showForm', 'url' => '/public/{token}', 'verb' => 'GET'],
        ['name' => 'public#authenticate', 'url' => '/public/{token}', 'verb' => 'POST'],
        ['name' => 'public#submit', 'url' => '/public/{token}/submit', 'verb' => 'POST'],
        ['name' => 'public#showResults', 'url' => '/public/{token}/results', 'verb' => 'GET'],

        // API routes - Forms
        ['name' => 'api#list', 'url' => '/api/forms', 'verb' => 'GET'],
        ['name' => 'api#create', 'url' => '/api/forms', 'verb' => 'POST'],
        ['name' => 'api#get', 'url' => '/api/form/{fileId}', 'verb' => 'GET'],
        ['name' => 'api#update', 'url' => '/api/form/{fileId}', 'verb' => 'PUT'],
        ['name' => 'api#delete', 'url' => '/api/form/{fileId}', 'verb' => 'DELETE'],

        // API routes - Responses
        ['name' => 'api#respond', 'url' => '/api/form/{fileId}/respond', 'verb' => 'POST'],
        ['name' => 'api#getResponses', 'url' => '/api/form/{fileId}/responses', 'verb' => 'GET'],
        ['name' => 'api#deleteResponse', 'url' => '/api/form/{fileId}/responses/{responseId}', 'verb' => 'DELETE'],

        // API routes - Export
        ['name' => 'api#exportCsv', 'url' => '/api/form/{fileId}/export/csv', 'verb' => 'GET'],
        ['name' => 'api#exportJson', 'url' => '/api/form/{fileId}/export/json', 'verb' => 'GET'],
        ['name' => 'api#exportExcel', 'url' => '/api/form/{fileId}/export/xlsx', 'verb' => 'GET'],

        // API routes - Index management
        ['name' => 'api#rebuildIndex', 'url' => '/api/form/{fileId}/rebuild-index', 'verb' => 'POST'],
    ],
];
