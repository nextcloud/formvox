import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import ImportModal from '@/components/ImportModal.vue'

/**
 * Characterization tests for the multi-step ImportModal (Microsoft Forms wizard).
 * Its local t() reads window.t and otherwise returns the raw English string, so
 * assertions match the untranslated text.
 */
describe('ImportModal', () => {
	const mountModal = (props = {}) =>
		mount(ImportModal, { props: { show: true, ...props } })

	beforeEach(() => {
		vi.restoreAllMocks()
		// checkMsConnection() runs on mount.
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { configured: true, connected: false } })
	})

	it('mounts on step 0 (choose source)', async () => {
		const wrapper = mountModal()
		await flushPromises()
		expect(wrapper.vm.currentStep).toBe(0)
		expect(wrapper.text()).toContain('Choose import source')
	})

	it('shows the 5-step MS Forms indicator labels', async () => {
		const wrapper = mountModal()
		await flushPromises()
		const labels = wrapper.vm.steps.map((s) => s.label)
		expect(labels).toEqual(['Source', 'Connect', 'Select', 'Options', 'Import'])
	})

	it('canProceed on step 0 is true (msforms is preselected)', async () => {
		const wrapper = mountModal()
		await flushPromises()
		expect(wrapper.vm.canProceed).toBe(true)
	})

	it('step 1 canProceed is false until connected', async () => {
		const wrapper = mountModal()
		await flushPromises()
		wrapper.vm.currentStep = 1
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canProceed).toBe(false)
		wrapper.vm.msConnected = true
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canProceed).toBe(true)
	})

	it('shows the not-configured warning on step 1 when MS is unconfigured', async () => {
		axios.get.mockResolvedValue({ data: { configured: false, connected: false } })
		const wrapper = mountModal()
		await flushPromises()
		wrapper.vm.currentStep = 1
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('is not configured')
	})

	it('shows the connected status + Disconnect when connected', async () => {
		axios.get.mockResolvedValue({ data: { configured: true, connected: true } })
		const wrapper = mountModal()
		await flushPromises()
		wrapper.vm.currentStep = 1
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('You are connected to Microsoft')
		expect(wrapper.text()).toContain('Disconnect')
	})

	it('loads the forms list when advancing from a connected step 1', async () => {
		axios.get
			.mockResolvedValueOnce({ data: { configured: true, connected: true } }) // mount check
			.mockResolvedValueOnce({ data: { forms: [{ id: 'f1', title: 'Feedback', responseCount: 3 }] } }) // list
		const wrapper = mountModal()
		await flushPromises()
		wrapper.vm.msConnected = true
		wrapper.vm.currentStep = 1
		await wrapper.vm.$nextTick()

		await wrapper.vm.nextStep()
		await flushPromises()

		expect(wrapper.vm.currentStep).toBe(2)
		expect(wrapper.vm.msFormsList).toEqual([{ id: 'f1', title: 'Feedback', responseCount: 3 }])
		expect(wrapper.text()).toContain('Feedback')
	})

	it('selects a form and marks step-2 canProceed', async () => {
		const wrapper = mountModal()
		await flushPromises()
		wrapper.vm.currentStep = 2
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canProceed).toBe(false)
		wrapper.vm.selectMsForm({ id: 'f1', title: 'Feedback' })
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.selectedMsForm).toEqual({ id: 'f1', title: 'Feedback' })
		expect(wrapper.vm.canProceed).toBe(true)
	})

	it('startImport posts the selected form + options, sets result and emits imported', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { fileId: 99, title: 'Feedback', questionsImported: 4, responsesImported: 3 },
		})
		const wrapper = mountModal()
		await flushPromises()
		wrapper.vm.selectMsForm({ id: 'f1', title: 'Feedback' })
		wrapper.vm.importOptions.path = '/Imports'
		wrapper.vm.importOptions.includeResponses = false
		await wrapper.vm.$nextTick()

		await wrapper.vm.startImport()
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/formvox/api/import/ms-forms/f1', {
			path: '/Imports',
			includeResponses: false,
		})
		expect(wrapper.vm.currentStep).toBe(4)
		expect(wrapper.vm.importResult).toMatchObject({ fileId: 99, questionsImported: 4 })
		expect(wrapper.emitted('imported')).toEqual([[{ fileId: 99, title: 'Feedback', questionsImported: 4, responsesImported: 3 }]])
		expect(wrapper.text()).toContain('Form imported successfully!')
	})

	it('surfaces the server error message on import failure', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue({ response: { data: { error: 'Quota exceeded' } } })
		const wrapper = mountModal()
		await flushPromises()
		wrapper.vm.selectMsForm({ id: 'f1', title: 'Feedback' })
		await wrapper.vm.$nextTick()

		await wrapper.vm.startImport()
		await flushPromises()

		expect(wrapper.vm.importError).toBe('Quota exceeded')
		expect(wrapper.text()).toContain('Quota exceeded')
	})

	it('prevStep decrements the current step', async () => {
		const wrapper = mountModal()
		await flushPromises()
		wrapper.vm.currentStep = 2
		await wrapper.vm.$nextTick()
		wrapper.vm.prevStep()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.currentStep).toBe(1)
	})

	it('emits close when NcModal closes', async () => {
		const wrapper = mountModal()
		await flushPromises()
		wrapper.getComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('disconnectMs clears the connected flag', async () => {
		axios.get.mockResolvedValue({ data: { configured: true, connected: true } })
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = mountModal()
		await flushPromises()
		expect(wrapper.vm.msConnected).toBe(true)
		await wrapper.vm.disconnectMs()
		await flushPromises()
		expect(wrapper.vm.msConnected).toBe(false)
	})
})
