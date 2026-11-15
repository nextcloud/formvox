<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Service\FormIntake;
use OCA\FormVox\Service\FormLock;
use OCA\FormVox\Service\FormLockManager;
use OCA\FormVox\Service\FormSchemaValidator;
use OCA\FormVox\Service\FormValidationException;
use OCA\FormVox\Service\OwnWrites;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCA\FormVox\Tests\Unit\Support\Fake\Redis;
use OCA\FormVox\Versions\IFormVersionCleaner;
use OCP\Files\Cache\IUpdater;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A copy is a new form, whether one file or a folder was copied (#146, M): questions and behaviour, made by
 * whoever copied it; the original's responses, link, keys and upload folders stay behind, and a copy that
 * cannot be cleaned is removed (PO).
 *
 * A file written over a form brings its content only (review L1/qual#42/test#113, PO 4b): the responses, link,
 * keys, webhooks and creator stay, and content that would break the form is refused or undone.
 */
class FormIntakeTest extends TestCase {
	/** @var array<string, string> what was written, by internal path */
	private array $written = [];
	/** @var list<string> names of removed nodes */
	private array $removed = [];
	private OwnWrites $ownWrites;
	private ?FormLockManager $lockManager = null;
	private ?IFormVersionCleaner $versionCleaner = null;
	/** The form locks, as a shared cache keeps them. */
	private Redis $locks;
	/** @var list<?string> who held the form's lock at each write */
	private array $lockWhileWriting = [];
	private ?\OCA\FormVox\Service\OutsideWriteHold $hold = null;

	protected function setUp(): void {
		parent::setUp();
		$this->ownWrites = new OwnWrites();
		$this->locks = new Redis();
	}

	private function intake(?string $uid = 'bob'): FormIntake {
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}
		$logger = $this->createMock(LoggerInterface::class);
		return new FormIntake(
			new FormSanitizer(),
			new FormSchemaValidator(new EnglishL10N(), $logger),
			$this->lockManager ?? $this->createMock(FormLockManager::class),
			$this->ownWrites,
			$this->versionCleaner ?? $this->createMock(IFormVersionCleaner::class),
			$session,
			new \OCA\FormVox\Service\FormWriteGuard($this->createMock(\OCP\Encryption\IManager::class), $this->createMock(\OCP\IConfig::class), $logger),
			$lock = new FormLock($this->lockFactory(), $this->createMock(IDBConnection::class), $logger),
			$this->hold = new \OCA\FormVox\Service\OutsideWriteHold($lock, 0.2),
			new EnglishL10N(),
			$logger,
		);
	}

	private function lockFactory(): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocking')->willReturn($this->locks);
		return $factory;
	}

	private function original(): array {
		return [
			'title' => 'Survey',
			'created_at' => '2026-01-01T00:00:00+00:00',
			'settings' => ['public_token' => 'TOK', 'api_keys' => [['id' => 'k1', 'hash' => 'enc:v1:h']], 'webhooks' => [['id' => 'w1']], 'notify_recipients' => [['type' => 'user', 'id' => 'carol']]],
			'permissions' => ['owner' => 'anna', 'roles' => []],
			'responses' => [['id' => 'r1']],
		];
	}

	private function form(string $name, string|false|null $content = null, bool $writes = true): File {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getUpdater')->willReturn($this->createMock(IUpdater::class));
		$storage->method('file_get_contents')->willReturn($content ?? json_encode($this->original()));
		$storage->method('file_put_contents')->willReturnCallback(function (string $path, string $data) use ($writes) {
			if (!$writes) {
				return false;
			}
			$this->written[$path] = $data;
			return strlen($data);
		});
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/' . $name);
		$file->method('delete')->willReturnCallback(function () use ($name) {
			$this->removed[] = $name;
		});
		return $file;
	}

	private function folder(string $name, array $children): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn($name);
		$folder->method('getDirectoryListing')->willReturn($children);
		$folder->method('delete')->willReturnCallback(function () use ($name) {
			$this->removed[] = $name;
		});
		return $folder;
	}

	private function copyOf(string $name): array {
		$this->assertArrayHasKey('files/' . $name, $this->written, "$name was not cleaned");
		return json_decode($this->written['files/' . $name], true);
	}

	public function testACopiedFormKeepsItsQuestionsAndLosesWhatWasTheOriginals(): void {
		$this->intake('bob')->copied($this->form('Copy of survey.fvform'));

		$copy = $this->copyOf('Copy of survey.fvform');
		$this->assertSame('Survey', $copy['title']);
		$this->assertSame('bob', $copy['permissions']['owner']);
		$this->assertSame([], $copy['responses']);
		$this->assertSame('2026-01-01T00:00:00+00:00', $copy['copied_from']);
		foreach (['public_token', 'api_keys', 'webhooks', 'notify_recipients'] as $key) {
			$this->assertArrayNotHasKey($key, $copy['settings']);
		}
	}

	public function testWithoutASessionNoCreatorIsRecordedRatherThanTheOriginalOne(): void {
		$this->intake(null)->copied($this->form('a.fvform'));

		$this->assertArrayNotHasKey('owner', $this->copyOf('a.fvform')['permissions']);
	}

	public function testEveryFormInACopiedFolderIsCleanedAlsoInNestedFolders(): void {
		$this->intake()->copied($this->folder('Copy of Surveys', [
			$this->form('one.fvform'),
			$this->form('notes.txt'),
			$this->folder('2026', [$this->form('two.FVFORM')]),
		]));

		$this->assertSame([], $this->copyOf('one.fvform')['responses']);
		$this->assertSame([], $this->copyOf('two.FVFORM')['responses']);
		$this->assertArrayNotHasKey('files/notes.txt', $this->written);
		$this->assertSame([], $this->removed);
	}

	/** The upload, branding and template folders of the original came along; the copy has no use for them. */
	public function testTheOriginalsHiddenFoldersNextToACopiedFormAreRemoved(): void {
		$this->intake()->copied($this->folder('Copy of Surveys', [
			$this->form('one.fvform'),
			$this->folder('.formvox-uploads-12', []),
			$this->folder('.formvox-branding-12', []),
			$this->folder('.formvox-templates-12', []),
			$this->folder('.formvox-uploads-mine', []),
			$this->folder('Archive', [$this->folder('.formvox-uploads-99', [])]),
		]));

		$this->assertSame(['.formvox-uploads-12', '.formvox-branding-12', '.formvox-templates-12'], $this->removed);
	}

	public function testAHiddenFolderCopiedOnItsOwnIsLeftAlone(): void {
		$this->intake()->copied($this->folder('.formvox-uploads-12', [$this->folder('5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f', [])]));

		$this->assertSame([], $this->removed);
	}

	/**
	 * @return array<string, array{string|false, bool}>
	 */
	public static function copiesThatCannotBeCleaned(): array {
		return [
			'the cleaned form cannot be written' => [json_encode(['title' => 'S', 'responses' => [['id' => 'r1']]]), false],
			'the copy cannot be read' => [false, true],
		];
	}

	/**
	 * @dataProvider copiesThatCannotBeCleaned
	 */
	public function testACopyThatCannotBeCleanedIsRemoved(string|false $content, bool $writes): void {
		$this->intake()->copied($this->form('Copy.fvform', $content, $writes));

		$this->assertSame(['Copy.fvform'], $this->removed);
	}

	public function testAFileNamedLikeAFormThatHoldsNoFormIsLeftAsItIs(): void {
		$this->intake()->copied($this->form('notes.fvform', 'just text'));

		$this->assertSame([], $this->written);
		$this->assertSame([], $this->removed);
	}

	// ---- a file written over a form ------------------------------------------------------------------------------

	/** @var array<string, ?string> what each form file holds, by name; null: it does not exist */
	private array $disk = [];
	/** What the owner has left, as Nextcloud reports it; false: not known. */
	private int|false $free = false;

	private function stored(): array {
		return [
			'version' => '1.0',
			'id' => 'uuid-1',
			'title' => 'Survey',
			'created_at' => '2026-01-01T00:00:00+00:00',
			'settings' => [
				'anonymous' => true, 'allow_multiple' => false, 'require_login' => false, 'max_responses' => 10,
				'public_token' => 'TOK', 'share_password_hash' => 'enc:v1:pw',
				'api_keys' => [['id' => 'k1', 'hash' => 'enc:v1:h', 'created_by' => 'anna']],
				'webhooks' => [['id' => 'w1', 'url' => 'https://example.com/hook', 'secret' => 'enc:v1:s', 'created_by' => 'anna']],
			],
			'permissions' => ['owner' => 'anna', 'roles' => []],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
			'branding' => ['primaryColor' => '#123456'],
			'_index' => ['response_count' => 1, 'answer_counts' => []],
			'responses' => [['id' => 'r1', 'answers' => ['q1' => 'Anna']]],
		];
	}

	/** What someone puts over it: a download, edited, with what they would like the server to hold. */
	private function upload(array $changes = []): string {
		return json_encode(array_replace([
			'title' => 'Survey, edited',
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Full name'], ['id' => 'q2', 'type' => 'text', 'question' => 'City']],
			'settings' => ['anonymous' => false, 'public_token' => 'MINE', 'api_keys' => [['id' => 'k9', 'hash' => 'known', 'created_by' => 'anna']], 'max_responses' => 0],
			'permissions' => ['owner' => 'mallory'],
			'responses' => [],
			'_index' => ['response_count' => 0],
			'branding' => null,
			'downloaded_at' => '2026-10-01T00:00:00+00:00',
		], $changes));
	}

	/** A form file whose storage keeps what is written to it. */
	private function storedFile(string $name, ?string $content, ?callable $failsToWrite = null): File {
		$this->disk[$name] = $content;
		$storage = $this->createMock(IStorage::class);
		$storage->method('getUpdater')->willReturn($this->createMock(IUpdater::class));
		$storage->method('free_space')->willReturnCallback(fn () => $this->free);
		$storage->method('file_get_contents')->willReturnCallback(fn () => $this->disk[$name] ?? false);
		$storage->method('file_put_contents')->willReturnCallback(function (string $path, string $data) use ($name, $failsToWrite) {
			if ($failsToWrite !== null && $failsToWrite($data)) {
				throw new \RuntimeException('the disk failed');
			}
			$this->disk[$name] = $data;
			$this->written[$path] = $data;
			$this->lockWhileWriting[] = $this->locks->store['formvox/form/42'] ?? null;
			return strlen($data);
		});
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		// As Nextcloud 32 reports a file that does not exist yet.
		$file->method('getPath')->willReturnCallback(fn () => ($this->disk[$name] === null ? '/bob/files//' : '/bob/files/') . $name);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/' . $name);
		$file->method('getId')->willReturnCallback(function () use ($name) {
			if ($this->disk[$name] === null) {
				throw new NotFoundException();
			}
			return 42;
		});
		$file->method('delete')->willReturnCallback(function () use ($name) {
			$this->removed[] = $name;
		});
		return $file;
	}

	/** Someone other than FormVox writes $upload, as Nextcloud reports it. */
	private function writeOutside(FormIntake $intake, File $file, string $upload): void {
		$intake->beforeWrite($file);
		$this->disk[$file->getName()] = $upload;
		$intake->written($file);
	}

	private function onDisk(string $name): array {
		return json_decode((string)$this->disk[$name], true);
	}

	/**
	 * Taking in a write from outside holds the form's lock while it writes, so a reader sees a save under way and does
	 * not call the form broken (review of the 3a fixes); released after.
	 */
	public function testTakingInAWriteHoldsTheFormsLockWhileItWrites(): void {
		$file = $this->storedFile('Survey.fvform', json_encode($this->stored()));

		$this->writeOutside($this->intake(), $file, $this->upload());

		$this->assertCount(1, $this->lockWhileWriting);
		$this->assertNotNull($this->lockWhileWriting[0]);
		$this->assertArrayNotHasKey('formvox/form/42', $this->locks->store, 'released after');
	}

	/**
	 * A write from outside that meets a FormVox save waits a moment, then is refused with a hint (the one exception
	 * Nextcloud's file hooks let through), so it never writes over a submission (3b-3); the other holder keeps its lock.
	 */
	public function testAnOutsideWriteThatMeetsAFormVoxSaveIsRefused(): void {
		$file = $this->storedFile('Survey.fvform', json_encode($this->stored()));
		$other = time() . ':other:elsewhere:1';
		$this->locks->store['formvox/form/42'] = $other;
		$started = microtime(true);

		try {
			$this->intake()->beforeWrite($file);
			$this->fail('An outside write went ahead over a FormVox save');
		} catch (\OCA\FormVox\Service\FormBusyHint $e) {
			$this->assertSame('The form is busy, please try again', $e->getHint());
		}
		$this->assertLessThan(1.0, microtime(true) - $started);
		$this->assertSame($other, $this->locks->store['formvox/form/42']);
	}

	/** Held from before the write to after it: the merge reads what is there, and the lock is free once it is taken in. */
	public function testTheLockIsHeldFromBeforeTheWriteToAfterIt(): void {
		$file = $this->storedFile('Survey.fvform', json_encode($this->stored()));
		$intake = $this->intake();

		$intake->beforeWrite($file);
		$this->assertArrayHasKey('formvox/form/42', $this->locks->store, 'held from before the write');
		$this->disk['Survey.fvform'] = $this->upload();
		$intake->written($file);

		$this->assertArrayNotHasKey('formvox/form/42', $this->locks->store);
		$this->assertSame([['id' => 'r1', 'answers' => ['q1' => 'Anna']]], $this->onDisk('Survey.fvform')['responses']);
	}

	/** Whatever keeps the lock from it (a cache that is down), the server's data still comes back: the lock only helps readers. */
	public function testTakingInAWriteWritesAlsoWhenTheLockCannotBeHad(): void {
		$file = $this->storedFile('Survey.fvform', json_encode($this->stored()));
		$this->locks = new class extends Redis {
			public function add($key, $value, $ttl = 0) {
				throw new \RuntimeException('Redis went away');
			}
		};

		$this->writeOutside($this->intake(), $file, $this->upload());

		$this->assertSame([['id' => 'r1', 'answers' => ['q1' => 'Anna']]], $this->onDisk('Survey.fvform')['responses']);
	}

	public function testAnUploadOverAFormBringsItsContentAndTheServerKeepsTheRest(): void {
		$form = $this->intake('mallory')->takeIn($this->stored(), $this->upload());

		$this->assertSame('Survey, edited', $form['title']);
		$this->assertSame(['q1', 'q2'], array_column($form['questions'], 'id'));
		$this->assertFalse($form['settings']['anonymous']);
		$this->assertSame([['id' => 'r1', 'answers' => ['q1' => 'Anna']]], $form['responses']);
		$this->assertSame(1, $form['_index']['response_count']);
		$this->assertSame('anna', $form['permissions']['owner']);
		$this->assertSame(['primaryColor' => '#123456'], $form['branding']);
		foreach (['public_token', 'share_password_hash', 'api_keys', 'webhooks', 'max_responses'] as $key) {
			$this->assertSame($this->stored()['settings'][$key], $form['settings'][$key], $key);
		}
		$this->assertSame(['uuid-1', '2026-01-01T00:00:00+00:00'], [$form['id'], $form['created_at']]);
		$this->assertArrayNotHasKey('downloaded_at', $form);
	}

	public function testANewFormFileStartsLikeACopyMadeByWhoeverPutItThere(): void {
		$form = $this->intake('bob')->takeIn(null, $this->upload());

		$this->assertSame('Survey, edited', $form['title']);
		$this->assertSame('bob', $form['permissions']['owner']);
		$this->assertSame([], $form['responses']);
		$this->assertSame(0, $form['_index']['response_count']);
		$this->assertSame(['anonymous' => false, 'allow_multiple' => false, 'require_login' => false], $form['settings']);
		$this->assertArrayNotHasKey('branding', $form);
		$this->assertArrayNotHasKey('downloaded_at', $form);
	}

	public function testContentThatWouldBreakTheFormIsRefusedWithTheQuestion(): void {
		try {
			$this->intake()->takeIn($this->stored(), $this->upload(['questions' => [['id' => 'q1', 'type' => 'scale', 'scaleMin' => 1, 'scaleMax' => 100000000]]]));
			$this->fail('A breaking upload was taken in');
		} catch (FormValidationException $e) {
			$this->assertSame('q1', $e->getErrors()[0]['question']);
		}
	}

	public function testSomethingThatIsNoFormIsRefusedOverAFormAndLeftAloneAsANewFile(): void {
		$this->assertNull($this->intake()->takeIn(null, 'just text'));
		$this->assertNull($this->intake()->takeIn(null, ''));
		$this->expectException(RequestException::class);
		$this->intake()->takeIn($this->stored(), 'just text');
	}

	/** Over WebDAV: merged under the form's lock, against what is stored at that moment. */
	public function testAnUploadOverAFormIsMergedUnderTheFormsLock(): void {
		$file = $this->storedFile('Survey.fvform', json_encode($this->stored()));
		$underLock = $this->stored();
		$underLock['responses'][] = ['id' => 'r2', 'answers' => ['q1' => 'Submitted meanwhile']];
		$this->lockManager = $this->createMock(FormLockManager::class);
		$this->lockManager->expects($this->once())->method('mutateFormFileWithLock')
			->willReturnCallback(function (File $f, callable $mutator) use (&$underLock) {
				$mutator($underLock);
				return null;
			});

		$this->assertTrue($this->intake()->overwrite($file, $this->upload()));
		$this->assertSame('Survey, edited', $underLock['title']);
		$this->assertSame(['r1', 'r2'], array_column($underLock['responses'], 'id'));
	}

	public function testAnUploadOverAFileThatIsNoFormArrivesAsANewForm(): void {
		$this->lockManager = $this->createMock(FormLockManager::class);
		$this->lockManager->expects($this->never())->method('mutateFormFileWithLock');

		$this->assertFalse($this->intake()->overwrite($this->storedFile('Broken.fvform', 'not json'), $this->upload()));
	}

	/** Bulk or chunked upload, another app: Nextcloud reports the write and FormIntake puts the server's data back. */
	public function testAFormWrittenOverOutsideFormVoxGetsItsDataBack(): void {
		$intake = $this->intake('mallory');
		$this->writeOutside($intake, $this->storedFile('Survey.fvform', json_encode($this->stored())), $this->upload());

		$form = $this->onDisk('Survey.fvform');
		$this->assertSame('Survey, edited', $form['title']);
		$this->assertSame(['r1'], array_column($form['responses'], 'id'));
		$this->assertSame('TOK', $form['settings']['public_token']);
		$this->assertSame('anna', $form['permissions']['owner']);
	}

	public function testAWriteThatWouldBreakTheFormIsUndone(): void {
		$original = json_encode($this->stored());
		$this->writeOutside($this->intake(), $this->storedFile('Survey.fvform', $original), $this->upload(['questions' => 'x']));

		$this->assertSame($original, $this->disk['Survey.fvform']);
		$this->assertSame([], $this->removed);
	}

	/**
	 * Left in place, an upload FormVox could not take in would stand as written: its own responses, keys and webhooks
	 * in the form, the server's gone.
	 */
	public function testAWriteThatFailsToBeTakenInIsUndone(): void {
		$original = json_encode($this->stored());
		$merged = fn (string $data): bool => str_contains($data, 'Survey, edited');

		$this->writeOutside($this->intake(), $this->storedFile('Survey.fvform', $original, $merged), $this->upload());

		$this->assertSame($original, $this->disk['Survey.fvform']);
		$this->assertSame([], $this->removed);
	}

	public function testANewFormFileThatFailsToBeTakenInIsRemoved(): void {
		$merged = fn (string $data): bool => str_contains($data, '"created_at"');

		$this->writeOutside($this->intake(), $this->storedFile('New.fvform', null, $merged), $this->upload());

		$this->assertSame(['New.fvform'], $this->removed);
	}

	public function testAnUploadWithANumberThatReadsAsInfinityIsUndone(): void {
		$original = json_encode($this->stored());
		$upload = str_replace('"INF"', '1e999', $this->upload(['questions' => [['id' => 'q1', 'type' => 'number', 'min' => 0, 'max' => 'INF']]]));

		$this->writeOutside($this->intake(), $this->storedFile('Survey.fvform', $original), $upload);

		$this->assertSame($original, $this->disk['Survey.fvform']);
	}

	public function testANewFormFileWrittenOutsideFormVoxIsTakenInOrRemovedWhenItWouldNotOpen(): void {
		$intake = $this->intake('bob');
		$this->writeOutside($intake, $this->storedFile('New.fvform', null), $this->upload());
		$this->writeOutside($intake, $this->storedFile('Broken.fvform', null), $this->upload(['questions' => 'x']));
		$this->writeOutside($intake, $this->storedFile('Notes.fvform', null), 'just text');

		$this->assertSame('bob', $this->onDisk('New.fvform')['permissions']['owner']);
		$this->assertSame([], $this->onDisk('New.fvform')['responses']);
		$this->assertSame(['Broken.fvform'], $this->removed);
		$this->assertSame('just text', $this->disk['Notes.fvform']);
	}

	/** FormVox's own writes hold every response; merging them would lose the one just added. */
	public function testFormVoxsOwnWritesAreLeftAlone(): void {
		$intake = $this->intake();
		$file = $this->storedFile('Survey.fvform', json_encode($this->stored()));
		$full = json_encode(['title' => 'Survey', 'responses' => [['id' => 'r1'], ['id' => 'r2']]]);

		$this->ownWrites->during(fn () => $this->writeOutside($intake, $file, $full));

		$this->assertSame($full, $this->disk['Survey.fvform']);
		$this->assertSame([], $this->written);
	}

	/** Without seeing what was there, nothing can be kept, so nothing is touched. */
	public function testAWriteWhoseStartWasNotSeenIsLeftAlone(): void {
		$file = $this->storedFile('Survey.fvform', $this->upload());

		$this->intake()->written($file);

		$this->assertSame([], $this->written);
	}

	/** PO 5: a copy's versions are the original's; a form written over keeps its own history. */
	public function testOnlyACopyLosesTheVersionsItTookAlong(): void {
		$this->versionCleaner = $this->createMock(IFormVersionCleaner::class);
		$this->versionCleaner->expects($this->once())->method('deleteVersionsForFile');
		$intake = $this->intake();

		$intake->copied($this->form('Copy.fvform'));
		$this->writeOutside($intake, $this->storedFile('Survey.fvform', json_encode($this->stored())), $this->upload());
	}

	/** Another app renaming a file into a form (WebDAV refuses it): taken in like a new form (PO 5). */
	public function testAFileRenamedIntoAFormIsTakenInAsANewOne(): void {
		$intake = $this->intake('bob');
		$source = $this->createMock(File::class);
		$source->method('getName')->willReturn('notes.json');

		$intake->renamed($source, $this->storedFile('notes.fvform', json_encode($this->stored())));

		$form = $this->onDisk('notes.fvform');
		$this->assertSame([], $form['responses']);
		$this->assertSame('bob', $form['permissions']['owner']);
		$this->assertArrayNotHasKey('public_token', $form['settings']);
	}

	public function testAFormRenamedToAnotherFormNameIsLeftAlone(): void {
		$source = $this->createMock(File::class);
		$source->method('getName')->willReturn('Old title.fvform');

		$this->intake()->renamed($source, $this->storedFile('New title.fvform', json_encode($this->stored())));

		$this->assertSame([], $this->written);
	}

	/** @return array<string, array{string}> */
	public static function encryptionRoundTrips(): array {
		return [
			'occ encryption:encrypt-all' => ['Survey.fvform.encrypted.1792000000'],
			'occ encryption:decrypt-all' => ['Survey.fvform.decrypted.20261022'],
		];
	}

	/**
	 * Encrypting or decrypting every file copies each one aside and renames the copy back over it: the form keeps
	 * its responses, link, keys and creator (it lost them all before).
	 *
	 * @dataProvider encryptionRoundTrips
	 */
	public function testAFormRenamedBackOverItselfKeepsItsResponses(string $aside): void {
		$intake = $this->intake('admin');
		$form = $this->storedFile('Survey.fvform', json_encode($this->stored()));
		$source = $this->createMock(File::class);
		$source->method('getName')->willReturn($aside);

		$intake->beforeRename($source, $form);
		$intake->renamed($source, $form);

		$after = $this->onDisk('Survey.fvform');
		$this->assertSame(['r1'], array_column($after['responses'], 'id'));
		$this->assertSame('TOK', $after['settings']['public_token']);
		$this->assertSame('anna', $after['permissions']['owner']);
		$this->assertSame([], $this->removed);
		$this->assertSame([], $this->written, 'the same bytes back are not rewritten, so the form is not changed by the run');
	}

	/** A rename that never happened (Nextcloud sends no event after it) is not remembered for the rest of the run. */
	public function testARenameThatNeverHappenedIsForgotten(): void {
		$intake = $this->intake();
		$source = $this->createMock(File::class);
		$source->method('getName')->willReturn('Survey.fvform.encrypted.1792000000');

		$intake->beforeRename($source, $this->storedFile('One.fvform', json_encode($this->stored())));
		$intake->beforeRename($source, $this->storedFile('Two.fvform', json_encode($this->stored())));

		$before = (new \ReflectionProperty(FormIntake::class, 'before'))->getValue($intake);
		$this->assertSame(['/bob/files/Two.fvform'], array_keys($before));
	}

	/**
	 * No room to put the server's data back next to content written over the form: those bytes were counted before
	 * the write, so they go back past the quota. The write is neither undone nor removed (review 3a-1).
	 */
	public function testAWriteOverAFormWithoutRoomStillGetsItsDataBack(): void {
		$this->free = 10;
		$this->writeOutside($this->intake(), $this->storedFile('Survey.fvform', json_encode($this->stored())), $this->upload());

		$form = $this->onDisk('Survey.fvform');
		$this->assertSame('Survey, edited', $form['title']);
		$this->assertSame(['r1'], array_column($form['responses'], 'id'));
		$this->assertSame([], $this->removed);
	}

	/**
	 * A new form file without room for its cleaned version: cleaned past the quota, as cleaning lays it out and adds a
	 * few fields but never brings more than the upload did; never left with the link, keys or responses it came with.
	 */
	public function testANewFormWithoutRoomIsStillCleaned(): void {
		$this->free = 10;
		$intake = $this->intake('bob');
		$responses = array_map(fn (int $i) => ['id' => 'r' . $i, 'answers' => ['q1' => str_repeat('x', 200)]], range(1, 50));

		$this->writeOutside($intake, $this->storedFile('Big.fvform', null), $this->upload(['responses' => $responses]));
		$this->writeOutside($intake, $this->storedFile('Small.fvform', null), $this->upload());

		foreach (['Big.fvform', 'Small.fvform'] as $name) {
			$this->assertSame([], $this->onDisk($name)['responses'], $name);
			$this->assertSame('bob', $this->onDisk($name)['permissions']['owner'], $name);
			$this->assertArrayNotHasKey('public_token', $this->onDisk($name)['settings'], $name);
		}
		$this->assertSame([], $this->removed);
	}

	public function testSomethingRenamedOverAFormThatWouldBreakItIsUndone(): void {
		$intake = $this->intake();
		$original = json_encode($this->stored());
		$form = $this->storedFile('Survey.fvform', $original);
		$source = $this->createMock(File::class);
		$source->method('getName')->willReturn('notes.txt');

		$intake->beforeRename($source, $form);
		$this->disk['Survey.fvform'] = 'just text';
		$intake->renamed($source, $form);

		$this->assertSame($original, $this->disk['Survey.fvform']);
		$this->assertSame([], $this->removed);
	}

	public function testAFileRenamedOntoAFreeFormNameIsStillANewForm(): void {
		$intake = $this->intake('bob');
		$target = $this->storedFile('notes.fvform', null);
		$source = $this->createMock(File::class);
		$source->method('getName')->willReturn('notes.json');

		$intake->beforeRename($source, $target);
		$this->disk['notes.fvform'] = json_encode($this->stored());
		$intake->renamed($source, $target);

		$this->assertSame([], $this->onDisk('notes.fvform')['responses']);
		$this->assertSame('bob', $this->onDisk('notes.fvform')['permissions']['owner']);
	}

	/** Restoring from the trash renames "x.fvform.d1700000000" back: its responses must stay. */
	public function testAFormBackFromTheTrashIsLeftAlone(): void {
		$source = $this->createMock(File::class);
		$source->method('getName')->willReturn('Survey.fvform.d1700000000');

		$this->intake()->renamed($source, $this->storedFile('Survey.fvform', json_encode($this->stored())));

		$this->assertSame([], $this->written);
	}
}
