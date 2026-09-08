import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ImageBlock from '@/components/pagebuilder/blocks/ImageBlock.vue'

const make = (block, props = {}) => mount(ImageBlock, { props: { block, ...props } })

describe('ImageBlock', () => {
	it('renders the image when imageUrl is set (view mode)', () => {
		const w = make({ id: 'b1', settings: { imageUrl: '/x.png', alt: 'pic' } })
		const img = w.get('img')
		expect(img.attributes('src')).toBe('/x.png')
		expect(img.attributes('alt')).toBe('pic')
	})

	it('renders no image when imageUrl is empty (view mode)', () => {
		const w = make({ id: 'b1', settings: {} })
		expect(w.find('img').exists()).toBe(false)
	})

	it('shows the upload button in edit mode when no image', () => {
		const w = make({ id: 'b1', settings: {} }, { editMode: true })
		expect(w.find('.image-upload').exists()).toBe(true)
		expect(w.text()).toContain('Upload image')
	})

	it('shows the preview + remove button in edit mode when image set', () => {
		const w = make({ id: 'b1', settings: { imageUrl: '/x.png' } }, { editMode: true })
		expect(w.find('.image-preview').exists()).toBe(true)
		expect(w.text()).toContain('Remove')
	})

	it('emits update clearing imageUrl/imageId when Remove is clicked', async () => {
		const block = { id: 'b1', settings: { imageUrl: '/x.png', imageId: 5, alt: 'a' } }
		const w = make(block, { editMode: true })
		await w.get('[data-stub="NcButton"]').trigger('click')
		const emitted = w.emitted('update')
		expect(emitted).toBeTruthy()
		expect(emitted.at(-1)[0].settings.imageUrl).toBeNull()
		expect(emitted.at(-1)[0].settings.imageId).toBeNull()
		expect(emitted.at(-1)[0].settings.alt).toBe('a')
	})

	it('emits upload-image with blockId and file on file select', async () => {
		const w = make({ id: 'b7', settings: {} }, { editMode: true })
		const file = new File(['data'], 'a.png', { type: 'image/png' })
		const input = w.get('input[type="file"]')
		Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
		await input.trigger('change')
		const emitted = w.emitted('upload-image')
		expect(emitted).toBeTruthy()
		expect(emitted.at(-1)[0].blockId).toBe('b7')
		expect(emitted.at(-1)[0].file).toBe(file)
	})

	it('does not emit upload-image when no file chosen', async () => {
		const w = make({ id: 'b7', settings: {} }, { editMode: true })
		const input = w.get('input[type="file"]')
		Object.defineProperty(input.element, 'files', { value: [], configurable: true })
		await input.trigger('change')
		expect(w.emitted('upload-image')).toBeFalsy()
	})

	it('applies alignment class (default center)', () => {
		const w = make({ id: 'b1', settings: {} })
		expect(w.get('.block-image').classes()).toContain('align-center')
	})
})
