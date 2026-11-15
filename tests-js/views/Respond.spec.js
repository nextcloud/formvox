import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import Respond from '@/views/Respond.vue'

// The proof of work is solved by workers the test environment does not have: a solved number stands in.
vi.mock('altcha-lib', () => ({ solveChallengeWorkers: vi.fn(async () => ({ number: 7 })) }))

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

	describe("a signed-in respondent's confirmation", () => {
		const confirming = () => ({
			settings: { sendConfirmationEmail: true },
			questions: [
				{ id: 'q1', type: 'text', question: 'Email', useAsRespondentEmail: true },
				{ id: 'q2', type: 'text', question: 'Name' },
			],
		})

		it('says under the e-mail question, and there only, that it goes to the account address', () => {
			const wrapper = mountRespond(confirming(), { respondent: { email: 'alice@school.example' } })

			const notes = wrapper.findAll('.confirmation-note')
			expect(notes).toHaveLength(1)
			expect(notes[0].text()).toBe('Your confirmation goes to alice@school.example.')
			expect(wrapper.find('#question-q1 .confirmation-note').exists()).toBe(true)
		})

		it('says that none can go when the account has no address', () => {
			const wrapper = mountRespond(confirming(), { respondent: { email: '' } })

			expect(wrapper.find('.confirmation-note').text()).toBe('Your account has no e-mail address, so no confirmation can be sent.')
		})

		it('shows nothing of it to an anonymous respondent', () => {
			const wrapper = mountRespond(confirming())

			expect(wrapper.find('.confirmation-note').exists()).toBe(false)
		})
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

	it("shows the form's own branding images with the link's token, and the default ones as they are (qual#87)", () => {
		const image = (id, imageUrl) => ({ id, type: 'image', settings: { imageId: id, imageUrl } })
		const wrapper = mountRespond({}, {
			fileId: 7,
			token: 'a b',
			branding: { layout: { header: [
				image('own', '/apps/formvox/branding/7/image/own?t=1'),
				image('default', '/apps/formvox/branding/image/default'),
				image('other', '/apps/formvox/branding/8/image/other'),
			] } },
		})
		const sources = wrapper.findAll('.zone-header img').map((img) => img.attributes('src'))
		expect(sources).toEqual([
			'/apps/formvox/branding/7/image/own?t=1&token=a%20b',
			'/apps/formvox/branding/image/default',
			'/apps/formvox/branding/8/image/other',
		])
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

	describe('what the respondent sees, as the server works it out', () => {
		const routed = () => ({
			questions: [
				{ id: 'q1', type: 'text' },
				{ id: 'q2', type: 'text', required: true },
				{ id: 'q3', type: 'text' },
			],
			pages: [
				{ id: 'p1', title: '', questions: ['q1'], routing: [{ questionId: 'q1', operator: 'equals', value: 'skip', targetPageId: 'p3' }] },
				{ id: 'p2', title: '', questions: ['q2'] },
				{ id: 'p3', title: '', questions: ['q3'] },
			],
		})

		it('Next follows the routing', () => {
			const wrapper = mountRespond(routed())
			wrapper.vm.updateAnswer('q1', 'skip')
			wrapper.vm.nextPage()
			expect(wrapper.vm.currentPageIndex).toBe(2)
		})

		it('Next goes on in order without a matching rule', () => {
			const wrapper = mountRespond(routed())
			wrapper.vm.updateAnswer('q1', 'stay')
			wrapper.vm.nextPage()
			expect(wrapper.vm.currentPageIndex).toBe(1)
		})

		it('a question hidden by a hidden question stays hidden', async () => {
			const wrapper = mountRespond({
				questions: [
					{ id: 'q1', type: 'text' },
					{ id: 'q2', type: 'text', showIf: { questionId: 'q1', operator: 'equals', value: 'yes' } },
					{ id: 'q3', type: 'text', showIf: { questionId: 'q2', operator: 'equals', value: 'yes' } },
				],
			})
			wrapper.vm.updateAnswer('q1', 'yes')
			wrapper.vm.updateAnswer('q2', 'yes')
			wrapper.vm.updateAnswer('q1', 'no')
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.visibleQuestions.map(q => q.id)).toEqual(['q1'])
		})

		it('a 0 counts as an answer for what depends on it', async () => {
			const wrapper = mountRespond({
				questions: [
					{ id: 'q1', type: 'scale', scaleMin: 0, scaleMax: 10 },
					{ id: 'q2', type: 'text', showIf: { questionId: 'q1', operator: 'isNotEmpty' } },
				],
			})
			wrapper.vm.updateAnswer('q1', 0)
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.visibleQuestions.map(q => q.id)).toContain('q2')
		})

		it('the progress leaves out a page the routing skips', async () => {
			const wrapper = mountRespond(routed())
			wrapper.vm.updateAnswer('q1', 'skip')
			wrapper.vm.updateAnswer('q3', 'z')
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.realProgress).toBe(100)
		})
	})

	/**
	 * A busy server is tried again with the same submission id for a minute; never "received" without the server's
	 * confirmation (7a, 3a-5).
	 */
	describe('when the server is busy', () => {
		const busy = () => Object.assign(new Error('busy'), { response: { status: 503, data: { reason: 'busy', retryToken: 'pass' }, headers: { 'retry-after': '2' } } })
		const stored = { data: { success: true, response: { id: 'r1', submitted_at: 'now' } } }

		beforeEach(() => {
			vi.useFakeTimers()
			// No challenge in this test environment: solving it fails quietly and the post carries none.
			vi.spyOn(axios, 'get').mockRejectedValue(new Error('no challenge here'))
			vi.spyOn(console, 'warn').mockImplementation(() => {})
		})

		afterEach(() => {
			vi.useRealTimers()
			vi.restoreAllMocks()
		})

		it('tries again with the same submission id and the pass, then thanks', async () => {
			const post = vi.spyOn(axios, 'post').mockRejectedValueOnce(busy()).mockResolvedValueOnce(stored)
			const wrapper = mountRespond({ questions: [] })

			const done = wrapper.vm.submit()
			await vi.runAllTimersAsync()
			await done

			expect(post).toHaveBeenCalledTimes(2)
			const [first, second] = post.mock.calls.map(call => call[1])
			expect(first.submissionId).toMatch(/^[0-9a-f-]{36}$/)
			expect(second.submissionId).toBe(first.submissionId)
			expect([first.retryToken, second.retryToken]).toEqual([null, 'pass'])
			expect(wrapper.vm.submitted).toBe(true)
			expect(wrapper.vm.error).toBe(null)
		})

		// The server's retry pass holds only for the same answers: a retry that sent other bytes would be refused for good.
		it('sends the very same answers again, even when they change while it waits', async () => {
			const post = vi.spyOn(axios, 'post').mockRejectedValueOnce(busy()).mockResolvedValueOnce(stored)
			const wrapper = mountRespond({ questions: [{ id: 'q1', type: 'text' }, { id: 'q2', type: 'checkbox', options: [{ id: 'o1', label: 'One' }, { id: 'o2', label: 'Two' }] }] })
			wrapper.vm.updateAnswer('q1', 'Anna')
			wrapper.vm.updateAnswer('q2', ['o2', 'o1'])

			const done = wrapper.vm.submit()
			await vi.advanceTimersByTimeAsync(100)
			wrapper.vm.updateAnswer('q1', 'Bert')
			await vi.runAllTimersAsync()
			await done

			const [first, second] = post.mock.calls.map(call => call[1])
			expect(JSON.stringify(second.answers)).toBe(JSON.stringify(first.answers))
			expect(first.answers.q1).toBe('Anna')
		})

		it('says it is busy after two seconds of waiting', async () => {
			let answer
			vi.spyOn(axios, 'post').mockImplementation(() => new Promise(resolve => { answer = resolve }))
			const wrapper = mountRespond({ questions: [] })

			const done = wrapper.vm.submit()
			await vi.advanceTimersByTimeAsync(1000)
			expect(wrapper.vm.busy).toBe(false)
			await vi.advanceTimersByTimeAsync(1500)
			await wrapper.vm.$nextTick()
			expect(wrapper.find('.busy-message').exists()).toBe(true)

			answer(stored)
			await done
			expect(wrapper.vm.busy).toBe(false)
		})

		it('after a minute it is not saved, and the form stays filled in', async () => {
			vi.spyOn(axios, 'post').mockRejectedValue(busy())
			const wrapper = mountRespond({ questions: [{ id: 'q1', type: 'text' }] })
			wrapper.vm.updateAnswer('q1', 'Anna')

			const done = wrapper.vm.submit()
			await vi.advanceTimersByTimeAsync(61000)
			await done

			expect(wrapper.vm.submitted).toBe(false)
			expect(wrapper.vm.error).toBe('Not saved. Please try again.')
			expect(wrapper.vm.answers.q1).toBe('Anna')
		})

		it('shows a refusal at once and does not try it again', async () => {
			const post = vi.spyOn(axios, 'post').mockRejectedValue(Object.assign(new Error('no'), { response: { status: 409, data: { error: 'You have already submitted a response to this form' }, headers: {} } }))
			const wrapper = mountRespond({ questions: [] })

			const done = wrapper.vm.submit()
			await vi.runAllTimersAsync()
			await done

			expect(post).toHaveBeenCalledTimes(1)
			expect(wrapper.vm.error).toBe('You have already submitted a response to this form')
		})

		it('leaves no draft behind after the thanks, also with a save still pending', async () => {
			vi.spyOn(axios, 'post').mockResolvedValue(stored)
			const wrapper = mountRespond({ questions: [{ id: 'q1', type: 'text' }] })
			wrapper.vm.updateAnswer('q1', 'Anna')

			const done = wrapper.vm.submit()
			await vi.runAllTimersAsync()
			await done

			expect(localStorage.getItem('formvox-draft-1-tok')).toBe(null)
		})

		it('sends the fill again with the id of the draft it was restored from', async () => {
			localStorage.setItem('formvox-draft-1-tok', JSON.stringify({ answers: { q1: 'Anna' }, submissionId: '0f8b6c1e-aaaa-4bbb-8ccc-123456789abc', savedAt: new Date().toISOString() }))
			const post = vi.spyOn(axios, 'post').mockResolvedValue(stored)
			const wrapper = mountRespond({ questions: [{ id: 'q1', type: 'text' }] })

			const done = wrapper.vm.submit()
			await vi.runAllTimersAsync()
			await done

			expect(post.mock.calls[0][1].submissionId).toBe('0f8b6c1e-aaaa-4bbb-8ccc-123456789abc')
		})

		it("sends the respondent's time zone, for the times in the response's PDF", async () => {
			vi.spyOn(Intl, 'DateTimeFormat').mockReturnValue({ resolvedOptions: () => ({ timeZone: 'Europe/Berlin' }), format: () => '' })
			const post = vi.spyOn(axios, 'post').mockResolvedValue(stored)
			const wrapper = mountRespond({ questions: [{ id: 'q1', type: 'text' }] })

			const done = wrapper.vm.submit()
			await vi.runAllTimersAsync()
			await done

			expect(post.mock.calls[0][1].timezone).toBe('Europe/Berlin')
		})

		describe('files and the anti-spam proof', () => {
			const challenge = { data: { algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'sig', maxnumber: 10 } }
			const refusedForProof = () => Object.assign(new Error('no proof'), { response: { status: 429, data: { reason: 'challenge_failed', error: 'Challenge verification failed. Please refresh the page.' }, headers: {} } })
			const answer = (name, grant) => ({ data: { responseId: 'tmp', filename: name, ...(grant ? { uploadGrant: grant } : {}) } })
			const uploadsOf = (post) => post.mock.calls.filter(call => call[0].includes('/upload')).map(call => call[1])
			const withFiles = (...names) => {
				const wrapper = mountRespond({ questions: [{ id: 'f', type: 'file' }] })
				wrapper.vm.updatePendingFiles('f', names.map(name => new File([name], name)))
				return wrapper
			}
			const send = async (wrapper, ms = 0) => {
				const done = wrapper.vm.submit()
				await (ms ? vi.advanceTimersByTimeAsync(ms) : vi.runAllTimersAsync())
				await done
			}

			beforeEach(() => {
				axios.get.mockResolvedValue(challenge)
				vi.stubGlobal('fetch', vi.fn(async () => ({ text: async () => '' })))
				URL.createObjectURL = vi.fn(() => 'blob:worker')
				URL.revokeObjectURL = vi.fn()
			})

			afterEach(() => {
				vi.unstubAllGlobals()
			})

			it('pays the challenge for the first file and carries the pass for the next', async () => {
				const post = vi.spyOn(axios, 'post').mockImplementation(async (url, data) => url.includes('/upload') ? answer(data.get('file').name, data.get('altcha') ? 'G' : null) : stored)
				const wrapper = withFiles('a.pdf', 'b.pdf')

				await send(wrapper)

				const [first, second] = uploadsOf(post)
				expect([JSON.parse(first.get('altcha')).number, first.get('uploadGrant')]).toEqual([7, null])
				expect([second.get('altcha'), second.get('uploadGrant')]).toEqual([null, 'G'])
				expect(wrapper.vm.submitted).toBe(true)
			})

			it('tries a file again on the pass a busy answer carried, without a new challenge', async () => {
				let uploads = 0
				const post = vi.spyOn(axios, 'post').mockImplementation(async (url, data) => {
					if (!url.includes('/upload')) {
						return stored
					}
					if (++uploads === 1) {
						throw Object.assign(new Error('busy'), { response: { status: 503, data: { reason: 'busy', uploadGrant: 'B' }, headers: { 'retry-after': '2' } } })
					}
					return answer('a.pdf', 'G')
				})
				const wrapper = withFiles('a.pdf')

				await send(wrapper)

				const [first, retry] = uploadsOf(post)
				expect([JSON.parse(first.get('altcha')).number, first.get('uploadGrant')]).toEqual([7, null])
				expect([retry.get('altcha'), retry.get('uploadGrant')]).toEqual([null, 'B'])
				// One for the first file and one for the submission itself; none for the retry.
				expect(axios.get.mock.calls.filter(call => String(call[0]).includes('/challenge'))).toHaveLength(2)
				expect(wrapper.vm.submitted).toBe(true)
			})

			it('falls back to a new challenge when the pass ran out', async () => {
				let uploads = 0
				const post = vi.spyOn(axios, 'post').mockImplementation(async (url, data) => {
					if (!url.includes('/upload')) {
						return stored
					}
					uploads++
					if (uploads === 1) {
						return answer('a.pdf', 'G')
					}
					if (data.get('uploadGrant')) {
						throw refusedForProof()
					}
					return answer('b.pdf', 'H')
				})
				const wrapper = withFiles('a.pdf', 'b.pdf')

				await send(wrapper)

				const third = uploadsOf(post)[2]
				expect([JSON.parse(third.get('altcha')).number, third.get('uploadGrant')]).toEqual([7, null])
				expect(wrapper.vm.submitted).toBe(true)
			})

			// Review of the 3a fixes: sent without proof, the refusal was final.
			it('waits for a challenge it could not fetch rather than be refused for want of proof', async () => {
				axios.get.mockRejectedValueOnce(new Error('gateway timeout'))
				const post = vi.spyOn(axios, 'post').mockImplementation(async (url, data) => {
					if (!url.includes('/upload')) {
						return stored
					}
					if (!data.get('altcha') && !data.get('uploadGrant')) {
						throw refusedForProof()
					}
					return answer('a.pdf', 'G')
				})
				const wrapper = withFiles('a.pdf')

				await send(wrapper, 20000)

				expect(uploadsOf(post)).toHaveLength(2)
				expect([wrapper.vm.submitted, wrapper.vm.error]).toEqual([true, null])
			})

			it('tries the submission again when it was refused for want of a proof it could not fetch', async () => {
				axios.get.mockRejectedValueOnce(new Error('gateway timeout'))
				const post = vi.spyOn(axios, 'post').mockImplementation(async (url, data) => {
					if (!data.altcha && !data.retryToken) {
						throw refusedForProof()
					}
					return stored
				})
				const wrapper = mountRespond({ questions: [] })

				await send(wrapper, 20000)

				expect(post).toHaveBeenCalledTimes(2)
				expect(post.mock.calls[1][1].altcha.number).toBe(7)
				expect(wrapper.vm.submitted).toBe(true)
			})

			// Review of the 3a fixes: all files shared one minute, so a long first file left the next none.
			it('gives every file its own minute to get through a busy server', async () => {
				const start = Date.now()
				let firstDone = 0
				vi.spyOn(axios, 'post').mockImplementation(async (url, data) => {
					if (!url.includes('/upload')) {
						return stored
					}
					if (data.get('file').name === 'a.pdf') {
						if (Date.now() - start < 50000) {
							throw busy()
						}
						firstDone = Date.now()
						return answer('a.pdf', 'G')
					}
					if (Date.now() - firstDone < 30000) {
						throw busy()
					}
					return answer('b.pdf')
				})
				const wrapper = withFiles('a.pdf', 'b.pdf')

				await send(wrapper, 150000)

				expect([wrapper.vm.submitted, wrapper.vm.error]).toEqual([true, null])
			})

			// Review of the 3a fixes: a page left open past the day kept sending files the server had cleared.
			it('sends its files again after a day, as the server cleared them, and not before', async () => {
				const fills = []
				let submits = 0
				vi.spyOn(axios, 'post').mockImplementation(async (url, data) => {
					if (url.includes('/upload')) {
						fills.push(data.get('tempResponseId'))
						return answer('a.pdf', 'G')
					}
					submits++
					if (submits < 3) {
						throw Object.assign(new Error('no'), { response: { status: 400, data: { error: 'Please check your answers' }, headers: {} } })
					}
					return stored
				})
				const wrapper = withFiles('a.pdf')

				await send(wrapper)
				await send(wrapper)
				expect(fills).toHaveLength(1)

				vi.setSystemTime(Date.now() + 24 * 60 * 60 * 1000)
				await send(wrapper)
				expect(fills).toHaveLength(2)
				expect(fills[1]).not.toBe(fills[0])
				expect(wrapper.vm.submitted).toBe(true)
			})
		})

		describe('the draft', () => {
			const key = 'formvox-draft-1-tok'

			it('keeps the fill id in the draft it writes', async () => {
				const wrapper = mountRespond({ questions: [{ id: 'q1', type: 'text' }] })
				wrapper.vm.updateAnswer('q1', 'Anna')
				await vi.advanceTimersByTimeAsync(1500)

				expect(JSON.parse(localStorage.getItem(key)).submissionId).toMatch(/^[0-9a-f-]{36}$/)
			})

			it('removes a written draft after the thanks and gives the next fill a new id', async () => {
				const post = vi.spyOn(axios, 'post').mockResolvedValue(stored)
				const wrapper = mountRespond({ questions: [{ id: 'q1', type: 'text' }] })
				wrapper.vm.updateAnswer('q1', 'Anna')
				await vi.advanceTimersByTimeAsync(1500)
				const id = JSON.parse(localStorage.getItem(key)).submissionId

				let done = wrapper.vm.submit()
				await vi.runAllTimersAsync()
				await done
				expect(localStorage.getItem(key)).toBe(null)
				expect(post.mock.calls[0][1].submissionId).toBe(id)

				done = wrapper.vm.submit()
				await vi.runAllTimersAsync()
				await done
				expect(post.mock.calls[1][1].submissionId).not.toBe(id)
			})
		})

		it('waits and tries again rather than send a retry without proof', async () => {
			const lost = Object.assign(new Error('Network Error'), { response: undefined })
			const post = vi.spyOn(axios, 'post').mockRejectedValueOnce(lost).mockResolvedValueOnce(stored)
			const wrapper = mountRespond({ questions: [] })

			const done = wrapper.vm.submit()
			await vi.advanceTimersByTimeAsync(61000)
			await done

			// The challenge cannot be fetched here: the retry waits for the deadline instead of going out bare.
			expect(post).toHaveBeenCalledTimes(1)
			expect(wrapper.vm.error).toBe('Not saved. Please try again.')
		})
	})
})
