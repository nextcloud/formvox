import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import Respond from '@/views/Respond.vue'

/**
 * Characterization tests for the Respond (form-fill) view.
 *
 * Respond takes a form prop and drives rendering, page navigation, conditional
 * visibility (evaluateCondition), per-page validation, markdown rendering and
 * the response-limit guard. It reads/writes a localStorage draft, so an
 * in-memory localStorage is installed. It does NOT fetch on mount.
 */

function installLocalStorage() {
	const store = new Map()
	const ls = {
		getItem: (k) => (store.has(k) ? store.get(k) : null),
		setItem: (k, v) => { store.set(k, String(v)) },
		removeItem: (k) => { store.delete(k) },
		clear: () => { store.clear() },
	}
	Object.defineProperty(globalThis, 'localStorage', {
		value: ls, configurable: true, writable: true,
	})
	return ls
}

const baseForm = (over = {}) => ({
	title: 'Survey',
	description: '',
	questions: [],
	settings: {},
	...over,
})

const mountRespond = (formOver = {}, props = {}) =>
	mount(Respond, {
		props: {
			fileId: 1,
			token: 'tok',
			form: baseForm(formOver),
			...props,
		},
	})

describe('views/Respond', () => {
	beforeEach(() => {
		installLocalStorage()
	})

	it('mounts and renders the form title', () => {
		const wrapper = mountRespond({ title: 'Survey' })
		expect(wrapper.find('.form-header h1').text()).toBe('Survey')
	})

	it('does not render a description block when description is empty (#134)', () => {
		const wrapper = mountRespond({ description: '' })
		expect(wrapper.find('.form-description').exists()).toBe(false)
	})

	it('does not crash on a null title/description (#134)', () => {
		const wrapper = mountRespond({ title: null, description: null })
		// Renders the form, no throw; description block absent.
		expect(wrapper.find('.form-header').exists()).toBe(true)
		expect(wrapper.find('.form-description').exists()).toBe(false)
	})

	it('renders markdown description as HTML when present', () => {
		const wrapper = mountRespond({ description: '**bold**' })
		const desc = wrapper.find('.form-description')
		expect(desc.exists()).toBe(true)
		expect(desc.html()).toContain('<strong>bold</strong>')
	})

	it('renderMarkdown adds target=_blank + rel to links (#87)', () => {
		const wrapper = mountRespond()
		const html = wrapper.vm.renderMarkdown('[x](https://e.com)')
		expect(html).toContain('target="_blank"')
		expect(html).toContain('rel="noopener noreferrer"')
	})

	it('initializes answers by question type', () => {
		const wrapper = mountRespond({
			questions: [
				{ id: 'q1', type: 'text' },
				{ id: 'q2', type: 'multiple', options: [] },
				{ id: 'q3', type: 'matrix', rows: [], columns: [] },
				{ id: 'q4', type: 'consent' },
			],
		})
		expect(wrapper.vm.answers.q1).toBe('')
		expect(wrapper.vm.answers.q2).toEqual([])
		expect(wrapper.vm.answers.q3).toEqual({})
		expect(wrapper.vm.answers.q4).toBe(false)
	})

	it('does not create answers for section / descriptor items', () => {
		const wrapper = mountRespond({
			questions: [
				{ id: 's1', type: 'section' },
				{ id: 'd1', type: 'descriptor' },
			],
		})
		expect('s1' in wrapper.vm.answers).toBe(false)
		expect('d1' in wrapper.vm.answers).toBe(false)
	})

	it('isLimitReached is false when no max is configured', () => {
		const wrapper = mountRespond({ settings: {} })
		expect(wrapper.vm.isLimitReached).toBe(false)
	})

	it('isLimitReached is true once responses reach the max', () => {
		const wrapper = mountRespond({
			settings: { max_responses: 2 },
			_index: { response_count: 2 },
		})
		expect(wrapper.vm.isLimitReached).toBe(true)
		// and renders the limit zone, not the form
		expect(wrapper.find('.limit-reached-zone').exists()).toBe(true)
		expect(wrapper.find('form').exists()).toBe(false)
	})

	it('single page: one implicit page holding all question ids', () => {
		const wrapper = mountRespond({
			questions: [{ id: 'q1', type: 'text' }, { id: 'q2', type: 'text' }],
		})
		expect(wrapper.vm.pages.length).toBe(1)
		expect(wrapper.vm.hasNextPage).toBe(false)
		expect(wrapper.vm.hasPreviousPage).toBe(false)
	})

	it('multi page: hasNextPage true on first of two pages', () => {
		const wrapper = mountRespond({
			questions: [{ id: 'q1', type: 'text' }, { id: 'q2', type: 'text' }],
			pages: [
				{ id: 'p1', questions: ['q1'] },
				{ id: 'p2', questions: ['q2'] },
			],
		})
		expect(wrapper.vm.pages.length).toBe(2)
		expect(wrapper.vm.hasNextPage).toBe(true)
	})

	it('pages: appends orphaned questions to the last page (#6)', () => {
		const wrapper = mountRespond({
			questions: [{ id: 'q1', type: 'text' }, { id: 'orphan', type: 'text' }],
			pages: [{ id: 'p1', questions: ['q1'] }],
		})
		expect(wrapper.vm.pages[0].questions).toContain('orphan')
	})

	it('updateAnswer records the value', () => {
		const wrapper = mountRespond({ questions: [{ id: 'q1', type: 'text' }] })
		wrapper.vm.updateAnswer('q1', 'hello')
		expect(wrapper.vm.answers.q1).toBe('hello')
	})

	it('nextPage blocks advancing when a required question is unanswered', () => {
		const wrapper = mountRespond({
			questions: [
				{ id: 'q1', type: 'text', required: true },
				{ id: 'q2', type: 'text' },
			],
			pages: [
				{ id: 'p1', questions: ['q1'] },
				{ id: 'p2', questions: ['q2'] },
			],
		})
		wrapper.vm.nextPage()
		expect(wrapper.vm.currentPageIndex).toBe(0)
		expect(wrapper.vm.validationErrors.q1).toBe('This question is required')
	})

	it('nextPage advances once the required question is answered', () => {
		const wrapper = mountRespond({
			questions: [
				{ id: 'q1', type: 'text', required: true },
				{ id: 'q2', type: 'text' },
			],
			pages: [
				{ id: 'p1', questions: ['q1'] },
				{ id: 'p2', questions: ['q2'] },
			],
		})
		wrapper.vm.updateAnswer('q1', 'answered')
		wrapper.vm.nextPage()
		expect(wrapper.vm.currentPageIndex).toBe(1)
	})

	it('previousPage goes back via history', () => {
		const wrapper = mountRespond({
			questions: [{ id: 'q1', type: 'text' }, { id: 'q2', type: 'text' }],
			pages: [
				{ id: 'p1', questions: ['q1'] },
				{ id: 'p2', questions: ['q2'] },
			],
		})
		wrapper.vm.nextPage()
		expect(wrapper.vm.currentPageIndex).toBe(1)
		wrapper.vm.previousPage()
		expect(wrapper.vm.currentPageIndex).toBe(0)
	})

	it('visibleQuestions hides a showIf question until its condition is met', async () => {
		const wrapper = mountRespond({
			questions: [
				{ id: 'q1', type: 'text' },
				{
					id: 'q2',
					type: 'text',
					showIf: { questionId: 'q1', operator: 'equals', value: 'go' },
				},
			],
		})
		expect(wrapper.vm.visibleQuestions.map(q => q.id)).toEqual(['q1'])
		wrapper.vm.updateAnswer('q1', 'go')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.visibleQuestions.map(q => q.id)).toEqual(['q1', 'q2'])
	})

	it('displayItems groups section children under their section', () => {
		const wrapper = mountRespond({
			questions: [
				{ id: 's1', type: 'section', question: 'Sec' },
				{ id: 'q1', type: 'text', sectionId: 's1' },
				{ id: 'q2', type: 'text' },
			],
			pages: [{ id: 'p1', questions: ['s1', 'q1', 'q2'] }],
		})
		const items = wrapper.vm.displayItems
		const section = items.find(i => i.type === 'section')
		expect(section).toBeTruthy()
		expect(section.questions.map(q => q.id)).toEqual(['q1'])
		expect(items.some(i => i.type === 'question' && i.question.id === 'q2')).toBe(true)
	})

	it('consent required: only explicit true counts as answered (#94)', () => {
		const wrapper = mountRespond({
			questions: [{ id: 'c1', type: 'consent', required: true }],
		})
		wrapper.vm.nextPage() // single page → validation via submit path is same
		// consent defaults to false → still counts as empty/required
		expect(wrapper.vm.validationErrors.c1).toBe('This question is required')
		wrapper.vm.updateAnswer('c1', true)
		wrapper.vm.nextPage()
		expect(wrapper.vm.validationErrors.c1).toBeUndefined()
	})

	it('sets no branding overrides without branding, so the instance theme shows', () => {
		// Previously this defaulted to a literal #0082c9, which meant a themed
		// instance still got Nextcloud-default blue on its public forms (#142).
		const wrapper = mountRespond()
		expect(wrapper.vm.globalStyles.primaryColor).toBeUndefined()
		expect(wrapper.vm.brandingTokens).toEqual({})
	})

	it('globalStyles reflects branding overrides', () => {
		const wrapper = mountRespond({}, { branding: { globalStyles: { primaryColor: '#ff0000' } } })
		expect(wrapper.vm.globalStyles.primaryColor).toBe('#ff0000')
	})

	it('applies branding by overriding the design tokens', () => {
		const wrapper = mountRespond({}, { branding: { globalStyles: { backgroundColor: '#eee' } } })
		expect(wrapper.vm.brandingTokens['--formvox-bg-primary']).toBe('#eee')
	})

	it('derives a readable foreground for a chosen background', () => {
		// The author sets a background but no text colour. Without deriving one,
		// a light background keeps the theme's foreground — light grey on cream
		// in dark mode (#142).
		const light = mountRespond({}, { branding: { globalStyles: { backgroundColor: '#fff8f0' } } })
		expect(light.vm.brandingTokens['--formvox-text-primary']).toBe('#1a1a1a')

		const dark = mountRespond({}, { branding: { globalStyles: { backgroundColor: '#102030' } } })
		expect(dark.vm.brandingTokens['--formvox-text-primary']).toBe('#ffffff')
	})

	it('derives a page background from the chosen background', () => {
		// The page behind the cards must follow the chosen background, not stay
		// on the Nextcloud theme colour — otherwise the cards float on blue (#142).
		const wrapper = mountRespond({}, { branding: { globalStyles: { backgroundColor: '#fff8f0' } } })
		const page = wrapper.vm.brandingTokens['--formvox-page-bg']
		expect(page).toBeTruthy()
		expect(page).not.toBe('#fff8f0') // shifted so the cards still stand out
	})

	it('sets the branding tokens on the document root so #body-public inherits them', () => {
		// #body-public (the page background element) is an ANCESTOR of this
		// component; CSS custom properties inherit downward, so the tokens must
		// live on :root, not on the form container, to reach the page background.
		document.documentElement.style.removeProperty('--formvox-page-bg')
		mountRespond({}, { branding: { globalStyles: { backgroundColor: '#fff8f0' } } })
		expect(document.documentElement.style.getPropertyValue('--formvox-page-bg')).toBeTruthy()
	})

	it('derives a readable foreground for the accent colour', () => {
		const wrapper = mountRespond({}, { branding: { globalStyles: { primaryColor: '#d8a906' } } })
		expect(wrapper.vm.brandingTokens['--formvox-accent']).toBe('#d8a906')
		expect(wrapper.vm.brandingTokens['--formvox-accent-text']).toBe('#1a1a1a')
	})

	it('leaves tokens alone for a colour it cannot parse', () => {
		const wrapper = mountRespond({}, { branding: { globalStyles: { backgroundColor: 'rebeccapurple' } } })
		expect(wrapper.vm.brandingTokens['--formvox-bg-primary']).toBe('rebeccapurple')
		expect(wrapper.vm.brandingTokens['--formvox-text-primary']).toBeUndefined()
	})

	it('preview submit emits the answers instead of posting', async () => {
		const wrapper = mountRespond(
			{ questions: [{ id: 'q1', type: 'text' }] },
			{ isPreview: true },
		)
		wrapper.vm.updateAnswer('q1', 'x')
		await wrapper.vm.submit()
		expect(wrapper.emitted('submit')).toBeTruthy()
		expect(wrapper.emitted('submit')[0][0].q1).toBe('x')
	})

	describe('evaluateCondition (via visibleQuestions)', () => {
		const withCond = (showIf, answerSetup = () => {}) => {
			const wrapper = mountRespond({
				questions: [
					{ id: 'q1', type: 'text' },
					{ id: 'q2', type: 'text', showIf },
				],
			})
			answerSetup(wrapper)
			return wrapper
		}

		it('notEquals matches when values differ', async () => {
			const w = withCond({ questionId: 'q1', operator: 'notEquals', value: 'x' })
			w.vm.updateAnswer('q1', 'y')
			await w.vm.$nextTick()
			expect(w.vm.visibleQuestions.map(q => q.id)).toContain('q2')
		})

		it('isNotEmpty matches once an answer exists', async () => {
			const w = withCond({ questionId: 'q1', operator: 'isNotEmpty' })
			expect(w.vm.visibleQuestions.map(q => q.id)).not.toContain('q2')
			w.vm.updateAnswer('q1', 'anything')
			await w.vm.$nextTick()
			expect(w.vm.visibleQuestions.map(q => q.id)).toContain('q2')
		})

		it('greaterThan compares numerically', async () => {
			const w = withCond({ questionId: 'q1', operator: 'greaterThan', value: '5' })
			w.vm.updateAnswer('q1', '10')
			await w.vm.$nextTick()
			expect(w.vm.visibleQuestions.map(q => q.id)).toContain('q2')
			w.vm.updateAnswer('q1', '3')
			await w.vm.$nextTick()
			expect(w.vm.visibleQuestions.map(q => q.id)).not.toContain('q2')
		})

		it('unknown operator never matches (#99)', async () => {
			const w = withCond({ questionId: 'q1', operator: 'bogus', value: 'x' })
			w.vm.updateAnswer('q1', 'x')
			await w.vm.$nextTick()
			expect(w.vm.visibleQuestions.map(q => q.id)).not.toContain('q2')
		})
	})
})
