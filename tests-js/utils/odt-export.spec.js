import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { unzipSync, strFromU8 } from 'fflate'
import { OdtDocument } from 'odf-kit'
import {
	generateResponseOdt,
	generateFromTemplate,
	generateAllResponsesZip,
	generateAllFromTemplateZip,
	downloadFile,
} from '@/utils/odt-export.js'

/**
 * Characterization tests for the ODT export utilities.
 *
 * The answer-formatting and table-building helpers are private, so they are
 * exercised through the public generators and asserted by unzipping the real
 * odf-kit output and inspecting content.xml. This pins the actual document
 * FormVox produces for each answer shape.
 */

const contentXml = async (form, response, fileId) => {
	const bytes = await generateResponseOdt(form, response, fileId)
	return strFromU8(unzipSync(bytes)['content.xml'])
}

const userResponse = (answers) => ({
	id: 'abcdef1234567890',
	submitted_at: '2024-01-02T10:00:00Z',
	respondent: { type: 'user', display_name: 'Alice' },
	answers,
})

const oneQuestionForm = (question) => ({ title: 'My Form', questions: [question] })

describe('generateResponseOdt — document shell', () => {
	it('returns a valid ODT zip as a Uint8Array', async () => {
		const bytes = await generateResponseOdt(oneQuestionForm({ id: 'q1', question: 'Name?' }), userResponse({ q1: 'Bob' }))
		expect(bytes).toBeInstanceOf(Uint8Array)
		const files = unzipSync(bytes)
		expect(Object.keys(files)).toContain('content.xml')
		expect(Object.keys(files)).toContain('meta.xml')
	})

	it('renders the form title and the question text', async () => {
		const xml = await contentXml(oneQuestionForm({ id: 'q1', question: 'What is your name?' }), userResponse({ q1: 'Bob' }))
		expect(xml).toContain('My Form')
		expect(xml).toContain('What is your name?')
	})

	it('shows the display name for a user respondent', async () => {
		const xml = await contentXml(oneQuestionForm({ id: 'q1', question: 'Q?' }), userResponse({ q1: 'x' }))
		expect(xml).toContain('Alice')
	})

	it('shows "Anonymous" for an anonymous respondent', async () => {
		const resp = { id: 'zz', submitted_at: '2024-01-02T10:00:00Z', respondent: { type: 'anonymous' }, answers: {} }
		const xml = await contentXml(oneQuestionForm({ id: 'q1', question: 'Q?' }), resp)
		expect(xml).toContain('Anonymous')
	})
})

describe('generateResponseOdt — scalar answer formatting', () => {
	it('renders a plain string answer', async () => {
		const xml = await contentXml(oneQuestionForm({ id: 'q1', question: 'Q?' }), userResponse({ q1: 'hello world' }))
		expect(xml).toContain('hello world')
	})

	it('renders a dash for a missing answer', async () => {
		const xml = await contentXml(oneQuestionForm({ id: 'q1', question: 'Q?' }), userResponse({}))
		expect(xml).toContain('>-<')
	})

	it('renders a dash for an empty-string answer', async () => {
		const xml = await contentXml(oneQuestionForm({ id: 'q1', question: 'Q?' }), userResponse({ q1: '' }))
		expect(xml).toContain('>-<')
	})

	it('shows the option LABEL, not the value, for a single choice', async () => {
		const q = { id: 'q1', question: 'Color?', options: [{ value: 'r', label: 'Red' }, { value: 'g', label: 'Green' }] }
		const xml = await contentXml(oneQuestionForm(q), userResponse({ q1: 'r' }))
		expect(xml).toContain('>Red<')
		expect(xml).not.toContain('>r<')
	})

	it('falls back to the raw value when no matching option exists', async () => {
		const q = { id: 'q1', question: 'Color?', options: [{ value: 'r', label: 'Red' }] }
		const xml = await contentXml(oneQuestionForm(q), userResponse({ q1: 'purple' }))
		expect(xml).toContain('purple')
	})
})

describe('generateResponseOdt — array answer formatting', () => {
	it('joins multi-choice values as their labels', async () => {
		const q = { id: 'q1', question: 'Pick', options: [{ value: 'a', label: 'Apple' }, { value: 'b', label: 'Banana' }] }
		const xml = await contentXml(oneQuestionForm(q), userResponse({ q1: ['a', 'b'] }))
		expect(xml).toContain('Apple, Banana')
	})

	it('joins a plain array of primitives with commas', async () => {
		const q = { id: 'q1', question: 'Tags' }
		const xml = await contentXml(oneQuestionForm(q), userResponse({ q1: ['x', 'y', 'z'] }))
		expect(xml).toContain('x, y, z')
	})

	it('lists uploaded file names, preferring originalName', async () => {
		const q = { id: 'q1', question: 'Files' }
		const answer = [{ filename: 'stored1.pdf', originalName: 'report.pdf' }, { filename: 'stored2.pdf' }]
		const xml = await contentXml(oneQuestionForm(q), userResponse({ q1: answer }))
		expect(xml).toContain('report.pdf, stored2.pdf')
	})
})

describe('generateResponseOdt — object answer formatting', () => {
	it('renders a single uploaded file name', async () => {
		const q = { id: 'q1', question: 'File' }
		const xml = await contentXml(oneQuestionForm(q), userResponse({ q1: { filename: 'stored.pdf', originalName: 'doc.pdf' } }))
		expect(xml).toContain('doc.pdf')
	})
})

describe('generateResponseOdt — table answers become real tables', () => {
	const tableQuestion = {
		id: 'q1',
		question: 'Items',
		type: 'table',
		columns: [{ id: 'c1', label: 'Name' }, { id: 'c2', label: 'Qty' }],
	}

	it('emits a table element with header labels and cell values', async () => {
		const xml = await contentXml(oneQuestionForm(tableQuestion), userResponse({ q1: [{ c1: 'Widget', c2: '3' }] }))
		expect(xml).toContain('table:table')
		expect(xml).toContain('>Name<')
		expect(xml).toContain('>Qty<')
		expect(xml).toContain('>Widget<')
		expect(xml).toContain('>3<')
	})

	it('fills a missing table cell with a dash', async () => {
		const xml = await contentXml(oneQuestionForm(tableQuestion), userResponse({ q1: [{ c1: 'Widget' }] }))
		expect(xml).toContain('>-<')
	})

	it('renders a table with no columns as plain text, not a table', async () => {
		const q = { id: 'q1', question: 'Items', type: 'table', columns: [] }
		const xml = await contentXml(oneQuestionForm(q), userResponse({ q1: [{ c1: 'x' }] }))
		expect(xml).not.toContain('table:table')
	})
})

describe('generateResponseOdt — matrix answers become real tables', () => {
	const matrixQuestion = {
		id: 'q1',
		question: 'Grid',
		type: 'matrix',
		rows: [{ id: 'r1', label: 'Speed' }, { id: 'r2', label: 'Price' }],
		columns: [{ value: 'low', label: 'Low' }, { value: 'high', label: 'High' }],
	}

	it('emits a table with row labels, column headers and a mark in the chosen cell', async () => {
		const xml = await contentXml(oneQuestionForm(matrixQuestion), userResponse({ q1: { r1: 'high', r2: 'low' } }))
		expect(xml).toContain('table:table')
		expect(xml).toContain('>Speed<')
		expect(xml).toContain('>Price<')
		expect(xml).toContain('>Low<')
		expect(xml).toContain('>High<')
		expect(xml).toContain('✕')
	})

	it('supports a multi-select matrix cell (array value)', async () => {
		const xml = await contentXml(oneQuestionForm(matrixQuestion), userResponse({ q1: { r1: ['low', 'high'] } }))
		expect(xml).toContain('✕')
	})
})

describe('generateFromTemplate', () => {
	let templateBytes

	beforeEach(async () => {
		const tpl = new OdtDocument()
		tpl.addParagraph('Name: {Q1}')
		tpl.addParagraph('By: {respondent_name}')
		tpl.addParagraph('Title: {form_title}')
		templateBytes = await tpl.save()
	})

	it('returns a filled Uint8Array with placeholders substituted', () => {
		const form = oneQuestionForm({ id: 'q1', question: 'Name?' })
		const out = generateFromTemplate(templateBytes, form, userResponse({ q1: 'Bob' }))
		expect(out).toBeInstanceOf(Uint8Array)
		const xml = strFromU8(unzipSync(out)['content.xml'])
		expect(xml).toContain('Bob')
		expect(xml).toContain('Alice')
		expect(xml).toContain('My Form')
		expect(xml).not.toContain('{Q1}')
	})

	it('substitutes the option label for a choice answer via its alias', () => {
		const q = { id: 'q1', question: 'Color?', options: [{ value: 'r', label: 'Red' }] }
		const out = generateFromTemplate(templateBytes, oneQuestionForm(q), userResponse({ q1: 'r' }))
		const xml = strFromU8(unzipSync(out)['content.xml'])
		expect(xml).toContain('Red')
	})
})

describe('generateAllResponsesZip', () => {
	it('bundles one ODT per response, named by sanitized title, date and id prefix', async () => {
		const form = { title: 'My Form!', questions: [{ id: 'q1', question: 'Q?' }] }
		const responses = [
			{ id: '12345678abcd', submitted_at: '2024-01-02T10:00:00Z', respondent: { type: 'user', display_name: 'A' }, answers: { q1: 'x' } },
			{ id: 'fedcba987654', submitted_at: '2024-03-04T10:00:00Z', respondent: { type: 'user', display_name: 'B' }, answers: { q1: 'y' } },
		]
		const zip = await generateAllResponsesZip(form, responses)
		const names = Object.keys(unzipSync(zip))
		expect(names).toContain('My_Form__2024-01-02_12345678.odt')
		expect(names).toContain('My_Form__2024-03-04_fedcba98.odt')
	})

	it('defaults the file prefix to "form" when the title is empty', async () => {
		const form = { title: '', questions: [{ id: 'q1', question: 'Q?' }] }
		const responses = [{ id: 'abcdef01', submitted_at: '2024-01-02T10:00:00Z', respondent: { type: 'user', display_name: 'A' }, answers: {} }]
		const zip = await generateAllResponsesZip(form, responses)
		expect(Object.keys(unzipSync(zip))[0]).toBe('form_2024-01-02_abcdef01.odt')
	})

	it('produces an empty zip for no responses', async () => {
		const zip = await generateAllResponsesZip({ title: 'F', questions: [] }, [])
		expect(Object.keys(unzipSync(zip))).toHaveLength(0)
	})
})

describe('generateAllFromTemplateZip', () => {
	it('bundles one filled ODT per response with the same naming scheme', async () => {
		const tpl = new OdtDocument()
		tpl.addParagraph('{Q1}')
		const templateBytes = await tpl.save()
		const form = { title: 'Form', questions: [{ id: 'q1', question: 'Q?' }] }
		const responses = [
			{ id: '11112222aaaa', submitted_at: '2024-05-06T10:00:00Z', respondent: { type: 'user', display_name: 'A' }, answers: { q1: 'hello' } },
		]
		const zip = await generateAllFromTemplateZip(templateBytes, form, responses)
		const files = unzipSync(zip)
		const names = Object.keys(files)
		expect(names).toContain('Form_2024-05-06_11112222.odt')
		const xml = strFromU8(unzipSync(files[names[0]])['content.xml'])
		expect(xml).toContain('hello')
	})
})

describe('downloadFile', () => {
	let clickSpy
	let createObjectURL
	let revokeObjectURL

	beforeEach(() => {
		clickSpy = vi.fn()
		createObjectURL = vi.fn(() => 'blob:fake')
		revokeObjectURL = vi.fn()
		globalThis.URL.createObjectURL = createObjectURL
		globalThis.URL.revokeObjectURL = revokeObjectURL
		vi.spyOn(document, 'createElement').mockImplementation((tag) => {
			if (tag === 'a') {
				return { href: '', download: '', click: clickSpy }
			}
			return document.createElement.wrappedMethod
				? document.createElement.wrappedMethod.call(document, tag)
				: {}
		})
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.URL.createObjectURL
		delete globalThis.URL.revokeObjectURL
	})

	it('creates an object URL, clicks a download anchor, then revokes the URL', () => {
		const bytes = new Uint8Array([1, 2, 3])
		downloadFile(bytes, 'out.odt')
		expect(createObjectURL).toHaveBeenCalledTimes(1)
		expect(clickSpy).toHaveBeenCalledTimes(1)
		expect(revokeObjectURL).toHaveBeenCalledWith('blob:fake')
	})

	it('sets the anchor download name to the given filename', () => {
		let anchor
		document.createElement.mockImplementation(() => {
			anchor = { href: '', download: '', click: clickSpy }
			return anchor
		})
		downloadFile(new Uint8Array([1]), 'myfile.odt')
		expect(anchor.download).toBe('myfile.odt')
		expect(anchor.href).toBe('blob:fake')
	})
})
