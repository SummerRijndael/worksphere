<script setup>
import { computed } from 'vue';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
} from 'chart.js';
import { Line } from 'vue-chartjs';

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

const props = defineProps({
    data: {
        type: Object,
        required: true,
        default: () => ({})
    },
    loading: {
        type: Boolean,
        default: false
    }
});

const chartData = computed(() => {
    const labels = Object.keys(props.data);
    const values = Object.values(props.data);

    return {
        labels,
        datasets: [
            {
                label: 'New Registrations',
                backgroundColor: (context) => {
                    const ctx = context.chart.ctx;
                    const gradient = ctx.createLinearGradient(0, 0, 0, 400);
                    gradient.addColorStop(0, 'rgba(59, 130, 246, 0.2)'); // Blue with opacity
                    gradient.addColorStop(1, 'rgba(59, 130, 246, 0)');
                    return gradient;
                },
                borderColor: '#3b82f6', // Tailwind blue-500
                data: values,
                fill: true,
                tension: 0.4, // Smooth curve
                pointRadius: 0,
                pointHoverRadius: 4
            }
        ]
    };
});

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: false
        },
        tooltip: {
            mode: 'index',
            intersect: false,
            backgroundColor: 'rgba(0, 0, 0, 0.8)',
            titleColor: '#fff',
            bodyColor: '#fff',
            borderColor: 'rgba(255, 255, 255, 0.1)',
            borderWidth: 1,
            padding: 10,
            displayColors: false,
            callbacks: {
                title: (tooltipItems) => {
                    // Format date (e.g., "Jan 1, 2024")
                    const date = new Date(tooltipItems[0].label);
                    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                }
            }
        }
    },
    scales: {
        x: {
            display: false, // Hide x-axis labels for cleaner look or make minimal
            grid: {
                display: false
            }
        },
        y: {
            display: false, // Hide y-axis for cleaner sparkline look
            min: 0,
            grid: {
                display: false
            }
        }
    },
    interaction: {
        mode: 'nearest',
        axis: 'x',
        intersect: false
    }
};
</script>

<template>
    <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4 h-32 relative overflow-hidden flex flex-col">
        <div class="flex items-center justify-between mb-2">
             <p class="text-sm font-medium text-[var(--text-secondary)]">New Registrations (30d)</p>
             <!-- Use a dynamic total from the data if desired, or just show the chart -->
        </div>
        
        <div class="flex-1 w-full min-h-0 relative">
             <div v-if="loading" class="absolute inset-0 flex items-center justify-center bg-[var(--surface-elevated)] z-10 opacity-50">
                 <div class="h-full w-full bg-[var(--surface-tertiary)] animate-pulse rounded"></div>
             </div>
             <Line v-else :data="chartData" :options="chartOptions" />
        </div>
    </div>
</template>
