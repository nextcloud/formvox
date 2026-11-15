<?php

declare(strict_types=1);

/**
 * Builds lib/Vendor: dompdf and what it needs, under OCA\FormVox\Vendor\ so no other app's copy can clash with it.
 * Run `composer install` first, then `composer vendor:pdf`; the result is committed, like the JS bundles.
 *
 * Strauss comes as a phar of a fixed version, checked by its hash: as a dev dependency its php-parser 5 would clash
 * with Psalm 5's php-parser 4.
 */

const STRAUSS_URL = 'https://github.com/BrianHenryIE/strauss/releases/download/0.31.1/strauss.phar';
const STRAUSS_SHA256 = '17dc2baf27b9a3f52834e3db729c8365d8915a80e37a087d253cf705387aa887';

$root = dirname(__DIR__);
$phar = $root . '/vendor/strauss-0.31.1.phar';

function fail(string $message): never {
	fwrite(STDERR, 'vendor:pdf: ' . $message . "\n");
	exit(1);
}

if (!is_file($phar) || hash_file('sha256', $phar) !== STRAUSS_SHA256) {
	$data = file_get_contents(STRAUSS_URL);
	if (!is_string($data) || hash('sha256', $data) !== STRAUSS_SHA256) {
		fail('the Strauss phar could not be fetched, or is not the one expected');
	}
	file_put_contents($phar, $data);
}

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phar) . ' --deleteVendorPackages=false', $status);
if ($status !== 0) {
	fail('Strauss failed');
}

// php-font-lib builds two class names from its own namespace, which Strauss cannot see: each patched exactly once.
$file = $root . '/lib/Vendor/dompdf/php-font-lib/src/FontLib/TrueType/File.php';
$code = (string)file_get_contents($file);
$patches = [
	'return $class_parts[1];' => 'return $class_parts[count($class_parts) - 2];',
	'$class = "FontLib\\\\$type\\\\TableDirectoryEntry";' => '$class = "OCA\\\\FormVox\\\\Vendor\\\\FontLib\\\\$type\\\\TableDirectoryEntry";',
];
foreach ($patches as $from => $to) {
	$code = str_replace($from, $to, $code, $count);
	if ($count !== 1) {
		fail("php-font-lib changed: '$from' found $count times in TrueType/File.php, expected once; check the patch");
	}
}
if (str_contains($code, '"FontLib\\\\')) {
	fail('php-font-lib still builds an unprefixed class name in TrueType/File.php');
}
file_put_contents($file, $code);

// Nothing but what runs: a script under bin/ would answer anyone over the web (Apache serves apps/*/lib).
$extras = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib/Vendor', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($extras as $item) {
	$path = $item->getPathname();
	$isExtraDir = $item->isDir() && in_array($item->getFilename(), ['bin', 'tests', 'test', 'docs', '.github'], true);
	$isExtraFile = $item->isFile() && preg_match('/^(phpunit\.xml.*|.*\.dist|Makefile|.*\.sh)$/', $item->getFilename()) === 1;
	if ($isExtraDir) {
		exec('rm -rf ' . escapeshellarg($path));
	} elseif ($isExtraFile) {
		unlink($path);
	}
}

// FormVox's PDFs use DejaVu Sans only; the mono and serif families are 3.4 MB nobody uses.
foreach (glob($root . '/lib/Vendor/dompdf/dompdf/lib/fonts/DejaVu{SansMono,Serif}*', GLOB_BRACE) ?: [] as $font) {
	unlink($font);
}
// LGPL: the files are changed (their namespaces, and the patch above), and say where to find the originals.
$lock = json_decode((string)file_get_contents($root . '/composer.lock'), true);
$notice = "# Third-party code in lib/Vendor\n\nBuilt by `composer vendor:pdf` (scripts/vendor-pdf.php) on " . gmdate('Y-m-d') . ".\n"
	. "Every file was changed by Strauss: its namespace now starts with OCA\\FormVox\\Vendor\\, so no other app's copy can clash with it.\n"
	. "dompdf/php-font-lib src/FontLib/TrueType/File.php was also patched in two lines to build its class names under that namespace.\n"
	. "Each package keeps its own licence file; the unchanged originals are at the sources below.\n\n"
	. "| Package | Version | Licence | Source |\n|---|---|---|---|\n";
foreach (is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
	if (is_dir($root . '/lib/Vendor/' . $package['name'])) {
		$notice .= '| ' . $package['name'] . ' | ' . $package['version'] . ' | ' . implode(', ', (array)($package['license'] ?? [])) . ' | ' . ($package['source']['url'] ?? '') . " |\n";
	}
}
// The fonts have no licence file of their own in dompdf; theirs asks to go along with every copy.
$notice .= "\nThe DejaVu Sans fonts in dompdf/dompdf/lib/fonts come with dompdf and are under the Bitstream Vera and Arev licences, with DejaVu's changes in the public domain: see LICENSES/DejaVu-Fonts.txt.\n";
file_put_contents($root . '/lib/Vendor/NOTICE.md', $notice);
echo "lib/Vendor is ready\n";
