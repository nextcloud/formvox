<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Listener;

use OCA\FormVox\BackgroundJob\AccountFormsJob;
use OCA\FormVox\Listener\AccountDeletedListener;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\StoredForm;
use OCP\BackgroundJob\IJobList;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which forms a deleted account takes along, and when: only those noted in its home before, handed to a background
 * job once it is deleted.
 */
class AccountDeletedListenerTest extends TestCase {
	private FormStore&MockObject $forms;
	private IJobList&MockObject $jobs;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->forms = $this->createMock(FormStore::class);
		$this->jobs = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function listener(): AccountDeletedListener {
		return new AccountDeletedListener($this->forms, $this->jobs, $this->logger);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testTheFormsNotedInTheHomeAreHandedToAJobOnceTheAccountIsDeleted(): void {
		$this->forms->expects($this->once())->method('homeStorages')->with('anna')->willReturn([7]);
		$this->forms->expects($this->once())->method('inStorages')->with([7])->willReturn([new StoredForm(1, 'u', 10, 7), new StoredForm(2, 'v', 11, 7)]);
		$this->jobs->expects($this->once())->method('add')->with(AccountFormsJob::class, ['forms' => [[1, 10], [2, 11]]]);
		$listener = $this->listener();

		$listener->handle(new BeforeUserDeletedEvent($this->user('anna')));
		$listener->handle(new UserDeletedEvent($this->user('anna')));
		// Asked once: a second event for the same account adds nothing.
		$listener->handle(new UserDeletedEvent($this->user('anna')));
	}

	public function testManyFormsAreSplitOverJobs(): void {
		$forms = array_map(fn (int $i) => new StoredForm($i, 'u' . $i, 1000 + $i, 7), range(1, AccountFormsJob::PER_JOB + 1));
		$this->forms->method('homeStorages')->willReturn([7]);
		$this->forms->method('inStorages')->willReturn($forms);
		$sizes = [];
		$this->jobs->method('add')->willReturnCallback(function (string $class, array $argument) use (&$sizes): void {
			$sizes[] = count($argument['forms']);
		});
		$listener = $this->listener();

		$listener->handle(new BeforeUserDeletedEvent($this->user('anna')));
		$listener->handle(new UserDeletedEvent($this->user('anna')));

		$this->assertSame([AccountFormsJob::PER_JOB, 1], $sizes);
	}

	public function testAnAccountWhoseDeletionWasCalledOffTakesNothing(): void {
		$this->forms->method('homeStorages')->willReturn([7]);
		$this->forms->method('inStorages')->willReturn([new StoredForm(1, 'u', 10, 7)]);
		$this->jobs->expects($this->never())->method('add');
		$listener = $this->listener();

		$listener->handle(new BeforeUserDeletedEvent($this->user('anna')));
		$listener->handle(new UserDeletedEvent($this->user('bob')));
	}

	public function testAnAccountWithoutAStorageAsksForNoForms(): void {
		$this->forms->method('homeStorages')->willReturn([]);
		$this->forms->expects($this->never())->method('inStorages');
		$this->jobs->expects($this->never())->method('add');
		$listener = $this->listener();

		$listener->handle(new BeforeUserDeletedEvent($this->user('anna')));
		$listener->handle(new UserDeletedEvent($this->user('anna')));
	}

	public function testAFailureNeverStopsTheAccountBeingDeleted(): void {
		$this->forms->method('homeStorages')->willThrowException(new \RuntimeException('database away'));
		$this->logger->expects($this->once())->method('error');

		$this->listener()->handle(new BeforeUserDeletedEvent($this->user('anna')));
	}
}
