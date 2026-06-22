<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Instance-wide templates managed by the FormVox admin and shown in the
 * TemplateGallery for every user (#100). Stored as a single JSON array in
 * appconfig under `templates` — no separate table or file storage needed.
 */
class TemplateService
{
    private const KEY = 'templates';
    private const MAX_TEMPLATES = 100;

    public function __construct(
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, array{id:string, title:string, description:string, questionCount:int, createdAt:string}>
     */
    public function listTemplates(): array
    {
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
                    fn($q) => !in_array($q['type'] ?? '', ['section', 'descriptor'], true),
                )),
                'createdAt' => (string)($t['createdAt'] ?? ''),
            ];
        }, $decoded);
    }

    /**
     * Return the full form structure for a given template id, or null.
     */
    public function getTemplate(string $id): ?array
    {
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
                return $t['form'] ?? null;
            }
        }
        return null;
    }

    /**
     * Add a new template snapshot. Strips responses, indexes, share tokens
     * and respondent state so users only get the structure.
     */
    public function addTemplate(string $title, string $description, array $form): array
    {
        $raw = $this->config->getAppValue(Application::APP_ID, self::KEY, '');
        $list = $raw === '' ? [] : (json_decode($raw, true) ?: []);

        if (count($list) >= self::MAX_TEMPLATES) {
            throw new \RuntimeException('Template limit reached');
        }

        // Sanitize form structure
        $cleanForm = $this->stripRuntimeFields($form);
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
                fn($q) => !in_array($q['type'] ?? '', ['section', 'descriptor'], true),
            )),
            'createdAt' => $entry['createdAt'],
        ];
    }

    public function removeTemplate(string $id): bool
    {
        $raw = $this->config->getAppValue(Application::APP_ID, self::KEY, '');
        if ($raw === '') {
            return false;
        }
        $list = json_decode($raw, true);
        if (!is_array($list)) {
            return false;
        }
        $before = count($list);
        $list = array_values(array_filter($list, fn($t) => ($t['id'] ?? '') !== $id));
        if (count($list) === $before) {
            return false;
        }
        $this->config->setAppValue(Application::APP_ID, self::KEY, json_encode($list, JSON_UNESCAPED_UNICODE));
        return true;
    }

    private function stripRuntimeFields(array $form): array
    {
        unset(
            $form['responses'],
            $form['_index'],
            $form['permissions'],
            $form['created_at'],
            $form['modified_at'],
            $form['id'],
        );
        // Strip share-link / password state — templates shouldn't carry these.
        if (isset($form['settings']) && is_array($form['settings'])) {
            unset(
                $form['settings']['public_token'],
                $form['settings']['share_password'],
                $form['settings']['share_password_hash'],
                $form['settings']['share_expires_at'],
                $form['settings']['share_starts_at'],
            );
        }
        return $form;
    }
}
