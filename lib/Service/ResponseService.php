<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\IGroupManager;
use OCA\FormVox\AppInfo\Application;
use Psr\Log\LoggerInterface;

class ResponseService
{
    private FormService $formService;
    private IndexService $indexService;
    private WebhookService $webhookService;
    private INotificationManager $notificationManager;
    private IGroupManager $groupManager;
    private IMailer $mailer;
    private IURLGenerator $urlGenerator;
    private IL10N $l;
    private LoggerInterface $logger;

    public function __construct(
        FormService $formService,
        IndexService $indexService,
        WebhookService $webhookService,
        INotificationManager $notificationManager,
        IGroupManager $groupManager,
        IMailer $mailer,
        IURLGenerator $urlGenerator,
        IL10N $l,
        LoggerInterface $logger
    ) {
        $this->formService = $formService;
        $this->indexService = $indexService;
        $this->webhookService = $webhookService;
        $this->notificationManager = $notificationManager;
        $this->groupManager = $groupManager;
        $this->mailer = $mailer;
        $this->urlGenerator = $urlGenerator;
        $this->l = $l;
        $this->logger = $logger;
    }

    /**
     * Submit a response as an anonymous user
     */
    public function submitAnonymous(int $fileId, array $answers, IRequest $request, string $shareToken): array
    {
        $form = $this->formService->load($fileId);
        return $this->submitAnonymousWithForm($fileId, $form, $answers, $request, $shareToken);
    }

    /**
     * Submit a response as an anonymous user (with form already loaded)
     */
    public function submitAnonymousWithForm(int $fileId, array $form, array $answers, IRequest $request, string $shareToken): array
    {
        // Check if form accepts responses
        $this->validateFormAcceptsResponses($form);

        // Calculate fingerprint
        $fingerprint = $this->calculateFingerprint($request, $shareToken);

        // Check for duplicate submission
        if (!($form['settings']['allow_multiple'] ?? false)) {
            if ($this->indexService->hasFingerprint($form, $fingerprint)) {
                throw new \RuntimeException('You have already submitted a response to this form');
            }
        }

        // Validate answers
        $this->validateAnswers($form, $answers);

        // Create response
        $response = [
            'id' => $this->generateUuid(),
            'submitted_at' => date('c'),
            'respondent' => [
                'type' => 'anonymous',
                'fingerprint' => $fingerprint,
            ],
            'answers' => $answers,
        ];

        // Calculate score if quiz mode
        if ($this->isQuizMode($form)) {
            $response['score'] = $this->calculateScore($form, $answers);
        }

        // Re-check limits under the write lock against the fresh form state, so
        // parallel submissions can't each pass the pre-lock snapshot check and
        // oversell capacity / exceed max_responses / bypass no-duplicate (#4).
        $guard = function (array $freshForm) use ($fingerprint, $answers) {
            $this->validateFormAcceptsResponses($freshForm);
            if (!($freshForm['settings']['allow_multiple'] ?? false)) {
                if ($this->indexService->hasFingerprint($freshForm, $fingerprint)) {
                    throw new \RuntimeException('You have already submitted a response to this form');
                }
            }
            $this->validateCapacity($freshForm, $answers);
        };

        // Append response (use public method since no user is logged in)
        $result = $this->formService->appendResponsePublic($fileId, $response, $guard);

        // Trigger webhook
        $this->webhookService->trigger($form, 'response.created', $response);

        // Send notification to form owner
        $this->notifyFormOwner($fileId, $form, $response);

        // Confirmation email to respondent (best-effort, #103)
        $this->sendConfirmationEmail($form, $answers);

        return $result;
    }

    /**
     * Submit a response as an authenticated user
     */
    public function submitAuthenticated(int $fileId, array $answers, string $userId, string $displayName): array
    {
        // Use the admin-bypass loader: respondents are authenticated for
        // identity (the response is attributed to $userId) but they don't
        // need file/folder permissions on the form file itself. The share
        // link + token already validated their right to submit. (#77)
        $form = $this->formService->loadPublic($fileId);

        // Check if form accepts responses
        $this->validateFormAcceptsResponses($form);

        // Check for duplicate submission
        if (!($form['settings']['allow_multiple'] ?? false)) {
            if ($this->indexService->hasUserResponse($form, $userId)) {
                throw new \RuntimeException('You have already submitted a response to this form');
            }
        }

        // Validate answers
        $this->validateAnswers($form, $answers);

        // Create response
        $response = [
            'id' => $this->generateUuid(),
            'submitted_at' => date('c'),
            'respondent' => [
                'type' => 'user',
                'user_id' => $userId,
                'display_name' => $displayName,
            ],
            'answers' => $answers,
        ];

        // Calculate score if quiz mode
        if ($this->isQuizMode($form)) {
            $response['score'] = $this->calculateScore($form, $answers);
        }

        // Re-check limits under the write lock against the fresh form state (#4).
        $guard = function (array $freshForm) use ($userId, $answers) {
            $this->validateFormAcceptsResponses($freshForm);
            if (!($freshForm['settings']['allow_multiple'] ?? false)) {
                if ($this->indexService->hasUserResponse($freshForm, $userId)) {
                    throw new \RuntimeException('You have already submitted a response to this form');
                }
            }
            $this->validateCapacity($freshForm, $answers);
        };

        // Append response (use public method so respondent doesn't need file permissions)
        $result = $this->formService->appendResponsePublic($fileId, $response, $guard);

        // Trigger webhook
        $this->webhookService->trigger($form, 'response.created', $response);

        // Send notification to form owner
        $this->notifyFormOwner($fileId, $form, $response);

        // Confirmation email to respondent (best-effort, #103)
        $this->sendConfirmationEmail($form, $answers);

        return $result;
    }

    /**
     * Send a plain-text confirmation email to the respondent (best-effort).
     *
     * Conditions: form has `settings.sendConfirmationEmail` true, exactly
     * one question is flagged `useAsRespondentEmail` (and has validation
     * type email), the respondent filled in a syntactically valid email.
     * All failures are logged and swallowed — never break the submit (#103).
     */
    private function sendConfirmationEmail(array $form, array $answers): void
    {
        try {
            if (empty($form['settings']['sendConfirmationEmail'])) {
                return;
            }
            // Find the question flagged as respondent email
            $emailField = null;
            foreach ($form['questions'] ?? [] as $q) {
                if (!empty($q['useAsRespondentEmail']) && in_array($q['type'] ?? '', ['text', 'textarea'], true)) {
                    $emailField = $q;
                    break;
                }
            }
            if ($emailField === null) {
                return;
            }
            $to = trim((string)($answers[$emailField['id']] ?? ''));
            if ($to === '' || !$this->mailer->validateMailAddress($to)) {
                return;
            }

            $formTitle = $form['title'] ?? $this->l->t('Form');
            $subject = trim((string)($form['settings']['confirmationEmailSubject'] ?? ''));
            if ($subject === '') {
                $subject = $this->l->t('Confirmation: %s', [$formTitle]);
            }
            $body = trim((string)($form['settings']['confirmationEmailBody'] ?? ''));
            if ($body === '') {
                $body = $this->l->t(
                    "Thank you for filling in \"%s\".\n\nWe have received your response.",
                    [$formTitle]
                );
            }

            $message = $this->mailer->createMessage();
            $message->setTo([$to]);
            $message->setSubject($subject);
            $message->setPlainBody($body);

            $failed = $this->mailer->send($message);
            if (!empty($failed)) {
                $this->logger->warning('FormVox confirmation email partly failed', ['failed' => $failed]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('FormVox confirmation email failed: ' . $e->getMessage());
        }
    }

    /**
     * Send a Nextcloud notification to the form owner
     */
    private function notifyFormOwner(int $fileId, array $form, array $response): void
    {
        try {
            $respondentId = ($response['respondent']['type'] ?? '') === 'user'
                ? ($response['respondent']['user_id'] ?? '')
                : '';

            $respondentName = ($response['respondent']['type'] ?? '') === 'user'
                ? ($response['respondent']['display_name'] ?? 'Unknown')
                : 'Anonymous';

            // Collect all user IDs to notify
            $recipientIds = [];

            // 1. Form owner (if notify_owner is enabled)
            if (($form['settings']['notify_owner'] ?? true) !== false) {
                $file = $this->formService->getFileByIdPublic($fileId);
                $owner = $file->getOwner();
                if ($owner !== null) {
                    $recipientIds[] = $owner->getUID();
                }
            }

            // 2. Additional recipients from settings
            foreach ($form['settings']['notify_recipients'] ?? [] as $recipient) {
                if (($recipient['type'] ?? '') === 'user') {
                    $recipientIds[] = $recipient['id'];
                } elseif (($recipient['type'] ?? '') === 'group') {
                    $group = $this->groupManager->get($recipient['id']);
                    if ($group !== null) {
                        foreach ($group->getUsers() as $user) {
                            $recipientIds[] = $user->getUID();
                        }
                    }
                }
            }

            // Deduplicate and exclude the respondent
            $recipientIds = array_unique($recipientIds);
            $recipientIds = array_filter($recipientIds, fn($id) => $id !== $respondentId);

            // Send notifications
            foreach ($recipientIds as $userId) {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp(Application::APP_ID)
                    ->setUser($userId)
                    ->setDateTime(new \DateTime())
                    ->setObject('response', $response['id'])
                    ->setSubject('response_submitted', [
                        'formTitle' => $form['title'] ?? 'Untitled',
                        'respondentName' => $respondentName,
                        'fileId' => $fileId,
                    ]);

                $this->notificationManager->notify($notification);
            }
        } catch (\Exception $e) {
            // Don't let notification failures break form submission
        }
    }

    /**
     * Get summary statistics for a form
     */
    public function getSummary(int $fileId): array
    {
        $form = $this->formService->load($fileId);
        return $this->buildSummary($form);
    }

    /**
     * Get summary statistics for a form (public access)
     */
    public function getSummaryPublic(int $fileId): array
    {
        $form = $this->formService->loadPublic($fileId);
        return $this->buildSummary($form);
    }

    /**
     * Build summary from form data
     */
    private function buildSummary(array $form): array
    {

        $summary = [
            'responseCount' => $this->indexService->getResponseCount($form),
            'lastResponseAt' => $form['_index']['last_response_at'] ?? null,
            'questions' => [],
        ];

        // Sections are UI grouping containers, not questions — exclude them
        // so they don't show up as empty "questions" in the results summary.
        $questions = array_values(array_filter(
            $form['questions'] ?? [],
            fn($q) => !in_array($q['type'] ?? '', ['section', 'descriptor'], true),
        ));

        foreach ($questions as $question) {
            $questionId = $question['id'];
            $questionSummary = [
                'id' => $questionId,
                'type' => $question['type'],
                'question' => $question['question'],
                'answerCounts' => $this->indexService->getAnswerStats($form, $questionId),
            ];

            // Preserve options/rows/columns so the frontend can map stored
            // values (often internal ids) back to human-readable labels.
            if (in_array($question['type'], ['choice', 'multiple', 'dropdown']) && isset($question['options'])) {
                $questionSummary['options'] = $question['options'];
            }
            if ($question['type'] === 'matrix') {
                if (isset($question['rows'])) $questionSummary['rows'] = $question['rows'];
                if (isset($question['columns'])) $questionSummary['columns'] = $question['columns'];
            }

            // For numeric types, calculate average
            if (in_array($question['type'], ['number', 'scale', 'rating'])) {
                $stats = $this->calculateNumericStats($form, $questionId);
                $questionSummary['average'] = $stats['average'];
                $questionSummary['min'] = $stats['min'];
                $questionSummary['max'] = $stats['max'];
            }

            $summary['questions'][] = $questionSummary;
        }

        return $summary;
    }

    /**
     * Get all responses for a form
     */
    public function getResponses(int $fileId, ?string $dateFilter = null): array
    {
        $form = $this->formService->load($fileId);

        if ($dateFilter !== null) {
            return $this->indexService->getResponsesByDate($form, $dateFilter);
        }

        return $form['responses'] ?? [];
    }

    /**
     * Neutralise CSV formula injection. A respondent (including anonymous) can
     * submit an answer beginning with =, +, -, @, or a tab/CR; when the form
     * owner opens the export in Excel/LibreOffice such a cell is evaluated as a
     * formula (DDE command execution, HYPERLINK/WEBSERVICE data exfiltration).
     * Prefixing with a single quote forces the cell to be treated as text.
     */
    private function sanitizeCsvCell($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Build the shared export table (header + data rows) used by both the CSV
     * and XLSX exporters, so the two formats stay identical in content.
     *
     * Every string cell is run through sanitizeCsvCell() — formula injection
     * applies to XLSX just as much as CSV. Returns ['headers' => string[],
     * 'rows' => array<array<string>>], or null when there are no responses.
     */
    private function buildExportData(int $fileId): ?array
    {
        $form = $this->formService->load($fileId);
        $responses = $form['responses'] ?? [];

        if (empty($responses)) {
            return null;
        }

        // Sections are UI grouping containers, not questions — exclude them
        // so they don't appear as empty columns next to the answer columns.
        $questions = array_values(array_filter(
            $form['questions'] ?? [],
            fn($q) => !in_array($q['type'] ?? '', ['section', 'descriptor'], true),
        ));

        // Header row (question titles are sanitised too — a title beginning
        // with a formula char would otherwise inject on the owner's export).
        $headers = ['Response ID', 'Submitted At', 'Respondent Type', 'Respondent ID'];
        foreach ($questions as $question) {
            $headers[] = $this->sanitizeCsvCell($question['question'] ?? '');
        }

        $rows = [];
        foreach ($responses as $response) {
            $row = [
                $response['id'],
                $response['submitted_at'],
                $response['respondent']['type'],
                $response['respondent']['user_id'] ?? $response['respondent']['fingerprint'] ?? '',
            ];

            foreach ($questions as $question) {
                $answer = $response['answers'][$question['id']] ?? '';

                // Consent: render boolean as Yes/No so it is human-readable (#94).
                if (($question['type'] ?? '') === 'consent') {
                    $answer = $answer === true ? 'Yes' : ($answer === false ? 'No' : '');
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
                        if (!is_array($rowObj)) continue;
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

            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Export responses to CSV format
     */
    public function exportCsv(int $fileId): string
    {
        $data = $this->buildExportData($fileId);
        if ($data === null) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        // Semicolon separator + BOM (no sep= directive) — see the return
        // statement below for why. eol: "\r\n" matches Excel's record
        // separator and the \r\n we normalise within cells (#83).
        fputcsv($output, $data['headers'], separator: ';', eol: "\r\n");

        foreach ($data['rows'] as $row) {
            // Normalise embedded newlines so Excel renders them as in-cell
            // line breaks instead of new rows (cells are already sanitised).
            $row = array_map(function ($v) {
                if (!is_string($v)) return $v;
                $v = str_replace(["\r\n", "\r"], ["\n", "\n"], $v);
                return str_replace("\n", "\r\n", $v);
            }, $row);
            fputcsv($output, $row, separator: ';', eol: "\r\n");
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
    public function exportXlsx(int $fileId): string
    {
        $data = $this->buildExportData($fileId);
        if ($data === null) {
            return '';
        }

        // Build the single worksheet: header row + data rows, inline strings.
        $allRows = array_merge([$data['headers']], $data['rows']);
        $sheetRows = '';
        foreach ($allRows as $r => $row) {
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
            $sheetRows .= '<row r="' . $rowNum . '">' . $cells . '</row>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetRows . '</sheetData></worksheet>';

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
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content === false ? '' : $content;
    }

    /**
     * Convert a 0-based column index to an Excel column letter (0→A, 26→AA).
     */
    private function xlsxColumnLetter(int $index): string
    {
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
    private function xmlEscape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Export responses to JSON format
     */
    public function exportJson(int $fileId): string
    {
        $form = $this->formService->load($fileId);

        return json_encode([
            'title' => $form['title'],
            'exportedAt' => date('c'),
            'questions' => $form['questions'],
            'responses' => $form['responses'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Validate that the form accepts responses
     */
    private function validateFormAcceptsResponses(array $form): void
    {
        $settings = $form['settings'] ?? [];

        // Check expiration
        if (!empty($settings['expires_at'])) {
            $expiresAt = new \DateTime($settings['expires_at']);
            if ($expiresAt < new \DateTime()) {
                throw new \RuntimeException('This form has expired');
            }
        }

        // Check response limit
        $maxResponses = $settings['max_responses'] ?? 0;
        if ($maxResponses > 0) {
            $currentCount = $this->indexService->getResponseCount($form);
            if ($currentCount >= $maxResponses) {
                $limitMessage = $settings['limit_message'] ?? '';
                if (empty($limitMessage)) {
                    $limitMessage = 'This form has reached its response limit';
                }
                throw new \RuntimeException($limitMessage);
            }
        }
    }

    /**
     * Validate answers against form questions
     */
    private function validateAnswers(array $form, array $answers): void
    {
        $questionIds = array_column($form['questions'] ?? [], 'id');
        $questionsById = [];
        foreach ($form['questions'] ?? [] as $question) {
            $questionsById[$question['id']] = $question;
        }

        // Check required questions
        foreach ($form['questions'] ?? [] as $question) {
            $questionId = $question['id'];

            // Skip non-answerable UI items (section headers, info blocks).
            // They never store an answer so a stray required flag should
            // not reject the submit (#64).
            if (in_array($question['type'] ?? '', ['section', 'descriptor'], true)) {
                continue;
            }

            // Skip if question is conditionally hidden
            if ($this->isQuestionHidden($question, $answers, $questionsById)) {
                continue;
            }

            if ($question['required'] ?? false) {
                $a = $answers[$questionId] ?? null;
                $missing = $a === null || $a === '' || $a === [];
                // Consent: only an explicit `true` counts as answered (#94).
                if (($question['type'] ?? '') === 'consent') {
                    $missing = $a !== true;
                }
                if ($missing) {
                    throw new \RuntimeException("Question '{$question['question']}' is required");
                }
            }
        }

        // Validate answer types
        foreach ($answers as $questionId => $answer) {
            if (!in_array($questionId, $questionIds)) {
                continue; // Skip unknown questions
            }

            $question = $questionsById[$questionId];
            $this->validateAnswerType($question, $answer);
        }

        // Capacity limits per option (#104)
        $this->validateCapacity($form, $answers);
    }

    /**
     * Reject submission if any selected option has reached its capacity.
     * Counts come from the existing _index.answer_counts aggregator.
     */
    private function validateCapacity(array $form, array $answers): void
    {
        $counts = $form['_index']['answer_counts'] ?? [];
        foreach ($form['questions'] ?? [] as $question) {
            if (!in_array($question['type'] ?? '', ['choice', 'multiple', 'dropdown'], true)) {
                continue;
            }
            $qid = $question['id'];
            $selected = $answers[$qid] ?? null;
            if ($selected === null || $selected === '' || $selected === []) {
                continue;
            }
            $selectedValues = is_array($selected) ? $selected : [$selected];
            foreach ($question['options'] ?? [] as $option) {
                $capacity = $option['capacity'] ?? null;
                if ($capacity === null || $capacity === '' || (int)$capacity <= 0) {
                    continue; // unlimited
                }
                $optValue = $option['value'] ?? $option['id'] ?? null;
                if ($optValue === null || !in_array($optValue, $selectedValues, true)) {
                    continue;
                }
                $used = (int)($counts[$qid][$optValue] ?? 0);
                if ($used >= (int)$capacity) {
                    $label = $option['label'] ?? $optValue;
                    throw new \RuntimeException("The option '{$label}' is no longer available — capacity reached");
                }
            }
        }
    }

    /**
     * Validate answer matches question type
     */
    private function validateAnswerType(array $question, $answer): void
    {
        $type = $question['type'];

        switch ($type) {
            case 'number':
            case 'scale':
            case 'rating':
                if ($answer !== '' && !is_numeric($answer)) {
                    throw new \RuntimeException("Invalid answer type for question '{$question['question']}'");
                }
                break;

            case 'multiple':
                if (!is_array($answer)) {
                    throw new \RuntimeException("Multiple choice question requires array answer");
                }
                // Min/max selection limits (#113). Empty answers are handled by
                // the required-check; only enforce bounds on a non-empty answer.
                $count = count($answer);
                $min = (int)($question['minSelections'] ?? 0);
                $max = (int)($question['maxSelections'] ?? 0);
                if ($count > 0 && $min > 0 && $count < $min) {
                    throw new \RuntimeException("Select at least {$min} option(s) for question '{$question['question']}'");
                }
                if ($max > 0 && $count > $max) {
                    throw new \RuntimeException("Select at most {$max} option(s) for question '{$question['question']}'");
                }
                break;

            case 'textarea':
            case 'text':
                // Character limit (#113). mb_strlen counts characters, so a
                // multi-byte umlaut counts as one, matching the browser counter.
                $maxLength = (int)($question['maxLength'] ?? 0);
                if ($maxLength > 0 && is_string($answer) && mb_strlen($answer) > $maxLength) {
                    throw new \RuntimeException("Answer for question '{$question['question']}' exceeds the {$maxLength}-character limit");
                }
                break;

            case 'consent':
                // Accept boolean true/false. The required-check enforces
                // that 'true' is mandatory when the question is required.
                if (!is_bool($answer) && $answer !== '' && $answer !== null) {
                    throw new \RuntimeException("Consent question requires boolean answer");
                }
                break;

            case 'date':
                if ($answer !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $answer)) {
                    throw new \RuntimeException("Invalid date format for question '{$question['question']}'");
                }
                break;

            case 'datetime':
                if ($answer !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $answer)) {
                    throw new \RuntimeException("Invalid datetime format for question '{$question['question']}'");
                }
                break;

            case 'time':
                if ($answer !== '' && !preg_match('/^\d{2}:\d{2}$/', $answer)) {
                    throw new \RuntimeException("Invalid time format for question '{$question['question']}'");
                }
                break;
        }

        // Custom pattern validation (for text-based fields)
        if (!empty($question['validation']['pattern'])) {
            $pattern = $question['validation']['pattern'];
            // Only validate non-empty answers
            if ($answer !== '' && $answer !== null) {
                // Escape delimiter and validate the pattern
                $escapedPattern = '/' . str_replace('/', '\/', $pattern) . '/';
                if (@preg_match($escapedPattern, '') === false) {
                    // Invalid regex pattern - log and skip validation
                    return;
                }
                if (!preg_match($escapedPattern, (string)$answer)) {
                    $errorMsg = $question['validation']['errorMessage']
                        ?? "Answer does not match the required format for question '{$question['question']}'";
                    throw new \RuntimeException($errorMsg);
                }
            }
        }

        // Date/Time range validation (only for non-empty answers)
        if ($answer !== '' && $answer !== null) {
            if (in_array($type, ['date', 'datetime'])) {
                if (!empty($question['dateMin']) && $answer < $question['dateMin']) {
                    throw new \RuntimeException("Date is before the allowed minimum for question '{$question['question']}'");
                }
                if (!empty($question['dateMax']) && $answer > $question['dateMax']) {
                    throw new \RuntimeException("Date is after the allowed maximum for question '{$question['question']}'");
                }
            }
            if ($type === 'time') {
                if (!empty($question['timeMin']) && $answer < $question['timeMin']) {
                    throw new \RuntimeException("Time is before the allowed minimum for question '{$question['question']}'");
                }
                if (!empty($question['timeMax']) && $answer > $question['timeMax']) {
                    throw new \RuntimeException("Time is after the allowed maximum for question '{$question['question']}'");
                }
            }
        }
    }

    /**
     * Check if a question is hidden due to conditional logic
     */
    private function isQuestionHidden(array $question, array $answers, array $questionsById): bool
    {
        // Question is hidden if its parent section is hidden (#92). A
        // required question inside a section whose showIf evaluates false
        // must not be enforced — the respondent never saw the field.
        if (!empty($question['sectionId']) && isset($questionsById[$question['sectionId']])) {
            $section = $questionsById[$question['sectionId']];
            if (isset($section['showIf']) && !$this->evaluateCondition($section['showIf'], $answers, $questionsById)) {
                return true;
            }
        }

        if (!isset($question['showIf'])) {
            return false;
        }

        return !$this->evaluateCondition($question['showIf'], $answers, $questionsById);
    }

    /**
     * Evaluate a conditional expression
     */
    /**
     * Build a list of values to match against for a choice/multiple/dropdown
     * question. The answer is stored as option.value (e.g. "optdca45095")
     * but historically the routing/showIf editor saved option.label (e.g.
     * "Ja"). Accept either form so existing forms with label-based rules
     * keep working alongside new value-based ones. (#99)
     *
     * @return list<mixed>
     */
    private function choiceCompareCandidates(string $questionId, $compareValue, array $questionsById): array
    {
        $question = $questionsById[$questionId] ?? null;
        if ($question === null || !in_array($question['type'] ?? '', ['choice', 'multiple', 'dropdown'], true)) {
            return [$compareValue];
        }
        $opts = $question['options'] ?? [];
        foreach ($opts as $o) {
            $v = $o['value'] ?? $o['id'] ?? null;
            $label = $o['label'] ?? null;
            if ($label === $compareValue || $v === $compareValue) {
                $out = [];
                if ($v !== null) $out[] = $v;
                if ($label !== null && $label !== $v) $out[] = $label;
                return $out;
            }
        }
        return [$compareValue];
    }

    private function evaluateCondition(array $condition, array $answers, array $questionsById = []): bool
    {
        // Combined conditions (AND/OR)
        if (isset($condition['operator']) && isset($condition['conditions'])) {
            $op = strtolower($condition['operator']);
            $results = array_map(
                fn($c) => $this->evaluateCondition($c, $answers, $questionsById),
                $condition['conditions']
            );

            if ($op === 'and') {
                return !in_array(false, $results, true);
            } elseif ($op === 'or') {
                return in_array(true, $results, true);
            }
            return false;
        }

        // Simple condition
        if (isset($condition['questionId'], $condition['operator'])) {
            $questionId = $condition['questionId'];
            $operator = $condition['operator'];
            $value = $condition['value'] ?? null;
            $answer = $answers[$questionId] ?? null;
            $isArrayAnswer = is_array($answer);
            $candidates = $this->choiceCompareCandidates($questionId, $value, $questionsById);

            switch ($operator) {
                case 'equals':
                    if ($isArrayAnswer) {
                        foreach ($candidates as $c) if (in_array($c, $answer, true)) return true;
                        return false;
                    }
                    return in_array($answer, $candidates, true);
                case 'notEquals':
                    if ($isArrayAnswer) {
                        foreach ($candidates as $c) if (in_array($c, $answer, true)) return false;
                        return true;
                    }
                    return !in_array($answer, $candidates, true);
                case 'contains':
                    if ($isArrayAnswer) {
                        foreach ($candidates as $c) if (in_array($c, $answer, true)) return true;
                        return false;
                    }
                    if (!is_string($answer)) return false;
                    foreach ($candidates as $c) if (str_contains($answer, (string)$c)) return true;
                    return false;
                case 'notContains':
                    if ($isArrayAnswer) {
                        foreach ($candidates as $c) if (in_array($c, $answer, true)) return false;
                        return true;
                    }
                    if (!is_string($answer)) return true;
                    foreach ($candidates as $c) if (str_contains($answer, (string)$c)) return false;
                    return true;
                case 'isEmpty':
                    return $answer === null || $answer === '' || $answer === [];
                case 'isNotEmpty':
                    return $answer !== null && $answer !== '' && $answer !== [];
                case 'greaterThan': {
                    $a = $isArrayAnswer ? ($answer[0] ?? null) : $answer;
                    return is_numeric($a) && $a > $value;
                }
                case 'lessThan': {
                    $a = $isArrayAnswer ? ($answer[0] ?? null) : $answer;
                    return is_numeric($a) && $a < $value;
                }
                case 'in':
                    if (!is_array($value)) return false;
                    if ($isArrayAnswer) {
                        foreach ($answer as $a) if (in_array($a, $value, true)) return true;
                        return false;
                    }
                    return in_array($answer, $value, true);
                case 'notIn':
                    if (!is_array($value)) return true;
                    if ($isArrayAnswer) {
                        foreach ($answer as $a) if (in_array($a, $value, true)) return false;
                        return true;
                    }
                    return !in_array($answer, $value, true);
            }
        }

        // Unknown / unset operator → don't match. Returning true used to
        // silently fire showIf and page-routing rules when a rule was
        // half-configured (#99).
        return false;
    }

    /**
     * Calculate fingerprint for anonymous users
     */
    private function calculateFingerprint(IRequest $request, string $shareToken): string
    {
        $data = implode('|', [
            $request->getRemoteAddress(),
            $request->getHeader('User-Agent'),
            $shareToken,
        ]);

        return 'sha256:' . hash('sha256', $data);
    }

    /**
     * Check if form is in quiz mode
     */
    private function isQuizMode(array $form): bool
    {
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
    private function calculateScore(array $form, array $answers): array
    {
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
     * Calculate numeric statistics for a question
     */
    private function calculateNumericStats(array $form, string $questionId): array
    {
        $values = [];

        foreach ($form['responses'] ?? [] as $response) {
            $answer = $response['answers'][$questionId] ?? null;
            if ($answer !== null && is_numeric($answer)) {
                $values[] = (float)$answer;
            }
        }

        if (empty($values)) {
            return ['average' => null, 'min' => null, 'max' => null];
        }

        return [
            'average' => round(array_sum($values) / count($values), 2),
            'min' => min($values),
            'max' => max($values),
        ];
    }

    /**
     * Generate a UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
