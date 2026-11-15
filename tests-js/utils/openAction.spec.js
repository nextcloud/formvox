import { describe, expect, it, vi } from 'vitest'
import { isForm, readsV3Actions, registerNewFormEntry, registerOpenAction } from '../../src/utils/openAction.js'

const form = { type: 'file', fileid: 7, basename: 'Survey.fvform', mime: 'application/x-fvform' }
const folderNamedLikeAForm = { type: 'folder', fileid: 8, basename: 'Old.fvform', mime: 'httpd/unix-directory' }
// Nextcloud versions as the server reports them.
const versions = { nc32: '32.0.4.1', nc33: '33.0.0.16', nc35: '35.0.0.1' }

/**
 * "Edit with FormVox" is in the Files app on every supported Nextcloud, as the click on a form (7a, 3a-6): it was in
 * the version 4 registry with version 3 arguments, so no version showed it and Nextcloud 32 never read it.
 */
describe('open action', () => {
	const register = (version) => {
		const registerFileAction = vi.fn()
		const open = vi.fn(() => true)
		const scope = {}
		registerOpenAction({ registerFileAction, version, open, icon: '<svg/>', defaultType: 'default', scope })
		return { registerFileAction, open, scope }
	}

	it('knows a form, and no folder named like one', () => {
		expect(isForm(form)).toBe(true)
		expect(isForm({ ...form, mime: 'text/plain' })).toBe(true)
		expect(isForm(folderNamedLikeAForm)).toBe(false)
		expect(isForm({ type: 'file', basename: 'notes.txt', mime: 'text/plain' })).toBe(false)
	})

	it('reads the version from Nextcloud', () => {
		expect(readsV3Actions(versions.nc32)).toBe(true)
		expect(readsV3Actions(versions.nc33)).toBe(false)
		expect(readsV3Actions('35.0.0')).toBe(false)
		expect(readsV3Actions(undefined)).toBe(false)
	})

	it('on Nextcloud 33 and later: the version 4 registry, called with a context', async () => {
		const { registerFileAction, open, scope } = register(versions.nc35)

		expect(scope._nc_fileactions).toBeUndefined()
		const action = registerFileAction.mock.calls[0][0]
		expect([action.id, action.default, action.order]).toEqual(['formvox-open', 'default', -1100])
		expect(action.enabled({ nodes: [form] })).toBe(true)
		expect(action.enabled({ nodes: [form, form] })).toBe(false)
		expect(action.enabled({ nodes: [folderNamedLikeAForm] })).toBe(false)
		await action.exec({ nodes: [form] })
		expect(open).toHaveBeenCalledWith(form)
	})

	it('on Nextcloud 32: window._nc_fileactions, called with nodes and a node, once', async () => {
		const { registerFileAction, open, scope } = register(versions.nc32)
		registerOpenAction({ registerFileAction, version: versions.nc32, open, icon: '<svg/>', defaultType: 'default', scope })

		expect(registerFileAction).not.toHaveBeenCalled()
		expect(scope._nc_fileactions).toHaveLength(1)
		const [action] = scope._nc_fileactions
		expect(action.enabled([form])).toBe(true)
		expect(action.enabled([folderNamedLikeAForm])).toBe(false)
		await action.exec(form, {}, '/')
		expect(open).toHaveBeenCalledWith(form)
	})

	it('is not the action for a form in the deleted files', () => {
		const v4 = register(versions.nc35).registerFileAction.mock.calls[0][0]
		expect(v4.enabled({ nodes: [form], view: { id: 'trashbin' } })).toBe(false)
		expect(v4.enabled({ nodes: [form], view: { id: 'files' } })).toBe(true)

		const v3 = register(versions.nc32).scope._nc_fileactions[0]
		expect(v3.enabled([form], { id: 'trashbin' })).toBe(false)
	})
})

/** "New form" in the + New menu on every supported Nextcloud (review 3a-6: missing on 32). */
describe('new form entry', () => {
	const entry = { id: 'formvox-new', displayName: 'New form', iconSvgInline: '<svg/>', handler: () => {} }

	it('on Nextcloud 33 and later: the version 4 registry', async () => {
		const addNewFileMenuEntry = vi.fn()
		await expect(registerNewFormEntry({ addNewFileMenuEntry, version: versions.nc33, entry, scope: {} })).resolves.toBe(true)
		expect(addNewFileMenuEntry).toHaveBeenCalledWith(entry)
	})

	it('on Nextcloud 32: the menu its Files app makes, once it is there, and only once', async () => {
		const registered = []
		const scope = {}
		const menu = { registerEntry: (e) => registered.push(e), getEntries: () => registered }
		let waits = 0
		const later = (fn) => { if (++waits === 2) { scope._nc_newfilemenu = menu } fn() }

		await expect(registerNewFormEntry({ addNewFileMenuEntry: vi.fn(), version: versions.nc32, entry, scope, later })).resolves.toBe(true)
		await registerNewFormEntry({ addNewFileMenuEntry: vi.fn(), version: versions.nc32, entry, scope, later })
		expect(registered).toEqual([entry])
	})

	it('gives up when the Files app never makes its menu', async () => {
		await expect(registerNewFormEntry({ addNewFileMenuEntry: vi.fn(), version: versions.nc32, entry, scope: {}, later: (fn) => fn() })).resolves.toBe(false)
	})
})

