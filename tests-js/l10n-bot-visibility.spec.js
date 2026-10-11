import { describe, it, expect } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

/**
 * Simulate the Nextcloud Transifex extractor and fail if it would miss a string.
 *
 * Our own extractor (scripts/extract-en-json.js) is permissive, but the POT we
 * commit is not what reaches Transifex: the sync bot runs
 * `translationtool.phar create-pot-files`, which overwrites our template with
 * its own extraction before `tx push`. What its extractor cannot see does not
 * exist for translators. FormVox lost 593 of 843 strings that way: the
 * frontend called a one-argument wrapper, t('Save').
 *
 * The bot (nextcloud/docker-ci translations/translationtool) has two passes:
 *
 *   <template>  its own regex, matching  t('formvox', '…')  literally
 *   <script>    the script blocks go to  xgettext --keyword=t:2 --keyword=n:2,3
 *
 * Both need the app id as the FIRST argument and a string literal as the
 * second, and xgettext only knows the keywords t and n.
 */

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const APP_ID = 'formvox'
const require = createRequire(import.meta.url)
const { computeSourceStrings } = require(path.join(ROOT, 'scripts/extract-en-json.js'))

// translationtool collapses whitespace in a template match with PHP's
// preg_replace('/\s+/', ' '). PHP's \s is ASCII-only, so the U+00A0 before an
// ellipsis survives it; JavaScript's \s would eat it too.
const collapsePhpWhitespace = (s) => s.replace(/[ \t\n\r\f\v]+/g, ' ')
const unescapeJs = (s) => s.replace(/\\(['\\])/g, '$1')

// The bot skips what .l10nignore lists.
const IGNORED = fs.readFileSync(path.join(ROOT, '.l10nignore'), 'utf8')
	.split('\n').map((l) => l.trim()).filter((l) => l && !l.startsWith('#'))
const isIgnored = (rel) => IGNORED.some((pat) => {
	const clean = pat.replace(/\/$/, '')
	return rel === clean || rel.startsWith(clean + '/') || rel.split('/').includes(clean)
})

function walk(dir, exts, acc = []) {
	for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, e.name)
		if (isIgnored(path.relative(ROOT, full))) continue
		if (e.isDirectory()) walk(full, exts, acc)
		else if (exts.some((ext) => e.name.endsWith(ext))) acc.push(full)
	}
	return acc
}

const STR = "'((?:[^'\\\\\\n]|\\\\.)*)'"

/** The bot's view of one .vue/.js file: which msgids its two passes extract. */
function botSees(file, source) {
	const found = new Set()
	const isVue = file.endsWith('.vue')

	if (isVue) {
		const template = source.match(/<template>([\s\S]+)<\/template>/)
		if (template) {
			// translationtool's regex, per call instead of greedy per line (see
			// the "one call per line" test for what greedy matching breaks).
			const tRe = new RegExp(`\\Wt\\s*\\(\\s*'?([\\w.]+)'?,\\s*${STR}\\s*[),]`, 'g')
			for (const m of template[1].matchAll(tRe)) {
				if (m[1] === APP_ID) found.add(collapsePhpWhitespace(unescapeJs(m[2])))
			}
			const nRe = new RegExp(`\\Wn\\s*\\(\\s*'?([\\w.]+)'?,\\s*${STR}\\s*,\\s*${STR}\\s*,`, 'g')
			for (const m of template[1].matchAll(nRe)) {
				if (m[1] === APP_ID) found.add(collapsePhpWhitespace(unescapeJs(m[2])))
			}
		}
	}

	// <script> blocks (or a plain .js file): xgettext --keyword=t:2 --keyword=n:2,3.
	const scripts = isVue
		? [...source.matchAll(/<script[^>]*>([\s\S]+?)<\/script>/g)].map((m) => m[1])
		: [source]
	const re = new RegExp(`(?<![\\w$])[tn]\\(\\s*'${APP_ID}'\\s*,\\s*${STR}`, 'g')
	for (const body of scripts) {
		for (const m of body.matchAll(re)) found.add(unescapeJs(m[1]))
	}
	return found
}

describe('Transifex bot visibility', () => {
	const files = walk(path.join(ROOT, 'src'), ['.vue', '.js'])
	const sources = new Map(files.map((f) => [f, fs.readFileSync(f, 'utf8')]))
	const rel = (f) => path.relative(ROOT, f)

	const visible = new Set()
	for (const [f, body] of sources) for (const s of botSees(f, body)) visible.add(s)

	// Every frontend msgid our own (permissive) extractor finds: any t()/n()
	// call, with or without the app id. Its full set must contain them all.
	const src = computeSourceStrings()
	const known = new Set([...src.singulars, ...src.plurals.map(([a]) => a)])
	const frontend = new Set()
	const anyCall = new RegExp(`(?<![\\w$])(?:this\\.)?[tn]\\(\\s*(?:'${APP_ID}'\\s*,\\s*)?(?!'${APP_ID}'\\s*,)${STR}`, 'g')
	for (const body of sources.values()) {
		for (const m of body.matchAll(anyCall)) frontend.add(unescapeJs(m[1]))
	}

	it('our extractor and this test agree on what the frontend translates', () => {
		expect([...frontend].filter((s) => !known.has(s))).toEqual([])
	})

	it('the simulation finds something', () => {
		// Guard against a broken simulation passing everything vacuously.
		expect(visible.size).toBeGreaterThan(500)
	})

	it('every frontend string is visible to the bot extractor', () => {
		const invisible = [...frontend].filter((s) => !visible.has(s)).sort()
		expect(invisible, `${invisible.length} string(s) the Transifex bot cannot see. `
			+ "Call t('formvox', 'text') / n('formvox', 'one', 'many', count).").toEqual([])
	})

	it('no call omits the app id', () => {
		// t('Some text') works at runtime through a wrapper and is invisible to
		// both passes. Any literal first argument must be the app id.
		const offenders = []
		for (const [f, body] of sources) {
			for (const m of body.matchAll(/(?<![\w$'])[tn]\(\s*'((?:[^'\\]|\\.)*)'\s*[,)]/g)) {
				if (m[1] !== APP_ID) offenders.push(`${rel(f)}: ${m[0].slice(0, 60)}`)
			}
		}
		expect(offenders).toEqual([])
	})

	it('the text after the app id is a string literal', () => {
		// t('formvox', LABELS[key]) is invisible too: translate each literal
		// where the map is defined.
		const offenders = []
		for (const [f, body] of sources) {
			for (const m of body.matchAll(new RegExp(`(?<![\\w$])[tn]\\(\\s*'${APP_ID}'\\s*,\\s*([^'\\s])`, 'g'))) {
				offenders.push(`${rel(f)}: ${body.slice(m.index, m.index + 60).split('\n')[0]}`)
			}
		}
		expect(offenders).toEqual([])
	})

	it('no translation helper is named anything but t or n', () => {
		// xgettext only knows the keywords t and n.
		const offenders = []
		for (const [f, body] of sources) {
			for (const m of body.matchAll(/\b(?:const|let|var)\s+(\w+)\s*=\s*\([^)]*\)\s*=>\s*(?:\{\s*return\s+)?(?:translate(?:Plural)?|window\.t|OC\.L10N\.translate)\(/g)) {
				if (m[1] !== 't' && m[1] !== 'n') offenders.push(`${rel(f)}: ${m[1]}`)
			}
			for (const m of body.matchAll(/import\s*\{([^}]*)\}\s*from\s*'@nextcloud\/l10n'/g)) {
				for (const spec of m[1].split(',').map((x) => x.trim()).filter(Boolean)) {
					const local = spec.split(/\s+as\s+/).pop()
					if (/^translate(Plural)?\b/.test(spec) && local !== 't' && local !== 'n' && !/^translate(Plural)?$/.test(local)) {
						offenders.push(`${rel(f)}: import ${spec}`)
					}
				}
			}
		}
		expect(offenders).toEqual([])
	})

	it('one translated call per template line when markup separates them', () => {
		// The template regex is greedy per line: with two calls on one line it
		// hands xgettext a fragment like  'A') }}</strong> - {{ t('formvox', 'B'
		// and xgettext reads the "</strong" as the start of a regex literal, so
		// "B" is lost. Break such lines up.
		const offenders = []
		for (const [f, body] of sources) {
			const template = f.endsWith('.vue') && body.match(/<template>([\s\S]+)<\/template>/)
			if (!template) continue
			template[1].split('\n').forEach((line) => {
				const calls = [...line.matchAll(/\W[tn]\s*\(\s*'formvox'/g)]
				for (let i = 1; i < calls.length; i++) {
					if (line.slice(calls[i - 1].index, calls[i].index).includes('<')) {
						offenders.push(`${rel(f)}: ${line.trim().slice(0, 80)}`)
						break
					}
				}
			})
		}
		expect(offenders).toEqual([])
	})

	it('template hints are on one line, where the bot can read them', () => {
		// translationtool only looks at the call's own line and the line above,
		// so a <!-- TRANSLATORS … --> spread over two lines is lost.
		const offenders = []
		for (const [f, body] of sources) {
			body.split('\n').forEach((line, i) => {
				if (/<!--\s*TRANSLATORS/.test(line) && !line.includes('-->')) offenders.push(`${rel(f)}:${i + 1}`)
			})
		}
		expect(offenders).toEqual([])
	})

	it('the word TRANSLATORS appears only where a hint starts', () => {
		// xgettext starts a hint at the word TRANSLATORS anywhere in a comment, so
		// an ordinary comment that mentions it ("… keeps its TRANSLATORS hint")
		// right above a real hint is glued onto that hint in the bot's POT. In
		// prose, say "translator hint".
		const hintStart = /^\s*(?:\/\/|\/\*|\*|<!--|<\?php\s+\/\/)\s*TRANSLATORS:/
		const offenders = []
		const scanned = [
			...walk(path.join(ROOT, 'src'), ['.vue', '.js']),
			...walk(path.join(ROOT, 'lib'), ['.php']),
			...walk(path.join(ROOT, 'templates'), ['.php']),
			...fs.readdirSync(ROOT).filter((f) => f.endsWith('.js')).map((f) => path.join(ROOT, f)),
		]
		for (const f of scanned) {
			fs.readFileSync(f, 'utf8').split('\n').forEach((line, i) => {
				if (line.includes('TRANSLATORS') && !hintStart.test(line)) offenders.push(`${rel(f)}:${i + 1}: ${line.trim().slice(0, 70)}`)
			})
		}
		expect(offenders).toEqual([])
	})

	it('our extractor carries the hints into the POT', () => {
		// The hints only help if they reach translators; scripts/generate-pot.js
		// writes them as #. lines from this map.
		expect(src.hints.size).toBeGreaterThan(400)
	})

	it('PHP translates literals only', () => {
		// $l->t($text) is invisible to xgettext; the bot reads lib/ and templates/.
		const offenders = []
		for (const f of [...walk(path.join(ROOT, 'lib'), ['.php']), ...walk(path.join(ROOT, 'templates'), ['.php'])]) {
			const body = fs.readFileSync(f, 'utf8')
			for (const m of body.matchAll(/->[tn]\(\s*([^'"\s])/g)) {
				offenders.push(`${rel(f)}: ${body.slice(m.index, m.index + 50).split('\n')[0]}`)
			}
		}
		expect(offenders).toEqual([])
	})
})
