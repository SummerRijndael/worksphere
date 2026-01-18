export interface AnalyticsStat {
    id: number;
    label: string;
    value: string;
    change: string;
    trend: 'up' | 'down';
    icon: string;
}

export interface AnalyticsChartPoint {
    date: string;
    count: number;
}

export interface TopPage {
    path: string;
    views: string;
    unique: string;
    avgTime: string;
}

export interface TrafficSource {
    source: string;
    visits: number;
    percentage: number;
}

export type AnalyticsPeriod = '24h' | '7d' | '30d' | '90d' | 'year';

export interface AnalyticsState {
    overview: AnalyticsStat[];
    chart: AnalyticsChartPoint[];
    topPages: TopPage[];
    sources: TrafficSource[];
    loading: boolean;
    period: AnalyticsPeriod;
}
