/**
 * Pick a readable foreground for a chosen background colour.
 *
 * Branding lets an author set a background colour but no text colour, so the
 * foreground has to be derived. Without this, a light background chosen by the
 * author is painted onto the card while the text keeps the theme's colour —
 * which in dark mode means light grey on cream (#142).
 *
 * Uses WCAG relative luminance rather than a naive average: the eye is far more
 * sensitive to green than to blue, so #0000ff and #00ff00 have very different
 * perceived brightness despite identical naive averages.
 */

/**
 * Parse a CSS hex colour into [r, g, b] (0-255), or null if it is not one.
 *
 * Only hex is handled on purpose: that is what the branding editor stores. A
 * named colour, rgb() or a CSS variable returns null, and the caller then leaves
 * the foreground alone rather than guessing.
 *
 * @param {string} color a colour such as '#fff8f0' or '#fff'
 * @return {?Array<number>} the channels, or null when not a hex colour
 */
export function parseHex(color) {
	if (typeof color !== 'string') {
		return null
	}
	const hex = color.trim().replace(/^#/, '')
	if (!/^[0-9a-f]{3}$|^[0-9a-f]{6}$/i.test(hex)) {
		return null
	}
	const full = hex.length === 3
		? hex.split('').map((c) => c + c).join('')
		: hex
	return [
		parseInt(full.slice(0, 2), 16),
		parseInt(full.slice(2, 4), 16),
		parseInt(full.slice(4, 6), 16),
	]
}

/**
 * WCAG relative luminance of a colour, 0 (black) to 1 (white).
 *
 * @param {string} color a hex colour
 * @return {?number} the luminance, or null when the colour is not hex
 * @see https://www.w3.org/TR/WCAG21/#dfn-relative-luminance
 */
export function relativeLuminance(color) {
	const rgb = parseHex(color)
	if (rgb === null) {
		return null
	}
	const [r, g, b] = rgb.map((channel) => {
		const c = channel / 255
		return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
	})
	return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

const DARK_FOREGROUND = '#1a1a1a'
const LIGHT_FOREGROUND = '#ffffff'

/**
 * WCAG contrast ratio between two luminances, 1:1 to 21:1.
 *
 * @param {number} a one relative luminance
 * @param {number} b the other
 * @return {number} the ratio
 * @see https://www.w3.org/TR/WCAG21/#dfn-contrast-ratio
 */
function contrastRatio(a, b) {
	const lighter = Math.max(a, b)
	const darker = Math.min(a, b)
	return (lighter + 0.05) / (darker + 0.05)
}

/**
 * Readable foreground for a background colour.
 *
 * Compares the actual contrast ratio of dark and light text rather than
 * thresholding the luminance. A midtone shows why that matters: the amber
 * #d8a906 has luminance 0.43, so a "below 0.5 means light text" rule would pick
 * white at 2.19:1 — below the WCAG AA minimum of 4.5:1 — where black scores
 * 9.60:1.
 *
 * @param {string} background a hex colour
 * @return {?string} the foreground to use, or null when background is not hex
 */
export function readableForeground(background) {
	const luminance = relativeLuminance(background)
	if (luminance === null) {
		return null
	}
	const onDark = contrastRatio(luminance, relativeLuminance(DARK_FOREGROUND))
	const onLight = contrastRatio(luminance, relativeLuminance(LIGHT_FOREGROUND))
	return onDark >= onLight ? DARK_FOREGROUND : LIGHT_FOREGROUND
}
