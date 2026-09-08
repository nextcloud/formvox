import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ButtonBlock from '@/components/pagebuilder/blocks/ButtonBlock.vue'

const make = (block, props = {}) => mount(ButtonBlock, { props: { block, ...props } })

describe('ButtonBlock', () => {
	it('renders the settings text', () => {
		const w = make({ settings: { text: 'Go now' } })
		expect(w.get('a.button-link').text()).toBe('Go now')
	})

	it('falls back to "Button" label when no text', () => {
		const w = make({ settings: {} })
		expect(w.get('a.button-link').text()).toBe('Button')
	})

	it('normalizes a bare URL to https:// (non-edit mode)', () => {
		const w = make({ settings: { url: 'example.com' } })
		expect(w.get('a').attributes('href')).toBe('https://example.com')
	})

	it('keeps an already-qualified URL as-is', () => {
		const w = make({ settings: { url: 'mailto:a@b.com' } })
		expect(w.get('a').attributes('href')).toBe('mailto:a@b.com')
	})

	it('uses # for an empty URL', () => {
		const w = make({ settings: {} })
		expect(w.get('a').attributes('href')).toBe('#')
	})

	it('has no href in edit mode', () => {
		const w = make({ settings: { url: 'example.com' } }, { editMode: true })
		expect(w.get('a').attributes('href')).toBeUndefined()
	})

	it('applies the alignment class from block.alignment', () => {
		const w = make({ alignment: 'right', settings: {} })
		expect(w.get('.block-button').classes()).toContain('align-right')
	})

	it('defaults alignment to center', () => {
		const w = make({ settings: {} })
		expect(w.get('.block-button').classes()).toContain('align-center')
	})

	it('target is _blank when newTab is set', () => {
		const w = make({ settings: { newTab: true } })
		expect(w.get('a').attributes('target')).toBe('_blank')
	})

	it('target is _self by default', () => {
		const w = make({ settings: {} })
		expect(w.get('a').attributes('target')).toBe('_self')
	})

	it('uses settings background color in the inline style', () => {
		const w = make({ settings: { backgroundColor: 'rgb(255, 0, 0)' } })
		expect(w.get('a').attributes('style')).toContain('background-color: rgb(255, 0, 0)')
	})

	it('falls back to globalStyles.primaryColor for background', () => {
		const w = make({ settings: {} }, { globalStyles: { primaryColor: 'rgb(1, 2, 3)' } })
		expect(w.get('a').attributes('style')).toContain('background-color: rgb(1, 2, 3)')
	})
})
