<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Pdf;

use OCA\FormVox\Pdf\PdfRenderer;
use OCA\FormVox\Pdf\PdfStore;
use OCA\FormVox\Pdf\ResponsePdf;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCA\FormVox\Tests\Unit\Support\PdfDocuments;
use OCP\Files\File;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A response's PDF is made once and kept, in its respondent's language, by the response's revision: the email, a
 * download and the API give the same document, and one of other answers never passes for it.
 */
class ResponsePdfTest extends TestCase {
	/** A form that keeps a PDF of every response: only such a form gets one made. */
	private const KEPT = ['settings' => ['keepResponsePdf' => true]];
	/** @var array<string, string> by response id and revision */
	private array $kept = [];
	private int $renders = 0;
	/** @var list<string|null> the languages documents were made in */
	private array $languages = [];
	private bool $storeFails = false;
	/** Another writer's PDF, kept just before this one put its own. */
	private ?string $keptFirst = null;
	private bool $readFails = false;
	/** No account can write the form: an archived, read-only team folder. */
	private bool $noWriter = false;
	/** @var list<string> response ids whose PDFs were removed */
	private array $forgotten = [];
	private string $where = 'files/Forms/form.fvform';
	/** @var list<File> the forms the store was asked to look in */
	private array $lookedIn = [];
	private ?File $keptAt = null;
	/** @var list<array{0: string, 1: string}> */
	private array $footers = [];

	private function pdfs(): ResponsePdf {
		$store = $this->createMock(PdfStore::class);
		$store->method('get')->willReturnCallback(function (File $form, string $id, string $revision) {
			$this->lookedIn[] = $form;
			if ($this->readFails) {
				throw new \OCP\Lock\LockedException('form.fvform');
			}
			return $this->kept[$id . '/' . $revision] ?? null;
		});
		$store->method('find')->willReturnCallback(function (File $form) {
			$this->lookedIn[] = $form;
			return null;
		});
		$store->method('delete')->willReturnCallback(function (File $form, string $id): void {
			$this->forgotten[] = $id;
		});
		$store->method('put')->willReturnCallback(function (File $form, string $id, string $revision, string $pdf): void {
			if ($this->storeFails) {
				throw new \RuntimeException('quota');
			}
			$this->kept = array_filter($this->kept, fn (string $key) => !str_starts_with($key, $id . '/'), ARRAY_FILTER_USE_KEY);
			$this->kept[$id . '/' . $revision] = $this->keptFirst ?? $pdf;
		});
		$renderer = $this->createMock(PdfRenderer::class);
		$renderer->method('render')->willReturnCallback(function (string $html, string $footer = '', string $pages = ''): string {
			$this->footers[] = [$footer, $pages];
			$this->renders++;
			return '%PDF-' . $this->renders;
		});
		$l10n = $this->createMock(IFactory::class);
		$l10n->method('get')->willReturnCallback(function (string $app, ?string $language) {
			$this->languages[] = $language;
			return new EnglishL10N();
		});
		$now = $this->createMock(File::class);
		$now->method('getInternalPath')->willReturnCallback(fn () => $this->where);
		$this->keptAt = $now;
		$locator = $this->createMock(FormFileLocator::class);
		$locator->method('getFileByIdPublic')->willReturnCallback(fn () => $this->noWriter ? throw new \RuntimeException('No account with write access') : $now);
		$locator->method('getFileByIdPublicForWrite')->willReturn($now);
		return new ResponsePdf(PdfDocuments::document($this), $renderer, $store, $locator, $l10n, $this->createMock(LoggerInterface::class));
	}

	public function testEachPageNamesTheResponse(): void {
		$this->pdfs()->of($this->createMock(File::class), self::KEPT + ['title' => 'T', 'questions' => []], ['id' => 'r1', 'answers' => []]);

		$this->assertSame([['Response r1', 'Page {current} of {total}']], $this->footers);
	}

	/** A download and the API hand it out by the name it goes by everywhere. */
	public function testItIsHandedOutByItsName(): void {
		$download = $this->pdfs()->download($this->createMock(File::class), self::KEPT + ['title' => 'Größe', 'questions' => []], fn () => ['id' => 'r1', 'submitted_at' => '2026-11-10T09:00:00+00:00', 'answers' => []]);

		$this->assertSame(['%PDF-1', 'Groesse_2026-11-10_r1.pdf'], $download);
	}

	public function testNoResponseNoPdf(): void {
		$this->assertNull($this->pdfs()->download($this->createMock(File::class), self::KEPT + ['questions' => []], fn () => null));
		$this->assertSame(0, $this->renders);
	}

	/** Deleted while its PDF was made: the PDF made for it goes too, and none is handed out. */
	public function testAResponseDeletedWhileItsPdfWasMadeDoesNotGetItBack(): void {
		$asked = 0;
		$current = function () use (&$asked): ?array {
			return $asked++ === 0 ? ['id' => 'r1', 'answers' => []] : null;
		};

		$this->assertNull($this->pdfs()->download($this->createMock(File::class), self::KEPT + ['questions' => []], $current));
		$this->assertSame(['r1'], $this->forgotten);
	}

	/** Someone the form is shared with sees it in their own folder; its PDFs are looked for where they are kept. */
	public function testItIsLookedForWhereItIsKept(): void {
		$pdfs = $this->pdfs();
		$theirs = $this->createMock(File::class);
		$theirs->method('getId')->willReturn(7);

		$pdfs->of($theirs, self::KEPT + ['title' => 'T'], ['id' => 'r1', 'answers' => []]);

		$this->assertNotSame([], $this->lookedIn);
		$this->assertSame(array_fill(0, count($this->lookedIn), $this->keptAt), $this->lookedIn);
	}

	/** No account may write the form: its PDF is still made and looked for, beside the asker's own form. */
	public function testWithoutAWriterItLooksBesideTheAskersForm(): void {
		$this->noWriter = true;
		$pdfs = $this->pdfs();
		$theirs = $this->createMock(File::class);
		$theirs->method('getId')->willReturn(7);

		$this->assertSame('%PDF-1', $pdfs->of($theirs, self::KEPT + ['title' => 'T'], ['id' => 'r1', 'answers' => []]));
		$this->assertSame($theirs, $this->lookedIn[0]);
		$this->lookedIn = [];
		$pdfs->stored($theirs, ['id' => 'r1', 'answers' => []]);
		$this->assertSame([$theirs], $this->lookedIn);
	}

	public function testMadeOnceAndKept(): void {
		$pdfs = $this->pdfs();
		$form = $this->createMock(File::class);
		$response = ['id' => 'r1', 'language' => 'de', 'answers' => ['q' => 'a']];

		$first = $pdfs->of($form, self::KEPT + ['title' => 'T', 'questions' => []], $response, 'nl');
		$again = $pdfs->of($form, self::KEPT + ['title' => 'Changed since', 'questions' => []], $response, 'nl');

		$this->assertSame($first, $again);
		$this->assertSame(1, $this->renders);
		$this->assertSame(['de'], $this->languages, "the respondent's own language, kept with the response");
	}

	/** Changed meanwhile, even while its old PDF was being made: the old one never passes for it. */
	public function testAChangedResponseNeverGetsTheOldOne(): void {
		$pdfs = $this->pdfs();
		$form = $this->createMock(File::class);
		$old = $pdfs->of($form, self::KEPT + ['questions' => []], ['id' => 'r1', 'answers' => ['q' => 'a']], 'nl');

		$new = $pdfs->of($form, self::KEPT + ['questions' => []], ['id' => 'r1', 'answers' => ['q' => 'b'], 'updated_at' => 'now'], 'nl');

		$this->assertNotSame($old, $new);
		$this->assertCount(1, $this->kept, 'only the response as it is now is kept');
	}

	/** Another one kept first is the one it gives: a download, the API and a webhook's checksum agree. */
	public function testOneKeptFirstIsTheOneGiven(): void {
		$this->keptFirst = '%PDF-first';
		$pdfs = $this->pdfs();
		$form = $this->createMock(File::class);
		$response = ['id' => 'r1', 'answers' => []];

		$this->assertSame('%PDF-first', $pdfs->of($form, self::KEPT + ['questions' => []], $response));
		$this->assertSame('%PDF-first', $pdfs->keptOf($form, self::KEPT + ['questions' => []], $response));
	}

	/** A kept PDF that cannot be read now is made again, not an error for whoever asked for it. */
	public function testAKeptPdfThatCannotBeReadIsMadeAgain(): void {
		$this->kept = ['r1/' . ResponsePdf::revision(['id' => 'r1']) => '%PDF-kept'];
		$this->readFails = true;

		$this->assertSame('%PDF-1', $this->pdfs()->of($this->createMock(File::class), self::KEPT + ['questions' => []], ['id' => 'r1']));
	}

	/** A webhook names only a kept PDF: one made but not kept would differ from the one the API makes later. */
	public function testOnlyAKeptPdfIsNamed(): void {
		$this->storeFails = true;

		$this->assertNull($this->pdfs()->keptOf($this->createMock(File::class), self::KEPT + ['questions' => []], ['id' => 'r1']));
	}

	/** One that cannot be kept is still the right document: it is made again next time. */
	public function testOneThatCannotBeKeptIsStillGiven(): void {
		$this->storeFails = true;

		$this->assertSame('%PDF-1', $this->pdfs()->of($this->createMock(File::class), self::KEPT + ['questions' => []], ['id' => 'r1']));
	}

	/** A form in the trash by now gets no new folder beside it. */
	public function testNothingIsKeptBesideAFormInTheTrash(): void {
		$this->where = 'files_trashbin/files/form.fvform.d123';

		$this->pdfs()->of($this->createMock(File::class), self::KEPT + ['questions' => []], ['id' => 'r1']);

		$this->assertSame([], $this->kept);
	}

	/** A form that keeps no PDFs gets none made, whoever asks: the same refusal the API gives. */
	public function testNothingIsMadeForAFormThatKeepsNoPdf(): void {
		try {
			$this->pdfs()->of($this->createMock(File::class), ['questions' => []], ['id' => 'r1']);
			$this->fail('a PDF was made');
		} catch (\OCA\FormVox\Exception\RequestException $e) {
			$this->assertSame(409, $e->getStatus());
			$this->assertSame('PDF generation is disabled for this form', $e->getMessage());
		}
		$this->assertSame(0, $this->renders);
		$this->assertSame([], $this->lookedIn, 'not even looked for');
	}
}
