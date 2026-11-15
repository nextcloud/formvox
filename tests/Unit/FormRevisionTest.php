<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Service\FormRevision;
use PHPUnit\Framework\TestCase;

/**
 * The version of a form's structure a client holds (qual#26): a submission or a share setting is no change.
 */
class FormRevisionTest extends TestCase {
	private function form(): array {
		return ['title' => 'T', 'description' => 'D', 'questions' => [['id' => 'q1', 'type' => 'text']], 'pages' => null, 'branding' => null];
	}

	public function testResponsesAndSettingsAreNoChange(): void {
		$changed = $this->form() + ['responses' => [['id' => 'r1']], '_index' => ['response_count' => 1], 'settings' => ['share_expires_at' => '2027-01-01'], 'modified_at' => 'now'];

		$this->assertSame(FormRevision::of($this->form()), FormRevision::of($changed));
	}

	public function testEveryPartOfTheStructureIsAChange(): void {
		$base = FormRevision::of($this->form());
		foreach (['title' => 'T2', 'description' => 'D2', 'descriptionAlign' => 'center', 'questions' => [], 'pages' => [['id' => 'p1']], 'branding' => ['primaryColor' => '#000']] as $field => $value) {
			$form = $this->form();
			$form[$field] = $value;
			$this->assertNotSame($base, FormRevision::of($form), $field);
		}
	}

	public function testTheIfMatchHeaderIsRead(): void {
		$this->assertSame('abc', FormRevision::fromIfMatch('"abc"'));
		$this->assertSame('abc', FormRevision::fromIfMatch('W/"abc"'));
		$this->assertSame('abc', FormRevision::fromIfMatch('abc'));
		$this->assertNull(FormRevision::fromIfMatch(''));
		$this->assertNull(FormRevision::fromIfMatch('*'));
	}

	public function testASignedInUserGetsTheRevisionWithTheForm(): void {
		$out = (new FormSanitizer())->forUser($this->form(), ['viewResponses' => false]);

		$this->assertSame(FormRevision::of($this->form()), $out['revision']);
	}
}
