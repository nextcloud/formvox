<template>
  <div class="bar-chart-container">
    <Bar :data="chartData" :options="chartOptions" />
  </div>
</template>

<script>
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Tooltip,
  Legend,
} from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend);

export default {
  name: 'BarChart',
  components: { Bar },
  props: {
    data: {
      type: Object,
      required: true,
    },
    horizontal: {
      type: Boolean,
      default: false,
    },
    title: {
      type: String,
      default: '',
    },
  },
  setup(props) {
    const chartData = computed(() => {
      const labels = Object.keys(props.data);
      const values = Object.values(props.data);

      return {
        labels,
        datasets: [{
          data: values,
          backgroundColor: '#0082c9',
          borderRadius: 4,
          borderSkipped: false,
        }],
      };
    });

    const chartOptions = computed(() => ({
      indexAxis: props.horizontal ? 'y' : 'x',
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: {
          display: false,
        },
        tooltip: {
          callbacks: {
            label: (context) => {
              const total = context.dataset.data.reduce((a, b) => a + b, 0);
              const value = context.raw;
              const percentage = Math.round((value / total) * 100);
              return `${value} responses (${percentage}%)`;
            },
          },
        },
      },
      scales: {
        x: {
          grid: {
            display: !props.horizontal,
          },
          ticks: {
            font: {
              size: 12,
            },
          },
        },
        y: {
          grid: {
            display: props.horizontal,
          },
          ticks: {
            font: {
              size: 12,
            },
          },
          beginAtZero: true,
        },
      },
    }));

    return {
      chartData,
      chartOptions,
    };
  },
};
</script>

<style scoped>
.bar-chart-container {
  max-width: 600px;
}
</style>
