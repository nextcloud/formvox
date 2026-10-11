import { describe, it, expect, vi, afterEach } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Plurals in the shipped translation bundles, looked up the way Nextcloud does.
 *
 * Nextcloud loads l10n/<lang>.js, whose OC.L10N.register() puts the
 * translations and the language's plural function into @nextcloud/l10n's
 * registry; translatePlural() then looks a plural up under
 * "_<singular>_::_<plural>_". A plural stored under its bare singular — the
 * hand-made shape — is never found, and the English text shows instead.
 */
vi.mock('@nextcloud/l10n', async () => await import('../node_modules/@nextcloud/l10n/dist/index.mjs'))
const { translatePlural } = await import('@nextcloud/l10n')

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const APP = 'formvox'
const LANGS = fs.readdirSync(path.join(ROOT, 'l10n'))
	.filter((f) => f.endsWith('.js') && f !== 'en.js')
	.map((f) => f.replace(/\.js$/, ''))

// What core's OC.L10N.register() does with the bundle's third argument.
const pluralFunction = (form) => new Function('n', `var plural; var nplurals; ${form}; return Number(plural);`)

/** Load l10n/<lang>.js as Nextcloud does and return what it registered. */
function loadBundle(lang) {
	let registered = null
	const OC = { L10N: { register: (app, translations, form) => { registered = { app, translations, form } } } }
	new Function('OC', fs.readFileSync(path.join(ROOT, 'l10n', `${lang}.js`), 'utf8'))(OC)
	globalThis._oc_l10n_registry_translations[APP] = registered.translations
	globalThis._oc_l10n_registry_plural_functions[APP] = pluralFunction(registered.form)
	return registered
}

describe('plural translations at runtime', () => {
	afterEach(() => {
		delete globalThis._oc_l10n_registry_translations[APP]
		delete globalThis._oc_l10n_registry_plural_functions[APP]
	})

	it.each(LANGS)('%s: every plural is stored under the key Nextcloud looks up', (lang) => {
		const { app, translations, form } = loadBundle(lang)
		expect(app).toBe(APP)
		const misplaced = Object.entries(translations)
			.filter(([key, value]) => Array.isArray(value) && !/^_.*_::_.*_$/s.test(key))
			.map(([key]) => key)
		expect(misplaced).toEqual([])
		for (const [key, forms] of Object.entries(translations).filter(([, v]) => Array.isArray(v))) {
			const [, singular, plural] = key.match(/^_(.*)_::_(.*)_$/s)
			expect(translatePlural(APP, singular, plural, 2)).toBe(forms[pluralFunction(form)(2)].replace(/%n/g, '2'))
		}
	})

	it('nl: with the Nextcloud key, n=2 gives the plural and n=1 the singular', () => {
		const { translations } = loadBundle('nl')
		// No plural is translated yet, so add one to the real nl bundle.
		translations['_%n response_::_%n responses_'] = ['%n reactie', '%n reacties']
		expect(translatePlural(APP, '%n response', '%n responses', 2)).toBe('2 reacties')
		expect(translatePlural(APP, '%n response', '%n responses', 1)).toBe('1 reactie')
	})

	it('nl: under the bare singular the plural is not found (the bug this guards)', () => {
		const { translations } = loadBundle('nl')
		translations['%n response'] = ['%n reactie', '%n reacties']
		expect(translatePlural(APP, '%n response', '%n responses', 2)).toBe('2 responses')
	})
})
