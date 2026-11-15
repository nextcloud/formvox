<?php

declare(strict_types=1);

namespace OCA\FormVox\Pdf;

use OCA\FormVox\Service\AnswerDate;
use OCA\FormVox\Service\FileNames;
use OCA\FormVox\Service\FormLogic;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IL10N;

/** One response as the respondent saw the form, in their language and time zone; all form and answer text escaped. */
class ResponseDocument {
	/** Line breaks one PDF draws: dompdf spends about 17 KB on each, so past them text runs on. */
	private const LINES = 2000;
	/** Table cells and list items one PDF draws, at about 30 KB each. */
	private const CELLS = 4000;
	private const ANSWER_CHARS = 50000;
	/** Far above a whole submission (256 KiB): only older stored answers reach it. */
	private const CHARS = 500000;
	/** dompdf lays out a text in time quadratic in its length, so a longer one goes in pieces. */
	private const PIECE = 2000;
	/** The page between its margins (@page, A4) and a cell's padding, in mm, and a 10 pt glyph's width in them. */
	private const PAGE_WIDTH = 174;
	private const CELL_PADDING = 4;
	private const GLYPH = 1.9;

	/** @var array{lines: int, cells: int, chars: int} what this document may still draw */
	private array $left = ['lines' => 0, 'cells' => 0, 'chars' => 0];
	private int $answerLeft = 0;
	private bool $shortened = false;

	public function __construct(
		private FormLogic $logic,
		private IDateTimeFormatter $formatter,
		private IDateTimeZone $timeZone,
		private Brand $brand,
	) {
	}

	public function html(array $form, array $response, IL10N $l): string {
		$this->left = ['lines' => self::LINES, 'cells' => self::CELLS, 'chars' => self::CHARS];
		$intro = $this->description($form['description'] ?? '', 'intro');
		$answers = is_array($response['answers'] ?? null) ? $response['answers'] : [];
		$hidden = $this->logic->analyse($form, $answers)['hidden'];
		$tz = $this->zoneOf($response);
		$all = [];
		$byId = [];
		foreach (is_array($form['questions'] ?? null) ? $form['questions'] : [] as $question) {
			if (is_array($question) && is_scalar($question['id'] ?? null)) {
				$all[] = $question;
				$byId[(string)$question['id']] ??= $question;
			}
		}
		// As the page: only what the respondent could see is filled in.
		$shown = array_diff_key($answers, $hidden);
		$piped = fn (mixed $text): string => $this->pipe(is_scalar($text) ? (string)$text : '', $all, $shown);

		$body = '';
		foreach ($this->logic->pagesShown($form, $answers) as $page) {
			// As Respond.vue displayItems: a section's questions together, where the first of them is.
			$visible = [];
			foreach ($page as $id) {
				$question = $byId[$id] ?? null;
				if ($question !== null && !isset($hidden[$id]) && !in_array($question['type'] ?? '', ['section', 'descriptor'], true)) {
					$visible[] = $question;
				}
			}
			$done = [];
			foreach ($visible as $question) {
				$section = is_scalar($question['sectionId'] ?? null) ? (string)$question['sectionId'] : null;
				if ($section === null) {
					$body .= $this->question($question, $answers, $l, $tz, $piped);
					continue;
				}
				if (isset($done[$section])) {
					continue;
				}
				$done[$section] = true;
				// Not piped, as on the page.
				$heading = is_scalar($byId[$section]['question'] ?? null) ? (string)$byId[$section]['question'] : '';
				if ($heading !== '') {
					$body .= '<h2>' . $this->words($heading) . '</h2>';
				}
				$body .= $this->description($byId[$section]['description'] ?? '');
				foreach ($visible as $member) {
					if ((string)($member['sectionId'] ?? '') === $section) {
						$body .= $this->question($member, $answers, $l, $tz, $piped);
					}
				}
			}
		}

		// TRANSLATORS: In the PDF of a response: who filled in the form, a name or "Anonymous"
		$meta = [$l->t('Filled in by: %s', [self::respondent($response, $l)])];
		$submitted = $this->moment((string)($response['submitted_at'] ?? ''), $l, $tz);
		if ($submitted !== '') {
			$meta[] = $l->t('Submitted on %s', [$submitted . ' (' . $tz->getName() . ')']);
		}
		$score = is_array($response['score'] ?? null) ? $response['score'] : null;
		if ($score !== null && isset($score['total'], $score['max'])) {
			$meta[] = $l->t('Score: %1$s / %2$s (%3$s%%)', [(string)$score['total'], (string)$score['max'], (string)($score['percentage'] ?? 0)]);
		}
		$title = self::text($form['title'] ?? '');

		return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . self::CSS . '</style></head><body>'
			. $this->band()
			. '<h1>' . $title . '</h1>'
			. $intro
			. '<p class="meta">' . implode(' · ', array_map(self::text(...), $meta)) . '</p>'
			// Whole, as the API and webhook name it: the key to match this PDF elsewhere.
			. '<p class="rid">' . self::text($l->t('Response %s', [(string)($response['id'] ?? '')])) . '</p>'
			. $body . '</body></html>';
	}

	/** {title}_{day sent}_{id}.pdf, the day as this document shows it. */
	public function fileName(array $form, array $response): string {
		$sent = is_scalar($response['submitted_at'] ?? null) ? (string)$response['submitted_at'] : '';
		$tz = $this->zoneOf($response);
		try {
			// new DateTime('') would be today.
			$day = $sent === '' ? '' : (new \DateTime($sent, $tz))->setTimezone($tz)->format('Y-m-d');
		} catch (\Exception) {
			$day = '';
		}
		return FileNames::responsePdf(is_scalar($form['title'] ?? null) ? (string)$form['title'] : '', $day, (string)($response['id'] ?? ''));
	}

	/**
	 * At the foot of every page: the response, so a loose printed page can be found again, and the page numbers.
	 *
	 * @return array{0: string, 1: string} the text on the left, and the page numbers with {current} and {total}
	 */
	public function footer(array $response, IL10N $l): array {
		return [$l->t('Response %s', [(string)($response['id'] ?? '')]), $l->t('Page {current} of {total}')];
	}

	/** As the results name them (respondent.js): a person, an API key, or nobody named. */
	private static function respondent(array $response, IL10N $l): string {
		$respondent = is_array($response['respondent'] ?? null) ? $response['respondent'] : [];
		$name = is_scalar($respondent['display_name'] ?? null) ? (string)$respondent['display_name'] : '';
		$type = $respondent['type'] ?? null;
		if ($type === 'api' || (!isset($response['respondent']) && ($response['source'] ?? null) === 'api')) {
			return $name !== '' ? $name . ' (API)' : 'API';
		}
		if (($type === 'user' || $type === 'external') && $name !== '') {
			return $name;
		}
		return $l->t('Anonymous');
	}

	/** As Nextcloud's own header: the theming logo is made for the primary colour, often white. */
	private function band(): string {
		$brand = $this->brand->header();
		$name = '<td class="band-name' . ($brand['logo'] === null ? '' : ' right') . '" style="color:' . $brand['textColor'] . '">' . self::text($brand['name']) . '</td>';
		$logo = $brand['logo'] === null ? '' : '<td><img class="logo" src="' . self::text($brand['logo']) . '" alt=""></td>';
		return '<table class="band" style="background-color:' . $brand['color'] . '"><tr>' . $logo . $name . '</tr></table>';
	}

	private const CSS = '@page{margin:20mm 18mm 22mm 18mm}'
		// A long word or link wraps at the page edge instead of running off it.
		. 'body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;color:#222;line-height:1.35;overflow-wrap:anywhere}'
		. 'h1{font-size:17pt;margin:0 0 2mm 0}h2{font-size:12.5pt;margin:7mm 0 2mm 0;border-bottom:1px solid #999}'
		. '.band{margin:0 0 6mm 0}.band td{border:none;padding:3mm 5mm;vertical-align:middle}'
		. '.band-name{font-weight:bold;font-size:11pt}.right{text-align:right}.logo{height:11mm}'
		. '.intro{color:#444;margin:0 0 3mm 0}'
		. '.meta{color:#666;font-size:9pt;margin:0 0 1mm 0}.rid{color:#888;font-size:8pt;margin:0 0 6mm 0}'
		. '.q{margin:0 0 4mm 0;page-break-inside:avoid}.label{font-weight:bold;margin-bottom:1mm}'
		. '.answer{margin-left:3mm}.none{color:#888;font-style:italic}ul{margin:0;padding-left:5mm}'
		. '.desc{color:#555;font-size:9pt;margin:0 0 1.5mm 0}'
		. 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:1.5mm 2mm;text-align:left;vertical-align:top}'
		// Equal columns, whatever a cell holds: laid out by content, one long cell squeezes the others to a letter a line.
		. '.answer table{table-layout:fixed}'
		. 'th{background:#f2f2f2}';

	/** @param \Closure(mixed): string $piped */
	private function question(array $question, array $answers, IL10N $l, \DateTimeZone $tz, \Closure $piped): string {
		$html = '<div class="q"><div class="label">' . $this->words($piped($question['question'] ?? '')) . '</div>'
			. $this->description($piped($question['description'] ?? ''));
		$this->startAnswer();
		$answer = $this->answer($question, $answers[(string)$question['id']] ?? null, $l, $tz, $piped);
		if ($this->shortened) {
			// TRANSLATORS: In the PDF of a response, under an answer too long to show whole
			$answer .= '<div class="none">' . self::text($l->t('This answer is shortened in the PDF; the full answer is in FormVox.')) . '</div>';
		}
		return $html . '<div class="answer">' . $answer . '</div></div>';
	}

	/** Its own share of characters, nothing cut yet. */
	private function startAnswer(): void {
		$this->answerLeft = self::ANSWER_CHARS;
		$this->shortened = false;
	}

	private function description(mixed $text, string $class = 'desc'): string {
		$text = trim(self::plain(is_scalar($text) ? (string)$text : ''));
		return $text === '' ? '' : '<div class="' . $class . '">' . $this->lines($text) . '</div>';
	}

	/** As nl2br, but a run of empty lines draws one, and past the document's line breaks the text runs on. */
	private function lines(string $text, bool $answer = false): string {
		$text = (string)preg_replace('/\n(?:[ \t]*\n){2,}/', "\n\n", str_replace(["\r\n", "\r"], "\n", $text));
		$html = '';
		foreach (explode("\n", $text) as $i => $line) {
			if ($i > 0) {
				if ($this->room($answer) === 0) {
					break;
				}
				$html .= $this->spend('lines', 1) ? "<br>\n" : ' ';
			}
			$html .= $this->words($line, $answer);
		}
		return $html;
	}

	/** Escaped; cut where the answer or the document has shown all it may. */
	private function words(string $text, bool $answer = false): string {
		$room = $this->room($answer);
		$length = mb_strlen($text);
		if ($length > $room) {
			$text = mb_substr($text, 0, $room);
			$length = $room;
			$this->shortened = $this->shortened || $answer;
		}
		$this->left['chars'] -= $length;
		if ($answer) {
			$this->answerLeft -= $length;
		}
		return self::pieces($text);
	}

	private function room(bool $answer): int {
		return $answer ? min($this->left['chars'], $this->answerLeft) : $this->left['chars'];
	}

	/**
	 * Takes $n of what the document may still draw; false, and from then on none left, when it has not that much.
	 *
	 * @param 'lines'|'cells' $kind
	 */
	private function spend(string $kind, int $n): bool {
		if ($this->left[$kind] < $n) {
			$this->left[$kind] = 0;
			return false;
		}
		$this->left[$kind] -= $n;
		return true;
	}

	/**
	 * A cell of a table with this many equal columns: the lines it wraps into beyond its first come out of the
	 * document's line breaks, and where those run out the text is cut.
	 */
	private function cell(string $text, int $columns): string {
		$perLine = max(1, (int)floor((self::PAGE_WIDTH / $columns - self::CELL_PADDING) / self::GLYPH));
		$lines = (int)ceil(mb_strlen($text) / $perLine) - 1;
		if ($lines > 0) {
			$taken = min($lines, $this->left['lines']);
			$this->left['lines'] -= $taken;
			if ($taken < $lines) {
				$text = mb_substr($text, 0, ($taken + 1) * $perLine);
				$this->shortened = true;
			}
		}
		return $this->words($text, true);
	}

	/** Escaped, a long text in pieces cut after a space, where it would break anyway. */
	private static function pieces(string $text): string {
		if (strlen($text) <= self::PIECE) {
			return self::text($text);
		}
		$html = '';
		$offset = 0;
		$length = strlen($text);
		while ($offset < $length) {
			$piece = mb_strcut($text, $offset, self::PIECE, 'UTF-8');
			$space = strrpos($piece, ' ');
			if ($space !== false && $space >= self::PIECE / 2 && $offset + strlen($piece) < $length) {
				$piece = substr($piece, 0, $space + 1);
			}
			if ($piece === '') {
				break;
			}
			$html .= '<span>' . self::text($piece) . '</span>';
			$offset += strlen($piece);
		}
		return $html;
	}

	/**
	 * The page renders descriptions as Markdown: the PDF shows the text a reader saw. A mark is closed within 1,000
	 * characters, so a long text without its closing mark costs linear time, not quadratic.
	 */
	private static function plain(string $markdown): string {
		$patterns = [
			'/!\[[^\]]{0,1000}\]\([^)]{0,1000}\)/u' => '',
			'/\[([^\]]{1,1000})\]\(([^)\s]{1,1000})[^)]{0,1000}\)/u' => '$1 ($2)',
			'/(\*\*|__)(.{1,1000}?)\1/u' => '$2',
			'/(?<![\w*])([*_])(?!\s)(.{1,1000}?)(?<!\s)\1(?![\w*])/u' => '$2',
			'/~~(.{1,1000}?)~~/u' => '$1',
			'/`{1,3}([^`]*)`{1,3}/u' => '$1',
			'/^#{1,6}\s+/mu' => '',
			'/^>\s?/mu' => '',
			'/^\s*[-*+]\s+/mu' => '• ',
		];
		return (string)preg_replace(array_keys($patterns), array_values($patterns), $markdown);
	}

	/** @param \Closure(mixed): string $piped */
	private function answer(array $question, mixed $answer, IL10N $l, \DateTimeZone $tz, \Closure $piped): string {
		$type = (string)($question['type'] ?? '');
		if ($type === 'table' && is_array($answer)) {
			// The page submits its empty starting rows too.
			$answer = array_values(array_filter($answer, static fn ($row) => is_array($row) && array_filter($row, static fn ($cell) => trim(is_scalar($cell) ? (string)$cell : '') !== '') !== []));
		}
		if ($answer === null || $answer === '' || $answer === []) {
			return '<span class="none">' . self::text($l->t('Not answered')) . '</span>';
		}
		$scalar = is_scalar($answer) ? (string)$answer : '';
		return match ($type) {
			'textarea' => $this->lines($scalar, true),
			'choice', 'dropdown' => $this->words($piped($this->optionLabel($question, $answer)), true),
			'multiple' => $this->list(array_map(fn ($value) => $piped($this->optionLabel($question, $value)), is_array($answer) ? $answer : [$answer])),
			'consent' => $this->words(($answer === true ? $l->t('Yes') : $l->t('No'))
				. (is_scalar($question['consentLabel'] ?? null) && trim((string)$question['consentLabel']) !== '' ? ': ' . $piped($question['consentLabel']) : ''), true),
			'date' => $this->words($this->date($scalar, $l, $tz), true),
			'datetime' => $this->words($this->dateTime($scalar, $l, $tz), true),
			'scale' => $this->words($scalar . ' / ' . (int)($question['scaleMax'] ?? $question['max'] ?? 5), true),
			'rating' => $this->words($scalar . ' / ' . (int)($question['ratingMax'] ?? $question['max'] ?? 5), true),
			'matrix' => $this->matrix($question, $answer),
			'table' => is_array($answer) ? $this->table($question, $answer) : $this->words($scalar, true),
			'file' => $this->list(array_map(
				fn ($file) => is_array($file) ? (string)($file['originalName'] ?? $file['filename'] ?? '') : (is_scalar($file) ? (string)$file : ''),
				is_array($answer) && array_is_list($answer) ? $answer : [$answer],
			)),
			default => $this->words(is_array($answer) ? implode(', ', array_map('strval', array_filter($answer, 'is_scalar'))) : $scalar, true),
		};
	}

	/**
	 * As QuestionRenderer.vue applyPiping: {{Q1}} or {{questionId}} becomes that answer, an empty one is left as written.
	 *
	 * @param list<array> $questions
	 */
	private function pipe(string $text, array $questions, array $answers): string {
		return (string)preg_replace_callback('/\{\{(\w+)\}\}/', static function (array $match) use ($questions, $answers): string {
			if (preg_match('/^Q(\d+)$/i', $match[1], $number) === 1) {
				$question = $questions[(int)$number[1] - 1] ?? null;
				$id = is_array($question) ? (string)$question['id'] : null;
			} else {
				$id = $match[1];
			}
			$answer = $id === null ? null : ($answers[$id] ?? null);
			if ($answer === null || $answer === '' || $answer === []) {
				return $match[0];
			}
			if (is_array($answer)) {
				return array_is_list($answer)
					? implode(', ', array_map(static fn ($v) => is_scalar($v) ? (string)$v : '', $answer))
					: implode('; ', array_map(static fn ($k, $v) => $k . ': ' . (is_scalar($v) ? (string)$v : ''), array_keys($answer), $answer));
			}
			if (is_bool($answer)) {
				return $answer ? 'true' : 'false';
			}
			return is_scalar($answer) ? (string)$answer : $match[0];
		}, $text);
	}

	private function optionLabel(array $question, mixed $value): string {
		foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
			if (is_array($option) && (string)($option['value'] ?? $option['id'] ?? '') === (string)(is_scalar($value) ? $value : '')) {
				return (string)($option['label'] ?? $value);
			}
		}
		return is_scalar($value) ? (string)$value : '';
	}

	private function matrix(array $question, mixed $answer): string {
		if (!is_array($answer)) {
			return $this->words(is_scalar($answer) ? (string)$answer : '', true);
		}
		$columns = [];
		foreach (is_array($question['columns'] ?? null) ? $question['columns'] : [] as $column) {
			if (is_array($column)) {
				$columns[(string)($column['value'] ?? $column['id'] ?? '')] = (string)($column['label'] ?? $column['value'] ?? '');
			}
		}
		$rows = '';
		foreach (is_array($question['rows'] ?? null) ? $question['rows'] : [] as $row) {
			if (!is_array($row) || !array_key_exists((string)($row['id'] ?? ''), $answer)) {
				continue;
			}
			if (!$this->spend('cells', 2)) {
				$this->shortened = true;
				break;
			}
			$value = $answer[(string)$row['id']];
			$label = $row['label'] ?? $row['id'];
			$shown = $columns[(string)(is_scalar($value) ? $value : '')] ?? $value;
			$rows .= '<tr><td>' . $this->cell(is_scalar($label) ? (string)$label : '', 2) . '</td><td>' . $this->cell(is_scalar($shown) ? (string)$shown : '', 2) . '</td></tr>';
		}
		return '<table>' . $rows . '</table>';
	}

	private function table(array $question, array $answer): string {
		$columns = [];
		foreach (is_array($question['columns'] ?? null) ? $question['columns'] : [] as $column) {
			if (is_array($column) && is_scalar($column['id'] ?? null)) {
				$columns[(string)$column['id']] = (string)($column['label'] ?? $column['id']);
			}
		}
		$width = max(1, count($columns));
		if (!$this->spend('cells', $width)) {
			$this->shortened = true;
			return '';
		}
		$head = '';
		foreach ($columns as $label) {
			$head .= '<th>' . $this->cell($label, $width) . '</th>';
		}
		$rows = '';
		foreach ($answer as $row) {
			if (!$this->spend('cells', $width)) {
				$this->shortened = true;
				break;
			}
			$rows .= '<tr>';
			foreach (array_keys($columns) as $id) {
				$cell = is_array($row) ? ($row[$id] ?? '') : '';
				$rows .= '<td>' . $this->cell(is_scalar($cell) ? (string)$cell : '', $width) . '</td>';
			}
			$rows .= '</tr>';
		}
		return '<table><tr>' . $head . '</tr>' . $rows . '</table>';
	}

	/** @param list<string> $items */
	private function list(array $items): string {
		$html = '';
		foreach ($items as $item) {
			if (!$this->spend('cells', 1)) {
				$this->shortened = true;
				break;
			}
			$html .= '<li>' . $this->words($item, true) . '</li>';
		}
		return '<ul>' . $html . '</ul>';
	}

	/** Read and shown in one zone, so the day never shifts; what is no real day is shown as it was given. */
	private function date(string $value, IL10N $l, \DateTimeZone $tz): string {
		$date = AnswerDate::isDate($value) ? \DateTime::createFromFormat('!Y-m-d', $value, $tz) : false;
		return $date === false ? $value : $this->formatter->formatDate($date, 'long', $tz, $l);
	}

	private function dateTime(string $value, IL10N $l, \DateTimeZone $tz): string {
		return AnswerDate::isDateTime($value) ? $this->moment($value, $l, $tz) : $value;
	}

	/** Also a moment the server or an import set, which PHP reads in more forms than an answer may take. */
	private function moment(string $value, IL10N $l, \DateTimeZone $tz): string {
		if ($value === '') {
			return '';
		}
		try {
			// A value without an offset (API) is in the respondent's zone.
			return $this->formatter->formatDateTime(new \DateTime($value, $tz), 'long', 'short', $tz, $l);
		} catch (\Exception) {
			return $value;
		}
	}

	private function zoneOf(array $response): \DateTimeZone {
		$zone = TimeZones::name($response['timezone'] ?? null);
		return $zone !== null ? new \DateTimeZone($zone) : $this->timeZone->getDefaultTimeZone();
	}

	private static function text(mixed $value): string {
		return htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
