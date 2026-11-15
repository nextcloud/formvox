<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\ConfirmationMail;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The confirmation a respondent asked for (#103), sent from a background job in the language they filled the form in
 * (7a, 3a-4); a mail server that fails is tried again, nothing to send is done. A signed-in respondent is written to
 * at their account's address, an anonymous one at the address they typed.
 */
class ConfirmationMailTest extends TestCase {
	private IMailer $mailer;
	private IMessage $message;
	/** @var list<?string> languages asked for */
	private array $languages = [];
	private ?\OCA\FormVox\Pdf\PdfMailPolicy $policy = null;
	private ?\OCA\FormVox\Tests\Unit\Support\Fake\Redis $cache = null;
	/** @var array<string, string> the accounts there are, with their addresses ('' for none) */
	private array $accounts = ['bob' => 'bob@school.example'];

	/** The real rule: signed-in respondents get the PDF; anonymous ones where the administrator allowed it, within limits. */
	private function policy(bool $anonymousAllowed = false): \OCA\FormVox\Pdf\PdfMailPolicy {
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturn($anonymousAllowed ? '1' : '0');
		// Redis, so the limits can hold.
		$config->method('getSystemValue')->willReturnCallback(fn (string $key, $default = '') => $key === 'memcache.distributed' ? '\\OC\\Memcache\\Redis' : $default);
		$factory = $this->createMock(\OCP\ICacheFactory::class);
		$this->cache = new \OCA\FormVox\Tests\Unit\Support\Fake\Redis();
		$factory->method('createDistributed')->willReturn($this->cache);
		return new \OCA\FormVox\Pdf\PdfMailPolicy($config, $factory, $this->createMock(LoggerInterface::class));
	}

	protected function setUp(): void {
		parent::setUp();
		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('validateMailAddress')->willReturnCallback(fn (string $address) => str_contains($address, '@'));
		$this->message = $this->createMock(IMessage::class);
		$this->message->method('setTo')->willReturnSelf();
		$this->message->method('setSubject')->willReturnSelf();
		$this->message->method('setPlainBody')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($this->message);
	}

	private function mail(): ConfirmationMail {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(function (string $app, ?string $language) {
			$this->languages[] = $language;
			$l = $this->createMock(IL10N::class);
			$l->method('t')->willReturnCallback(fn (string $text, array $params = []) => '[' . $language . '] ' . vsprintf($text, $params));
			return $l;
		});
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid): ?IUser {
			if (!array_key_exists($uid, $this->accounts)) {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getEMailAddress')->willReturn($this->accounts[$uid] === '' ? null : $this->accounts[$uid]);
			return $user;
		});
		return new ConfirmationMail($this->mailer, $factory, $this->createMock(LoggerInterface::class), $this->policy ?? $this->policy(), $users);
	}

	private function form(array $settings = ['sendConfirmationEmail' => true]): array {
		return [
			'title' => 'Survey',
			'settings' => $settings,
			'questions' => [['id' => 'q1', 'type' => 'text', 'useAsRespondentEmail' => true]],
		];
	}

	/** @return array<string, array{array, array}> */
	public static function nothingToSend(): array {
		$withField = ['title' => 'S', 'settings' => ['sendConfirmationEmail' => true], 'questions' => [['id' => 'q1', 'type' => 'text', 'useAsRespondentEmail' => true]]];
		return [
			'not asked for' => [['settings' => [], 'questions' => $withField['questions']], ['q1' => 'a@b.com']],
			'no email question' => [['settings' => ['sendConfirmationEmail' => true], 'questions' => [['id' => 'q1', 'type' => 'text']]], ['q1' => 'a@b.com']],
			'no valid address' => [$withField, ['q1' => 'not an address']],
			'left empty' => [$withField, []],
		];
	}

	/**
	 * @dataProvider nothingToSend
	 */
	public function testNothingToSendIsDone(array $form, array $answers): void {
		$this->mailer->expects($this->never())->method('send');

		$this->assertTrue($this->mail()->send($form, $answers, 'nl'));
	}

	public function testSentInTheRespondentsLanguage(): void {
		$this->message->expects($this->once())->method('setTo')->with(['a@b.com']);
		$this->message->expects($this->once())->method('setSubject')->with('[nl] Confirmation: Survey');
		$this->mailer->expects($this->once())->method('send')->with($this->message)->willReturn([]);

		$this->assertTrue($this->mail()->send($this->form(), ['q1' => 'a@b.com'], 'nl'));
		$this->assertSame(['nl'], $this->languages);
	}

	public function testTheFormsOwnTextIsUsedAsWritten(): void {
		$this->message->expects($this->once())->method('setSubject')->with('Thanks!');
		$this->message->expects($this->once())->method('setPlainBody')->with('See you.');
		$this->mailer->method('send')->willReturn([]);

		$this->mail()->send($this->form(['sendConfirmationEmail' => true, 'confirmationEmailSubject' => 'Thanks!', 'confirmationEmailBody' => 'See you.']), ['q1' => 'a@b.com'], 'de');
	}

	/** Without a known language, Nextcloud's default. */
	public function testWithoutALanguageTheDefaultIsUsed(): void {
		$this->mailer->method('send')->willReturn([]);

		$this->mail()->send($this->form(), ['q1' => 'a@b.com'], '');

		$this->assertSame([null], $this->languages);
	}

	/** @return array<string, array{\Closure}> */
	public static function mailServerFailures(): array {
		return [
			'throws' => [fn () => throw new \RuntimeException('connection refused')],
			'refuses the address' => [fn () => ['a@b.com']],
		];
	}

	/**
	 * @dataProvider mailServerFailures
	 */
	public function testAMailServerThatFailsIsTriedAgain(\Closure $send): void {
		$this->mailer->method('send')->willReturnCallback($send);

		$this->assertFalse($this->mail()->send($this->form(), ['q1' => 'a@b.com'], 'nl'));
	}

	/** With the PDF where the form asks for it, named after the form. */
	public function testThePdfGoesWithItWhereTheFormAsks(): void {
		$attachment = $this->createMock(\OCP\Mail\IAttachment::class);
		$this->mailer->expects($this->once())->method('createAttachment')->with('%PDF-1.7', 'Survey_2026-11-10_r1.pdf', 'application/pdf')->willReturn($attachment);
		$this->message->expects($this->once())->method('attach')->with($attachment);
		$this->mailer->method('send')->willReturn([]);

		$this->assertTrue($this->mail()->send($this->form(['sendConfirmationEmail' => true, 'confirmationEmailPdf' => true, 'keepResponsePdf' => true]), ['q1' => 'a@b.com'], 'nl', fn () => '%PDF-1.7', 'bob', 7, 'Survey_2026-11-10_r1.pdf'));
	}

	/** A signed-in respondent is written to at their account's address, whatever they typed: one email, the PDF along. */
	public function testASignedInRespondentGetsItAtTheirAccountsAddress(): void {
		$this->message->expects($this->once())->method('setTo')->with(['bob@school.example']);
		$this->mailer->expects($this->once())->method('createAttachment')->willReturn($this->createMock(\OCP\Mail\IAttachment::class));
		$this->mailer->expects($this->once())->method('send')->willReturn([]);

		$this->assertTrue($this->mail()->send($this->form(['sendConfirmationEmail' => true, 'confirmationEmailPdf' => true, 'keepResponsePdf' => true]), ['q1' => 'typed@elsewhere.example'], 'nl', fn () => '%PDF-1.7', 'bob', 7));
	}

	/** The question marked as their email may be left empty or missing: the account's address is what counts. */
	public function testASignedInRespondentNeedsNoEmailQuestion(): void {
		$this->message->expects($this->once())->method('setTo')->with(['bob@school.example']);
		$this->mailer->expects($this->once())->method('send')->willReturn([]);

		$this->assertTrue($this->mail()->send(['title' => 'S', 'settings' => ['sendConfirmationEmail' => true], 'questions' => [['id' => 'q1', 'type' => 'text']]], [], 'nl', null, 'bob', 7));
	}

	/** @return array<string, array{string}> */
	public static function accountsWithoutAnAddress(): array {
		return ['no address on the account' => ['carol'], 'no such account' => ['nobody'], 'signed in, account not known' => ['']];
	}

	/**
	 * Nothing goes to a typed address for a signed-in respondent: without an address on their account, no confirmation
	 * and no PDF.
	 *
	 * @dataProvider accountsWithoutAnAddress
	 */
	public function testASignedInRespondentWithoutAnAccountAddressGetsNothing(string $account): void {
		$this->accounts['carol'] = '';
		$this->mailer->expects($this->never())->method('send');
		$this->mailer->expects($this->never())->method('createAttachment');

		$this->assertTrue($this->mail()->send($this->form(['sendConfirmationEmail' => true, 'confirmationEmailPdf' => true, 'keepResponsePdf' => true]), ['q1' => 'typed@elsewhere.example'], 'nl', fn () => '%PDF-1.7', $account, 7), 'done: nothing to try again');
	}

	/** An anonymous respondent gets the email at the address they typed, without the PDF: that address is never checked. */
	public function testAnAnonymousRespondentGetsNoPdfByDefault(): void {
		$this->message->expects($this->once())->method('setTo')->with(['a@b.com']);
		$this->mailer->expects($this->never())->method('createAttachment');
		$this->mailer->expects($this->once())->method('send')->willReturn([]);

		$this->assertTrue($this->mail()->send($this->form(['sendConfirmationEmail' => true, 'confirmationEmailPdf' => true, 'keepResponsePdf' => true]), ['q1' => 'a@b.com'], 'nl', fn () => '%PDF-1.7', null, 7));
	}

	/** Where the administrator allowed it, anonymous respondents get it too, at most a few times per address. */
	public function testWhereTheAdministratorAllowsItAnonymousRespondentsGetItWithinLimits(): void {
		$this->policy = $this->policy(true);
		$attached = 0;
		$this->mailer->method('createAttachment')->willReturnCallback(function () use (&$attached) {
			$attached++;
			return $this->createMock(\OCP\Mail\IAttachment::class);
		});
		$this->mailer->method('send')->willReturn([]);
		$mail = $this->mail();

		for ($i = 0; $i < 5; $i++) {
			$this->assertTrue($mail->send($this->form(['sendConfirmationEmail' => true, 'confirmationEmailPdf' => true, 'keepResponsePdf' => true]), ['q1' => 'A@b.com '], 'nl', fn () => '%PDF-1.7', null, 7));
		}

		$this->assertSame(\OCA\FormVox\Pdf\PdfMailPolicy::PER_ADDRESS_PER_DAY, $attached, 'then the email goes without it');
	}

	/** A PDF too large for a mail server to ever take stays behind: the confirmation goes without it and takes no place. */
	public function testAPdfTooLargeToAttachStaysBehind(): void {
		$this->policy = $this->policy(true);
		$this->mailer->expects($this->never())->method('createAttachment');
		$this->mailer->expects($this->once())->method('send')->willReturn([]);

		$this->assertTrue($this->mail()->send($this->form(['sendConfirmationEmail' => true, 'confirmationEmailPdf' => true, 'keepResponsePdf' => true]), ['q1' => 'a@b.com'], 'nl', fn () => str_repeat('x', ConfirmationMail::MAX_PDF_BYTES + 1), null, 7));
		$this->assertSame(0, (int)$this->cache->get('form/7'), 'its place is given back');
	}

	/** @return array<string, array{string}> */
	public static function failuresBeforeSending(): array {
		return ['mail server throws' => ['throws'], 'mail server refuses' => ['refuses'], 'PDF cannot be made' => ['pdf']];
	}

	/**
	 * A try that fails is retried later: it must not use up the respondent's few PDFs a day.
	 *
	 * @dataProvider failuresBeforeSending
	 */
	public function testAFailedTryDoesNotUseUpTheLimit(string $failure): void {
		$this->policy = $this->policy(true);
		$attached = 0;
		$this->mailer->method('createAttachment')->willReturnCallback(function () use (&$attached) {
			$attached++;
			return $this->createMock(\OCP\Mail\IAttachment::class);
		});
		$failing = true;
		$this->mailer->method('send')->willReturnCallback(function () use (&$failing, $failure) {
			if ($failing && $failure === 'throws') {
				throw new \RuntimeException('connection refused');
			}
			return $failing && $failure === 'refuses' ? ['a@b.com'] : [];
		});
		$mail = $this->mail();
		$form = $this->form(['sendConfirmationEmail' => true, 'confirmationEmailPdf' => true, 'keepResponsePdf' => true]);
		$pdf = function () use (&$failing, $failure) {
			if ($failing && $failure === 'pdf') {
				throw new \RuntimeException('out of memory');
			}
			return '%PDF-1.7';
		};

		for ($i = 0; $i < 5; $i++) {
			$this->assertFalse($mail->send($form, ['q1' => 'a@b.com'], 'nl', $pdf, null, 7));
		}
		$failing = false;
		$attached = 0;
		for ($i = 0; $i < \OCA\FormVox\Pdf\PdfMailPolicy::PER_ADDRESS_PER_DAY; $i++) {
			$this->assertTrue($mail->send($form, ['q1' => 'a@b.com'], 'nl', $pdf, null, 7));
		}

		$this->assertSame(\OCA\FormVox\Pdf\PdfMailPolicy::PER_ADDRESS_PER_DAY, $attached, 'every PDF the limit allows still goes out');
	}

	/** A failed email that went without a PDF took no place, so it gives none back: the limit holds. */
	public function testAFailureWithoutThePdfGivesNothingBack(): void {
		$this->policy = $this->policy(true);
		$attached = 0;
		$this->mailer->method('createAttachment')->willReturnCallback(function () use (&$attached) {
			$attached++;
			return $this->createMock(\OCP\Mail\IAttachment::class);
		});
		$up = true;
		$this->mailer->method('send')->willReturnCallback(function () use (&$up) {
			return $up ? [] : ['a@b.com'];
		});
		$mail = $this->mail();
		$form = $this->form(['sendConfirmationEmail' => true, 'confirmationEmailPdf' => true, 'keepResponsePdf' => true]);

		for ($i = 0; $i < \OCA\FormVox\Pdf\PdfMailPolicy::PER_ADDRESS_PER_DAY; $i++) {
			$this->assertTrue($mail->send($form, ['q1' => 'a@b.com'], 'nl', fn () => '%PDF-1.7', null, 7));
		}
		$up = false;
		for ($i = 0; $i < 3; $i++) {
			$this->assertFalse($mail->send($form, ['q1' => 'a@b.com'], 'nl', fn () => '%PDF-1.7', null, 7));
		}
		$up = true;
		$this->assertTrue($mail->send($form, ['q1' => 'a@b.com'], 'nl', fn () => '%PDF-1.7', null, 7));

		$this->assertSame(\OCA\FormVox\Pdf\PdfMailPolicy::PER_ADDRESS_PER_DAY, $attached, 'no fourth PDF');
	}

	/** The place a failed email gives back is the form's as well as the address's. */
	public function testAFailureGivesBackTheFormsPlaceToo(): void {
		$this->policy = $this->policy(true);
		$cache = $this->cache;
		$this->mailer->method('createAttachment')->willReturn($this->createMock(\OCP\Mail\IAttachment::class));
		$this->mailer->method('send')->willReturn(['a@b.com']);

		$this->assertFalse($this->mail()->send($this->form(['sendConfirmationEmail' => true, 'confirmationEmailPdf' => true, 'keepResponsePdf' => true]), ['q1' => 'a@b.com'], 'nl', fn () => '%PDF-1.7', null, 7));

		$this->assertSame(0, (int)$cache->get('form/7'));
		$this->assertSame(0, (int)$cache->get('address/' . hash('sha256', 'a@b.com')));
	}

	public function testNoPdfIsMadeWhereTheFormDoesNotAsk(): void {
		$this->mailer->expects($this->never())->method('createAttachment');
		$this->mailer->method('send')->willReturn([]);

		$this->assertTrue($this->mail()->send($this->form(), ['q1' => 'a@b.com'], 'nl', fn () => throw new \LogicException('not asked for')));
	}

	/** The option to attach it counts only on a form that keeps PDFs: left on after the switch went off, it attaches nothing. */
	public function testTheAttachmentNeedsAFormThatKeepsPdfs(): void {
		$this->mailer->expects($this->never())->method('createAttachment');
		$this->mailer->method('send')->willReturn([]);

		$this->assertTrue($this->mail()->send($this->form(['sendConfirmationEmail' => true, 'confirmationEmailPdf' => true]), ['q1' => 'a@b.com'], 'nl', fn () => throw new \LogicException('no PDF to attach'), 'bob', 7));
	}

	/** Never without the PDF it promises: one that cannot be had holds the email for a later try. */
	public function testAPdfThatCannotBeHadHoldsTheEmail(): void {
		$this->mailer->expects($this->never())->method('send');

		$this->assertFalse($this->mail()->send($this->form(['sendConfirmationEmail' => true, 'confirmationEmailPdf' => true, 'keepResponsePdf' => true]), ['q1' => 'a@b.com'], 'nl', fn () => throw new \RuntimeException('out of memory'), 'bob'));
	}

	public function testWithoutANameThePdfIsCalledResponse(): void {
		$this->mailer->expects($this->once())->method('createAttachment')->with('%PDF-1.7', 'response.pdf', 'application/pdf')->willReturn($this->createMock(\OCP\Mail\IAttachment::class));
		$this->mailer->method('send')->willReturn([]);

		$this->assertTrue($this->mail()->send($this->form(['sendConfirmationEmail' => true, 'confirmationEmailPdf' => true, 'keepResponsePdf' => true]), ['q1' => 'a@b.com'], 'nl', fn () => '%PDF-1.7', 'bob'));
	}
}
