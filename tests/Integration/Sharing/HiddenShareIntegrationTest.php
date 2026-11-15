<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Sharing;

use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * A form's own folders, and all in them, cannot be shared by link; sharing them with a colleague still works.
 *
 * @group DB
 */
class HiddenShareIntegrationTest extends IntegrationTestCase {
	/** Its own colleague: Nextcloud remembers a deleted user's mounts within one test run. */
	private const COLLEAGUE = 'formvox-int-colleague-share';

	private const REFUSAL = 'Files and folders that belong to a form cannot be shared by link, email, Talk or Teams.';

	private Folder $shared;
	private Folder $hidden;

	protected function setUp(): void {
		parent::setUp();
		$this->shared = $this->userFolder->newFolder('Shared-' . bin2hex(random_bytes(4)));
		$this->hidden = $this->shared->newFolder('.formvox-uploads-7');
		$this->hidden->newFile('photo.jpg', 'respondent photo');
	}

	public static function tearDownAfterClass(): void {
		Server::get(\OCP\IUserManager::class)->get(self::COLLEAGUE)?->delete();
		parent::tearDownAfterClass();
	}

	protected function tearDown(): void {
		$this->loginAs($this->ownerUid, $this->owner);
		try {
			$this->shared->delete();
		} catch (\Throwable) {
		}
		parent::tearDown();
	}

	public function testALinkOnTheFolderOrAFileInItIsRefused(): void {
		foreach ([$this->hidden, $this->hidden->get('photo.jpg')] as $node) {
			try {
				$this->link($node, $this->ownerUid);
				$this->fail('a link on ' . $node->getName());
			} catch (\Exception $e) {
				$this->assertSame(self::REFUSAL, $e->getMessage());
			}
			$this->assertSame([], Server::get(IShareManager::class)->getSharesBy($this->ownerUid, IShare::TYPE_LINK, $node), 'no link was made');
		}
	}

	public function testOtherItemsAndColleaguesAreSharedAsBefore(): void {
		$this->assertNotSame('', $this->link($this->shared, $this->ownerUid)->getToken());

		$share = $this->shareWithColleague($this->hidden);
		$this->assertSame(IShare::TYPE_USER, $share->getShareType());
	}

	/**
	 * A folder shared with a colleague that its owner later moved into a form's folder keeps its old name for the
	 * colleague; it is judged by where it lies for its owner.
	 */
	public function testAShareIsJudgedByTheOwnersPath(): void {
		$handedOver = $this->shared->newFolder('handed-over');
		$this->shareWithColleague($handedOver);
		$handedOver->move($this->hidden->getPath() . '/handed-over');

		$this->switchToUser(self::COLLEAGUE);
		$this->expectExceptionMessage(self::REFUSAL);
		$this->link($this->userFolder->get('handed-over'), self::COLLEAGUE);
	}

	private function link(Node $node, string $by): IShare {
		$shares = Server::get(IShareManager::class);
		$share = $shares->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_LINK)
			->setSharedBy($by)
			->setPermissions(Constants::PERMISSION_READ);
		return $shares->createShare($share);
	}

	private function shareWithColleague(Node $node): IShare {
		$this->ensureUser(self::COLLEAGUE);
		$shares = Server::get(IShareManager::class);
		$share = $shares->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_USER)
			->setSharedWith(self::COLLEAGUE)
			->setSharedBy($this->ownerUid)
			->setPermissions(Constants::PERMISSION_ALL);
		return $shares->createShare($share);
	}
}
