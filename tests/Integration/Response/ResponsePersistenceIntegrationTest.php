<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Response;

use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\InvalidAnswerException;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\ResponseRefusedException;
use OCA\FormVox\Service\ResponseService;
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

	/** The form with its responses, from the database the first write moved them into. */
	private function load(int $fileId): array {
		/** @var FormRepository $forms */
		$forms = $this->getService(FormRepository::class);
		return $forms->load($fileId);
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

		$returned = $this->service()->append($file, $response);
		$this->assertSame('r1', $returned['id']);

		$stored = $this->load($fileId);
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

		// Read back without a session too.
		/** @var FormRepository $forms */
		$forms = $this->getService(FormRepository::class);
		$form = $forms->loadPublic($fileId);
		$this->assertSame(['pub1'], array_column($forms->page($fileId, $form)['responses'], 'id'));
		$this->assertSame(1, $form['_index']['response_count']);
		$this->assertSame(0, $this->lockRowCount($fileId));
	}

	/** The form's rules are applied under the lock on the stored form, whatever the caller checked before (H8, Q7). */
	public function testAClosedFormRefusesAResponseUnderTheLock(): void {
		$file = $this->writeFormFile($this->userFolder, 'Closed Form', ['settings' => ['share_expires_at' => '2001-01-01T00:00:00Z']]);
		$fileId = $file->getId();

		$this->logout();

		try {
			$this->service()->appendResponsePublic($fileId, ['id' => 'late', 'submitted_at' => date('c'), 'answers' => []]);
			$this->fail('A closed form took a response');
		} catch (ResponseRefusedException $e) {
			$this->assertSame('This form has expired', $e->getMessage());
		}

		$forms = $this->getService(FormRepository::class);
		$form = $forms->loadPublic($fileId);
		$this->assertSame([], $forms->page($fileId, $form)['responses']);
		$this->assertSame(0, $form['_index']['response_count']);
		// Lock released even though the rules refused (finally block).
		$this->assertSame(0, $this->lockRowCount($fileId));
	}

	public function testDeleteResponseCascadesUploads(): void {
		$file = $this->writeFormFile($this->userFolder, 'Delete Cascade', ['questions' => [['id' => 'q_file', 'type' => 'file']]]);
		$fileId = $file->getId();

		$responseId = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';

		// Store a real upload in the form's hidden uploads folder.
		/** @var UploadService $uploadService */
		$uploadService = $this->getService(UploadService::class);
		$uploaded = $this->makeUploadedFile('attachment.txt', 'hello upload', 'text/plain');
		$meta = $uploadService->storeUploadPublic($fileId, $responseId, $uploaded);
		$this->assertSame($responseId, $meta['responseId']);
		$this->assertArrayHasKey('filename', $meta);

		// The upload subfolder must exist on disk now.
		$uploadsFolder = $uploadService->getUploadsFolder($file, true);
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
		$this->service()->append($file, $response);

		$this->assertCount(1, $this->load($fileId)['responses']);
		// The append moved the form into the database, its uploads into app data.
		$this->assertTrue($uploadService->getUploadsFolder($file)->nodeExists($responseId), 'Upload subfolder should have moved along');

		// Delete the response — must cascade and remove the upload subfolder.
		$this->service()->delete($file, $responseId);

		$storedAfter = $this->load($fileId);
		$this->assertSame([], $storedAfter['responses'], 'Response should be gone after delete');
		$this->assertSame(0, $storedAfter['_index']['response_count']);

		// Re-resolve the uploads folder and confirm the subfolder is gone.
		$uploadsFolderAfter = $uploadService->getUploadsFolder($file, true);
		$this->assertFalse(
			$uploadsFolderAfter->nodeExists($responseId),
			'Upload subfolder should be deleted when its response is deleted'
		);

		$this->assertSame(0, $this->lockRowCount($fileId));
	}

	/**
	 * A respondent writes what an answer says; deleting their spam must not take others' uploads (H5, T10).
	 */
	public function testDeletingOneResponseNeverRemovesAnotherResponsesUploads(): void {
		$file = $this->writeFormFile($this->userFolder, 'Delete Spam', ['questions' => [['id' => 'q_file', 'type' => 'file']]]);
		$fileId = $file->getId();
		/** @var UploadService $uploadService */
		$uploadService = $this->getService(UploadService::class);
		$uploadsFolder = $uploadService->getUploadsFolder($file, true);
		$kept = $uploadsFolder->newFolder('5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f');
		$kept->newFile('cv.pdf')->putContent('from another respondent');

		foreach (['', '/', '..', '../..', '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f/../..'] as $i => $claimed) {
			$this->service()->append($file, [
				'id' => 'spam-' . $i,
				'submitted_at' => date('c'),
				'answers' => ['q_file' => ['responseId' => $claimed, 'filename' => 'x']],
			]);
			$this->service()->delete($file, 'spam-' . $i);

			$this->assertTrue($uploadService->getUploadsFolder($file, true)->nodeExists('5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f/cv.pdf'), "after deleting a response claiming '$claimed'");
		}
		$this->assertSame(0, $this->lockRowCount($fileId));
	}

	/**
	 * The external API works from a form it read earlier; what arrived or was deleted since must survive (H8, T3).
	 */
	public function testAnApiWriteFromAStaleReadLosesNothingAndResurrectsNothing(): void {
		$file = $this->writeFormFile($this->userFolder, 'No Lost Update', [
			'settings' => ['allow_multiple' => true],
			'questions' => [['id' => 'q', 'type' => 'text', 'question' => 'Q']],
			'responses' => [
				['id' => 'keep', 'submitted_at' => date('c'), 'answers' => ['q' => 'a']],
				['id' => 'deleted-meanwhile', 'submitted_at' => date('c'), 'answers' => ['q' => 'b']],
			],
		]);
		$fileId = $file->getId();
		/** @var ResponseService $responses */
		$responses = $this->getService(ResponseService::class);
		// As the API reads it, with the responses the file still holds.
		$staleForm = Server::get(FormRepository::class)->readForm($file);

		// Meanwhile: a web submission arrives and an editor deletes a response.
		$this->service()->append($file, ['id' => 'web-1', 'submitted_at' => date('c'), 'answers' => ['q' => 'c']]);
		$this->service()->delete($file, 'deleted-meanwhile');

		$created = $responses->submitViaApi($fileId, $file, $staleForm, ['q' => 'from the API'], ['id' => 'k1', 'name' => 'CRM']);
		$responses->updateViaApi($fileId, $file, $staleForm, 'keep', ['q' => 'changed by the API'], ['id' => 'k1']);

		$stored = $this->load($fileId);
		$ids = array_column($stored['responses'], 'id');
		$this->assertSame(['keep', 'web-1', $created['id']], $ids);
		$this->assertSame(3, $stored['_index']['response_count']);
		$this->assertSame('changed by the API', $stored['responses'][0]['answers']['q']);
		$this->assertSame('api', end($stored['responses'])['respondent']['type']);
		$this->assertSame(0, $this->lockRowCount($fileId));
	}

	/**
	 * The share dialog's closing date also closes the API, checked again under the lock (Q7, T16).
	 */
	public function testAnApiResponseIsRefusedOnceTheShareDialogClosedTheForm(): void {
		$file = $this->writeFormFile($this->userFolder, 'Closed Meanwhile', [
			'settings' => ['allow_multiple' => true],
			'questions' => [['id' => 'q', 'type' => 'text', 'question' => 'Q']],
		]);
		$fileId = $file->getId();
		/** @var ResponseService $responses */
		$responses = $this->getService(ResponseService::class);
		$openForm = $this->readFormFromStorage($file);

		/** @var FormRepository $forms */
		$forms = $this->getService(FormRepository::class);
		$forms->update($fileId, ['settings' => ['share_expires_at' => '2001-01-01T00:00:00Z']]);

		try {
			$responses->submitViaApi($fileId, $file, $openForm, ['q' => 'too late'], ['id' => 'k1', 'name' => 'CRM']);
			$this->fail('A closed form took an API response');
		} catch (\RuntimeException $e) {
			$this->assertSame('This form has expired', $e->getMessage());
		}
		$this->assertSame([], $this->load($fileId)['responses']);
		$this->assertSame(0, $this->lockRowCount($fileId));
	}

	/** Changing a response through the API is held to the same dates as a new one (Q7). */
	public function testAnApiChangeIsRefusedOnceTheFormClosed(): void {
		$file = $this->writeFormFile($this->userFolder, 'Change After Closing', [
			'questions' => [['id' => 'q', 'type' => 'text', 'question' => 'Q']],
			'responses' => [['id' => 'r1', 'submitted_at' => date('c'), 'answers' => ['q' => 'before']]],
		]);
		$fileId = $file->getId();
		$form = Server::get(FormRepository::class)->readForm($file);
		Server::get(FormRepository::class)->update($fileId, ['settings' => ['share_expires_at' => '2001-01-01T00:00:00Z']]);

		try {
			$this->responses()->updateViaApi($fileId, $file, $form, 'r1', ['q' => 'after'], ['id' => 'k1']);
			$this->fail('A closed form took a change');
		} catch (ResponseRefusedException $e) {
			$this->assertSame('This form has expired', $e->getMessage());
		}
		$this->assertSame('before', $this->load($fileId)['responses'][0]['answers']['q']);
		$this->assertSame(0, $this->lockRowCount($fileId));
	}

	/** A response keeps its own place in a full option when changed, but cannot move into one (Q7). */
	public function testAnApiChangeKeepsItsPlaceButCannotTakeAFullOption(): void {
		$file = $this->writeFormFile($this->userFolder, 'Full Slots', [
			'settings' => ['allow_multiple' => true],
			'questions' => [['id' => 'slot', 'type' => 'choice', 'question' => 'Slot', 'options' => [
				['value' => 'a', 'label' => 'Slot A', 'capacity' => 1],
				['value' => 'b', 'label' => 'Slot B', 'capacity' => 1],
			]], ['id' => 'note', 'type' => 'text', 'question' => 'Note']],
		]);
		$fileId = $file->getId();
		$this->service()->append($file, ['id' => 'ra', 'submitted_at' => date('c'), 'answers' => ['slot' => 'a']]);
		$this->service()->append($file, ['id' => 'rb', 'submitted_at' => date('c'), 'answers' => ['slot' => 'b']]);
		// As the API reads it: the form with its counts.
		$form = Server::get(FormRepository::class)->readForm($file);

		$kept = $this->responses()->updateViaApi($fileId, $file, $form, 'ra', ['slot' => 'a', 'note' => 'same slot'], ['id' => 'k1']);
		$this->assertSame('same slot', $kept['answers']['note']);

		try {
			$this->responses()->updateViaApi($fileId, $file, $form, 'ra', ['slot' => 'b'], ['id' => 'k1']);
			$this->fail('A change took a place in a full option');
		} catch (InvalidAnswerException $e) {
			$this->assertSame('slot', $e->questionId);
		}
		$this->assertSame(['a', 'b'], array_column(array_column($this->load($fileId)['responses'], 'answers'), 'slot'));
	}

	/** Files a changed response no longer names are removed, not left behind (L32). */
	public function testAnApiChangeRemovesTheFilesTheResponseNoLongerNames(): void {
		$file = $this->writeFormFile($this->userFolder, 'Changed Upload', ['questions' => [['id' => 'cv', 'type' => 'file', 'question' => 'CV', 'maxFiles' => 2]]]);
		$fileId = $file->getId();
		$uploadId = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';
		/** @var UploadService $uploads */
		$uploads = $this->getService(UploadService::class);
		$keep = $uploads->storeUploadPublic($fileId, $uploadId, $this->makeUploadedFile('keep.pdf', 'keep', 'application/pdf'));
		$drop = $uploads->storeUploadPublic($fileId, $uploadId, $this->makeUploadedFile('drop.pdf', 'drop', 'application/pdf'));
		$item = fn (array $meta) => ['responseId' => $uploadId, 'filename' => $meta['filename']];
		$this->service()->append($file, ['id' => 'r1', 'submitted_at' => date('c'), 'answers' => ['cv' => [$item($keep), $item($drop)]]]);

		$this->responses()->updateViaApi($fileId, $file, $this->readFormFromStorage($file), 'r1', ['cv' => [$item($keep)]], ['id' => 'k1']);

		$folder = $uploads->getUploadsFolder($file);
		$this->assertTrue($folder->nodeExists($uploadId . '/' . $keep['filename']));
		$this->assertFalse($folder->nodeExists($uploadId . '/' . $drop['filename']), 'the file the response no longer names is gone');
	}

	/**
	 * A fill sent again after a lost answer names the same folder twice. Deleting one response keeps the files the other
	 * names and takes only its own; deleting the last takes the folder (H5).
	 */
	public function testDeletingOneOfTwoResponsesNamingOneFolderKeepsTheOthersFiles(): void {
		$file = $this->writeFormFile($this->userFolder, 'Shared Folder', ['settings' => ['allow_multiple' => true], 'questions' => [['id' => 'cv', 'type' => 'file', 'question' => 'CV', 'maxFiles' => 2]]]);
		$fileId = $file->getId();
		$group = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';
		/** @var UploadService $uploads */
		$uploads = $this->getService(UploadService::class);
		$cv = $uploads->storeUploadPublic($fileId, $group, $this->makeUploadedFile('cv.pdf', 'cv', 'application/pdf'));
		$mine = $uploads->storeUploadPublic($fileId, $group, $this->makeUploadedFile('mine.pdf', 'mine', 'application/pdf'));
		$item = fn (array $meta) => ['responseId' => $group, 'filename' => $meta['filename']];
		$this->service()->append($file, ['id' => 'first', 'submitted_at' => date('c'), 'answers' => ['cv' => [$item($cv), $item($mine)]]]);
		$this->service()->append($file, ['id' => 'second', 'submitted_at' => date('c'), 'answers' => ['cv' => [$item($cv)]]]);

		$this->service()->delete($file, 'first');

		$folder = $uploads->getUploadsFolder($file);
		$this->assertTrue($folder->nodeExists($group . '/' . $cv['filename']), 'the file the other response names stays');
		$this->assertFalse($folder->nodeExists($group . '/' . $mine['filename']), 'the file only the deleted response named goes');

		$this->service()->delete($file, 'second');
		$this->assertFalse($uploads->getUploadsFolder($file, true)->nodeExists($group), 'nobody names the folder any more');
	}

	/** A change of one of two responses sharing a folder takes only the file it dropped and nobody else names (H5). */
	public function testAChangeOfOneOfTwoResponsesSharingAFolderRemovesOnlyItsOwnDroppedFile(): void {
		$file = $this->writeFormFile($this->userFolder, 'Shared Folder Change', ['settings' => ['allow_multiple' => true], 'questions' => [['id' => 'cv', 'type' => 'file', 'question' => 'CV', 'maxFiles' => 3]]]);
		$fileId = $file->getId();
		$group = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';
		/** @var UploadService $uploads */
		$uploads = $this->getService(UploadService::class);
		$keep = $uploads->storeUploadPublic($fileId, $group, $this->makeUploadedFile('keep.pdf', 'keep', 'application/pdf'));
		$drop = $uploads->storeUploadPublic($fileId, $group, $this->makeUploadedFile('drop.pdf', 'drop', 'application/pdf'));
		$cv = $uploads->storeUploadPublic($fileId, $group, $this->makeUploadedFile('cv.pdf', 'cv', 'application/pdf'));
		$item = fn (array $meta) => ['responseId' => $group, 'filename' => $meta['filename']];
		$this->service()->append($file, ['id' => 'first', 'submitted_at' => date('c'), 'answers' => ['cv' => [$item($keep), $item($drop), $item($cv)]]]);
		$this->service()->append($file, ['id' => 'second', 'submitted_at' => date('c'), 'answers' => ['cv' => [$item($cv)]]]);

		$this->responses()->updateViaApi($fileId, $file, $this->readFormFromStorage($file), 'first', ['cv' => [$item($keep)]], ['id' => 'k1']);

		$folder = $uploads->getUploadsFolder($file);
		$this->assertTrue($folder->nodeExists($group . '/' . $keep['filename']));
		$this->assertTrue($folder->nodeExists($group . '/' . $cv['filename']), 'dropped here, but the other response still names it');
		$this->assertFalse($folder->nodeExists($group . '/' . $drop['filename']), 'the dropped file nobody names goes');
	}

	/** A form moved in from its file keeps a folder two of its responses name as each one's, as it was in the file (H5). */
	public function testAFormMovedInFromItsFileKeepsAFolderTwoResponsesName(): void {
		$group = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';
		$names = fn (string $id) => ['id' => $id, 'submitted_at' => date('c'), 'answers' => ['cv' => ['responseId' => $group, 'filename' => 'cv.pdf']]];
		$file = $this->writeFormFile($this->userFolder, 'Moved In Shared', [
			'settings' => ['allow_multiple' => true],
			'questions' => [['id' => 'cv', 'type' => 'file', 'question' => 'CV']],
			'responses' => [$names('first'), $names('second')],
		]);
		/** @var UploadService $uploads */
		$uploads = $this->getService(UploadService::class);
		$uploads->storeUploadPublic($file->getId(), $group, $this->makeUploadedFile('cv.pdf', 'cv', 'application/pdf'));

		$this->service()->delete($file, 'first');
		$this->assertTrue($uploads->getUploadsFolder($file)->nodeExists($group . '/cv.pdf'), 'the other response still names it');

		$this->service()->delete($file, 'second');
		$this->assertFalse($uploads->getUploadsFolder($file, true)->nodeExists($group));
	}

	private function responses(): ResponseService {
		/** @var ResponseService $responses */
		$responses = $this->getService(ResponseService::class);
		return $responses;
	}
}
