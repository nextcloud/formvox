import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ConditionGroup from '@/components/ConditionGroup.vue'

/**
 * Characterization tests for ConditionGroup — the recursive conditional-logic
 * node. A "simple" condition has a questionId; a "combined" condition has an
 * operator (and/or) + a conditions array. The component keeps a deep-cloned
 * localCondition and emits `update` with a fresh object on every mutation.
 */
describe('ConditionGroup', () => {
	const questions = [
		{ id: 'q1', question: 'Name', type: 'text' },
		{ id: 'q2', question: 'Color', type: 'choice', options: [{ value: 'r', label: 'Red' }, { value: 'b', label: 'Blue' }] },
		{ id: 'q3', question: 'When', type: 'date' },
	]

	const simple = () => ({ questionId: 'q1', operator: 'equals', value: 'hi' })
	const combined = () => ({ operator: 'and', conditions: [simple(), { questionId: 'q2', operator: 'equals', value: 'r' }] })

	const mountSimple = (condition = simple(), props = {}) =>
		mount(ConditionGroup, { props: { condition, questions, ...props } })

	it('renders a simple condition (question + operator selects, value input)', () => {
		const wrapper = mountSimple()
		expect(wrapper.find('.simple-condition').exists()).toBe(true)
		expect(wrapper.find('.combined-condition').exists()).toBe(false)
		// question select, operator select, value input
		const selects = wrapper.findAll('select')
		expect(selects.length).toBe(2)
	})

	it('renders the plain-text value input when the question has no options', () => {
		const wrapper = mountSimple()
		expect(wrapper.find('input[type="text"]').exists()).toBe(true)
		expect(wrapper.find('input[type="text"]').element.value).toBe('hi')
	})

	it('hides the value input for isEmpty / isNotEmpty operators', () => {
		const wrapper = mountSimple({ questionId: 'q1', operator: 'isEmpty', value: '' })
		expect(wrapper.find('input[type="text"]').exists()).toBe(false)
		// only the two selects, no value control
		expect(wrapper.findAll('select').length).toBe(2)
	})

	it('renders an option <select> for a question that has options', () => {
		const wrapper = mountSimple({ questionId: 'q2', operator: 'equals', value: 'r' })
		// question select, operator select, value option-select => 3
		const selects = wrapper.findAll('select')
		expect(selects.length).toBe(3)
		const valueSelect = selects[2]
		const opts = valueSelect.findAll('option').map(o => o.text())
		expect(opts).toContain('Red')
		expect(opts).toContain('Blue')
	})

	it('renders a date picker for a date-typed question', () => {
		const wrapper = mountSimple({ questionId: 'q3', operator: 'equals', value: '' })
		expect(wrapper.find('[data-stub="NcDateTimePicker"]').exists()).toBe(true)
	})

	it('emits update with the new operator when operator changes', async () => {
		const wrapper = mountSimple()
		const operatorSelect = wrapper.findAll('select')[1]
		await operatorSelect.setValue('notEquals')
		const emitted = wrapper.emitted('update')
		expect(emitted).toBeTruthy()
		expect(emitted.at(-1)[0].operator).toBe('notEquals')
	})

	it('emits update on value text input', async () => {
		const wrapper = mountSimple()
		const input = wrapper.find('input[type="text"]')
		await input.setValue('changed')
		const emitted = wrapper.emitted('update')
		expect(emitted.at(-1)[0].value).toBe('changed')
	})

	it('resets value to "" and emits update when the question changes', async () => {
		const wrapper = mountSimple()
		const questionSelect = wrapper.findAll('select')[0]
		await questionSelect.setValue('q2')
		const last = wrapper.emitted('update').at(-1)[0]
		expect(last.questionId).toBe('q2')
		expect(last.value).toBe('')
	})

	it('emits remove when the delete button is clicked', async () => {
		const wrapper = mountSimple()
		// last NcButton in the simple condition actions is the delete button
		const buttons = wrapper.findAll('[data-stub="NcButton"]')
		await buttons[buttons.length - 1].trigger('click')
		expect(wrapper.emitted('remove')).toBeTruthy()
	})

	it('converts a simple condition into an AND group (nesting the original)', async () => {
		const wrapper = mountSimple()
		// AND button is the first NcButton in condition-actions
		const buttons = wrapper.findAll('.condition-actions [data-stub="NcButton"]')
		await buttons[0].trigger('click')
		const emitted = wrapper.emitted('update').at(-1)[0]
		expect(emitted.operator).toBe('and')
		expect(Array.isArray(emitted.conditions)).toBe(true)
		expect(emitted.conditions.length).toBe(2)
		// original condition preserved as first child
		expect(emitted.conditions[0]).toMatchObject({ questionId: 'q1', value: 'hi' })
		// new child seeded with first question
		expect(emitted.conditions[1]).toMatchObject({ questionId: 'q1', operator: 'equals', value: '' })
	})

	it('converts a simple condition into an OR group', async () => {
		const wrapper = mountSimple()
		const buttons = wrapper.findAll('.condition-actions [data-stub="NcButton"]')
		await buttons[1].trigger('click')
		const emitted = wrapper.emitted('update').at(-1)[0]
		expect(emitted.operator).toBe('or')
		expect(emitted.conditions.length).toBe(2)
	})

	it('after conversion, re-renders as a combined group', async () => {
		const wrapper = mountSimple()
		const buttons = wrapper.findAll('.condition-actions [data-stub="NcButton"]')
		await buttons[0].trigger('click')
		expect(wrapper.find('.combined-condition').exists()).toBe(true)
	})

	it('renders a combined condition with its sub-conditions and group operator', () => {
		const wrapper = mountSimple(combined())
		expect(wrapper.find('.combined-condition').exists()).toBe(true)
		// group operator select has and/or
		const groupSelect = wrapper.find('.group-header select')
		expect(groupSelect.findAll('option').map(o => o.attributes('value'))).toEqual(['and', 'or'])
		// two nested ConditionGroups (child .condition-group nodes)
		const nested = wrapper.findAll('.conditions-list .condition-group')
		expect(nested.length).toBe(2)
	})

	it('marks nested groups with the nested class (isRoot=false)', () => {
		const wrapper = mountSimple(simple(), { isRoot: false })
		expect(wrapper.find('.condition-group').classes()).toContain('nested')
	})

	it('root group is not marked nested', () => {
		const wrapper = mountSimple(simple(), { isRoot: true })
		expect(wrapper.find('.condition-group').classes()).not.toContain('nested')
	})

	it('adds a sub-condition to a combined group and emits update', async () => {
		const wrapper = mountSimple(combined())
		// The "Add condition" button is the direct NcButton of the combined block
		const addBtn = wrapper.findAll('.combined-condition > [data-stub="NcButton"]')
		await addBtn[addBtn.length - 1].trigger('click')
		const emitted = wrapper.emitted('update').at(-1)[0]
		expect(emitted.conditions.length).toBe(3)
		expect(emitted.conditions[2]).toMatchObject({ questionId: 'q1', operator: 'equals', value: '' })
	})

	it('removing a sub-condition down to one collapses to a simple condition', async () => {
		const wrapper = mountSimple(combined())
		// remove the second sub-condition via the nested group's remove
		wrapper.vm.removeSubCondition(1)
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update').at(-1)[0]
		// collapsed to the remaining simple condition
		expect(emitted).toMatchObject({ questionId: 'q1', value: 'hi' })
		expect(emitted.conditions).toBeUndefined()
	})

	it('changing the group operator emits update with the new operator', async () => {
		const wrapper = mountSimple(combined())
		const groupSelect = wrapper.find('.group-header select')
		await groupSelect.setValue('or')
		expect(wrapper.emitted('update').at(-1)[0].operator).toBe('or')
	})

	it('emits remove from a combined group header delete button', async () => {
		const wrapper = mountSimple(combined())
		await wrapper.find('.group-header [data-stub="NcButton"]').trigger('click')
		expect(wrapper.emitted('remove')).toBeTruthy()
	})

	it('re-clones localCondition when the condition prop changes', async () => {
		const wrapper = mountSimple()
		await wrapper.setProps({ condition: { questionId: 'q2', operator: 'contains', value: 'x' } })
		expect(wrapper.vm.localCondition.questionId).toBe('q2')
		expect(wrapper.vm.localCondition.operator).toBe('contains')
	})

	it('onDateValue formats a date-typed value and emits update', async () => {
		const wrapper = mountSimple({ questionId: 'q3', operator: 'equals', value: '' })
		wrapper.vm.onDateValue(new Date('2026-03-04T10:00:00Z'), 'date')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update').at(-1)[0].value).toBe('2026-03-04')
	})

	it('onDateValue clears the value when passed null', async () => {
		const wrapper = mountSimple({ questionId: 'q3', operator: 'equals', value: '2026-03-04' })
		wrapper.vm.onDateValue(null, 'date')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update').at(-1)[0].value).toBe('')
	})

	it('convertToGroup seeds child with "" questionId when there are no questions', async () => {
		const wrapper = mount(ConditionGroup, { props: { condition: simple(), questions: [] } })
		const buttons = wrapper.findAll('.condition-actions [data-stub="NcButton"]')
		await buttons[0].trigger('click')
		const emitted = wrapper.emitted('update').at(-1)[0]
		expect(emitted.conditions[1].questionId).toBe('')
	})
})
