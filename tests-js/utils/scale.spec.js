import { describe, it, expect } from 'vitest'
import { ratingMaxOf, renameOldScaleFields, scaleOf } from '@/utils/scale'

/**
 * One reading of a scale's and a rating's range, also from the names older forms used (qual#35, PO 4c).
 */
describe('utils/scale', () => {
	it('reads the current names, and a 0 as a start', () => {
		expect(scaleOf({ scaleMin: 0, scaleMax: 10, scaleMinLabel: 'Low', scaleMaxLabel: 'High' })).toEqual({ min: 0, max: 10, minLabel: 'Low', maxLabel: 'High' })
	})

	it('reads the older names, the current ones winning', () => {
		expect(scaleOf({ min: 1, max: 10, minLabel: 'a', maxLabel: 'b' })).toEqual({ min: 1, max: 10, minLabel: 'a', maxLabel: 'b' })
		expect(scaleOf({ scaleMax: 4, max: 9 }).max).toBe(4)
	})

	it('falls back to 1 to 5, and 5 stars', () => {
		expect(scaleOf({})).toEqual({ min: 1, max: 5, minLabel: '', maxLabel: '' })
		expect(ratingMaxOf({})).toBe(5)
		expect(ratingMaxOf({ max: 7 })).toBe(7)
		expect(ratingMaxOf({ ratingMax: '3' })).toBe(3)
	})

	it('moves the older names onto the current ones, as a save does', () => {
		const scale = { type: 'scale', min: 1, max: 10, minLabel: 'a', scaleMaxLabel: 'kept', maxLabel: 'dropped' }
		renameOldScaleFields(scale)
		expect(scale).toEqual({ type: 'scale', scaleMin: 1, scaleMax: 10, scaleMinLabel: 'a', scaleMaxLabel: 'kept' })

		const rating = { type: 'rating', max: 7 }
		renameOldScaleFields(rating)
		expect(rating).toEqual({ type: 'rating', ratingMax: 7 })

		const number = { type: 'number', min: 0, max: 100 }
		renameOldScaleFields(number)
		expect(number).toEqual({ type: 'number', min: 0, max: 100 })
	})
})
