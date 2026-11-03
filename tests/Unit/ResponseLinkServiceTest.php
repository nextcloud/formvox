<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormLockManager;
use OCA\FormVox\Service\ResponseLinkService;
use OCA\FormVox\Service\ShareTokenService;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCP\Files\File;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Unique response links: token lookup, the per-link limit counted on the link
 * itself, and generating/deleting links under the form lock.
 */
class ResponseLinkServiceTest extends TestCase {
	private array $storedForm;
	private int $tokenSeq = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->storedForm = ['settings' => [], 'responses' => []];
	}

	private function service(): ResponseLinkService {
		$locator = $this->createMock(FormFileLocator::class);
		$locator->method('getFileById')->willReturn($this->createMock(File::class));

		// Run the mutator against $this->storedForm, as the real lock would
		// against the freshly-read file.
		$lock = $this->createMock(FormLockManager::class);
		$lock->method('mutateFormFileWithLock')->willReturnCallback(
			function (File $file, callable $mutator) {
				return $mutator($this->storedForm);
			}
		);

		$tokens = $this->createMock(ShareTokenService::class);
		$tokens->method('generateToken')->willReturnCallback(fn () => 'TOKEN' . ++$this->tokenSeq);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(fn () => 'id' . bin2hex(random_bytes(4)));

		return new ResponseLinkService($locator, $lock, $tokens, $random, new EnglishL10N());
	}

	private function link(string $id, string $token, int $maxUses = 1, int $uses = 0): array {
		return ['id' => $id, 'token' => $token, 'label' => '', 'max_uses' => $maxUses, 'uses' => $uses];
	}

	public function testFindByTokenMatchesOnlyExactToken(): void {
		$form = ['response_links' => [$this->link('a', 'AAA'), $this->link('b', 'BBB')]];
		$this->assertSame('b', $this->service()->findByToken($form, 'BBB')['id']);
		$this->assertNull($this->service()->findByToken($form, 'CCC'));
		$this->assertNull($this->service()->findByToken([], 'AAA'));
	}

	public function testSingleUseLinkIsUsedUpAfterConsume(): void {
		$link = $this->link('a', 'AAA');
		$form = ['response_links' => [$this->link('b', 'BBB'), $link]];
		$this->assertTrue($this->service()->isAvailable($form, $link));

		$this->service()->consume($form, $link);

		$this->assertSame(1, $form['response_links'][1]['uses']);
		$this->assertSame(0, $form['response_links'][0]['uses']);
		$this->assertFalse($this->service()->isAvailable($form, $link));
	}

	public function testLinkAllowsUpToMaxUses(): void {
		$link = $this->link('a', 'AAA', 3, 2);
		$form = ['response_links' => [$link]];
		$this->assertTrue($this->service()->isAvailable($form, $link));
		$this->service()->consume($form, $link);
		$this->assertFalse($this->service()->isAvailable($form, $link));
	}

	public function testConsumeThrowsWhenUsedUpAndLeavesCountAlone(): void {
		$link = $this->link('a', 'AAA', 1, 1);
		$form = ['response_links' => [$link]];
		try {
			$this->service()->consume($form, $link);
			$this->fail('A used-up link must not be consumed');
		} catch (\RuntimeException $e) {
			$this->assertSame('This link has already been used', $e->getMessage());
		}
		$this->assertSame(1, $form['response_links'][0]['uses']);
	}

	public function testUsesAreReadFromTheFreshFormNotTheCallersCopy(): void {
		// The caller looked the link up before the lock (uses 0); meanwhile
		// another submission used it. The stored count must win.
		$stale = $this->link('a', 'AAA', 1, 0);
		$form = ['response_links' => [$this->link('a', 'AAA', 1, 1)]];
		$this->assertFalse($this->service()->isAvailable($form, $stale));
	}

	public function testDeletingResponsesDoesNotFreeALink(): void {
		$link = $this->link('a', 'AAA', 1, 1);
		$form = ['response_links' => [$link], 'responses' => []];
		$this->assertFalse($this->service()->isAvailable($form, $link));
	}

	public function testLimitIsReadFromTheFreshForm(): void {
		// The guard passes a link found before the lock; the stored link (here
		// deleted meanwhile) is what counts.
		$link = $this->link('a', 'AAA', 5);
		$this->assertFalse($this->service()->isAvailable(['response_links' => []], $link));
	}

	public function testAssertAvailableThrowsWithMessage(): void {
		$link = $this->link('a', 'AAA', 1, 1);
		$form = ['response_links' => [$link]];
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('This link has already been used');
		$this->service()->assertAvailable($form, $link);
	}

	public function testGenerateAppendsUniqueLinks(): void {
		$this->storedForm['response_links'] = [$this->link('old', 'OLD')];

		$created = $this->service()->generate(1, 250, 1, '  Batch A  ', 'alice');

		$this->assertCount(250, $created);
		$this->assertCount(251, $this->storedForm['response_links']);
		$tokens = array_column($this->storedForm['response_links'], 'token');
		$this->assertSame(count($tokens), count(array_unique($tokens)));
		$this->assertSame('Batch A', $created[0]['label']);
		$this->assertSame(1, $created[0]['max_uses']);
		$this->assertSame(0, $created[0]['uses']);
		$this->assertSame('alice', $created[0]['created_by']);
	}

	public function testGenerateRejectsOutOfRangeInput(): void {
		foreach ([[0, 1], [ResponseLinkService::MAX_PER_BATCH + 1, 1], [1, 0]] as [$count, $maxUses]) {
			try {
				$this->service()->generate(1, $count, $maxUses, '', 'alice');
				$this->fail("count=$count maxUses=$maxUses should be rejected");
			} catch (\InvalidArgumentException $e) {
				$this->assertArrayNotHasKey('response_links', $this->storedForm);
			}
		}
	}

	public function testGenerateRejectsExceedingTotal(): void {
		$this->storedForm['response_links'] = array_fill(0, ResponseLinkService::MAX_TOTAL, $this->link('x', 'X'));
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->generate(1, 1, 1, '', 'alice');
	}

	public function testDeleteOneOrAllKeepsResponses(): void {
		$this->storedForm['response_links'] = [$this->link('a', 'A'), $this->link('b', 'B')];
		$this->storedForm['responses'] = [['id' => 'r1']];

		$this->assertSame(1, $this->service()->delete(1, ['a']));
		$this->assertSame(['b'], array_column($this->storedForm['response_links'], 'id'));
		$this->assertSame(0, $this->service()->delete(1, ['missing']));

		$this->assertSame(1, $this->service()->delete(1, null));
		$this->assertSame([], $this->storedForm['response_links']);
		$this->assertCount(1, $this->storedForm['responses']);
	}
}
