<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Security\FormSanitizer;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class ResponseService {
	public function __construct(
		private FormRepository $formRepository,
		private FormFileLocator $fileLocator,
		private ResponsePersistenceService $responsePersistence,
		private IndexService $indexService,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private LoggerInterface $logger,
		private AnswerValidator $answers,
		private ICrypto $crypto,
		private FormSanitizer $sanitizer,
		private ResponseRules $rules,
	) {
	}

	/**
	 * A response on an anonymous form. $accountId is the respondent when the form makes them sign in:
	 * never stored, only used to recognise a repeat.
	 */
	/** @param string|null $signedInAs the account of a respondent who is signed in, though not named in the response */
	public function submitAnonymousWithForm(int $fileId, array $form, array $answers, IRequest $request, ?string $accountId = null, ?string $submissionId = null, ?string $timezone = null, ?string $signedInAs = null): array {
		return $this->submitPublic($fileId, $form, $answers, [
			'type' => 'anonymous',
			'fingerprint' => $this->fingerprint($fileId, $request, $accountId),
		], $submissionId, $timezone, $signedInAs ?? $accountId);
	}

	/**
	 * A response that carries who answered; the share link already admitted them, so they need no rights on the file (#77).
	 */
	public function submitAuthenticated(int $fileId, array $form, array $answers, string $userId, string $displayName, ?string $submissionId = null, ?string $timezone = null): array {
		return $this->submitPublic($fileId, $form, $answers, [
			'type' => 'user',
			'user_id' => $userId,
			'display_name' => $displayName,
		], $submissionId, $timezone, $userId);
	}

	/**
	 * A response through the external API: the same answers check and rules as one through the form (M16, Q7).
	 *
	 * @throws InvalidAnswerException|ResponseRefusedException
	 */
	public function submitViaApi(int $fileId, File $file, array $form, array $answers, array $keyConfig): array {
		$response = $this->newResponse($form, $this->answers->validate($form, $answers, fn () => $file), self::apiRespondent($keyConfig));
		// API ids stay 32 hex characters, as integrations have seen them so far.
		$response['id'] = bin2hex(random_bytes(16));
		$response['source'] = 'api';
		$response['api_key_id'] = $keyConfig['id'] ?? null;
		$this->rules->assertNew($form, $response);
		// Webhooks and notifications follow from a background job, so the integration need not wait for them.
		$this->responsePersistence->append($file, $response, [ResponseOutbox::WEBHOOKS, ResponseOutbox::NOTIFY], $this->l->getLanguageCode());
		return $response;
	}

	/**
	 * Replaces a response's answers by id; any response may be changed (PO), recorded by which key.
	 * The form's rules apply under the lock, as for a new response.
	 *
	 * @throws InvalidAnswerException|ResponseRefusedException|NotFoundException
	 */
	public function updateViaApi(int $fileId, File $file, array $form, string $responseId, array $answers, array $keyConfig): array {
		$answers = $this->answers->validate($form, $answers, fn () => $file);
		$quiz = $this->isQuizMode($form);
		$updated = $this->responsePersistence->update($file, $responseId, function (array $response) use ($answers, $keyConfig, $form, $quiz) {
			$response['answers'] = $answers;
			$response['updated_at'] = (new \DateTime())->format(\DateTime::ATOM);
			$response['updated_by'] = 'api:' . ($keyConfig['id'] ?? 'unknown');
			if ($quiz) {
				$response['score'] = $this->calculateScore($form, $answers);
			}
			return $response;
		});

		// Its webhook follows from the background job, in order after the response's own created.
		return $updated;
	}

	/**
	 * The one way a response is deleted, from the web or the API; integrations hear about it either way (L33).
	 *
	 * @throws NotFoundException
	 */
	public function deleteResponse(int $fileId, File $file, string $responseId): void {
		// Its webhook follows from the background job and carries only its id (PO: deleted is gone).
		$this->responsePersistence->delete($file, $responseId);
	}

	/** A retry answered as stored: what its first try set off is sent if it still waits. */
	public function retried(int $fileId, array $form, array $stored): void {
		$this->responsePersistence->retried($fileId, $form, $stored);
	}

	/**
	 * Every response at once; integrations hear about each one, as for a single delete (PO).
	 */
	public function deleteAllResponses(int $fileId, File $file): void {
		// Each deleted response's webhook follows from the background job.
		$this->responsePersistence->deleteAll($file);
	}

	/**
	 * The shared way in for the form page: answers checked, rules asked now for a clear answer and again under the lock.
	 *
	 * @throws InvalidAnswerException|ResponseRefusedException
	 */
	/** @param string|null $account the signed-in respondent's account: the confirmation goes to its address */
	private function submitPublic(int $fileId, array $form, array $answers, array $respondent, ?string $submissionId = null, ?string $timezone = null, ?string $account = null): array {
		// A retry of a submission that was stored: the same answer, before any rule could refuse it as a second one.
		$submission = SubmissionIds::of($submissionId, $answers);
		$seen = $this->responsePersistence->submitted($fileId, $form, $submission);
		if ($seen !== null) {
			$this->responsePersistence->retried($fileId, $form, $seen);
			return $seen;
		}
		$answers = $this->answers->validate($form, $answers, fn () => $this->fileLocator->getFileByIdPublicForWrite($fileId));
		$response = $this->newResponse($form, $answers, $respondent, $timezone);
		$this->rules->assertNew($form, $response);
		// Webhooks, notifications and the confirmation email follow from a background job: the respondent has their
		// answer without waiting for a slow receiver or mail server (7a, 3a-4).
		return $this->responsePersistence->appendResponsePublic($fileId, $response, [ResponseOutbox::WEBHOOKS, ResponseOutbox::NOTIFY, ResponseOutbox::MAIL], $this->l->getLanguageCode(), $submission, $account !== null, $account);
	}

	private function newResponse(array $form, array $answers, array $respondent, ?string $timezone = null): array {
		$response = [
			'id' => $this->generateUuid(),
			'submitted_at' => date('c'),
			// Its PDF is in the language the form was filled in, also when it is made later.
			'language' => $this->l->getLanguageCode(),
			'respondent' => $respondent,
			'answers' => $answers,
		];
		// Where the browser named a real zone: its times in the PDF are at the respondent's clock.
		if (\OCA\FormVox\Pdf\TimeZones::name($timezone) !== null) {
			$response['timezone'] = $timezone;
		}
		if ($this->isQuizMode($form)) {
			$response['score'] = $this->calculateScore($form, $answers);
		}
		return $response;
	}

	/**
	 * Who answered through the API, so the results view shows it like any other respondent (M30).
	 */
	public static function apiRespondent(array $keyConfig): array {
		return [
			'type' => 'api',
			'key_id' => (string)($keyConfig['id'] ?? ''),
			'display_name' => (string)($keyConfig['name'] ?? 'API'),
		];
	}

	/**
	 * Get summary statistics for a form
	 */
	public function getSummary(int $fileId): array {
		$form = $this->formRepository->loadForm($fileId);
		return $this->buildSummary($form, $this->formRepository->eachResponse($fileId, $form));
	}

	/**
	 * Build the summary in one pass over the responses, a page at a time: counted as rebuildIndex counts them, plus the
	 * different typed answers and the numeric statistics.
	 *
	 * @param iterable<array> $responses
	 */
	private function buildSummary(array $form, iterable $responses): array {
		// Sections are UI grouping containers, not questions — exclude them
		// so they don't show up as empty "questions" in the results summary.
		$questions = array_values(array_filter(
			$form['questions'] ?? [],
			fn ($q) => !in_array($q['type'] ?? '', ['section', 'descriptor'], true),
		));
		// Typed answers are not in the index (H6); the summary counts them from the responses.
		$typed = [];
		/** @var array<string, array{0: float, 1: int, 2: float, 3: float}|null> $numeric sum, count, min and max */
		$numeric = [];
		foreach ($questions as $question) {
			if (in_array($question['type'], ['text', 'textarea', 'number', 'date', 'datetime', 'time'], true)) {
				$typed[(string)$question['id']] = [];
			}
			if (in_array($question['type'], ['number', 'scale', 'rating'], true)) {
				$numeric[(string)$question['id']] = null;
			}
		}

		$index = ['response_count' => 0, 'last_response_at' => null, 'answer_counts' => []];
		foreach ($responses as $response) {
			$this->indexService->tally($index, $form, $response);
			foreach (array_keys($typed) as $questionId) {
				$answer = $response['answers'][$questionId] ?? null;
				if (is_scalar($answer) && $answer !== '') {
					$typed[$questionId][(string)$answer] = true;
				}
			}
			foreach ($numeric as $questionId => $stat) {
				$answer = $response['answers'][$questionId] ?? null;
				// An older stored '1e400' would be infinite here, and the summary could not go out as JSON.
				if ($answer !== null && is_numeric($answer) && is_finite((float)$answer)) {
					$value = (float)$answer;
					$numeric[$questionId] = $stat === null ? [$value, 1, $value, $value] : [$stat[0] + $value, $stat[1] + 1, min($stat[2], $value), max($stat[3], $value)];
				}
			}
		}

		$summary = [
			'responseCount' => $index['response_count'],
			'lastResponseAt' => $index['last_response_at'],
			'questions' => [],
		];

		foreach ($questions as $question) {
			$questionId = (string)$question['id'];
			$questionSummary = [
				'id' => $question['id'],
				'type' => $question['type'],
				'question' => $question['question'],
				'answerCounts' => $index['answer_counts'][$questionId] ?? [],
			];

			// Preserve options/rows/columns so the frontend can map stored
			// values (often internal ids) back to human-readable labels.
			if (in_array($question['type'], ['choice', 'multiple', 'dropdown']) && isset($question['options'])) {
				$questionSummary['options'] = $question['options'];
			}
			if ($question['type'] === 'matrix') {
				if (isset($question['rows'])) {
					$questionSummary['rows'] = $question['rows'];
				}
				if (isset($question['columns'])) {
					$questionSummary['columns'] = $question['columns'];
				}
			}

			if (array_key_exists($questionId, $typed)) {
				$questionSummary['uniqueAnswers'] = count($typed[$questionId]);
			}

			if (array_key_exists($questionId, $numeric)) {
				$stat = $numeric[$questionId];
				$questionSummary['average'] = $stat === null ? null : round($stat[0] / $stat[1], 2);
				$questionSummary['min'] = $stat === null ? null : $stat[2];
				$questionSummary['max'] = $stat === null ? null : $stat[3];
			}

			$summary['questions'][] = $questionSummary;
		}

		return $summary;
	}

	/**
	 * Get all responses for a form
	 */
	public function getResponses(int $fileId, ?string $dateFilter = null): array {
		$form = $this->formRepository->loadForm($fileId);
		if ($dateFilter !== null) {
			$day = [];
			foreach ($this->formRepository->eachResponse($fileId, $form) as $response) {
				if (substr((string)($response['submitted_at'] ?? ''), 0, 10) === $dateFilter) {
					$day[] = $response;
				}
			}
			return $this->sanitizer->responses($day);
		}
		return $this->sanitizer->responses($this->formRepository->page($fileId, $form)['responses']);
	}

	/**
	 * One page of responses, oldest first, and the cursor of the next.
	 *
	 * @return array{responses: list<array>, next: ?string}
	 */
	public function getResponsePage(int $fileId, int $limit, ?string $after = null): array {
		$page = $this->formRepository->page($fileId, $this->formRepository->loadForm($fileId), $limit, $after);
		return ['responses' => $this->sanitizer->responses($page['responses']), 'next' => $page['next']];
	}

	/**
	 * Neutralise CSV formula injection. A respondent (including anonymous) can
	 * submit an answer beginning with =, +, -, @, or a tab/CR; when the form
	 * owner opens the export in Excel/LibreOffice such a cell is evaluated as a
	 * formula (DDE command execution, HYPERLINK/WEBSERVICE data exfiltration).
	 * Prefixing with a single quote forces the cell to be treated as text.
	 */
	private function sanitizeCsvCell($value) {
		if (!is_string($value) || $value === '') {
			return $value;
		}
		if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * The shared export table used by both the CSV and XLSX exporters, so the two formats stay identical in content:
	 * the header row, then one row per response, read a page at a time; nothing at all when there are no responses.
	 *
	 * Every string cell is run through sanitizeCsvCell() — formula injection
	 * applies to XLSX just as much as CSV.
	 *
	 * @return \Generator<int, list<mixed>>
	 */
	private function exportRows(int $fileId): \Generator {
		$form = $this->formRepository->loadForm($fileId);

		// Sections are UI grouping containers, not questions — exclude them
		// so they don't appear as empty columns next to the answer columns.
		$questions = array_values(array_filter(
			$form['questions'] ?? [],
			fn ($q) => !in_array($q['type'] ?? '', ['section', 'descriptor'], true),
		));

		// Header row (question titles are sanitised too — a title beginning
		// with a formula char would otherwise inject on the owner's export).
		$headers = [
			// TRANSLATORS: Column header in the CSV and Excel export: the identifier of a response
			$this->l->t('Response ID'),
			// TRANSLATORS: Column header in the CSV and Excel export: date and time of a response
			$this->l->t('Submitted at'),
			// TRANSLATORS: Column header in the CSV and Excel export: anonymous, account or API
			$this->l->t('Respondent type'),
			// TRANSLATORS: Column header in the CSV and Excel export: who answered
			$this->l->t('Respondent ID'),
		];
		foreach ($questions as $question) {
			$headers[] = $this->sanitizeCsvCell($question['question'] ?? '');
		}

		$first = true;
		foreach ($this->formRepository->eachResponse($fileId, $form) as $response) {
			if ($first) {
				yield $headers;
				$first = false;
			}
			yield $this->exportRow($questions, $response);
		}
	}

	/** @return list<mixed> */
	private function exportRow(array $questions, array $response): array {
		$row = [
			$response['id'],
			$response['submitted_at'],
			$response['respondent']['type'] ?? (($response['source'] ?? '') === 'api' ? 'api' : 'anonymous'),
			// The fingerprint only tells the server about repeats; it is not a respondent (M13).
			$response['respondent']['user_id'] ?? '',
		];

		foreach ($questions as $question) {
			$answer = $response['answers'][$question['id']] ?? '';

			// Consent: render boolean as Yes/No so it is human-readable (#94).
			if (($question['type'] ?? '') === 'consent') {
				$answer = $answer === true ? $this->l->t('Yes') : ($answer === false ? $this->l->t('No') : '');
			}

			// Matrix questions: format as "Row: Column" pairs
			if (($question['type'] ?? '') === 'matrix' && is_array($answer)) {
				$rowsDef = $question['rows'] ?? [];
				$columns = $question['columns'] ?? [];
				$rowMap = [];
				foreach ($rowsDef as $r) {
					$rowMap[$r['id']] = $r['label'] ?? $r['id'];
				}
				$colMap = [];
				foreach ($columns as $c) {
					$colMap[$c['value'] ?? $c['id']] = $c['label'] ?? $c['value'] ?? '';
				}
				$parts = [];
				foreach ($answer as $rowId => $colValue) {
					$rowLabel = $rowMap[$rowId] ?? $rowId;
					$colLabel = $colMap[$colValue] ?? $colValue;
					$parts[] = $rowLabel . ': ' . $colLabel;
				}
				$answer = implode(', ', $parts);
			} elseif (($question['type'] ?? '') === 'table' && is_array($answer)) {
				// Table answers are arrays of row objects keyed by column id.
				// Replace internal column ids with the user-defined label.
				$columns = $question['columns'] ?? [];
				$colLabels = [];
				foreach ($columns as $c) {
					$cid = $c['id'] ?? null;
					if ($cid !== null) {
						$colLabels[$cid] = $c['label'] ?? $cid;
					}
				}
				$relabelled = [];
				foreach ($answer as $rowObj) {
					if (!is_array($rowObj)) {
						continue;
					}
					$relabelledRow = [];
					foreach ($rowObj as $cid => $val) {
						$key = $colLabels[$cid] ?? $cid;
						$relabelledRow[$key] = $val;
					}
					$relabelled[] = $relabelledRow;
				}
				$answer = json_encode($relabelled, JSON_UNESCAPED_UNICODE);
			} else {
				// Map option values to labels for choice/multiple/dropdown questions
				$options = $question['options'] ?? [];
				if (!empty($options)) {
					$optionMap = [];
					foreach ($options as $opt) {
						$optionMap[$opt['value'] ?? $opt['id']] = $opt['label'] ?? $opt['value'] ?? '';
					}

					if (is_array($answer)) {
						$answer = array_map(function ($val) use ($optionMap) {
							return $optionMap[$val] ?? $val;
						}, $answer);
					} elseif (is_string($answer) && isset($optionMap[$answer])) {
						$answer = $optionMap[$answer];
					}
				}

				if (is_array($answer)) {
					// Table or file answers: serialize as JSON
					if (!empty($answer) && is_array(reset($answer))) {
						$answer = json_encode($answer, JSON_UNESCAPED_UNICODE);
					} else {
						$answer = implode(', ', $answer);
					}
				}
			}
			// Formula-injection safety, applied uniformly to every cell.
			$row[] = is_string($answer) ? $this->sanitizeCsvCell($answer) : $answer;
		}

		return $row;
	}

	/**
	 * Export responses to CSV format
	 */
	public function exportCsv(int $fileId): string {
		$output = fopen('php://temp', 'r+');
		$any = false;

		// Semicolon separator + BOM (no sep= directive) — see the return
		// statement below for why. eol: "\r\n" matches Excel's record
		// separator and the \r\n we normalise within cells (#83).
		foreach ($this->exportRows($fileId) as $r => $row) {
			$any = true;
			if ($r > 0) {
				// Normalise embedded newlines so Excel renders them as in-cell
				// line breaks instead of new rows (cells are already sanitised).
				$row = array_map(function ($v) {
					if (!is_string($v)) {
						return $v;
					}
					$v = str_replace(["\r\n", "\r"], ["\n", "\n"], $v);
					return str_replace("\n", "\r\n", $v);
				}, $row);
			}
			fputcsv($output, $row, separator: ';', eol: "\r\n");
		}
		if (!$any) {
			fclose($output);
			return '';
		}

		rewind($output);
		$csv = stream_get_contents($output);
		fclose($output);

		// Prepend a UTF-8 BOM so Excel on Windows detects UTF-8 and renders
		// umlauts correctly. We deliberately do NOT emit a `sep=` directive:
		// in Excel a `sep=` line forces the legacy ANSI-codepage parser, which
		// ignores the BOM and corrupts umlauts (ö → Ã¶) on German Windows
		// (#114). Columns are delimited with `;`, matching the Excel list-
		// separator default in DE/NL/FR locales (our primary audience, #91).
		// RFC 4180 tools (Pandas/R/LibreOffice) read the BOM/delimiter fine.
		// Users needing comma-delimited or guaranteed columns everywhere can
		// use the .xlsx export instead.
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Export responses as a real .xlsx (Office Open XML) file.
	 *
	 * Unlike CSV, xlsx has no BOM/separator/codepage ambiguity: umlauts and
	 * columns are correct in every Excel locale by construction (#114). Built
	 * by hand with \ZipArchive — no external dependency — writing the five
	 * minimal parts of a workbook with inline-string cells. Returns the raw
	 * xlsx bytes, or '' when there are no responses.
	 */
	public function exportXlsx(int $fileId): string {
		// The single worksheet, header row + data rows with inline strings, written to a file a row at a time.
		$sheetFile = tempnam(sys_get_temp_dir(), 'formvox_sheet_');
		$tempFile = null;
		try {
			$sheet = fopen($sheetFile, 'w');
			fwrite($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
				. '<sheetData>');
			$any = false;
			foreach ($this->exportRows($fileId) as $r => $row) {
				$any = true;
				$rowNum = $r + 1;
				$cells = '';
				$col = 0;
				foreach ($row as $value) {
					$ref = $this->xlsxColumnLetter($col) . $rowNum;
					$col++;
					if ($value === null || $value === '') {
						continue; // empty cell — omit entirely
					}
					if (is_int($value) || is_float($value)) {
						$cells .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
					} else {
						// Inline string; xml:space="preserve" keeps leading/trailing spaces
						// (e.g. our formula-injection guard prefixes a value with an apostrophe).
						$cells .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
							. $this->xmlEscape((string)$value) . '</t></is></c>';
					}
				}
				fwrite($sheet, '<row r="' . $rowNum . '">' . $cells . '</row>');
			}
			fwrite($sheet, '</sheetData></worksheet>');
			fclose($sheet);
			if (!$any) {
				return '';
			}

			$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
				. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
				. '<Default Extension="xml" ContentType="application/xml"/>'
				. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
				. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
				. '</Types>';

			$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
				. '</Relationships>';

			$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
				. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
				. '<sheets><sheet name="Responses" sheetId="1" r:id="rId1"/></sheets></workbook>';

			$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
				. '</Relationships>';

			$tempFile = tempnam(sys_get_temp_dir(), 'formvox_xlsx_');
			$zip = new \ZipArchive();
			if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
				throw new \RuntimeException('Failed to create XLSX file');
			}
			$zip->addFromString('[Content_Types].xml', $contentTypes);
			$zip->addFromString('_rels/.rels', $rootRels);
			$zip->addFromString('xl/workbook.xml', $workbook);
			$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
			$zip->addFile($sheetFile, 'xl/worksheets/sheet1.xml');
			$zip->close();

			$content = file_get_contents($tempFile);
			return $content === false ? '' : $content;
		} finally {
			// Also when reading the responses or making the ZIP fails halfway.
			foreach ([$sheetFile, $tempFile] as $file) {
				if (is_string($file) && is_file($file)) {
					unlink($file);
				}
			}
		}
	}

	/**
	 * Convert a 0-based column index to an Excel column letter (0→A, 26→AA).
	 */
	private function xlsxColumnLetter(int $index): string {
		$letter = '';
		$index++;
		while ($index > 0) {
			$rem = ($index - 1) % 26;
			$letter = chr(65 + $rem) . $letter;
			$index = intdiv($index - 1, 26);
		}
		return $letter;
	}

	/**
	 * XML-escape a value for inclusion in a worksheet cell, stripping control
	 * characters that are illegal in XML 1.0 (which would corrupt the file).
	 */
	private function xmlEscape(string $value): string {
		$value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
		return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	/**
	 * Export responses to JSON format
	 */
	public function exportJson(int $fileId): string {
		$form = $this->formRepository->loadForm($fileId);
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;
		$head = json_encode([
			'title' => $form['title'],
			'exportedAt' => date('c'),
			'questions' => $form['questions'],
			'responses' => [],
		], $flags);
		if ($head === false) {
			throw new \RuntimeException('The form could not be encoded');
		}

		// The responses a page at a time, laid out as json_encode lays out the whole: two levels in.
		$output = fopen('php://temp', 'r+');
		fwrite($output, substr($head, 0, -strlen("[]\n}")));
		$first = true;
		foreach ($this->formRepository->eachResponse($fileId, $form) as $response) {
			$json = json_encode($this->sanitizer->response($response), $flags);
			if ($json === false) {
				fclose($output);
				throw new \RuntimeException('A response could not be encoded');
			}
			fwrite($output, ($first ? "[\n" : ",\n") . preg_replace('/^/m', '        ', $json));
			$first = false;
		}
		fwrite($output, $first ? "[]\n}" : "\n    ]\n}");
		rewind($output);
		$json = (string)stream_get_contents($output);
		fclose($output);
		return $json;
	}

	/**
	 * Recognises a repeat without recording who it was: keyed with the server's secret and the form, and
	 * without the share token, so a new link does not reset it (M13, L5). A signed-in respondent of an
	 * anonymous form counts per account (M34); anyone else per network and browser.
	 */
	private function fingerprint(int $fileId, IRequest $request, ?string $accountId): string {
		$subject = $accountId !== null
			? 'account|' . $accountId
			: 'request|' . $request->getRemoteAddress() . '|' . $request->getHeader('User-Agent');
		return 'v2:' . bin2hex($this->crypto->calculateHMAC($fileId . '|' . $subject));
	}

	/**
	 * Check if form is in quiz mode
	 */
	private function isQuizMode(array $form): bool {
		foreach ($form['questions'] ?? [] as $question) {
			if (isset($question['options'])) {
				foreach ($question['options'] as $option) {
					if (isset($option['score'])) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Calculate score for quiz mode
	 */
	private function calculateScore(array $form, array $answers): array {
		$totalScore = 0;
		$maxScore = 0;
		$questionScores = [];

		foreach ($form['questions'] ?? [] as $question) {
			if (!isset($question['options'])) {
				continue;
			}

			$questionId = $question['id'];
			$answer = $answers[$questionId] ?? null;
			$questionScore = 0;
			$questionMaxScore = 0;

			foreach ($question['options'] as $option) {
				$optionScore = $option['score'] ?? 0;

				if ($question['type'] === 'multiple') {
					// Multiple-choice sums the score of every ticked option, so
					// the best possible score is the sum of all positively
					// scored options — not the single highest one (#118).
					if ($optionScore > 0) {
						$questionMaxScore += $optionScore;
					}
					if (is_array($answer) && in_array($option['value'], $answer)) {
						$questionScore += $optionScore;
					}
				} else {
					// Single-choice: only one option can be picked, so the best
					// possible score is the highest-scoring option.
					$questionMaxScore = max($questionMaxScore, $optionScore);
					if ($answer === $option['value']) {
						$questionScore = $optionScore;
					}
				}
			}

			$totalScore += $questionScore;
			$maxScore += $questionMaxScore;
			$questionScores[$questionId] = $questionScore;
		}

		return [
			'total' => $totalScore,
			'max' => $maxScore,
			'percentage' => $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 1) : 0,
			'byQuestion' => $questionScores,
		];
	}

	/**
	 * Generate a UUID v4
	 */
	private function generateUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
