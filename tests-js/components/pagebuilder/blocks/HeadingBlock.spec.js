import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import HeadingBlock from '@/components/pagebuilder/blocks/HeadingBlock.vue'

const make = (block, props = {}) => mount(HeadingBlock, { props: { block, ...props } })

describe('HeadingBlock', () => {
	it('renders the heading text using the configured tag', () => {
		const w = make({ settings: { level: 'h3', text: 'Hello' } })
		const h = w.get('h3')
		expect(h.text()).toBe('Hello')
	})

	it('draws only a heading, whatever level the branding names (secu#8)', () => {
		for (const level of ['img', 'script', 'iframe', 'a']) {
			const w = make({ settings: { level, text: 'X' } })
			expect(w.find('h1').exists()).toBe(true)
			expect(w.find(level).exists()).toBe(false)
		}
	})

	it('defaults to an h1 tag', () => {
		const w = make({ settings: { text: 'X' } })
		expect(w.find('h1').exists()).toBe(true)
	})

	it('shows placeholder "Heading" in edit mode when text is empty', () => {
		const w = make({ settings: {} }, { editMode: true })
		expect(w.text()).toContain('Heading')
	})

	it('renders empty text (not the placeholder) when not in edit mode', () => {
		const w = make({ settings: {} })
		expect(w.get('h1').text()).toBe('')
	})

	it('applies a color style when set', () => {
		const w = make({ settings: { text: 'X', color: 'rgb(9, 9, 9)' } })
		expect(w.get('h1').attributes('style')).toContain('color: rgb(9, 9, 9)')
	})

	it('has no color style when color is not set', () => {
		const w = make({ settings: { text: 'X' } })
		const style = w.get('h1').attributes('style')
		expect(style === undefined || !style.includes('color')).toBe(true)
	})

	it('applies alignment class', () => {
		const w = make({ alignment: 'right', settings: { text: 'X' } })
		expect(w.get('.block-heading').classes()).toContain('align-right')
	})
})
