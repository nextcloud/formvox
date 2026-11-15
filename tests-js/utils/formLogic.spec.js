import { describe, it, expect } from 'vitest'
import cases from '../../tests/fixtures/form-logic-cases.json'
import { analyse, evaluateCondition, nextPageIndex, visibleAnswers } from '@/utils/formLogic'

/**
 * What a respondent sees, worked out in the browser exactly as on the server: both run the same table
 * (tests/fixtures/form-logic-cases.json; the server side is tests/Unit/FormLogicTest.php).
 */
describe('utils/formLogic', () => {
	describe('conditions, as the server evaluates them', () => {
		it.each(cases.conditions.map(c => [c.name, c]))('%s', (_, c) => {
			const byId = Object.fromEntries((c.questions || []).map(q => [q.id, q]))
			expect(evaluateCondition(c.condition, c.answers, byId)).toBe(c.expect)
		})
	})

	describe('hidden questions and visited pages, as the server works them out', () => {
		it.each(cases.forms.map(c => [c.name, c]))('%s', (_, c) => {
			const result = analyse(c.form, c.answers)
			expect([...result.hidden].sort()).toEqual([...c.hidden].sort())
			expect(result.pages).toEqual(c.pages)
		})
	})

	it('leaves out the answers on hidden questions and skipped pages', () => {
		const c = cases.forms.find(f => f.name === 'routing skips a page, and what is on it')
		expect(visibleAnswers(c.form, c.answers)).toEqual({ q1: 'skip', q3: 'z' })
	})

	it('tells apart what a condition hides from what is on a skipped page', () => {
		const c = cases.forms.find(f => f.name === 'routing skips a page, and what is on it')
		expect([...analyse(c.form, c.answers).hiddenByCondition]).toEqual([])
	})

	describe('Next', () => {
		const routed = cases.forms.find(f => f.name === 'routing skips a page, and what is on it').form
		const loop = cases.forms.find(f => f.name === 'a rule back to a page already visited leads on to the next page').form

		it('follows the first matching rule', () => {
			expect(nextPageIndex(routed, 0, { q1: 'skip' })).toBe(2)
		})

		it('goes to the next page without a matching rule', () => {
			expect(nextPageIndex(routed, 0, { q1: 'stay' })).toBe(1)
		})

		it('does not lead back to a page already on the way, as the server walks the pages', () => {
			expect(nextPageIndex(loop, 1, { q2: 'again' })).toBe(2)
		})
	})
})
