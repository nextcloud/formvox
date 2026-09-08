import { describe, it, expect } from 'vitest'
import { subscriptionNudge } from '@/composables/useSubscriptionNudge.js'

/**
 * Characterization tests for subscriptionNudge — the single-message rule.
 *
 * These pin BRANCH SELECTION (which of the three outcomes is chosen). Two also
 * assert the rendered text now that translate() is called correctly as
 * t(app, text, vars): previously it was called t(text[, vars]) (missing the app
 * name), so the enterprise branch returned undefined and the headcount branch
 * returned the raw vars object. The identity l10n stub renders {count}, so the
 * headcount message must contain the interpolated user count.
 */
describe('subscriptionNudge', () => {
	it('returns null when there are no stats', () => {
		expect(subscriptionNudge(null)).toBeNull()
		expect(subscriptionNudge(undefined)).toBeNull()
	})

	it('returns null when the instance is already licensed', () => {
		expect(subscriptionNudge({ hasLicense: true, totalUsers: 5000, supportNudgeUserThreshold: 100 })).toBeNull()
	})

	it('shows the enterprise message when a valid subscription is detected', () => {
		const msg = subscriptionNudge({ hasLicense: false, hasValidSubscription: true })
		expect(msg).not.toBeNull()
		// Rendered text (proves translate(app, text) is called correctly).
		expect(msg).toContain('Nextcloud Enterprise subscription detected')
	})

	it('shows the enterprise message when extended support is detected', () => {
		const msg = subscriptionNudge({ hasLicense: false, hasExtendedSupport: true })
		expect(msg).not.toBeNull()
	})

	it('prefers the enterprise message over the headcount message (enterprise wins)', () => {
		// Both conditions match: subscription AND far above the threshold.
		const both = subscriptionNudge({
			hasLicense: false,
			hasValidSubscription: true,
			supportNudgeUserThreshold: 100,
			totalUsers: 400,
		})
		const enterpriseOnly = subscriptionNudge({ hasLicense: false, hasValidSubscription: true })
		// Same branch taken → same result shape as the pure enterprise case.
		expect(both).toEqual(enterpriseOnly)
	})

	it('returns null when the threshold is missing (older backend)', () => {
		expect(subscriptionNudge({ hasLicense: false, totalUsers: 400 })).toBeNull()
	})

	it('returns null when the user count is missing', () => {
		expect(subscriptionNudge({ hasLicense: false, supportNudgeUserThreshold: 100 })).toBeNull()
	})

	it('returns null when users are at or below the threshold', () => {
		expect(subscriptionNudge({ hasLicense: false, supportNudgeUserThreshold: 100, totalUsers: 100 })).toBeNull()
		expect(subscriptionNudge({ hasLicense: false, supportNudgeUserThreshold: 100, totalUsers: 50 })).toBeNull()
	})

	it('shows the headcount message when users exceed the threshold', () => {
		const msg = subscriptionNudge({ hasLicense: false, supportNudgeUserThreshold: 100, totalUsers: 101 })
		expect(msg).not.toBeNull()
		// {count} is interpolated (proves translate(app, text, vars) is used).
		expect(msg).toContain('101 users')
	})

	it('treats a non-numeric threshold as missing', () => {
		expect(subscriptionNudge({ hasLicense: false, supportNudgeUserThreshold: '100', totalUsers: 400 })).toBeNull()
	})
})
