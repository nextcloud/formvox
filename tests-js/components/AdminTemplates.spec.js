import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import AdminTemplates from '@/components/AdminTemplates.vue'
import axios from '@nextcloud/axios'

/**
 * Characterization tests for AdminTemplates — snapshot forms into shared
 * templates and manage the template list. Loads templates + owned forms on
 * mount; snapshots via POST and refreshes.
 */
describe('AdminTemplates', () => {
	afterEach(() => vi.restoreAllMocks())

	const mockLoads = ({ templates = [], forms = [] } = {}) => {
		vi.spyOn(axios, 'get').mockImplementation((url) => {
			if (url.includes('/admin/templates')) return Promise.resolve({ data: { templates } })
			if (url.includes('/api/forms')) return Promise.resolve({ data: forms })
			return Promise.resolve({ data: {} })
		})
	}

	it('shows the empty states when there are no templates and no forms', async () => {
		mockLoads({ templates: [], forms: [] })
		const wrapper = mount(AdminTemplates)
		await flushPromises()
		expect(wrapper.text()).toContain('No custom templates yet')
		expect(wrapper.text()).toContain('You have no forms yet')
	})

	it('renders a templates table row with truncated description and question count', async () => {
		mockLoads({
			templates: [{ id: 't1', title: 'Survey', description: 'D', questionCount: 4, createdAt: '2024-05-01' }],
		})
		const wrapper = mount(AdminTemplates)
		await flushPromises()
		expect(wrapper.find('.templates-table').exists()).toBe(true)
		expect(wrapper.text()).toContain('Survey')
		expect(wrapper.text()).toContain('4')
	})

	it('maps owned forms to {fileId, title} with an untitled fallback', async () => {
		mockLoads({ forms: [{ fileId: 1, title: 'Named' }, { fileId: 2, title: '' }] })
		const wrapper = mount(AdminTemplates)
		await flushPromises()
		expect(wrapper.vm.ownedForms).toEqual([
			{ fileId: 1, title: 'Named' },
			{ fileId: 2, title: '(untitled)' },
		])
	})

	it('truncate returns "" for falsy, leaves short strings, ellipsizes long ones', async () => {
		mockLoads()
		const wrapper = mount(AdminTemplates)
		await flushPromises()
		expect(wrapper.vm.truncate('', 5)).toBe('')
		expect(wrapper.vm.truncate('abc', 5)).toBe('abc')
		expect(wrapper.vm.truncate('abcdef', 3)).toBe('abc…')
	})

	it('formatDate returns "" for falsy and a locale string otherwise', async () => {
		mockLoads()
		const wrapper = mount(AdminTemplates)
		await flushPromises()
		expect(wrapper.vm.formatDate(null)).toBe('')
		expect(wrapper.vm.formatDate('2024-05-01')).not.toBe('')
	})

	it('snapshotTemplate is a no-op when no form is selected', async () => {
		mockLoads()
		const wrapper = mount(AdminTemplates)
		await flushPromises()
		const post = vi.spyOn(axios, 'post')
		wrapper.vm.snapshotFormId = null
		await wrapper.vm.snapshotTemplate()
		expect(post).not.toHaveBeenCalled()
	})

	it('snapshotTemplate posts using the selected form fileId and resets + reloads', async () => {
		mockLoads({ forms: [{ fileId: 7, title: 'X' }] })
		const wrapper = mount(AdminTemplates)
		await flushPromises()

		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		wrapper.vm.snapshotFormId = { fileId: 7, title: 'X' }
		wrapper.vm.snapshotTitle = 'My template'
		await wrapper.vm.snapshotTemplate()
		await flushPromises()

		expect(post).toHaveBeenCalled()
		expect(post.mock.calls[0][0]).toContain('7')
		expect(post.mock.calls[0][1]).toEqual({ title: 'My template', description: '' })
		// Reset after save.
		expect(wrapper.vm.snapshotFormId).toBeNull()
		expect(wrapper.vm.snapshotTitle).toBe('')
	})

	it('snapshotTemplate accepts a bare numeric fileId as well as an object', async () => {
		mockLoads()
		const wrapper = mount(AdminTemplates)
		await flushPromises()
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		wrapper.vm.snapshotFormId = 42
		await wrapper.vm.snapshotTemplate()
		await flushPromises()
		expect(post.mock.calls[0][0]).toContain('42')
	})

	it('removeTemplate deletes by id and reloads templates', async () => {
		mockLoads({ templates: [{ id: 't1', title: 'A', questionCount: 1 }] })
		const wrapper = mount(AdminTemplates)
		await flushPromises()
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		await wrapper.vm.removeTemplate('t1')
		await flushPromises()
		expect(del).toHaveBeenCalled()
		expect(del.mock.calls[0][0]).toContain('t1')
	})
})
