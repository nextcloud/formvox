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

	// --- Hardening additions: kill surviving core-logic mutants ---------------

	describe('isQuizMode uses .some, not .every', () => {
		it('is true when SOME (not all) options carry a numeric score', () => {
			// One scored, one not — .some => true, .every => false. Kills the
			// .some -> .every method mutant (907).
			const wrapper = mountEditor(
				base({
					type: 'choice',
					options: [
						{ id: 'a', label: 'A', value: 'a', score: 2 },
						{ id: 'b', label: 'B', value: 'b' },
					],
				}),
			)
			expect(wrapper.vm.isQuizMode).toBe(true)
		})
	})

	describe('hasValidation null-vs-undefined guard', () => {
		it('is false when validation is explicitly null (not just undefined)', () => {
			// Kills the `validation !== null` -> `true` conditional mutant (916):
			// with a real null, the second clause must contribute a false.
			const wrapper = mountEditor(base({ type: 'text', validation: null }))
			expect(wrapper.vm.hasValidation).toBe(false)
		})
	})

	describe('validationPreset watcher — pattern matching', () => {
		it('selects the matching named preset when the pattern is a known one', async () => {
			// digits_only pattern -> the digits_only option. Kills the find()
			// predicate mutants at 956 (preset.pattern === pattern).
			const wrapper = mountEditor(
				base({ type: 'text', validation: { pattern: '^[0-9]+$', errorMessage: '' } }),
			)
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.validationPreset).toBeTruthy()
			expect(wrapper.vm.validationPreset.value).toBe('digits_only')
		})

		it('falls back to the custom preset for an unknown pattern', async () => {
			// A pattern that matches no preset must resolve to the 'custom'
			// option — kills the find(opt => opt.value === 'custom') mutants (961).
			const wrapper = mountEditor(
				base({ type: 'text', validation: { pattern: '^ZZZ-unknown-999$', errorMessage: '' } }),
			)
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.validationPreset).toBeTruthy()
			expect(wrapper.vm.validationPreset.value).toBe('custom')
		})

		it('clears the preset when there is no pattern', async () => {
			const wrapper = mountEditor(
				base({ type: 'text', validation: { pattern: '', errorMessage: '' } }),
			)
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.validationPreset).toBe(null)
		})
	})

	describe('availableSections filter', () => {
		it('returns only section-typed questions other than self', () => {
			// self is a section, another section, and a plain question.
			// Kills the filter predicate mutants at 988: type === 'section'
			// AND id !== self.id.
			const self = base({ id: 'me', type: 'section', question: 'Me' })
			const other = base({ id: 'sec2', type: 'section', question: 'Other' })
			const plain = base({ id: 'q9', type: 'text', question: 'Plain' })
			const wrapper = mountEditor(self, { questions: [self, other, plain] })
			const sections = wrapper.vm.availableSections
			expect(sections).toHaveLength(1)
			expect(sections[0].id).toBe('sec2')
		})

		it('excludes the current question even if it is a section', () => {
			const self = base({ id: 'me', type: 'section' })
			const wrapper = mountEditor(self, { questions: [self] })
			expect(wrapper.vm.availableSections).toHaveLength(0)
		})
	})

	describe('otherPages filter', () => {
		it('returns an empty array when there is one page or fewer', () => {
			// Kills the guard mutants at 1001 (return [] when pages<=1).
			const wrapper = mountEditor(base(), { pages: [{ id: 'p0', title: 'One' }] })
			expect(wrapper.vm.otherPages).toEqual([])
		})

		it('returns every page except the current one, index-tagged', () => {
			// Three pages, current is index 1 -> pages 0 and 2 remain, keeping
			// their original indices. Kills 1001 (<=1 boundary) and 1004
			// (index !== currentPageIndex).
			const pages = [
				{ id: 'p0', title: 'A' },
				{ id: 'p1', title: 'B' },
				{ id: 'p2', title: 'C' },
			]
			const wrapper = mountEditor(base(), { pages, currentPageIndex: 1 })
			const result = wrapper.vm.otherPages
			expect(result.map((p) => p.index)).toEqual([0, 2])
			expect(result.map((p) => p.id)).toEqual(['p0', 'p2'])
		})
	})

	describe('assignToSection / removeFromSection / moveToPage emits', () => {
		it('assignToSection emits move-to-section with the section id', () => {
			const wrapper = mountEditor(base())
			wrapper.vm.assignToSection('sec42')
			expect(wrapper.emitted('move-to-section').at(-1)).toEqual(['sec42'])
		})

		it('removeFromSection deletes sectionId and emits update', () => {
			const wrapper = mountEditor(base({ sectionId: 's1' }))
			wrapper.vm.removeFromSection()
			expect('sectionId' in wrapper.vm.localQuestion).toBe(false)
			expect(wrapper.emitted('update')).toBeTruthy()
		})

		it('moveToPage emits move with the target index', () => {
			const wrapper = mountEditor(base())
			wrapper.vm.moveToPage(3)
			expect(wrapper.emitted('move').at(-1)).toEqual([3])
		})
	})

	describe('onTypeChange — preserves options between choice types', () => {
		it('does NOT strip options when moving choice -> multiple', () => {
			// Both are option types, so the !hasOpts strip must not fire (kills
			// the `if (!hasOpts)` -> `if (true)` mutant at 1067) and the existing
			// non-empty options must be kept (kills 1108).
			const q = base({
				type: 'choice',
				options: [
					{ id: 'a', label: 'Keep A', value: 'a' },
					{ id: 'b', label: 'Keep B', value: 'b' },
				],
			})
			const wrapper = mountEditor(q)
			wrapper.vm.localQuestion.type = 'multiple'
			wrapper.vm.onTypeChange()
			expect(wrapper.vm.localQuestion.options).toHaveLength(2)
			expect(wrapper.vm.localQuestion.options[0].label).toBe('Keep A')
		})
	})

	describe('onTypeChange — type guards only fire for their type', () => {
		it('does not set scale bounds when switching to a non-scale type', () => {
			// Kills the `type === 'scale'` -> `true` mutant at 1117.
			const wrapper = mountEditor(base({ type: 'text' }))
			wrapper.vm.localQuestion.type = 'rating'
			wrapper.vm.onTypeChange()
			expect('scaleMin' in wrapper.vm.localQuestion).toBe(false)
			expect('scaleMax' in wrapper.vm.localQuestion).toBe(false)
			// rating branch DID fire
			expect(wrapper.vm.localQuestion.ratingMax).toBe(5)
		})

		it('does not set file defaults when switching to a non-file type', () => {
			// Kills the `type === 'file'` -> `true` mutant at 1147.
			const wrapper = mountEditor(base({ type: 'text' }))
			wrapper.vm.localQuestion.type = 'scale'
			wrapper.vm.onTypeChange()
			expect('allowedTypePreset' in wrapper.vm.localQuestion).toBe(false)
			expect('maxFiles' in wrapper.vm.localQuestion).toBe(false)
		})

		it('sets allowedTypes to the "all" preset list when switching to file', () => {
			// Kills the `?? fileTypePresets.all` -> `&& fileTypePresets.all`
			// logical mutant at 1149: with no prior allowedTypes the fallback
			// must produce the concrete list, not undefined.
			const wrapper = mountEditor(base({ type: 'text' }))
			wrapper.vm.localQuestion.type = 'file'
			wrapper.vm.onTypeChange()
			expect(wrapper.vm.localQuestion.allowedTypes).toEqual(['*/*'])
		})

		it('initialises matrix rows and columns when switching to matrix', () => {
			const wrapper = mountEditor(base({ type: 'text' }))
			wrapper.vm.localQuestion.type = 'matrix'
			wrapper.vm.onTypeChange()
			expect(wrapper.vm.localQuestion.rows).toHaveLength(2)
			expect(wrapper.vm.localQuestion.columns).toHaveLength(3)
		})

		it('initialises table columns and row bounds when switching to table', () => {
			const wrapper = mountEditor(base({ type: 'text' }))
			wrapper.vm.localQuestion.type = 'table'
			wrapper.vm.onTypeChange()
			expect(wrapper.vm.localQuestion.columns).toHaveLength(2)
			expect(wrapper.vm.localQuestion.minRows).toBe(1)
			expect(wrapper.vm.localQuestion.maxRows).toBe(50)
		})

		it('strips validation when switching to a non-text type', () => {
			// Kills the strip conditional for validation.
			const wrapper = mountEditor(base({ type: 'text', validation: { pattern: 'x' } }))
			wrapper.vm.localQuestion.type = 'choice'
			wrapper.vm.onTypeChange()
			expect('validation' in wrapper.vm.localQuestion).toBe(false)
		})

		it('strips date range bounds when switching away from a date type', () => {
			const wrapper = mountEditor(base({ type: 'date', dateMin: '2026-01-01', dateMax: '2026-12-31' }))
			wrapper.vm.localQuestion.type = 'text'
			wrapper.vm.onTypeChange()
			expect('dateMin' in wrapper.vm.localQuestion).toBe(false)
			expect('dateMax' in wrapper.vm.localQuestion).toBe(false)
		})
	})

	describe('onFileTypePresetChange', () => {
		it('replaces allowedTypes with the preset list for a named preset', () => {
			const wrapper = mountEditor(base({ type: 'file', allowedTypePreset: 'images' }))
			wrapper.vm.onFileTypePresetChange()
			expect(wrapper.vm.localQuestion.allowedTypes).toContain('image/png')
		})

		it('leaves allowedTypes untouched for the custom preset', () => {
			const wrapper = mountEditor(
				base({ type: 'file', allowedTypePreset: 'custom', allowedTypes: ['.foo'] }),
			)
			wrapper.vm.onFileTypePresetChange()
			expect(wrapper.vm.localQuestion.allowedTypes).toEqual(['.foo'])
		})
	})

	describe('onCustomTypesChange', () => {
		it('parses a comma-separated list, trimming and dropping blanks', () => {
			const wrapper = mountEditor(base({ type: 'file', allowedTypePreset: 'custom' }))
			wrapper.vm.onCustomTypesChange('.pdf, , image/* ,.docx')
			expect(wrapper.vm.localQuestion.allowedTypes).toEqual(['.pdf', 'image/*', '.docx'])
			expect(wrapper.vm.customTypesString).toBe('.pdf, , image/* ,.docx')
		})
	})

	describe('addTableColumn creates the columns array when absent', () => {
		it('initialises columns then appends when localQuestion has no columns', () => {
			// Kills the `!localQuestion.columns` guard mutant at 1269: with the
			// key absent, the method must create the array rather than throw.
			const wrapper = mountEditor(base({ type: 'table' }))
			delete wrapper.vm.localQuestion.columns
			wrapper.vm.addTableColumn()
			expect(wrapper.vm.localQuestion.columns).toHaveLength(1)
			expect(wrapper.vm.localQuestion.columns[0].id.startsWith('col')).toBe(true)
		})
	})

	describe('updateTableColumnOptions edge cases', () => {
		it('drops empty entries produced by trailing/duplicate commas', () => {
			// Kills the filter(s => s.length > 0) mutants at 1293.
			const wrapper = mountEditor(
				base({ type: 'table', columns: [{ id: 'c1', label: 'C', inputType: 'dropdown', optionsText: 'a,, ,b,' }] }),
			)
			wrapper.vm.updateTableColumnOptions(0)
			expect(wrapper.vm.localQuestion.columns[0].options).toEqual(['a', 'b'])
		})

		it('is a no-op for an out-of-range column index', () => {
			// Kills the `if (col)` -> `if (true)` mutant at 1289: an undefined
			// col must not be dereferenced.
			const wrapper = mountEditor(
				base({ type: 'table', columns: [{ id: 'c1', label: 'C', inputType: 'text', optionsText: '' }] }),
			)
			expect(() => wrapper.vm.updateTableColumnOptions(99)).not.toThrow()
		})
	})

	describe('updateDateMax and datetime formatting', () => {
		it('stores a full ISO timestamp for a datetime question', () => {
			// Kills the `type === 'datetime'` branch mutants at 1300 and the
			// date/datetime distinction: a datetime keeps the time component.
			const wrapper = mountEditor(base({ type: 'datetime' }))
			const d = new Date(Date.UTC(2026, 4, 17, 9, 30, 0))
			wrapper.vm.updateDateMin(d)
			expect(wrapper.vm.localQuestion.dateMin).toBe('2026-05-17T09:30:00.000Z')
		})

		it('updateDateMax stores a plain date for type=date', () => {
			const wrapper = mountEditor(base({ type: 'date' }))
			wrapper.vm.updateDateMax(new Date(Date.UTC(2026, 4, 17, 12, 0, 0)))
			expect(wrapper.vm.localQuestion.dateMax).toBe('2026-05-17')
		})

		it('updateDateMax deletes the key when cleared', () => {
			const wrapper = mountEditor(base({ type: 'date', dateMax: '2026-01-01' }))
			wrapper.vm.updateDateMax(null)
			expect('dateMax' in wrapper.vm.localQuestion).toBe(false)
		})
	})

	describe('generated id prefixes and emitted updates', () => {
		it('addOption gives the new option an opt-prefixed id equal to its value', () => {
			const wrapper = mountEditor(base({ type: 'choice', options: [] }))
			wrapper.vm.addOption()
			const opt = wrapper.vm.localQuestion.options.at(-1)
			expect(opt.id.startsWith('opt')).toBe(true)
			expect(opt.value).toBe(opt.id)
		})

		it('addOption creates the options array when absent', () => {
			const wrapper = mountEditor(base({ type: 'choice' }))
			delete wrapper.vm.localQuestion.options
			wrapper.vm.addOption()
			expect(wrapper.vm.localQuestion.options).toHaveLength(1)
		})

		it('addRow gives the new row an r-prefixed id and emits', () => {
			const wrapper = mountEditor(base({ type: 'matrix', rows: [], columns: [] }))
			wrapper.vm.addRow()
			const before = wrapper.emitted('update').length
			expect(wrapper.vm.localQuestion.rows.at(-1).id.startsWith('r')).toBe(true)
			expect(before).toBeGreaterThan(0)
		})

		it('addRow creates the rows array when absent', () => {
			const wrapper = mountEditor(base({ type: 'matrix', columns: [] }))
			delete wrapper.vm.localQuestion.rows
			wrapper.vm.addRow()
			expect(wrapper.vm.localQuestion.rows).toHaveLength(1)
		})

		it('addColumn gives the new column a c-prefixed id', () => {
			const wrapper = mountEditor(base({ type: 'matrix', rows: [], columns: [] }))
			wrapper.vm.addColumn()
			expect(wrapper.vm.localQuestion.columns.at(-1).id.startsWith('c')).toBe(true)
		})

		it('addColumn creates the columns array when absent', () => {
			const wrapper = mountEditor(base({ type: 'matrix', rows: [] }))
			delete wrapper.vm.localQuestion.columns
			wrapper.vm.addColumn()
			expect(wrapper.vm.localQuestion.columns).toHaveLength(1)
			// first column value is length(0)+1 = 1
			expect(wrapper.vm.localQuestion.columns[0].value).toBe(1)
		})

		it('addTableColumn gives the new column a col-prefixed id and text default', () => {
			const wrapper = mountEditor(base({ type: 'table', columns: [] }))
			wrapper.vm.addTableColumn()
			const col = wrapper.vm.localQuestion.columns.at(-1)
			expect(col.id.startsWith('col')).toBe(true)
			expect(col.inputType).toBe('text')
			expect(col.options).toEqual([])
			expect(col.optionsText).toBe('')
		})
	})

	describe('mutating methods each emit an update', () => {
		const optsQ = () =>
			base({
				type: 'choice',
				options: [
					{ id: 'a', label: 'A', value: 'a' },
					{ id: 'b', label: 'B', value: 'b' },
				],
			})

		it('removeOption emits update after splicing', () => {
			const wrapper = mountEditor(optsQ())
			wrapper.vm.removeOption(0)
			expect(lastUpdate(wrapper).options).toHaveLength(1)
			expect(lastUpdate(wrapper).options[0].id).toBe('b')
		})

		it('toggleQuizMode emits the scored copy', () => {
			const wrapper = mountEditor(optsQ())
			wrapper.vm.toggleQuizMode(true)
			expect(lastUpdate(wrapper).options.every((o) => o.score === 0)).toBe(true)
		})

		it('toggleValidation emits the new validation object', () => {
			const wrapper = mountEditor(base({ type: 'text' }))
			wrapper.vm.toggleValidation(true)
			expect(lastUpdate(wrapper).validation).toEqual({ pattern: '', errorMessage: '' })
		})

		it('removeRow emits the trimmed rows', () => {
			const wrapper = mountEditor(
				base({ type: 'matrix', rows: [{ id: 'r1', label: 'R1' }, { id: 'r2', label: 'R2' }], columns: [] }),
			)
			wrapper.vm.removeRow(0)
			expect(lastUpdate(wrapper).rows).toHaveLength(1)
			expect(lastUpdate(wrapper).rows[0].id).toBe('r2')
		})

		it('removeColumn emits the trimmed columns', () => {
			const wrapper = mountEditor(
				base({ type: 'matrix', rows: [], columns: [{ id: 'c1', label: 'C1', value: 1 }, { id: 'c2', label: 'C2', value: 2 }] }),
			)
			wrapper.vm.removeColumn(1)
			expect(lastUpdate(wrapper).columns).toHaveLength(1)
			expect(lastUpdate(wrapper).columns[0].id).toBe('c1')
		})

		it('removeTableColumn emits the trimmed columns', () => {
			const wrapper = mountEditor(
				base({ type: 'table', columns: [{ id: 'x1', label: 'X1', inputType: 'text' }, { id: 'x2', label: 'X2', inputType: 'text' }] }),
			)
			wrapper.vm.removeTableColumn(0)
			expect(lastUpdate(wrapper).columns).toHaveLength(1)
			expect(lastUpdate(wrapper).columns[0].id).toBe('x2')
		})

		it('updateDescriptorAlign sets the align and emits', () => {
			const wrapper = mountEditor(base({ type: 'descriptor', description: 'hi' }))
			wrapper.vm.updateDescriptorAlign('center')
			expect(wrapper.vm.localQuestion.descriptorAlign).toBe('center')
			expect(lastUpdate(wrapper).descriptorAlign).toBe('center')
		})
	})

	describe('onValidationPresetChange', () => {
		it('applies the preset pattern and default error when a preset is picked', () => {
			const wrapper = mountEditor(base({ type: 'text', validation: { pattern: '', errorMessage: '' } }))
			wrapper.vm.onValidationPresetChange({ value: 'digits_only' })
			expect(wrapper.vm.localQuestion.validation.pattern).toBe('^[0-9]+$')
			expect(wrapper.vm.localQuestion.validation.errorMessage).toBeTruthy()
		})

		it('does not overwrite a user-customised error message', () => {
			const wrapper = mountEditor(
				base({ type: 'text', validation: { pattern: '', errorMessage: 'Mine' } }),
			)
			wrapper.vm.onValidationPresetChange({ value: 'digits_only' })
			expect(wrapper.vm.localQuestion.validation.errorMessage).toBe('Mine')
		})

		it('clears pattern and error when the preset is deselected (null)', () => {
			const wrapper = mountEditor(
				base({ type: 'text', validation: { pattern: '^[0-9]+$', errorMessage: 'x' } }),
			)
			wrapper.vm.onValidationPresetChange(null)
			expect(wrapper.vm.localQuestion.validation.pattern).toBe('')
			expect(wrapper.vm.localQuestion.validation.errorMessage).toBe('')
		})
	})
})
