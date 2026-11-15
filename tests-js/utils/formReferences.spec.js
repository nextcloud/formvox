import { describe, it, expect } from 'vitest'
import { removeReferencesToQuestion, removeRoutingToPage } from '@/utils/formReferences'

/**
 * Deleting a question or page takes along what points at it: the server refuses a condition on a missing
 * question (step 4b), and the editor says what it removed (PO 4c).
 */
describe('utils/formReferences', () => {
	const form = () => ({
		questions: [
			{ id: 'q1', type: 'choice' },
			{ id: 'q2', type: 'text', showIf: { questionId: 'q1', operator: 'equals', value: 'yes' } },
			{ id: 'q3', type: 'text', showIf: { operator: 'and', conditions: [
				{ questionId: 'q1', operator: 'isNotEmpty' },
				{ questionId: 'q2', operator: 'isNotEmpty' },
			] } },
			{ id: 'q4', type: 'text', showIf: { operator: 'or', conditions: [
				{ operator: 'and', conditions: [{ questionId: 'q1', operator: 'isEmpty' }] },
			] } },
			{ id: 's1', type: 'section', showIf: { questionId: 'q2', operator: 'isEmpty' } },
		],
		pages: [
			{ id: 'p1', questions: ['q1', 'q2'], routing: [
				{ questionId: 'q1', operator: 'equals', value: 'no', targetPageId: 'p3' },
				{ questionId: 'q2', operator: 'isEmpty', targetPageId: 'p2' },
			] },
			{ id: 'p2', questions: ['q3', 'q4'] },
			{ id: 'p3', questions: [], routing: [{ questionId: 'q3', operator: 'isEmpty', targetPageId: 'p2' }] },
		],
	})

	it('removes every condition and routing rule on the question, also nested ones, and counts them', () => {
		const f = form()

		expect(removeReferencesToQuestion(f, 'q1')).toBe(4)

		expect(f.questions[1].showIf).toBeNull()
		expect(f.questions[2].showIf).toEqual({ operator: 'and', conditions: [{ questionId: 'q2', operator: 'isNotEmpty' }] })
		// A group with nothing left goes as a whole.
		expect(f.questions[3].showIf).toBeNull()
		expect(f.pages[0].routing).toEqual([{ questionId: 'q2', operator: 'isEmpty', targetPageId: 'p2' }])
	})

	it('leaves what uses other questions, and takes the question off its page', () => {
		const f = form()

		expect(removeReferencesToQuestion(f, 'q4')).toBe(0)
		expect(f.questions[1].showIf).toEqual({ questionId: 'q1', operator: 'equals', value: 'yes' })
		expect(f.pages[1].questions).toEqual(['q3'])
	})

	it('also on sections', () => {
		const f = form()

		removeReferencesToQuestion(f, 'q2')

		expect(f.questions[4].showIf).toBeNull()
	})

	it('works on a form without pages', () => {
		const f = { questions: [{ id: 'q1' }, { id: 'q2', showIf: { questionId: 'q1', operator: 'isEmpty' } }], pages: null }

		expect(removeReferencesToQuestion(f, 'q1')).toBe(1)
		expect(f.questions[1].showIf).toBeNull()
	})

	it('removes the routing that leads to a deleted page', () => {
		const f = form()

		expect(removeRoutingToPage(f, 'p2')).toBe(2)
		expect(f.pages[0].routing).toEqual([{ questionId: 'q1', operator: 'equals', value: 'no', targetPageId: 'p3' }])
		expect(f.pages[2].routing).toEqual([])
	})
})
