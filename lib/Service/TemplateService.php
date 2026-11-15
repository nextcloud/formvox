<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Security\FormSanitizer;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Instance-wide templates managed by the FormVox admin and shown in the
 * TemplateGallery for every user (#100). Stored as a single JSON array in
 * appconfig under `templates` — no separate table or file storage needed.
 */
class TemplateService {
	private const KEY = 'templates';
	private const MAX_TEMPLATES = 100;

	public function __construct(
		private IConfig $config,
		private LoggerInterface $logger,
		private FormSanitizer $sanitizer,
		private FormSchemaValidator $schema,
	) {
	}

	/**
	 * @return array<int, array{id:string, title:string, description:string, questionCount:int, createdAt:string}>
	 */
	public function listTemplates(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		// Return a slim summary for listings; full form is fetched via getTemplate.
		return array_map(function ($t) {
			return [
				'id' => (string)($t['id'] ?? ''),
				'title' => (string)($t['title'] ?? ''),
				'description' => (string)($t['description'] ?? ''),
				'questionCount' => count(array_filter(
					$t['form']['questions'] ?? [],
					fn ($q) => !in_array($q['type'] ?? '', ['section', 'descriptor'], true),
				)),
				'createdAt' => (string)($t['createdAt'] ?? ''),
			];
		}, $decoded);
	}

	/**
	 * Return the full form structure for a given template id, or null.
	 */
	public function getTemplate(string $id): ?array {
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY, '');
		if ($raw === '') {
			return null;
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return null;
		}
		foreach ($decoded as $t) {
			if (($t['id'] ?? '') === $id) {
				// Also cleans templates stored before forTemplate() dropped integrations.
				return is_array($t['form'] ?? null) ? $this->sanitizer->forTemplate($t['form']) : null;
			}
		}
		return null;
	}

	/**
	 * Add a new template snapshot. Strips responses, indexes, share tokens
	 * and respondent state so users only get the structure.
	 */
	public function addTemplate(string $title, string $description, array $form): array {
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY, '');
		$list = $raw === '' ? [] : (json_decode($raw, true) ?: []);

		if (count($list) >= self::MAX_TEMPLATES) {
			throw TemplateLimitException::reached();
		}

		// Sanitize form structure; every form made from it starts from what is stored here.
		$cleanForm = $this->schema->check($this->sanitizer->forTemplate($form));
		$cleanForm['title'] = $title;
		$cleanForm['description'] = $description;

		$entry = [
			'id' => 't' . bin2hex(random_bytes(8)),
			'title' => $title,
			'description' => $description,
			'createdAt' => date('c'),
			'form' => $cleanForm,
		];
		$list[] = $entry;

		$this->config->setAppValue(Application::APP_ID, self::KEY, json_encode($list, JSON_UNESCAPED_UNICODE));
		return [
			'id' => $entry['id'],
			'title' => $entry['title'],
			'description' => $entry['description'],
			'questionCount' => count(array_filter(
				$cleanForm['questions'] ?? [],
				fn ($q) => !in_array($q['type'] ?? '', ['section', 'descriptor'], true),
			)),
			'createdAt' => $entry['createdAt'],
		];
	}

	/**
	 * Rewrites stored templates through forTemplate(), for those saved before it dropped secrets, people and
	 * links: they still sat in appconfig, only filtered when read. Run once by the CleanStoredTemplates repair step.
	 *
	 * @return int how many templates were cleaned
	 */
	public function cleanStored(): int {
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY, '');
		$list = $raw === '' ? null : json_decode($raw, true);
		if (!is_array($list)) {
			return 0;
		}
		$cleaned = 0;
		foreach ($list as $i => $template) {
			if (!is_array($template) || !is_array($template['form'] ?? null)) {
				continue;
			}
			$clean = $this->sanitizer->forTemplate($template['form']);
			if ($clean != $template['form']) {
				$list[$i]['form'] = $clean;
				$cleaned++;
			}
		}
		if ($cleaned > 0) {
			$this->config->setAppValue(Application::APP_ID, self::KEY, json_encode($list, JSON_UNESCAPED_UNICODE));
		}
		return $cleaned;
	}

	public function removeTemplate(string $id): bool {
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY, '');
		if ($raw === '') {
			return false;
		}
		$list = json_decode($raw, true);
		if (!is_array($list)) {
			return false;
		}
		$before = count($list);
		$list = array_values(array_filter($list, fn ($t) => ($t['id'] ?? '') !== $id));
		if (count($list) === $before) {
			return false;
		}
		$this->config->setAppValue(Application::APP_ID, self::KEY, json_encode($list, JSON_UNESCAPED_UNICODE));
		return true;
	}
}
