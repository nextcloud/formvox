<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Repository;

use OCA\FormVox\Controller\FormController;
use OCA\FormVox\Middleware\ExceptionMiddleware;
use OCA\FormVox\Middleware\FormAccessMiddleware;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\FormValidationException;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * The structure check on a real save and a real create (review V5, PO 4b): refused saves leave the file as it
 * was, leftovers are tidied on disk, and what the editor sends is kept.
 *
 * @group DB
 */
class FormSchemaIntegrationTest extends IntegrationTestCase {
	/** Through FormVox's middleware, as Nextcloud's dispatcher calls it. */
	private function save(int $fileId, array $args): Response {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $key === 'fileId' ? (string)$fileId : $default);
		$access = new FormAccessMiddleware($request, Server::get(FormAccessService::class));
		$errors = new ExceptionMiddleware($request, Server::get(\OCP\IConfig::class), Server::get(\OCP\L10N\IFactory::class)->get('formvox'), Server::get(LoggerInterface::class));
		$controller = Server::get(FormController::class);
		try {
			$access->beforeController($controller, 'update');
			return $controller->update($fileId, ...$args);
		} catch (\Exception $e) {
			return $errors->afterException($controller, 'update', $e);
		}
	}

	public function testABrokenSaveIsRefusedWithTheQuestionAndTheFileStaysAsItWas(): void {
		$file = $this->writeFormFile($this->userFolder, 'Schema refused', ['questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']]]);
		$before = $file->getContent();

		$response = $this->save($file->getId(), ['questions' => [
			['id' => 'q1', 'type' => 'text', 'question' => 'Name'],
			['id' => 'q2', 'type' => 'scale', 'question' => 'Score', 'scaleMin' => 1, 'scaleMax' => 100000000],
		]]);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('invalid_form', $response->getData()['reason']);
		$this->assertSame(['q2', 'scaleMin'], [$response->getData()['errors'][0]['question'], $response->getData()['errors'][0]['field']]);
		$this->assertSame($before, $file->getContent());
	}

	public function testASaveIsTidiedOnDiskAndKeepsTheDescriptionAlignment(): void {
		$file = $this->writeFormFile($this->userFolder, 'Schema tidied', ['questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']]]);

		$response = $this->save($file->getId(), [
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name', 'options' => [], 'timeMin' => '09:00']],
			'pages' => [['id' => 'p1', 'title' => 'One', 'questions' => ['q1', 'deleted']]],
			'descriptionAlign' => 'center',
		]);

		$this->assertSame(200, $response->getStatus());
		$stored = $this->readFormFromStorage($file);
		$this->assertSame(['id' => 'q1', 'type' => 'text', 'question' => 'Name'], $stored['questions'][0]);
		$this->assertSame(['q1'], $stored['pages'][0]['questions']);
		$this->assertSame('center', $stored['descriptionAlign']);
	}

	/** An AI answer or a prefill is checked before anything is written. */
	public function testAFormThatWouldBreakIsNotCreated(): void {
		$folder = $this->userFolder->newFolder('Schema create ' . uniqid());
		try {
			Server::get(FormRepository::class)->createAsUser($this->ownerUid, 'Broken AI form', $folder->getName(), null, [
				'questions' => [['id' => 'q1', 'type' => 'text', 'showIf' => 'x']],
			]);
			$this->fail('A broken form was created');
		} catch (FormValidationException $e) {
			$this->assertSame('showIf', $e->getErrors()[0]['field']);
		}
		$this->assertSame([], $folder->getDirectoryListing());
		$folder->delete();
	}
}
