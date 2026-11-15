<?php

declare(strict_types=1);

/**
 * One process of ConcurrentSubmissionsIntegrationTest: a respondent who opens the form and submits ('submit'),
 * or a visitor who only opens it ('read'), $count times, through the public path. Prints what happened as JSON.
 *
 * Usage: php concurrent-worker.php submit|read <fileId> <count>
 */

use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCP\App\IAppManager;
use OCP\Server;

[, $mode, $fileId, $count] = $argv;
require dirname(__DIR__, 5) . '/lib/base.php';
Server::get(IAppManager::class)->loadApp('formvox');

$result = ['ok' => 0, 'failed' => []];
for ($i = 0; $i < (int)$count; $i++) {
	try {
		Server::get(FormRepository::class)->loadPublic((int)$fileId);
		if ($mode === 'submit') {
			Server::get(ResponsePersistenceService::class)->appendResponsePublic((int)$fileId, [
				'id' => bin2hex(random_bytes(16)), 'submitted_at' => date('c'), 'respondent' => ['type' => 'anonymous'], 'answers' => ['q1' => "answer $i"],
			]);
		}
		$result['ok']++;
	} catch (\Throwable $e) {
		$result['failed'][] = get_class($e) . ': ' . substr($e->getMessage(), 0, 160);
	}
}
echo json_encode($result);
