import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PresenceAvatars from '@/components/PresenceAvatars.vue'

/**
 * Characterization tests for PresenceAvatars — shows up to 3 stacked avatars and
 * a text label. Hidden entirely when there are no editors. Single editor shows
 * the display name; multiple shows a "{count} others editing" label.
 */
describe('PresenceAvatars', () => {
	const editor = (id) => ({ userId: id, displayName: 'User ' + id })

	it('renders nothing when there are no editors', () => {
		const wrapper = mount(PresenceAvatars, { props: { editors: [] } })
		expect(wrapper.find('.presence-indicator').exists()).toBe(false)
	})

	it('renders nothing by default (no editors prop)', () => {
		const wrapper = mount(PresenceAvatars)
		expect(wrapper.find('.presence-indicator').exists()).toBe(false)
	})

	it('shows the display name for a single editor', () => {
		const wrapper = mount(PresenceAvatars, { props: { editors: [editor('a')] } })
		expect(wrapper.get('.presence-text').text()).toBe('User a')
	})

	it('shows a count label for multiple editors', () => {
		const wrapper = mount(PresenceAvatars, {
			props: { editors: [editor('a'), editor('b'), editor('c')] },
		})
		expect(wrapper.get('.presence-text').text()).toBe('3 others editing')
	})

	it('renders one avatar per editor up to 3', () => {
		const wrapper = mount(PresenceAvatars, {
			props: { editors: [editor('a'), editor('b')] },
		})
		expect(wrapper.findAll('[data-stub="NcAvatar"]')).toHaveLength(2)
	})

	it('caps the rendered avatars at 3 even with more editors', () => {
		const wrapper = mount(PresenceAvatars, {
			props: { editors: [editor('a'), editor('b'), editor('c'), editor('d'), editor('e')] },
		})
		expect(wrapper.findAll('[data-stub="NcAvatar"]')).toHaveLength(3)
		// but the count label reflects the full total
		expect(wrapper.get('.presence-text').text()).toBe('5 others editing')
	})
})
