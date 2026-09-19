import { describe, it, expect } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'

// happy-dom leaves import.meta.url without a file: scheme, so anchor on the
// project root Vitest already runs from.
const ROOT = process.cwd()
const require_ = createRequire(path.join(ROOT, 'vitest.config.mjs'))
const { computeSourceStrings } = require_(path.join(ROOT, 'scripts/extract-en-json.js'))

/**
 * Guard against a translatable string drifting away from its own translations.
 *
 * The bug this pins (1.4.6): the FormService split moved two demo-form
 * placeholders into FormFactory and, on the way, turned the U+00A0 before their
 * ellipsis into a plain space. Translations are keyed on the exact source text,
 * so "Write a short bio …" and "Share your thoughts …" silently fell back to
 * English in all five languages. Nothing caught it: the string count stayed at
 * 825, the identity-t() snapshots in FormFactoryTest happily snapshot whatever
 * the source says, and check-l10n-sync could only report "+2 added / -2 removed"
 * over two pairs that look identical on screen.
 *
 * The invariant: a source string may legitimately have no translation yet (new
 * strings wait for translators), but it must never differ from an EXISTING
 * translation key by whitespace alone. That combination is not a new string —
 * it is an old one whose translations have just been orphaned.
 *
 * Whitespace-only is deliberately the whole rule. Any real edit to a string
 * (rewording, punctuation, capitalisation) legitimately orphans its translations
 * and is caught by check-l10n-sync as a normal add/remove; only the invisible
 * variants — NBSP vs space, tab, thin/narrow NBSP — are indistinguishable to a
 * reviewer and therefore need a machine to spot them.
 */

// Every space-like codepoint that renders (near enough) like a plain space.
const SPACE_LIKE = /[   -   　\t]/g
const normalise = (s) => s.replace(SPACE_LIKE, ' ')

const LANGUAGES = ['nl', 'de', 'fr', 'ca', 'uk']

const loadCatalogue = (lang) => {
	const raw = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', `${lang}.json`), 'utf8'))
	return raw.translations || raw
}

describe('l10n source drift', () => {
	const sourceStrings = computeSourceStrings().singulars

	it('extracts the source strings it is meant to guard', () => {
		// Sanity: if the extractor ever returns nothing, the assertions below
		// would pass vacuously and this guard would be silently dead.
		expect(sourceStrings.length).toBeGreaterThan(500)
	})

	it.each(LANGUAGES)('no source string differs from a %s translation key by whitespace alone', (lang) => {
		const catalogue = loadCatalogue(lang)

		// Existing translation keys, indexed by their whitespace-normalised form.
		const keysByShape = new Map()
		for (const key of Object.keys(catalogue)) {
			keysByShape.set(normalise(key), key)
		}

		const drifted = sourceStrings
			.filter((s) => !(s in catalogue))
			.map((s) => ({ source: s, key: keysByShape.get(normalise(s)) }))
			.filter(({ key }) => key !== undefined)

		expect(drifted, drifted.map(({ source, key }) =>
			`\n  source: ${JSON.stringify(source)}`
			+ `\n  ${lang}.json key: ${JSON.stringify(key)}`
			+ '\n  → identical apart from whitespace, so this string lost its translations.'
			+ '\n    Restore the original spacing in the source rather than re-pushing it as a new string.',
		).join('\n')).toEqual([])
	})
})
