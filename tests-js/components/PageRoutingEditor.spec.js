import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PageRoutingEditor from '@/components/PageRoutingEditor.vue'

/**
 * Characterization tests for PageRoutingEditor — the per-page skip-routing
 * editor. It builds a localRules list from page.routing, exposes only the
 * page's own questions + other pages as targets, and emits `update:routing`
 * with only COMPLETE rules (questionId AND targetPageId set).
 */
describe('PageRoutingEditor', () => {
	const questions = [
		{ id: 'q1', question: 'Name', type: 'text' },
		{ id: 'q2', question: 'Color', type: 'choice', options: [{ label: 'Red' }, { label: 'Blue' }, { label: '' }] },
		{ id: 'q3', question: 'Other', type: 'text' },
	]

	const pages = [
		{ id: 'p1', title: 'First', questions: ['q1', 'q2'] },
		{ id: 'p2', title: 'Second', questions: ['q3'] },
		{ id: 'p3', title: '', questions: [] },
	]

	const factory = (pageOverrides = {}, props = {}) =>
		mount(PageRoutingEditor, {
			props: {
				page: { ...pages[0], ...pageOverrides },
				pages,
				questions,
				...props,
			},
		})

	it('shows the empty state when the page has no routing', () => {
		const wrapper = factory()
		expect(wrapper.find('.no-rules').exists()).toBe(true)
		expect(wrapper.find('.routing-rule').exists()).toBe(false)
	})

	it('builds localRules from existing page.routing', () => {
		const routing = [{ id: 'r1', questionId: 'q1', operator: 'equals', value: 'x', targetPageId: 'p2' }]
		const wrapper = factory({ routing })
		expect(wrapper.vm.localRules.length).toBe(1)
		expect(wrapper.find('.no-rules').exists()).toBe(false)
		expect(wrapper.findAll('.routing-rule').length).toBe(1)
	})

	it('clones routing rules (does not share object identity with prop)', () => {
		const routing = [{ id: 'r1', questionId: 'q1', operator: 'equals', value: 'x', targetPageId: 'p2' }]
		const wrapper = factory({ routing })
		expect(wrapper.vm.localRules[0]).not.toBe(routing[0])
		expect(wrapper.vm.localRules[0]).toEqual(routing[0])
	})

	it('availableQuestions is limited to the page\'s own questions', () => {
		const wrapper = factory() // page p1 has q1, q2
		expect(wrapper.vm.availableQuestions.map(q => q.id)).toEqual(['q1', 'q2'])
	})

	it('otherPages excludes the current page', () => {
		const wrapper = factory()
		expect(wrapper.vm.otherPages.map(p => p.id)).toEqual(['p2', 'p3'])
	})

	it('getPageDisplayIndex returns the 1-based index', () => {
		const wrapper = factory()
		expect(wrapper.vm.getPageDisplayIndex('p1')).toBe(1)
		expect(wrapper.vm.getPageDisplayIndex('p3')).toBe(3)
	})

	it('getQuestionOptions returns non-empty labels for choice questions', () => {
		const wrapper = factory()
		expect(wrapper.vm.getQuestionOptions('q2')).toEqual(['Red', 'Blue'])
	})

	it('getQuestionOptions returns [] for a text question', () => {
		const wrapper = factory()
		expect(wrapper.vm.getQuestionOptions('q1')).toEqual([])
	})

	it('getQuestionOptions returns [] for an unknown question id', () => {
		const wrapper = factory()
		expect(wrapper.vm.getQuestionOptions('nope')).toEqual([])
	})

	it('addRule appends a blank rule with a generated id', async () => {
		const wrapper = factory()
		wrapper.vm.addRule()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.localRules.length).toBe(1)
		const rule = wrapper.vm.localRules[0]
		expect(rule.id).toMatch(/^r/)
		expect(rule).toMatchObject({ questionId: '', operator: 'equals', value: '', targetPageId: '' })
		// a rule row is now rendered
		expect(wrapper.findAll('.routing-rule').length).toBe(1)
	})

	it('clicking Add rule adds a rule via the button', async () => {
		const wrapper = factory()
		// The Add rule button is the NcButton before routing-actions
		const buttons = wrapper.findAll('[data-stub="NcButton"]')
		// find the one that is NOT inside routing-actions: it's the addRule button
		await buttons[0].trigger('click')
		expect(wrapper.vm.localRules.length).toBe(1)
	})

	it('addRule alone does NOT emit update:routing (incomplete rule)', async () => {
		const wrapper = factory()
		wrapper.vm.addRule()
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:routing')).toBeFalsy()
	})

	it('onRuleChange emits only complete rules (questionId + targetPageId)', async () => {
		const wrapper = factory()
		wrapper.vm.addRule()
		wrapper.vm.localRules[0].questionId = 'q1'
		wrapper.vm.localRules[0].targetPageId = 'p2'
		wrapper.vm.onRuleChange()
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:routing')
		expect(emitted).toBeTruthy()
		expect(emitted.at(-1)[0].length).toBe(1)
		expect(emitted.at(-1)[0][0]).toMatchObject({ questionId: 'q1', targetPageId: 'p2' })
	})

	it('onRuleChange filters out a rule missing its target page', async () => {
		const wrapper = factory()
		wrapper.vm.addRule()
		wrapper.vm.localRules[0].questionId = 'q1'
		// targetPageId stays ''
		wrapper.vm.onRuleChange()
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:routing').at(-1)[0]).toEqual([])
	})

	it('onRuleChange filters out a rule missing its question', async () => {
		const wrapper = factory()
		wrapper.vm.addRule()
		wrapper.vm.localRules[0].targetPageId = 'p2'
		wrapper.vm.onRuleChange()
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:routing').at(-1)[0]).toEqual([])
	})

	it('removeRule removes the rule and emits the filtered set', async () => {
		const routing = [
			{ id: 'r1', questionId: 'q1', operator: 'equals', value: 'x', targetPageId: 'p2' },
			{ id: 'r2', questionId: 'q2', operator: 'equals', value: 'Red', targetPageId: 'p3' },
		]
		const wrapper = factory({ routing })
		wrapper.vm.removeRule(0)
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.localRules.length).toBe(1)
		const emitted = wrapper.emitted('update:routing').at(-1)[0]
		expect(emitted.length).toBe(1)
		expect(emitted[0].id).toBe('r2')
	})

	it('clicking the remove button on a rule removes it', async () => {
		const routing = [{ id: 'r1', questionId: 'q1', operator: 'equals', value: 'x', targetPageId: 'p2' }]
		const wrapper = factory({ routing })
		await wrapper.find('.remove-rule').trigger('click')
		expect(wrapper.vm.localRules.length).toBe(0)
	})

	it('hides the value field for isEmpty / isNotEmpty operators', () => {
		const routing = [{ id: 'r1', questionId: 'q1', operator: 'isEmpty', value: '', targetPageId: 'p2' }]
		const wrapper = factory({ routing })
		const labels = wrapper.findAll('.rule-field label').map(l => l.text())
		expect(labels).not.toContain('Value')
	})

	it('shows the value field for equals operator', () => {
		const routing = [{ id: 'r1', questionId: 'q1', operator: 'equals', value: 'x', targetPageId: 'p2' }]
		const wrapper = factory({ routing })
		const labels = wrapper.findAll('.rule-field label').map(l => l.text())
		expect(labels).toContain('Value')
	})

	it('renders a value <select> when the chosen question has options', () => {
		const routing = [{ id: 'r1', questionId: 'q2', operator: 'equals', value: 'Red', targetPageId: 'p2' }]
		const wrapper = factory({ routing })
		// value field select should contain Red/Blue options
		const allOptionTexts = wrapper.findAll('option').map(o => o.text())
		expect(allOptionTexts).toContain('Red')
		expect(allOptionTexts).toContain('Blue')
	})

	it('renders the page number when an other-page has no title', () => {
		const routing = [{ id: 'r1', questionId: 'q1', operator: 'equals', value: 'x', targetPageId: 'p3' }]
		const wrapper = factory({ routing })
		// p3 has empty title -> "Page 3" via t('Page {n}', { n: 3 })
		const targetOptions = wrapper.findAll('option').map(o => o.text())
		expect(targetOptions).toContain('Page 3')
	})
})
