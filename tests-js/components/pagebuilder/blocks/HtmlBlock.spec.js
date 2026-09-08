import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import HtmlBlock from '@/components/pagebuilder/blocks/HtmlBlock.vue'

const make = (block, props = {}) => mount(HtmlBlock, { props: { block, ...props } })

describe('HtmlBlock', () => {
	it('renders allowed sanitized HTML content', () => {
		const w = make({ settings: { content: '<p><strong>Hi</strong></p>' } })
		expect(w.html()).toContain('<strong>Hi</strong>')
	})

	it('strips disallowed attributes via DOMPurify', () => {
		const w = make({ settings: { content: '<p onclick="evil()">ok</p>' } })
		expect(w.html()).not.toContain('onclick')
		expect(w.html()).toContain('ok')
	})

	it('drops disallowed tags but keeps their allowed contents', () => {
		// <table> is not in ALLOWED_TAGS; DOMPurify unwraps it, keeping inner text.
		const w = make({ settings: { content: '<table><tr><td>cell</td></tr></table>' } })
		expect(w.html()).not.toContain('<table')
		expect(w.html()).toContain('cell')
	})

	it('shows the placeholder in edit mode when content is empty', () => {
		const w = make({ settings: {} }, { editMode: true })
		expect(w.get('.placeholder').text()).toBe('Enter HTML in settings')
	})

	it('renders nothing (no placeholder) when empty and not in edit mode', () => {
		const w = make({ settings: {} })
		expect(w.find('.placeholder').exists()).toBe(false)
		// The v-html container is only rendered when there is content.
		expect(w.get('.block-html').element.children).toHaveLength(0)
	})

	it('defaults alignment to left', () => {
		const w = make({ settings: { content: '<p>x</p>' } })
		expect(w.get('.block-html').classes()).toContain('align-left')
	})

	it('applies configured alignment', () => {
		const w = make({ alignment: 'center', settings: { content: '<p>x</p>' } })
		expect(w.get('.block-html').classes()).toContain('align-center')
	})
})
