import { describe, it, expect } from 'vitest'
import { t, n } from '@/utils/l10n.js'

/**
 * Characterization tests for the l10n helpers. Under the test stub
 * (@nextcloud/l10n) translation is identity with {var} interpolation, so these
 * pin the app-name wiring and the var/plural forwarding.
 */
describe('l10n t()', () => {
	it('returns the text unchanged when there are no vars', () => {
		expect(t('Hello world')).toBe('Hello world')
	})

	it('interpolates named {vars}', () => {
		expect(t('Hello {name}', { name: 'Alice' })).toBe('Hello Alice')
	})

	it('interpolates several vars', () => {
		expect(t('{a} and {b}', { a: 'x', b: 'y' })).toBe('x and y')
	})

	it('leaves an unknown placeholder in place', () => {
		expect(t('Hi {missing}', { name: 'Alice' })).toBe('Hi {missing}')
	})

	it('defaults vars to {} so a bare call does not throw', () => {
		expect(t('Just text')).toBe('Just text')
	})
})

describe('l10n n()', () => {
	it('uses the singular form when count is 1', () => {
		expect(n('one item', '{count} items', 1)).toBe('one item')
	})

	it('uses the plural form when count is not 1', () => {
		expect(n('one item', '{count} items', 3)).toBe('{count} items')
	})

	it('interpolates vars into the chosen form', () => {
		expect(n('{count} item', '{count} items', 5, { count: 5 })).toBe('5 items')
	})

	it('uses plural for a count of 0', () => {
		expect(n('one item', 'many items', 0)).toBe('many items')
	})
})
