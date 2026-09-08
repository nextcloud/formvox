import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PageBuilder from '@/components/pagebuilder/PageBuilder.vue'

/**
 * PageBuilder is exercised in `embedded` mode so save operations emit
 * `update:branding` instead of hitting axios — this lets us characterize
 * block add / remove / reorder and settings mutation without a network stub.
 */
const baseBranding = () => ({
	layout: { header: [], footer: [], thankYou: [] },
	globalStyles: { primaryColor: '#0082c9', backgroundColor: '#ffffff' },
})

const make = (branding = baseBranding(), props = {}) =>
	mount(PageBuilder, { props: { initialBranding: branding, embedded: true, ...props } })

const lastBranding = (w) => w.emitted('update:branding').at(-1)[0]

describe('PageBuilder', () => {
	it('mounts and shows the header title', () => {
		const w = make()
		expect(w.text()).toContain('Page builder')
	})

	it('starts on the header zone with an empty-zone message', () => {
		const w = make()
		expect(w.vm.activeZone).toBe('header')
		expect(w.text()).toContain('No blocks yet')
	})

	it('addBlock appends a block to the current zone and selects it', async () => {
		const w = make()
		w.vm.addBlock('heading')
		await w.vm.$nextTick()
		expect(w.vm.currentBlocks).toHaveLength(1)
		expect(w.vm.currentBlocks[0].type).toBe('heading')
		expect(w.vm.selectedBlockId).toBe(w.vm.currentBlocks[0].id)
	})

	it('addBlock emits update:branding with the new block (embedded)', async () => {
		const w = make()
		w.vm.addBlock('text')
		await w.vm.$nextTick()
		const b = lastBranding(w)
		expect(b.layout.header).toHaveLength(1)
		expect(b.layout.header[0].type).toBe('text')
	})

	it('addBlock uses type-specific default settings', () => {
		const w = make()
		w.vm.addBlock('button')
		const block = w.vm.currentBlocks[0]
		expect(block.settings).toEqual({ text: 'Click here', url: '', newTab: false })
		expect(block.alignment).toBe('center')
	})

	it('deleteBlock removes the block and clears selection', async () => {
		const w = make()
		w.vm.addBlock('text')
		const id = w.vm.currentBlocks[0].id
		w.vm.deleteBlock(id)
		await w.vm.$nextTick()
		expect(w.vm.currentBlocks).toHaveLength(0)
		expect(w.vm.selectedBlockId).toBeNull()
	})

	it('deleteBlock emits an update:branding without the removed block', async () => {
		const w = make()
		w.vm.addBlock('text')
		const id = w.vm.currentBlocks[0].id
		w.vm.deleteBlock(id)
		await w.vm.$nextTick()
		expect(lastBranding(w).layout.header).toHaveLength(0)
	})

	it('deleteBlock keeps other blocks and does not clear an unrelated selection', () => {
		const w = make()
		w.vm.addBlock('text')
		w.vm.addBlock('heading')
		const first = w.vm.currentBlocks[0].id
		const second = w.vm.currentBlocks[1].id
		w.vm.selectBlock(second)
		w.vm.deleteBlock(first)
		expect(w.vm.currentBlocks).toHaveLength(1)
		expect(w.vm.currentBlocks[0].id).toBe(second)
		expect(w.vm.selectedBlockId).toBe(second)
	})

	it('reorder via currentBlocks setter emits new order', async () => {
		const w = make()
		w.vm.addBlock('text')
		w.vm.addBlock('heading')
		const [a, b] = w.vm.currentBlocks
		w.vm.currentBlocks = [b, a]
		w.vm.onDragEnd()
		await w.vm.$nextTick()
		const order = lastBranding(w).layout.header.map(x => x.type)
		expect(order).toEqual(['heading', 'text'])
	})

	it('selectedBlock resolves the selected block object', () => {
		const w = make()
		w.vm.addBlock('text')
		const id = w.vm.currentBlocks[0].id
		w.vm.selectBlock(id)
		expect(w.vm.selectedBlock.id).toBe(id)
	})

	it('selectedBlock is null when nothing selected', () => {
		const w = make()
		expect(w.vm.selectedBlock).toBeNull()
	})

	it('updateBlockAlignment mutates the selected block alignment', async () => {
		const w = make()
		w.vm.addBlock('text')
		w.vm.selectBlock(w.vm.currentBlocks[0].id)
		w.vm.updateBlockAlignment('right')
		await w.vm.$nextTick()
		expect(w.vm.selectedBlock.alignment).toBe('right')
	})

	it('updateBlock replaces a block by id', async () => {
		const w = make()
		w.vm.addBlock('image')
		const original = w.vm.currentBlocks[0]
		const updated = { ...original, settings: { ...original.settings, imageUrl: '/new.png' } }
		w.vm.updateBlock(updated)
		await w.vm.$nextTick()
		expect(w.vm.currentBlocks[0].settings.imageUrl).toBe('/new.png')
	})

	it('switching zones changes currentBlocks', async () => {
		const w = make()
		w.vm.addBlock('text') // header
		w.vm.activeZone = 'footer'
		await w.vm.$nextTick()
		expect(w.vm.currentBlocks).toHaveLength(0)
	})

	it('normalizes null text settings to empty strings on load (#134)', () => {
		const branding = {
			layout: { header: [{ id: '1', type: 'heading', settings: { text: null, level: 'h1' } }], footer: [], thankYou: [] },
			globalStyles: { primaryColor: '#000', backgroundColor: '#fff' },
		}
		const w = make(branding)
		expect(w.vm.layout.header[0].settings.text).toBe('')
	})

	it('getSocialLinkUrl returns the stored url for a platform', () => {
		const w = make()
		w.vm.addBlock('socialLinks')
		w.vm.selectBlock(w.vm.currentBlocks[0].id)
		w.vm.setSocialLinkUrl('facebook', 'facebook.com/x')
		expect(w.vm.getSocialLinkUrl('facebook')).toBe('facebook.com/x')
	})

	it('getSocialLinkUrl returns empty string for an unset platform', () => {
		const w = make()
		w.vm.addBlock('socialLinks')
		w.vm.selectBlock(w.vm.currentBlocks[0].id)
		expect(w.vm.getSocialLinkUrl('twitter')).toBe('')
	})

	it('setSocialLinkUrl updates an existing platform link', () => {
		const w = make()
		w.vm.addBlock('socialLinks')
		w.vm.selectBlock(w.vm.currentBlocks[0].id)
		w.vm.setSocialLinkUrl('facebook', 'a.com')
		w.vm.setSocialLinkUrl('facebook', 'b.com')
		const links = w.vm.selectedBlock.settings.links
		expect(links).toHaveLength(1)
		expect(links[0].url).toBe('b.com')
	})

	it('applyTheme sets primary and background colors', () => {
		const w = make()
		w.vm.applyTheme({ primaryColor: '#111111', backgroundColor: '#222222' })
		expect(w.vm.globalStyles.primaryColor).toBe('#111111')
		expect(w.vm.globalStyles.backgroundColor).toBe('#222222')
	})

	it('isActivePreset matches the current styles case-insensitively', () => {
		const w = make()
		w.vm.globalStyles.primaryColor = '#AABBCC'
		w.vm.globalStyles.backgroundColor = '#FFFFFF'
		expect(w.vm.isActivePreset({ primaryColor: '#aabbcc', backgroundColor: '#ffffff' })).toBe(true)
		expect(w.vm.isActivePreset({ primaryColor: '#000000', backgroundColor: '#ffffff' })).toBe(false)
	})

	it('previewStyles reflects the background color', () => {
		const w = make()
		w.vm.globalStyles.backgroundColor = '#abcdef'
		expect(w.vm.previewStyles.backgroundColor).toBe('#abcdef')
	})
})
