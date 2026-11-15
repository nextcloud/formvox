<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Response;

use OCA\FormVox\Controller\FormController;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\InvalidAnswerException;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\TemplateService;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Server;

/**
 * What a submission stores is what AnswerValidator allows, against a real form and real uploads (H6, L40).
 *
 * @group DB
 */
class SubmissionValidationIntegrationTest extends IntegrationTestCase {
	private const UPLOAD = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';

	private function form(): int {
		$file = $this->writeFormFile($this->userFolder, 'Validated ' . uniqid(), [
			'settings' => ['allow_multiple' => true, 'anonymous' => false],
			'questions' => [
				['id' => 'name', 'type' => 'text', 'question' => 'Name'],
				['id' => 'colour', 'type' => 'choice', 'question' => 'Colour', 'options' => [['value' => 'red'], ['value' => 'blue']]],
				['id' => 'cv', 'type' => 'file', 'question' => 'CV'],
			],
		]);
		$this->requirePublicResolvable($file->getId());
		return $file->getId();
	}

	public function testOnlyValidatedAnswersAreStoredAndAFileIsDescribedByNextcloud(): void {
		$fileId = $this->form();
		$upload = Server::get(UploadService::class)->storeUploadPublic($fileId, self::UPLOAD, $this->makeUploadedFile('cv.pdf', 'PDF bytes', 'application/pdf'));

		Server::get(ResponseService::class)->submitAuthenticated($fileId, Server::get(FormRepository::class)->loadPublic($fileId), [
			'name' => 'Anna',
			'colour' => 'red',
			'cv' => ['responseId' => self::UPLOAD, 'filename' => $upload['filename'], 'originalName' => 'cv.pdf', 'size' => 999999, 'mimeType' => 'text/html'],
			'invented' => str_repeat('junk', 1000),
		], $this->ownerUid, 'Owner');

		$stored = Server::get(FormRepository::class)->load($fileId)['responses'][0]['answers'];
		$this->assertSame(['name', 'colour', 'cv'], array_keys($stored));
		$this->assertSame(strlen('PDF bytes'), $stored['cv']['size']);
		$this->assertNotSame('text/html', $stored['cv']['mimeType']);
	}

	public function testARefusedAnswerStoresNothing(): void {
		$fileId = $this->form();

		try {
			Server::get(ResponseService::class)->submitAuthenticated($fileId, Server::get(FormRepository::class)->loadPublic($fileId), ['colour' => 'purple'], $this->ownerUid, 'Owner');
			$this->fail('a colour that is not an option was accepted');
		} catch (InvalidAnswerException $e) {
			$this->assertSame('colour', $e->questionId);
		}
		$this->assertSame([], Server::get(FormRepository::class)->load($fileId)['responses'] ?? []);
	}

	public function testAFileAnswerForAnUploadThatDoesNotExistIsRefused(): void {
		$fileId = $this->form();

		$this->expectException(InvalidAnswerException::class);
		Server::get(ResponseService::class)->submitAuthenticated($fileId, Server::get(FormRepository::class)->loadPublic($fileId), ['cv' => ['responseId' => self::UPLOAD, 'filename' => 'never-uploaded.pdf']], $this->ownerUid, 'Owner');
	}

	public function testAFormFromATemplateGetsItsPagesAndSettingsButNothingFormBound(): void {
		$templates = Server::get(TemplateService::class);
		$entry = $templates->addTemplate('IT template ' . uniqid(), '', [
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q']],
			'pages' => [['id' => 'p1', 'title' => 'Page 1']],
			'settings' => ['allow_multiple' => true, 'max_responses' => 50, 'public_token' => 'TOK', 'api_keys' => [['id' => 'k', 'hash' => 'h']], 'notify_recipients' => [['type' => 'user', 'id' => 'x']]],
		]);
		try {
			$created = Server::get(FormController::class)->create('From template ' . uniqid(), '', null, [], false, $entry['id'])->getData();
			$form = Server::get(FormRepository::class)->load((int)$created['fileId']);

			$this->assertSame([['id' => 'p1', 'title' => 'Page 1']], $form['pages']);
			$this->assertTrue($form['settings']['allow_multiple']);
			$this->assertSame(50, $form['settings']['max_responses']);
			foreach (['public_token', 'api_keys', 'notify_recipients'] as $formBound) {
				$this->assertArrayNotHasKey($formBound, $form['settings']);
			}
		} finally {
			$templates->removeTemplate($entry['id']);
		}
	}

	/** secu#46, test#50: the server walks the pages as the browser does. */
	public function testASkippedPageIsNeitherRequiredNorStored(): void {
		$file = $this->writeFormFile($this->userFolder, 'Routed ' . uniqid(), [
			'settings' => ['allow_multiple' => true, 'anonymous' => false],
			'questions' => [
				['id' => 'member', 'type' => 'choice', 'question' => 'Member?', 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
				['id' => 'number', 'type' => 'text', 'question' => 'Member number', 'required' => true],
				['id' => 'mail', 'type' => 'text', 'question' => 'E-mail'],
			],
			'pages' => [
				// A rule naming the label, as older editors saved it (#99).
				['id' => 'p1', 'title' => 'One', 'questions' => ['member'], 'routing' => [['questionId' => 'member', 'operator' => 'equals', 'value' => 'No', 'targetPageId' => 'p3']]],
				['id' => 'p2', 'title' => 'Two', 'questions' => ['number']],
				['id' => 'p3', 'title' => 'Three', 'questions' => ['mail']],
			],
		]);
		$this->requirePublicResolvable($file->getId());
		$fileId = $file->getId();
		$responses = Server::get(ResponseService::class);

		$responses->submitAuthenticated($fileId, Server::get(FormRepository::class)->loadPublic($fileId), ['member' => 'no', 'number' => 'typed before going back', 'mail' => 'a@example.com'], $this->ownerUid, 'Owner');

		$stored = Server::get(FormRepository::class)->load($fileId)['responses'][0]['answers'];
		$this->assertSame(['member' => 'no', 'mail' => 'a@example.com'], $stored);

		// Through the page it was on, the question is required.
		$this->expectException(InvalidAnswerException::class);
		$responses->submitAuthenticated($fileId, Server::get(FormRepository::class)->loadPublic($fileId), ['member' => 'yes', 'mail' => 'b@example.com'], $this->ownerUid, 'Owner');
	}
}
