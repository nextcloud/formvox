<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the route contract during the controller split.
 *
 * Two independent guarantees:
 *  1. The SET of URL paths is frozen — the controller split may rename the
 *     `controller#method` handler prefix, but must NOT change any public URL
 *     (the frontend calls URLs, not route names).
 *  2. Every `controller#method` resolves to a real public method on the named
 *     controller class. This is what actually catches a botched split: a route
 *     whose handler was moved but not repointed passes a URL snapshot yet 500s
 *     at runtime. Reflection catches it here, with no server.
 *
 * When a controller is intentionally split, update EXPECTED_URLS only if a URL
 * genuinely changed (it should not during this refactor); the reflection check
 * updates itself as handlers move, as long as they land on a real method.
 */
class RoutesIntegrityTest extends TestCase {
	/**
	 * The complete frozen set of public URL paths. Sorted. If the refactor
	 * changes any of these, that is a frontend-breaking change and must be
	 * deliberate — update this list only then.
	 */
	private const EXPECTED_URLS = [
		'DELETE /api/branding/image/{blockId}',
		'DELETE /api/form/{fileId}',
		'DELETE /api/form/{fileId}/api-keys/{keyId}',
		'DELETE /api/form/{fileId}/branding/image/{blockId}',
		'DELETE /api/form/{fileId}/odt-template',
		'DELETE /api/form/{fileId}/responses',
		'DELETE /api/form/{fileId}/responses/{responseId}',
		'DELETE /api/form/{fileId}/webhooks/{webhookId}',
		'DELETE /api/v1/external/forms/{fileId}/responses/{responseId}',
		'DELETE /api/admin/templates/{id}',
		'GET /',
		'GET /api/ai/status',
		'GET /api/ai/task/{taskId}',
		'GET /api/ai/resolve-file',
		'GET /api/branding',
		'GET /api/form/{fileId}',
		'GET /api/form/{fileId}/export/csv',
		'GET /api/form/{fileId}/export/json',
		'GET /api/form/{fileId}/export/xlsx',
		'GET /api/form/{fileId}/odt-template',
		'GET /api/form/{fileId}/odt-template/status',
		'GET /api/form/{fileId}/presence',
		'GET /api/form/{fileId}/responses',
		'GET /api/form/{fileId}/uploads',
		'GET /api/form/{fileId}/uploads/{responseId}/{filename}',
		'GET /api/forms',
		'GET /api/import/ms-forms/auth',
		'GET /api/import/ms-forms/callback',
		'GET /api/import/ms-forms/list',
		'GET /api/import/ms-forms/status',
		'GET /api/import/ms-forms/{msFormId}/preview',
		'GET /api/integration/permissions',
		'GET /api/integration/webhook-events',
		'GET /api/license/stats',
		'GET /api/permissions/{fileId}',
		'GET /api/settings/ai',
		'GET /api/sharees',
		'GET /api/statistics',
		'GET /api/statistics/telemetry',
		'GET /api/templates',
		'GET /api/admin/templates',
		'GET /api/v1/external/forms/{fileId}',
		'GET /api/v1/external/forms/{fileId}/responses',
		'GET /api/v1/external/forms/{fileId}/responses/{responseId}',
		'GET /api/v1/external/forms/{fileId}/schema',
		'GET /branding/image/{blockId}',
		'GET /branding/{fileId}/image/{blockId}',
		'GET /edit/{fileId}',
		'GET /embed/{fileId}/{token}',
		'GET /public/{fileId}/{token}',
		'GET /public/{fileId}/{token}/challenge',
		'GET /results/{fileId}',
		'POST /api/ai/generate-form',
		'POST /api/branding/image/{blockId}',
		'POST /api/form/{fileId}/api-keys',
		'POST /api/form/{fileId}/branding/image/{blockId}',
		'POST /api/form/{fileId}/favorite',
		'POST /api/form/{fileId}/odt-template',
		'POST /api/form/{fileId}/presence',
		'POST /api/form/{fileId}/rebuild-index',
		'POST /api/form/{fileId}/save-as-template',
		'POST /api/form/{fileId}/share-token',
		'POST /api/form/{fileId}/webhooks',
		'POST /api/forms',
		'POST /api/import/ms-forms/disconnect',
		'POST /api/import/ms-forms/{msFormId}',
		'POST /api/license/update-usage',
		'POST /api/license/validate',
		'POST /api/settings/ai',
		'POST /api/settings/embed',
		'POST /api/settings/license',
		'POST /api/settings/ms-forms',
		'POST /api/statistics/telemetry',
		'POST /api/statistics/telemetry/send',
		'POST /api/admin/templates',
		'POST /api/v1/external/forms/{fileId}/responses',
		'POST /embed/{fileId}/{token}',
		'POST /public/{fileId}/{token}',
		'POST /public/{fileId}/{token}/submit',
		'POST /public/{fileId}/{token}/upload',
		'PUT /api/branding/layout',
		'PUT /api/branding/styles',
		'PUT /api/form/{fileId}',
		'PUT /api/form/{fileId}/webhooks/{webhookId}',
		'PUT /api/v1/external/forms/{fileId}/responses/{responseId}',
	];

	private function loadRoutes(): array {
		/** @var array{routes: array<int, array{name: string, url: string, verb: string}>} $routes */
		$routes = require __DIR__ . '/../../appinfo/routes.php';
		return $routes['routes'];
	}

	public function testUrlSetIsFrozen(): void {
		$actual = [];
		foreach ($this->loadRoutes() as $r) {
			$actual[] = $r['verb'] . ' ' . $r['url'];
		}
		sort($actual);
		$expected = self::EXPECTED_URLS;
		sort($expected);

		$this->assertSame(
			$expected,
			$actual,
			'The set of public URL paths changed. If deliberate, update EXPECTED_URLS; '
			. 'otherwise the controller split broke the frontend contract.'
		);
	}

	/**
	 * Every `controller#method` must resolve to a real public method on the
	 * mapped controller class. Catches a handler moved during the split but
	 * not repointed in routes.php.
	 */
	public function testEveryRouteHandlerResolves(): void {
		$failures = [];
		foreach ($this->loadRoutes() as $r) {
			[$controllerKey, $method] = explode('#', $r['name'], 2);
			$class = 'OCA\\FormVox\\Controller\\' . $this->toClassName($controllerKey) . 'Controller';

			if (!class_exists($class)) {
				$failures[] = "{$r['name']} -> class {$class} does not exist";
				continue;
			}
			$rc = new ReflectionClass($class);
			if (!$rc->hasMethod($method)) {
				$failures[] = "{$r['name']} -> {$class}::{$method}() does not exist";
				continue;
			}
			if (!$rc->getMethod($method)->isPublic()) {
				$failures[] = "{$r['name']} -> {$class}::{$method}() is not public";
			}
		}

		$this->assertSame([], $failures, "Unresolved route handlers:\n" . implode("\n", $failures));
	}

	/**
	 * Nextcloud route-controller key -> class-name stem.
	 * snake_case -> PascalCase (external_api -> ExternalApi).
	 */
	private function toClassName(string $key): string {
		return str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
	}
}
