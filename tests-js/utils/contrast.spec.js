import { describe, it, expect } from 'vitest'
import { parseHex, relativeLuminance, readableForeground } from '@/utils/contrast.js'

const ratio = (a, b) => (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)

describe('parseHex', () => {
	it('parses six-digit hex', () => {
		expect(parseHex('#fff8f0')).toEqual([255, 248, 240])
	})

	it('expands three-digit hex', () => {
		expect(parseHex('#abc')).toEqual([170, 187, 204])
	})

	it('accepts hex without the leading hash', () => {
		expect(parseHex('d8a906')).toEqual([216, 169, 6])
	})

	it.each([
		['rebeccapurple', 'a named colour'],
		['rgb(1, 2, 3)', 'an rgb() function'],
		['var(--color-primary-element)', 'a CSS variable'],
		['#12345', 'a five-digit value'],
		['#gggggg', 'non-hex characters'],
		['', 'an empty string'],
		[null, 'null'],
		[undefined, 'undefined'],
		[42, 'a number'],
	])('returns null for %s (%s)', (input) => {
		expect(parseHex(input)).toBeNull()
	})
})

describe('relativeLuminance', () => {
	it('is 0 for black and 1 for white', () => {
		expect(relativeLuminance('#000000')).toBeCloseTo(0, 5)
		expect(relativeLuminance('#ffffff')).toBeCloseTo(1, 5)
	})

	it('weights green above red above blue', () => {
		// The eye is far more sensitive to green, so channels of equal magnitude
		// do not have equal perceived brightness.
		expect(relativeLuminance('#00ff00')).toBeGreaterThan(relativeLuminance('#ff0000'))
		expect(relativeLuminance('#ff0000')).toBeGreaterThan(relativeLuminance('#0000ff'))
	})

	it('returns null for a colour it cannot parse', () => {
		expect(relativeLuminance('rebeccapurple')).toBeNull()
	})
})

describe('readableForeground', () => {
	it('picks dark text on a light background', () => {
		expect(readableForeground('#ffffff')).toBe('#1a1a1a')
		expect(readableForeground('#fff8f0')).toBe('#1a1a1a')
	})

	it('picks light text on a dark background', () => {
		expect(readableForeground('#000000')).toBe('#ffffff')
		expect(readableForeground('#102030')).toBe('#ffffff')
	})

	it('picks by contrast ratio, not by a luminance threshold', () => {
		// #d8a906 (the amber from #142) has luminance 0.43. A "below 0.5 means
		// light text" rule picks white at 2.19:1 — under the WCAG AA minimum of
		// 4.5:1 — where black scores 9.60:1. This is the case that makes a
		// luminance threshold the wrong tool.
		expect(readableForeground('#d8a906')).toBe('#1a1a1a')
	})

	it('always picks whichever foreground has the better contrast', () => {
		// A spread of hues and lightnesses, including the midtones where the
		// choice is least obvious.
		const backgrounds = [
			'#ffffff', '#000000', '#fff8f0', '#d8a906', '#808080', '#7f7f7f',
			'#0082c9', '#ff0000', '#00ff00', '#0000ff', '#102030', '#c0ffee',
			'#767676', '#777777', '#595959',
		]

		for (const background of backgrounds) {
			const chosen = readableForeground(background)
			const rejected = chosen === '#1a1a1a' ? '#ffffff' : '#1a1a1a'
			const bg = relativeLuminance(background)
			expect(
				ratio(bg, relativeLuminance(chosen)),
				`${background}: chose ${chosen} over ${rejected}`,
			).toBeGreaterThanOrEqual(ratio(bg, relativeLuminance(rejected)))
		}
	})

	it('reaches WCAG AA on colours where AA is reachable at all', () => {
		// Plenty of midtones cannot reach 4.5:1 against either black or white.
		// #808080 tops out at 4.41:1, and Nextcloud's own default blue #0082c9
		// at 4.17:1. That is a property of those colours rather than of this
		// function, and an argument for not offering a free colour field: a
		// picker will happily accept a value whose text can never be compliant.
		const reachable = ['#ffffff', '#000000', '#fff8f0', '#d8a906', '#102030']

		for (const background of reachable) {
			const foreground = readableForeground(background)
			const contrast = ratio(relativeLuminance(background), relativeLuminance(foreground))
			expect(contrast, `${background} on ${foreground}`).toBeGreaterThanOrEqual(4.5)
		}
	})

	it('returns null for a colour it cannot parse, so the caller leaves the token alone', () => {
		expect(readableForeground('var(--color-main-background)')).toBeNull()
	})
})
