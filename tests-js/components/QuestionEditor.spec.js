import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import QuestionEditor from '@/components/QuestionEditor.vue'

/**
 * Characterization tests for QuestionEditor — the form-builder question editor.
 *
 * Focus areas per the batch brief:
 *   - migrateQuestion: the #134 null-coercion for question/description/labels/
 *     options and the drop-null-number-settings behaviour, plus legacy option
 *     normalisation (string/number/malformed -> object).
 *   - setNumberField and its three wrappers (updateQuestionNumber,
 *     updateOptionCapacity, updateOptionScore) — the null-means-empty contract.
 *   - the min/max/maxLength editor number fields emitting through those wrappers.
 *
 * The component deep-copies the `question` prop into a reactive localQuestion,
 * runs migrateQuestion on it, and every mutation emits the copy via `update`.
 * Tests drive the exposed methods and assert on `wrapper.emitted('update')`
 * and on localQuestion itself (the migration result).
 */

const base = (overrides = {}) => ({
	id: 'q1',
	type: 'text',
	question: 'Q',
	...overrides,
})

const mountEditor = (question, props = {}) =>
	mount(QuestionEditor, {
		props: {
			question,
			index: 0,
			questions: [question],
			...props,
		},
	})

const lastUpdate = (wrapper) => wrapper.emitted('update').at(-1)[0]

describe('QuestionEditor', () => {
	describe('migrateQuestion — #134 null-coercion on load', () => {
		it('coerces a null question text to empty string', () => {
			const wrapper = mountEditor(base({ question: null }))
			expect(wrapper.vm.localQuestion.question).toBe('')
		})

		it('coerces a null description to empty string', () => {
			const wrapper = mountEditor(base({ description: null }))
			expect(wrapper.vm.localQuestion.description).toBe('')
		})

		it('coerces null scale labels to empty strings', () => {
			const wrapper = mountEditor(
				base({ type: 'scale', scaleMinLabel: null, scaleMaxLabel: null }),
			)
			expect(wrapper.vm.localQuestion.scaleMinLabel).toBe('')
			expect(wrapper.vm.localQuestion.scaleMaxLabel).toBe('')
		})

		it('coerces null validation pattern/errorMessage to empty strings', () => {
			const wrapper = mountEditor(
				base({ validation: { pattern: null, errorMessage: null } }),
			)
			expect(wrapper.vm.localQuestion.validation.pattern).toBe('')
			expect(wrapper.vm.localQuestion.validation.errorMessage).toBe('')
		})

		it('drops null nullable number settings (absent = not set)', () => {
			const wrapper = mountEditor(
				base({
					type: 'scale',
					scaleMin: null,
					scaleMax: null,
					ratingMax: null,
					maxFileSize: null,
					maxFiles: null,
					minSelections: null,
					maxSelections: null,
					maxLength: null,
				}),
			)
			const lq = wrapper.vm.localQuestion
			for (const key of ['scaleMin', 'scaleMax', 'ratingMax', 'maxFileSize', 'maxFiles', 'minSelections', 'maxSelections', 'maxLength']) {
				expect(key in lq).toBe(false)
			}
		})

		it('coerces null matrix row and column labels', () => {
			const wrapper = mountEditor(
				base({
					type: 'matrix',
					rows: [{ id: 'r1', label: null }],
					columns: [{ id: 'c1', label: null, optionsText: null }],
				}),
			)
			expect(wrapper.vm.localQuestion.rows[0].label).toBe('')
			expect(wrapper.vm.localQuestion.columns[0].label).toBe('')
			expect(wrapper.vm.localQuestion.columns[0].optionsText).toBe('')
		})
	})

	describe('migrateQuestion — option normalisation', () => {
		it('converts a bare string option to an object', () => {
			const wrapper = mountEditor(base({ type: 'choice', options: ['Yes'] }))
			const opt = wrapper.vm.localQuestion.options[0]
			expect(opt.label).toBe('Yes')
			expect(opt.id).toBeTruthy()
			expect(opt.value).toBe(opt.id)
		})

		it('converts a numeric option to an object with string label', () => {
			const wrapper = mountEditor(base({ type: 'choice', options: [7] }))
			expect(wrapper.vm.localQuestion.options[0].label).toBe('7')
		})

		it('replaces a null/invalid option with a blank object', () => {
			const wrapper = mountEditor(base({ type: 'choice', options: [null] }))
			const opt = wrapper.vm.localQuestion.options[0]
			expect(opt.label).toBe('')
			expect(opt.id).toBeTruthy()
		})

		it('fills a missing value from the id', () => {
			const wrapper = mountEditor(
				base({ type: 'choice', options: [{ id: 'abc', label: 'A' }] }),
			)
			expect(wrapper.vm.localQuestion.options[0].value).toBe('abc')
		})

		it('coerces a null option label to empty string (#134)', () => {
			const wrapper = mountEditor(
				base({ type: 'choice', options: [{ id: 'a', label: null, value: 'a' }] }),
			)
			expect(wrapper.vm.localQuestion.options[0].label).toBe('')
		})

		it('drops a null capacity (no-limit = absent)', () => {
			const wrapper = mountEditor(
				base({ type: 'choice', options: [{ id: 'a', label: 'A', value: 'a', capacity: null }] }),
			)
			expect('capacity' in wrapper.vm.localQuestion.options[0]).toBe(false)
		})

		it('coerces a null score to 0 (keeps quiz mode alive)', () => {
			const wrapper = mountEditor(
				base({ type: 'choice', options: [{ id: 'a', label: 'A', value: 'a', score: null }] }),
			)
			expect(wrapper.vm.localQuestion.options[0].score).toBe(0)
		})
	})

	describe('computed guards', () => {
		it('hasOptions is true for choice/multiple/dropdown', () => {
			expect(mountEditor(base({ type: 'choice' })).vm.hasOptions).toBe(true)
			expect(mountEditor(base({ type: 'multiple' })).vm.hasOptions).toBe(true)
			expect(mountEditor(base({ type: 'dropdown' })).vm.hasOptions).toBe(true)
		})

		it('hasOptions is false for text', () => {
			expect(mountEditor(base({ type: 'text' })).vm.hasOptions).toBe(false)
		})

		it('isQuizMode is true when any option has a numeric score', () => {
			const wrapper = mountEditor(
				base({ type: 'choice', options: [{ id: 'a', label: 'A', value: 'a', score: 5 }] }),
			)
			expect(wrapper.vm.isQuizMode).toBe(true)
		})

		it('isQuizMode is false when no option has a score', () => {
			const wrapper = mountEditor(
				base({ type: 'choice', options: [{ id: 'a', label: 'A', value: 'a' }] }),
			)
			expect(wrapper.vm.isQuizMode).toBe(false)
		})

		it('supportsValidation is true for text/textarea/number only', () => {
			expect(mountEditor(base({ type: 'text' })).vm.supportsValidation).toBe(true)
			expect(mountEditor(base({ type: 'textarea' })).vm.supportsValidation).toBe(true)
			expect(mountEditor(base({ type: 'number' })).vm.supportsValidation).toBe(true)
			expect(mountEditor(base({ type: 'choice' })).vm.supportsValidation).toBe(false)
		})

		it('hasValidation reflects presence of a validation object', () => {
			expect(mountEditor(base({ validation: { pattern: '' } })).vm.hasValidation).toBe(true)
			expect(mountEditor(base()).vm.hasValidation).toBe(false)
		})
	})

	describe('setNumberField via updateQuestionNumber', () => {
		it('sets a numeric value and emits an update', () => {
			const wrapper = mountEditor(base({ type: 'scale' }))
			wrapper.vm.updateQuestionNumber('scaleMin', 3)
			expect(wrapper.vm.localQuestion.scaleMin).toBe(3)
			expect(lastUpdate(wrapper).scaleMin).toBe(3)
		})

		it('deletes the key when value is null and there is no fallback', () => {
			const wrapper = mountEditor(base({ type: 'scale', scaleMax: 5 }))
			wrapper.vm.updateQuestionNumber('scaleMax', null)
			expect('scaleMax' in wrapper.vm.localQuestion).toBe(false)
			expect('scaleMax' in lastUpdate(wrapper)).toBe(false)
		})

		it('sets 0 (a real value, not cleared)', () => {
			const wrapper = mountEditor(base({ type: 'multiple' }))
			wrapper.vm.updateQuestionNumber('minSelections', 0)
			expect(wrapper.vm.localQuestion.minSelections).toBe(0)
		})

		it('updates maxLength for a textarea', () => {
			const wrapper = mountEditor(base({ type: 'textarea' }))
			wrapper.vm.updateQuestionNumber('maxLength', 100)
			expect(wrapper.vm.localQuestion.maxLength).toBe(100)
		})
	})

	describe('updateOptionCapacity', () => {
		it('sets a capacity value', () => {
			const q = base({ type: 'choice', options: [{ id: 'a', label: 'A', value: 'a' }] })
			const wrapper = mountEditor(q)
			const opt = wrapper.vm.localQuestion.options[0]
			wrapper.vm.updateOptionCapacity(opt, 5)
			expect(wrapper.vm.localQuestion.options[0].capacity).toBe(5)
		})

		it('deletes capacity on null (no-limit)', () => {
			const q = base({ type: 'choice', options: [{ id: 'a', label: 'A', value: 'a', capacity: 3 }] })
			const wrapper = mountEditor(q)
			const opt = wrapper.vm.localQuestion.options[0]
			wrapper.vm.updateOptionCapacity(opt, null)
			expect('capacity' in wrapper.vm.localQuestion.options[0]).toBe(false)
		})
	})

	describe('updateOptionScore', () => {
		it('sets a score value', () => {
			const q = base({ type: 'choice', options: [{ id: 'a', label: 'A', value: 'a', score: 0 }] })
			const wrapper = mountEditor(q)
			const opt = wrapper.vm.localQuestion.options[0]
			wrapper.vm.updateOptionScore(opt, 10)
			expect(wrapper.vm.localQuestion.options[0].score).toBe(10)
		})

		it('falls back to 0 on null (keeps quiz mode)', () => {
			const q = base({ type: 'choice', options: [{ id: 'a', label: 'A', value: 'a', score: 5 }] })
			const wrapper = mountEditor(q)
			const opt = wrapper.vm.localQuestion.options[0]
			wrapper.vm.updateOptionScore(opt, null)
			expect(wrapper.vm.localQuestion.options[0].score).toBe(0)
		})
	})

	describe('options add/remove', () => {
		it('addOption appends a blank option and emits', () => {
			const q = base({ type: 'choice', options: [{ id: 'a', label: 'A', value: 'a' }] })
			const wrapper = mountEditor(q)
			wrapper.vm.addOption()
			expect(wrapper.vm.localQuestion.options).toHaveLength(2)
			expect(lastUpdate(wrapper).options).toHaveLength(2)
		})

		it('removeOption removes at index', () => {
			const q = base({
				type: 'choice',
				options: [
					{ id: 'a', label: 'A', value: 'a' },
					{ id: 'b', label: 'B', value: 'b' },
				],
			})
			const wrapper = mountEditor(q)
			wrapper.vm.removeOption(0)
			expect(wrapper.vm.localQuestion.options).toHaveLength(1)
			expect(wrapper.vm.localQuestion.options[0].id).toBe('b')
		})
	})

	describe('toggleQuizMode', () => {
		it('adds score 0 to every option when enabled', () => {
			const q = base({
				type: 'choice',
				options: [{ id: 'a', label: 'A', value: 'a' }, { id: 'b', label: 'B', value: 'b' }],
			})
			const wrapper = mountEditor(q)
			wrapper.vm.toggleQuizMode(true)
			expect(wrapper.vm.localQuestion.options.every((o) => o.score === 0)).toBe(true)
		})

		it('strips scores when disabled', () => {
			const q = base({
				type: 'choice',
				options: [{ id: 'a', label: 'A', value: 'a', score: 3 }],
			})
			const wrapper = mountEditor(q)
			wrapper.vm.toggleQuizMode(false)
			expect('score' in wrapper.vm.localQuestion.options[0]).toBe(false)
		})
	})

	describe('toggleValidation', () => {
		it('creates a blank validation object when enabled', () => {
			const wrapper = mountEditor(base({ type: 'text' }))
			wrapper.vm.toggleValidation(true)
			expect(wrapper.vm.localQuestion.validation).toEqual({ pattern: '', errorMessage: '' })
		})

		it('removes validation when disabled', () => {
			const wrapper = mountEditor(base({ type: 'text', validation: { pattern: 'x' } }))
			wrapper.vm.toggleValidation(false)
			expect('validation' in wrapper.vm.localQuestion).toBe(false)
		})
	})

	describe('matrix rows/columns', () => {
		it('addRow / removeRow', () => {
			const wrapper = mountEditor(base({ type: 'matrix', rows: [{ id: 'r1', label: 'R' }], columns: [] }))
			wrapper.vm.addRow()
			expect(wrapper.vm.localQuestion.rows).toHaveLength(2)
			wrapper.vm.removeRow(0)
			expect(wrapper.vm.localQuestion.rows).toHaveLength(1)
		})

		it('addColumn assigns an incrementing value', () => {
			const wrapper = mountEditor(base({ type: 'matrix', rows: [], columns: [{ id: 'c1', label: 'C', value: 1 }] }))
			wrapper.vm.addColumn()
			expect(wrapper.vm.localQuestion.columns[1].value).toBe(2)
		})
	})

	describe('table columns', () => {
		it('addTableColumn appends a text column', () => {
			const wrapper = mountEditor(base({ type: 'table', columns: [] }))
			wrapper.vm.addTableColumn()
			expect(wrapper.vm.localQuestion.columns[0].inputType).toBe('text')
		})

		it('updateTableColumnOptions parses comma-separated options', () => {
			const wrapper = mountEditor(
				base({ type: 'table', columns: [{ id: 'c1', label: 'C', inputType: 'dropdown', optionsText: 'a, b ,c' }] }),
			)
			wrapper.vm.updateTableColumnOptions(0)
			expect(wrapper.vm.localQuestion.columns[0].options).toEqual(['a', 'b', 'c'])
		})
	})

	describe('onTypeChange — orphaned field stripping', () => {
		it('drops options when switching away from a choice type', async () => {
			const q = base({ type: 'choice', options: [{ id: 'a', label: 'A', value: 'a' }] })
			const wrapper = mountEditor(q)
			wrapper.vm.localQuestion.type = 'text'
			wrapper.vm.onTypeChange()
			expect('options' in wrapper.vm.localQuestion).toBe(false)
		})

		it('initialises scale bounds when switching to scale', () => {
			const wrapper = mountEditor(base({ type: 'text' }))
			wrapper.vm.localQuestion.type = 'scale'
			wrapper.vm.onTypeChange()
			expect(wrapper.vm.localQuestion.scaleMin).toBe(1)
			expect(wrapper.vm.localQuestion.scaleMax).toBe(5)
		})

		it('creates two blank options when switching to a choice type', () => {
			const wrapper = mountEditor(base({ type: 'text' }))
			wrapper.vm.localQuestion.type = 'choice'
			wrapper.vm.onTypeChange()
			expect(wrapper.vm.localQuestion.options).toHaveLength(2)
		})

		it('initialises file defaults when switching to file', () => {
			const wrapper = mountEditor(base({ type: 'text' }))
			wrapper.vm.localQuestion.type = 'file'
			wrapper.vm.onTypeChange()
			expect(wrapper.vm.localQuestion.maxFileSize).toBe(10)
			expect(wrapper.vm.localQuestion.maxFiles).toBe(1)
			expect(wrapper.vm.localQuestion.allowedTypePreset).toBe('all')
		})
	})

	describe('date range helpers', () => {
		it('updateDateMin stores a plain YYYY-MM-DD date for type=date', () => {
			// The editor's updateDateMin uses toISOString().split('T')[0] (UTC).
			// Use a Date at UTC noon so the UTC calendar day is unambiguous across
			// the runner's timezone — this pins the current behaviour.
			const wrapper = mountEditor(base({ type: 'date' }))
			wrapper.vm.updateDateMin(new Date(Date.UTC(2026, 4, 17, 12, 0, 0)))
			expect(wrapper.vm.localQuestion.dateMin).toBe('2026-05-17')
		})

		it('updateDateMin deletes the key when cleared', () => {
			const wrapper = mountEditor(base({ type: 'date', dateMin: '2026-01-01' }))
			wrapper.vm.updateDateMin(null)
			expect('dateMin' in wrapper.vm.localQuestion).toBe(false)
		})
	})

	describe('condition editing', () => {
		it('updateCondition sets showIf and emits', () => {
			const wrapper = mountEditor(base())
			const cond = { questionId: 'q0', operator: 'eq', value: 'x' }
			wrapper.vm.updateCondition(cond)
			expect(wrapper.vm.localQuestion.showIf).toEqual(cond)
			expect(lastUpdate(wrapper).showIf).toEqual(cond)
		})
	})

	describe('emitUpdate deep-copies', () => {
		it('emits a detached copy (not the reactive localQuestion)', () => {
			const wrapper = mountEditor(base())
			wrapper.vm.emitUpdate()
			const emitted = lastUpdate(wrapper)
			expect(emitted).not.toBe(wrapper.vm.localQuestion)
			expect(emitted.id).toBe('q1')
		})
	})

	describe('section rendering', () => {
		it('renders the section badge for type=section', () => {
			const wrapper = mountEditor(base({ type: 'section', question: 'My section' }))
			expect(wrapper.find('.section-badge').exists()).toBe(true)
		})
	})

	describe('descriptor rendering', () => {
		it('renders the info-block badge for type=descriptor', () => {
			const wrapper = mountEditor(base({ type: 'descriptor', description: 'info' }))
			expect(wrapper.find('.section-badge').exists()).toBe(true)
			expect(wrapper.classes()).toContain('is-descriptor')
		})
	})
})
