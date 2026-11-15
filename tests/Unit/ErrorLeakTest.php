<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Exception texts can carry paths, SQL, storage details or another service's answer, so only FormVox's own
 * messages may reach a person; every other text stays in the log (review V1/V4, 18 leaks before 4a).
 * ExceptionMiddleware answers; controllers do not catch for themselves.
 */
class ErrorLeakTest extends TestCase {
	private const LIB = __DIR__ . '/../../lib';

	/** Exceptions whose message is written for the person who reads it. */
	private const OWN_EXCEPTIONS = ['ClientErrorException', 'RequestException', 'ResponseRefusedException', 'FormNotFoundException',
		'FormAccessDeniedException', 'TemplateLimitException', 'InvalidAnswerException', 'AiGenerationException', 'OrphanException'];

	/** Deliberate, with the reason. */
	private const ALLOWED = [
		// Reads the database error only to recognise a lock race on databases that give no reason code.
		'Service/FormLockManager.php',
		// The admin's own "send now" shows what the telemetry server answered.
		'Service/TelemetryService.php',
		// Shows our own exceptions after checking their type.
		'Middleware/ExceptionMiddleware.php',
		// Tells the admin running occ formvox:migrate, on the server's own console, why a form was passed over.
		'Store/FormMigration.php',
	];

	public function testOutsideTheLogOnlyOurOwnMessagesAreShown(): void {
		$offenders = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB)) as $file) {
			$path = $file->getPathname();
			$relative = substr($path, strlen(self::LIB) + 1);
			// lib/Vendor is dompdf as shipped: not FormVox's code, and it answers nobody directly.
			if (!str_ends_with($path, '.php') || in_array($relative, self::ALLOWED, true) || str_starts_with($relative, 'Vendor/')) {
				continue;
			}
			$source = file_get_contents($path);
			preg_match_all('/\$(\w+)->getMessage\(\)/', $source, $calls, PREG_OFFSET_CAPTURE);
			foreach ($calls[1] as [$variable, $offset]) {
				$before = substr($source, 0, $offset);
				$statement = substr($before, max((int)strrpos($before, ';'), (int)strrpos($before, '{'), (int)strrpos($before, '}')));
				$line = substr($before, (int)strrpos($before, "\n"));
				// The log; wrapping it into an exception of our own making, which ExceptionMiddleware then hides.
				if (str_contains($statement . $line, '->logger->') || preg_match('/throw new \\\\(Runtime)?Exception\(/', $statement . $line)) {
					continue;
				}
				preg_match_all('/catch\s*\(([^)]*?)\$' . $variable . '\s*\)/', $before, $catches);
				$types = array_map(static fn (string $type) => substr((string)strrchr('\\' . trim($type), '\\'), 1), explode('|', (string)end($catches[1])));
				if (array_diff($types, self::OWN_EXCEPTIONS) !== []) {
					$offenders[] = $relative . ':' . (substr_count($before, "\n") + 1) . ' shows the message of ' . implode('|', $types);
				}
			}
		}

		$this->assertSame([], $offenders, implode("\n", $offenders));
	}

	/** A controller answers through ExceptionMiddleware rather than wording an exception itself. */
	public function testControllersDoNotHandExceptionTextsOut(): void {
		$offenders = [];
		foreach (glob(self::LIB . '/Controller/*.php') as $file) {
			$source = file_get_contents($file);
			if (preg_match_all('/->getMessage\(\)|->getTraceAsString\(\)/', $source, $m, PREG_OFFSET_CAPTURE)) {
				foreach ($m[0] as [, $offset]) {
					$offenders[] = basename($file) . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
				}
			}
		}

		$this->assertSame([], $offenders, implode("\n", $offenders));
	}

	/**
	 * Messages ExceptionMiddleware translates must reach translators: Translatable::t() takes a literal, and
	 * every literal is in the template (run npm run l10n:push).
	 */
	public function testEveryTranslatableMessageIsALiteralTheTranslatorsHave(): void {
		$pot = file_get_contents(__DIR__ . '/../../translationfiles/templates/formvox.pot');
		$offenders = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB)) as $file) {
			$path = $file->getPathname();
			if (!str_ends_with($path, '.php') || str_ends_with($path, 'L10n/Translatable.php')) {
				continue;
			}
			$source = file_get_contents($path);
			preg_match_all("/Translatable::t\(\s*([^)\s][^)]*)\)/", $source, $calls);
			foreach ($calls[1] as $argument) {
				if (!preg_match("/^'((?:[^'\\\\]|\\\\.)*)'$/", trim($argument), $literal)) {
					$offenders[] = basename($path) . ': Translatable::t(' . trim($argument) . ') is not a literal';
					continue;
				}
				$text = stripslashes($literal[1]);
				if (!str_contains($pot, 'msgid "' . addcslashes($text, '"\\') . '"')) {
					$offenders[] = basename($path) . ": '$text' is not in formvox.pot";
				}
			}
		}

		$this->assertSame([], $offenders, implode("\n", $offenders));
	}
}
