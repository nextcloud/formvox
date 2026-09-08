import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SupportSettings from '@/components/SupportSettings.vue'
import axios from '@nextcloud/axios'

/**
 * Characterization tests for SupportSettings — subscription key + telemetry.
 * Loads license stats on mount, derives a locale-aware pricing URL, maps
 * license-refusal reasons to distinct messages, and drives telemetry toggling.
 */
const mountIt = (props = {}) =>
	mount(SupportSettings, { props })

describe('SupportSettings', () => {
	beforeEach(() => {
		// Default: license stats load returns nothing useful.
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { success: false } })
	})
	afterEach(() => {
		vi.restoreAllMocks()
		document.documentElement.lang = ''
	})

	it('mounts and shows the support heading', async () => {
		const wrapper = mountIt()
		await flushPromises()
		expect(wrapper.text()).toContain('Support FormVox')
		expect(wrapper.text()).toContain('Subscription key')
	})

	it('pricingUrl is the English page by default', async () => {
		const wrapper = mountIt()
		await flushPromises()
		expect(wrapper.vm.pricingUrl).toBe('https://voxcloud.nl/en/pricing/#formvox')
	})

	it('pricingUrl is the Dutch page when document lang is nl', async () => {
		document.documentElement.lang = 'nl-NL'
		const wrapper = mountIt()
		await flushPromises()
		expect(wrapper.vm.pricingUrl).toBe('https://voxcloud.nl/pricing/#formvox')
	})

	it('licenseProblem is null when there is no license or it is valid', async () => {
		const wrapper = mountIt()
		await flushPromises()
		wrapper.vm.licenseStats = null
		expect(wrapper.vm.licenseProblem).toBeNull()
		wrapper.vm.licenseStats = { hasLicense: true, licenseValid: true }
		expect(wrapper.vm.licenseProblem).toBeNull()
	})

	it('licenseProblem maps expiry, not-found, in-use, inactive reasons distinctly', async () => {
		const wrapper = mountIt()
		await flushPromises()

		wrapper.vm.licenseStats = { hasLicense: true, licenseValid: false, licenseReason: 'License not found' }
		expect(wrapper.vm.licenseProblem).toContain('not known to the licence server')

		wrapper.vm.licenseStats = { hasLicense: true, licenseValid: false, licenseReason: 'License already in use elsewhere' }
		expect(wrapper.vm.licenseProblem).toContain('already registered to another')

		wrapper.vm.licenseStats = { hasLicense: true, licenseValid: false, licenseReason: 'License is inactive' }
		expect(wrapper.vm.licenseProblem).toContain('deactivated')

		wrapper.vm.licenseStats = { hasLicense: true, licenseValid: false, licenseReason: 'License not yet valid' }
		expect(wrapper.vm.licenseProblem).toContain('not valid yet')
	})

	it('licenseProblem includes the formatted expiry date when available', async () => {
		const wrapper = mountIt()
		await flushPromises()
		wrapper.vm.licenseStats = {
			hasLicense: true,
			licenseValid: false,
			licenseReason: 'License has expired',
			licenseValidUntil: '2024-01-15T00:00:00Z',
		}
		const msg = wrapper.vm.licenseProblem
		expect(msg).toContain('expired on')
		// The {date} placeholder must be substituted, not left literal.
		expect(msg).not.toContain('{date}')
	})

	it('licenseProblem falls back to the raw reason for unrecognised reasons', async () => {
		const wrapper = mountIt()
		await flushPromises()
		wrapper.vm.licenseStats = { hasLicense: true, licenseValid: false, licenseReason: 'Weird backend thing' }
		expect(wrapper.vm.licenseProblem).toContain('Weird backend thing')
	})

	it('saveLicenseKey shows an error and skips the network when the key is blank', async () => {
		const wrapper = mountIt()
		await flushPromises()
		const post = vi.spyOn(axios, 'post')
		wrapper.vm.licenseKey = '   '
		await wrapper.vm.saveLicenseKey()
		expect(post).not.toHaveBeenCalled()
		expect(wrapper.vm.message).toContain('Please enter a subscription key')
	})

	it('saveLicenseKey activates and emits license-changed on a valid key', async () => {
		const wrapper = mountIt()
		await flushPromises()
		wrapper.vm.licenseKey = 'FVOX-1234'
		vi.spyOn(axios, 'post').mockImplementation((url) => {
			if (url.includes('/settings/license')) return Promise.resolve({ data: { success: true } })
			if (url.includes('/license/validate')) return Promise.resolve({ data: { success: true, validation: { valid: true } } })
			if (url.includes('/license/update-usage')) return Promise.resolve({ data: { success: true } })
			return Promise.resolve({ data: {} })
		})
		await wrapper.vm.saveLicenseKey()
		await flushPromises()
		expect(wrapper.vm.message).toContain('Subscription activated')
		expect(wrapper.emitted('license-changed')).toBeTruthy()
	})

	it('toggleTelemetry sets the flag on success', async () => {
		const wrapper = mountIt()
		await flushPromises()
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		await wrapper.vm.toggleTelemetry(true)
		await flushPromises()
		expect(wrapper.vm.telemetryEnabled).toBe(true)
	})

	it('sendTelemetryNow records success and updates the last-report timestamp', async () => {
		const wrapper = mountIt({ initialTelemetryEnabled: true })
		await flushPromises()
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { success: true } })
		await wrapper.vm.sendTelemetryNow()
		await flushPromises()
		expect(wrapper.vm.telemetryMessage).toContain('sent successfully')
		expect(wrapper.vm.telemetryMessageType).toBe('success')
		expect(typeof wrapper.vm.telemetryLastReport).toBe('number')
	})

	it('sendTelemetryNow maps a connection failure to a friendly message', async () => {
		const wrapper = mountIt({ initialTelemetryEnabled: true })
		await flushPromises()
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { success: false, reason: 'server_error', message: 'could not resolve host' } })
		await wrapper.vm.sendTelemetryNow()
		await flushPromises()
		expect(wrapper.vm.telemetryMessage).toContain('Could not reach the telemetry server')
		expect(wrapper.vm.telemetryMessageType).toBe('error')
	})

	it('formatDate returns "Never" for a falsy timestamp', async () => {
		const wrapper = mountIt()
		await flushPromises()
		expect(wrapper.vm.formatDate(null)).toBe('Never')
	})

	it('formatIsoDate returns empty string for invalid input', async () => {
		const wrapper = mountIt()
		await flushPromises()
		expect(wrapper.vm.formatIsoDate('')).toBe('')
		expect(wrapper.vm.formatIsoDate('not-a-date')).toBe('')
	})

	it('populates the masked key on load when the user has not edited it', async () => {
		axios.get.mockResolvedValue({
			data: { success: true, hasLicense: true, licenseKeyMasked: 'FVOX-****-1234' },
		})
		const wrapper = mountIt()
		await flushPromises()
		expect(wrapper.vm.licenseKey).toBe('FVOX-****-1234')
	})
})
