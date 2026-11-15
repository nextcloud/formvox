<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\BackgroundJob\AccountFormsJob;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\StoredForm;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * The forms of a deleted account go with it: those whose file lay in its home, its trash too, are removed by a
 * background job (AccountFormsJob). Nextcloud empties the home and forgets its files and its storage without a word
 * per file, so the forms in it are noted before, while the storage is known. A form the account only had access to
 * (shared with it, in a team folder) lies in another storage and stays.
 *
 * @template-implements IEventListener<BeforeUserDeletedEvent|UserDeletedEvent>
 */
class AccountDeletedListener implements IEventListener {
	/** @var array<string, list<StoredForm>> the forms in the home of each account about to be deleted */
	private array $noted = [];

	public function __construct(
		private FormStore $forms,
		private IJobList $jobs,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		// Never a reason to fail deleting an account.
		try {
			if ($event instanceof BeforeUserDeletedEvent) {
				$uid = $event->getUser()->getUID();
				$homes = $this->forms->homeStorages($uid);
				$forms = $homes === [] ? [] : $this->forms->inStorages($homes);
				if ($forms !== []) {
					$this->noted[$uid] = $forms;
				}
			} elseif ($event instanceof UserDeletedEvent) {
				$uid = $event->getUser()->getUID();
				$forms = $this->noted[$uid] ?? [];
				unset($this->noted[$uid]);
				$pairs = array_map(fn (StoredForm $form): array => [$form->id, $form->fileId], $forms);
				foreach (array_chunk($pairs, AccountFormsJob::PER_JOB) as $chunk) {
					$this->jobs->add(AccountFormsJob::class, ['forms' => $chunk]);
				}
			}
		} catch (\Throwable $e) {
			// The daily sweep finds what is left, and gives it its grace.
			$this->logger->error('FormVox: could not note the forms of a deleted account: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}
}
