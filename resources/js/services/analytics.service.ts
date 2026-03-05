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

    getDemographics(period: AnalyticsPeriod) {
        return axios.get<{ data: any }>(`${BASE_URL}/demographics`, { params: { period } });
    },

    getGeoStats(period: AnalyticsPeriod) {
        return axios.get<{ data: any[] }>(`${BASE_URL}/geo-stats`, { params: { period } });
    },

    trackPageVisit(path: string, fingerprint?: string | null) {
        return axios.post('/api/analytics/track', {
            path,
            url: window.location.href,
            referer: document.referrer,
            fingerprint
        });
    },

    // Aggregated fetch for initial load
    async getAll(period: AnalyticsPeriod) {
        const [overview, chart, pages, sources, demographics, geoStats] = await Promise.all([
            this.getOverview(period),
            this.getChart(period),
            this.getTopPages(period),
            this.getSources(period),
            this.getDemographics(period),
            this.getGeoStats(period)
        ]);

        return {
            overview: overview.data.data,
            chart: chart.data.data,
            topPages: pages.data.data,
            sources: sources.data.data,
            demographics: demographics.data.data,
            geoStats: geoStats.data.data
        };
    },

    async exportData(type: 'traffic' | 'pages' | 'sources', period: AnalyticsPeriod) {
        const response = await axios.get(`${BASE_URL}/export`, {
            params: { type, period },
            responseType: 'blob'
        });

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        const filename = `analytics_${type}_${period}_${new Date().toISOString().split('T')[0]}.csv`;
        link.setAttribute('download', filename);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
};
