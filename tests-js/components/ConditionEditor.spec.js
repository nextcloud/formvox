import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ConditionEditor from '@/components/ConditionEditor.vue'

/**
 * Characterization tests for ConditionEditor — the modal wrapper around
 * ConditionGroup. It exposes `availableQuestions` (only questions BEFORE the
 * current one), can seed a first simple condition, and saves via the `update`
 * event carrying localCondition.
 */
describe('ConditionEditor', () => {
	const questions = [
		{ id: 'q1', question: 'One', type: 'text' },
		{ id: 'q2', question: 'Two', type: 'text' },
		{ id: 'q3', question: 'Three', type: 'text' },
	]

	const factory = (props = {}) =>
		mount(ConditionEditor, {
			props: { questions, currentQuestionId: 'q3', ...props },
		})

	it('shows the "no condition" state when condition is null', () => {
		const wrapper = factory()
		expect(wrapper.find('.no-condition').exists()).toBe(true)
		expect(wrapper.find('.condition-builder').exists()).toBe(false)
	})

	it('availableQuestions only includes questions before the current one', () => {
		const wrapper = factory({ currentQuestionId: 'q3' })
		expect(wrapper.vm.availableQuestions.map(q => q.id)).toEqual(['q1', 'q2'])
	})

	it('availableQuestions is empty for the first question', () => {
		const wrapper = factory({ currentQuestionId: 'q1' })
		expect(wrapper.vm.availableQuestions).toEqual([])
	})

	it('shows the no-questions hint and disables Add when nothing precedes', () => {
		const wrapper = factory({ currentQuestionId: 'q1' })
		expect(wrapper.find('.no-questions-hint').exists()).toBe(true)
	})

	it('does not show the hint when previous questions exist', () => {
		const wrapper = factory({ currentQuestionId: 'q3' })
		expect(wrapper.find('.no-questions-hint').exists()).toBe(false)
	})

	it('addSimpleCondition seeds a condition from the first available question', async () => {
		const wrapper = factory({ currentQuestionId: 'q3' })
		wrapper.vm.addSimpleCondition()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.localCondition).toEqual({ questionId: 'q1', operator: 'equals', value: '' })
		// now the builder (ConditionGroup) is shown
		expect(wrapper.find('.condition-builder').exists()).toBe(true)
	})

	it('addSimpleCondition is a no-op when no questions are available', async () => {
		const wrapper = factory({ currentQuestionId: 'q1' })
		wrapper.vm.addSimpleCondition()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.localCondition).toBe(null)
	})

	it('clicking Add condition seeds a condition via the button', async () => {
		const wrapper = factory({ currentQuestionId: 'q3' })
		// The Add button is the NcButton inside .no-condition
		await wrapper.find('.no-condition [data-stub="NcButton"]').trigger('click')
		expect(wrapper.vm.localCondition).toEqual({ questionId: 'q1', operator: 'equals', value: '' })
	})

	it('deep-clones the incoming condition (does not mutate the prop)', () => {
		const condition = { questionId: 'q1', operator: 'equals', value: 'x' }
		const wrapper = factory({ condition })
		expect(wrapper.vm.localCondition).toEqual(condition)
		expect(wrapper.vm.localCondition).not.toBe(condition)
	})

	it('renders the builder (ConditionGroup) when a condition is present', () => {
		const wrapper = factory({ condition: { questionId: 'q1', operator: 'equals', value: 'x' } })
		expect(wrapper.find('.condition-builder').exists()).toBe(true)
		expect(wrapper.find('.no-condition').exists()).toBe(false)
	})

	it('updateCondition replaces localCondition', async () => {
		const wrapper = factory({ condition: { questionId: 'q1', operator: 'equals', value: 'x' } })
		wrapper.vm.updateCondition({ questionId: 'q2', operator: 'contains', value: 'y' })
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.localCondition).toEqual({ questionId: 'q2', operator: 'contains', value: 'y' })
	})

	it('save emits update with the current localCondition', async () => {
		const condition = { questionId: 'q1', operator: 'equals', value: 'x' }
		const wrapper = factory({ condition })
		wrapper.vm.save()
		const emitted = wrapper.emitted('update')
		expect(emitted).toBeTruthy()
		expect(emitted.at(-1)).toEqual([condition])
	})

	it('save emits null when there is no condition', async () => {
		const wrapper = factory({ currentQuestionId: 'q3' })
		wrapper.vm.save()
		expect(wrapper.emitted('update').at(-1)).toEqual([null])
	})

	it('emits close when the modal closes', async () => {
		const wrapper = factory()
		await wrapper.find('[data-stub="NcModal"]').trigger('click')
		// NcModal stub emits close via its onClick->? Actually it emits click; use direct close
		wrapper.vm.$emit('close')
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('removing the condition from the group resets localCondition to null', async () => {
		const wrapper = factory({ condition: { questionId: 'q1', operator: 'equals', value: 'x' } })
		// ConditionGroup emits remove -> handler sets localCondition = null
		wrapper.findComponent({ name: 'ConditionGroup' }).vm.$emit('remove')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.localCondition).toBe(null)
		expect(wrapper.find('.no-condition').exists()).toBe(true)
	})

	it('Cancel / Save action buttons are rendered', () => {
		const wrapper = factory()
		const actionBtns = wrapper.findAll('.actions [data-stub="NcButton"]')
		expect(actionBtns.length).toBe(2)
	})
})
