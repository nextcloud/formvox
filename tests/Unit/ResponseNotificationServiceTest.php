<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormCreatorService;
use OCA\FormVox\Service\ResponseNotificationService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who hears about a new response — the one place every channel uses (#146).
 */
class ResponseNotificationServiceTest extends TestCase {
	private FormCreatorService $creators;
	private INotificationManager $notificationManager;
	private IGroupManager $groupManager;
	private LoggerInterface $logger;
	/** @var list<array{user: string, subject: array}> */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();
		$this->creators = $this->createMock(FormCreatorService::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Record every notification that is actually sent.
		$built = [];
		$this->notificationManager->method('createNotification')->willReturnCallback(function () use (&$built) {
			$n = $this->createMock(INotification::class);
			$id = spl_object_id($n);
			$built[$id] = ['user' => '', 'subject' => []];
			$n->method('setApp')->willReturnSelf();
			$n->method('setDateTime')->willReturnSelf();
			$n->method('setObject')->willReturnSelf();
			$n->method('setUser')->willReturnCallback(function (string $uid) use ($n, $id, &$built) {
				$built[$id]['user'] = $uid;
				return $n;
			});
			$n->method('setSubject')->willReturnCallback(function (string $s, array $params) use ($n, $id, &$built) {
				$built[$id]['subject'] = $params;
				return $n;
			});
			return $n;
		});
		$this->notificationManager->method('notify')->willReturnCallback(function (INotification $n) use (&$built) {
			$this->sent[] = $built[spl_object_id($n)];
		});
	}

	private function service(): ResponseNotificationService {
		return new ResponseNotificationService($this->creators, $this->notificationManager, $this->groupManager, $this->logger);
	}

	private function creatorIs(string $status, ?string $id = 'anna'): void {
		$this->creators->method('getCreator')->willReturn(['id' => $id, 'displayName' => 'Anna', 'status' => $status]);
	}

	private function anonymous(): array {
		return ['id' => 'r1', 'respondent' => ['type' => 'anonymous']];
	}

	public function testCreatorWhoCanOpenTheFormIsNotified(): void {
		$this->creatorIs(FormCreatorService::STATUS_OK);

		$this->assertSame(['anna'], $this->service()->getRecipientIds(1, ['settings' => []]));
	}

	/**
	 * The heart of #146: a creator who left the team, was disabled or deleted
	 * gets nothing — and nobody is picked in their place.
	 */
	public function testCreatorWhoCannotOpenTheFormIsNotNotifiedAndNotReplaced(): void {
		foreach ([FormCreatorService::STATUS_NO_ACCESS, FormCreatorService::STATUS_DISABLED, FormCreatorService::STATUS_DELETED] as $status) {
			$creators = $this->createMock(FormCreatorService::class);
			$creators->method('getCreator')->willReturn(['id' => 'anna', 'displayName' => 'Anna', 'status' => $status]);
			$service = new ResponseNotificationService($creators, $this->notificationManager, $this->groupManager, $this->logger);

			$this->assertSame([], $service->getRecipientIds(1, ['settings' => []]), $status);
		}
	}

	public function testUnreachableCreatorIsLogged(): void {
		$this->creatorIs(FormCreatorService::STATUS_NO_ACCESS);
		$this->logger->expects($this->once())->method('info')
			->with($this->stringContains('not notifying form creator "anna": no_access'));

		$this->service()->getRecipientIds(1, ['settings' => []]);
	}

	public function testFormWithoutCreatorIsNotLogged(): void {
		$this->creatorIs(FormCreatorService::STATUS_NONE, null);
		$this->logger->expects($this->never())->method('info');

		$this->assertSame([], $this->service()->getRecipientIds(1, ['settings' => []]));
	}

	public function testNotifyOwnerFalseSkipsTheCreatorEntirely(): void {
		$this->creators->expects($this->never())->method('getCreator');

		$this->assertSame([], $this->service()->getRecipientIds(1, ['settings' => ['notify_owner' => false]]));
	}

	public function testExtraRecipientsAreStillNotifiedWhenTheCreatorIsGone(): void {
		$this->creatorIs(FormCreatorService::STATUS_NO_ACCESS);
		$member = $this->createMock(IUser::class);
		$member->method('getUID')->willReturn('carol');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$member]);
		$this->groupManager->method('get')->with('team')->willReturn($group);

		$form = ['settings' => ['notify_recipients' => [
			['type' => 'user', 'id' => 'bob'],
			['type' => 'group', 'id' => 'team'],
			['type' => 'user', 'id' => ''],
		]]];
		$this->assertSame(['bob', 'carol'], $this->service()->getRecipientIds(1, $form));
	}

	public function testRecipientsAreDeduplicatedAndTheRespondentLeftOut(): void {
		$this->creatorIs(FormCreatorService::STATUS_OK);
		$form = ['settings' => ['notify_recipients' => [
			['type' => 'user', 'id' => 'anna'],
			['type' => 'user', 'id' => 'bob'],
		]]];

		$this->assertSame(['anna'], $this->service()->getRecipientIds(1, $form, 'bob'));
	}

	public function testNotifySendsOneNotificationPerRecipient(): void {
		$this->creatorIs(FormCreatorService::STATUS_OK);
		$form = ['title' => 'Survey', 'settings' => ['notify_recipients' => [['type' => 'user', 'id' => 'bob']]]];

		$this->service()->notifyNewResponse(9, $form, $this->anonymous());

		$this->assertSame(['anna', 'bob'], array_column($this->sent, 'user'));
	}

	/** Anonymous: no name is sent, so the Notifier translates "Anonymous". */
	public function testAnonymousResponseSendsNoRespondentName(): void {
		$this->creatorIs(FormCreatorService::STATUS_OK);

		$this->service()->notifyNewResponse(9, ['title' => 'Survey', 'settings' => []], $this->anonymous());

		$this->assertSame(['formTitle' => 'Survey', 'fileId' => 9], $this->sent[0]['subject']);
	}

	public function testLoggedInResponseSendsTheRespondentsName(): void {
		$this->creatorIs(FormCreatorService::STATUS_OK);
		$response = ['id' => 'r1', 'respondent' => ['type' => 'user', 'user_id' => 'bob', 'display_name' => 'Bob']];

		$this->service()->notifyNewResponse(9, ['title' => 'Survey', 'settings' => []], $response);

		$this->assertSame('Bob', $this->sent[0]['subject']['respondentName']);
	}

	/** An API response carries no respondent at all. */
	public function testApiResponseWithoutRespondentIsTreatedAsAnonymous(): void {
		$this->creatorIs(FormCreatorService::STATUS_OK);

		$this->service()->notifyNewResponse(9, ['title' => 'Survey', 'settings' => []], ['id' => 'r1', 'source' => 'api']);

		$this->assertSame(['anna'], array_column($this->sent, 'user'));
		$this->assertArrayNotHasKey('respondentName', $this->sent[0]['subject']);
	}

	/** Nobody could be told: logged, not thrown, and worth trying again. */
	public function testWhenTheRecipientsCannotBeFoundItIsTriedAgain(): void {
		$this->creators->method('getCreator')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('could not tell who to notify of a new response: boom'));

		$this->assertFalse($this->service()->notifyNewResponse(9, ['settings' => []], $this->anonymous()));
		$this->assertSame([], $this->sent);
	}

	/** One recipient that fails holds back nobody else, and is not a reason to notify the others twice. */
	public function testOneRecipientThatFailsHoldsBackNobody(): void {
		$this->creatorIs(FormCreatorService::STATUS_OK);
		$form = ['title' => 'Survey', 'settings' => ['notify_recipients' => [['type' => 'user', 'id' => 'bob'], ['type' => 'user', 'id' => 'carol']]]];
		$failFor = 'bob';
		$notifier = $this->notificationManager;
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->notificationManager->method('createNotification')->willReturnCallback(fn () => $notifier->createNotification());
		$this->notificationManager->method('notify')->willReturnCallback(function (INotification $n) use ($notifier, &$failFor) {
			$notifier->notify($n);
			if (end($this->sent)['user'] === $failFor) {
				array_pop($this->sent);
				throw new \RuntimeException('notifications table locked');
			}
		});
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('could not notify bob'));

		$this->assertTrue($this->service()->notifyNewResponse(9, $form, $this->anonymous()));
		$this->assertSame(['anna', 'carol'], array_column($this->sent, 'user'));
	}
}
