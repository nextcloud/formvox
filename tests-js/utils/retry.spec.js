import { describe, expect, it, vi } from 'vitest'
import { isTransient, newFillId, retryDelay, withRetry, withoutProof } from '../../src/utils/retry.js'

const busy = (retryAfter) => Object.assign(new Error('busy'), { response: { status: 503, data: { reason: 'busy', retryToken: 'pass' }, headers: retryAfter ? { 'retry-after': retryAfter } : {} } })
const refused = (status) => Object.assign(new Error('refused'), { response: { status, data: { reason: 'invalid_answer' }, headers: {} } })

/**
 * A busy server is tried again until it answers or a minute has passed (7a, 3a-5): busy, a gateway that gave up, or no
 * answer at all; a refusal is never tried again.
 */
describe('retry', () => {
	it('tries again only what may come out differently', () => {
		expect(isTransient(busy())).toBe(true)
		expect(isTransient(refused(502))).toBe(true)
		expect(isTransient(refused(504))).toBe(true)
		expect(isTransient(new Error('Network Error'))).toBe(true)
		for (const status of [400, 403, 404, 409, 410, 429, 500, 507]) {
			expect(isTransient(refused(status))).toBe(false)
		}
	})

	it('waits what the server asks, at least two seconds, longer each time but never more than eight', () => {
		const random = vi.spyOn(Math, 'random').mockReturnValue(0)
		expect(retryDelay(busy('2'), 0)).toBe(2000)
		expect(retryDelay(busy(), 0)).toBe(2000)
		expect(retryDelay(busy('2'), 1)).toBe(4000)
		expect(retryDelay(busy('2'), 5)).toBe(8000)
		expect(retryDelay(busy('30'), 0)).toBe(8000)
		random.mockRestore()
	})

	it('answers with the first answer that is not busy, and passes on what the busy one said', async () => {
		const seen = []
		const request = vi.fn(async (lastError) => {
			seen.push(lastError?.response?.data?.retryToken ?? null)
			if (seen.length < 3) {
				throw busy('1')
			}
			return 'stored'
		})
		const onBusy = vi.fn()

		await expect(withRetry(request, Date.now() + 60000, onBusy, async () => {})).resolves.toBe('stored')
		expect(seen).toEqual([null, 'pass', 'pass'])
		expect(onBusy).toHaveBeenCalledTimes(2)
	})

	it('gives a refusal at once', async () => {
		const request = vi.fn(async () => { throw refused(409) })

		await expect(withRetry(request, Date.now() + 60000, () => {}, async () => {})).rejects.toThrow('refused')
		expect(request).toHaveBeenCalledTimes(1)
	})

	it('gives up when the minute is over', async () => {
		const request = vi.fn(async () => { throw busy() })

		await expect(withRetry(request, Date.now() - 1, () => {}, async () => {})).rejects.toThrow('busy')
		expect(request).toHaveBeenCalledTimes(1)
	})

	it('makes a new id for every fill', () => {
		const id = newFillId()
		expect(id).toMatch(/^[0-9a-f-]{36}$/)
		expect(newFillId()).not.toBe(id)
	})
	it('tries again a refusal for want of a proof that could not be sent, and nothing else', () => {
		const refused = { response: { status: 429, data: { reason: 'challenge_failed' } } }
		const limited = { response: { status: 429, data: { reason: 'rate_limited' } } }

		expect(isTransient(withoutProof(refused))).toBe(true)
		expect(withoutProof(refused).cause).toBe(refused)
		expect(withoutProof(limited)).toBe(limited)
		expect(isTransient(withoutProof(limited))).toBe(false)
	})
})
