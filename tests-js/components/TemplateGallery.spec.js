import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import TemplateGallery from '@/components/TemplateGallery.vue'

// useRouter is called in setup(); provide a harmless stub.
vi.mock('vue-router', () => ({
	useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

/**
 * Characterization tests for TemplateGallery — renders the built-in template
 * cards, toggles collapsed state (persisted to localStorage), and emits
 * 'select-template' with the chosen id. AI / import cards are conditional.
 */
describe('TemplateGallery', () => {
	// happy-dom's localStorage here is not a working Storage, so install a small
	// in-memory shim the component (and these tests) can rely on.
	let store
	beforeEach(() => {
		store = {}
		const shim = {
			getItem: (k) => (k in store ? store[k] : null),
			setItem: (k, v) => { store[k] = String(v) },
			removeItem: (k) => { delete store[k] },
			clear: () => { store = {} },
		}
		vi.stubGlobal('localStorage', shim)
		vi.stubGlobal('sessionStorage', { ...shim, getItem: () => null, removeItem: () => {} })
	})
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	const cards = (wrapper) => wrapper.findAllComponents({ name: 'TemplateCard' })

	it('renders the five built-in template cards', async () => {
		const wrapper = mount(TemplateGallery)
		await flushPromises()
		// No AI (async status resolves empty) and no MS Forms card by default.
		expect(cards(wrapper)).toHaveLength(5)
	})

	it('renders the section title', () => {
		const wrapper = mount(TemplateGallery)
		expect(wrapper.get('.template-gallery__title').text()).toBe('New form')
	})

	it('emits select-template with the template id when a card is selected', async () => {
		const wrapper = mount(TemplateGallery)
		await flushPromises()
		// First built-in card is the survey template.
		await cards(wrapper)[0].vm.$emit('select')
		expect(wrapper.emitted('select-template').at(-1)).toEqual(['survey'])
	})

	it('shows the import card when MS Forms is configured', async () => {
		const wrapper = mount(TemplateGallery, { props: { msFormsConfigured: true } })
		await flushPromises()
		expect(cards(wrapper)).toHaveLength(6)
	})

	it('toggles collapsed state and hides the content', async () => {
		const wrapper = mount(TemplateGallery)
		await flushPromises()
		expect(wrapper.find('.template-gallery__content').exists()).toBe(true)
		await wrapper.get('[data-stub="NcButton"]').trigger('click')
		expect(wrapper.vm.isCollapsed).toBe(true)
		expect(localStorage.getItem('formvox-templates-collapsed')).toBe('true')
	})

	it('restores the collapsed state from localStorage on mount', async () => {
		localStorage.setItem('formvox-templates-collapsed', 'true')
		const wrapper = mount(TemplateGallery)
		await flushPromises()
		expect(wrapper.vm.isCollapsed).toBe(true)
	})

	it('shows the AI card when the AI status endpoint reports available', async () => {
		const axios = (await import('@nextcloud/axios')).default
		const spy = vi.spyOn(axios, 'get').mockImplementation((url) => {
			if (url.includes('/ai/status')) {
				return Promise.resolve({ data: { available: true } })
			}
			return Promise.resolve({ data: { templates: [] } })
		})
		const wrapper = mount(TemplateGallery)
		await flushPromises()
		// 5 built-ins + 1 AI card.
		expect(cards(wrapper)).toHaveLength(6)
		spy.mockRestore()
	})

	it('appends admin templates returned by the templates endpoint', async () => {
		const axios = (await import('@nextcloud/axios')).default
		const spy = vi.spyOn(axios, 'get').mockImplementation((url) => {
			if (url.includes('/api/templates')) {
				return Promise.resolve({ data: { templates: [{ id: 7, title: 'Admin One', description: 'd' }] } })
			}
			return Promise.resolve({ data: {} })
		})
		const wrapper = mount(TemplateGallery)
		await flushPromises()
		expect(cards(wrapper)).toHaveLength(6)
		await cards(wrapper).at(-1).vm.$emit('select')
		expect(wrapper.emitted('select-template').at(-1)).toEqual(['admin:7'])
		spy.mockRestore()
	})
})
