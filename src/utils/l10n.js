/**
 * Translation helpers for FormVox.
 *
 * These are @nextcloud/l10n's own translate/translatePlural under the names
 * t and n, so every call site passes the app id first:
 * t(appId, text, vars) and n(appId, singular, plural, count, vars), with
 * appId the literal 'formvox' and text a string literal.
 *
 * Keep both the names and the app-id-first call form: the Nextcloud Transifex
 * bot extracts strings with xgettext --keyword=t:2 --keyword=n:2,3 (and an own
 * regex for <template> that needs the literal 'formvox' first). A one-argument
 * wrapper called with only the text is invisible to it, so its strings never reach
 * translators. tests-js/l10n-bot-visibility.spec.js guards this.
 */
export { translate as t, translatePlural as n } from '@nextcloud/l10n';
