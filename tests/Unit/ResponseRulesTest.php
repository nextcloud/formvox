<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\AnswerValidator;
use OCA\FormVox\Service\IndexService;
use OCA\FormVox\Service\InvalidAnswerException;
use OCA\FormVox\Service\ResponseRefusedException;
use OCA\FormVox\Service\ResponseRules;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use PHPUnit\Framework\TestCase;

/**
 * Whether a response may be written now, the same for every way in (Q7): the share dialog's dates,
 * the response limit, once per person and option capacity, for a new and for a changed response.
 */
class ResponseRulesTest extends TestCase {
	private IndexService $index;

	protected function setUp(): void {
		parent::setUp();
		$this->index = $this->createMock(IndexService::class);
	}

	private function rules(): ResponseRules {
		return new ResponseRules(new AnswerValidator($this->createMock(UploadService::class), new EnglishL10N()), $this->index);
	}

	private function refusal(callable $check): ?string {
		try {
			$check();
			return null;
		} catch (ResponseRefusedException|InvalidAnswerException $e) {
			return $e->getMessage();
		}
	}

	// ---- the share dialog's dates ------------------------------------------------

	/**
	 * @return array<string, array{array, ?string}>
	 */
	public static function dates(): array {
		return [
			'after the expiry date' => [['share_expires_at' => '2000-01-01T00:00:00Z'], 'This form has expired'],
			'before the opening date' => [['share_starts_at' => '2999-01-01T00:00:00Z'], 'This form is not yet open'],
			'an unreadable date closes the form' => [['share_expires_at' => 'tomorrow-ish'], 'This form has expired'],
			'inside the window' => [['share_starts_at' => '2000-01-01T00:00:00Z', 'share_expires_at' => '2999-01-01T00:00:00Z'], null],
			'the retired expires_at no longer closes' => [['expires_at' => '2000-01-01T00:00:00Z'], null],
			'no dates' => [[], null],
		];
	}

	/**
	 * @dataProvider dates
	 */
	public function testANewResponseFollowsTheShareDialogsDates(array $settings, ?string $refused): void {
		$this->assertSame($refused, $this->refusal(fn () => $this->rules()->assertNew(['settings' => $settings], ['answers' => []])));
	}

	/**
	 * @dataProvider dates
	 */
	public function testAChangedResponseFollowsTheSameDates(array $settings, ?string $refused): void {
		$this->assertSame($refused, $this->refusal(fn () => $this->rules()->assertChange(['settings' => $settings], ['answers' => []], ['answers' => []])));
	}

	// ---- the response limit -----------------------------------------------------

	public function testTheLimitRefusesWithItsOwnMessageOrTheDefault(): void {
		$this->index->method('getResponseCount')->willReturn(10);

		$this->assertSame('This form has reached its response limit', $this->refusal(fn () => $this->rules()->assertNew(['settings' => ['max_responses' => 10]], ['answers' => []])));
		$this->assertSame('Sold out!', $this->refusal(fn () => $this->rules()->assertNew(['settings' => ['max_responses' => 10, 'limit_message' => 'Sold out!']], ['answers' => []])));
		$this->assertNull($this->refusal(fn () => $this->rules()->assertNew(['settings' => ['max_responses' => 11]], ['answers' => []])));
	}

	public function testChangingAResponseDoesNotCountAgainstTheLimit(): void {
		$this->index->method('getResponseCount')->willReturn(10);

		$this->assertNull($this->refusal(fn () => $this->rules()->assertChange(['settings' => ['max_responses' => 10]], ['answers' => []], ['answers' => []])));
	}

	// ---- once per person --------------------------------------------------------

	public function testOncePerPersonByFingerprintOrAccountNotForTheApi(): void {
		$this->index->method('hasFingerprint')->willReturnCallback(fn (array $form, string $fp) => $fp === 'v2:seen');
		$this->index->method('hasUserResponse')->willReturnCallback(fn (array $form, string $uid) => $uid === 'anna');
		$once = ['settings' => ['allow_multiple' => false]];

		$this->assertSame('You have already submitted a response to this form', $this->refusal(fn () => $this->rules()->assertNew($once, ['respondent' => ['type' => 'anonymous', 'fingerprint' => 'v2:seen']])));
		$this->assertSame('You have already submitted a response to this form', $this->refusal(fn () => $this->rules()->assertNew($once, ['respondent' => ['type' => 'user', 'user_id' => 'anna']])));
		$this->assertNull($this->refusal(fn () => $this->rules()->assertNew($once, ['respondent' => ['type' => 'anonymous', 'fingerprint' => 'v2:new']])));
		$this->assertNull($this->refusal(fn () => $this->rules()->assertNew($once, ['respondent' => ['type' => 'user', 'user_id' => 'bob']])));
		$this->assertNull($this->refusal(fn () => $this->rules()->assertNew($once, ['respondent' => ['type' => 'api', 'key_id' => 'k1']])));
		$this->assertNull($this->refusal(fn () => $this->rules()->assertNew(['settings' => ['allow_multiple' => true]], ['respondent' => ['type' => 'user', 'user_id' => 'anna']])));
	}

	// ---- capacity ---------------------------------------------------------------

	private function slots(int $takenA): array {
		return [
			'questions' => [['id' => 'q', 'type' => 'choice', 'question' => 'Slot', 'options' => [
				['value' => 'a', 'label' => 'Slot A', 'capacity' => 2],
				['value' => 'b', 'label' => 'Slot B', 'capacity' => 2],
			]]],
			'_index' => ['answer_counts' => ['q' => ['a' => $takenA, 'b' => 0]]],
		];
	}

	public function testANewResponseNeedsRoomInTheChosenOption(): void {
		$this->assertSame("The option 'Slot A' is no longer available — capacity reached", $this->refusal(fn () => $this->rules()->assertNew($this->slots(2), ['answers' => ['q' => 'a']])));
		$this->assertNull($this->refusal(fn () => $this->rules()->assertNew($this->slots(1), ['answers' => ['q' => 'a']])));
	}

	/** A response that already holds a place in a full option keeps it when it is changed (Q7). */
	public function testAChangedResponseKeepsItsOwnPlaceButCannotMoveIntoAFullOption(): void {
		$this->assertNull($this->refusal(fn () => $this->rules()->assertChange($this->slots(2), ['answers' => ['q' => 'a']], ['answers' => ['q' => 'a']])));
		$this->assertSame("The option 'Slot A' is no longer available — capacity reached", $this->refusal(fn () => $this->rules()->assertChange($this->slots(2), ['answers' => ['q' => 'b']], ['answers' => ['q' => 'a']])));
		$this->assertNull($this->refusal(fn () => $this->rules()->assertChange($this->slots(2), ['answers' => ['q' => 'a']], ['answers' => ['q' => 'b']])));
	}
}
