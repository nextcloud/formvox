// Stryker mutation-testing config for the FormVox frontend.
//
// Runs the full Vitest suite (tests-js/) against mutated copies of the src/
// code to measure whether the tests actually ASSERT behaviour, not just cover
// it. This is heavy (the whole src/ tree → thousands of mutants → ~1-2h), so it
// runs ONLY on push to main via .forgejo/workflows/mutation.yml, never on
// feature branches. Run locally with: npm run test:mutation.

/** @type {import('@stryker-mutator/api/core').PartialStrykerOptions} */
export default {
	testRunner: 'vitest',
	vitest: {
		// Reuse the project's Vitest config (aliases + @nextcloud stubs).
		configFile: 'vitest.config.mjs',
	},
	// Mutate the whole frontend source. Icons are near-trivial SVG components;
	// they stay in scope (per the "full" choice) but contribute little.
	mutate: [
		'src/**/*.js',
		'src/**/*.vue',
		'!src/**/*.spec.js',
	],
	// Report formats: a browsable HTML report + a concise console summary.
	reporters: ['html', 'progress', 'clear-text'],
	htmlReporter: { fileName: 'reports/mutation/index.html' },
	// Thresholds: below `break` fails the run (the main-push gate). Deliberately
	// low for the first full run — a smoke run of the tightest component
	// (NumberField) scored ~54%, and declaration-level mutants (prop `type`,
	// `emits` arrays) legitimately survive without changing behaviour, so the
	// full-tree score will be lower. Establish the real baseline from the first
	// main run, then raise `break`. `high`/`low` only colour the report.
	thresholds: { high: 70, low: 40, break: 20 },
	// Keep the machine usable; the CI runner can override via --concurrency.
	concurrency: 4,
	// A mutant that makes a test hang is killed after this (ms).
	timeoutMS: 15000,
	tempDirName: '.stryker-tmp',
	cleanTempDir: true,
}
