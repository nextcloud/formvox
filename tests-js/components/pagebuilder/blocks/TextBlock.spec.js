import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import TextBlock from '@/components/pagebuilder/blocks/TextBlock.vue'

const make = (block, props = {}) => mount(TextBlock, { props: { block, ...props } })

describe('TextBlock', () => {
	it('renders the content text', () => {
		const w = make({ settings: { content: 'Hello world' } })
		expect(w.get('p').text()).toBe('Hello world')
	})

	it('shows placeholder in edit mode when content empty', () => {
		const w = make({ settings: {} }, { editMode: true })
		expect(w.get('.placeholder').text()).toContain('Enter text')
	})

	it('renders no paragraph when empty and not in edit mode', () => {
		const w = make({ settings: {} })
		expect(w.find('p').exists()).toBe(false)
	})

	it('applies a color style when set', () => {
		const w = make({ settings: { content: 'x', color: 'rgb(7, 8, 9)' } })
		expect(w.get('p').attributes('style')).toContain('color: rgb(7, 8, 9)')
	})

	it('defaults alignment to left', () => {
		const w = make({ settings: { content: 'x' } })
		expect(w.get('.block-text').classes()).toContain('align-left')
	})

	it('applies configured alignment', () => {
		const w = make({ alignment: 'right', settings: { content: 'x' } })
		expect(w.get('.block-text').classes()).toContain('align-right')
	})
})
