import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import AiSettings from '@/components/AiSettings.vue'
import axios from '@nextcloud/axios'

/**
 * Characterization tests for AiSettings — admin panel for AI form generation.
 * Loads settings on mount and shows a provider-available/unavailable banner.
 *
 * Regression note: save()/load() previously called a bare t('formvox', …) that
 * was only a return-property, not in setup() scope, so every save threw
 * ReferenceError and the panel could not persist a change. Fixed by importing
 * translate as t; the save/load tests below would catch a re-introduction.
 */
const mountLoaded = async (settings) => {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: { settings } })
	const wrapper = mount(AiSettings)
	await flushPromises()
	return wrapper
}

describe('AiSettings', () => {
	afterEach(() => vi.restoreAllMocks())

	it('save() posts settings and does not throw (regression: bare t was out of scope)', async () => {
		const wrapper = await mountLoaded({ providerAvailable: true, enabled: false })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { settings: { enabled: true } } })

		await wrapper.vm.save()
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			'/apps/formvox/api/settings/ai',
			expect.objectContaining({ enabled: false }),
		)
		// enabled was merged back from the server response.
		expect(wrapper.vm.settings.enabled).toBe(true)
		expect(wrapper.vm.error).toBe('')
	})

	it('save() surfaces a server error message instead of crashing', async () => {
		const wrapper = await mountLoaded({ providerAvailable: true })
		vi.spyOn(axios, 'post').mockRejectedValue({ response: { data: { error: 'nope' } } })

		await wrapper.vm.save()
		await flushPromises()

		expect(wrapper.vm.error).toBe('nope')
	})

	it('load() error path shows a toast without crashing on the bare t', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('boom'))
		const wrapper = mount(AiSettings)
		await flushPromises()
		// Reached the finally, loading cleared — no ReferenceError propagated.
		expect(wrapper.vm.loading).toBe(false)
	})

	it('shows the loading icon before the request resolves', () => {
		vi.spyOn(axios, 'get').mockReturnValue(new Promise(() => {}))
		const wrapper = mount(AiSettings)
		expect(wrapper.find('[data-stub="NcLoadingIcon"]').exists()).toBe(true)
	})

	it('renders the "provider available" banner when a provider is configured', async () => {
		const wrapper = await mountLoaded({
			enabled: true,
			providerAvailable: true,
			providerTaskType: 'core:text2text',
			maxQuestions: 10,
			maxDocSizeMb: 5,
		})
		expect(wrapper.text()).toContain('AI provider available')
		expect(wrapper.text()).toContain('core:text2text')
		expect(wrapper.find('.provider-status.ok').exists()).toBe(true)
	})

	it('renders the "no provider" warning when none configured', async () => {
		const wrapper = await mountLoaded({ providerAvailable: false, enabled: false })
		expect(wrapper.text()).toContain('No AI provider configured')
		expect(wrapper.find('.provider-status.warning').exists()).toBe(true)
	})

	it('disables the enable-switch when no provider is available', async () => {
		const wrapper = await mountLoaded({ providerAvailable: false, enabled: false })
		// The stub forwards unknown props (disabled) as DOM attributes.
		const enableSwitch = wrapper.findAll('[data-stub="NcCheckboxRadioSwitch"]')[0]
		expect(enableSwitch.attributes('disabled')).toBeDefined()
	})

	it('merges loaded settings over the defaults', async () => {
		const wrapper = await mountLoaded({
			providerAvailable: true,
			enabled: true,
			maxQuestions: 17,
			maxDocSizeMb: 3,
		})
		// A field not returned by the server keeps its default.
		expect(wrapper.vm.settings.allowSourceUpload).toBe(true)
		// Returned fields win.
		expect(wrapper.vm.settings.maxQuestions).toBe(17)
		expect(wrapper.vm.settings.maxDocSizeMb).toBe(3)
	})

	it('reflects maxQuestions / maxDocSizeMb in the slider hints', async () => {
		const wrapper = await mountLoaded({
			providerAvailable: true,
			enabled: true,
			maxQuestions: 11,
			maxDocSizeMb: 6,
		})
		expect(wrapper.text()).toContain('11')
		expect(wrapper.text()).toContain('6 MB')
	})

	it('renders the four AI option switches when enabled', async () => {
		const wrapper = await mountLoaded({ providerAvailable: true, enabled: true })
		// enable + source-upload + conditional = 3 NcCheckboxRadioSwitch stubs.
		expect(wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).length).toBe(3)
	})
})
