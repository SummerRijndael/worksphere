<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { Doughnut } from 'vue-chartjs';
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js';
import { useThemeStore } from '@/stores/theme';
import type { TicketStats } from '@/composables/useTicketReport';

ChartJS.register(ArcElement, Tooltip, Legend);

const props = defineProps<{
    stats: TicketStats;
}>();

const themeStore = useThemeStore();
const { isDark } = storeToRefs(themeStore);

const chartData = computed(() => {
    return {
        labels: ['Open', 'In Progress', 'Resolved', 'Closed'],
        datasets: [
            {
                backgroundColor: [
                    '#f59e0b', // Open - Warning (Amber)
                    '#3b82f6', // In Progress - Primary (Blue)
                    '#10b981', // Resolved - Success (Emerald)
                    '#6b7280'  // Closed - Secondary (Gray)
                ],
                data: [
                    props.stats.open,
                    props.stats.in_progress,
                    props.stats.resolved,
                    props.stats.closed
                ],
                borderWidth: 2,
                borderColor: isDark.value ? '#18181b' : '#ffffff',
            }
        ]
    };
});

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'bottom' as const,
            labels: {
                usePointStyle: true,
                padding: 20,
                color: isDark.value ? '#a1a1aa' : '#52525b',
            }
        }
    },
    cutout: '60%',
}));
</script>

<template>
    <div class="h-64 w-full relative">
        <Doughnut :data="chartData" :options="chartOptions" />
    </div>
</template>
