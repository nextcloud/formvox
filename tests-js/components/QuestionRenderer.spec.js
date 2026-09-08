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
	})
})
