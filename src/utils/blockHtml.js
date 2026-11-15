import DOMPurify from 'dompurify'

/**
 * The HTML a branding block may show. Style is kept for how text looks, not for where it sits or what it covers:
 * a block could otherwise lay a fake login over the whole page (L13). A link that opens a new tab gets
 * rel="noopener noreferrer". DOMPurify does the cleaning; the style and links are adjusted on its result, so no
 * hook is added that other sanitizing would share.
 */

const TAGS = ['p', 'br', 'strong', 'em', 'u', 'a', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'code', 'pre', 'span', 'div']

const ATTRIBUTES = ['href', 'target', 'rel', 'style']

const SAFE_CSS = [
	'color', 'background-color', 'text-align', 'font-weight', 'font-style', 'font-size', 'text-decoration', 'line-height',
	'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
	'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
	'border', 'border-radius', 'list-style-type',
]

function safeCss(style) {
	return String(style).split(';')
		.map(declaration => declaration.trim())
		.filter(declaration => {
			const [property, ...rest] = declaration.split(':')
			const value = rest.join(':')
			return SAFE_CSS.includes(property.trim().toLowerCase()) && value.trim() !== '' && !/url\(|expression|javascript:|@import/i.test(value)
		})
		.join('; ')
}

/**
 * @param {string} html
 * @return {string}
 */
export function sanitizeBlockHtml(html) {
	const fragment = DOMPurify.sanitize(html, { ALLOWED_TAGS: TAGS, ALLOWED_ATTR: ATTRIBUTES, RETURN_DOM_FRAGMENT: true })
	// Also here, so nothing else stays even where DOMPurify cannot remove attributes (a test DOM, for one).
	for (const element of fragment.querySelectorAll('*')) {
		for (const name of element.getAttributeNames()) {
			if (!ATTRIBUTES.includes(name)) {
				element.removeAttribute(name)
			}
		}
	}
	for (const element of fragment.querySelectorAll('[style]')) {
		const css = safeCss(element.getAttribute('style'))
		if (css === '') {
			element.removeAttribute('style')
		} else {
			element.setAttribute('style', css)
		}
	}
	for (const link of fragment.querySelectorAll('a[target]')) {
		link.setAttribute('target', '_blank')
		link.setAttribute('rel', 'noopener noreferrer')
	}
	const holder = document.createElement('div')
	holder.appendChild(fragment)
	return holder.innerHTML
}
