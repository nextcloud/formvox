import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BlockRenderer from '@/components/pagebuilder/BlockRenderer.vue'

const render = (block, props = {}) => mount(BlockRenderer, { props: { block, ...props } })

describe('BlockRenderer', () => {
	it('dispatches type "text" to TextBlock', () => {
		const w = render({ type: 'text', settings: { content: 'hi' } })
		expect(w.findComponent({ name: 'TextBlock' }).exists()).toBe(true)
		expect(w.find('.block-text').exists()).toBe(true)
	})

	it('dispatches type "heading" to HeadingBlock', () => {
		const w = render({ type: 'heading', settings: { text: 'H' } })
		expect(w.findComponent({ name: 'HeadingBlock' }).exists()).toBe(true)
	})

	it('dispatches type "button" to ButtonBlock', () => {
		const w = render({ type: 'button', settings: {} })
		expect(w.findComponent({ name: 'ButtonBlock' }).exists()).toBe(true)
	})

	it('dispatches type "divider" to DividerBlock', () => {
		const w = render({ type: 'divider', settings: {} })
		expect(w.findComponent({ name: 'DividerBlock' }).exists()).toBe(true)
	})

	it('dispatches type "spacer" to SpacerBlock', () => {
		const w = render({ type: 'spacer', settings: {} })
		expect(w.findComponent({ name: 'SpacerBlock' }).exists()).toBe(true)
	})

	it('dispatches type "logo" to LogoBlock', () => {
		const w = render({ type: 'logo', settings: {} })
		expect(w.findComponent({ name: 'LogoBlock' }).exists()).toBe(true)
	})

	it('dispatches type "image" to ImageBlock', () => {
		const w = render({ type: 'image', settings: {} })
		expect(w.findComponent({ name: 'ImageBlock' }).exists()).toBe(true)
	})

	it('dispatches type "socialLinks" to SocialLinksBlock', () => {
		const w = render({ type: 'socialLinks', settings: {} })
		expect(w.findComponent({ name: 'SocialLinksBlock' }).exists()).toBe(true)
	})

	it('dispatches type "html" to HtmlBlock', () => {
		const w = render({ type: 'html', settings: { content: '<p>x</p>' } })
		expect(w.findComponent({ name: 'HtmlBlock' }).exists()).toBe(true)
	})

	it('dispatches type "progressBar" to ProgressBarBlock', () => {
		const w = render({ type: 'progressBar', settings: {} })
		expect(w.findComponent({ name: 'ProgressBarBlock' }).exists()).toBe(true)
	})

	it('renders nothing for an unknown type', () => {
		const w = render({ type: 'nope', settings: {} })
		expect(w.html()).toBe('')
	})

	it('passes editMode and globalStyles down to the block', () => {
		const w = render({ type: 'text', settings: { content: 'x' } }, {
			editMode: true,
			globalStyles: { primaryColor: '#123456' },
		})
		const child = w.findComponent({ name: 'TextBlock' })
		expect(child.props('editMode')).toBe(true)
		expect(child.props('globalStyles')).toEqual({ primaryColor: '#123456' })
	})

	it('re-emits the child update event', async () => {
		const block = { type: 'image', id: 'b1', settings: { imageUrl: '/x.png' } }
		const w = render(block, { editMode: true })
		await w.get('[data-stub="NcButton"]').trigger('click')
		expect(w.emitted('update')).toBeTruthy()
	})

	it('re-emits the child upload-image event', async () => {
		const w = render({ type: 'image', id: 'b1', settings: {} }, { editMode: true })
		const file = new File(['d'], 'a.png', { type: 'image/png' })
		const input = w.get('input[type="file"]')
		Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
		await input.trigger('change')
		expect(w.emitted('upload-image')).toBeTruthy()
	})
})
