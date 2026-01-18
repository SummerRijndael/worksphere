<script setup lang="ts">
// @ts-nocheck
import { computed, watch, ref } from 'vue';
import { Line } from 'vue-chartjs';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js';
import { useThemeStore } from '@/stores/theme';

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
);

interface Dataset {
  label: string;
  data: number[];
  borderColor?: string;
  backgroundColor?: string;
}

interface Props {
  labels: string[];
  datasets: Dataset[];
  title?: string;
  height?: number;
}

const props = withDefaults(defineProps<Props>(), {
  height: 300,
});

const themeStore = useThemeStore();
const chartKey = ref(0);

// Force re-render when theme changes
watch(() => themeStore.currentTheme, () => {
  chartKey.value++;
});

const chartData = computed(() => ({
  labels: props.labels,
  datasets: props.datasets.map((dataset, index) => ({
    label: dataset.label,
    data: dataset.data,
    borderColor: dataset.borderColor || getDefaultColor(index),
    backgroundColor: dataset.backgroundColor || getDefaultBgColor(index),
    borderWidth: 2,
    fill: true,
    tension: 0.4,
    pointRadius: 4,
    pointHoverRadius: 6,
    pointBackgroundColor: dataset.borderColor || getDefaultColor(index),
    pointBorderColor: themeStore.currentTheme === 'dark' ? '#1f2937' : '#ffffff',
    pointBorderWidth: 2,
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
      grid: {
        color: themeStore.currentTheme === 'dark' ? 'rgba(75, 85, 99, 0.3)' : 'rgba(229, 231, 235, 0.8)',
        drawBorder: false,
      },
      ticks: {
        color: themeStore.currentTheme === 'dark' ? '#9ca3af' : '#6b7280',
        font: {
          size: 11,
        },
      },
    },
    y: {
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

function getDefaultColor(index: number): string {
  const colors = [
    'rgb(139, 92, 246)', // purple
    'rgb(249, 115, 22)', // orange
    'rgb(59, 130, 246)', // blue
    'rgb(34, 197, 94)',  // green
    'rgb(236, 72, 153)', // pink
  ];
  return colors[index % colors.length];
}

function getDefaultBgColor(index: number): string {
  const colors = [
    'rgba(139, 92, 246, 0.1)',
    'rgba(249, 115, 22, 0.1)',
    'rgba(59, 130, 246, 0.1)',
    'rgba(34, 197, 94, 0.1)',
    'rgba(236, 72, 153, 0.1)',
  ];
  return colors[index % colors.length];
}
</script>

<template>
  <div :style="{ height: `${height}px` }">
    <Line
      :key="chartKey"
      :data="chartData"
      :options="chartOptions"
    />
  </div>
</template>
