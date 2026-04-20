<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\TaskProcessing\IManager as ITaskManager;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

class AiFormGeneratorService
{
    private const TASK_TYPES = ['core:text2text', 'core:text2text:chat'];
    private const MAX_WAIT_SECONDS = 120;
    private const MAX_DOC_CHARS = 12000;
    private const MAX_DOC_BYTES = 8 * 1024 * 1024; // 8 MB hard cap on source document size

    private const ASSISTANT_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
    ];

    private ITaskManager $taskManager;
    private IRootFolder $rootFolder;
    private IConfig $config;
    private LoggerInterface $logger;

    public function __construct(ITaskManager $taskManager, IRootFolder $rootFolder, IConfig $config, LoggerInterface $logger)
    {
        $this->taskManager = $taskManager;
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Whether the AI form generator is available to end users — requires both
     * an installed TextToText provider AND the admin toggle to be on.
     */
    public function isAvailable(): bool
    {
        return $this->isAdminEnabled() && $this->isProviderAvailable();
    }

    /**
     * Whether a TextToText AI provider is installed. Used by the admin panel.
     */
    public function isProviderAvailable(): bool
    {
        try {
            $available = $this->taskManager->getAvailableTaskTypes();
            foreach (self::TASK_TYPES as $type) {
                if (isset($available[$type])) {
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Returns the first matching task type, or null if none available.
     */
    public function resolvedTaskTypeOrNull(): ?string
    {
        try {
            $available = $this->taskManager->getAvailableTaskTypes();
            foreach (self::TASK_TYPES as $type) {
                if (isset($available[$type])) {
                    return $type;
                }
            }
        } catch (\Exception $e) {
            // fall through
        }
        return null;
    }

    /**
     * Whether the admin has enabled AI (default-on when a provider exists the
     * first time the admin loads the settings panel).
     */
    public function isAdminEnabled(): bool
    {
        $raw = $this->config->getAppValue(Application::APP_ID, 'ai_enabled', '__unset__');
        if ($raw === '__unset__') {
            return $this->isProviderAvailable();
        }
        return $raw === '1';
    }

    public function isSourceUploadAllowed(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'ai_allow_source_upload', '1') === '1';
    }

    public function isConditionalLogicAllowed(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'ai_allow_conditional', '1') === '1';
    }

    public function getMaxQuestions(): int
    {
        return max(3, min(20, (int)$this->config->getAppValue(Application::APP_ID, 'ai_max_questions', '12')));
    }

    public function getMaxDocBytes(): int
    {
        $mb = max(1, min(25, (int)$this->config->getAppValue(Application::APP_ID, 'ai_max_doc_size_mb', '8')));
        return $mb * 1024 * 1024;
    }

    /**
     * Schedule an AI form-generation task and return the task id immediately.
     * This is the async pattern used by nextcloud/assistant — the caller polls
     * the task status separately and a TaskSuccessfulEvent listener materialises
     * the form when the task finishes.
     *
     * @throws \RuntimeException if the AI is unavailable.
     */
    public function scheduleGeneration(string $description, string $userId, ?int $sourceFileId = null): int
    {
        $taskType = $this->resolveTaskType();

        $sourceText = '';
        if ($sourceFileId !== null && $sourceFileId > 0) {
            if (!$this->isSourceUploadAllowed()) {
                throw new \RuntimeException('Source document uploads are disabled by the administrator.');
            }
            $sourceText = $this->extractTextFromFileId($sourceFileId, $userId);
        }

        if (trim($description) === '' && trim($sourceText) === '') {
            throw new \RuntimeException('Provide a description, an uploaded document, or both.');
        }

        $prompt = $this->buildPrompt($description, $sourceText);

        $task = new Task(
            $taskType,
            ['input' => $prompt],
            'formvox',
            $userId,
        );
        $this->taskManager->scheduleTask($task);
        $taskId = $task->getId();
        if ($taskId === null) {
            throw new \RuntimeException('Failed to schedule AI task.');
        }
        return $taskId;
    }

    /**
     * Synchronous variant — kept for callers that want a blocking result. The
     * async/scheduled flow is preferred (see scheduleGeneration + listener).
     *
     * @throws \RuntimeException if the AI is unavailable, fails, or returns unparseable output.
     */
    public function generateForm(string $description, string $userId, ?int $sourceFileId = null): array
    {
        $taskType = $this->resolveTaskType();

        $sourceText = '';
        if ($sourceFileId !== null && $sourceFileId > 0) {
            if (!$this->isSourceUploadAllowed()) {
                throw new \RuntimeException('Source document uploads are disabled by the administrator.');
            }
            $sourceText = $this->extractTextFromFileId($sourceFileId, $userId);
        }

        if (trim($description) === '' && trim($sourceText) === '') {
            throw new \RuntimeException('Provide a description, an uploaded document, or both.');
        }

        $prompt = $this->buildPrompt($description, $sourceText);

        $task = new Task(
            $taskType,
            ['input' => $prompt],
            'formvox',
            $userId,
        );

        $this->taskManager->scheduleTask($task);
        $taskId = $task->getId();

        $waited = 0;
        $pollInterval = 1;
        while ($waited < self::MAX_WAIT_SECONDS) {
            sleep($pollInterval);
            $waited += $pollInterval;

            $task = $this->taskManager->getTask($taskId);
            $status = $task->getStatus();

            if ($status === Task::STATUS_SUCCESSFUL) {
                break;
            }
            if ($status === Task::STATUS_FAILED || $status === Task::STATUS_CANCELLED) {
                $err = method_exists($task, 'getErrorMessage') ? $task->getErrorMessage() : null;
                throw new \RuntimeException('AI task failed: ' . ($err ?? 'Unknown error'));
            }
            if ($waited > 5) {
                $pollInterval = 2;
            }
        }

        if ($task->getStatus() !== Task::STATUS_SUCCESSFUL) {
            throw new \RuntimeException('AI task timed out after ' . self::MAX_WAIT_SECONDS . ' seconds');
        }

        $output = $task->getOutput();
        $raw = (string)($output['output'] ?? '');

        return $this->parseAiResponse($raw);
    }

    private function resolveTaskType(): string
    {
        $available = $this->taskManager->getAvailableTaskTypes();
        foreach (self::TASK_TYPES as $type) {
            if (isset($available[$type])) {
                return $type;
            }
        }
        throw new \RuntimeException('No AI text-to-text provider is available on this Nextcloud instance.');
    }

    private function buildPrompt(string $description, string $sourceText = ''): string
    {
        $schemaExample = json_encode([
            'title' => 'Klantfeedback',
            'description' => 'Korte enquête',
            'questions' => [
                [
                    'id' => 'has_account',
                    'type' => 'choice',
                    'question' => 'Heeft u een account?',
                    'required' => true,
                    'options' => [
                        ['id' => 'opt1', 'label' => 'Ja', 'value' => 'yes'],
                        ['id' => 'opt2', 'label' => 'Nee', 'value' => 'no'],
                    ],
                ],
                [
                    'id' => 'account_email',
                    'type' => 'text',
                    'question' => 'Wat is het e-mailadres van uw account?',
                    'required' => true,
                    'showIf' => [
                        'questionId' => 'has_account',
                        'operator' => 'equals',
                        'value' => 'yes',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $safeDescription = trim($description);
        $safeSource = trim($sourceText);
        if ($safeSource !== '') {
            $safeSource = mb_substr($safeSource, 0, self::MAX_DOC_CHARS);
        }

        $sourceBlock = '';
        if ($safeSource !== '') {
            $sourceBlock = "\n\nSource document (the basis for the form):\n\"\"\"\n{$safeSource}\n\"\"\"";
        }

        if ($safeDescription !== '' && $safeSource !== '') {
            $userRequestBlock = "Instruction (how to use the source document below):\n\"\"\"\n{$safeDescription}\n\"\"\"\n\nFollow the instruction precisely — only generate questions about the parts of the source document the instruction asks for. If the instruction conflicts with the source, the instruction wins.";
        } elseif ($safeDescription !== '') {
            $userRequestBlock = "User request:\n\"\"\"\n{$safeDescription}\n\"\"\"";
        } else {
            $userRequestBlock = "User request: (no extra description — base the form purely on the source document below.)";
        }

        $maxQuestions = $this->getMaxQuestions();
        $conditionalAllowed = $this->isConditionalLogicAllowed();

        $conditionalBlock = $conditionalAllowed ? <<<COND

Conditional logic — `showIf` (optional, USE SPARINGLY):
A question may include `showIf` to be hidden until a previous question has a specific answer. Only add `showIf` when the question would be genuinely irrelevant otherwise (e.g. "What kind of pet?" should only appear if the user said they have a pet). Never use `showIf` as a generic refinement or "nice-to-have"; most questions should NOT have one.

Shape: `{ "questionId": "<id of an EARLIER question>", "operator": "<op>", "value": "<...>" }`
- `questionId` MUST refer to a question that appears EARLIER in the array (no forward references).
- `operator` allowed: equals, notEquals, contains, notContains, isEmpty, isNotEmpty, greaterThan, lessThan.
- For choice/multiple/dropdown, `value` MUST be copy-pasted verbatim from that question's `options[].value` (ASCII string).
COND : "\n\nConditional logic (`showIf`) is DISABLED by the administrator — never include a `showIf` field.";

        $fieldsAllowList = $conditionalAllowed
            ? '`id`, `type`, `question`, `description`, `required`, `options`, `min`, `max`, `showIf`'
            : '`id`, `type`, `question`, `description`, `required`, `options`, `min`, `max`';

        return <<<PROMPT
You are a form-design assistant for the FormVox application. Generate a JSON form structure based on the user's request{$this->sourceMention($safeSource)}.

{$userRequestBlock}{$sourceBlock}

Allowed question types (use the lowercase string verbatim):
- text          — short single-line answer
- textarea      — long multi-line answer
- choice        — single choice (radio buttons), needs `options`
- multiple      — multiple choice (checkboxes), needs `options`
- dropdown      — dropdown select, needs `options`
- number        — numeric answer
- date          — date picker
- datetime      — date + time
- time          — time only
- scale         — linear scale, needs `min` and `max` (integers)
- rating        — star rating, needs `max` (integer)

Output schema (return JSON exactly in this shape):
{$schemaExample}
{$conditionalBlock}

Rules:
1. Return ONLY valid JSON. No prose, no markdown fences, no commentary, no trailing text.
2. Output COMPACT JSON without indentation, line breaks, or extra whitespace.
3. `id` values must be unique strings within the form. Prefer short semantic ids (e.g. `has_account`, `email`) over `q1`, `q2`.
4. Each option's `id` must be unique within its question.
5. Match the user's request language for `title`, `description`, `question`, and option `label` values. Keep `id` and `value` ASCII-only.
6. Generate between 3 and {$maxQuestions} questions unless the user explicitly asks otherwise. Up to 8 options per question.
7. Set `required: true` only for questions that are clearly important.
8. Do NOT add fields beyond {$fieldsAllowList}.
PROMPT;
    }

    /**
     * Parse the AI's raw text response into a form structure.
     */
    public function parseAiResponse(string $raw): array
    {
        $cleaned = trim($raw);

        // Strip ```json ... ``` fences if the model added them
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```\s*$/', '', $cleaned);

        // Some models wrap the JSON in extra prose — extract first {...} block
        if (!str_starts_with($cleaned, '{')) {
            $start = strpos($cleaned, '{');
            $end = strrpos($cleaned, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $cleaned = substr($cleaned, $start, $end - $start + 1);
            }
        }

        $data = json_decode($cleaned, true);

        // The model often gets cut off mid-question. Try to repair the JSON by
        // closing the last complete `questions` array and the root object.
        if (!is_array($data)) {
            $repaired = $this->tryRepairTruncatedJson($cleaned);
            if ($repaired !== null) {
                $data = json_decode($repaired, true);
            }
        }

        if (!is_array($data)) {
            $this->logger->warning('FormVox AI: failed to decode AI response', [
                'raw' => mb_substr($raw, 0, 2000),
                'cleaned' => mb_substr($cleaned, 0, 2000),
            ]);
            throw new \RuntimeException('AI returned an invalid response. Try a shorter or simpler description.');
        }

        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        $description = is_string($data['description'] ?? null) ? trim($data['description']) : '';
        $questions = is_array($data['questions'] ?? null) ? $data['questions'] : [];

        if ($questions === []) {
            throw new \RuntimeException('AI did not generate any questions.');
        }

        $allowedTypes = ['text', 'textarea', 'choice', 'multiple', 'dropdown', 'number', 'date', 'datetime', 'time', 'scale', 'rating'];
        $sanitized = [];
        foreach ($questions as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $type = $q['type'] ?? 'text';
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }

            $clean = [
                'id' => is_string($q['id'] ?? null) && $q['id'] !== '' ? $q['id'] : 'q' . ($i + 1),
                'type' => $type,
                'question' => (string)($q['question'] ?? ''),
                'description' => (string)($q['description'] ?? ''),
                'required' => (bool)($q['required'] ?? false),
            ];

            if (in_array($type, ['choice', 'multiple', 'dropdown'], true) && isset($q['options']) && is_array($q['options'])) {
                $opts = [];
                foreach ($q['options'] as $j => $opt) {
                    if (!is_array($opt)) continue;
                    $opts[] = [
                        'id' => is_string($opt['id'] ?? null) && $opt['id'] !== '' ? $opt['id'] : 'opt' . ($j + 1),
                        'label' => (string)($opt['label'] ?? ''),
                        'value' => (string)($opt['value'] ?? ($opt['label'] ?? '')),
                    ];
                }
                if ($opts !== []) {
                    $clean['options'] = $opts;
                }
            }

            if (in_array($type, ['scale', 'number', 'rating'], true)) {
                if (isset($q['min']) && is_numeric($q['min'])) $clean['min'] = (int)$q['min'];
                if (isset($q['max']) && is_numeric($q['max'])) $clean['max'] = (int)$q['max'];
            }

            // Stash any showIf the AI suggested; validated in a second pass below
            // once all question IDs are known.
            if (isset($q['showIf']) && is_array($q['showIf'])) {
                $clean['_pendingShowIf'] = $q['showIf'];
            }

            $sanitized[] = $clean;
        }

        if ($sanitized === []) {
            throw new \RuntimeException('AI did not generate any usable questions.');
        }

        $sanitized = $this->validateAndApplyShowIf($sanitized);

        return [
            'title' => $title !== '' ? $title : 'Untitled form',
            'description' => $description,
            'questions' => $sanitized,
        ];
    }

    /**
     * Walk the sanitized question list and validate every `_pendingShowIf`:
     *   - questionId must reference an EARLIER question (no forward refs / no cycles)
     *   - operator must be allowed for the parent question's type
     *   - for choice/multiple/dropdown, value must match (case-insensitive trim) one of the parent's option values
     * Invalid showIf objects are silently dropped — the question still renders.
     */
    private function validateAndApplyShowIf(array $questions): array
    {
        $allowedOperators = ['equals', 'notEquals', 'contains', 'notContains', 'isEmpty', 'isNotEmpty', 'greaterThan', 'lessThan', 'in', 'notIn'];
        $stringOnlyOps = ['contains', 'notContains'];
        $numericOnlyOps = ['greaterThan', 'lessThan'];

        $byIndex = [];
        foreach ($questions as $i => $q) {
            $byIndex[$q['id']] = $i;
        }

        foreach ($questions as $i => &$q) {
            if (!isset($q['_pendingShowIf'])) continue;
            $raw = $q['_pendingShowIf'];
            unset($q['_pendingShowIf']);

            $refId = $raw['questionId'] ?? null;
            $op = $raw['operator'] ?? null;
            if (!is_string($refId) || !is_string($op)) continue;
            if (!in_array($op, $allowedOperators, true)) continue;

            // Reference must exist and appear EARLIER in the array
            if (!isset($byIndex[$refId])) continue;
            if ($byIndex[$refId] >= $i) continue;

            $parent = $questions[$byIndex[$refId]];
            $parentType = $parent['type'];

            // Type/operator compatibility
            if (in_array($parentType, ['number', 'scale', 'rating'], true) && in_array($op, $stringOnlyOps, true)) continue;
            if (in_array($parentType, ['choice', 'multiple', 'dropdown'], true) && in_array($op, $numericOnlyOps, true)) continue;

            $value = $raw['value'] ?? null;

            // For choice-style parents, snap the value to an actual option (case-insensitive)
            if (in_array($parentType, ['choice', 'multiple', 'dropdown'], true) && in_array($op, ['equals', 'notEquals', 'in', 'notIn'], true)) {
                $opts = $parent['options'] ?? [];
                $matched = null;
                $needle = is_string($value) ? mb_strtolower(trim($value)) : null;
                if ($needle !== null) {
                    foreach ($opts as $opt) {
                        if (mb_strtolower(trim((string)$opt['value'])) === $needle
                            || mb_strtolower(trim((string)$opt['label'])) === $needle) {
                            $matched = $opt['value'];
                            break;
                        }
                    }
                }
                if ($matched === null) continue; // drop — value doesn't match any option
                $value = $matched;
            }

            $q['showIf'] = [
                'questionId' => $refId,
                'operator' => $op,
                'value' => $value,
            ];
        }
        unset($q);

        return $questions;
    }

    private function sourceMention(string $sourceText): string
    {
        return $sourceText !== '' ? ' and the source document they uploaded' : '';
    }

    /**
     * Attempt to repair JSON that was truncated mid-question. Strategy: find the
     * last complete question object inside `"questions": [ ... ]`, drop anything
     * after it, then close the brackets.
     */
    private function tryRepairTruncatedJson(string $cleaned): ?string
    {
        $qPos = strpos($cleaned, '"questions"');
        if ($qPos === false) {
            return null;
        }
        $arrayStart = strpos($cleaned, '[', $qPos);
        if ($arrayStart === false) {
            return null;
        }

        // Walk forward, tracking brace depth inside the questions array, and
        // remember the position of the last successful closing brace at depth 0
        // (i.e. end of one question object inside the array).
        $depth = 0;
        $inString = false;
        $escape = false;
        $lastCompleteEnd = -1;

        $len = strlen($cleaned);
        for ($i = $arrayStart + 1; $i < $len; $i++) {
            $c = $cleaned[$i];
            if ($escape) { $escape = false; continue; }
            if ($c === '\\') { $escape = true; continue; }
            if ($c === '"') { $inString = !$inString; continue; }
            if ($inString) continue;

            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $lastCompleteEnd = $i;
                }
            } elseif ($c === ']' && $depth === 0) {
                // Original JSON was already complete here
                return null;
            }
        }

        if ($lastCompleteEnd < 0) {
            return null;
        }

        // Truncate after the last complete question object and close the array + object.
        return substr($cleaned, 0, $lastCompleteEnd + 1) . ']}';
    }

    /**
     * Resolve a Nextcloud file id (must belong to $userId) and extract its text.
     * Supports text/* mime types directly, and PDF/DOCX/ODT via the assistant app.
     * Returns '' if the file cannot be read or extracted.
     */
    private function extractTextFromFileId(int $fileId, string $userId): string
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $nodes = $userFolder->getById($fileId);
            if ($nodes === []) {
                throw new \RuntimeException('Source document not found.');
            }
            $node = $nodes[0];
            if (!$node instanceof File) {
                throw new \RuntimeException('Source must be a file.');
            }

            $maxBytes = $this->getMaxDocBytes();
            if ($node->getSize() > $maxBytes) {
                $maxMb = (int)round($maxBytes / 1024 / 1024);
                throw new \RuntimeException("Source document is too large. Maximum {$maxMb} MB.");
            }

            $mime = $node->getMimeType();

            if (str_starts_with($mime, 'text/')) {
                $raw = $node->getContent();
                return mb_substr($raw, 0, self::MAX_DOC_CHARS);
            }

            if (in_array($mime, self::ASSISTANT_MIMES, true)) {
                return $this->extractViaAssistant($node);
            }

            throw new \RuntimeException('Unsupported file type. Use PDF, DOCX, ODT, or a plain text file.');
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->warning('FormVox AI: failed to read source document', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Use the Nextcloud "assistant" app's bundled vendor (PdfParser / PhpWord) to
     * extract plain text from PDF/DOCX/ODT. Returns '' if the assistant app or
     * its vendor autoloader is missing.
     */
    private function extractViaAssistant(File $file): string
    {
        try {
            $assistantPath = \OC::$server->getAppManager()->getAppPath('assistant');
        } catch (\Exception $e) {
            $this->logger->debug('FormVox AI: assistant app not installed; cannot parse document');
            return '';
        }

        $autoloader = $assistantPath . '/vendor/autoload.php';
        if (!file_exists($autoloader)) {
            $this->logger->debug('FormVox AI: assistant vendor autoloader not found at ' . $autoloader);
            return '';
        }
        require_once $autoloader;

        $mime = $file->getMimeType();
        try {
            // Write file content to a temp file because both libraries expect a path
            $tmp = tempnam(sys_get_temp_dir(), 'fvxai_');
            if ($tmp === false) {
                return '';
            }
            file_put_contents($tmp, $file->getContent());

            $text = '';
            try {
                if ($mime === 'application/pdf' && class_exists('\\Smalot\\PdfParser\\Parser')) {
                    // Configure PdfParser to skip image streams to keep memory low
                    // on image-heavy PDFs.
                    $config = null;
                    if (class_exists('\\Smalot\\PdfParser\\Config')) {
                        $config = new \Smalot\PdfParser\Config();
                        if (method_exists($config, 'setRetainImageContent')) {
                            $config->setRetainImageContent(false);
                        }
                        if (method_exists($config, 'setIgnoreEncryption')) {
                            $config->setIgnoreEncryption(true);
                        }
                    }
                    $parser = $config ? new \Smalot\PdfParser\Parser([], $config) : new \Smalot\PdfParser\Parser();
                    $pdf = $parser->parseFile($tmp);
                    $text = $pdf->getText();
                } elseif (class_exists('\\PhpOffice\\PhpWord\\IOFactory')) {
                    $reader = $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                        ? \PhpOffice\PhpWord\IOFactory::createReader('Word2007')
                        : \PhpOffice\PhpWord\IOFactory::createReader('ODText');
                    $phpWord = $reader->load($tmp);
                    $text = $this->extractPhpWordText($phpWord);
                }
            } finally {
                @unlink($tmp);
            }

            return mb_substr(trim($text), 0, self::MAX_DOC_CHARS);
        } catch (\Throwable $e) {
            $this->logger->debug('FormVox AI: document parse failed: ' . $e->getMessage());
            return '';
        }
    }

    private function extractPhpWordText($phpWord): string
    {
        $out = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $out .= $this->phpWordElementText($element) . "\n";
            }
        }
        return $out;
    }

    private function phpWordElementText($element): string
    {
        if (method_exists($element, 'getText')) {
            $val = $element->getText();
            if (is_string($val)) {
                return $val;
            }
        }
        if (method_exists($element, 'getElements')) {
            $out = '';
            foreach ($element->getElements() as $child) {
                $out .= $this->phpWordElementText($child) . ' ';
            }
            return rtrim($out);
        }
        return '';
    }
}
