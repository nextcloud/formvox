import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import NumberField from '@/components/NumberField.vue'

/**
 * Characterization tests for NumberField — the #134 fix.
 *
 * NumberField wraps NcTextField and guarantees it never receives null (which
 * crashes NcTextField's render). It shows '' for any non-finite value and emits
 * a real number, or null when the field is cleared.
 */
describe('NumberField', () => {
	const displayed = (wrapper) => wrapper.get('[data-stub="NcTextField"]').attributes('value')

	it('renders a finite number as its string', () => {
		const wrapper = mount(NumberField, { props: { modelValue: 42 } })
		expect(displayed(wrapper)).toBe('42')
	})

	it('renders 0 as "0" (not empty)', () => {
		const wrapper = mount(NumberField, { props: { modelValue: 0 } })
		expect(displayed(wrapper)).toBe('0')
	})

	it('renders null as empty string — never hands NcTextField null (#134)', () => {
		const wrapper = mount(NumberField, { props: { modelValue: null } })
		expect(displayed(wrapper)).toBe('')
	})

	it('renders undefined as empty string', () => {
		const wrapper = mount(NumberField, { props: {} })
		expect(displayed(wrapper)).toBe('')
	})

	it('renders NaN as empty string', () => {
		const wrapper = mount(NumberField, { props: { modelValue: NaN } })
		expect(displayed(wrapper)).toBe('')
	})

	it('emits a parsed number on numeric input', async () => {
		const wrapper = mount(NumberField, { props: { modelValue: null } })
		wrapper.get('input').setValue('7')
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:model-value')
		expect(emitted).toBeTruthy()
		expect(emitted.at(-1)).toEqual([7])
	})

	it('emits null when the field is cleared (empty string)', async () => {
		const wrapper = mount(NumberField, { props: { modelValue: 5 } })
		wrapper.get('input').setValue('')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:model-value').at(-1)).toEqual([null])
	})

	it('emits null for non-numeric input', async () => {
		const wrapper = mount(NumberField, { props: { modelValue: null } })
		wrapper.get('input').setValue('abc')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:model-value').at(-1)).toEqual([null])
	})
})
