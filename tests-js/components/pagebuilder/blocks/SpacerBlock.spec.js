import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SpacerBlock from '@/components/pagebuilder/blocks/SpacerBlock.vue'

const make = (block, props = {}) => mount(SpacerBlock, { props: { block, ...props } })

describe('SpacerBlock', () => {
	it('maps size "small" to 16px', () => {
		const w = make({ settings: { size: 'small' } })
		expect(w.get('.block-spacer').attributes('style')).toContain('height: 16px')
	})

	it('maps size "large" to 64px', () => {
		const w = make({ settings: { size: 'large' } })
		expect(w.get('.block-spacer').attributes('style')).toContain('height: 64px')
	})

	it('defaults to medium (32px) when size unset', () => {
		const w = make({ settings: {} })
		expect(w.get('.block-spacer').attributes('style')).toContain('height: 32px')
	})

	it('customHeight overrides the named size', () => {
		const w = make({ settings: { size: 'small', customHeight: 100 } })
		expect(w.get('.block-spacer').attributes('style')).toContain('height: 100px')
	})

	it('shows the px indicator in edit mode', () => {
		const w = make({ settings: { size: 'large' } }, { editMode: true })
		expect(w.get('.spacer-indicator').text()).toBe('64px')
	})

	it('hides the indicator when not in edit mode', () => {
		const w = make({ settings: { size: 'large' } })
		expect(w.find('.spacer-indicator').exists()).toBe(false)
	})
})
