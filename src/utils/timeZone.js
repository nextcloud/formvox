/**
 * The respondent's time zone as their browser knows it, sent with a submission so the times in its PDF are at their
 * clock; undefined where the browser does not say.
 *
 * @return {string|undefined}
 */
export function browserTimeZone() {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || undefined
	} catch (e) {
		return undefined
	}
}
