import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import LogoBlock from '@/components/pagebuilder/blocks/LogoBlock.vue'

const make = (block, props = {}) => mount(LogoBlock, { props: { block, ...props } })

describe('LogoBlock', () => {
	it('renders the logo image when imageUrl is set (view mode)', () => {
		const w = make({ id: 'b1', settings: { imageUrl: '/logo.png' } })
		expect(w.get('img').attributes('src')).toBe('/logo.png')
	})

	it('renders no image when imageUrl is empty (view mode)', () => {
		const w = make({ id: 'b1', settings: {} })
		expect(w.find('img').exists()).toBe(false)
	})

	it('shows the upload UI + hint in edit mode when no image', () => {
		const w = make({ id: 'b1', settings: {} }, { editMode: true })
		expect(w.find('.logo-upload').exists()).toBe(true)
		expect(w.text()).toContain('Upload logo')
		expect(w.text()).toContain('Max 2MB')
	})

	it('shows preview + remove in edit mode when image set', () => {
		const w = make({ id: 'b1', settings: { imageUrl: '/logo.png' } }, { editMode: true })
		expect(w.find('.logo-preview').exists()).toBe(true)
		expect(w.text()).toContain('Remove')
	})

	it('emits update clearing imageUrl/imageId when Remove clicked', async () => {
		const w = make({ id: 'b1', settings: { imageUrl: '/logo.png', imageId: 3 } }, { editMode: true })
		await w.get('[data-stub="NcButton"]').trigger('click')
		const emitted = w.emitted('update')
		expect(emitted.at(-1)[0].settings.imageUrl).toBeNull()
		expect(emitted.at(-1)[0].settings.imageId).toBeNull()
	})

	it('emits upload-image on file select', async () => {
		const w = make({ id: 'logoA', settings: {} }, { editMode: true })
		const file = new File(['d'], 'logo.png', { type: 'image/png' })
		const input = w.get('input[type="file"]')
		Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
		await input.trigger('change')
		expect(w.emitted('upload-image').at(-1)[0]).toEqual({ blockId: 'logoA', file })
	})

	it('applies alignment class (default center)', () => {
		const w = make({ id: 'b1', settings: {} })
		expect(w.get('.block-logo').classes()).toContain('align-center')
	})
})
