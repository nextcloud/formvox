<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * The confirmation a respondent asked for by filling in the form's email question (#103), in the language they filled
 * the form in: it is sent from a background job, long after their request. A signed-in respondent gets it at their
 * account's address, whatever they typed.
 */
class ConfirmationMail {
	/** Above this the PDF stays behind: a mail server refuses a larger message for good, not just now. */
	public const MAX_PDF_BYTES = 8 * 1024 * 1024;

	public function __construct(
		private IMailer $mailer,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
		private \OCA\FormVox\Pdf\PdfMailPolicy $pdfPolicy,
		private IUserManager $users,
	) {
	}

	/**
	 * Sent when the form asks for it: to a signed-in respondent at their account's address, to anyone else at the
	 * valid address they filled in on the question marked as their email.
	 *
	 * @param (\Closure(): string)|null $pdf the response's PDF, asked for only when the email goes out with it
	 * @param string|null $account the respondent's account when they were signed in: the email goes to its address,
	 *                             and only then does the PDF go along by default (PdfMailPolicy); '' when signed in
	 *                             but not known, so nothing goes to a typed address
	 * @param string $pdfName the name the PDF goes by everywhere (ResponsePdf::fileName)
	 * @return bool false only when sending failed and is worth trying again; true when sent or there is nothing to send
	 */
	public function send(array $form, array $answers, string $language, ?\Closure $pdf = null, ?string $account = null, int $fileId = 0, string $pdfName = 'response.pdf'): bool {
		if (empty($form['settings']['sendConfirmationEmail'])) {
			return true;
		}
		$signedIn = $account !== null;
		$to = $signedIn ? $this->accountAddress($account) : self::typedAddress($form, $answers);
		if ($to === '' || !$this->mailer->validateMailAddress($to)) {
			if ($signedIn) {
				$this->logger->info('FormVox: no confirmation email of form ' . $fileId . ': the signed-in respondent\'s account has no email address', ['app' => Application::APP_ID]);
			}
			return true;
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $language !== '' ? $language : null);
		$formTitle = $form['title'] ?? $l->t('Form');
		$subject = trim((string)($form['settings']['confirmationEmailSubject'] ?? ''));
		if ($subject === '') {
			// TRANSLATORS: Subject of the confirmation email to a respondent; %s is the form title
			$subject = $l->t('Confirmation: %s', [$formTitle]);
		}
		$body = trim((string)($form['settings']['confirmationEmailBody'] ?? ''));
		if ($body === '') {
			// TRANSLATORS: Body of the confirmation email to a respondent; %s is the form title
			$body = $l->t("Thank you for filling in \"%s\".\n\nWe have received your response.", [$formTitle]);
		}

		$withPdf = false;
		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$to]);
			$message->setSubject($subject);
			$message->setPlainBody($body);
			if ($pdf !== null && \OCA\FormVox\Pdf\ResponsePdf::attachedFor($form) && $this->pdfPolicy->allows($fileId, $to, $signedIn)) {
				$withPdf = true;
				// Not without the PDF it promises: one that cannot be had now holds the email for a later try.
				$bytes = $pdf();
				if (strlen($bytes) > self::MAX_PDF_BYTES) {
					// Too large for a mail server to ever take: the confirmation goes, the PDF stays in FormVox.
					$this->pdfPolicy->release($fileId, $to, $signedIn);
					$withPdf = false;
					$this->logger->info('FormVox: the PDF of a response of form ' . $fileId . ' is too large to attach (' . strlen($bytes) . ' bytes); the confirmation goes without it', ['app' => Application::APP_ID]);
				} else {
					$message->attach($this->mailer->createAttachment($bytes, $pdfName, 'application/pdf'));
				}
			}
			$failed = $this->mailer->send($message);
		} catch (\Throwable $e) {
			if ($withPdf) {
				$this->pdfPolicy->release($fileId, $to, $signedIn);
			}
			$this->logger->warning('FormVox: a confirmation email could not be sent: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return false;
		}
		if (!empty($failed)) {
			if ($withPdf) {
				$this->pdfPolicy->release($fileId, $to, $signedIn);
			}
			$this->logger->warning('FormVox: a confirmation email was refused by the mail server', ['app' => Application::APP_ID]);
			return false;
		}
		return true;
	}

	/** The question marked as the respondent's email, as they filled it in. */
	private static function typedAddress(array $form, array $answers): string {
		foreach ($form['questions'] ?? [] as $q) {
			if (!empty($q['useAsRespondentEmail']) && in_array($q['type'] ?? '', ['text', 'textarea'], true)) {
				return trim((string)($answers[$q['id']] ?? ''));
			}
		}
		return '';
	}

	/** The address of the account, as Nextcloud has it; '' without one. */
	private function accountAddress(string $account): string {
		if ($account === '') {
			return '';
		}
		return trim((string)($this->users->get($account)?->getEMailAddress() ?? ''));
	}
}
