import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import Results from '@/views/Results.vue'

/**
 * Characterization tests for the Results view.
 *
 * Results fetches the summary + responses on mount (axios stubbed). We assert
 * the pure derivation/formatting helpers (type predicates, label resolution,
 * answer formatting, matrix counts, pagination) and the initial render.
 */

const baseForm = (over = {}) => ({
	title: 'Poll',
	questions: [],
	...over,
})

const mountResults = (formOver = {}, props = {}) =>
	mount(Results, {
		props: {
			fileId: 5,
			form: baseForm(formOver),
			role: 'owner',
			permissions: { deleteResponses: true },
			...props,
		},
	})

describe('views/Results', () => {
	beforeEach(() => {
		// Give the on-mount loads a valid shape.
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { summary: { responseCount: 0, questions: [] }, responses: [], hasTemplate: false },
		})
	})

	it('loads a large form in pages, the rest behind the first', async () => {
		const first = Array.from({ length: 2000 }, (_, i) => ({ id: `r${i}`, answers: {} }))
		const rest = [{ id: 'r2000', answers: {} }]
		axios.get.mockImplementation((url, config) => Promise.resolve({
			data: config?.params?.after
				? { responses: rest, next: null }
				: { summary: { responseCount: 2001, questions: [] }, responses: first, next: '~2000', hasTemplate: false },
		}))

		const wrapper = mountResults()
		await flushPromises()

		const pages = axios.get.mock.calls.filter(([url]) => url.includes('/responses'))
		expect(pages.map(([, config]) => config.params)).toEqual([{ limit: 2000 }, { limit: 2000, after: '~2000' }])
		expect(wrapper.vm.responses).toHaveLength(2001)
		expect(wrapper.vm.loadingMore).toBe(false)
	})

	it('mounts and renders the form title', () => {
		const wrapper = mountResults({ title: 'Poll' })
		expect(wrapper.find('.results-header h1').text()).toBe('Poll')
	})

	it('defaults to the summary view', () => {
		const wrapper = mountResults()
		expect(wrapper.vm.view).toBe('summary')
	})

	it('type predicates classify correctly', () => {
		const wrapper = mountResults()
		expect(wrapper.vm.isChoiceType('choice')).toBe(true)
		expect(wrapper.vm.isChoiceType('consent')).toBe(true)
		expect(wrapper.vm.isChoiceType('text')).toBe(false)
		expect(wrapper.vm.isNumericType('number')).toBe(true)
		expect(wrapper.vm.isNumericType('rating')).toBe(true)
		expect(wrapper.vm.isMatrixType('matrix')).toBe(true)
		expect(wrapper.vm.isFileType('file')).toBe(true)
		expect(wrapper.vm.isTableType('table')).toBe(true)
	})

	it('answerableQuestions strips sections and descriptors', () => {
		const wrapper = mountResults({
			questions: [
				{ id: 'q1', type: 'text' },
				{ id: 's1', type: 'section' },
				{ id: 'd1', type: 'descriptor' },
				{ id: 'q2', type: 'number' },
			],
		})
		expect(wrapper.vm.answerableQuestions.map(q => q.id)).toEqual(['q1', 'q2'])
	})

	it('getChartType defaults to bar and setChartType overrides it', () => {
		const wrapper = mountResults()
		expect(wrapper.vm.getChartType('q1')).toBe('bar')
		wrapper.vm.setChartType('q1', 'pie')
		expect(wrapper.vm.getChartType('q1')).toBe('pie')
	})

	it('getPercentage returns 0 when total is 0, else a rounded percent', () => {
		const wrapper = mountResults()
		expect(wrapper.vm.getPercentage(3, 0)).toBe(0)
		expect(wrapper.vm.getPercentage(1, 3)).toBe(33)
	})

	it('getBarWidth returns a percentage string', () => {
		const wrapper = mountResults()
		expect(wrapper.vm.getBarWidth(0, 0)).toBe('0%')
		expect(wrapper.vm.getBarWidth(1, 2)).toBe('50%')
	})

	it('truncate shortens long text and passes short text through', () => {
		const wrapper = mountResults()
		expect(wrapper.vm.truncate('', 5)).toBe('')
		expect(wrapper.vm.truncate('hi', 5)).toBe('hi')
		expect(wrapper.vm.truncate('abcdefgh', 4)).toBe('abcd...')
	})

	it('findOptionLabel resolves by value, id or label', () => {
		const wrapper = mountResults()
		const opts = [{ id: 'o1', value: 'v1', label: 'One' }]
		expect(wrapper.vm.findOptionLabel(opts, 'v1')).toBe('One')
		expect(wrapper.vm.findOptionLabel(opts, 'o1')).toBe('One')
		expect(wrapper.vm.findOptionLabel(opts, 'One')).toBe('One')
		expect(wrapper.vm.findOptionLabel(opts, 'nope')).toBe(null)
		expect(wrapper.vm.findOptionLabel(null, 'v1')).toBe(null)
	})

	it('labelledAnswerCounts re-keys counts by human label', () => {
		const wrapper = mountResults()
		const q = {
			options: [{ id: 'o1', value: 'v1', label: 'Yes' }],
			answerCounts: { v1: 3 },
		}
		expect(wrapper.vm.labelledAnswerCounts(q)).toEqual({ Yes: 3 })
	})

	it('labelledAnswerCounts returns raw counts when there are no options', () => {
		const wrapper = mountResults()
		const q = { answerCounts: { a: 1 } }
		expect(wrapper.vm.labelledAnswerCounts(q)).toEqual({ a: 1 })
	})

	it('formatAnswer maps consent booleans to Yes/No/Not answered (#94)', () => {
		const wrapper = mountResults()
		const q = { type: 'consent' }
		expect(wrapper.vm.formatAnswer(true, q)).toBe('Yes')
		expect(wrapper.vm.formatAnswer(false, q)).toBe('No')
		expect(wrapper.vm.formatAnswer(undefined, q)).toBe('Not answered')
	})

	it('formatAnswer resolves single-choice labels', () => {
		const wrapper = mountResults()
		const q = { type: 'choice', options: [{ value: 'v1', label: 'Blue' }] }
		expect(wrapper.vm.formatAnswer('v1', q)).toBe('Blue')
	})

	it('formatAnswer joins array answers with resolved labels', () => {
		const wrapper = mountResults()
		const q = { type: 'multiple', options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }] }
		expect(wrapper.vm.formatAnswer(['a', 'b'], q)).toBe('A, B')
	})

	it('formatAnswer returns "Not answered" for empty scalar', () => {
		const wrapper = mountResults()
		expect(wrapper.vm.formatAnswer('', null)).toBe('Not answered')
	})

	it('isFileAnswer detects a file object and an array of files', () => {
		const wrapper = mountResults()
		expect(wrapper.vm.isFileAnswer({ filename: 'a.pdf', responseId: 'r1' })).toBeTruthy()
		expect(wrapper.vm.isFileAnswer([{ filename: 'a.pdf', responseId: 'r1' }])).toBeTruthy()
		expect(wrapper.vm.isFileAnswer('plain text')).toBeFalsy()
		expect(wrapper.vm.isFileAnswer(null)).toBe(false)
	})

	it('normalizeFileAnswer always yields an array', () => {
		const wrapper = mountResults()
		expect(wrapper.vm.normalizeFileAnswer(null)).toEqual([])
		expect(wrapper.vm.normalizeFileAnswer({ filename: 'a' })).toEqual([{ filename: 'a' }])
		expect(wrapper.vm.normalizeFileAnswer([{ filename: 'a' }])).toEqual([{ filename: 'a' }])
	})

	it('getMatrixCount / getMatrixPercentage read the row:col key', async () => {
		const wrapper = mountResults()
		// Let the on-mount load settle, then set a known responseCount.
		await flushPromises()
		wrapper.vm.summary = { responseCount: 4, questions: [] }
		await wrapper.vm.$nextTick()
		const counts = { 'r1:1': 2 }
		expect(wrapper.vm.getMatrixCount(counts, 'r1', 1)).toBe(2)
		expect(wrapper.vm.getMatrixCount(counts, 'r1', 2)).toBe(0)
		expect(wrapper.vm.getMatrixPercentage(counts, 'r1', 1)).toBe(50)
	})

	it('getMatrixRows / getMatrixColumns come from the form definition', () => {
		const wrapper = mountResults({
			questions: [{ id: 'm1', type: 'matrix', rows: [{ id: 'r1', label: 'Row' }], columns: [{ id: 'c1', label: 'Col' }] }],
		})
		expect(wrapper.vm.getMatrixRows('m1')).toEqual([{ id: 'r1', label: 'Row' }])
		expect(wrapper.vm.getMatrixColumns('m1')).toEqual([{ id: 'c1', label: 'Col' }])
		expect(wrapper.vm.getMatrixRows('missing')).toEqual([])
	})

	it('getFileDownloadUrl builds the upload path', () => {
		const wrapper = mountResults()
		expect(wrapper.vm.getFileDownloadUrl('r1', 'a.pdf'))
			.toBe('/apps/formvox/api/form/5/uploads/r1/a.pdf')
	})

	it('pagination: totalPages and paginatedResponses respect pageSize', async () => {
		const wrapper = mountResults()
		await flushPromises()
		wrapper.vm.responses = Array.from({ length: 120 }, (_, i) => ({ id: `r${i}` }))
		wrapper.vm.pageSize = 50
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.totalPages).toBe(3)
		expect(wrapper.vm.paginatedResponses.length).toBe(50)
		wrapper.vm.currentPage = 3
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.paginatedResponses.length).toBe(20)
	})

	it('totalPages is at least 1 with no responses', () => {
		const wrapper = mountResults()
		expect(wrapper.vm.totalPages).toBe(1)
	})

	it('getFilesForQuestion collects files across responses', async () => {
		const wrapper = mountResults()
		await flushPromises()
		wrapper.vm.responses = [
			{ id: 'r1', answers: { q1: { filename: 'a', responseId: 'r1' } } },
			{ id: 'r2', answers: { q1: [{ filename: 'b', responseId: 'r2' }] } },
		]
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.getFilesForQuestion('q1').map(f => f.filename)).toEqual(['a', 'b'])
	})

	// What a share without download permission sees (FormPermission::DOWNLOAD).
	it('hides exports when the user may not download', () => {
		const wrapper = mountResults({}, { permissions: { canDownload: false, editSettings: false } })
		expect(wrapper.text()).not.toContain('Export CSV')
		expect(wrapper.text()).not.toContain('Export ODT')
	})

	it('shows exports when the user may download', () => {
		const wrapper = mountResults({}, { permissions: { canDownload: true } })
		expect(wrapper.text()).toContain('Export CSV')
	})

	it('offers the ODT template only to editors', () => {
		// The menu entry; the dialog's own heading reads "ODT template" too.
		expect(mountResults({}, { permissions: { canDownload: true, editSettings: false } }).text()).not.toContain('ODT template …')
		expect(mountResults({}, { permissions: { canDownload: true, editSettings: true } }).text()).toContain('ODT template …')
	})

	// Typed answers are no longer in the index (H6); the server counts them for the summary.
	it('shows the unique answers the server counted for a text question', async () => {
		axios.get.mockResolvedValue({
			data: {
				summary: { responseCount: 3, questions: [{ id: 't', type: 'text', question: 'Name', answerCounts: {}, uniqueAnswers: 2 }] },
				responses: [],
				hasTemplate: false,
			},
		})
		const wrapper = mountResults()
		await flushPromises()

		expect(wrapper.find('.text-count').text()).toContain('2')
	})

	describe("a response's PDF", () => {
		afterEach(() => {
			vi.unstubAllGlobals()
		})

		const atLocation = () => {
			const location = { href: '' }
			vi.stubGlobal('location', location)
			Object.defineProperty(window, 'location', { value: location, configurable: true })
			return location
		}

		const loads = { data: { summary: { responseCount: 0, questions: [] }, responses: [], hasTemplate: false } }

		it("downloads one response's PDF under the name the server gives it", async () => {
			const exporter = await import('@/utils/odt-export')
			const downloadFile = vi.spyOn(exporter, 'downloadFile').mockImplementation(() => {})
			const pdf = new Blob(['%PDF-1.7'], { type: 'application/pdf' })
			axios.get.mockImplementation((url) => Promise.resolve(url.endsWith('/pdf')
				? { data: pdf, headers: { 'content-disposition': 'attachment; filename*=UTF-8\'\'Poll_2026-11-10_r-1.pdf; filename="Poll_2026-11-10_r-1.pdf"' } }
				: loads))
			const wrapper = mountResults()

			await wrapper.vm.downloadResponsePdf({ id: 'r-1' })

			expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/apps/formvox/api/form/5/responses/r-1/pdf'), { responseType: 'blob' })
			expect(downloadFile).toHaveBeenCalledWith(pdf, 'Poll_2026-11-10_r-1.pdf', 'application/pdf')
			downloadFile.mockRestore()
		})

		it('says in the app that the PDF could not be made, and stays on the page', async () => {
			const location = atLocation()
			const dialogs = await import('@nextcloud/dialogs')
			const showError = vi.spyOn(dialogs, 'showError')
			vi.spyOn(console, 'error').mockImplementation(() => {})
			axios.get.mockImplementation((url) => url.endsWith('/pdf')
				? Promise.reject({ response: { status: 500, data: new Blob(['{"error":"Something went wrong, please try again","reason":"internal_error"}']) } })
				: Promise.resolve(loads))
			const wrapper = mountResults()

			await wrapper.vm.downloadResponsePdf({ id: 'r-1' })

			expect(showError).toHaveBeenCalledWith('The PDF could not be made')
			expect(location.href).toBe('')
			showError.mockRestore()
		})

		it("shows the server's own reason when it refused the request as such", async () => {
			const dialogs = await import('@nextcloud/dialogs')
			const showError = vi.spyOn(dialogs, 'showError')
			vi.spyOn(console, 'error').mockImplementation(() => {})
			axios.get.mockImplementation((url) => url.endsWith('/pdf')
				? Promise.reject({ response: { status: 404, data: new Blob(['{"error":"Response not found","reason":"not_found"}']) } })
				: Promise.resolve(loads))
			const wrapper = mountResults()

			await wrapper.vm.downloadResponsePdf({ id: 'r-1' })

			expect(showError).toHaveBeenCalledWith('Response not found')
			showError.mockRestore()
		})

		it('exports all PDFs as one ZIP, not when there is nothing to export', async () => {
			const location = atLocation()
			const wrapper = mountResults()
			await flushPromises()

			wrapper.vm.exportPdfs()
			expect(location.href).toBe('')

			wrapper.vm.responses.push({ id: 'r-1', answers: {} })
			wrapper.vm.exportPdfs()
			expect(location.href).toContain('/apps/formvox/api/form/5/export/pdf')
		})

		it('offers the PDF download and the ZIP only where the form keeps PDFs', async () => {
			const pdfButtons = (wrapper) => wrapper.findAll('[title="Download PDF"]').length
			const permissions = { deleteResponses: true, canDownload: true }

			const without = mountResults({ settings: {} }, { permissions })
			await flushPromises()
			without.vm.view = 'responses'
			without.vm.responses.push({ id: 'r-1', answers: {} })
			await flushPromises()
			expect(without.text()).not.toContain('Export PDFs (ZIP)')
			expect(without.text()).toContain('Export CSV')
			expect(pdfButtons(without)).toBe(0)

			const keeping = mountResults({ settings: { keepResponsePdf: true } }, { permissions })
			await flushPromises()
			keeping.vm.view = 'responses'
			// The responses live outside the component instance: start this one from nothing.
			keeping.vm.responses.splice(0)
			keeping.vm.responses.push({ id: 'r-1', answers: {} })
			await flushPromises()
			expect(keeping.text()).toContain('Export PDFs (ZIP)')
			expect(pdfButtons(keeping)).toBe(1)
		})
	})
})

