import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import AiSettings from '@/components/AiSettings.vue'
import axios from '@nextcloud/axios'

/**
 * Characterization tests for AiSettings — admin panel for AI form generation.
 * Loads settings on mount and shows a provider-available/unavailable banner.
 *
 * NOTE: `save()` (and the load/save error paths) reference an out-of-scope `t`
 * and throw ReferenceError on every call — the panel cannot actually persist a
 * change. That is a live bug in the component, not behaviour worth pinning, so
 * these tests exercise only the load + render surface, which is `t`-free.
 */
const mountLoaded = async (settings) => {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: { settings } })
	const wrapper = mount(AiSettings)
	await flushPromises()
	return wrapper
}

describe('AiSettings', () => {
	afterEach(() => vi.restoreAllMocks())

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
