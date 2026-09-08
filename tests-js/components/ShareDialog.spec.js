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
})
