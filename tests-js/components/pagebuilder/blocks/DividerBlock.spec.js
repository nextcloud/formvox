import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import DividerBlock from '@/components/pagebuilder/blocks/DividerBlock.vue'

const make = (block) => mount(DividerBlock, { props: { block } })

describe('DividerBlock', () => {
	it('renders an <hr>', () => {
		const w = make({ settings: {} })
		expect(w.find('hr').exists()).toBe(true)
	})

	it('applies alignment class', () => {
		const w = make({ alignment: 'left', settings: {} })
		expect(w.get('.block-divider').classes()).toContain('align-left')
	})

	it('defaults alignment to center', () => {
		const w = make({ settings: {} })
		expect(w.get('.block-divider').classes()).toContain('align-center')
	})

	it('applies color, style and thickness to the hr', () => {
		const w = make({ settings: { color: 'rgb(1, 2, 3)', style: 'dashed', thickness: 4 } })
		const style = w.get('hr').attributes('style')
		expect(style).toContain('border-color: rgb(1, 2, 3)')
		expect(style).toContain('border-style: dashed')
		expect(style).toContain('border-width: 4px 0px 0px')
	})

	it('defaults thickness to 1px', () => {
		const w = make({ settings: {} })
		expect(w.get('hr').attributes('style')).toContain('border-width: 1px 0px 0px')
	})
})
