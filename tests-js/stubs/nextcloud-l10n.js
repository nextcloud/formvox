/**
 * Stub for @nextcloud/l10n: identity translation so component output is
 * deterministic and readable in assertions.
 */
export const t = (app, text, vars) => {
	if (!vars) {
		return text
	}
	return text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m))
}
// Like the real translatePlural: pick the form by count and put the count in %n.
export const n = (app, singular, plural, count, vars) => t(app, count === 1 ? singular : plural, vars).replace(/%n/g, String(count))
export const translate = t
export const translatePlural = n
export const getLanguage = () => 'en'
export const getLocale = () => 'en'
export const getCanonicalLocale = () => 'en-US'
