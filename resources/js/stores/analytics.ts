import { defineStore } from 'pinia';
import { ref } from 'vue';
import { analyticsService } from '@/services/analytics.service';
import { AnalyticsPeriod, AnalyticsState } from '@/types/analytics';

export const useAnalyticsStore = defineStore('analytics', () => {
    const period = ref<AnalyticsPeriod>('7d');
    const loading = ref(false);
    
    // State Data
    const overview = ref<AnalyticsState['overview']>([]);
    const chart = ref<AnalyticsState['chart']>([]);
    const topPages = ref<AnalyticsState['topPages']>([]);
    const sources = ref<AnalyticsState['sources']>([]);

    async function fetchAll(newPeriod?: AnalyticsPeriod) {
        if (newPeriod) {
            period.value = newPeriod;
        }

        loading.value = true;
        try {
            const data = await analyticsService.getAll(period.value);
            overview.value = data.overview;
            chart.value = data.chart;
            topPages.value = data.topPages;
            sources.value = data.sources;
        } catch (error) {
            console.error('Failed to fetch analytics:', error);
        } finally {
            loading.value = false;
        }
    }

    return {
        period,
        loading,
        overview,
        chart,
        topPages,
        sources,
        fetchAll
    };
});
