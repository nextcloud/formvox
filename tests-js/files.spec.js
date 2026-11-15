import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@nextcloud/files', () => ({
	registerFileAction: vi.fn(),
	addNewFileMenuEntry: vi.fn(),
	NewMenuEntryCategory: { CreateNew: 0 },
	DefaultType: { DEFAULT: 'default' },
}))

/**
 * The Files script registers New form and the open action as it runs: NC 32's upload picker copies the New menu once,
 * when the first folder shows, so a registration after DOMContentLoaded came too late for that folder (review of the
 * 3a fixes).
 */
describe('files', () => {
	beforeEach(() => {
		vi.resetModules()
	})

	it('puts New form in the menu on Nextcloud 32 as the script runs', async () => {
		const registerEntry = vi.fn()
		window._nc_newfilemenu = { registerEntry, getEntries: () => [] }
		window._nc_fileactions = []
		window.OC = { config: { version: '32.0.3.1' } }

		await import('../src/files.js')

		expect(registerEntry).toHaveBeenCalledWith(expect.objectContaining({ id: 'formvox-new' }))
	})

	it('puts New form in the menu on Nextcloud 33 and later as the script runs', async () => {
		const { addNewFileMenuEntry } = await import('@nextcloud/files')
		window.OC = { config: { version: '33.0.0.16' } }

		await import('../src/files.js')

		expect(addNewFileMenuEntry).toHaveBeenCalledWith(expect.objectContaining({ id: 'formvox-new' }))
	})
})
