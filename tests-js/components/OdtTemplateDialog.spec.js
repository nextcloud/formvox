import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import OdtTemplateDialog from '@/components/OdtTemplateDialog.vue'

/**
 * Characterization tests for OdtTemplateDialog — upload/download/delete of an
 * ODT export template, plus the placeholder list derived from form.questions.
 */
describe('OdtTemplateDialog', () => {
	const baseForm = {
		title: 'My form',
		questions: [
			{ id: 'q1', question: 'What is your name?' },
			{ id: 'q2', question: 'A very long question text that exceeds the fifty character truncation boundary for sure' },
		],
	}

	const mountDialog = (props = {}) =>
		mount(OdtTemplateDialog, {
			props: { show: true, fileId: 7, form: baseForm, ...props },
		})

	beforeEach(() => {
		vi.restoreAllMocks()
		// checkTemplate() runs on mount — default it to "no template".
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { hasTemplate: false } })
	})

	it('mounts and shows the upload heading when no template exists', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		expect(wrapper.text()).toContain('Upload template')
		expect(wrapper.text()).not.toContain('Template active')
	})

	it('renders the fixed placeholders and one {Qn} per question', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		const text = wrapper.text()
		expect(text).toContain('{form_title}')
		expect(text).toContain('{submitted_at}')
		expect(text).toContain('{respondent_name}')
		expect(text).toContain('{Q1}')
		expect(text).toContain('{Q2}')
		expect(text).not.toContain('{Q3}')
	})

	it('truncates question text longer than 50 chars with an ellipsis', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		expect(wrapper.text()).toContain('What is your name?')
		// The long question should be cut to 50 chars + '...' (substring(0, 50))
		expect(wrapper.text()).toContain('A very long question text that exceeds the fifty c...')
	})

	it('shows the active-template status when the server reports one', async () => {
		axios.get.mockResolvedValue({ data: { hasTemplate: true } })
		const wrapper = mountDialog()
		await flushPromises()
		expect(wrapper.text()).toContain('Template active')
		expect(wrapper.text()).toContain('Replace template')
	})

	it('treats a failed status check as "no template" (fail-safe)', async () => {
		axios.get.mockRejectedValue(new Error('boom'))
		const wrapper = mountDialog()
		await flushPromises()
		expect(wrapper.text()).not.toContain('Template active')
		expect(wrapper.text()).toContain('Upload template')
	})

	it('emits close when NcModal closes', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		wrapper.getComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('picks the selected file and only then shows the Upload button', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		// No file selected yet → no upload button
		expect(wrapper.find('[data-stub="NcButton"]').exists()).toBe(false)

		const file = new File(['x'], 'template.odt')
		const input = wrapper.get('input[type="file"]')
		Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
		await input.trigger('change')

		expect(wrapper.find('[data-stub="NcButton"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Upload')
	})

	it('uploads the selected file, flips to hasTemplate and emits template-changed=true', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = mountDialog()
		await flushPromises()

		const file = new File(['x'], 'template.odt')
		const input = wrapper.get('input[type="file"]')
		Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
		await input.trigger('change')

		await wrapper.getComponent({ name: 'NcButton' }).vm.$emit('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/formvox/api/form/7/odt-template', expect.any(FormData))
		expect(wrapper.text()).toContain('Template active')
		expect(wrapper.emitted('template-changed')).toEqual([[true]])
	})

	it('deletes the template when confirmed and emits template-changed=false', async () => {
		axios.get.mockResolvedValue({ data: { hasTemplate: true } })
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		vi.spyOn(window, 'confirm').mockReturnValue(true)
		const wrapper = mountDialog()
		await flushPromises()

		// The delete button is the "error" one in the status block.
		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		const deleteBtn = buttons.find((b) => b.text().includes('Delete template'))
		await deleteBtn.vm.$emit('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith('/apps/formvox/api/form/7/odt-template')
		expect(wrapper.emitted('template-changed')).toEqual([[false]])
		expect(wrapper.text()).not.toContain('Template active')
	})

	it('does not delete when the confirm dialog is cancelled', async () => {
		axios.get.mockResolvedValue({ data: { hasTemplate: true } })
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		vi.spyOn(window, 'confirm').mockReturnValue(false)
		const wrapper = mountDialog()
		await flushPromises()

		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		const deleteBtn = buttons.find((b) => b.text().includes('Delete template'))
		await deleteBtn.vm.$emit('click')
		await flushPromises()

		expect(del).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Template active')
	})
})
