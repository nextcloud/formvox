/**
 * What in a form points at a question or a page: conditions (showIf, also nested and/or groups) and page
 * routing rules. Removing a question or a page takes these along; the server refuses a condition on a
 * missing question.
 */

/**
 * A condition without the parts that use the question; null when nothing is left.
 *
 * @param {object|null} condition
 * @param {string} questionId
 * @param {{removed: number}} count
 * @return {object|null}
 */
function withoutQuestion(condition, questionId, count) {
	if (!condition || typeof condition !== 'object') {
		return condition ?? null
	}
	if (Array.isArray(condition.conditions)) {
		const conditions = condition.conditions
			.map(c => withoutQuestion(c, questionId, count))
			.filter(c => c !== null)
		return conditions.length > 0 ? { ...condition, conditions } : null
	}
	if (condition.questionId === questionId) {
		count.removed++
		return null
	}
	return condition
}

/**
 * Removes every condition and routing rule that uses the question.
 *
 * @param {object} form the form being edited; changed in place
 * @param {string} questionId
 * @return {number} how many were removed
 */
export function removeReferencesToQuestion(form, questionId) {
	const count = { removed: 0 }
	for (const question of form.questions || []) {
		if (question.showIf) {
			question.showIf = withoutQuestion(question.showIf, questionId, count)
		}
	}
	for (const page of Array.isArray(form.pages) ? form.pages : []) {
		if (Array.isArray(page.questions)) {
			page.questions = page.questions.filter(id => id !== questionId)
		}
		if (Array.isArray(page.routing)) {
			const before = page.routing.length
			page.routing = page.routing.filter(rule => rule?.questionId !== questionId)
			count.removed += before - page.routing.length
		}
	}
	return count.removed
}

/**
 * Removes every routing rule that leads to the page.
 *
 * @param {object} form the form being edited; changed in place
 * @param {string} pageId
 * @return {number} how many were removed
 */
export function removeRoutingToPage(form, pageId) {
	let removed = 0
	for (const page of Array.isArray(form.pages) ? form.pages : []) {
		if (Array.isArray(page.routing)) {
			const before = page.routing.length
			page.routing = page.routing.filter(rule => rule?.targetPageId !== pageId)
			removed += before - page.routing.length
		}
	}
	return removed
}
