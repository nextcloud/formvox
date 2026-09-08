<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Response;

use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Integration coverage for the append side of ResponsePersistenceService,
 * exercised against a real Nextcloud + database + filesystem: real response
 * appends, real IndexService rebuilds, the real preferences-table lock, and the
 * real upload cascade on delete.
 *
 * @group DB
 */
class ResponsePersistenceIntegrationTest extends IntegrationTestCase {
	private function service(): ResponsePersistenceService {
		/** @var ResponsePersistenceService $svc */
		$svc = $this->getService(ResponsePersistenceService::class);
		return $svc;
	}

	private function db(): IDBConnection {
		return Server::get(IDBConnection::class);
	}

	/**
	 * Count live FormVox lock rows for a given file id in the preferences table.
	 * The lock key is 'formvox_response_' . fileId (see FormLockManager).
	 */
	private function lockRowCount(int $fileId): int {
		$qb = $this->db()->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter('__formvox_lock__')))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('formvox')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('formvox_response_' . $fileId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	public function testAppendResponsePersists(): void {
		$file = $this->writeFormFile($this->userFolder, 'Append Persists');
		$fileId = $file->getId();

		$response = [
			'id' => 'r1',
			'submitted_at' => date('c'),
			'answers' => [],
		];

		$returned = $this->service()->appendResponse($fileId, $response);
		$this->assertSame('r1', $returned['id']);

		$stored = $this->readFormFromStorage($file);
		$this->assertArrayHasKey('responses', $stored);
		$this->assertCount(1, $stored['responses']);
		$this->assertSame('r1', $stored['responses'][0]['id']);
		$this->assertSame(1, $stored['_index']['response_count']);

		// Lock must be released after the write completes.
		$this->assertSame(0, $this->lockRowCount($fileId));
	}

	public function testAppendResponsePublicNoSession(): void {
		// Form lives in the owner's home storage; write it while logged in.
		$file = $this->writeFormFile($this->userFolder, 'Public Append');
		$fileId = $file->getId();

		// Prove the public/no-session path: drop the session entirely.
		$this->logout();

		$response = [
			'id' => 'pub1',
			'submitted_at' => date('c'),
			'answers' => [],
		];

		$returned = $this->service()->appendResponsePublic($fileId, $response);
		$this->assertSame('pub1', $returned['id']);

		$stored = $this->readFormFromStorage($file);
		$this->assertCount(1, $stored['responses']);
		$this->assertSame('pub1', $stored['responses'][0]['id']);
		$this->assertSame(1, $stored['_index']['response_count']);
		$this->assertSame(0, $this->lockRowCount($fileId));
	}

	public function testGuardAbortsAppend(): void {
		$file = $this->writeFormFile($this->userFolder, 'Guard Aborts');
		$fileId = $file->getId();

		$this->logout();

		$guard = function (array $form): void {
			throw new \RuntimeException('nope');
		};

		$threw = false;
		try {
			$this->service()->appendResponsePublic($fileId, [
				'id' => 'blocked',
				'submitted_at' => date('c'),
				'answers' => [],
			], $guard);
		} catch (\RuntimeException $e) {
			$threw = true;
			$this->assertSame('nope', $e->getMessage());
		}
		$this->assertTrue($threw, 'Guard should have aborted the append by throwing');

		// Nothing appended: responses stays empty and count stays 0.
		$stored = $this->readFormFromStorage($file);
		$this->assertSame([], $stored['responses']);
		$this->assertSame(0, $stored['_index']['response_count']);

		// Lock released even though the mutator threw (finally block).
		$this->assertSame(0, $this->lockRowCount($fileId));
	}

	public function testDeleteResponseCascadesUploads(): void {
		$file = $this->writeFormFile($this->userFolder, 'Delete Cascade');
		$fileId = $file->getId();

		$responseId = 'resp-with-upload';

		// Store a real upload in the form's hidden uploads folder.
		/** @var UploadService $uploadService */
		$uploadService = $this->getService(UploadService::class);
		$uploaded = $this->makeUploadedFile('attachment.txt', 'hello upload', 'text/plain');
		$meta = $uploadService->storeUpload($fileId, $responseId, $uploaded);
		$this->assertSame($responseId, $meta['responseId']);
		$this->assertArrayHasKey('filename', $meta);

		// The upload subfolder must exist on disk now.
		$uploadsFolder = $uploadService->getUploadsFolder($fileId, true);
		$this->assertTrue($uploadsFolder->nodeExists($responseId), 'Upload subfolder should exist before delete');

		// Append a response whose answer references that upload, in the shape
		// extractFileResponseIds() understands (single file: has 'responseId').
		$response = [
			'id' => $responseId,
			'submitted_at' => date('c'),
			'answers' => [
				'q_file' => [
					'responseId' => $responseId,
					'filename' => $meta['filename'],
					'originalName' => $meta['originalName'],
					'fileId' => $meta['fileId'],
					'size' => $meta['size'],
					'mimeType' => $meta['mimeType'],
				],
			],
		];
		$this->service()->appendResponse($fileId, $response);

		$stored = $this->readFormFromStorage($file);
		$this->assertCount(1, $stored['responses']);

		// Delete the response — must cascade and remove the upload subfolder.
		$this->service()->deleteResponse($fileId, $responseId);

		$storedAfter = $this->readFormFromStorage($file);
		$this->assertSame([], $storedAfter['responses'], 'Response should be gone after delete');
		$this->assertSame(0, $storedAfter['_index']['response_count']);

		// Re-resolve the uploads folder and confirm the subfolder is gone.
		$uploadsFolderAfter = $uploadService->getUploadsFolder($fileId, true);
		$this->assertFalse(
			$uploadsFolderAfter->nodeExists($responseId),
			'Upload subfolder should be deleted when its response is deleted'
		);

		$this->assertSame(0, $this->lockRowCount($fileId));
	}

	public function testSavePublicReplacesResponses(): void {
		// Seed the form with an existing response via overrides.
		$file = $this->writeFormFile($this->userFolder, 'Save Public Replace', [
			'responses' => [
				['id' => 'old', 'submitted_at' => date('c'), 'answers' => []],
			],
		]);
		$fileId = $file->getId();

		$this->logout();

		// savePublic replaces the whole responses array from the external API.
		$this->service()->savePublic($fileId, [
			'responses' => [
				['id' => 'new', 'submitted_at' => date('c'), 'answers' => []],
			],
		]);

		$stored = $this->readFormFromStorage($file);
		$this->assertCount(1, $stored['responses']);
		$this->assertSame('new', $stored['responses'][0]['id'], 'Responses should be fully replaced');

		// Index rebuilt against the replaced responses.
		$this->assertSame(1, $stored['_index']['response_count']);
		$this->assertSame(
			hash('sha256', json_encode($stored['responses'])),
			$stored['_index']['_checksum'],
			'Index checksum should match the replaced responses'
		);

		$this->assertSame(0, $this->lockRowCount($fileId));
	}
}
