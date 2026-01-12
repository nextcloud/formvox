<template>
  <div class="pie-chart-container">
    <Pie :data="chartData" :options="chartOptions" />
  </div>
</template>

<script>
import { computed } from 'vue';
import { Pie } from 'vue-chartjs';
import {
  Chart as ChartJS,
  ArcElement,
  Tooltip,
  Legend,
} from 'chart.js';

ChartJS.register(ArcElement, Tooltip, Legend);

export default {
  name: 'PieChart',
  components: { Pie },
  props: {
    data: {
      type: Object,
      required: true,
    },
    title: {
      type: String,
      default: '',
    },
  },
  setup(props) {
    const colors = [
      '#0082c9', // Nextcloud blue
      '#e9322d', // Red
      '#46ba61', // Green
      '#eca700', // Orange
      '#745bca', // Purple
      '#00b4a0', // Teal
      '#f57c00', // Deep Orange
      '#8bc34a', // Light Green
      '#e91e63', // Pink
      '#607d8b', // Blue Grey
    ];

    const chartData = computed(() => {
      const labels = Object.keys(props.data);
      const values = Object.values(props.data);

      return {
        labels,
        datasets: [{
          data: values,
          backgroundColor: colors.slice(0, labels.length),
          borderWidth: 2,
          borderColor: '#fff',
        }],
      };
    });

    const chartOptions = {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: {
          position: 'right',
          labels: {
            padding: 15,
            usePointStyle: true,
            font: {
              size: 13,
            },
          },
        },
        tooltip: {
          callbacks: {
            label: (context) => {
              const total = context.dataset.data.reduce((a, b) => a + b, 0);
              const value = context.raw;
              const percentage = Math.round((value / total) * 100);
              return `${context.label}: ${value} (${percentage}%)`;
            },
          },
        },
      },
    };

    return {
      chartData,
      chartOptions,
    };
  },
};
</script>

<style scoped>
.pie-chart-container {
  max-width: 400px;
  margin: 0 auto;
}
</style>
