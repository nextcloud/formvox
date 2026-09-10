import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'

// QRCode.toCanvas would touch a real 2d context happy-dom doesn't implement;
// stub it so mounting a dialog with a link doesn't explode.
vi.mock('qrcode', () => ({ default: { toCanvas: vi.fn().mockResolvedValue(undefined) } }))
// Deterministic token for replaceShareLink-style flows if ever needed.
vi.mock('uuid', () => ({ v4: () => 'aaaa-bbbb-cccc-dddd' }))

import ShareDialog from '@/components/ShareDialog.vue'

/**
 * Characterization tests for ShareDialog.
 *
 * Central to #135: creating a link sends the sentinel public_token 'new' and
 * trusts the server to mint the real token; once a link exists an ordinary save
 * never changes it — only the dedicated share-token endpoint (replaceShareLink)
 * mints a fresh one.
 */
describe('ShareDialog', () => {
	const makeForm = (settings = {}) => ({
		title: 'My form',
		questions: [],
		settings,
		_index: { response_count: 0 },
	})

	const mountDialog = (form = makeForm(), extra = {}) =>
		mount(ShareDialog, {
			props: { fileId: 7, form, canShare: true, ...extra },
		})

	beforeEach(() => {
		vi.clearAllMocks()
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		window.OC = { currentUser: 'alice', getCurrentUser: () => ({ displayName: 'Alice' }) }
	})

	it('with no existing token shows the "create link" prompt, not the link display', async () => {
		const wrapper = mountDialog(makeForm())
		await flushPromises()
		expect(wrapper.text()).toContain('No link yet')
		expect(wrapper.find('.share-link-display').exists()).toBe(false)
	})

	it('disables the create button and shows a permission note when canShare is false', async () => {
		const wrapper = mountDialog(makeForm(), { canShare: false })
		await flushPromises()
		expect(wrapper.text()).toContain('do not have permission')
	})

	it('renders the existing share link from a stored public_token', async () => {
		const wrapper = mountDialog(makeForm({ public_token: 'tok123' }))
		await flushPromises()
		expect(wrapper.find('.share-link-display').exists()).toBe(true)
		const linkInput = wrapper.get('.link-input')
		expect(linkInput.attributes('value')).toContain('/apps/formvox/public/7/tok123')
	})

	it('createShareLink sends the "new" sentinel token and adopts the server token (#135)', async () => {
		axios.put.mockResolvedValue({ data: { form: { settings: { public_token: 'server-minted' } } } })
		const form = makeForm()
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.createShareLink()
		await flushPromises()

		// The PUT payload carries public_token: 'new' — the server swaps it.
		expect(axios.put).toHaveBeenCalledWith(
			'/apps/formvox/api/form/7',
			expect.objectContaining({ settings: expect.objectContaining({ public_token: 'new' }) }),
		)
		// After the response the real token is stored locally and the link is built.
		expect(wrapper.vm.shareLink).toContain('/apps/formvox/public/7/server-minted')
		expect(form.settings.public_token).toBe('server-minted')
	})

	it('createShareLink throws (and does not set a link) when the server returns no token', async () => {
		axios.put.mockResolvedValue({ data: { form: { settings: {} } } })
		const wrapper = mountDialog(makeForm())
		await flushPromises()

		await wrapper.vm.createShareLink()
		await flushPromises()

		expect(wrapper.vm.shareLink).toBeFalsy()
	})

	it('replaceShareLink hits the dedicated share-token endpoint (never a plain save) (#135)', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true)
		axios.post.mockResolvedValue({ data: { form: { settings: { public_token: 'fresh-tok' } } } })
		const form = makeForm({ public_token: 'old-tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.replaceShareLink()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/formvox/api/form/7/share-token')
		expect(wrapper.vm.shareLink).toContain('/apps/formvox/public/7/fresh-tok')
		expect(form.settings.public_token).toBe('fresh-tok')
	})

	it('replaceShareLink does nothing when the confirm is cancelled', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(false)
		const wrapper = mountDialog(makeForm({ public_token: 'old-tok' }))
		await flushPromises()

		await wrapper.vm.replaceShareLink()
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.shareLink).toContain('old-tok')
	})

	it('deleteShareLink clears the link and nulls the token after confirmation', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true)
		const form = makeForm({ public_token: 'tok123' })
		const wrapper = mountDialog(form)
		await flushPromises()
		expect(wrapper.vm.shareLink).toBeTruthy()

		await wrapper.vm.deleteShareLink()
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(
			'/apps/formvox/api/form/7',
			expect.objectContaining({ settings: expect.objectContaining({ public_token: null }) }),
		)
		expect(wrapper.vm.shareLink).toBeNull()
		expect(form.settings.public_token).toBeNull()
	})

	it('deleteShareLink aborts when the confirm is cancelled', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(false)
		const wrapper = mountDialog(makeForm({ public_token: 'tok123' }))
		await flushPromises()

		await wrapper.vm.deleteShareLink()
		await flushPromises()

		expect(axios.put).not.toHaveBeenCalled()
		expect(wrapper.vm.shareLink).toBeTruthy()
	})

	it('initialises response settings from form.settings', async () => {
		const wrapper = mountDialog(makeForm({
			public_token: 'tok',
			anonymous: false,
			allow_multiple: true,
			require_login: true,
			max_responses: 50,
			limit_message: 'Full!',
		}))
		await flushPromises()
		expect(wrapper.vm.responseSettings.allowAnonymous).toBe(false)
		expect(wrapper.vm.responseSettings.allowMultiple).toBe(true)
		expect(wrapper.vm.responseSettings.requireLogin).toBe(true)
		expect(wrapper.vm.responseSettings.limitResponses).toBe(true)
		expect(wrapper.vm.responseSettings.maxResponses).toBe(50)
		expect(wrapper.vm.responseSettings.limitMessage).toBe('Full!')
	})

	it('updateResponseSetting maps the API key to local state and persists it', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.updateResponseSetting('anonymous', false)
		await flushPromises()

		expect(wrapper.vm.responseSettings.allowAnonymous).toBe(false)
		expect(axios.put).toHaveBeenCalledWith(
			'/apps/formvox/api/form/7',
			expect.objectContaining({ settings: expect.objectContaining({ anonymous: false }) }),
		)
		expect(form.settings.anonymous).toBe(false)
	})

	it('embedCode returns an iframe once a token exists and empty before', async () => {
		const wrapper = mountDialog(makeForm({ public_token: 'tok123' }))
		await flushPromises()
		const code = wrapper.vm.embedCode()
		expect(code).toContain('<iframe')
		expect(code).toContain('/apps/formvox/embed/7/tok123')
		expect(code).toContain('width="100%"') // responsive default
	})

	it('embedCode uses a fixed pixel width when responsive is off', async () => {
		const wrapper = mountDialog(makeForm({ public_token: 'tok123' }))
		await flushPromises()
		wrapper.vm.embedOptions.responsive = false
		wrapper.vm.embedOptions.width = 500
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.embedCode()).toContain('width="500px"')
	})

	it('loads access restrictions from allowed_users/groups and enables the toggle', async () => {
		const wrapper = mountDialog(makeForm({
			public_token: 'tok',
			allowed_users: ['bob'],
			allowed_groups: ['team'],
		}))
		await flushPromises()
		expect(wrapper.vm.accessRestrictions.enabled).toBe(true)
		expect(wrapper.vm.accessRestrictions.users).toEqual([{ id: 'bob', displayName: 'bob' }])
		expect(wrapper.vm.accessRestrictions.groups).toEqual([{ id: 'team', displayName: 'team' }])
	})

	it('addUser stores the user and saves; removeUser drops it', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		wrapper.vm.addUser({ id: 'bob', displayName: 'Bob' })
		await flushPromises()
		expect(wrapper.vm.accessRestrictions.users).toContainEqual({ id: 'bob', displayName: 'Bob' })
		expect(axios.put).toHaveBeenCalledWith(
			'/apps/formvox/api/form/7',
			expect.objectContaining({ settings: expect.objectContaining({ allowed_users: ['bob'] }) }),
		)

		wrapper.vm.removeUser('bob')
		await flushPromises()
		expect(wrapper.vm.accessRestrictions.users).toEqual([])
	})

	it('confirmDeleteResponses deletes and emits responsesDeleted, resetting the count', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true)
		const form = makeForm({ public_token: 'tok' })
		form._index.response_count = 5
		const wrapper = mountDialog(form)
		await flushPromises()
		expect(wrapper.vm.responseCount).toBe(5)

		await wrapper.vm.confirmDeleteResponses()
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith('/apps/formvox/api/form/7/responses')
		expect(wrapper.vm.responseCount).toBe(0)
		expect(wrapper.emitted('responsesDeleted')).toBeTruthy()
	})

	it('emits close when the Done button (NcModal close) fires', async () => {
		const wrapper = mountDialog(makeForm())
		await flushPromises()
		wrapper.getComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	// ---- createShareLink: creatingLink guard + error path ----

	it('createShareLink toggles creatingLink true during the request and false at the end (success)', async () => {
		let seenDuring = null
		axios.put.mockImplementation(async () => {
			seenDuring = wrapper.vm.creatingLink
			return { data: { form: { settings: { public_token: 'srv' } } } }
		})
		const wrapper = mountDialog(makeForm())
		await flushPromises()

		await wrapper.vm.createShareLink()
		await flushPromises()

		expect(seenDuring).toBe(true)
		expect(wrapper.vm.creatingLink).toBe(false)
	})

	it('createShareLink resets creatingLink to false and leaves no link when the request rejects', async () => {
		axios.put.mockRejectedValue(new Error('boom'))
		const form = makeForm()
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.createShareLink()
		await flushPromises()

		expect(wrapper.vm.creatingLink).toBe(false)
		expect(wrapper.vm.shareLink).toBeFalsy()
		expect(form.settings.public_token).toBeUndefined()
	})

	// ---- embed code: empty before token, responsive vs fixed, height ----

	it('embedCode returns an empty string when there is no token yet', async () => {
		const wrapper = mountDialog(makeForm())
		await flushPromises()
		expect(wrapper.vm.embedCode()).toBe('')
	})

	it('embedCode embeds the configured pixel height', async () => {
		const wrapper = mountDialog(makeForm({ public_token: 'tok123' }))
		await flushPromises()
		wrapper.vm.embedOptions.height = 950
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.embedCode()).toContain('height="950px"')
	})

	// ---- replaceShareLink error path ----

	it('replaceShareLink keeps the old link when the server returns no token', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true)
		axios.post.mockResolvedValue({ data: { form: { settings: {} } } })
		const form = makeForm({ public_token: 'old-tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.replaceShareLink()
		await flushPromises()

		expect(wrapper.vm.shareLink).toContain('old-tok')
		expect(form.settings.public_token).toBe('old-tok')
	})

	// ---- deleteShareLink resets link settings ----

	it('deleteShareLink resets all link settings flags to their defaults', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true)
		const form = makeForm({
			public_token: 'tok',
			share_password_hash: 'h',
			share_expires_at: '2030-01-01T00:00:00.000Z',
			share_starts_at: '2029-01-01T00:00:00.000Z',
		})
		const wrapper = mountDialog(form)
		await flushPromises()
		expect(wrapper.vm.linkSettings.passwordProtected).toBe(true)
		expect(wrapper.vm.linkSettings.expires).toBe(true)
		expect(wrapper.vm.linkSettings.hasStart).toBe(true)

		await wrapper.vm.deleteShareLink()
		await flushPromises()

		expect(wrapper.vm.linkSettings.passwordProtected).toBe(false)
		expect(wrapper.vm.linkSettings.password).toBe('')
		expect(wrapper.vm.linkSettings.expires).toBe(false)
		expect(wrapper.vm.linkSettings.expiresAt).toBeNull()
		expect(wrapper.vm.linkSettings.hasStart).toBe(false)
		expect(wrapper.vm.linkSettings.startsAt).toBeNull()
		expect(form.settings.share_expires_at).toBeNull()
		expect(form.settings.share_starts_at).toBeNull()
		expect('share_password_hash' in form.settings).toBe(false)
	})

	// ---- confirmDeleteResponses cancel + error ----

	it('confirmDeleteResponses does nothing and does not emit when cancelled', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(false)
		const form = makeForm({ public_token: 'tok' })
		form._index.response_count = 5
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.confirmDeleteResponses()
		await flushPromises()

		expect(axios.delete).not.toHaveBeenCalled()
		expect(wrapper.vm.responseCount).toBe(5)
		expect(wrapper.emitted('responsesDeleted')).toBeFalsy()
	})

	it('confirmDeleteResponses does not emit or reset the count when the request rejects', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true)
		axios.delete.mockRejectedValue(new Error('boom'))
		const form = makeForm({ public_token: 'tok' })
		form._index.response_count = 5
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.confirmDeleteResponses()
		await flushPromises()

		expect(wrapper.vm.responseCount).toBe(5)
		expect(wrapper.emitted('responsesDeleted')).toBeFalsy()
	})

	// ---- updateResponseSetting key mapping ----

	it('updateResponseSetting maps every known API key to the right local field', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.updateResponseSetting('allow_multiple', true)
		expect(wrapper.vm.responseSettings.allowMultiple).toBe(true)

		await wrapper.vm.updateResponseSetting('require_login', true)
		expect(wrapper.vm.responseSettings.requireLogin).toBe(true)

		await wrapper.vm.updateResponseSetting('confirmationEmailSubject', 'Hi')
		expect(wrapper.vm.responseSettings.confirmationEmailSubject).toBe('Hi')

		await wrapper.vm.updateResponseSetting('confirmationEmailBody', 'Thanks')
		expect(wrapper.vm.responseSettings.confirmationEmailBody).toBe('Thanks')
	})

	it('updateResponseSetting persists an unmapped key without touching mapped local fields', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()
		const before = { ...wrapper.vm.responseSettings }

		await wrapper.vm.updateResponseSetting('some_other_key', 'x')
		await flushPromises()

		// Unknown key still persisted to the server / form...
		expect(axios.put).toHaveBeenCalledWith(
			'/apps/formvox/api/form/7',
			expect.objectContaining({ settings: expect.objectContaining({ some_other_key: 'x' }) }),
		)
		expect(form.settings.some_other_key).toBe('x')
		// ...but no mapped local field was overwritten.
		expect(wrapper.vm.responseSettings.allowAnonymous).toBe(before.allowAnonymous)
		expect(wrapper.vm.responseSettings.allowMultiple).toBe(before.allowMultiple)
	})

	// ---- response limit coercion ----

	it('saveResponseLimit sends maxResponses when the limit is enabled and 0 when disabled', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		// Enabled → sends the configured maximum.
		await wrapper.vm.toggleResponseLimit(true)
		wrapper.vm.responseSettings.maxResponses = 42
		await wrapper.vm.saveResponseLimit()
		await flushPromises()
		expect(axios.put).toHaveBeenLastCalledWith(
			'/apps/formvox/api/form/7',
			expect.objectContaining({ settings: expect.objectContaining({ max_responses: 42 }) }),
		)

		// Disabled → sends 0 regardless of maxResponses.
		await wrapper.vm.toggleResponseLimit(false)
		await flushPromises()
		expect(axios.put).toHaveBeenLastCalledWith(
			'/apps/formvox/api/form/7',
			expect.objectContaining({ settings: expect.objectContaining({ max_responses: 0 }) }),
		)
		expect(form.settings.max_responses).toBe(0)
	})

	// ---- password toggle / save ----

	it('togglePassword off clears the password and persists; on does not persist', async () => {
		const form = makeForm({ public_token: 'tok', share_password_hash: 'h' })
		const wrapper = mountDialog(form)
		await flushPromises()
		axios.put.mockClear()

		// Turning it on must NOT save (no password entered yet).
		wrapper.vm.togglePassword(true)
		await flushPromises()
		expect(axios.put).not.toHaveBeenCalled()
		expect(wrapper.vm.linkSettings.passwordProtected).toBe(true)

		// Turning it off clears the field and saves.
		wrapper.vm.togglePassword(false)
		await flushPromises()
		expect(wrapper.vm.linkSettings.password).toBe('')
		expect(axios.put).toHaveBeenCalled()
	})

	it('savePassword persists only when a password is present', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()
		axios.put.mockClear()
		wrapper.vm.linkSettings.passwordProtected = true

		wrapper.vm.linkSettings.password = ''
		wrapper.vm.savePassword()
		await flushPromises()
		expect(axios.put).not.toHaveBeenCalled()

		wrapper.vm.linkSettings.password = 's3cret'
		wrapper.vm.savePassword()
		await flushPromises()
		expect(axios.put).toHaveBeenCalledWith(
			'/apps/formvox/api/form/7',
			expect.objectContaining({ settings: expect.objectContaining({ share_password: 's3cret' }) }),
		)
	})

	// ---- expiration / start toggles set or clear the date ----

	it('toggleLinkExpiration sets a future expiry when enabled and clears it when disabled', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		wrapper.vm.toggleLinkExpiration(true)
		await flushPromises()
		expect(wrapper.vm.linkSettings.expires).toBe(true)
		expect(wrapper.vm.linkSettings.expiresAt).toBeInstanceOf(Date)
		expect(wrapper.vm.linkSettings.expiresAt.getTime()).toBeGreaterThan(Date.now())

		wrapper.vm.toggleLinkExpiration(false)
		await flushPromises()
		expect(wrapper.vm.linkSettings.expires).toBe(false)
		expect(wrapper.vm.linkSettings.expiresAt).toBeNull()
	})

	it('toggleLinkStart sets a start date at 09:00 when enabled and clears it when disabled', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		wrapper.vm.toggleLinkStart(true)
		await flushPromises()
		expect(wrapper.vm.linkSettings.hasStart).toBe(true)
		expect(wrapper.vm.linkSettings.startsAt).toBeInstanceOf(Date)
		expect(wrapper.vm.linkSettings.startsAt.getHours()).toBe(9)
		expect(wrapper.vm.linkSettings.startsAt.getMinutes()).toBe(0)

		wrapper.vm.toggleLinkStart(false)
		await flushPromises()
		expect(wrapper.vm.linkSettings.hasStart).toBe(false)
		expect(wrapper.vm.linkSettings.startsAt).toBeNull()
	})

	// ---- saveLinkSettings serialization branches ----

	it('saveLinkSettings serializes password/expiry/start when set and nulls them otherwise', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		// All on: values must be present and ISO-serialized.
		wrapper.vm.linkSettings.passwordProtected = true
		wrapper.vm.linkSettings.password = 'pw'
		wrapper.vm.linkSettings.expires = true
		wrapper.vm.linkSettings.expiresAt = new Date('2030-06-01T10:30:00.000Z')
		wrapper.vm.linkSettings.hasStart = true
		wrapper.vm.linkSettings.startsAt = new Date('2029-06-01T08:00:00.000Z')
		await wrapper.vm.$nextTick()

		axios.put.mockClear()
		wrapper.vm.savePassword() // triggers saveLinkSettings (password present)
		await flushPromises()
		expect(axios.put).toHaveBeenLastCalledWith(
			'/apps/formvox/api/form/7',
			expect.objectContaining({
				settings: expect.objectContaining({
					share_password: 'pw',
					share_expires_at: '2030-06-01T10:30:00.000Z',
					share_starts_at: '2029-06-01T08:00:00.000Z',
				}),
			}),
		)

		// Flags off → server receives explicit nulls.
		wrapper.vm.linkSettings.passwordProtected = false
		wrapper.vm.linkSettings.expires = false
		wrapper.vm.linkSettings.hasStart = false
		await wrapper.vm.$nextTick()
		axios.put.mockClear()
		wrapper.vm.toggleLinkExpiration(false)
		await flushPromises()
		expect(axios.put).toHaveBeenLastCalledWith(
			'/apps/formvox/api/form/7',
			expect.objectContaining({
				settings: expect.objectContaining({
					share_password: null,
					share_expires_at: null,
					share_starts_at: null,
				}),
			}),
		)
	})

	// ---- access restrictions: dedup, group add/remove, toggle ----

	it('addUser is idempotent — adding the same id twice keeps a single entry', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		wrapper.vm.addUser({ id: 'bob', displayName: 'Bob' })
		wrapper.vm.addUser({ id: 'bob', displayName: 'Bob again' })
		await flushPromises()
		expect(wrapper.vm.accessRestrictions.users).toHaveLength(1)
	})

	it('addUser clears the search term and results after adding', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()
		wrapper.vm.searchTerm = 'bo'
		wrapper.vm.searchResults.users = [{ id: 'bob', displayName: 'Bob' }]
		await wrapper.vm.$nextTick()

		wrapper.vm.addUser({ id: 'bob', displayName: 'Bob' })
		await flushPromises()
		expect(wrapper.vm.searchTerm).toBe('')
		expect(wrapper.vm.searchResults.users).toEqual([])
	})

	it('addGroup stores the group (deduped) and removeGroup drops it', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		wrapper.vm.addGroup({ id: 'team', displayName: 'Team' })
		wrapper.vm.addGroup({ id: 'team', displayName: 'Team' })
		await flushPromises()
		expect(wrapper.vm.accessRestrictions.groups).toHaveLength(1)
		expect(axios.put).toHaveBeenCalledWith(
			'/apps/formvox/api/form/7',
			expect.objectContaining({ settings: expect.objectContaining({ allowed_groups: ['team'] }) }),
		)

		wrapper.vm.removeGroup('team')
		await flushPromises()
		expect(wrapper.vm.accessRestrictions.groups).toEqual([])
	})

	it('loadAccessRestrictions leaves the toggle off when there are no allowed users or groups', async () => {
		const wrapper = mountDialog(makeForm({ public_token: 'tok' }))
		await flushPromises()
		expect(wrapper.vm.accessRestrictions.enabled).toBe(false)
	})

	it('toggleAccessRestrictions off clears the lists and saves; on does not save', async () => {
		const form = makeForm({ public_token: 'tok', allowed_users: ['bob'] })
		const wrapper = mountDialog(form)
		await flushPromises()
		expect(wrapper.vm.accessRestrictions.enabled).toBe(true)
		axios.put.mockClear()

		wrapper.vm.toggleAccessRestrictions(false)
		await flushPromises()
		expect(wrapper.vm.accessRestrictions.users).toEqual([])
		expect(wrapper.vm.accessRestrictions.groups).toEqual([])
		expect(axios.put).toHaveBeenCalled()

		axios.put.mockClear()
		wrapper.vm.toggleAccessRestrictions(true)
		await flushPromises()
		expect(axios.put).not.toHaveBeenCalled()
	})

	// ---- notify recipients ----

	it('injects the current user as an owner recipient by default', async () => {
		const wrapper = mountDialog(makeForm({ public_token: 'tok' }))
		await flushPromises()
		const owner = wrapper.vm.notifyRecipients.find(r => r.type === 'user' && r.id === 'alice')
		expect(owner).toBeTruthy()
		expect(owner.displayName).toContain('Alice')
	})

	it('does not inject the owner when notify_owner is explicitly false', async () => {
		const wrapper = mountDialog(makeForm({ public_token: 'tok', notify_owner: false }))
		await flushPromises()
		expect(wrapper.vm.notifyRecipients.find(r => r.id === 'alice')).toBeFalsy()
	})

	it('addNotifyRecipient dedupes on type+id and removeNotifyRecipient drops the entry', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()
		const before = wrapper.vm.notifyRecipients.length

		wrapper.vm.addNotifyRecipient({ type: 'group', id: 'devs', displayName: 'Devs' })
		wrapper.vm.addNotifyRecipient({ type: 'group', id: 'devs', displayName: 'Devs' })
		await flushPromises()
		expect(wrapper.vm.notifyRecipients.filter(r => r.id === 'devs')).toHaveLength(1)
		expect(wrapper.vm.notifyRecipients.length).toBe(before + 1)

		wrapper.vm.removeNotifyRecipient({ type: 'group', id: 'devs' })
		await flushPromises()
		expect(wrapper.vm.notifyRecipients.find(r => r.id === 'devs')).toBeFalsy()
		expect(wrapper.vm.notifyRecipients.length).toBe(before)
	})

	it('saveNotifyRecipients splits the owner (notify_owner) from the other recipients', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		wrapper.vm.addNotifyRecipient({ type: 'group', id: 'devs', displayName: 'Devs' })
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(
			'/apps/formvox/api/form/7',
			expect.objectContaining({
				settings: expect.objectContaining({
					notify_owner: true,
					notify_recipients: [{ type: 'group', id: 'devs', displayName: 'Devs' }],
				}),
			}),
		)
		// Owner (alice) is not duplicated into the stored recipients list.
		const lastCall = axios.put.mock.calls[axios.put.mock.calls.length - 1]
		expect(lastCall[1].settings.notify_recipients.find(r => r.id === 'alice')).toBeFalsy()
	})

	it('saveNotifyRecipients records notify_owner=false once the owner is removed', async () => {
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		wrapper.vm.removeNotifyRecipient({ type: 'user', id: 'alice' })
		await flushPromises()

		const lastCall = axios.put.mock.calls[axios.put.mock.calls.length - 1]
		expect(lastCall[1].settings.notify_owner).toBe(false)
	})

	// ---- toggleConfirmationEmail (#103 / #6) ----

	it('toggleConfirmationEmail on appends an auto-generated respondent-email question', async () => {
		const form = makeForm({ public_token: 'tok' })
		form.questions = [{ id: 'q1', type: 'text', question: 'Name' }]
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.toggleConfirmationEmail(true)
		await flushPromises()

		expect(wrapper.vm.responseSettings.sendConfirmationEmail).toBe(true)
		const lastCall = axios.put.mock.calls[axios.put.mock.calls.length - 1]
		const sent = lastCall[1].questions
		const auto = sent.find(q => q.useAsRespondentEmail)
		expect(auto).toBeTruthy()
		expect(auto.autoGenerated).toBe(true)
		expect(auto.required).toBe(true)
		expect(auto.validation.type).toBe('email')
		expect(form.questions.find(q => q.useAsRespondentEmail)).toBeTruthy()
	})

	it('toggleConfirmationEmail on registers the new question on the last page (#6)', async () => {
		const form = makeForm({ public_token: 'tok' })
		form.questions = [{ id: 'q1', type: 'text' }]
		form.pages = [{ id: 'p1', questions: ['q1'] }, { id: 'p2', questions: [] }]
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.toggleConfirmationEmail(true)
		await flushPromises()

		const lastCall = axios.put.mock.calls[axios.put.mock.calls.length - 1]
		const payload = lastCall[1]
		expect(payload.pages).toBeTruthy()
		const autoQ = payload.questions.find(q => q.useAsRespondentEmail)
		// The id lives on the LAST page only.
		expect(payload.pages[payload.pages.length - 1].questions).toContain(autoQ.id)
		expect(payload.pages[0].questions).not.toContain(autoQ.id)
	})

	it('toggleConfirmationEmail off removes the auto-generated question and its page id', async () => {
		const form = makeForm({ public_token: 'tok', sendConfirmationEmail: true })
		const autoQ = { id: 'qauto', type: 'text', useAsRespondentEmail: true, autoGenerated: true }
		form.questions = [{ id: 'q1', type: 'text' }, autoQ]
		form.pages = [{ id: 'p1', questions: ['q1', 'qauto'] }]
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.toggleConfirmationEmail(false)
		await flushPromises()

		const lastCall = axios.put.mock.calls[axios.put.mock.calls.length - 1]
		const payload = lastCall[1]
		expect(payload.questions.find(q => q.id === 'qauto')).toBeFalsy()
		expect(payload.pages[0].questions).not.toContain('qauto')
		expect(payload.questions.find(q => q.id === 'q1')).toBeTruthy()
		expect(wrapper.vm.responseSettings.sendConfirmationEmail).toBe(false)
	})

	it('toggleConfirmationEmail off keeps a user-customised email question but clears its flag', async () => {
		const form = makeForm({ public_token: 'tok', sendConfirmationEmail: true })
		// autoGenerated is falsy → the user adopted the question; must be kept.
		const custom = { id: 'qcustom', type: 'text', useAsRespondentEmail: true }
		form.questions = [custom]
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.toggleConfirmationEmail(false)
		await flushPromises()

		const lastCall = axios.put.mock.calls[axios.put.mock.calls.length - 1]
		const sentCustom = lastCall[1].questions.find(q => q.id === 'qcustom')
		expect(sentCustom).toBeTruthy()
		expect(sentCustom.useAsRespondentEmail).toBe(false)
	})

	it('toggleConfirmationEmail rolls the toggle back when the save rejects', async () => {
		axios.put.mockRejectedValue(new Error('boom'))
		const form = makeForm({ public_token: 'tok' })
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.toggleConfirmationEmail(true)
		await flushPromises()

		// The optimistic true is reverted on failure.
		expect(wrapper.vm.responseSettings.sendConfirmationEmail).toBe(false)
	})

	it('toggleConfirmationEmail on with an existing respondent-email question does not add a second one', async () => {
		const form = makeForm({ public_token: 'tok' })
		form.questions = [{ id: 'qx', type: 'text', useAsRespondentEmail: true, autoGenerated: false }]
		const wrapper = mountDialog(form)
		await flushPromises()

		await wrapper.vm.toggleConfirmationEmail(true)
		await flushPromises()

		const lastCall = axios.put.mock.calls[axios.put.mock.calls.length - 1]
		const emailQs = lastCall[1].questions.filter(q => q.useAsRespondentEmail)
		expect(emailQs).toHaveLength(1)
		expect(emailQs[0].id).toBe('qx')
	})
})
