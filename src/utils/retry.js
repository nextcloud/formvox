/**
 * Trying a request again while the server is busy for a moment (7a, 3a-5): a busy form (503), a gateway that gave up
 * (502, 504) or no answer at all. With the same submission id a retry is never stored twice, so even a request whose
 * answer got lost is safe to send again.
 */

/** How long a respondent's submission is tried before it is "not saved". */
export const RETRY_WINDOW_MS = 60000

/** After this long without an answer the respondent is told it is busy. */
export const BUSY_NOTICE_MS = 2000

/**
 * @param {object} err an axios error
 * @return {boolean} worth trying again
 */
export function isTransient(err) {
	return !err?.response || [502, 503, 504].includes(err.response.status)
}

/**
 * A refusal for want of an anti-spam proof, from a request sent without one because the challenge could not be
 * fetched: worth trying again in a moment, as it usually can be the next time (review of the 3a fixes).
 *
 * @param {object} err an axios error
 * @return {object} err, or an error without an answer that carries it, which isTransient tries again
 */
export function withoutProof(err) {
	if (err?.response?.status === 429 && err.response.data?.reason === 'challenge_failed') {
		return Object.assign(new Error('No anti-spam proof could be sent'), { cause: err })
	}
	return err
}

/**
 * The wait before the next try: what the server asks (Retry-After), at least two seconds, longer each time, never
 * longer than eight.
 *
 * @param {object} err the error of the last try
 * @param {number} attempt tries so far, from 0
 * @return {number} milliseconds
 */
export function retryDelay(err, attempt) {
	const asked = Number(err?.response?.headers?.['retry-after'])
	const base = Number.isFinite(asked) && asked > 0 ? asked * 1000 : 2000
	return Math.min(base * 2 ** Math.min(attempt, 2), 8000) + Math.floor(Math.random() * 500)
}

/**
 * Runs request until it gets an answer that is not busy, or the deadline passes.
 *
 * @param {function(?object): Promise} request gets the error of the try before (null the first time)
 * @param {number} deadline epoch milliseconds
 * @param {function(): void} onBusy called when a try met a busy server
 * @param {function(number): Promise} pause waits; replaced in tests
 * @return {Promise} what the request resolved to
 */
export async function withRetry(request, deadline, onBusy = () => {}, pause = (ms) => new Promise(resolve => setTimeout(resolve, ms))) {
	let lastError = null
	for (let attempt = 0; ; attempt++) {
		try {
			return await request(lastError)
		} catch (err) {
			if (!isTransient(err) || Date.now() >= deadline) {
				throw err
			}
			onBusy()
			lastError = err
			await pause(Math.max(0, Math.min(retryDelay(err, attempt), deadline - Date.now())))
		}
	}
}

/**
 * A new id for one fill of a form: crypto.randomUUID where the browser has it.
 *
 * @return {string}
 */
export function newFillId() {
	if (typeof crypto !== 'undefined' && crypto.randomUUID) {
		return crypto.randomUUID()
	}
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
		const r = Math.random() * 16 | 0
		return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16)
	})
}
