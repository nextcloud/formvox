import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import IntegrationSettings from '@/components/IntegrationSettings.vue'
import axios from '@nextcloud/axios'

/**
 * Characterization tests for IntegrationSettings — API keys + webhooks manager.
 * Reads existing keys/hooks from props.form.settings (masking secrets), and
 * creates/deletes them via axios while mutating the local form object.
 */
const baseForm = (overrides = {}) => ({
	settings: {
		api_keys: [],
		webhooks: [],
		...overrides,
	},
})

const mountIt = (form) =>
	mount(IntegrationSettings, { props: { fileId: 5, form: form || baseForm() } })

describe('IntegrationSettings', () => {
	afterEach(() => vi.restoreAllMocks())

	it('renders existing API keys from form settings, hiding the hash', () => {
		const form = baseForm({
			api_keys: [{ id: 'k1', name: 'Zapier', permissions: ['read_form'], hash: 'SECRET' }],
		})
		const wrapper = mountIt(form)
		expect(wrapper.text()).toContain('Zapier')
		expect(wrapper.text()).toContain('k1')
		expect(wrapper.text()).not.toContain('SECRET')
	})

	it('renders existing webhooks from form settings, hiding the secret', () => {
		const form = baseForm({
			webhooks: [{ id: 'w1', name: 'Hook', url: 'https://x.test/h', events: ['response.created'], enabled: true, secret: 'S3CR3T' }],
		})
		const wrapper = mountIt(form)
		expect(wrapper.text()).toContain('Hook')
		expect(wrapper.text()).toContain('https://x.test/h')
		expect(wrapper.text()).not.toContain('S3CR3T')
	})

	it('formatPermissions maps known perms to labels and joins them', () => {
		const wrapper = mountIt()
		expect(wrapper.vm.formatPermissions(['read_form', 'write_responses'])).toBe('Read, Write')
		expect(wrapper.vm.formatPermissions(['unknown'])).toBe('unknown')
	})

	it('formatEvents returns "All events" for empty and maps known events', () => {
		const wrapper = mountIt()
		expect(wrapper.vm.formatEvents([])).toBe('All events')
		expect(wrapper.vm.formatEvents(null)).toBe('All events')
		expect(wrapper.vm.formatEvents(['response.created', 'response.deleted'])).toBe('Created, Deleted')
	})

	it('createApiKey posts permissions, shows the new key once, and appends to the form', async () => {
		const form = baseForm()
		const wrapper = mountIt(form)
		wrapper.vm.newKeyName = 'CI'
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'new1', name: 'CI', permissions: ['read_form', 'read_responses'], key: 'PLAINKEY' },
		})
		await wrapper.vm.createApiKey()
		await flushPromises()

		const body = axios.post.mock.calls[0][1]
		expect(body.name).toBe('CI')
		// Defaults: read_form + read_responses on.
		expect(body.permissions).toEqual(['read_form', 'read_responses'])
		// The one-time plaintext key is exposed.
		expect(wrapper.vm.newApiKey.key).toBe('PLAINKEY')
		// Appended to the local form (with hash hidden, no plaintext key).
		expect(form.settings.api_keys).toHaveLength(1)
		expect(form.settings.api_keys[0].hash).toBe('[hidden]')
		// Name field reset.
		expect(wrapper.vm.newKeyName).toBe('')
	})

	it('createApiKey is a no-op when the name is empty', async () => {
		const wrapper = mountIt()
		const post = vi.spyOn(axios, 'post')
		wrapper.vm.newKeyName = ''
		await wrapper.vm.createApiKey()
		expect(post).not.toHaveBeenCalled()
	})

	it('deleteApiKey removes the key from the form when confirmed', async () => {
		const form = baseForm({
			api_keys: [{ id: 'k1', name: 'A', permissions: [] }, { id: 'k2', name: 'B', permissions: [] }],
		})
		const wrapper = mountIt(form)
		vi.spyOn(window, 'confirm').mockReturnValue(true)
		vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		await wrapper.vm.deleteApiKey('k1')
		await flushPromises()
		expect(form.settings.api_keys.map(k => k.id)).toEqual(['k2'])
	})

	it('deleteApiKey does nothing when the confirm is declined', async () => {
		const form = baseForm({ api_keys: [{ id: 'k1', name: 'A', permissions: [] }] })
		const wrapper = mountIt(form)
		vi.spyOn(window, 'confirm').mockReturnValue(false)
		const del = vi.spyOn(axios, 'delete')
		await wrapper.vm.deleteApiKey('k1')
		expect(del).not.toHaveBeenCalled()
		expect(form.settings.api_keys).toHaveLength(1)
	})

	it('createWebhook posts events, exposes the secret once, and appends the hook', async () => {
		const form = baseForm()
		const wrapper = mountIt(form)
		wrapper.vm.newWebhookUrl = 'https://hook.test/x'
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'w9', name: 'Webhook', url: 'https://hook.test/x', events: ['response.created'], secret: 'WSECRET' },
		})
		await wrapper.vm.createWebhook()
		await flushPromises()

		const body = axios.post.mock.calls[0][1]
		expect(body.url).toBe('https://hook.test/x')
		expect(body.events).toEqual(['response.created'])
		expect(wrapper.vm.newWebhookSecret).toBe('WSECRET')
		expect(form.settings.webhooks).toHaveLength(1)
		expect(form.settings.webhooks[0].secret).toBe('[hidden]')
		expect(wrapper.vm.newWebhookUrl).toBe('')
	})

	it('createWebhook is a no-op with no URL', async () => {
		const wrapper = mountIt()
		const post = vi.spyOn(axios, 'post')
		await wrapper.vm.createWebhook()
		expect(post).not.toHaveBeenCalled()
	})

	it('toggleWebhook updates enabled on the local hook', async () => {
		const form = baseForm({ webhooks: [{ id: 'w1', enabled: true }] })
		const wrapper = mountIt(form)
		vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		await wrapper.vm.toggleWebhook('w1', false)
		await flushPromises()
		expect(form.settings.webhooks[0].enabled).toBe(false)
	})

	it('deleteWebhook removes the hook when confirmed', async () => {
		const form = baseForm({ webhooks: [{ id: 'w1' }, { id: 'w2' }] })
		const wrapper = mountIt(form)
		vi.spyOn(window, 'confirm').mockReturnValue(true)
		vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		await wrapper.vm.deleteWebhook('w2')
		await flushPromises()
		expect(form.settings.webhooks.map(w => w.id)).toEqual(['w1'])
	})

	it('renders the fileId in the API documentation endpoints', () => {
		const wrapper = mountIt()
		expect(wrapper.text()).toContain('/api/v1/external/forms/5')
	})
})
