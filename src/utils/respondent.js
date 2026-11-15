/**
 * Who answered, for display: a person, an import, an API key, or nobody named.
 * Older API responses carry no respondent at all (M30).
 *
 * @param {object} response a stored response
 * @param {string} anonymousLabel shown when nobody is named
 * @return {string}
 */
export function respondentName(response, anonymousLabel) {
	const respondent = response?.respondent
	if (respondent?.type === 'api' || (!respondent && response?.source === 'api')) {
		return respondent?.display_name ? `${respondent.display_name} (API)` : 'API'
	}
	if ((respondent?.type === 'user' || respondent?.type === 'external') && respondent.display_name) {
		return respondent.display_name
	}
	return anonymousLabel
}
