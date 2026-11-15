<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Lock;

use OCA\FormVox\Service\FormFactory;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;

/**
 * Respondents at once on one form, each its own process as on a real server: every response is stored exactly
 * once, and nobody is turned away because someone else was reading or writing the form at that moment.
 * The heavy measurement runs on demand (tests/Load/load_test.py); this one guards correctness in CI.
 *
 * @group DB
 */
class ConcurrentSubmissionsIntegrationTest extends IntegrationTestCase {
	private const SUBMITTERS = 4;
	private const SUBMISSIONS = 8;
	private const READERS = 2;
	private const READS = 30;

	public function testRespondentsAtOnceAreAllStoredAndNobodyIsTurnedAway(): void {
		/** @var FormFactory $factory */
		$factory = $this->getService(FormFactory::class);
		$settings = $factory->createFormStructure('Concurrent', $this->ownerUid)['settings'];
		$fileId = $this->writeFormFile($this->userFolder, 'Concurrent Form', [
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
			'settings' => ['public_token' => 'concurrent', 'anonymous' => true, 'allow_multiple' => true] + $settings,
		])->getId();
		$this->logout();

		$workers = [];
		foreach ([['submit', self::SUBMITTERS, self::SUBMISSIONS], ['read', self::READERS, self::READS]] as [$mode, $processes, $count]) {
			for ($i = 0; $i < $processes; $i++) {
				$workers[] = $this->start($mode, $fileId, $count);
			}
		}
		$failed = [];
		$accepted = 0;
		foreach ($workers as [$mode, $process, $out]) {
			$result = json_decode(stream_get_contents($out) ?: 'null', true);
			proc_close($process);
			$this->assertIsArray($result, "A $mode worker did not finish");
			$failed = [...$failed, ...$result['failed']];
			$accepted += $mode === 'submit' ? $result['ok'] : 0;
		}

		$this->assertSame([], array_count_values($failed), 'Turned away while others read or wrote the form');
		/** @var FormRepository $repository */
		$repository = $this->getService(FormRepository::class);
		$form = $repository->loadPublic($fileId);
		// Through the public path too: from the database, where the first submission moved the form.
		$responses = $repository->page($fileId, $form)['responses'];
		$this->assertCount($accepted, $responses, 'Every accepted response is stored');
		$this->assertCount($accepted, array_unique(array_column($responses, 'id')), 'and stored once');
		$this->assertSame($accepted, $form['_index']['response_count'], 'and counted once');
	}

	/** @return array{string, resource, resource} */
	private function start(string $mode, int $fileId, int $count): array {
		$process = proc_open([PHP_BINARY, __DIR__ . '/concurrent-worker.php', $mode, (string)$fileId, (string)$count], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
		$this->assertIsResource($process);
		return [$mode, $process, $pipes[1]];
	}
}
