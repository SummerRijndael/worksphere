<script setup lang="ts">
// @ts-nocheck
import { computed, watch, ref } from 'vue';
import { Bar } from 'vue-chartjs';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js';
import { useThemeStore } from '@/stores/theme';

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend
);

interface Dataset {
  label: string;
  data: number[];
  backgroundColor?: string;
}

interface Props {
  labels: string[];
  datasets: Dataset[];
  title?: string;
  height?: number;
  stacked?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  height: 280,
  stacked: false,
});

const themeStore = useThemeStore();
const chartKey = ref(0);

// Force re-render when theme changes
watch(() => themeStore.currentTheme, () => {
  chartKey.value++;
});

const defaultColors = [
  'rgba(249, 115, 22, 0.8)',  // orange
  'rgba(34, 197, 94, 0.8)',   // green
  'rgba(59, 130, 246, 0.8)',  // blue
  'rgba(139, 92, 246, 0.8)',  // purple
];

const chartData = computed(() => ({
  labels: props.labels,
  datasets: props.datasets.map((dataset, index) => ({
    label: dataset.label,
    data: dataset.data,
    backgroundColor: dataset.backgroundColor || defaultColors[index % defaultColors.length],
    borderRadius: 6,
    borderSkipped: false,
    barThickness: props.datasets.length > 1 ? 20 : 32,
    maxBarThickness: 40,
  })),
}));

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: {
    intersect: false,
    mode: 'index' as const,
  },
  plugins: {
    legend: {
      position: 'top' as const,
      labels: {
        color: themeStore.currentTheme === 'dark' ? '#9ca3af' : '#374151',
        usePointStyle: true,
        padding: 20,
        font: {
          size: 12,
          // @ts-ignore
          weight: '500' as const,
        },
      },
    },
    title: {
      display: !!props.title,
      text: props.title,
      color: themeStore.currentTheme === 'dark' ? '#f3f4f6' : '#111827',
      font: {
        size: 16,
        weight: '600' as const,
      },
      padding: {
        bottom: 20,
      },
    },
    tooltip: {
      backgroundColor: themeStore.currentTheme === 'dark' ? '#374151' : '#ffffff',
      titleColor: themeStore.currentTheme === 'dark' ? '#f3f4f6' : '#111827',
      bodyColor: themeStore.currentTheme === 'dark' ? '#d1d5db' : '#4b5563',
      borderColor: themeStore.currentTheme === 'dark' ? '#4b5563' : '#e5e7eb',
      borderWidth: 1,
      cornerRadius: 8,
      padding: 12,
      displayColors: true,
      usePointStyle: true,
    },
  },
  scales: {
    x: {
      stacked: props.stacked,
      grid: {
        display: false,
      },
      ticks: {
        color: themeStore.currentTheme === 'dark' ? '#9ca3af' : '#6b7280',
        font: {
          size: 11,
        },
      },
    },
    y: {
      stacked: props.stacked,
      beginAtZero: true,
      grid: {
        color: themeStore.currentTheme === 'dark' ? 'rgba(75, 85, 99, 0.3)' : 'rgba(229, 231, 235, 0.8)',
        drawBorder: false,
      },
      ticks: {
        color: themeStore.currentTheme === 'dark' ? '#9ca3af' : '#6b7280',
        font: {
          size: 11,
        },
        stepSize: 1,
      },
    },
  },
}));
</script>

<template>
  <div :style="{ height: `${height}px` }">
    <Bar
      :key="chartKey"
      :data="chartData"
      :options="chartOptions"
    />
  </div>
</template>
