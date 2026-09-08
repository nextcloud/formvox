import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ProgressBarBlock from '@/components/pagebuilder/blocks/ProgressBarBlock.vue'

const make = (block, props = {}) => mount(ProgressBarBlock, { props: { block, ...props } })

describe('ProgressBarBlock', () => {
	it('uses progress when set', () => {
		const w = make({ settings: { progress: 30, demoProgress: 80 } })
		expect(w.get('.progress-bar').attributes('style')).toContain('width: 30%')
	})

	it('falls back to demoProgress when progress unset', () => {
		const w = make({ settings: { demoProgress: 70 } })
		expect(w.get('.progress-bar').attributes('style')).toContain('width: 70%')
	})

	it('defaults to 50% when neither set', () => {
		const w = make({ settings: {} })
		expect(w.get('.progress-bar').attributes('style')).toContain('width: 50%')
	})

	it('treats progress of 0 as 0% (not fallback)', () => {
		const w = make({ settings: { progress: 0, demoProgress: 90 } })
		expect(w.get('.progress-bar').attributes('style')).toContain('width: 0%')
	})

	it('shows percentage text when showPercentage is set', () => {
		const w = make({ settings: { demoProgress: 42, showPercentage: true } })
		expect(w.get('.progress-text').text()).toBe('42%')
	})

	it('hides percentage text when showPercentage is falsy', () => {
		const w = make({ settings: { demoProgress: 42 } })
		expect(w.find('.progress-text').exists()).toBe(false)
	})

	it('renders the label when set', () => {
		const w = make({ settings: { label: 'Step 1' } })
		expect(w.get('.progress-label').text()).toBe('Step 1')
	})

	it('omits label element when no label', () => {
		const w = make({ settings: {} })
		expect(w.find('.progress-label').exists()).toBe(false)
	})

	it('uses settings color for the bar', () => {
		const w = make({ settings: { color: 'rgb(1, 2, 3)' } })
		expect(w.get('.progress-bar').attributes('style')).toContain('background-color: rgb(1, 2, 3)')
	})

	it('falls back to globalStyles.primaryColor', () => {
		const w = make({ settings: {} }, { globalStyles: { primaryColor: 'rgb(4, 5, 6)' } })
		expect(w.get('.progress-bar').attributes('style')).toContain('background-color: rgb(4, 5, 6)')
	})

	it('applies alignment class (default center)', () => {
		const w = make({ settings: {} })
		expect(w.get('.block-progress-bar').classes()).toContain('align-center')
	})
})
