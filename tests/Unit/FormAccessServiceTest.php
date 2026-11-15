<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Security\FormAccessDeniedException;
use OCA\FormVox\Security\FormNotFoundException;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\PermissionService;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class FormAccessServiceTest extends TestCase {
	private FormFileLocator $locator;
	private IUserSession $session;
	private string $uid = 'alice';

	protected function setUp(): void {
		parent::setUp();
		$this->locator = $this->createMock(FormFileLocator::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturnCallback(function () {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->uid);
			return $user;
		});
	}

	private function service(): FormAccessService {
		return new FormAccessService($this->locator, new PermissionService(), $this->session);
	}

	private function file(bool $read, bool $update = false): File {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn($read);
		$file->method('isUpdateable')->willReturn($update);
		$file->method('getStorage')->willReturn($this->createMock(IStorage::class));
		return $file;
	}

	public function testTheFileIsOpenedOncePerUserPerRequest(): void {
		$alice = $this->file(true);
		$bob = $this->file(true);
		$this->locator->expects($this->exactly(2))->method('getFileById')->with(5)
			->willReturnOnConsecutiveCalls($alice, $bob);
		$service = $this->service();

		$this->assertSame($alice, $service->getFile(5));
		$this->assertSame($alice, $service->getFile(5));
		$this->uid = 'bob';
		$this->assertSame($bob, $service->getFile(5));
	}

	public function testNotInTheUsersViewIsNotFound(): void {
		$this->locator->method('getFileById')->willThrowException(new NotFoundException());

		$this->expectException(FormNotFoundException::class);
		$this->service()->getFile(5);
	}

	public function testAFolderIdIsNotFound(): void {
		$this->locator->method('getFileById')->willThrowException(new \RuntimeException('Not a file'));

		$this->expectException(FormNotFoundException::class);
		$this->service()->getFile(5);
	}

	public function testVisibleButUnreadableIsNotFound(): void {
		$this->locator->method('getFileById')->willReturn($this->file(false));

		$this->expectException(FormNotFoundException::class);
		$this->service()->require(5, FormPermission::READ);
	}

	public function testReadableWithoutThePermissionIsDenied(): void {
		$this->locator->method('getFileById')->willReturn($this->file(true));

		$this->expectException(FormAccessDeniedException::class);
		$this->service()->require(5, FormPermission::EDIT);
	}

	public function testGrantedReturnsTheFile(): void {
		$file = $this->file(true, true);
		$this->locator->method('getFileById')->willReturn($file);

		$this->assertSame($file, $this->service()->require(5, FormPermission::EDIT));
	}
}
