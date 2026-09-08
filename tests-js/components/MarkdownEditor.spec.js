import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MarkdownEditor from '@/components/MarkdownEditor.vue'

/**
 * Characterization tests for MarkdownEditor. It wraps EasyMDE but renders its
 * own NC-style toolbar. Alignment buttons don't touch the markdown — they emit
 * update:align — so those branches are testable without a live CodeMirror.
 */
describe('MarkdownEditor', () => {
	it('renders a toolbar button for every action', () => {
		const wrapper = mount(MarkdownEditor)
		// 11 actions defined in the component.
		expect(wrapper.findAll('.md-editor__btn')).toHaveLength(11)
	})

	it('marks the alignment button matching the align prop as active', () => {
		const wrapper = mount(MarkdownEditor, { props: { align: 'center' } })
		const centerBtn = wrapper.findAll('.md-editor__btn').find(b => b.attributes('title') === 'Align center')
		expect(centerBtn.classes()).toContain('active')
		const leftBtn = wrapper.findAll('.md-editor__btn').find(b => b.attributes('title') === 'Align left')
		expect(leftBtn.classes()).not.toContain('active')
	})

	it('emits update:align when an alignment button is clicked (no markdown mutation)', async () => {
		const wrapper = mount(MarkdownEditor, { props: { align: 'left' } })
		const rightBtn = wrapper.findAll('.md-editor__btn').find(b => b.attributes('title') === 'Align right')
		await rightBtn.trigger('click')
		expect(wrapper.emitted('update:align').at(-1)).toEqual(['right'])
		// An alignment click must not emit a model-value change.
		expect(wrapper.emitted('update:model-value')).toBeFalsy()
	})

	it('renders a resize handle', () => {
		const wrapper = mount(MarkdownEditor)
		expect(wrapper.find('.md-editor__resize').exists()).toBe(true)
	})
})
