<script setup lang="ts">
// @ts-nocheck
import { computed, watch, ref } from 'vue';
import { Doughnut } from 'vue-chartjs';
import {
  Chart as ChartJS,
  ArcElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js';
import { useThemeStore } from '@/stores/theme';

ChartJS.register(
  ArcElement,
  Title,
  Tooltip,
  Legend
);

interface Props {
  labels: string[];
  data: number[];
  backgroundColor?: string[];
  title?: string;
  height?: number;
  cutout?: string;
}

const props = withDefaults(defineProps<Props>(), {
  height: 280,
  cutout: '65%',
});

const themeStore = useThemeStore();
const chartKey = ref(0);

// Force re-render when theme changes
watch(() => themeStore.currentTheme, () => {
  chartKey.value++;
});

const defaultColors = [
  'rgb(59, 130, 246)',   // blue
  'rgb(34, 197, 94)',    // green
  'rgb(245, 158, 11)',   // amber
  'rgb(156, 163, 175)',  // gray
  'rgb(139, 92, 246)',   // purple
  'rgb(249, 115, 22)',   // orange
];

const chartData = computed(() => ({
  labels: props.labels,
  datasets: [{
    data: props.data,
    backgroundColor: props.backgroundColor || defaultColors.slice(0, props.data.length),
    borderColor: themeStore.currentTheme === 'dark' ? '#1f2937' : '#ffffff',
    borderWidth: 3,
    hoverOffset: 8,
  }],
}));

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  cutout: props.cutout,
  plugins: {
    legend: {
      position: 'bottom' as const,
      labels: {
        color: themeStore.currentTheme === 'dark' ? '#9ca3af' : '#374151',
        usePointStyle: true,
        padding: 16,
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
        bottom: 16,
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
      callbacks: {
        label: function(context: any) {
          const label = context.label || '';
          const value = context.raw || 0;
          const total = context.dataset.data.reduce((a: number, b: number) => a + b, 0);
          const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
          return `${label}: ${value} (${percentage}%)`;
        }
      }
    },
  },
}));

// Calculate total for center display
const total = computed(() => props.data.reduce((a, b) => a + b, 0));
</script>

<template>
  <div class="relative" :style="{ height: `${height}px` }">
    <Doughnut
      :key="chartKey"
      :data="chartData"
      :options="chartOptions"
    />
    <!-- Center text -->
    <div 
      class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none"
      :style="{ paddingBottom: '48px' }"
    >
      <span class="text-3xl font-bold text-[var(--text-primary)]">{{ total }}</span>
      <span class="text-xs text-[var(--text-muted)] uppercase tracking-wide">Total</span>
    </div>
  </div>
</template>
