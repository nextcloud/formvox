import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import App from '@/views/App.vue'

// happy-dom under Vitest exposes `localStorage` as a plain object with no
// methods, so App's try/catch swallows every read/write. Install a real
// in-memory implementation so the tab-persistence contract is observable.
function installLocalStorage() {
	const store = new Map()
	const ls = {
		getItem: (k) => (store.has(k) ? store.get(k) : null),
		setItem: (k, v) => { store.set(k, String(v)) },
		removeItem: (k) => { store.delete(k) },
		clear: () => { store.clear() },
	}
	Object.defineProperty(globalThis, 'localStorage', {
		value: ls,
		configurable: true,
		writable: true,
	})
	return ls
}

/**
 * Characterization tests for the App (forms list) view.
 *
 * App fetches the forms list on mount (axios is stubbed to resolve {data: {}}),
 * so it renders the loading state first, then settles into the empty state.
 * We assert the derived tab/filter computeds and the initial render branches.
 */
describe('views/App', () => {
	beforeEach(() => {
		installLocalStorage()
		// Default: the on-mount load resolves to an empty list (a valid array).
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
	})

	const mountApp = (props = {}) => mount(App, { props })

	// Mount and let the on-mount forms fetch settle, then set a known list.
	const mountWithForms = async (forms) => {
		const wrapper = mountApp()
		await flushPromises()
		wrapper.vm.forms = forms
		await wrapper.vm.$nextTick()
		return wrapper
	}

	it('mounts without error and starts in the loading state', () => {
		const wrapper = mountApp()
		// loading is true synchronously on first render
		expect(wrapper.find('.loading-container').exists()).toBe(true)
	})

	it('defaults activeTab to "recent"', () => {
		const wrapper = mountApp()
		expect(wrapper.vm.activeTab).toBe('recent')
	})

	it('filteredForms is empty for a fresh (unloaded) list', () => {
		const wrapper = mountApp()
		expect(wrapper.vm.filteredForms).toEqual([])
	})

	it('tabs expose recent/myforms counts derived from forms', async () => {
		const wrapper = await mountWithForms([
			{ fileId: 1, modifiedAt: '2020-01-01' },
			{ fileId: 2, modifiedAt: '2021-01-01' },
		])
		const tabs = wrapper.vm.tabs
		expect(tabs.map(t => t.id)).toEqual(['recent', 'myforms'])
		expect(tabs.find(t => t.id === 'myforms').count).toBe(2)
		expect(tabs.find(t => t.id === 'recent').count).toBe(2)
	})

	it('recent tab sorts by modifiedAt desc and caps at 10', async () => {
		const many = Array.from({ length: 12 }, (_, i) => ({
			fileId: i,
			modifiedAt: `2020-01-${String(i + 1).padStart(2, '0')}`,
		}))
		const wrapper = await mountWithForms(many)
		wrapper.vm.activeTab = 'recent'
		await wrapper.vm.$nextTick()
		const filtered = wrapper.vm.filteredForms
		expect(filtered.length).toBe(10)
		// newest first
		expect(filtered[0].fileId).toBe(11)
	})

	it('myforms tab returns the full list', async () => {
		const wrapper = await mountWithForms([
			{ fileId: 1, modifiedAt: 'x' },
			{ fileId: 2, modifiedAt: 'y' },
		])
		wrapper.vm.activeTab = 'myforms'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.filteredForms.length).toBe(2)
	})

	it('deleteForm opens the delete dialog and remembers the target', async () => {
		const wrapper = mountApp()
		const form = { fileId: 42, modifiedAt: 'x' }
		wrapper.vm.deleteForm(form)
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showDeleteDialog).toBe(true)
	})

	it('cancelDelete closes the dialog', async () => {
		const wrapper = mountApp()
		wrapper.vm.deleteForm({ fileId: 1, modifiedAt: 'x' })
		wrapper.vm.cancelDelete()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showDeleteDialog).toBe(false)
	})

	it('openNewFormWithTemplate stores the template and shows the modal', async () => {
		const wrapper = mountApp()
		wrapper.vm.openNewFormWithTemplate('feedback')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.selectedTemplate).toBe('feedback')
		expect(wrapper.vm.showNewFormModal).toBe(true)
	})

	it('closeNewFormModal resets modal state', async () => {
		const wrapper = mountApp()
		wrapper.vm.openNewFormWithTemplate('feedback')
		wrapper.vm.closeNewFormModal()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showNewFormModal).toBe(false)
		expect(wrapper.vm.selectedTemplate).toBe(null)
	})

	it('emptyTabMessage returns a stable string', () => {
		const wrapper = mountApp()
		expect(wrapper.vm.emptyTabMessage).toBe('No forms found.')
	})

	it('getFormUrl builds the per-form edit URL', () => {
		const wrapper = mountApp()
		expect(wrapper.vm.getFormUrl({ fileId: 7 })).toBe('/apps/formvox/edit/7')
	})

	it('changing the active tab persists it to localStorage', async () => {
		const wrapper = mountApp()
		wrapper.vm.activeTab = 'myforms'
		await wrapper.vm.$nextTick()
		expect(localStorage.getItem('formvox-active-tab')).toBe('myforms')
	})
})
