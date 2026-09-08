import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import NewFormModal from '@/components/NewFormModal.vue'

/**
 * Characterization tests for NewFormModal — title/template state, the canSubmit
 * guard, the AI branch, and the create() payload emitted to the parent.
 */
describe('NewFormModal', () => {
	const mountModal = (props = {}) =>
		mount(NewFormModal, { props: { ...props } })

	beforeEach(() => {
		vi.restoreAllMocks()
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
	})

	it('defaults the title from the built-in template (blank → Untitled Form)', async () => {
		const wrapper = mountModal({ initialTemplate: 'blank' })
		await flushPromises()
		expect(wrapper.get('[data-stub="NcTextField"]').attributes('value')).toBe('Untitled Form')
	})

	it('uses the template-specific default title (survey → New Survey)', async () => {
		const wrapper = mountModal({ initialTemplate: 'survey' })
		await flushPromises()
		expect(wrapper.get('[data-stub="NcTextField"]').attributes('value')).toBe('New Survey')
	})

	it('shows the template label for the chosen template', async () => {
		const wrapper = mountModal({ initialTemplate: 'poll' })
		await flushPromises()
		expect(wrapper.text()).toContain('Poll')
	})

	it('does not render the AI section for a non-AI template', async () => {
		const wrapper = mountModal({ initialTemplate: 'blank' })
		await flushPromises()
		expect(wrapper.find('#ai-prompt-input').exists()).toBe(false)
		expect(wrapper.text()).toContain('Create')
	})

	it('renders the AI section and "Generate" label for the ai template', async () => {
		const wrapper = mountModal({ initialTemplate: 'ai' })
		await flushPromises()
		expect(wrapper.find('#ai-prompt-input').exists()).toBe(true)
		expect(wrapper.text()).toContain('Generate')
	})

	it('canSubmit: false with an empty title', async () => {
		const wrapper = mountModal({ initialTemplate: 'blank' })
		await flushPromises()
		wrapper.vm.title = ''
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(false)
	})

	it('canSubmit: true with a title for a non-AI template', async () => {
		const wrapper = mountModal({ initialTemplate: 'blank' })
		await flushPromises()
		expect(wrapper.vm.title).toBeTruthy()
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	it('canSubmit: false for AI when there is no description and no source file', async () => {
		const wrapper = mountModal({ initialTemplate: 'ai' })
		await flushPromises()
		// Title is defaulted, but AI needs a description or source.
		expect(wrapper.vm.title).toBeTruthy()
		expect(wrapper.vm.canSubmit).toBe(false)
	})

	it('canSubmit: true for AI once a description is entered', async () => {
		const wrapper = mountModal({ initialTemplate: 'ai' })
		await flushPromises()
		wrapper.vm.aiDescription = 'A survey about coffee'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	it('emits close when Cancel is clicked', async () => {
		const wrapper = mountModal({ initialTemplate: 'blank' })
		await flushPromises()
		const cancel = wrapper.findAllComponents({ name: 'NcButton' })
			.find((b) => b.text().includes('Cancel'))
		await cancel.vm.$emit('click')
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('posts the title/path and emits created with the server payload (blank template)', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { fileId: 42, title: 'Untitled Form' } })
		const wrapper = mountModal({ initialTemplate: 'blank' })
		await flushPromises()

		await wrapper.vm.create()
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/formvox/api/forms', {
			title: 'Untitled Form',
			path: '',
			template: null,
			adminTemplateId: null,
		})
		expect(wrapper.emitted('created')).toEqual([[{ fileId: 42, title: 'Untitled Form' }]])
	})

	it('passes the built-in template id in the create payload (survey)', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { fileId: 1 } })
		const wrapper = mountModal({ initialTemplate: 'survey' })
		await flushPromises()

		await wrapper.vm.create()
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/formvox/api/forms', expect.objectContaining({
			template: 'survey',
			adminTemplateId: null,
		}))
	})

	it('splits an admin: template id into adminTemplateId', async () => {
		// onMounted fetches templates for admin ids
		axios.get.mockResolvedValue({ data: { templates: [{ id: 'abc', title: 'Admin Tpl' }] } })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { fileId: 2 } })
		const wrapper = mountModal({ initialTemplate: 'admin:abc' })
		await flushPromises()

		expect(wrapper.get('[data-stub="NcTextField"]').attributes('value')).toBe('Admin Tpl')

		await wrapper.vm.create()
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/formvox/api/forms', expect.objectContaining({
			template: null,
			adminTemplateId: 'abc',
		}))
	})

	it('onCloseAttempt emits close only when not creating', async () => {
		const wrapper = mountModal({ initialTemplate: 'blank' })
		await flushPromises()
		wrapper.vm.onCloseAttempt()
		expect(wrapper.emitted('close')).toBeTruthy()
	})
})
