import { describe, it, expect } from 'vitest'
import { sanitizeBlockHtml } from '@/utils/blockHtml'

// happy-dom drops the outermost element when parsing; a browser does not. A wrapper keeps the tests about the rest.
const clean = (html) => sanitizeBlockHtml(`<div>${html}</div>`)

/**
 * A branding block's HTML keeps how text looks, not where it sits or what it covers (L13).
 */
describe('utils/blockHtml', () => {
	it('keeps styling of text', () => {
		expect(clean('<p style="color: red; text-align: center; font-weight: bold">Hi</p>'))
			.toBe('<p style="color: red; text-align: center; font-weight: bold">Hi</p>')
	})

	it('drops placement and layering that could cover the page', () => {
		expect(clean('<div style="position: fixed; top: 0; left: 0; z-index: 9999; width: 100vw; color: blue">Log in</div>'))
			.toBe('<div style="color: blue">Log in</div>')
	})

	it('drops a style that has nothing safe left, and anything loading from elsewhere', () => {
		expect(clean('<p style="position: absolute">x</p>')).toBe('<p>x</p>')
		expect(clean('<p style="background-color: url(https://evil.example/x)">x</p>')).toBe('<p>x</p>')
	})

	it('keeps no class, and gives a link to a new tab noopener', () => {
		expect(clean('<span class="header-menu">x</span>')).toBe('<span>x</span>')
		expect(clean('<a href="https://example.com" target="_top">x</a>'))
			.toBe('<a href="https://example.com" target="_blank" rel="noopener noreferrer">x</a>')
	})

	it('leaves other sanitizing alone', async () => {
		const DOMPurify = (await import('dompurify')).default
		expect(DOMPurify.sanitize('<div><p style="position: fixed">x</p></div>')).toBe('<p style="position: fixed">x</p>')
	})
})
