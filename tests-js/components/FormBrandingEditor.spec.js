import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FormBrandingEditor from '@/components/FormBrandingEditor.vue'

/**
 * Characterization tests for FormBrandingEditor.
 * A modal that toggles per-form custom branding on/off. When off it shows the
 * "using defaults" info box and does NOT render PageBuilder; toggling emits
 * update:branding (a deep-cloned object when enabled, null when disabled).
 *
 * PageBuilder is stubbed globally so enabling branding does not pull the heavy
 * editor tree into the test.
 */
const mountEditor = (props = {}) =>
	mount(FormBrandingEditor, {
		props,
		global: {
			stubs: { PageBuilder: { name: 'PageBuilder', template: '<div class="pb-stub" />' } },
		},
	})

describe('FormBrandingEditor', () => {
	it('starts with custom branding OFF when branding prop is null', () => {
		const wrapper = mountEditor({ branding: null })
		expect(wrapper.text()).toContain('This form uses the default theme')
		expect(wrapper.findComponent({ name: 'PageBuilder' }).exists()).toBe(false)
	})

	it('starts with custom branding OFF when branding is an empty object', () => {
		const wrapper = mountEditor({ branding: {} })
		expect(wrapper.findComponent({ name: 'PageBuilder' }).exists()).toBe(false)
	})

	it('starts with custom branding ON when branding has keys', () => {
		const wrapper = mountEditor({ branding: { globalStyles: { primaryColor: '#123456' } } })
		expect(wrapper.findComponent({ name: 'PageBuilder' }).exists()).toBe(true)
	})

	it('emits a cloned branding object when the switch is turned on', async () => {
		const wrapper = mountEditor({ branding: null })
		// The checkbox stub emits update:model-value with the checkbox value.
		wrapper.getComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:model-value', true)
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:branding')
		expect(emitted).toBeTruthy()
		const payload = emitted.at(-1)[0]
		expect(payload).not.toBeNull()
		// Defaults to DEFAULT_BRANDING when no branding was supplied.
		expect(payload.globalStyles.primaryColor).toBe('#0082c9')
		// PageBuilder now renders.
		expect(wrapper.findComponent({ name: 'PageBuilder' }).exists()).toBe(true)
	})

	it('emits null when the switch is turned off', async () => {
		const wrapper = mountEditor({ branding: { globalStyles: { primaryColor: '#111111' } } })
		wrapper.getComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:model-value', false)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('update:branding').at(-1)).toEqual([null])
		expect(wrapper.findComponent({ name: 'PageBuilder' }).exists()).toBe(false)
	})

	it('forwards PageBuilder branding updates as a cloned object', async () => {
		const wrapper = mountEditor({ branding: { globalStyles: { primaryColor: '#111111' } } })
		const pb = wrapper.findComponent({ name: 'PageBuilder' })
		expect(pb.exists()).toBe(true)
		// Simulate the parent handler directly (stub has no @update:branding wiring).
		wrapper.vm.onBrandingUpdate({ globalStyles: { primaryColor: '#abcdef' } })
		await wrapper.vm.$nextTick()

		const payload = wrapper.emitted('update:branding').at(-1)[0]
		expect(payload.globalStyles.primaryColor).toBe('#abcdef')
	})

	it('emits close from the Close button', async () => {
		const wrapper = mountEditor({ branding: null })
		// Last NcButton is the Close action.
		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		buttons.at(-1).vm.$emit('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('close')).toBeTruthy()
	})
})
