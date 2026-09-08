import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PieChart from '@/components/charts/PieChart.vue'

/**
 * Smoke tests for PieChart — wraps vue-chartjs. Asserts it mounts and derives
 * chartData (labels/values + a colour per slice) from the data prop.
 */
describe('PieChart', () => {
	it('mounts and renders without error', () => {
		const wrapper = mount(PieChart, { props: { data: { A: 2, B: 5 } } })
		expect(wrapper.find('.pie-chart-container').exists()).toBe(true)
	})

	it('derives labels and values from the data prop', () => {
		const wrapper = mount(PieChart, { props: { data: { A: 2, B: 5 } } })
		expect(wrapper.vm.chartData.labels).toEqual(['A', 'B'])
		expect(wrapper.vm.chartData.datasets[0].data).toEqual([2, 5])
	})

	it('assigns one colour per label', () => {
		const wrapper = mount(PieChart, { props: { data: { A: 1, B: 1 } } })
		expect(wrapper.vm.chartData.datasets[0].backgroundColor).toHaveLength(2)
	})

	it('handles an empty data object', () => {
		const wrapper = mount(PieChart, { props: { data: {} } })
		expect(wrapper.vm.chartData.labels).toEqual([])
	})
})
