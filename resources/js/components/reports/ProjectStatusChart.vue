<script setup lang="ts">
import { computed } from 'vue';
import { Doughnut } from 'vue-chartjs';
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js';
import type { ProjectStatusDistribution } from '@/services/reportService';
import { useThemeStore } from '@/stores/theme';
import { storeToRefs } from 'pinia';

ChartJS.register(ArcElement, Tooltip, Legend);

const props = defineProps<{
  distribution: ProjectStatusDistribution[];
}>();

const themeStore = useThemeStore();
const { isDark } = storeToRefs(themeStore);

const themeColors = {
  primary: '#3b82f6', // blue-500
  secondary: '#64748b', // slate-500
  success: '#10b981', // emerald-500
  warning: '#f59e0b', // amber-500
  error: '#ef4444', // red-500
  info: '#0ea5e9', // sky-500
};

const chartData = computed(() => {
  return {
    labels: props.distribution.map(d => d.status),
    datasets: [{
      backgroundColor: props.distribution.map(d => {
           if (d.color && d.color in themeColors) {
               return themeColors[d.color as keyof typeof themeColors];
           }
           return d.color || generateRandomColor();
      }),
      data: props.distribution.map(d => d.count),
      borderWidth: 0,
      hoverOffset: 4
    }]
  };
});

const chartOptions = computed(() => {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'right' as const,
        labels: {
          color: isDark.value ? '#94a3b8' : '#475569',
          font: {
            family: "'Inter', sans-serif",
            size: 12
          },
          usePointStyle: true,
          padding: 20
        }
      },
      tooltip: {
        backgroundColor: isDark.value ? '#1e293b' : '#ffffff',
        titleColor: isDark.value ? '#f8fafc' : '#0f172a',
        bodyColor: isDark.value ? '#cbd5e1' : '#334155',
        borderColor: isDark.value ? '#334155' : '#e2e8f0',
        borderWidth: 1,
        padding: 12,
        boxPadding: 4
      }
    },
    cutout: '75%'
  };
});

function generateRandomColor() {
  return '#' + Math.floor(Math.random()*16777215).toString(16);
}
</script>

<template>
  <div class="h-[300px] w-full">
    <Doughnut :data="chartData" :options="chartOptions" />
  </div>
</template>
