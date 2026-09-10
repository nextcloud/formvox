import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import QuestionRenderer from '@/components/QuestionRenderer.vue'

/**
 * Characterization tests for QuestionRenderer — the public form input renderer.
 *
 * Focus areas per the batch brief:
 *   - safeInputValue: null/undefined -> '' at the text and number NcTextField
 *     inputs (#134), while 0 and other falsy-but-real values survive.
 *   - the #113 selection counter (multiple choice) + the maxLength char count
 *     (textarea), including code-point counting for astral characters.
 *   - emitted `update:value` events per question type.
 *   - capacity (#104) full/remaining badges and disabling.
 */

const mountQ = (question, extra = {}) =>
	mount(QuestionRenderer, {
		props: { question, ...extra },
	})

const textFieldValue = (wrapper) =>
	wrapper.get('[data-stub="NcTextField"]').attributes('value')

describe('QuestionRenderer', () => {
	describe('safeInputValue (#134) — text input', () => {
		it('renders a string value verbatim', () => {
			const wrapper = mountQ({ id: 'q1', type: 'text', question: 'Name' }, { value: 'Alice' })
			expect(textFieldValue(wrapper)).toBe('Alice')
		})

		it('coerces null to empty string (never hands NcTextField null)', () => {
			const wrapper = mountQ({ id: 'q1', type: 'text', question: 'Name' }, { value: null })
			expect(textFieldValue(wrapper)).toBe('')
		})

		it('coerces undefined to empty string', () => {
			// value defaults to '' anyway, but assert the branch explicitly
			const wrapper = mountQ({ id: 'q1', type: 'text', question: 'Name' }, { value: undefined })
			expect(textFieldValue(wrapper)).toBe('')
		})
	})

	describe('safeInputValue (#134) — number input', () => {
		it('renders a numeric value', () => {
			const wrapper = mountQ({ id: 'q1', type: 'number', question: 'Age' }, { value: 42 })
			expect(textFieldValue(wrapper)).toBe('42')
		})

		it('renders 0 as "0" (not empty)', () => {
			const wrapper = mountQ({ id: 'q1', type: 'number', question: 'Age' }, { value: 0 })
			expect(textFieldValue(wrapper)).toBe('0')
		})

		it('coerces null to empty string on the number field', () => {
			const wrapper = mountQ({ id: 'q1', type: 'number', question: 'Age' }, { value: null })
			expect(textFieldValue(wrapper)).toBe('')
		})
	})

	describe('text input emits', () => {
		it('emits update:value on input', async () => {
			const wrapper = mountQ({ id: 'q1', type: 'text', question: 'Name' }, { value: '' })
			await wrapper.get('input').setValue('Bob')
			expect(wrapper.emitted('update:value').at(-1)).toEqual(['Bob'])
		})
	})

	describe('textarea', () => {
		it('renders the current value in the textarea', () => {
			const wrapper = mountQ({ id: 'q1', type: 'textarea', question: 'Bio' }, { value: 'hello' })
			expect(wrapper.get('textarea').element.value).toBe('hello')
		})

		it('emits update:value on input', async () => {
			const wrapper = mountQ({ id: 'q1', type: 'textarea', question: 'Bio' }, { value: '' })
			const ta = wrapper.get('textarea')
			ta.element.value = 'typed'
			await ta.trigger('input')
			expect(wrapper.emitted('update:value').at(-1)).toEqual(['typed'])
		})

		it('shows no char counter when maxLength is unset', () => {
			const wrapper = mountQ({ id: 'q1', type: 'textarea', question: 'Bio' }, { value: 'abc' })
			expect(wrapper.find('.char-counter').exists()).toBe(false)
		})

		it('shows the char counter when maxLength > 0 (#113)', () => {
			const wrapper = mountQ({ id: 'q1', type: 'textarea', question: 'Bio', maxLength: 10 }, { value: 'abc' })
			const counter = wrapper.get('.char-counter')
			expect(counter.text()).toBe('3 / 10 characters')
			expect(counter.classes()).not.toContain('over-limit')
		})

		it('counts code points, not UTF-16 units, for astral characters (#10)', () => {
			// A single emoji is 2 UTF-16 code units but 1 code point.
			const wrapper = mountQ({ id: 'q1', type: 'textarea', question: 'Bio', maxLength: 10 }, { value: '😀😀' })
			expect(wrapper.get('.char-counter').text()).toBe('2 / 10 characters')
		})

		it('flags over-limit when the count exceeds maxLength', () => {
			const wrapper = mountQ({ id: 'q1', type: 'textarea', question: 'Bio', maxLength: 2 }, { value: 'abcd' })
			const counter = wrapper.get('.char-counter')
			expect(counter.classes()).toContain('over-limit')
			expect(counter.text()).toBe('4 / 2 characters')
		})

		it('treats a null value as zero characters', () => {
			const wrapper = mountQ({ id: 'q1', type: 'textarea', question: 'Bio', maxLength: 5 }, { value: null })
			expect(wrapper.get('.char-counter').text()).toBe('0 / 5 characters')
		})
	})

	describe('single choice', () => {
		const q = {
			id: 'q1',
			type: 'choice',
			question: 'Pick',
			options: [
				{ id: 'a', label: 'Apple', value: 'a' },
				{ id: 'b', label: 'Banana', value: 'b' },
			],
		}

		it('renders one radio per option', () => {
			const wrapper = mountQ(q, { value: '' })
			expect(wrapper.findAll('[data-stub="NcCheckboxRadioSwitch"]')).toHaveLength(2)
		})

		it('renders each option label (piped through renderPiping)', () => {
			const wrapper = mountQ(q, { value: '' })
			const boxes = wrapper.findAll('[data-stub="NcCheckboxRadioSwitch"]')
			expect(boxes[0].text()).toContain('Apple')
			expect(boxes[1].text()).toContain('Banana')
		})
	})

	describe('consent', () => {
		it('renders the consent label falling back through consentLabel/question', () => {
			const wrapper = mountQ({ id: 'q1', type: 'consent', consentLabel: 'I accept' }, { value: false })
			expect(wrapper.text()).toContain('I accept')
		})

		it('falls back to a default label when none provided', () => {
			const wrapper = mountQ({ id: 'q1', type: 'consent' }, { value: false })
			expect(wrapper.text()).toContain('I agree')
		})
	})

	describe('multiple choice — selection counter + limits (#113)', () => {
		const q = {
			id: 'q1',
			type: 'multiple',
			question: 'Pick some',
			maxSelections: 2,
			options: [
				{ id: 'a', label: 'A', value: 'a' },
				{ id: 'b', label: 'B', value: 'b' },
				{ id: 'c', label: 'C', value: 'c' },
			],
		}

		it('shows the selection counter when maxSelections > 0', () => {
			const wrapper = mountQ(q, { value: ['a'] })
			expect(wrapper.get('.selection-counter').text()).toBe('1 of max 2 selected')
		})

		it('does not show the counter when maxSelections is unset', () => {
			const wrapper = mountQ(
				{ ...q, maxSelections: undefined },
				{ value: ['a'] },
			)
			expect(wrapper.find('.selection-counter').exists()).toBe(false)
		})

		it('counts an empty/null value as zero selected', () => {
			const wrapper = mountQ(q, { value: null })
			expect(wrapper.get('.selection-counter').text()).toBe('0 of max 2 selected')
		})

		it('caps onMultipleUpdate at maxSelections', () => {
			const wrapper = mountQ(q, { value: ['a', 'b'] })
			wrapper.vm.onMultipleUpdate(['a', 'b', 'c'])
			expect(wrapper.emitted('update:value').at(-1)).toEqual([['a', 'b']])
		})

		it('passes through a selection within the limit', () => {
			const wrapper = mountQ(q, { value: [] })
			wrapper.vm.onMultipleUpdate(['a'])
			expect(wrapper.emitted('update:value').at(-1)).toEqual([['a']])
		})

		it('coerces a non-array update to an empty array', () => {
			const wrapper = mountQ(q, { value: [] })
			wrapper.vm.onMultipleUpdate(null)
			expect(wrapper.emitted('update:value').at(-1)).toEqual([[]])
		})

		it('disables an unchecked option once the max is reached', () => {
			const wrapper = mountQ(q, { value: ['a', 'b'] })
			expect(wrapper.vm.isOptionDisabled({ value: 'c' })).toBe(true)
			// already-checked options stay enabled
			expect(wrapper.vm.isOptionDisabled({ value: 'a' })).toBe(false)
		})
	})

	describe('capacity helpers (#104)', () => {
		const opt = { id: 'a', label: 'A', value: 'a', capacity: 3 }

		it('isOptionFull is false when capacity not reached', () => {
			const wrapper = mountQ(
				{ id: 'q1', type: 'choice', options: [opt] },
				{ value: '', answerCounts: { a: 2 } },
			)
			expect(wrapper.vm.isOptionFull(opt)).toBe(false)
		})

		it('isOptionFull is true once the count reaches capacity', () => {
			const wrapper = mountQ(
				{ id: 'q1', type: 'choice', options: [opt] },
				{ value: '', answerCounts: { a: 3 } },
			)
			expect(wrapper.vm.isOptionFull(opt)).toBe(true)
		})

		it('isOptionFull is false when no capacity is set', () => {
			const wrapper = mountQ(
				{ id: 'q1', type: 'choice', options: [{ value: 'a' }] },
				{ value: '', answerCounts: { a: 99 } },
			)
			expect(wrapper.vm.isOptionFull({ value: 'a' })).toBe(false)
		})

		it('optionRemaining returns null with no capacity', () => {
			const wrapper = mountQ(
				{ id: 'q1', type: 'choice', options: [{ value: 'a' }] },
				{ value: '' },
			)
			expect(wrapper.vm.optionRemaining({ value: 'a' })).toBe(null)
		})

		it('optionRemaining returns the remaining slots, floored at 0', () => {
			const wrapper = mountQ(
				{ id: 'q1', type: 'choice', options: [opt] },
				{ value: '', answerCounts: { a: 5 } },
			)
			expect(wrapper.vm.optionRemaining(opt)).toBe(0)
		})

		it('renders a "Full" badge for a full choice option', () => {
			const wrapper = mountQ(
				{ id: 'q1', type: 'choice', options: [opt] },
				{ value: '', answerCounts: { a: 3 } },
			)
			expect(wrapper.get('.option-badge--full').text()).toBe('Full')
		})

		it('renders a "n left" badge when slots remain', () => {
			const wrapper = mountQ(
				{ id: 'q1', type: 'choice', options: [opt] },
				{ value: '', answerCounts: { a: 1 } },
			)
			const badge = wrapper.get('.option-badge')
			expect(badge.text()).toBe('2 left')
		})
	})

	describe('normalizedOptions', () => {
		it('falls back to id when value is missing', () => {
			const wrapper = mountQ(
				{ id: 'q1', type: 'choice', options: [{ id: 'x', label: 'X' }] },
				{ value: '' },
			)
			expect(wrapper.vm.normalizedOptions[0].value).toBe('x')
		})
	})

	describe('dropdown', () => {
		const q = {
			id: 'q1',
			type: 'dropdown',
			question: 'Pick',
			options: [{ id: 'a', label: 'Apple', value: 'a' }],
		}

		it('renders a select with the placeholder plus options', () => {
			const wrapper = mountQ(q, { value: '' })
			const opts = wrapper.findAll('select.dropdown-select option')
			expect(opts).toHaveLength(2)
			expect(opts[0].text()).toContain('Select')
			expect(opts[1].text()).toContain('Apple')
		})

		it('emits update:value on change', async () => {
			const wrapper = mountQ(q, { value: '' })
			const select = wrapper.get('select.dropdown-select')
			await select.setValue('a')
			expect(wrapper.emitted('update:value').at(-1)).toEqual(['a'])
		})
	})

	describe('scale', () => {
		it('builds the range from scaleMin/scaleMax', () => {
			const wrapper = mountQ({ id: 'q1', type: 'scale', scaleMin: 2, scaleMax: 4 }, { value: null })
			const buttons = wrapper.findAll('.scale-option')
			expect(buttons.map((b) => b.text())).toEqual(['2', '3', '4'])
		})

		it('defaults to 1..5 when bounds are unset', () => {
			const wrapper = mountQ({ id: 'q1', type: 'scale' }, { value: null })
			expect(wrapper.findAll('.scale-option')).toHaveLength(5)
		})

		it('emits the numeric value on click', async () => {
			const wrapper = mountQ({ id: 'q1', type: 'scale', scaleMin: 1, scaleMax: 3 }, { value: null })
			await wrapper.findAll('.scale-option')[1].trigger('click')
			expect(wrapper.emitted('update:value').at(-1)).toEqual([2])
		})

		it('marks the selected option', () => {
			const wrapper = mountQ({ id: 'q1', type: 'scale', scaleMin: 1, scaleMax: 3 }, { value: 2 })
			const selected = wrapper.findAll('.scale-option').filter((b) => b.classes().includes('selected'))
			expect(selected).toHaveLength(1)
			expect(selected[0].text()).toBe('2')
		})
	})

	describe('rating', () => {
		it('builds the star range from ratingMax', () => {
			const wrapper = mountQ({ id: 'q1', type: 'rating', ratingMax: 3 }, { value: 0 })
			expect(wrapper.findAll('.star-button')).toHaveLength(3)
		})

		it('emits the star number on click', async () => {
			const wrapper = mountQ({ id: 'q1', type: 'rating', ratingMax: 5 }, { value: 0 })
			await wrapper.findAll('.star-button')[2].trigger('click')
			expect(wrapper.emitted('update:value').at(-1)).toEqual([3])
		})

		it('fills stars up to the current value', () => {
			const wrapper = mountQ({ id: 'q1', type: 'rating', ratingMax: 5 }, { value: 3 })
			const filled = wrapper.findAll('.star-button').filter((b) => b.classes().includes('filled'))
			expect(filled).toHaveLength(3)
		})
	})

	describe('time', () => {
		it('renders the value and emits on input', async () => {
			const wrapper = mountQ({ id: 'q1', type: 'time', question: 'When' }, { value: '10:00' })
			const input = wrapper.get('input.time-input')
			expect(input.element.value).toBe('10:00')
			input.element.value = '11:30'
			await input.trigger('input')
			expect(wrapper.emitted('update:value').at(-1)).toEqual(['11:30'])
		})
	})

	describe('matrix', () => {
		const q = {
			id: 'q1',
			type: 'matrix',
			rows: [{ id: 'r1', label: 'Row 1' }],
			columns: [
				{ id: 'c1', label: 'Col 1', value: 1 },
				{ id: 'c2', label: 'Col 2', value: 2 },
			],
		}

		it('renders a cell per row/column', () => {
			const wrapper = mountQ(q, { value: {} })
			expect(wrapper.findAll('.matrix-cell')).toHaveLength(2)
		})

		it('updateMatrix merges the chosen column into the value object', () => {
			const wrapper = mountQ(q, { value: { r1: 1 } })
			wrapper.vm.updateMatrix('r1', 2)
			expect(wrapper.emitted('update:value').at(-1)).toEqual([{ r1: 2 }])
		})
	})

	describe('table (dynamic rows)', () => {
		const q = {
			id: 'q1',
			type: 'table',
			minRows: 1,
			maxRows: 3,
			columns: [{ id: 'c1', label: 'Name', inputType: 'text' }],
		}

		it('seeds minRows empty rows when value is empty', () => {
			const wrapper = mountQ(q, { value: [] })
			expect(wrapper.vm.tableRows).toHaveLength(1)
			expect(wrapper.vm.tableRows[0]).toEqual({ c1: '' })
		})

		it('addTableRow appends a blank row', () => {
			const wrapper = mountQ(q, { value: [{ c1: 'a' }] })
			wrapper.vm.addTableRow()
			expect(wrapper.emitted('update:value').at(-1)).toEqual([[{ c1: 'a' }, { c1: '' }]])
		})

		it('removeTableRow drops the row at the index', () => {
			const wrapper = mountQ(q, { value: [{ c1: 'a' }, { c1: 'b' }] })
			wrapper.vm.removeTableRow(0)
			expect(wrapper.emitted('update:value').at(-1)).toEqual([[{ c1: 'b' }]])
		})

		it('updateTableCell writes a single cell', () => {
			const wrapper = mountQ(q, { value: [{ c1: 'a' }] })
			wrapper.vm.updateTableCell(0, 'c1', 'z')
			expect(wrapper.emitted('update:value').at(-1)).toEqual([[{ c1: 'z' }]])
		})
	})

	describe('date formatting/parsing helpers', () => {
		it('formatDate uses local Y-M-D (no UTC shift, #80/#89)', () => {
			const wrapper = mountQ({ id: 'q1', type: 'date' }, { value: '' })
			const d = new Date(2026, 4, 17) // 17 May 2026 local
			expect(wrapper.vm.formatDate(d)).toBe('2026-05-17')
		})

		it('formatDate returns empty for null', () => {
			const wrapper = mountQ({ id: 'q1', type: 'date' }, { value: '' })
			expect(wrapper.vm.formatDate(null)).toBe('')
		})

		it('parseDateLocal parses YYYY-MM-DD into a local Date', () => {
			const wrapper = mountQ({ id: 'q1', type: 'date' }, { value: '' })
			const d = wrapper.vm.parseDateLocal('2026-05-17')
			expect(d.getFullYear()).toBe(2026)
			expect(d.getMonth()).toBe(4)
			expect(d.getDate()).toBe(17)
		})

		it('parseDateLocal returns null for junk', () => {
			const wrapper = mountQ({ id: 'q1', type: 'date' }, { value: '' })
			expect(wrapper.vm.parseDateLocal('nope')).toBe(null)
			expect(wrapper.vm.parseDateLocal(null)).toBe(null)
		})
	})

	describe('validation pattern', () => {
		const q = {
			id: 'q1',
			type: 'text',
			question: 'Code',
			validation: { pattern: '^[0-9]+$', errorMessage: 'Digits only' },
		}

		it('sets an error when the value does not match', () => {
			const wrapper = mountQ(q, { value: 'abc' })
			expect(wrapper.vm.validatePattern()).toBe(false)
			expect(wrapper.vm.validationError).toBe('Digits only')
		})

		it('passes when the value matches', () => {
			const wrapper = mountQ(q, { value: '123' })
			expect(wrapper.vm.validatePattern()).toBe(true)
			expect(wrapper.vm.validationError).toBe('')
		})

		it('is a no-op (returns true) when there is no value', () => {
			const wrapper = mountQ(q, { value: '' })
			expect(wrapper.vm.validatePattern()).toBe(true)
		})

		it('falls back to a default message when errorMessage is empty', () => {
			const wrapper = mountQ(
				{ ...q, validation: { pattern: '^[0-9]+$', errorMessage: '' } },
				{ value: 'x' },
			)
			wrapper.vm.validatePattern()
			expect(wrapper.vm.validationError).toBe('This field does not match the required format')
		})

		it('renders the effective error next to the text input', async () => {
			const wrapper = mountQ(q, { value: 'abc' })
			wrapper.vm.validatePattern()
			await wrapper.vm.$nextTick()
			expect(wrapper.get('.validation-error').text()).toBe('Digits only')
		})

		it('clearValidationError resets the error', () => {
			const wrapper = mountQ(q, { value: 'abc' })
			wrapper.vm.validatePattern()
			wrapper.vm.clearValidationError()
			expect(wrapper.vm.validationError).toBe('')
		})
	})

	describe('external validation error', () => {
		it('shows the external error for a type that renders no own error', () => {
			const wrapper = mountQ(
				{ id: 'q1', type: 'choice', options: [] },
				{ validationErrorExternal: 'Please pick one' },
			)
			expect(wrapper.get('.validation-error').text()).toBe('Please pick one')
		})

		it('does not duplicate the external error for own-error types', () => {
			// text renders its own error block; the catch-all block must stay quiet.
			const wrapper = mountQ(
				{ id: 'q1', type: 'text', question: 'x' },
				{ validationErrorExternal: 'external' },
			)
			expect(wrapper.findAll('.validation-error')).toHaveLength(1)
		})

		it('effectiveError prefers the internal validation error', () => {
			const wrapper = mountQ(
				{ id: 'q1', type: 'text', validation: { pattern: '^[0-9]+$', errorMessage: 'internal' } },
				{ value: 'x', validationErrorExternal: 'external' },
			)
			wrapper.vm.validatePattern()
			expect(wrapper.vm.effectiveError).toBe('internal')
		})
	})

	describe('descriptor (#64)', () => {
		it('renders the markdown description with no input', () => {
			const wrapper = mountQ({ id: 'q1', type: 'descriptor', description: '# Hello' })
			const root = wrapper.get('.question-renderer')
			expect(root.classes()).toContain('descriptor')
			expect(wrapper.html()).toContain('Hello')
			expect(wrapper.find('input').exists()).toBe(false)
		})

		it('applies the alignment class', () => {
			const wrapper = mountQ({ id: 'q1', type: 'descriptor', description: 'x', descriptorAlign: 'center' })
			expect(wrapper.get('.question-renderer').classes()).toContain('align-center')
		})
	})

	describe('piping', () => {
		it('replaces {{Q1}} with the referenced answer', () => {
			const wrapper = mountQ(
				{ id: 'q2', type: 'text', question: 'Hi {{Q1}}' },
				{
					value: '',
					allQuestions: [{ id: 'q1' }, { id: 'q2' }],
					allAnswers: { q1: 'Sam' },
				},
			)
			expect(wrapper.vm.renderedQuestion).toBe('Hi Sam')
		})

		it('leaves the token when the referenced answer is missing', () => {
			const wrapper = mountQ(
				{ id: 'q2', type: 'text', question: 'Hi {{Q1}}' },
				{ value: '', allQuestions: [{ id: 'q1' }, { id: 'q2' }], allAnswers: {} },
			)
			expect(wrapper.vm.renderedQuestion).toBe('Hi {{Q1}}')
		})

		it('joins array answers with commas', () => {
			const wrapper = mountQ(
				{ id: 'q2', type: 'text', question: '{{q1}}' },
				{ value: '', allQuestions: [{ id: 'q1' }], allAnswers: { q1: ['a', 'b'] } },
			)
			expect(wrapper.vm.renderedQuestion).toBe('a, b')
		})
	})

	describe('required indicator', () => {
		it('renders the required marker when required', () => {
			const wrapper = mountQ({ id: 'q1', type: 'text', question: 'x', required: true }, { value: '' })
			expect(wrapper.find('.required-indicator').exists()).toBe(true)
		})

		it('omits it when not required', () => {
			const wrapper = mountQ({ id: 'q1', type: 'text', question: 'x' }, { value: '' })
			expect(wrapper.find('.required-indicator').exists()).toBe(false)
		})
	})

	describe('TTS', () => {
		it('emits speak with the question id on toggle', () => {
			const wrapper = mountQ({ id: 'q1', type: 'text', question: 'x' }, { value: '', ttsSupported: true })
			wrapper.vm.toggleTts()
			expect(wrapper.emitted('speak').at(-1)).toEqual(['q1'])
		})

		it('isSpeaking reflects the speakingQuestionId prop', () => {
			const wrapper = mountQ({ id: 'q1', type: 'text', question: 'x' }, { value: '', speakingQuestionId: 'q1' })
			expect(wrapper.vm.isSpeaking).toBe(true)
		})

		it('isSpeaking is false when a different question is speaking', () => {
			// kills the isSpeaking `=== -> true` mutant (620): must depend on the id match
			const wrapper = mountQ({ id: 'q1', type: 'text', question: 'x' }, { value: '', speakingQuestionId: 'q2' })
			expect(wrapper.vm.isSpeaking).toBe(false)
		})
	})

	describe('inputId computed', () => {
		it('returns input-<id> for a non-group type and wires it to the input', () => {
			// kills 603 (empty string return) and the 600 "always group" mutants
			const wrapper = mountQ({ id: 'q9', type: 'text', question: 'x' }, { value: '' })
			expect(wrapper.vm.inputId).toBe('input-q9')
			expect(wrapper.get('[data-stub="NcTextField"]').attributes('id')).toBe('input-q9')
		})

		it.each(['choice', 'multiple', 'scale', 'rating', 'matrix'])(
			'returns undefined for group type %s',
			(type) => {
				// kills each list-member mutant at 600 (removing a type would make it defined)
				const wrapper = mountQ(
					{ id: 'q9', type, options: [], rows: [], columns: [] },
					{ value: type === 'multiple' ? [] : type === 'matrix' ? {} : '' },
				)
				expect(wrapper.vm.inputId).toBe(undefined)
			},
		)

		it('is defined for a non-group type like number/dropdown', () => {
			// counter-case: kills the `if (true)` mutant that would make every type undefined
			const wrapper = mountQ({ id: 'q9', type: 'number', question: 'x' }, { value: '' })
			expect(wrapper.vm.inputId).toBe('input-q9')
		})
	})

	describe('ariaDescribedBy computed', () => {
		it('is undefined with no description and no error', () => {
			// kills the ids seed mutant (608 ["Stryker.."]) and length>0 -> true (615)
			const wrapper = mountQ({ id: 'q1', type: 'text', question: 'x' }, { value: '' })
			expect(wrapper.vm.ariaDescribedBy).toBe(undefined)
		})

		it('lists only the description id when a description exists', () => {
			// kills 609 description branch flips + 615 join mutant
			const wrapper = mountQ(
				{ id: 'q1', type: 'text', question: 'x', description: 'help' },
				{ value: '' },
			)
			expect(wrapper.vm.ariaDescribedBy).toBe('question-desc-q1')
		})

		it('lists only the error id when an error exists', () => {
			// kills 612 error branch flips + 613 push(``) mutant
			const wrapper = mountQ(
				{ id: 'q1', type: 'text', question: 'x' },
				{ value: '', validationErrorExternal: 'bad' },
			)
			expect(wrapper.vm.ariaDescribedBy).toBe('question-error-q1')
		})

		it('joins description and error ids with a space in order', () => {
			// kills 615 join('') mutant and both branch mutants together
			const wrapper = mountQ(
				{ id: 'q1', type: 'text', question: 'x', description: 'help' },
				{ value: '', validationErrorExternal: 'bad' },
			)
			expect(wrapper.vm.ariaDescribedBy).toBe('question-desc-q1 question-error-q1')
		})
	})

	describe('getRadioTabindex (roving tabindex)', () => {
		it('puts tabindex 0 on the first scale option when nothing is selected', () => {
			// kills 632 mutants: with no selection the FIRST in range is focusable
			const wrapper = mountQ({ id: 'q1', type: 'scale', scaleMin: 1, scaleMax: 3 }, { value: null })
			const tabindexes = wrapper.findAll('.scale-option').map((b) => b.attributes('tabindex'))
			expect(tabindexes).toEqual(['0', '-1', '-1'])
		})

		it('puts tabindex 0 only on the selected scale option', () => {
			// kills 629/630 mutants: with a selection, the SELECTED one is focusable
			const wrapper = mountQ({ id: 'q1', type: 'scale', scaleMin: 1, scaleMax: 3 }, { value: 2 })
			const tabindexes = wrapper.findAll('.scale-option').map((b) => b.attributes('tabindex'))
			expect(tabindexes).toEqual(['-1', '0', '-1'])
		})

		it('roves on the rating stars too', () => {
			const wrapper = mountQ({ id: 'q1', type: 'rating', ratingMax: 4 }, { value: 3 })
			const tabindexes = wrapper.findAll('.star-button').map((b) => b.attributes('tabindex'))
			expect(tabindexes).toEqual(['-1', '-1', '0', '-1'])
		})
	})

	describe('validatePattern — optional chaining guard', () => {
		it('is a no-op returning true when the question has no validation object', () => {
			// kills 685: validation?.pattern -> validation.pattern would throw here
			const wrapper = mountQ({ id: 'q1', type: 'text', question: 'x' }, { value: 'anything' })
			expect(wrapper.vm.validatePattern()).toBe(true)
			expect(wrapper.vm.validationError).toBe('')
		})
	})

	describe('capacity boundary (#104)', () => {
		it('isOptionFull uses answerCounts via optional chaining (undefined map -> not full)', () => {
			// kills 770 optional-chaining removal: answerCounts prop defaults to {}
			const opt = { id: 'a', value: 'a', capacity: 2 }
			const wrapper = mountQ({ id: 'q1', type: 'choice', options: [opt] }, { value: '' })
			expect(wrapper.vm.isOptionFull(opt)).toBe(false)
			expect(wrapper.vm.optionRemaining(opt)).toBe(2)
		})

		it('a negative capacity is treated as no cap (kills cap<0 vs cap<=0)', () => {
			// original: !cap || cap <= 0 -> capacity 0 already covered; use a below-cap count
			// The cap<0 mutant differs only for cap===0 which !cap catches; instead assert
			// exact remaining at the boundary so the <= stays meaningful.
			const opt = { id: 'a', value: 'a', capacity: 4 }
			const wrapper = mountQ(
				{ id: 'q1', type: 'choice', options: [opt] },
				{ value: '', answerCounts: { a: 4 } },
			)
			expect(wrapper.vm.optionRemaining(opt)).toBe(0)
			expect(wrapper.vm.isOptionFull(opt)).toBe(true)
		})
	})

	describe('isOptionDisabled — capacity-full path', () => {
		const opt = { id: 'a', value: 'a', capacity: 1 }

		it('disables a full option the user has not already picked', () => {
			// kills 785 (&& -> ||, false) : full + not-checked => disabled
			const wrapper = mountQ(
				{ id: 'q1', type: 'multiple', options: [opt] },
				{ value: [], answerCounts: { a: 1 } },
			)
			expect(wrapper.vm.isOptionDisabled(opt)).toBe(true)
		})

		it('keeps a full option enabled when already selected', () => {
			// kills 785 (|| would wrongly disable an already-checked full option)
			const wrapper = mountQ(
				{ id: 'q1', type: 'multiple', options: [opt] },
				{ value: ['a'], answerCounts: { a: 1 } },
			)
			expect(wrapper.vm.isOptionDisabled(opt)).toBe(false)
		})

		it('does not disable when maxSelections is unset (kills max>=0 mutant)', () => {
			// with no maxSelections (undefined -> NaN, max>0 false), option stays enabled
			const wrapper = mountQ(
				{ id: 'q1', type: 'multiple', options: [{ id: 'b', value: 'b' }] },
				{ value: ['x', 'y', 'z'] },
			)
			expect(wrapper.vm.isOptionDisabled({ value: 'b' })).toBe(false)
		})

		it('disables exactly at the selection limit, not one before (boundary)', () => {
			// kills 787 selected.length >= max vs > max
			const q = {
				id: 'q1', type: 'multiple', maxSelections: 2,
				options: [{ id: 'c', value: 'c' }],
			}
			const oneSelected = mountQ(q, { value: ['a'] })
			expect(oneSelected.vm.isOptionDisabled({ value: 'c' })).toBe(false)
			const atLimit = mountQ(q, { value: ['a', 'b'] })
			expect(atLimit.vm.isOptionDisabled({ value: 'c' })).toBe(true)
		})
	})

	describe('onMultipleUpdate — boundary + side effect', () => {
		const q = {
			id: 'q1', type: 'multiple', maxSelections: 2,
			options: [{ id: 'a', value: 'a' }, { id: 'b', value: 'b' }],
		}

		it('passes through a selection exactly at the limit unchanged', () => {
			// kills 796 value.length > max vs >= max: length===max must NOT be sliced
			const wrapper = mountQ(q, { value: [] })
			wrapper.vm.onMultipleUpdate(['a', 'b'])
			expect(wrapper.emitted('update:value').at(-1)).toEqual([['a', 'b']])
		})

		it('clears a standing validation error when the selection changes', () => {
			// kills 800: removing clearValidationError() would leave the error set
			const wrapper = mountQ(
				{ ...q, validation: { pattern: '^z$', errorMessage: 'nope' } },
				{ value: 'y' },
			)
			wrapper.vm.validatePattern()
			expect(wrapper.vm.validationError).toBe('nope')
			wrapper.vm.onMultipleUpdate(['a'])
			expect(wrapper.vm.validationError).toBe('')
		})
	})

	describe('formatAnswerForDisplay via piping', () => {
		it('renders an object answer as row: value pairs (matrix piping)', () => {
			// kills 712 object branch flips: object answers must serialise, not String([object])
			const wrapper = mountQ(
				{ id: 'q2', type: 'text', question: '{{q1}}' },
				{ value: '', allQuestions: [{ id: 'q1' }], allAnswers: { q1: { r1: 'yes', r2: 'no' } } },
			)
			expect(wrapper.vm.renderedQuestion).toBe('r1: yes; r2: no')
		})

		it('leaves the token when the referenced answer is an empty array', () => {
			// kills 710 answer.length > 0 -> true/>=0 : empty array yields null (no replace)
			const wrapper = mountQ(
				{ id: 'q2', type: 'text', question: 'X {{q1}}' },
				{ value: '', allQuestions: [{ id: 'q1' }], allAnswers: { q1: [] } },
			)
			expect(wrapper.vm.renderedQuestion).toBe('X {{q1}}')
		})

		it('leaves the token when the referenced answer is an empty string', () => {
			// kills 705 answer === '' branch removal
			const wrapper = mountQ(
				{ id: 'q2', type: 'text', question: 'X {{q1}}' },
				{ value: '', allQuestions: [{ id: 'q1' }], allAnswers: { q1: '' } },
			)
			expect(wrapper.vm.renderedQuestion).toBe('X {{q1}}')
		})
	})

	describe('piping numeric reference regex', () => {
		it('resolves a two-digit {{Q12}} reference (kills \\d -> \\d+ narrowing)', () => {
			const allQuestions = Array.from({ length: 12 }, (_, i) => ({ id: `q${i + 1}` }))
			const wrapper = mountQ(
				{ id: 'q13', type: 'text', question: 'Hi {{Q12}}' },
				{ value: '', allQuestions, allAnswers: { q12: 'Twelve' } },
			)
			expect(wrapper.vm.renderedQuestion).toBe('Hi Twelve')
		})
	})

	describe('parseDateLocal — guard and anchor', () => {
		it('returns null for a non-string truthy value (kills || -> &&)', () => {
			// a number is truthy but not a string; original returns null, && mutant would proceed
			const wrapper = mountQ({ id: 'q1', type: 'date' }, { value: '' })
			expect(wrapper.vm.parseDateLocal(20260517)).toBe(null)
		})

		it('parses when the date prefix is anchored at the start', () => {
			// kills 918 anchor removal: a leading non-date prefix must NOT parse
			const wrapper = mountQ({ id: 'q1', type: 'date' }, { value: '' })
			expect(wrapper.vm.parseDateLocal('xx2026-05-17')).toBe(null)
			const ok = wrapper.vm.parseDateLocal('2026-05-17T10:00')
			expect(ok.getFullYear()).toBe(2026)
			expect(ok.getMonth()).toBe(4)
			expect(ok.getDate()).toBe(17)
		})
	})

	describe('formatDate padding', () => {
		it('zero-pads single-digit month and day', () => {
			// kills 909 padStart(2, '') -> would leave "5"/"7" unpadded
			const wrapper = mountQ({ id: 'q1', type: 'date' }, { value: '' })
			expect(wrapper.vm.formatDate(new Date(2026, 4, 7))).toBe('2026-05-07')
		})
	})

	describe('updateMatrix — preserves existing answers', () => {
		it('merges into the existing value object rather than replacing it', () => {
			// kills 864 spread mutants: prior rows must survive the update
			const wrapper = mountQ(
				{ id: 'q1', type: 'matrix', rows: [{ id: 'r1' }, { id: 'r2' }], columns: [{ id: 'c1', value: 1 }] },
				{ value: { r1: 1 } },
			)
			wrapper.vm.updateMatrix('r2', 2)
			expect(wrapper.emitted('update:value').at(-1)).toEqual([{ r1: 1, r2: 2 }])
		})
	})

	describe('keyboard navigation (handleRadioGroupKeydown)', () => {
		const scaleQ = { id: 'q1', type: 'scale', scaleMin: 1, scaleMax: 3 }

		it('ArrowRight from the current value selects the next in range', () => {
			// kills 643 boundary + currentIndex+1 arithmetic
			const wrapper = mountQ(scaleQ, { value: 1 })
			wrapper.get('.scale-options').trigger('keydown', { key: 'ArrowRight' })
			expect(wrapper.emitted('update:value').at(-1)).toEqual([2])
		})

		it('ArrowDown behaves like ArrowRight', () => {
			const wrapper = mountQ(scaleQ, { value: 2 })
			wrapper.get('.scale-options').trigger('keydown', { key: 'ArrowDown' })
			expect(wrapper.emitted('update:value').at(-1)).toEqual([3])
		})

		it('ArrowRight wraps from the last value to the first', () => {
			// kills the `? currentIndex+1 : 0` false-branch mutant
			const wrapper = mountQ(scaleQ, { value: 3 })
			wrapper.get('.scale-options').trigger('keydown', { key: 'ArrowRight' })
			expect(wrapper.emitted('update:value').at(-1)).toEqual([1])
		})

		it('ArrowLeft from the current value selects the previous', () => {
			// kills 648 currentIndex-1 arithmetic + boundary
			const wrapper = mountQ(scaleQ, { value: 2 })
			wrapper.get('.scale-options').trigger('keydown', { key: 'ArrowLeft' })
			expect(wrapper.emitted('update:value').at(-1)).toEqual([1])
		})

		it('ArrowUp wraps from the first value to the last', () => {
			// kills the `? currentIndex-1 : range.length-1` false-branch mutant
			const wrapper = mountQ(scaleQ, { value: 1 })
			wrapper.get('.scale-options').trigger('keydown', { key: 'ArrowUp' })
			expect(wrapper.emitted('update:value').at(-1)).toEqual([3])
		})

		it('Home selects the first value', () => {
			const wrapper = mountQ(scaleQ, { value: 3 })
			wrapper.get('.scale-options').trigger('keydown', { key: 'Home' })
			expect(wrapper.emitted('update:value').at(-1)).toEqual([1])
		})

		it('End selects the last value', () => {
			// kills range.length-1 arithmetic mutants
			const wrapper = mountQ(scaleQ, { value: 1 })
			wrapper.get('.scale-options').trigger('keydown', { key: 'End' })
			expect(wrapper.emitted('update:value').at(-1)).toEqual([3])
		})

		it('an unrelated key emits nothing (default: return)', () => {
			// kills the whole-body-removal + default-branch mutants
			const wrapper = mountQ(scaleQ, { value: 1 })
			wrapper.get('.scale-options').trigger('keydown', { key: 'a' })
			expect(wrapper.emitted('update:value')).toBeUndefined()
		})

		it('drives the rating group by keyboard too', () => {
			const wrapper = mountQ({ id: 'q1', type: 'rating', ratingMax: 4 }, { value: 2 })
			wrapper.get('.rating-input').trigger('keydown', { key: 'End' })
			expect(wrapper.emitted('update:value').at(-1)).toEqual([4])
		})
	})

	describe('tableRows — minRows default', () => {
		it('seeds two rows when minRows is 2 (kills minRows && 1 / true mutants)', () => {
			const wrapper = mountQ(
				{ id: 'q1', type: 'table', minRows: 2, columns: [{ id: 'c1' }] },
				{ value: [] },
			)
			expect(wrapper.vm.tableRows).toHaveLength(2)
			expect(wrapper.vm.tableRows).toEqual([{ c1: '' }, { c1: '' }])
		})
	})
})
