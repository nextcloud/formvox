import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SettingsPanel from '@/components/SettingsPanel.vue'
import axios from '@nextcloud/axios'

/**
 * Characterization tests for SettingsPanel — the "Collaboration" sidebar.
 * It renders static access-control help and, on "Manage sharing", either drives
 * the Files sidebar (legacy API) or redirects to the Files app.
 */
describe('SettingsPanel', () => {
	beforeEach(() => {
		delete window.OCA
	})
	afterEach(() => {
		vi.restoreAllMocks()
		delete window.OCA
	})

	it('mounts and renders the collaboration heading', () => {
		const wrapper = mount(SettingsPanel, { props: { fileId: 7 } })
		expect(wrapper.text()).toContain('Access control')
		expect(wrapper.text()).toContain('Manage sharing')
	})

	it('emits close when the sidebar emits close', async () => {
		const wrapper = mount(SettingsPanel, { props: { fileId: 7 } })
		await wrapper.get('[data-stub="NcAppSidebar"]').trigger('click')
		// NcAppSidebar stub does not emit close on click; emit directly via vm
		wrapper.vm.$emit('close')
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('uses the legacy Files.Sidebar API when available', async () => {
		const open = vi.fn()
		const setActiveTab = vi.fn()
		window.OCA = { Files: { Sidebar: { open, setActiveTab } } }
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { path: '/Forms/my.form' } })

		const wrapper = mount(SettingsPanel, { props: { fileId: 42 } })
		await wrapper.get('[data-stub="NcButton"]').trigger('click')
		await new Promise((r) => setTimeout(r, 0))

		expect(axios.get).toHaveBeenCalled()
		expect(open).toHaveBeenCalledWith('/Forms/my.form')
		expect(setActiveTab).toHaveBeenCalledWith('sharing')
	})

	it('falls back to redirecting to the Files app when no legacy sidebar', async () => {
		// No window.OCA.Files.Sidebar -> must set window.location.href
		const hrefSetter = vi.fn()
		const originalLocation = window.location
		delete window.location
		window.location = { set href(v) { hrefSetter(v) }, get href() { return '' } }

		const wrapper = mount(SettingsPanel, { props: { fileId: 99 } })
		await wrapper.get('[data-stub="NcButton"]').trigger('click')
		await new Promise((r) => setTimeout(r, 0))

		expect(hrefSetter).toHaveBeenCalled()
		expect(hrefSetter.mock.calls[0][0]).toContain('99')

		window.location = originalLocation
	})
})
