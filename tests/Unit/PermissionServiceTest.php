<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Service\PermissionService;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * FormVox rights are Nextcloud's rights on the .fvform; the integration matrix proves it against real NC.
 */
class PermissionServiceTest extends TestCase {
	private function file(bool $read, bool $update, bool $delete, bool $share = false, ?IStorage $storage = null): File {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn($read);
		$file->method('isUpdateable')->willReturn($update);
		$file->method('isDeletable')->willReturn($delete);
		$file->method('isShareable')->willReturn($share);
		$file->method('getStorage')->willReturn($storage ?? $this->createMock(IStorage::class));
		return $file;
	}

	/**
	 * @return array<string, array{bool, bool, bool, string}>
	 */
	public static function nextcloudPermissions(): array {
		return [
			'no read' => [false, false, false, PermissionService::ROLE_NONE],
			'no read, even with update' => [false, true, true, PermissionService::ROLE_NONE],
			'read only' => [true, false, false, PermissionService::ROLE_VIEWER],
			'read + delete, no update' => [true, false, true, PermissionService::ROLE_VIEWER],
			'read + update' => [true, true, false, PermissionService::ROLE_EDITOR],
			'read + update + delete' => [true, true, true, PermissionService::ROLE_ADMIN],
		];
	}

	/**
	 * @dataProvider nextcloudPermissions
	 */
	public function testRoleFollowsNextcloudPermissions(bool $read, bool $update, bool $delete, string $role): void {
		$this->assertSame($role, (new PermissionService())->getRoleFromFile($this->file($read, $update, $delete)));
	}

	/** In a team folder getOwner() is whoever is looking, so it must grant nothing (#146). */
	public function testStorageOwnerGivesNoExtraRights(): void {
		$file = $this->file(true, false, false);
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('anna');
		$file->method('getOwner')->willReturn($owner);

		$service = new PermissionService();
		$this->assertSame(PermissionService::ROLE_VIEWER, $service->getRoleFromFile($file));
		$this->assertFalse($service->allows($file, FormPermission::MANAGE_SHARING));
	}

	/**
	 * @return array<string, array{array{bool, bool, bool, bool}, array<string, bool>}>
	 */
	public static function allowsMatrix(): array {
		$p = fn (bool $read, bool $edit, bool $delete, bool $manage) => [
			FormPermission::READ => $read,
			FormPermission::EDIT => $edit,
			FormPermission::DELETE => $delete,
			FormPermission::VIEW_CONTENT => $read,
			FormPermission::DOWNLOAD => $read,
			FormPermission::MANAGE_SHARING => $manage,
		];
		return [
			'no read' => [[false, true, true, true], $p(false, false, false, false)],
			'read only' => [[true, false, false, false], $p(true, false, false, false)],
			'read + share' => [[true, false, false, true], $p(true, false, false, false)],
			'read + update' => [[true, true, false, false], $p(true, true, false, false)],
			'read + update + share, no delete' => [[true, true, false, true], $p(true, true, false, false)],
			'everything' => [[true, true, true, true], $p(true, true, true, true)],
		];
	}

	/**
	 * @dataProvider allowsMatrix
	 * @param array{bool, bool, bool, bool} $node read, update, delete, share
	 * @param array<string, bool> $expected
	 */
	public function testAllowsFollowsNextcloudPermissions(array $node, array $expected): void {
		$file = $this->file(...$node);
		$service = new PermissionService();

		foreach ($expected as $permission => $allowed) {
			$this->assertSame($allowed, $service->allows($file, $permission), $permission);
		}
	}

	/** @param bool $seeContent Nextcloud's own verdict (IShare::canSeeContent): download allowed, or previews allowed without it */
	private function sharedFile(int $sharePermissions, ?bool $downloadAttribute, bool $nodeDeletable = false, bool $seeContent = true): File {
		$attributes = $this->createMock(IAttributes::class);
		$attributes->method('getAttribute')->with('permissions', 'download')->willReturn($downloadAttribute);
		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn($sharePermissions);
		$share->method('getAttributes')->willReturn($attributes);
		$share->method('canSeeContent')->willReturn($seeContent);

		$storage = $this->createMock(ISharedStorage::class);
		$storage->method('instanceOfStorage')->with(ISharedStorage::class)->willReturn(true);
		$storage->method('getShare')->willReturn($share);

		return $this->file(true, true, $nodeDeletable, true, $storage);
	}

	/**
	 * Nextcloud's "allow preview even if download is disabled" decides whether a share without download may see
	 * what the file holds; FormVox asks the share, so the responses follow the same switch as a preview in Files.
	 */
	public function testAShareNextcloudLetsLookAtButNotSeeTheContentOfGetsNoResponses(): void {
		$service = new PermissionService();
		$noPreview = $this->sharedFile(Constants::PERMISSION_READ, false, false, false);
		$preview = $this->sharedFile(Constants::PERMISSION_READ, false, false, true);

		$this->assertTrue($service->allows($noPreview, FormPermission::READ));
		$this->assertFalse($service->allows($noPreview, FormPermission::VIEW_CONTENT));
		$this->assertFalse($service->getPermissionsForFile($noPreview)['viewResponses']);

		$this->assertTrue($service->allows($preview, FormPermission::VIEW_CONTENT));
		$this->assertTrue($service->getPermissionsForFile($preview)['viewResponses']);
	}

	public function testAFileOfOnesOwnShowsItsContent(): void {
		$this->assertTrue((new PermissionService())->canSeeContent($this->file(true, false, false)));
	}

	/**
	 * Nextcloud reports a received share as deletable (taking it out of your files) when the node was loaded
	 * through the view, e.g. after a write; the form's delete right is the share's own, however it was loaded.
	 */
	public function testAReceivedShareMayDeleteTheFormOnlyWhenTheShareAllowsIt(): void {
		$service = new PermissionService();
		$withoutDelete = $this->sharedFile(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_SHARE, null, true);
		$withDelete = $this->sharedFile(Constants::PERMISSION_ALL, null, true);

		$this->assertFalse($service->allows($withoutDelete, FormPermission::DELETE));
		$this->assertFalse($service->allows($withoutDelete, FormPermission::MANAGE_SHARING));
		$this->assertSame(PermissionService::ROLE_EDITOR, $service->getRoleFromFile($withoutDelete));
		$this->assertFalse($service->getPermissionsForFile($withoutDelete)['deleteForm']);

		$this->assertTrue($service->allows($withDelete, FormPermission::DELETE));
		$this->assertSame(PermissionService::ROLE_ADMIN, $service->getRoleFromFile($withDelete));
	}

	public function testShareWithoutDownloadPermissionMayNotDownload(): void {
		$service = new PermissionService();

		$this->assertFalse($service->allows($this->sharedFile(Constants::PERMISSION_READ, false), FormPermission::DOWNLOAD));
		$this->assertTrue($service->allows($this->sharedFile(Constants::PERMISSION_READ, false), FormPermission::READ));
	}

	public function testShareWithDownloadOrNoAttributeMayDownload(): void {
		$service = new PermissionService();

		$this->assertTrue($service->allows($this->sharedFile(Constants::PERMISSION_READ, true), FormPermission::DOWNLOAD));
		$this->assertTrue($service->allows($this->sharedFile(Constants::PERMISSION_READ, null), FormPermission::DOWNLOAD));
	}

	public function testPermissionsForFileCarryShareAndDownloadFlags(): void {
		$file = $this->file(true, true, true, true);

		$flags = (new PermissionService())->getPermissionsForFile($file);

		$this->assertTrue($flags['canShare']);
		$this->assertTrue($flags['canDownload']);
		$this->assertTrue($flags['deleteForm']);
	}

	public function testViewerMayOnlyLook(): void {
		$p = (new PermissionService())->getPermissionsForRole(PermissionService::ROLE_VIEWER);

		$this->assertTrue($p['viewResponses']);
		$this->assertFalse($p['editQuestions']);
		$this->assertFalse($p['editSettings']);
		$this->assertFalse($p['deleteResponses']);
		$this->assertFalse($p['deleteForm']);
		$this->assertFalse($p['managePermissions']);
	}

	public function testEditorMayChangeButNotDeleteTheForm(): void {
		$p = (new PermissionService())->getPermissionsForRole(PermissionService::ROLE_EDITOR);

		$this->assertTrue($p['editQuestions']);
		$this->assertTrue($p['editSettings']);
		$this->assertTrue($p['deleteResponses']);
		$this->assertFalse($p['deleteForm']);
	}

	public function testOnlyNextcloudsDeletePermissionAllowsDeletingTheForm(): void {
		$service = new PermissionService();
		$this->assertTrue($service->canDeleteForm(PermissionService::ROLE_ADMIN));
		foreach ([PermissionService::ROLE_EDITOR, PermissionService::ROLE_VIEWER, PermissionService::ROLE_NONE] as $role) {
			$this->assertFalse($service->canDeleteForm($role), $role);
		}
	}

	public function testNoAccessMayDoNothing(): void {
		$p = (new PermissionService())->getPermissionsForRole(PermissionService::ROLE_NONE);

		$this->assertSame([], array_keys(array_filter($p)));
	}
}
