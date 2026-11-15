import { describe, it, expect } from 'vitest'
import { respondentName } from '@/utils/respondent.js'

describe('respondentName', () => {
	it('names a person, an import and an API key', () => {
		expect(respondentName({ respondent: { type: 'user', display_name: 'Anna' } }, 'Anonymous')).toBe('Anna')
		expect(respondentName({ respondent: { type: 'external', display_name: 'Bob' } }, 'Anonymous')).toBe('Bob')
		expect(respondentName({ respondent: { type: 'api', display_name: 'CRM' } }, 'Anonymous')).toBe('CRM (API)')
	})

	it('shows an API response from before respondents were recorded as API, not as a crash (M30)', () => {
		expect(respondentName({ source: 'api', answers: {} }, 'Anonymous')).toBe('API')
		expect(respondentName({ answers: {} }, 'Anonymous')).toBe('Anonymous')
	})

	it('keeps an anonymous respondent anonymous', () => {
		expect(respondentName({ respondent: { type: 'anonymous', fingerprint: 'x' } }, 'Anonymous')).toBe('Anonymous')
	})
})
