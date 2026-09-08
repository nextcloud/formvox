import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import TemplateCard from '@/components/TemplateCard.vue'

/**
 * Characterization tests for TemplateCard — a button rendering name/description,
 * a dynamic icon component and a colour CSS var; emits 'select' on click.
 */
const IconStub = defineComponent({
	name: 'IconStub',
	props: { size: { type: Number, default: 0 } },
	render() {
		return h('span', { class: 'icon-stub' }, 'icon')
	},
})

describe('TemplateCard', () => {
	const baseProps = {
		name: 'Survey',
		description: 'Feedback and opinions',
		icon: IconStub,
	}

	it('renders the name and description', () => {
		const wrapper = mount(TemplateCard, { props: baseProps })
		expect(wrapper.get('.template-card__name').text()).toBe('Survey')
		expect(wrapper.get('.template-card__description').text()).toBe('Feedback and opinions')
	})

	it('renders the provided icon component', () => {
		const wrapper = mount(TemplateCard, { props: baseProps })
		expect(wrapper.find('.icon-stub').exists()).toBe(true)
	})

	it('applies the default colour when none is given', () => {
		const wrapper = mount(TemplateCard, { props: baseProps })
		expect(wrapper.get('.template-card').attributes('style')).toContain('#0082c9')
	})

	it('applies a custom colour', () => {
		const wrapper = mount(TemplateCard, { props: { ...baseProps, color: '#9C27B0' } })
		expect(wrapper.get('.template-card').attributes('style')).toContain('#9C27B0')
	})

	it('emits select on click', async () => {
		const wrapper = mount(TemplateCard, { props: baseProps })
		await wrapper.get('button.template-card').trigger('click')
		expect(wrapper.emitted('select')).toHaveLength(1)
	})
})
