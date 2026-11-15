/**
 * Which questions a respondent sees, the same as the server works it out (lib/Service/FormLogic.php):
 * conditions, hidden sections, and the pages page routing leads through. Both are checked against one table
 * of cases (tests/fixtures/form-logic-cases.json), so they cannot drift apart again.
 *
 * - An answer to a hidden question does not count in other conditions.
 * - Values compare by their text, so 5 and "5" are the same; a choice rule may name the option's label or value.
 * - Empty: nothing, "", an empty list or object, false. A 0 is an answer.
 * - Greater/less than: dates (YYYY-MM-DD…) as dates, numbers as numbers, anything else never matches.
 * - Pages: from the first, each page's first matching rule leads on, otherwise the next page; a page is not
 *   visited twice. A question on no page is shown on the last one.
 */

const NUMBER = /^\s*-?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?\s*$/
const DATE = /^\d{4}-\d{2}-\d{2}/
const CHOICE_TYPES = ['choice', 'multiple', 'dropdown']

const isPlainObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v)
const isScalar = (v) => ['string', 'number', 'boolean'].includes(typeof v)

/** A value as text, the way the server writes it; null for what is no single value. */
function text(value) {
	if (typeof value === 'boolean') {
		return value ? 'true' : 'false'
	}
	if (typeof value === 'number') {
		return Number.isFinite(value) ? String(value) : null
	}
	return typeof value === 'string' ? value : null
}

/** A routing rule that is filled in; a half-made one in the editor never leads anywhere (#99). */
function isRule(rule) {
	return isPlainObject(rule)
		&& isScalar(rule.questionId) && String(rule.questionId) !== ''
		&& typeof rule.operator === 'string' && rule.operator !== ''
		&& isScalar(rule.targetPageId)
}

function isEmpty(answer) {
	return answer === undefined || answer === null || answer === '' || answer === false
		|| (Array.isArray(answer) && answer.length === 0)
		|| (isPlainObject(answer) && Object.keys(answer).length === 0)
}

/** What a rule's value may match: for a choice, both the option's value and its label (#99). */
function candidates(question, value) {
	const t = text(value)
	if (t === null) {
		return []
	}
	if (!question || !CHOICE_TYPES.includes(question.type)) {
		return [t]
	}
	for (const option of Array.isArray(question.options) ? question.options : []) {
		if (!isPlainObject(option)) {
			continue
		}
		const optionValue = text(option.value ?? option.id ?? null)
		const label = text(option.label ?? null)
		if (t === optionValue || t === label) {
			return [...new Set([optionValue, label].filter(c => c !== null))]
		}
	}
	return [t]
}

function matches(answer, list, partly) {
	const values = Array.isArray(answer) ? answer : [answer]
	for (const v of values) {
		const t = text(v)
		if (t === null) {
			continue
		}
		for (const c of list) {
			if (partly && !Array.isArray(answer) ? t.includes(c) : t === c) {
				return true
			}
		}
	}
	return false
}

/** -1, 0 or 1; null when the two cannot be compared. */
function compare(a, b) {
	if (typeof a === 'string' && typeof b === 'string' && DATE.test(a) && DATE.test(b)) {
		return a < b ? -1 : (a > b ? 1 : 0)
	}
	const x = text(a)
	const y = text(b)
	if (x === null || y === null || !NUMBER.test(x) || !NUMBER.test(y)) {
		return null
	}
	return Math.sign(Number(x) - Number(y))
}

/**
 * @param {object} condition a showIf or routing condition
 * @param {object} answers by question id
 * @param {object} questionsById
 * @return {boolean}
 */
export function evaluateCondition(condition, answers, questionsById = {}) {
	if (!isPlainObject(condition)) {
		return false
	}
	const operator = typeof condition.operator === 'string' ? condition.operator : ''
	if (Array.isArray(condition.conditions) && (operator === 'and' || operator === 'or')) {
		const results = condition.conditions.map(c => evaluateCondition(c, answers, questionsById))
		return operator === 'and' ? results.every(Boolean) : results.some(Boolean)
	}
	if (!isScalar(condition.questionId) || operator === '') {
		return false
	}

	const questionId = String(condition.questionId)
	const answer = answers[questionId] ?? null
	const value = condition.value ?? null
	const question = questionsById[questionId] ?? null

	switch (operator) {
	case 'isEmpty':
		return isEmpty(answer)
	case 'isNotEmpty':
		return !isEmpty(answer)
	case 'equals':
	case 'contains':
		return matches(answer, candidates(question, value), operator === 'contains')
	case 'notEquals':
	case 'notContains':
		return !matches(answer, candidates(question, value), operator === 'notContains')
	case 'in':
	case 'notIn': {
		if (!Array.isArray(value)) {
			return operator === 'notIn'
		}
		const allowed = value.flatMap(v => candidates(question, v))
		return matches(answer, allowed, false) === (operator === 'in')
	}
	case 'greaterThan':
	case 'lessThan': {
		const a = Array.isArray(answer) ? (answer[0] ?? null) : answer
		const order = compare(a, value)
		return order !== null && (operator === 'greaterThan' ? order > 0 : order < 0)
	}
	}
	return false
}

/** The pages in order, each with its question ids; questions on no page join the last one. */
function pagesOf(form, questions) {
	if (!Array.isArray(form.pages) || form.pages.length === 0) {
		return null
	}
	const placed = new Set()
	const pages = form.pages.map((page, p) => {
		const ids = (Array.isArray(page?.questions) ? page.questions : []).filter(isScalar).map(String)
		ids.forEach(id => placed.add(id))
		return {
			id: isScalar(page?.id) ? String(page.id) : String(p),
			questions: ids,
			routing: Array.isArray(page?.routing) ? page.routing : [],
		}
	})
	for (const q of questions) {
		if (!placed.has(String(q.id))) {
			pages[pages.length - 1].questions.push(String(q.id))
		}
	}
	return pages
}

function path(pages, answers, byId) {
	const index = new Map(pages.map((p, i) => [p.id, i]))
	const visited = []
	const seen = new Set()
	let i = 0
	while (i < pages.length && !seen.has(i)) {
		seen.add(i)
		visited.push(pages[i].id)
		let next = i + 1
		for (const rule of pages[i].routing) {
			if (!isRule(rule)) {
				continue
			}
			if (evaluateCondition({ questionId: rule.questionId, operator: rule.operator, value: rule.value ?? null }, answers, byId)) {
				const target = index.get(String(rule.targetPageId))
				if (target !== undefined && !seen.has(target)) {
					next = target
				}
				break
			}
		}
		i = next
	}
	return visited
}

function hiddenIds(questions, byId, pages, visited, answers) {
	let onVisitedPage = null
	if (pages !== null) {
		onVisitedPage = new Set()
		const visitedIds = new Set(visited)
		pages.filter(p => visitedIds.has(p.id)).forEach(p => p.questions.forEach(id => onVisitedPage.add(id)))
	}
	const hidden = new Set()
	const byCondition = new Set()
	for (const q of questions) {
		const id = String(q.id)
		const section = isScalar(q.sectionId) ? byId[String(q.sectionId)] : null
		const conditionHides = (q.showIf != null && !evaluateCondition(q.showIf, answers, byId))
			|| (section != null && section.showIf != null && !evaluateCondition(section.showIf, answers, byId))
		if (conditionHides) {
			byCondition.add(id)
		}
		if (conditionHides || (onVisitedPage !== null && !onVisitedPage.has(id))) {
			hidden.add(id)
		}
	}
	return { hidden, byCondition }
}

/**
 * @param {object} form
 * @param {object} answers by question id
 * @return {{hidden: Set<string>, hiddenByCondition: Set<string>, pages: string[]|null, questionsById: object}}
 *   hidden: hidden questions and those on skipped pages; hiddenByCondition: only those a condition hides
 */
export function analyse(form, answers) {
	const questions = (Array.isArray(form?.questions) ? form.questions : []).filter(q => isPlainObject(q) && isScalar(q.id))
	const byId = {}
	for (const q of questions) {
		byId[String(q.id)] ??= q
	}
	const pages = pagesOf(form, questions)

	// Hiding one question can hide the next that depended on it; repeat until nothing changes.
	let effective = { ...answers }
	let result = { hidden: new Set(), byCondition: new Set() }
	let visited = null
	for (let round = 0; round <= questions.length; round++) {
		visited = pages === null ? null : path(pages, effective, byId)
		result = hiddenIds(questions, byId, pages, visited, effective)
		const next = Object.fromEntries(Object.entries(answers).filter(([id]) => !result.hidden.has(id)))
		if (sameKeys(next, effective)) {
			break
		}
		effective = next
	}
	return { hidden: result.hidden, hiddenByCondition: result.byCondition, pages: visited, questionsById: byId }
}

function sameKeys(a, b) {
	const ka = Object.keys(a)
	return ka.length === Object.keys(b).length && ka.every(k => k in b)
}

/** The answers a respondent could give: those on hidden questions and skipped pages left out. */
export function visibleAnswers(form, answers) {
	const { hidden } = analyse(form, answers)
	return Object.fromEntries(Object.entries(answers).filter(([id]) => !hidden.has(id)))
}

/**
 * Where "Next" leads from a page, as the server walks the pages: its first matching rule, otherwise the next
 * page; a rule back to a page already on the way leads on to the next page instead.
 *
 * @return {number} the page index
 */
export function nextPageIndex(form, pageIndex, answers) {
	const questions = (Array.isArray(form?.questions) ? form.questions : []).filter(q => isPlainObject(q) && isScalar(q.id))
	const pages = pagesOf(form, questions)
	if (pages === null || !pages[pageIndex]) {
		return pageIndex + 1
	}
	const { questionsById, pages: visited } = analyse(form, answers)
	const effective = visibleAnswers(form, answers)
	const here = (visited || []).indexOf(pages[pageIndex].id)
	const before = new Set(here === -1 ? [pages[pageIndex].id] : visited.slice(0, here + 1))
	for (const rule of pages[pageIndex].routing) {
		if (!isRule(rule)) {
			continue
		}
		if (evaluateCondition({ questionId: rule.questionId, operator: rule.operator, value: rule.value ?? null }, effective, questionsById)) {
			const target = pages.findIndex(p => p.id === String(rule.targetPageId))
			return target === -1 || before.has(pages[target].id) ? pageIndex + 1 : target
		}
	}
	return pageIndex + 1
}
