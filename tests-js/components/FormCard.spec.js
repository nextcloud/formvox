import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FormCard from '@/components/FormCard.vue'

/**
 * Characterization tests for FormCard — renders a form summary card and emits
 * click / delete. Colour + badge are derived from form.template; description is
 * truncated to 60 chars; the date is formatted via toLocaleDateString.
 */
describe('FormCard', () => {
	const baseForm = {
		title: 'My Form',
		description: 'A short description',
		responseCount: 5,
		modifiedAt: '2024-01-02T00:00:00Z',
		template: 'survey',
	}

	it('renders the form title', () => {
		const wrapper = mount(FormCard, { props: { form: baseForm } })
		expect(wrapper.get('.form-card__title').text()).toBe('My Form')
	})

	it('renders the description and response count', () => {
		const wrapper = mount(FormCard, { props: { form: baseForm } })
		expect(wrapper.get('.form-card__description').text()).toBe('A short description')
		expect(wrapper.get('.form-card__responses').text()).toContain('5')
	})

	it('renders a badge label for a known template', () => {
		const wrapper = mount(FormCard, { props: { form: baseForm } })
		expect(wrapper.get('.form-card__badge').text()).toBe('Survey')
	})

	it('renders no badge for an unknown / missing template', () => {
		const wrapper = mount(FormCard, { props: { form: { ...baseForm, template: undefined } } })
		expect(wrapper.find('.form-card__badge').exists()).toBe(false)
	})

	it('renders no badge for a template without a label (blank)', () => {
		const wrapper = mount(FormCard, { props: { form: { ...baseForm, template: 'blank' } } })
		expect(wrapper.find('.form-card__badge').exists()).toBe(false)
	})

	it('sets the card colour from the template', () => {
		const wrapper = mount(FormCard, { props: { form: baseForm } })
		expect(wrapper.get('.form-card').attributes('style')).toContain('#9C27B0')
	})

	it('falls back to the default colour for an unknown template', () => {
		const wrapper = mount(FormCard, { props: { form: { ...baseForm, template: 'nope' } } })
		expect(wrapper.get('.form-card').attributes('style')).toContain('#0082c9')
	})

	it('truncates a long description to 60 chars + ellipsis', () => {
		const long = 'x'.repeat(100)
		const wrapper = mount(FormCard, { props: { form: { ...baseForm, description: long } } })
		const shown = wrapper.get('.form-card__description').text()
		expect(shown).toBe('x'.repeat(60) + '...')
	})

	it('omits the description element when there is none', () => {
		const wrapper = mount(FormCard, { props: { form: { ...baseForm, description: '' } } })
		expect(wrapper.find('.form-card__description').exists()).toBe(false)
	})

	it('emits click when the card is clicked', async () => {
		const wrapper = mount(FormCard, { props: { form: baseForm } })
		await wrapper.get('.form-card').trigger('click')
		expect(wrapper.emitted('click')).toHaveLength(1)
	})

	it('emits delete when the delete action button emits click', async () => {
		const wrapper = mount(FormCard, { props: { form: baseForm } })
		// FormCard binds @click.stop on NcActionButton, so the handler needs a
		// real event object; emit one with a stopPropagation stub.
		await wrapper.getComponent({ name: 'NcActionButton' }).vm.$emit('click', { stopPropagation() {} })
		expect(wrapper.emitted('delete')).toHaveLength(1)
	})

	it('does not emit click on the card when delete is triggered (stops propagation)', async () => {
		const wrapper = mount(FormCard, { props: { form: baseForm } })
		await wrapper.getComponent({ name: 'NcActionButton' }).vm.$emit('click', { stopPropagation() {} })
		expect(wrapper.emitted('click')).toBeUndefined()
	})

	it('renders an empty date when modifiedAt is missing', () => {
		const wrapper = mount(FormCard, { props: { form: { ...baseForm, modifiedAt: null } } })
		expect(wrapper.get('.form-card__date').text()).toBe('')
	})
})
