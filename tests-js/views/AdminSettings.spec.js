import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import AdminSettings from '@/views/AdminSettings.vue'

/**
 * Characterization tests for the AdminSettings view.
 *
 * AdminSettings is tab-based and fetches statistics + licence stats on mount
 * (axios stubbed). We assert tab switching, the reactive settings state seeded
 * from props, and the licenseBanner computed which drives the subscription /
 * licence-problem messaging.
 */

const requiredProps = {
	initialBranding: {},
}

const mountAdmin = (props = {}) =>
	mount(AdminSettings, { props: { ...requiredProps, ...props } })

describe('views/AdminSettings', () => {
	beforeEach(() => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
	})

	it('mounts and defaults to the branding tab', () => {
		const wrapper = mountAdmin()
		expect(wrapper.vm.activeTab).toBe('branding')
	})

	it('switching tabs updates activeTab and the rendered content', async () => {
		const wrapper = mountAdmin()
		const settingsTabBtn = wrapper.findAll('.tab-button').find(b => b.text().includes('Settings'))
		await settingsTabBtn.trigger('click')
		expect(wrapper.vm.activeTab).toBe('settings')
		expect(wrapper.text()).toContain('Embed settings')
	})

	it('embedSettings seeds allowedDomains from props', () => {
		const wrapper = mountAdmin({ initialEmbedSettings: { allowedDomains: 'a.com,b.com' } })
		expect(wrapper.vm.embedSettings.allowedDomains).toBe('a.com,b.com')
	})

	it('embedSettings defaults allowedDomains to *', () => {
		const wrapper = mountAdmin()
		expect(wrapper.vm.embedSettings.allowedDomains).toBe('*')
	})

	it('msFormsSettings seeds from props with tenant fallback', () => {
		const wrapper = mountAdmin({
			initialMsFormsSettings: { clientId: 'abc', isConfigured: true, redirectUri: '/cb' },
		})
		expect(wrapper.vm.msFormsSettings.clientId).toBe('abc')
		expect(wrapper.vm.msFormsSettings.tenantId).toBe('common')
		expect(wrapper.vm.msFormsSettings.isConfigured).toBe(true)
		expect(wrapper.vm.msFormsSettings.clientSecret).toBe('')
	})

	it('telemetryEnabled derives from initialTelemetry (default true)', () => {
		const wrapper = mountAdmin()
		expect(wrapper.vm.telemetryEnabled).toBe(true)
		const off = mountAdmin({ initialTelemetry: { enabled: false, lastReport: null } })
		expect(off.vm.telemetryEnabled).toBe(false)
	})

	it('saveEmbedSettings posts to the embed endpoint', async () => {
		const wrapper = mountAdmin()
		await wrapper.vm.saveEmbedSettings()
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/formvox/api/settings/embed',
			expect.objectContaining({ allowedDomains: '*' }),
		)
		expect(wrapper.vm.savingEmbedSettings).toBe(false)
	})

	it('saveMsFormsSettings posts and marks configured when a client id is set', async () => {
		const wrapper = mountAdmin({ initialMsFormsSettings: { clientId: 'cid', isConfigured: true } })
		await wrapper.vm.saveMsFormsSettings()
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/formvox/api/settings/ms-forms',
			expect.objectContaining({ clientId: 'cid', tenantId: 'common' }),
		)
		expect(wrapper.vm.msFormsSettings.isConfigured).toBe(true)
		// secret is cleared after save
		expect(wrapper.vm.msFormsSettings.clientSecret).toBe('')
	})

	it('formatDate renders a localized string and empty for falsy input', () => {
		const wrapper = mountAdmin()
		expect(wrapper.vm.formatDate(null)).toBe('')
		expect(typeof wrapper.vm.formatDate(1_700_000_000)).toBe('string')
		expect(wrapper.vm.formatDate(1_700_000_000).length).toBeGreaterThan(0)
	})

	it('licenseBanner is null when no licence stats have loaded', () => {
		const wrapper = mountAdmin()
		expect(wrapper.vm.licenseBanner).toBe(null)
	})

	it('licenseBanner is null for a valid licence', async () => {
		const wrapper = mountAdmin()
		await flushPromises()
		wrapper.vm.licenseStats = { hasLicense: true, licenseValid: true }
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.licenseBanner).toBe(null)
	})

	it('licenseBanner warns when a licence is present but invalid', async () => {
		const wrapper = mountAdmin()
		await flushPromises()
		wrapper.vm.licenseStats = {
			hasLicense: true,
			licenseValid: false,
			licenseReason: 'License not found',
		}
		await wrapper.vm.$nextTick()
		const banner = wrapper.vm.licenseBanner
		expect(banner.type).toBe('warning')
		expect(banner.message).toContain('not known to the licence server')
	})

	it('licenseBanner reports an expired licence', async () => {
		const wrapper = mountAdmin()
		await flushPromises()
		wrapper.vm.licenseStats = {
			hasLicense: true,
			licenseValid: false,
			licenseReason: 'License has expired',
			licenseValidUntil: '2020-01-01T00:00:00Z',
		}
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.licenseBanner.message).toContain('expired')
	})

	// subscriptionNudge() (composables/useSubscriptionNudge.js) now calls
	// translate() correctly as t(app, text[, vars]); an Enterprise subscription
	// therefore surfaces a real info banner rather than the previous undefined.

	it('licenseBanner shows an info nudge for an Enterprise subscription', async () => {
		const wrapper = mountAdmin()
		await flushPromises()
		wrapper.vm.licenseStats = { hasLicense: false, hasValidSubscription: true }
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.licenseBanner).not.toBeNull()
		expect(wrapper.vm.licenseBanner.type).toBe('info')
		expect(wrapper.vm.licenseBanner.message).toContain('Nextcloud Enterprise subscription detected')
	})

	it('licenseBanner is null below the user-count nudge threshold', async () => {
		const wrapper = mountAdmin()
		await flushPromises()
		wrapper.vm.licenseStats = {
			hasLicense: false,
			supportNudgeUserThreshold: 100,
			totalUsers: 10,
		}
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.licenseBanner).toBe(null)
	})

	it('licenseBanner returns an info nudge above the user-count threshold', async () => {
		const wrapper = mountAdmin()
		await flushPromises()
		wrapper.vm.licenseStats = {
			hasLicense: false,
			supportNudgeUserThreshold: 100,
			totalUsers: 250,
		}
		await wrapper.vm.$nextTick()
		const banner = wrapper.vm.licenseBanner
		expect(banner).not.toBe(null)
		expect(banner.type).toBe('info')
		// {count} is interpolated now that translate() gets the app name.
		expect(banner.message).toContain('250 users')
	})
})
