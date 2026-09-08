import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SocialLinksBlock from '@/components/pagebuilder/blocks/SocialLinksBlock.vue'

const make = (block, props = {}) => mount(SocialLinksBlock, { props: { block, ...props } })

describe('SocialLinksBlock', () => {
	it('renders a link per configured social link with a url', () => {
		const w = make({ settings: { links: [
			{ platform: 'facebook', url: 'facebook.com/x' },
			{ platform: 'twitter', url: 'https://x.com/y' },
		] } })
		expect(w.findAll('a.social-icon')).toHaveLength(2)
	})

	it('filters out links without a url', () => {
		const w = make({ settings: { links: [
			{ platform: 'facebook', url: '' },
			{ platform: 'twitter', url: 'https://x.com/y' },
		] } })
		expect(w.findAll('a.social-icon')).toHaveLength(1)
	})

	it('normalizes a bare url to https:// in view mode', () => {
		const w = make({ settings: { links: [{ platform: 'facebook', url: 'facebook.com/x' }] } })
		expect(w.get('a.social-icon').attributes('href')).toBe('https://facebook.com/x')
	})

	it('keeps qualified urls unchanged', () => {
		const w = make({ settings: { links: [{ platform: 'twitter', url: 'https://x.com/y' }] } })
		expect(w.get('a.social-icon').attributes('href')).toBe('https://x.com/y')
	})

	it('omits href in edit mode', () => {
		const w = make({ settings: { links: [{ platform: 'facebook', url: 'facebook.com/x' }] } }, { editMode: true })
		expect(w.get('a.social-icon').attributes('href')).toBeUndefined()
	})

	it('shows placeholder in edit mode when no visible links', () => {
		const w = make({ settings: { links: [] } }, { editMode: true })
		expect(w.get('.placeholder').text()).toBe('Add social links in settings')
	})

	it('renders an icon component for each link', () => {
		const w = make({ settings: { links: [{ platform: 'facebook', url: 'facebook.com/x' }] } })
		expect(w.get('a.social-icon').find('svg').exists()).toBe(true)
	})

	it('handles missing links array without error', () => {
		const w = make({ settings: {} })
		expect(w.findAll('a.social-icon')).toHaveLength(0)
	})

	it('applies alignment class (default center)', () => {
		const w = make({ settings: {} })
		expect(w.get('.block-social-links').classes()).toContain('align-center')
	})
})
