<script setup lang="ts">
import { onMounted, computed } from 'vue';
import { Card, Button } from '@/components/ui';
import { TrendingUp, TrendingDown, Users, Eye, Clock, ArrowUpRight } from 'lucide-vue-next';
import { useAnalyticsStore } from '@/stores/analytics';
import { Bar } from 'vue-chartjs';
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js';

// Register ChartJS components
ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale);

const store = useAnalyticsStore();

// Icons map for dynamic component rendering
const icons = {
    Eye,
    Users,
    Clock,
    ArrowUpRight
};

const chartData = computed(() => {
    return {
        labels: store.chart.map(p => p.date),
        datasets: [{
            label: 'Views',
            backgroundColor: '#3b82f6', // primary-500 equivalent
            data: store.chart.map(p => p.count)
        }]
    };
});

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false }
    },
    scales: {
        y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.1)' }
        },
        x: {
            grid: { display: false }
        }
    }
};

const periods = ['24h', '7d', '30d', '90d'] as const;

onMounted(() => {
    store.fetchAll();
});
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">Analytics</h1>
                <p class="text-[var(--text-secondary)] mt-1">
                    Monitor your application performance and user engagement.
                </p>
            </div>
            <div class="flex gap-2">
                <Button
                    v-for="p in periods"
                    :key="p"
                    :variant="store.period === p ? 'secondary' : 'ghost'"
                    size="sm"
                    :disabled="store.loading"
                    @click="store.fetchAll(p)"
                >
                    {{ p }}
                </Button>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card v-for="stat in store.overview" :key="stat.id" padding="lg" hover>
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-sm text-[var(--text-secondary)]">{{ stat.label }}</p>
                        <p class="text-2xl font-bold text-[var(--text-primary)] mt-1">{{ stat.value }}</p>
                    </div>
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--color-primary-100)] dark:bg-[var(--color-primary-900)]/30">
                        <component :is="icons[stat.icon as keyof typeof icons] || Eye" class="h-5 w-5 text-[var(--color-primary-600)] dark:text-[var(--color-primary-400)]" />
                    </div>
                </div>
                <div class="mt-3 flex items-center gap-1.5">
                    <component
                        :is="stat.trend === 'up' ? TrendingUp : TrendingDown"
                        :class="[
                            'h-4 w-4',
                            stat.trend === 'up' ? 'text-green-500' : 'text-red-500'
                        ]"
                    />
                    <span
                        :class="[
                            'text-sm font-medium',
                            stat.trend === 'up' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'
                        ]"
                    >
                        {{ stat.change }}
                    </span>
                    <span class="text-sm text-[var(--text-muted)]">vs last period</span>
                </div>
            </Card>
        </div>

        <!-- Charts Row -->
        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Traffic Chart -->
            <Card padding="lg">
                <h2 class="text-lg font-semibold text-[var(--text-primary)] mb-4">Traffic Overview</h2>
                <div class="h-64 rounded-lg bg-[var(--surface-secondary)] p-4">
                    <Bar v-if="store.chart.length > 0" :data="chartData" :options="chartOptions" />
                    <div v-else class="h-full flex items-center justify-center text-[var(--text-muted)]">
                        No data available
                    </div>
                </div>
            </Card>

            <!-- Traffic Sources -->
            <Card padding="lg">
                <h2 class="text-lg font-semibold text-[var(--text-primary)] mb-4">Traffic Sources</h2>
                <div class="space-y-4">
                    <div v-for="source in store.sources" :key="source.source">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-sm text-[var(--text-primary)]">{{ source.source }}</span>
                            <span class="text-sm text-[var(--text-secondary)]">{{ source.visits.toLocaleString() }} ({{ source.percentage }}%)</span>
                        </div>
                        <div class="h-2 rounded-full bg-[var(--surface-tertiary)] overflow-hidden">
                            <div
                                class="h-full rounded-full bg-[var(--interactive-primary)] transition-all duration-500"
                                :style="{ width: `${source.percentage}%` }"
                            />
                        </div>
                    </div>
                     <div v-if="store.sources.length === 0" class="text-center text-[var(--text-muted)] py-8">
                        No traffic source data
                    </div>
                </div>
            </Card>
        </div>

        <!-- Top Pages -->
        <Card padding="none">
            <div class="p-5 border-b border-[var(--border-default)]">
                <h2 class="text-lg font-semibold text-[var(--text-primary)]">Top Pages</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-[var(--border-default)]">
                            <th class="px-5 py-3 text-left text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider">Page</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider">Views</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider">Unique</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider">Avg. Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-muted)]">
                        <tr v-for="page in store.topPages" :key="page.path" class="hover:bg-[var(--surface-secondary)] transition-colors">
                            <td class="px-5 py-4">
                                <span class="text-sm font-medium text-[var(--text-primary)]">{{ page.path }}</span>
                            </td>
                            <td class="px-5 py-4 text-right text-sm text-[var(--text-secondary)]">{{ page.views }}</td>
                            <td class="px-5 py-4 text-right text-sm text-[var(--text-secondary)]">{{ page.unique }}</td>
                            <td class="px-5 py-4 text-right text-sm text-[var(--text-secondary)]">{{ page.avgTime }}</td>
                        </tr>
                         <tr v-if="store.topPages.length === 0">
                            <td colspan="4" class="px-5 py-8 text-center text-[var(--text-muted)]">
                                No page view data recorded yet
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
</template>
