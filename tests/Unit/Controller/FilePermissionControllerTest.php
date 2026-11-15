<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\FilePermissionController;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\PermissionService;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\IRequest;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/permissions/{fileId} reports Nextcloud's own permissions on the
 * form, through the same PermissionService every other endpoint uses.
 * Access to the form itself is checked by FormAccessMiddleware.
 */
class FilePermissionControllerTest extends TestCase {
	private FormAccessService $access;

	protected function setUp(): void {
		parent::setUp();
		$this->access = $this->createMock(FormAccessService::class);
	}

	private function controller(): FilePermissionController {
		return new FilePermissionController(
			$this->createMock(IRequest::class),
			$this->access,
			new PermissionService()
		);
	}

	private function file(bool $update, bool $delete, bool $share, ?IStorage $storage = null): File {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('isUpdateable')->willReturn($update);
		$file->method('isDeletable')->willReturn($delete);
		$file->method('isShareable')->willReturn($share);
		$file->method('getStorage')->willReturn($storage ?? $this->createMock(IStorage::class));
		$this->access->method('getFile')->with(1)->willReturn($file);
		return $file;
	}

	public function testReadOnlyFileIsReportedAsViewer(): void {
		$this->file(false, false, false);

		$this->assertSame([
			'canRead' => true,
			'canEdit' => false,
			'canDelete' => false,
			'canDownload' => true,
			'canShare' => false,
			'role' => PermissionService::ROLE_VIEWER,
		], $this->controller()->getPermissions(1)->getData());
	}

	public function testFullPermissionsAreReportedAsAdmin(): void {
		$this->file(true, true, true);

		$data = $this->controller()->getPermissions(1)->getData();
		$this->assertSame(PermissionService::ROLE_ADMIN, $data['role']);
		$this->assertTrue($data['canEdit']);
		$this->assertTrue($data['canDelete']);
		$this->assertTrue($data['canShare']);
	}

	/** Sharing reaches outside Nextcloud, so it needs full rights, not just reshare. */
	public function testEditorWithReshareButNoDeleteCannotShare(): void {
		$this->file(true, false, true);

		$data = $this->controller()->getPermissions(1)->getData();
		$this->assertSame(PermissionService::ROLE_EDITOR, $data['role']);
		$this->assertTrue($data['canEdit']);
		$this->assertFalse($data['canShare']);
	}

	public function testShareWithoutDownloadIsReported(): void {
		$attributes = $this->createMock(IAttributes::class);
		$attributes->method('getAttribute')->with('permissions', 'download')->willReturn(false);
		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$share->method('getAttributes')->willReturn($attributes);
		$storage = $this->createMock(ISharedStorage::class);
		$storage->method('instanceOfStorage')->with(ISharedStorage::class)->willReturn(true);
		$storage->method('getShare')->willReturn($share);
		$this->file(false, false, false, $storage);

		$data = $this->controller()->getPermissions(1)->getData();
		$this->assertTrue($data['canRead']);
		$this->assertFalse($data['canDownload']);
	}
}
