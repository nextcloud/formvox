import { translate, translatePlural } from '@nextcloud/l10n';

const APP_NAME = 'formvox';

/**
 * Translate a string
 * @param {string} text - The text to translate
 * @param {object} vars - Variables to replace in the text
 * @returns {string} - The translated text
 */
export function t(text, vars = {}) {
  return translate(APP_NAME, text, vars);
}

/**
 * Translate a plural string
 * @param {string} singular - The singular form
 * @param {string} plural - The plural form
 * @param {number} count - The count
 * @param {object} vars - Variables to replace in the text
 * @returns {string} - The translated text
 */
export function n(singular, plural, count, vars = {}) {
  return translatePlural(APP_NAME, singular, plural, count, vars);
}
