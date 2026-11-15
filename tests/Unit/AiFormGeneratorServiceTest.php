<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\AiFormGeneratorService;
use OCA\FormVox\Service\FormSchemaValidator;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the AI answers becomes a form a save would accept (PO 4b): no question id twice, scales and stars and
 * options within the limits.
 */
class AiFormGeneratorServiceTest extends TestCase {
	private function parse(array $answer): array {
		$service = new AiFormGeneratorService($this->createMock(IManager::class), $this->createMock(IRootFolder::class),
			$this->createMock(IConfig::class), $this->createMock(IAppManager::class), $this->createMock(LoggerInterface::class),
			new EnglishL10N());
		return $service->parseAiResponse(json_encode($answer));
	}

	private function assertASaveAcceptsIt(array $parsed): void {
		(new FormSchemaValidator(new EnglishL10N(), $this->createMock(LoggerInterface::class)))
			->check(['title' => 'AI', 'description' => $parsed['description'] ?? '', 'questions' => $parsed['questions']]);
		$this->addToAssertionCount(1);
	}

	/** The model may repeat an id, or leave one out where filling in by position collides with its own q2. */
	public function testEveryQuestionGetsItsOwnId(): void {
		$parsed = $this->parse(['title' => 'Event', 'questions' => [
			['id' => 'q1', 'type' => 'text', 'question' => 'Your name'],
			['type' => 'text', 'question' => 'Your email'],
			['id' => 'q2', 'type' => 'text', 'question' => 'Phone'],
			['id' => 'email', 'type' => 'text', 'question' => 'Applicant email'],
			['id' => 'email', 'type' => 'text', 'question' => 'Colleague email', 'showIf' => ['questionId' => 'q1', 'operator' => 'isNotEmpty']],
		]]);

		$ids = array_column($parsed['questions'], 'id');
		$this->assertCount(5, array_unique($ids));
		$this->assertSame(['q1', 'q2', 'email'], [$ids[0], $ids[2], $ids[3]]);
		$this->assertMatchesRegularExpression('/^q[0-9a-f]{8}$/', $ids[1]);
		$this->assertASaveAcceptsIt($parsed);
	}

	public function testScalesStarsAndOptionsStayWithinTheLimits(): void {
		$parsed = $this->parse(['title' => 'Survey', 'questions' => [
			['id' => 'nps', 'type' => 'scale', 'question' => 'Recommend?', 'min' => 0, 'max' => 100],
			['id' => 'stars', 'type' => 'rating', 'question' => 'Stars', 'max' => 20],
			['id' => 'pick', 'type' => 'choice', 'question' => 'Pick', 'options' => array_map(fn ($i) => ['label' => "Option $i"], range(1, 120))],
		]]);

		// Stored under the names the page reads (PO 4c).
		$this->assertSame([0, 10], [$parsed['questions'][0]['scaleMin'], $parsed['questions'][0]['scaleMax']]);
		$this->assertArrayNotHasKey('max', $parsed['questions'][0]);
		$this->assertSame(10, $parsed['questions'][1]['ratingMax']);
		$this->assertArrayNotHasKey('max', $parsed['questions'][1]);
		$this->assertCount(100, $parsed['questions'][2]['options']);
		$this->assertASaveAcceptsIt($parsed);
	}

	/** L12: a source document can make the model put a phishing link in a description, shown as markdown publicly. */
	public function testDescriptionsLoseLinksImagesAndHtmlButKeepTheirText(): void {
		$parsed = $this->parse(['title' => 'Survey', 'description' => 'Please **log in** at [our portal](https://evil.example/login) first. ![x](https://evil.example/pixel.png)', 'questions' => [
			['id' => 'q1', 'type' => 'text', 'question' => 'Name', 'description' => "See https://evil.example or www.evil.example <b>now</b>\n\n[ref]: https://evil.example\n- a list item"],
		]]);

		$this->assertSame('Please **log in** at our portal first.', $parsed['description']);
		$description = $parsed['questions'][0]['description'];
		$this->assertStringNotContainsString('evil', $description);
		$this->assertStringNotContainsString('<b>', $description);
		$this->assertStringContainsString('- a list item', $description);
	}
}
