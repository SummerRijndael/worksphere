<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { Bar } from 'vue-chartjs';
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js';
import { useThemeStore } from '@/stores/theme';
import type { UserWorkload } from '@/composables/useTicketReport';

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale);

const props = defineProps<{
    data: UserWorkload[];
}>();

const themeStore = useThemeStore();
const { isDark } = storeToRefs(themeStore);

const chartData = computed(() => {
    return {
        labels: props.data.map(u => u.name),
        datasets: [
            {
                label: 'Assigned Tickets',
                backgroundColor: '#3b82f6', // Tailwind blue-500
                data: props.data.map(u => u.count),
                borderRadius: 4,
            }
        ]
    };
});

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: false
        }
    },
    scales: {
        y: {
            beginAtZero: true,
            ticks: {
                 stepSize: 1,
                 color: isDark.value ? '#a1a1aa' : '#52525b' // zinc-400 / zinc-600
            },
            grid: {
                color: isDark.value ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)'
            }
        },
        x: {
            grid: {
                display: false
            },
            ticks: {
                color: isDark.value ? '#a1a1aa' : '#52525b'
            }
        }
    }
}));
</script>

<template>
    <div class="h-64 sm:h-80 w-full relative">
        <Bar :data="chartData" :options="chartOptions" />
        <div v-if="data.length === 0" class="absolute inset-0 flex items-center justify-center text-gray-400 text-sm">
            No data available
        </div>
    </div>
</template>
