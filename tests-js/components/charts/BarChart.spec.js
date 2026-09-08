import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BarChart from '@/components/charts/BarChart.vue'

/**
 * Smoke tests for BarChart — wraps vue-chartjs. We assert it mounts without
 * error and derives chartData from the data prop.
 */
describe('BarChart', () => {
	it('mounts and renders without error', () => {
		const wrapper = mount(BarChart, { props: { data: { Yes: 3, No: 1 } } })
		expect(wrapper.find('.bar-chart-container').exists()).toBe(true)
	})

	it('derives chart labels and values from the data prop', () => {
		const wrapper = mount(BarChart, { props: { data: { Yes: 3, No: 1 } } })
		expect(wrapper.vm.chartData.labels).toEqual(['Yes', 'No'])
		expect(wrapper.vm.chartData.datasets[0].data).toEqual([3, 1])
	})

	it('uses a y index axis when horizontal', () => {
		const wrapper = mount(BarChart, { props: { data: { A: 1 }, horizontal: true } })
		expect(wrapper.vm.chartOptions.indexAxis).toBe('y')
	})

	it('uses an x index axis by default', () => {
		const wrapper = mount(BarChart, { props: { data: { A: 1 } } })
		expect(wrapper.vm.chartOptions.indexAxis).toBe('x')
	})

	it('handles an empty data object', () => {
		const wrapper = mount(BarChart, { props: { data: {} } })
		expect(wrapper.vm.chartData.labels).toEqual([])
	})
})
