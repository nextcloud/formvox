<?php

declare(strict_types=1);

/**
 * One respondent process of ConcurrentStoredSubmissionsIntegrationTest: submits $count times through the public path,
 * half of them choosing the limited option. Prints what happened as JSON.
 *
 * Usage: php stored-worker.php <fileId> <count> <worker> <flags>; it says it is ready in <flags>/ready-<worker> and
 * starts once <flags>/go exists, so all respondents start at the same moment.
 */

use OCA\FormVox\Service\InvalidAnswerException;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\ResponseRefusedException;
use OCP\App\IAppManager;
use OCP\Server;

[, $fileId, $count, $worker, $flags] = $argv;
require dirname(__DIR__, 5) . '/lib/base.php';
Server::get(IAppManager::class)->loadApp('formvox');
Server::get(ResponsePersistenceService::class);
touch($flags . '/ready-' . $worker);
while (!file_exists($flags . '/go')) {
	usleep(1000);
}

$result = ['ok' => [], 'full' => 0, 'taken' => 0, 'failed' => []];
for ($i = 0; $i < (int)$count; $i++) {
	$id = bin2hex(random_bytes(16));
	try {
		Server::get(ResponsePersistenceService::class)->appendResponsePublic((int)$fileId, [
			'id' => $id, 'submitted_at' => date('c'),
			'respondent' => ['type' => 'anonymous', 'fingerprint' => 'v2:' . bin2hex(random_bytes(32))],
			'answers' => ['q1' => "worker $worker answer $i", 'slot' => $i % 2 === 0 ? 'opt1' : 'opt2'],
		]);
		$result['ok'][] = $id;
	} catch (ResponseRefusedException) {
		$result['full']++;
	} catch (InvalidAnswerException) {
		$result['taken']++;
	} catch (\Throwable $e) {
		$result['failed'][] = get_class($e) . ': ' . substr($e->getMessage(), 0, 160);
	}
}
echo json_encode($result);
