<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Store;

use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\ResponseStore;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Server;

/**
 * Respondents at once on one form in the database, each its own process as on a real server: every accepted response
 * is stored exactly once, the limit and a limited option hold exactly, and nobody gets an error.
 *
 * @group DB
 */
class ConcurrentStoredSubmissionsIntegrationTest extends IntegrationTestCase {
	private const PROCESSES = 6;
	private const EACH = 10;
	private const LIMIT = 40;
	private const CAPACITY = 10;

	/** The last place: respondents who all start at the very same moment, and only one gets it. */
	public function testOnlyOneGetsTheLastPlace(): void {
		$fileId = $this->form(1, 1000);

		[$accepted, $failed] = $this->race($fileId, 8, 1);

		$this->assertSame([], array_count_values($failed), 'Errors while others submitted');
		$this->assertCount(1, $accepted);
		$this->assertCount(1, Server::get(ResponseStore::class)->all(Server::get(FormStore::class)->byFileId($fileId)->id));
	}

	public function testTheLimitsHoldExactlyWithRespondentsAtOnce(): void {
		$repository = Server::get(FormRepository::class);
		$fileId = $this->form(self::LIMIT, self::CAPACITY);

		[$accepted, $failed] = $this->race($fileId, self::PROCESSES, self::EACH);

		$this->assertSame([], array_count_values($failed), 'Errors while others submitted');
		$this->assertCount(self::LIMIT, $accepted, 'Exactly as many as the limit');
		$stored = Server::get(FormStore::class)->byFileId($fileId);
		$responses = Server::get(ResponseStore::class)->all($stored->id);
		$this->assertEqualsCanonicalizing($accepted, array_column($responses, 'id'), 'Every accepted response stored once, nothing else');
		$this->assertSame(self::LIMIT, $stored->count);
		$limited = count(array_filter($responses, fn (array $r) => $r['answers']['slot'] === 'opt1'));
		$this->assertLessThanOrEqual(self::CAPACITY, $limited, 'The limited option never overfilled');
		$counts = $repository->loadPublic($fileId)['_index']['answer_counts']['slot'];
		ksort($counts);
		$this->assertSame(['opt1' => $limited, 'opt2' => self::LIMIT - $limited], $counts, 'Counters match what is stored');
	}

	private function form(int $limit, int $capacity): int {
		$repository = Server::get(FormRepository::class);
		$fileId = (int)$repository->create('Concurrent stored ' . uniqid())['fileId'];
		$repository->update($fileId, [
			'questions' => [
				['id' => 'q1', 'type' => 'text', 'question' => 'Name'],
				['id' => 'slot', 'type' => 'choice', 'question' => 'Slot', 'options' => [['id' => 'opt1', 'label' => 'Limited', 'capacity' => $capacity], ['id' => 'opt2', 'label' => 'Open']]],
			],
			'settings' => ['anonymous' => true, 'allow_multiple' => true, 'max_responses' => $limit],
		]);
		$this->logout();
		return $fileId;
	}

	/**
	 * Starts the respondents, waits until each is ready, then lets them all go at once.
	 *
	 * @return array{0: list<string>, 1: list<string>} the accepted response ids, and the errors
	 */
	private function race(int $fileId, int $processes, int $each): array {
		$flags = sys_get_temp_dir() . '/formvox-race-' . bin2hex(random_bytes(6));
		mkdir($flags);
		$workers = [];
		for ($i = 0; $i < $processes; $i++) {
			$process = proc_open([PHP_BINARY, __DIR__ . '/stored-worker.php', (string)$fileId, (string)$each, (string)$i, $flags], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
			$this->assertIsResource($process);
			$workers[] = [$process, $pipes[1]];
		}
		$deadline = microtime(true) + 60;
		while (count(glob($flags . '/ready-*') ?: []) < $processes && microtime(true) < $deadline) {
			usleep(10000);
		}
		touch($flags . '/go');
		$accepted = [];
		$failed = [];
		foreach ($workers as [$process, $out]) {
			$result = json_decode(stream_get_contents($out) ?: 'null', true);
			proc_close($process);
			$this->assertIsArray($result, 'A worker did not finish');
			$accepted = [...$accepted, ...$result['ok']];
			$failed = [...$failed, ...$result['failed']];
		}
		array_map('unlink', glob($flags . '/*') ?: []);
		rmdir($flags);
		return [$accepted, $failed];
	}
}
