<script setup lang="ts">
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import {
  Chart as ChartJS,
  Title,
  Tooltip,
  Legend,
  BarElement,
  CategoryScale,
  LinearScale
} from 'chart.js';
import type { BudgetVsRevenueItem } from '@/services/reportService';
import { useThemeStore } from '@/stores/theme';
import { storeToRefs } from 'pinia';

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

const props = defineProps<{
  data: BudgetVsRevenueItem[];
}>();

const themeStore = useThemeStore();
const { isDark } = storeToRefs(themeStore);

const chartData = computed(() => {
  return {
    labels: props.data.map(d => d.name),
    datasets: [
      {
        label: 'Budget',
        backgroundColor: '#6366f1', // Indigo 500
        data: props.data.map(d => d.budget),
        borderRadius: 4,
        barPercentage: 0.6,
        categoryPercentage: 0.8
      },
      {
        label: 'Invoiced',
        backgroundColor: '#10b981', // Emerald 500
        data: props.data.map(d => d.revenue),
        borderRadius: 4,
        barPercentage: 0.6,
        categoryPercentage: 0.8
      }
    ]
  };
});

const chartOptions = computed(() => {
  const textColor = isDark.value ? '#94a3b8' : '#475569';
  const gridColor = isDark.value ? '#334155' : '#e2e8f0';

  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'top' as const,
        labels: {
          color: textColor,
          usePointStyle: true,
        }
      },
      tooltip: {
        backgroundColor: isDark.value ? '#1e293b' : '#ffffff',
        titleColor: isDark.value ? '#f8fafc' : '#0f172a',
        bodyColor: isDark.value ? '#cbd5e1' : '#334155',
        borderColor: isDark.value ? '#334155' : '#e2e8f0',
        borderWidth: 1,
        callbacks: {
            label: function(context: any) {
                let label = context.dataset.label || '';
                if (label) {
                    label += ': ';
                }
                if (context.parsed.y !== null) {
                    label += new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(context.parsed.y);
                }
                return label;
            }
        }
      }
    },
    scales: {
      x: {
        grid: {
          display: false,
          color: gridColor
        },
        ticks: {
          color: textColor
        }
      },
      y: {
        grid: {
          color: gridColor,
          borderDash: [4, 4]
        },
        ticks: {
          color: textColor,
          callback: function(value: any) {
              return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', notation: 'compact' }).format(value);
          }
        }
      }
    }
  };
});
</script>

<template>
  <div class="h-[300px] w-full">
    <Bar :data="chartData" :options="chartOptions" />
  </div>
</template>
