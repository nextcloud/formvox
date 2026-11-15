<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Service\FormFactory;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormLockManager;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\FormRevision;
use OCA\FormVox\Service\FormSchemaValidator;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCA\FormVox\Tests\Unit\Support\ReversibleCrypto;
use OCP\Files\File;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * FormRepository::update — the creator (#146), API keys, webhooks and the link password are server-owned;
 * secrets are stored encrypted.
 */
class FormRepositoryUpdateTest extends TestCase {
	private FormFileLocator $fileLocator;
	private FormLockManager $lockManager;
	private array $stored = [];
	private int $writes = 0;
	/** @var File&\PHPUnit\Framework\MockObject\MockObject */
	private File $file;
	/** @var \OCP\Files\Folder&\PHPUnit\Framework\MockObject\MockObject */
	private \OCP\Files\Folder $parent;
	/** Whether the editor may create in the form's folder, as its owner may. */
	private bool $creatable = true;
	/** The mount the form's folder is the root of, when it is one. */
	private ?\OCP\Files\Mount\IMountPoint $rootOf = null;

	protected function setUp(): void {
		parent::setUp();
		$this->fileLocator = $this->createMock(FormFileLocator::class);
		$this->file = $this->createMock(File::class);
		$this->file->method('getName')->willReturn('Old.fvform');
		$parent = $this->createMock(\OCP\Files\Folder::class);
		$parent->method('getPath')->willReturn('/anna/files');
		$parent->method('isCreatable')->willReturnCallback(fn () => $this->creatable);
		$parent->method('getInternalPath')->willReturnCallback(fn () => $this->rootOf !== null ? '' : 'files');
		$parent->method('getMountPoint')->willReturnCallback(fn () => $this->rootOf ?? $this->createMock(\OCP\Files\Mount\IMountPoint::class));
		$this->file->method('getParent')->willReturn($parent);
		$this->parent = $parent;
		$this->fileLocator->method('getFileById')->willReturn($this->file);

		// Run the mutator against the "stored" form, as the real lock manager does.
		$this->lockManager = $this->createMock(FormLockManager::class);
		$this->lockManager->method('mutateFormFileWithLock')->willReturnCallback(
			function (File $file, callable $mutator) {
				$form = $this->stored;
				$result = $mutator($form);
				$this->stored = $result;
				$this->writes++;
				return $result;
			}
		);
	}

	private ?\OCA\FormVox\Service\OutsideWriteHold $hold = null;
	private ?\Psr\Log\LoggerInterface $logger = null;
	/** @var list<string> names Nextcloud does not allow */
	private array $refusedNames = [];
	/** @var array<string, string> names Nextcloud's own rules change */
	private array $sanitized = [];

	private function filenames(): \OCP\Files\IFilenameValidator {
		$filenames = $this->createMock(\OCP\Files\IFilenameValidator::class);
		$filenames->method('isFilenameValid')->willReturnCallback(fn (string $name) => !in_array($name, $this->refusedNames, true));
		$filenames->method('sanitizeFilename')->willReturnCallback(fn (string $name) => $this->sanitized[$name] ?? $name);
		return $filenames;
	}

	private function repository(): FormRepository {
		return new FormRepository(
			$this->createMock(IRootFolder::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(IL10N::class),
			$this->createMock(IMimeTypeLoader::class),
			new FormFactory($this->createMock(IL10N::class)),
			$this->fileLocator,
			$this->lockManager,
			new PermissionService(),
			ReversibleCrypto::secrets(),
			new FormSanitizer(),
			new FormSchemaValidator(new EnglishL10N(), $this->createMock(\Psr\Log\LoggerInterface::class)),
			new \OCA\FormVox\Service\OwnWrites(),
			new \OCA\FormVox\Service\FormReader(new \OCA\FormVox\Service\DecodedForms(), $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class), $this->createMock(\OCA\FormVox\Service\FormLock::class), $this->createMock(\OCP\Lock\ILockingProvider::class), $this->createMock(\Psr\Log\LoggerInterface::class)),
			$this->hold ?? new \OCA\FormVox\Service\OutsideWriteHold($this->createMock(\OCA\FormVox\Service\FormLock::class)),
			$this->filenames(),
			$this->logger ?? $this->createMock(\Psr\Log\LoggerInterface::class),
		);
	}

	public function testAClientCannotChangeTheCreator(): void {
		$this->stored = ['title' => 'T', 'permissions' => ['owner' => 'anna', 'roles' => []]];

		$form = $this->repository()->update(1, ['permissions' => ['owner' => 'mallory', 'roles' => ['x']]]);

		$this->assertSame(['owner' => 'anna', 'roles' => ['x']], $form['permissions']);
	}

	public function testAClientCannotRemoveTheCreator(): void {
		$this->stored = ['title' => 'T', 'permissions' => ['owner' => 'anna', 'roles' => []]];

		$form = $this->repository()->update(1, ['permissions' => ['roles' => []]]);

		$this->assertSame('anna', $form['permissions']['owner']);
	}

	public function testAClientCannotInventACreatorWhereNoneIsRecorded(): void {
		$this->stored = ['title' => 'T', 'permissions' => ['roles' => []]];

		$form = $this->repository()->update(1, ['permissions' => ['owner' => 'mallory']]);

		$this->assertArrayNotHasKey('owner', $form['permissions']);
	}

	public function testASaveWithoutPermissionsLeavesThemAlone(): void {
		$this->stored = ['title' => 'T', 'description' => '', 'permissions' => ['owner' => 'anna', 'roles' => ['r']]];

		$form = $this->repository()->update(1, ['description' => 'new']);

		$this->assertSame(['owner' => 'anna', 'roles' => ['r']], $form['permissions']);
		$this->assertSame('new', $form['description']);
	}

	private function storedSecrets(): array {
		return [
			'api_keys' => [['id' => 'k1', 'name' => 'CRM', 'hash' => 'enc:v1:key']],
			'webhooks' => [['id' => 'w1', 'url' => 'https://example.com/hook', 'secret' => 'enc:v1:wh']],
			'share_password_hash' => 'enc:v1:pw',
		];
	}

	public function testAClientSaveCannotOverwriteApiKeysWebhooksOrThePasswordHash(): void {
		$this->stored = ['title' => 'T', 'settings' => $this->storedSecrets()];

		$form = $this->repository()->update(1, ['settings' => [
			'api_keys' => [['id' => 'k1', 'name' => 'CRM', 'hash' => '[hidden]']],
			'webhooks' => [],
			'share_password_hash' => 'mallory',
			'has_share_password' => false,
		]]);

		$this->assertSame($this->storedSecrets(), $form['settings']);
	}

	/** Responses and the index are the server's: the index feeds the capacity counts on the public page (T). */
	public function testASaveCannotReplaceResponsesOrTheIndex(): void {
		$index = ['response_count' => 1, 'answer_counts' => ['slot' => ['a' => 1]]];
		$this->stored = ['title' => 'T', 'responses' => [['id' => 'r1']], '_index' => $index, 'created_at' => '2026-01-01'];

		$form = $this->repository()->update(1, [
			'description' => 'New',
			'responses' => [],
			'_index' => ['response_count' => 0, 'answer_counts' => ['slot' => ['a' => 0]]],
			'created_at' => '1999-01-01',
		]);

		$this->assertSame('New', $form['description']);
		$this->assertSame([['id' => 'r1']], $form['responses']);
		$this->assertSame($index, $form['_index']);
		$this->assertSame('2026-01-01', $form['created_at']);
	}

	public function testSettingsLeftOutOfASaveAreKept(): void {
		$this->stored = ['title' => 'T', 'settings' => ['allow_multiple' => false, 'max_responses' => 5]];

		$form = $this->repository()->update(1, ['settings' => ['allow_multiple' => true]]);

		$this->assertSame(['allow_multiple' => true, 'max_responses' => 5], $form['settings']);
	}

	public function testANewLinkPasswordIsStoredOnlyAsAnEncryptedHash(): void {
		$this->stored = ['title' => 'T', 'settings' => ['public_token' => 'TOK']];

		$form = $this->repository()->update(1, ['settings' => ['share_password' => 'geheim']]);

		$this->assertArrayNotHasKey('share_password', $form['settings']);
		$this->assertStringNotContainsString('geheim', json_encode($this->stored));
		$hash = ReversibleCrypto::secrets()->open($form['settings']['share_password_hash']);
		$this->assertStringStartsWith('enc:v1:', $form['settings']['share_password_hash']);
		$this->assertTrue(password_verify('geheim', $hash));
	}

	/** The client never holds the hash, so text that looks like one is just a password (M39). */
	public function testAPasswordThatLooksLikeAHashIsStillAPassword(): void {
		$this->stored = ['title' => 'T', 'settings' => ['public_token' => 'TOK']];

		$form = $this->repository()->update(1, ['settings' => ['share_password' => '$2y$10$looks.like.a.hash']]);

		$hash = ReversibleCrypto::secrets()->open($form['settings']['share_password_hash']);
		$this->assertTrue(password_verify('$2y$10$looks.like.a.hash', $hash));
	}

	public function testClearingTheLinkPasswordRemovesIt(): void {
		$this->stored = ['title' => 'T', 'settings' => ['public_token' => 'TOK', 'share_password_hash' => 'enc:v1:pw']];

		$form = $this->repository()->update(1, ['settings' => ['share_password' => null]]);

		$this->assertArrayNotHasKey('share_password_hash', $form['settings']);
	}

	public function testSecretsFromBeforeEncryptionAreEncryptedOnTheNextSave(): void {
		$this->stored = ['title' => 'T', 'description' => '', 'settings' => [
			'api_keys' => [['id' => 'k1', 'hash' => '$2y$10$legacykey']],
			'webhooks' => [['id' => 'w1', 'secret' => 'legacysecret']],
			'share_password_hash' => '$2y$10$legacypw',
		]];

		$this->repository()->update(1, ['description' => 'any change']);

		$secrets = ReversibleCrypto::secrets();
		$settings = $this->stored['settings'];
		$this->assertStringNotContainsString('legacy', json_encode($settings));
		$this->assertSame('$2y$10$legacykey', $secrets->open($settings['api_keys'][0]['hash']));
		$this->assertSame('legacysecret', $secrets->open($settings['webhooks'][0]['secret']));
		$this->assertSame('$2y$10$legacypw', $secrets->open($settings['share_password_hash']));
	}

	public function testMutateSettingsEncryptsWhatItWrites(): void {
		$this->stored = ['title' => 'T', 'settings' => ['webhooks' => []]];

		$written = $this->repository()->mutateSettings(1, function (array $settings): array {
			$settings['webhooks'][] = ['id' => 'w1', 'secret' => 'whsec'];
			return $settings;
		});

		$this->assertTrue($written);
		$this->assertSame('whsec', ReversibleCrypto::secrets()->open($this->stored['settings']['webhooks'][0]['secret']));
		$this->assertStringNotContainsString('whsec', json_encode($this->stored));
	}

	public function testMutateSettingsWritesNothingWhenThereIsNothingToChange(): void {
		$this->stored = ['title' => 'T', 'settings' => ['webhooks' => []]];

		$written = $this->repository()->mutateSettings(1, fn (array $settings): ?array => null);

		$this->assertFalse($written);
		$this->assertSame(0, $this->writes);
	}

	/** qual#26: a tab that holds an older structure cannot overwrite a newer one. */
	public function testASaveFromAStaleTabIsRefusedAndWritesNothing(): void {
		$this->stored = ['title' => 'T', 'description' => 'saved elsewhere', 'questions' => []];
		$stale = FormRevision::of(['title' => 'T', 'description' => 'what this tab saw', 'questions' => []]);

		try {
			$this->repository()->update(1, ['description' => 'from the stale tab'], $stale);
			$this->fail('A stale save went through');
		} catch (RequestException $e) {
			$this->assertSame([412, 'changed_elsewhere'], [$e->getStatus(), $e->getReason()]);
		}
		$this->assertSame('saved elsewhere', $this->stored['description']);
		$this->assertSame(0, $this->writes);
	}

	public function testASaveFromAnUpToDateTabGoesThrough(): void {
		$this->stored = ['title' => 'T', 'description' => 'now', 'questions' => [], 'responses' => [['id' => 'r1']], 'settings' => ['public_token' => 'x']];

		$form = $this->repository()->update(1, ['description' => 'next'], FormRevision::of(['title' => 'T', 'description' => 'now', 'questions' => []]));

		$this->assertSame('next', $form['description']);
	}

	public function testWithoutARevisionThereIsNoCheck(): void {
		$this->stored = ['title' => 'T', 'description' => 'now'];

		$this->assertSame('other', $this->repository()->update(1, ['description' => 'other'])['description']);
	}

	/**
	 * The new title's file name is taken under the form's lock, taken before Nextcloud holds any; a form that stays busy
	 * keeps its old name, and the save stands (review of 3b-3: the save answered 500 after it went through).
	 */
	public function testABusyFormKeepsItsOldNameAndTheSaveStands(): void {
		$this->stored = ['title' => 'Old', 'questions' => []];
		$lock = $this->createMock(\OCA\FormVox\Service\FormLock::class);
		$lock->method('acquire')->willThrowException(new \OCA\FormVox\Service\FormLockedException('busy'));
		$this->hold = new \OCA\FormVox\Service\OutsideWriteHold($lock);
		$this->file->expects($this->never())->method('move');

		$form = $this->repository()->update(1, ['title' => 'New']);

		$this->assertSame('New', $form['title']);
	}

	public function testAFreeNameIsTakenOnceTheFormIsHeld(): void {
		$this->stored = ['title' => 'Old', 'questions' => []];
		$this->file->expects($this->once())->method('move')->with('/anna/files/new.fvform');

		$this->repository()->update(1, ['title' => 'New']);
	}

	/** A file that took the new name while the rename waited for the form is never moved over (review of 3b-3b). */
	public function testANameTakenWhileTheRenameWaitedIsLeftAlone(): void {
		$this->stored = ['title' => 'Old', 'questions' => []];
		$this->parent->method('nodeExists')->willReturnOnConsecutiveCalls(false, true);
		$this->file->expects($this->never())->method('move');

		$this->assertSame('New', $this->repository()->update(1, ['title' => 'New'])['title']);
	}

	/** Whatever else stops the rename, the written save stands: only the name stays (review of 3b-3b). */
	public function testALockBackendFailureKeepsTheOldNameAndTheSaveStands(): void {
		$this->stored = ['title' => 'Old', 'questions' => []];
		$lock = $this->createMock(\OCA\FormVox\Service\FormLock::class);
		$lock->method('acquire')->willThrowException(new \RuntimeException('Redis went away'));
		$this->hold = new \OCA\FormVox\Service\OutsideWriteHold($lock);
		$this->file->expects($this->never())->method('move');

		$this->assertSame('New', $this->repository()->update(1, ['title' => 'New'])['title']);
	}

	/** Where the editor may not create, the rename is not tried and the form's lock not taken, on any save (review of 3b-3c). */
	public function testWhereTheEditorMayNotCreateNoRenameIsTried(): void {
		$this->stored = ['title' => 'Old', 'questions' => []];
		$this->creatable = false;
		$lock = $this->createMock(\OCA\FormVox\Service\FormLock::class);
		$lock->expects($this->never())->method('acquire');
		$this->hold = new \OCA\FormVox\Service\OutsideWriteHold($lock);
		$this->file->expects($this->never())->method('move');

		$this->assertSame('New', $this->repository()->update(1, ['title' => 'New'])['title']);
	}

	/** A refused move keeps the old name, the save stands, and the log says so without a trace (review of 3b-3c). */
	public function testARefusedMoveKeepsTheOldNameAndTheSaveStands(): void {
		$this->stored = ['title' => 'Old', 'questions' => []];
		$this->file->expects($this->once())->method('move')->willThrowException(new \OCP\Files\NotPermittedException('Could not move'));
		$this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->once())->method('info')->with($this->anything(), $this->callback(fn (array $context) => !isset($context['exception'])));

		$this->assertSame('Con', $this->repository()->update(1, ['title' => 'Con'])['title']);
	}

	/** A name Nextcloud does not allow is not tried, and the form's lock not taken, on any save (review of 3b-3d). */
	public function testANameNextcloudDoesNotAllowIsNotTried(): void {
		$this->stored = ['title' => 'Old', 'questions' => []];
		$this->refusedNames = ['con.fvform'];
		$lock = $this->createMock(\OCA\FormVox\Service\FormLock::class);
		$lock->expects($this->never())->method('acquire');
		$this->hold = new \OCA\FormVox\Service\OutsideWriteHold($lock);
		$this->file->expects($this->never())->method('move');

		$this->assertSame('Con', $this->repository()->update(1, ['title' => 'Con'])['title']);
	}

	/** Nextcloud's own rules apply to the new name, so a name the administrator forbids becomes one it allows. */
	public function testTheNewNameFollowsNextcloudsRules(): void {
		$this->stored = ['title' => 'Old', 'questions' => []];
		$this->sanitized = ['con.fvform' => 'con (renamed).fvform'];
		$this->file->expects($this->once())->method('move')->with('/anna/files/con (renamed).fvform');

		$this->repository()->update(1, ['title' => 'Con']);
	}

	/** A mount root that can be moved is tried, as Node::move allows it there (review of 3b-3d). */
	public function testAMovableMountRootIsTriedWhereNothingMayBeCreated(): void {
		$this->stored = ['title' => 'Old', 'questions' => []];
		$this->creatable = false;
		$this->rootOf = $this->createMockForIntersectionOfInterfaces([\OCP\Files\Mount\IMountPoint::class, \OCP\Files\Mount\IMovableMount::class]);
		$this->file->expects($this->once())->method('move');

		$this->repository()->update(1, ['title' => 'New']);
	}

	/** The editor's save id is kept outside the revision, so a tab whose answer was lost can ask whether it landed (review of 3b-4d). */
	public function testTheSaveIdIsKeptOutsideTheRevision(): void {
		$this->stored = ['title' => 'Old', 'questions' => []];
		$before = \OCA\FormVox\Service\FormRevision::of(['title' => 'Same', 'questions' => []]);

		$form = $this->repository()->update(1, ['title' => 'Same', '_lastSave' => 'from-the-client'], null, 'save-0001');

		$this->assertSame(['id' => 'save-0001', 'revision' => $before], $form['_lastSave'], 'with the revision this save made');
		$this->assertSame($before, \OCA\FormVox\Service\FormRevision::of($form));
	}

	public function testOnlyAWellFormedSaveIdIsTaken(): void {
		$this->assertSame('3f2a9c1e-77b0-4c1d-9e2a-0b1c2d3e4f50', \OCA\FormVox\Service\FormRevision::saveId('3f2a9c1e-77b0-4c1d-9e2a-0b1c2d3e4f50'));
		$this->assertNull(\OCA\FormVox\Service\FormRevision::saveId(''));
		$this->assertNull(\OCA\FormVox\Service\FormRevision::saveId('<script>'));
	}

	/** A team folder's root, which Nextcloud does not move, where nothing may be created: not tried (review of 3b-3e). */
	public function testANonMovableMountRootWhereNothingMayBeCreatedIsNotTried(): void {
		$this->stored = ['title' => 'Old', 'questions' => []];
		$this->creatable = false;
		$this->rootOf = $this->createMock(\OCP\Files\Mount\IMountPoint::class);
		$lock = $this->createMock(\OCA\FormVox\Service\FormLock::class);
		$lock->expects($this->never())->method('acquire');
		$this->hold = new \OCA\FormVox\Service\OutsideWriteHold($lock);
		$this->file->expects($this->never())->method('move');

		$this->repository()->update(1, ['title' => 'New']);
	}

	/** Taken after the schema check: the revision of what was written, also when the check tidied the save (review of 3b-4f). */
	public function testTheSaveIdCarriesTheRevisionOfWhatWasWritten(): void {
		$this->stored = ['title' => 'Old', 'questions' => []];

		$form = $this->repository()->update(1, ['title' => 'Old', 'descriptionAlign' => 'bogus'], null, 'save-0002');

		$this->assertSame('left', $form['descriptionAlign'], 'tidied by the check');
		$this->assertSame(\OCA\FormVox\Service\FormRevision::of($form), $form['_lastSave']['revision']);
	}
}
