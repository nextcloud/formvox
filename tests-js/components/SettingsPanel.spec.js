import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SettingsPanel from '@/components/SettingsPanel.vue'
import axios from '@nextcloud/axios'

/**
 * SettingsPanel, the "Collaboration" sidebar: static access-control help, and "Manage sharing", which shows the form in
 * the Files details view.
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

	it('opens the form in the Files details view, not in FormVox again', async () => {
		const hrefSetter = vi.fn()
		const originalLocation = window.location
		delete window.location
		window.location = { set href(v) { hrefSetter(v) }, get href() { return '' } }
		const get = vi.spyOn(axios, 'get')

		const wrapper = mount(SettingsPanel, { props: { fileId: 99 } })
		await wrapper.get('[data-stub="NcButton"]').trigger('click')

		// openfile=true would run FormVox's default action, which is opening the editor.
		expect(hrefSetter).toHaveBeenCalledWith('/f/99?opendetails=true&openfile=false')
		expect(get).not.toHaveBeenCalled()

		window.location = originalLocation
	})

	it('does the same where the legacy Files sidebar exists', async () => {
		const open = vi.fn()
		window.OCA = { Files: { Sidebar: { open, setActiveTab: vi.fn() } } }
		const hrefSetter = vi.fn()
		const originalLocation = window.location
		delete window.location
		window.location = { set href(v) { hrefSetter(v) }, get href() { return '' } }

		const wrapper = mount(SettingsPanel, { props: { fileId: 42 } })
		await wrapper.get('[data-stub="NcButton"]').trigger('click')

		expect(open).not.toHaveBeenCalled()
		expect(hrefSetter).toHaveBeenCalledWith('/f/42?opendetails=true&openfile=false')

		window.location = originalLocation
	})
})
