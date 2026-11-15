import { describe, it, expect, vi, afterEach } from 'vitest'
import { browserTimeZone } from '../../src/utils/timeZone.js'

describe('utils/timeZone', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it("names the browser's zone", () => {
		vi.spyOn(Intl, 'DateTimeFormat').mockReturnValue({ resolvedOptions: () => ({ timeZone: 'Europe/Berlin' }) })
		expect(browserTimeZone()).toBe('Europe/Berlin')
	})

	it('names none where the browser cannot say', () => {
		vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(() => { throw new Error('no Intl') })
		expect(browserTimeZone()).toBeUndefined()
	})
})
