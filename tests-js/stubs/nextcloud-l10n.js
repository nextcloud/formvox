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
export const n = (app, singular, plural, count, vars) => (count === 1 ? t(app, singular, vars) : t(app, plural, vars))
export const translate = t
export const translatePlural = n
export const getLanguage = () => 'en'
export const getLocale = () => 'en'
