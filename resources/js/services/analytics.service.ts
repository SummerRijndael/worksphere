import axios from 'axios';
import { AnalyticsPeriod, AnalyticsStat, AnalyticsChartPoint, TopPage, TrafficSource } from '@/types/analytics';

const BASE_URL = '/api/maintenance/analytics'; // Matches routes/api.php definition

export const analyticsService = {
    getOverview(period: AnalyticsPeriod) {
        return axios.get<{ data: AnalyticsStat[] }>(`${BASE_URL}/overview`, { params: { period } });
    },

    getChart(period: AnalyticsPeriod) {
        return axios.get<{ data: AnalyticsChartPoint[] }>(`${BASE_URL}/chart`, { params: { period } });
    },

    getTopPages(period: AnalyticsPeriod) {
        return axios.get<{ data: TopPage[] }>(`${BASE_URL}/pages`, { params: { period } });
    },

    getSources(period: AnalyticsPeriod) {
        return axios.get<{ data: TrafficSource[] }>(`${BASE_URL}/sources`, { params: { period } });
    },

    // Aggregated fetch for initial load
    async getAll(period: AnalyticsPeriod) {
        const [overview, chart, pages, sources] = await Promise.all([
            this.getOverview(period),
            this.getChart(period),
            this.getTopPages(period),
            this.getSources(period)
        ]);

        return {
            overview: overview.data.data,
            chart: chart.data.data,
            topPages: pages.data.data,
            sources: sources.data.data
        };
    }
};
