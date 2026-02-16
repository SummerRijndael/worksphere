<script setup lang="ts">
import { computed } from 'vue';
import { Card } from '@/components/ui';
import { Doughnut } from 'vue-chartjs';
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js';
import { useAnalyticsStore } from '@/stores/analytics';

ChartJS.register(ArcElement, Tooltip, Legend);

const store = useAnalyticsStore();

const deviceData = computed(() => {
    if (!store.demographics?.devices) return null;
    return {
        labels: Object.keys(store.demographics.devices),
        datasets: [{
            data: Object.values(store.demographics.devices),
            backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
        }]
    };
});

const browserData = computed(() => {
    if (!store.demographics?.browsers) return null;
    return {
        labels: store.demographics.browsers.map(b => b.label),
        datasets: [{
            data: store.demographics.browsers.map(b => b.value),
            backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
        }]
    };
});

const osData = computed(() => {
    if (!store.demographics?.os) return null;
    return {
        labels: store.demographics.os.map(o => o.label),
        datasets: [{
            data: store.demographics.os.map(o => o.value),
            backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
        }]
    };
});

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'right' as const,
            labels: {
                usePointStyle: true,
                boxWidth: 20,
                padding: 20,
                color: '#9ca3af' // gray-400 for better visibility in dark/light mode
            }
        }
    }
};
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-3">
        <!-- Device Types -->
        <Card padding="lg">
            <h3 class="text-lg font-semibold text-[var(--text-primary)] mb-4">Device Types</h3>
            <div class="h-64 relative">
                <Doughnut v-if="deviceData" :data="deviceData" :options="chartOptions" />
                <div v-else class="flex h-full items-center justify-center text-[var(--text-muted)]">
                    No data available
                </div>
            </div>
        </Card>

        <!-- Browsers -->
        <Card padding="lg">
            <h3 class="text-lg font-semibold text-[var(--text-primary)] mb-4">Browsers</h3>
            <div class="h-64 relative">
                <Doughnut v-if="browserData" :data="browserData" :options="chartOptions" />
                <div v-else class="flex h-full items-center justify-center text-[var(--text-muted)]">
                    No data available
                </div>
            </div>
        </Card>

        <!-- Operating Systems -->
        <Card padding="lg">
            <h3 class="text-lg font-semibold text-[var(--text-primary)] mb-4">Operating Systems</h3>
            <div class="h-64 relative">
                <Doughnut v-if="osData" :data="osData" :options="chartOptions" />
                <div v-else class="flex h-full items-center justify-center text-[var(--text-muted)]">
                    No data available
                </div>
            </div>
        </Card>
    </div>
</template>
