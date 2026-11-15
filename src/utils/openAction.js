/**
 * "Edit with FormVox" in the Files app, also as the click on a form (7a, 3a-6). Nextcloud 32's Files app still reads
 * @nextcloud/files 3 actions from window._nc_fileactions, called with (nodes, view) and (node, view, dir); 33 and
 * later read the version 4 registry, called with a context ({ nodes, view, folder, contents }).
 */

import { t } from './l10n.js'

export const OPEN_ACTION_ID = 'formvox-open'

/**
 * @param {object} node a Files node
 * @return {boolean}
 */
export function isForm(node) {
	return node?.type === 'file'
		&& (node.mime === 'application/x-fvform' || Boolean(node.basename?.toLowerCase().endsWith('.fvform')))
}

/**
 * @param {object|undefined} view a Files view
 * @return {boolean} the deleted files: a form there is restored, not opened
 */
function inTrash(view) {
	return view?.id === 'trashbin'
}

/**
 * @param {string|undefined} version OC.config.version, such as "32.0.4.1"
 * @return {boolean} whether this Files app reads @nextcloud/files 3 actions
 */
export function readsV3Actions(version) {
	const major = parseInt(String(version ?? ''), 10)
	return Number.isFinite(major) && major < 33
}

/**
 * Registers the action the way this Nextcloud's Files app reads it.
 *
 * @param {object} options what it needs
 * @param {function(object): void} options.registerFileAction @nextcloud/files 4's
 * @param {string|undefined} options.version OC.config.version
 * @param {function(object): boolean} options.open opens a node in FormVox
 * @param {string} options.icon the icon as inline SVG
 * @param {string} options.defaultType DefaultType.DEFAULT
 * @param {object} options.scope where version 3 actions live (window)
 */
export function registerOpenAction({ registerFileAction, version, open, icon, defaultType, scope }) {
	const shared = {
		id: OPEN_ACTION_ID,
		displayName: () => t('formvox', 'Edit with FormVox'),
		iconSvgInline: () => icon,
		default: defaultType,
		// Before the viewer's and the text editor's own default, so a click opens the form.
		order: -1100,
	}
	if (readsV3Actions(version)) {
		scope._nc_fileactions ??= []
		if (!scope._nc_fileactions.some(action => action.id === OPEN_ACTION_ID)) {
			scope._nc_fileactions.push({
				...shared,
				enabled: (nodes, view) => nodes.length === 1 && isForm(nodes[0]) && !inTrash(view),
				exec: async (node) => open(node),
			})
		}
		return
	}
	registerFileAction({
		...shared,
		enabled: ({ nodes, view }) => nodes.length === 1 && isForm(nodes[0]) && !inTrash(view),
		exec: async ({ nodes }) => open(nodes[0]),
	})
}

/**
 * Registers "New form" in the Files app's + New menu where this Nextcloud's Files app reads it: Nextcloud 32 from
 * window._nc_newfilemenu, which its Files app creates as it starts; 33 and later from the version 4 registry.
 *
 * @param {object} options what it needs
 * @param {function(object): void} options.addNewFileMenuEntry @nextcloud/files 4's
 * @param {string|undefined} options.version OC.config.version
 * @param {object} options.entry the menu entry
 * @param {object} options.scope where the Files app keeps its menu (window)
 * @param {function(function(): void, number): void} options.later waits; replaced in tests
 * @return {Promise<boolean>} whether it was registered
 */
export async function registerNewFormEntry({ addNewFileMenuEntry, version, entry, scope, later = (fn, ms) => setTimeout(fn, ms) }) {
	if (!readsV3Actions(version)) {
		addNewFileMenuEntry(entry)
		return true
	}
	for (let attempt = 0; attempt < 50; attempt++) {
		const menu = scope._nc_newfilemenu
		if (typeof menu?.registerEntry === 'function') {
			if (!menu.getEntries?.().some(existing => existing.id === entry.id)) {
				menu.registerEntry(entry)
			}
			return true
		}
		await new Promise(resolve => later(resolve, 100))
	}
	return false
}
