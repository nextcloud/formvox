import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { FORM_ICON_MONO_SVG, FORM_ICON_SVG } from '../../src/utils/formIcon.js'

const read = (file) => readFileSync(path.join(process.cwd(), file), 'utf8')
const svgOf = (text) => new DOMParser().parseFromString(text, 'image/svg+xml').documentElement

describe('form icon', () => {
	it('is the file type icon Files shows, so the + New menu matches the form in the list', () => {
		expect(FORM_ICON_SVG).toBe(read('img/filetypes/application-x-fvform.svg'))
		expect(svgOf(FORM_ICON_SVG).getAttribute('viewBox')).toBe('0 0 32 32')
		expect(FORM_ICON_SVG).toContain('#b6469d')
	})

	it('has a one-colour version that takes the text colour of the actions menu', () => {
		const paths = [...svgOf(FORM_ICON_MONO_SVG).querySelectorAll('path')]
		expect(paths.length).toBeGreaterThan(0)
		expect(paths.every(path => path.getAttribute('fill') === 'currentColor')).toBe(true)
	})

	it('draws the same shape as the app icon', () => {
		const appIcon = read('img/app.svg')
		const shape = (text) => svgOf(text).querySelector('path').getAttribute('d')
		expect(shape(FORM_ICON_MONO_SVG)).toBe(shape(appIcon))
	})
})
