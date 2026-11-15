/**
 * A scale's or a rating's range. Older forms (the demo template, AI forms) said min/max/minLabel/maxLabel;
 * a save converts those to the scaleMin/scaleMax/ratingMax names, until then both are read. A 0 is a start.
 */

const number = (value, fallback) => (value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value)) ? Number(value) : fallback)

/**
 * @param {object} question
 * @return {{min: number, max: number, minLabel: string, maxLabel: string}}
 */
export function scaleOf(question) {
	return {
		min: number(question.scaleMin ?? question.min, 1),
		max: number(question.scaleMax ?? question.max, 5),
		minLabel: question.scaleMinLabel || question.minLabel || '',
		maxLabel: question.scaleMaxLabel || question.maxLabel || '',
	}
}

/**
 * @param {object} question
 * @return {number} the number of stars
 */
export function ratingMaxOf(question) {
	return number(question.ratingMax ?? question.max, 5)
}

/**
 * Moves the older names onto the current ones, as a save does; the current ones win.
 *
 * @param {object} question changed in place
 */
export function renameOldScaleFields(question) {
	const moves = question.type === 'scale'
		? [['min', 'scaleMin'], ['max', 'scaleMax'], ['minLabel', 'scaleMinLabel'], ['maxLabel', 'scaleMaxLabel']]
		: (question.type === 'rating' ? [['max', 'ratingMax']] : [])
	for (const [from, to] of moves) {
		if (from in question) {
			if (question[to] === undefined || question[to] === null || question[to] === '') {
				question[to] = question[from]
			}
			delete question[from]
		}
	}
}
