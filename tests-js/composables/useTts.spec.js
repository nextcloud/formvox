import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { useTts } from '@/composables/useTts.js'

/**
 * Tests for the useTts composable.
 *
 * buildSpeechText is pure and is tested directly. speak()/stop() drive the
 * Web Speech API, which happy-dom does not provide, so those tests install a
 * fake window.speechSynthesis + SpeechSynthesisUtterance BEFORE calling
 * useTts() (isSupported is captured at construction time).
 */
describe('useTts buildSpeechText', () => {
	const { buildSpeechText } = useTts()

	it('joins question and description with ". "', () => {
		const q = { type: 'text' }
		expect(buildSpeechText(q, 'What is your name?', 'Please be honest')).toBe('What is your name?. Please be honest')
	})

	it('omits the description when it is falsy', () => {
		const q = { type: 'text' }
		expect(buildSpeechText(q, 'Just the question', '')).toBe('Just the question')
	})

	it('appends option labels for choice questions', () => {
		const q = { type: 'choice', options: [{ label: 'Red' }, { label: 'Green' }] }
		expect(buildSpeechText(q, 'Pick one', null)).toBe('Pick one. Red, Green')
	})

	it('appends option labels for multiple and dropdown too', () => {
		const multi = { type: 'multiple', options: [{ label: 'A' }, { label: 'B' }] }
		expect(buildSpeechText(multi, 'Q', null)).toBe('Q. A, B')
		const drop = { type: 'dropdown', options: [{ label: 'X' }] }
		expect(buildSpeechText(drop, 'Q', null)).toBe('Q. X')
	})

	it('applies renderPiping to option labels when provided', () => {
		const q = { type: 'choice', options: [{ label: 'a' }, { label: 'b' }] }
		const upper = (s) => s.toUpperCase()
		expect(buildSpeechText(q, 'Q', null, upper)).toBe('Q. A, B')
	})

	it('does not add options for a choice question with no options', () => {
		const q = { type: 'choice' }
		expect(buildSpeechText(q, 'Q', null)).toBe('Q')
	})

	it('describes a scale using its bounds', () => {
		const q = { type: 'scale', scaleMin: 2, scaleMax: 8 }
		expect(buildSpeechText(q, 'Rate it', null)).toBe('Rate it. 2 - 8')
	})

	it('defaults scale bounds to 1 - 5', () => {
		const q = { type: 'scale' }
		expect(buildSpeechText(q, 'Rate it', null)).toBe('Rate it. 1 - 5')
	})

	it('includes scale min and max labels when present', () => {
		const q = { type: 'scale', scaleMin: 1, scaleMax: 5, scaleMinLabel: 'Poor', scaleMaxLabel: 'Great' }
		expect(buildSpeechText(q, 'Rate it', null)).toBe('Rate it. 1 - 5, Poor - Great')
	})

	it('describes a rating from 1 to its max', () => {
		const q = { type: 'rating', ratingMax: 10 }
		expect(buildSpeechText(q, 'Stars?', null)).toBe('Stars?. 1 - 10')
	})

	it('defaults rating max to 5', () => {
		const q = { type: 'rating' }
		expect(buildSpeechText(q, 'Stars?', null)).toBe('Stars?. 1 - 5')
	})

	it('describes a matrix by its row and column labels', () => {
		const q = {
			type: 'matrix',
			rows: [{ label: 'Speed' }, { label: 'Price' }],
			columns: [{ label: 'Low' }, { label: 'High' }],
		}
		expect(buildSpeechText(q, 'Rate each', null)).toBe('Rate each. Speed, Price. Low, High')
	})

	it('does not add matrix text without rows/columns', () => {
		const q = { type: 'matrix' }
		expect(buildSpeechText(q, 'Q', null)).toBe('Q')
	})
})

describe('useTts speak/stop — unsupported environment', () => {
	it('reports not supported when speechSynthesis is absent', () => {
		const tts = useTts()
		expect(tts.isSupported).toBe(false)
	})

	it('starts with no speaking question', () => {
		const tts = useTts()
		expect(tts.speakingQuestionId.value).toBeNull()
	})

	it('speak() is a no-op and does not set state when unsupported', () => {
		const tts = useTts()
		tts.speak('q1', 'hello')
		expect(tts.speakingQuestionId.value).toBeNull()
	})

	it('stop() is a no-op when unsupported', () => {
		const tts = useTts()
		expect(() => tts.stop()).not.toThrow()
		expect(tts.speakingQuestionId.value).toBeNull()
	})
})

describe('useTts speak/stop — supported environment', () => {
	let synth
	let utterances

	beforeEach(() => {
		utterances = []
		synth = {
			cancel: vi.fn(),
			speak: vi.fn((u) => { u._spoken = true }),
		}
		globalThis.window.speechSynthesis = synth
		globalThis.SpeechSynthesisUtterance = class {
			constructor(text) {
				this.text = text
				this.onend = null
				this.onerror = null
				utterances.push(this)
			}
		}
	})

	afterEach(() => {
		delete globalThis.window.speechSynthesis
		delete globalThis.SpeechSynthesisUtterance
	})

	it('reports supported when speechSynthesis is present', () => {
		const tts = useTts()
		expect(tts.isSupported).toBe(true)
	})

	it('cancels prior speech, speaks the text, and records the question id', () => {
		const tts = useTts()
		tts.speak('q1', 'hello there')
		expect(synth.cancel).toHaveBeenCalled()
		expect(synth.speak).toHaveBeenCalledTimes(1)
		expect(utterances.at(-1).text).toBe('hello there')
		expect(tts.speakingQuestionId.value).toBe('q1')
	})

	it('toggles off when speak() is called again for the same question', () => {
		const tts = useTts()
		tts.speak('q1', 'hello')
		expect(tts.speakingQuestionId.value).toBe('q1')
		tts.speak('q1', 'hello')
		// Second call detects the same id and stops instead of re-speaking.
		expect(tts.speakingQuestionId.value).toBeNull()
		expect(synth.speak).toHaveBeenCalledTimes(1)
	})

	it('switches to a new question when a different id is spoken', () => {
		const tts = useTts()
		tts.speak('q1', 'first')
		tts.speak('q2', 'second')
		expect(synth.speak).toHaveBeenCalledTimes(2)
		expect(tts.speakingQuestionId.value).toBe('q2')
	})

	it('clears the speaking id when the utterance ends', () => {
		const tts = useTts()
		tts.speak('q1', 'hello')
		utterances.at(-1).onend()
		expect(tts.speakingQuestionId.value).toBeNull()
	})

	it('clears the speaking id on an utterance error', () => {
		const tts = useTts()
		tts.speak('q1', 'hello')
		utterances.at(-1).onerror()
		expect(tts.speakingQuestionId.value).toBeNull()
	})

	it('stop() cancels speech and clears the speaking id', () => {
		const tts = useTts()
		tts.speak('q1', 'hello')
		tts.stop()
		expect(synth.cancel).toHaveBeenCalled()
		expect(tts.speakingQuestionId.value).toBeNull()
	})
})
