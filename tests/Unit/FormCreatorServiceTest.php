<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\AccountAccess;
use OCA\FormVox\Service\FormCreatorService;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\PermissionService;
use OCP\Files\File;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Who created a form, and whether they can still reach it (#146).
 */
class FormCreatorServiceTest extends TestCase {
	private IUserManager $userManager;
	private FormFileLocator $fileLocator;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->fileLocator = $this->createMock(FormFileLocator::class);
	}

	private function service(): FormCreatorService {
		return new FormCreatorService(new AccountAccess($this->userManager, $this->fileLocator, new PermissionService()));
	}

	private function user(string $displayName, bool $enabled = true): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn($displayName);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}

	private function form(?string $creator): array {
		return ['title' => 'F', 'permissions' => ['owner' => $creator, 'roles' => []]];
	}

	// ---- getCreator --------------------------------------------------------

	public function testCreatorWhoCanOpenTheFormIsOk(): void {
		$this->userManager->method('get')->with('anna')->willReturn($this->user('Anna'));
		$this->fileLocator->expects($this->once())->method('getFileForUser')
			->with('anna', 7)->willReturn($this->createMock(File::class));

		$this->assertSame(
			['id' => 'anna', 'displayName' => 'Anna', 'status' => FormCreatorService::STATUS_OK],
			$this->service()->getCreator(7, $this->form('anna'))
		);
	}

	public function testCreatorWhoCanNoLongerOpenTheFormHasNoAccess(): void {
		$this->userManager->method('get')->willReturn($this->user('Anna'));
		$this->fileLocator->method('getFileForUser')->willReturn(null);

		$this->assertSame(
			['id' => 'anna', 'displayName' => 'Anna', 'status' => FormCreatorService::STATUS_NO_ACCESS],
			$this->service()->getCreator(7, $this->form('anna'))
		);
	}

	public function testDisabledCreatorIsReportedWithoutCheckingAccess(): void {
		$this->userManager->method('get')->willReturn($this->user('Anna', false));
		$this->fileLocator->expects($this->never())->method('getFileForUser');

		$this->assertSame(
			FormCreatorService::STATUS_DISABLED,
			$this->service()->getCreator(7, $this->form('anna'))['status']
		);
	}

	public function testDeletedCreatorKeepsTheIdAsName(): void {
		$this->userManager->method('get')->willReturn(null);
		$this->fileLocator->expects($this->never())->method('getFileForUser');

		$this->assertSame(
			['id' => 'anna', 'displayName' => 'anna', 'status' => FormCreatorService::STATUS_DELETED],
			$this->service()->getCreator(7, $this->form('anna'))
		);
	}

	public function testFormWithoutRecordedCreator(): void {
		$this->userManager->expects($this->never())->method('get');

		foreach ([[], ['permissions' => []], $this->form(null), $this->form(''), ['permissions' => ['owner' => 42]]] as $form) {
			$this->assertSame(
				['id' => null, 'displayName' => null, 'status' => FormCreatorService::STATUS_NONE],
				$this->service()->getCreator(7, $form)
			);
		}
	}

	// ---- withCreator / getCreatorId ---------------------------------------

	public function testWithCreatorSetsTheCreatorAndKeepsTheRestOfPermissions(): void {
		$form = FormCreatorService::withCreator(['permissions' => ['owner' => 'anna', 'roles' => ['x']]], 'bob');

		$this->assertSame(['owner' => 'bob', 'roles' => ['x']], $form['permissions']);
		$this->assertSame('bob', FormCreatorService::getCreatorId($form));
	}

	public function testWithCreatorNullClearsIt(): void {
		$form = FormCreatorService::withCreator(['permissions' => ['owner' => 'anna', 'roles' => []]], null);

		$this->assertSame(['roles' => []], $form['permissions']);
		$this->assertNull(FormCreatorService::getCreatorId($form));
	}

	public function testWithCreatorRepairsMissingOrBrokenPermissions(): void {
		$this->assertSame(['owner' => 'bob'], FormCreatorService::withCreator([], 'bob')['permissions']);
		$this->assertSame(['owner' => 'bob'], FormCreatorService::withCreator(['permissions' => 'junk'], 'bob')['permissions']);
	}
}
