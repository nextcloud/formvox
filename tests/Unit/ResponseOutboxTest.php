<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\ResponseOutbox;
use PHPUnit\Framework\TestCase;

/**
 * What an event on a response sets off on a form: only what the form has, so a form without webhooks, people to tell
 * or a confirmation queues nothing and costs no extra write (review 3a-4).
 */
class ResponseOutboxTest extends TestCase {
	/** @return array<string, array{0: array, 1: string, 2: list<string>, 3?: list<string>}> form, event, effects, and what the outbox entry does when that differs */
	public static function forms(): array {
		return [
			'nothing set' => [['settings' => ['notify_owner' => false]], ResponseOutbox::CREATED, []],
			'the creator is told by default' => [['settings' => []], ResponseOutbox::CREATED, [ResponseOutbox::NOTIFY]],
			'others to tell' => [['settings' => ['notify_owner' => false, 'notify_recipients' => [['type' => 'user', 'id' => 'b']]]], ResponseOutbox::CREATED, [ResponseOutbox::NOTIFY]],
			'a confirmation' => [['settings' => ['notify_owner' => false, 'sendConfirmationEmail' => true]], ResponseOutbox::CREATED, [ResponseOutbox::MAIL]],
			// A form that keeps no PDFs makes none, whoever would name it.
			'a webhook for every event, no PDF kept' => [['settings' => ['notify_owner' => false, 'webhooks' => [['id' => 'w1']]]], ResponseOutbox::UPDATED, [ResponseOutbox::WEBHOOKS]],
			'a webhook for a submission, no PDF kept' => [['settings' => ['notify_owner' => false, 'webhooks' => [['id' => 'w1']]]], ResponseOutbox::CREATED, [ResponseOutbox::WEBHOOKS]],
			'a confirmation asking for a PDF the form does not keep' => [['settings' => ['notify_owner' => false, 'sendConfirmationEmail' => true, 'confirmationEmailPdf' => true]], ResponseOutbox::CREATED, [ResponseOutbox::MAIL]],
			// A form that keeps PDFs: one with every submission, first, for the webhooks and the email to name.
			'a kept PDF with every submission, with nothing else set' => [['settings' => ['notify_owner' => false, 'keepResponsePdf' => true]], ResponseOutbox::CREATED, [], [ResponseOutbox::PDF]],
			'a kept PDF goes first, for the webhooks to name' => [['settings' => ['notify_owner' => false, 'keepResponsePdf' => true, 'webhooks' => [['id' => 'w1']]]], ResponseOutbox::CREATED, [ResponseOutbox::WEBHOOKS], [ResponseOutbox::PDF, ResponseOutbox::WEBHOOKS]],
			'a confirmation with its PDF' => [['settings' => ['notify_owner' => false, 'keepResponsePdf' => true, 'sendConfirmationEmail' => true, 'confirmationEmailPdf' => true]], ResponseOutbox::CREATED, [ResponseOutbox::MAIL], [ResponseOutbox::PDF, ResponseOutbox::MAIL]],
			'a changed response gets a new PDF only for a webhook' => [['settings' => ['notify_owner' => false, 'keepResponsePdf' => true, 'webhooks' => [['id' => 'w1']]]], ResponseOutbox::UPDATED, [ResponseOutbox::WEBHOOKS], [ResponseOutbox::PDF, ResponseOutbox::WEBHOOKS]],
			'a change nobody hears of gets no new PDF' => [['settings' => ['keepResponsePdf' => true, 'sendConfirmationEmail' => true]], ResponseOutbox::UPDATED, []],
			'a webhook hears of a deletion, which has no PDF' => [['settings' => ['notify_owner' => false, 'keepResponsePdf' => true, 'webhooks' => [['id' => 'w1']]]], ResponseOutbox::DELETED, [ResponseOutbox::WEBHOOKS]],
			'a disabled webhook' => [['settings' => ['notify_owner' => false, 'webhooks' => [['id' => 'w1', 'enabled' => false]]]], ResponseOutbox::CREATED, []],
			'a webhook for other events' => [['settings' => ['notify_owner' => false, 'webhooks' => [['id' => 'w1', 'events' => ['response.deleted']]]]], ResponseOutbox::CREATED, []],
			'no one told of a change' => [['settings' => ['sendConfirmationEmail' => true]], ResponseOutbox::UPDATED, []],
		];
	}

	/**
	 * @dataProvider forms
	 * @param list<string> $effects
	 */
	public function testOnlyWhatTheFormHasIsQueued(array $form, string $event, array $effects, ?array $todo = null): void {
		$this->assertSame($effects, ResponseOutbox::effectsFor($form, $event));
		$queued = ResponseOutbox::add($form, ['id' => 'r1'], [ResponseOutbox::WEBHOOKS, ResponseOutbox::NOTIFY, ResponseOutbox::MAIL], 'nl', $event);
		$todo ??= $effects;
		$this->assertSame($todo !== [], $queued);
		$this->assertSame($todo === [] ? null : $todo, $form[ResponseOutbox::KEY][0]['todo'] ?? null);
	}

	/** An import brings history, not submissions: it sets nothing off, so no PDF is made for it either. */
	public function testAnImportGetsNoPdf(): void {
		$form = ['settings' => ['keepResponsePdf' => true, 'webhooks' => [['id' => 'w1']]]];

		$this->assertFalse(ResponseOutbox::add($form, ['id' => 'old'], [], ''));
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $form);
	}

	public function testEveryUpdateHasItsOwnEventId(): void {
		$form = ['settings' => ['webhooks' => [['id' => 'w1']]]];
		ResponseOutbox::add($form, ['id' => 'r1'], [ResponseOutbox::WEBHOOKS], '', ResponseOutbox::UPDATED);
		ResponseOutbox::add($form, ['id' => 'r1'], [ResponseOutbox::WEBHOOKS], '', ResponseOutbox::UPDATED);

		[$first, $second] = array_column($form[ResponseOutbox::KEY], 'id');
		$this->assertNotSame($first, $second);
	}

	/** Whether the respondent was signed in rides with the entry, not with the response the owner sees. */
	public function testASignedInRespondentIsNotedOnTheEntryOnly(): void {
		$form = ['settings' => ['notify_owner' => false, 'sendConfirmationEmail' => true]];

		ResponseOutbox::add($form, ['id' => 'r1'], [ResponseOutbox::MAIL], 'nl', ResponseOutbox::CREATED, true);
		ResponseOutbox::add($form, ['id' => 'r2'], [ResponseOutbox::MAIL], 'nl');

		$this->assertTrue($form[ResponseOutbox::KEY][0]['signedIn']);
		$this->assertArrayNotHasKey('signedIn', $form[ResponseOutbox::KEY][1]);
	}

	/** The signed-in respondent's account rides with the entry too: the confirmation goes to its address. */
	public function testTheSignedInRespondentsAccountRidesWithTheEntry(): void {
		$form = ['settings' => ['notify_owner' => false, 'sendConfirmationEmail' => true]];

		ResponseOutbox::add($form, ['id' => 'r1'], [ResponseOutbox::MAIL], 'nl', ResponseOutbox::CREATED, true, 'bob');
		ResponseOutbox::add($form, ['id' => 'r2'], [ResponseOutbox::MAIL], 'nl', ResponseOutbox::CREATED, false, 'carol');
		ResponseOutbox::add($form, ['id' => 'r3'], [ResponseOutbox::MAIL], 'nl', ResponseOutbox::CREATED, true, '');

		$this->assertSame(['bob', 'carol'], array_column($form[ResponseOutbox::KEY], 'account'));
		$this->assertTrue($form[ResponseOutbox::KEY][1]['signedIn'], 'an account means signed in');
		$this->assertArrayNotHasKey('account', $form[ResponseOutbox::KEY][2]);
	}
}
