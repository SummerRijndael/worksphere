<script setup>
import { ref, onMounted, watch } from 'vue';
import { 
    HeartIcon, 
    ArrowPathIcon, 
    UsersIcon, 
    ChatBubbleLeftRightIcon,
    ArrowTrendingUpIcon,
    ArrowTrendingDownIcon,
    FaceSmileIcon,
    ChartBarIcon,
    ClockIcon,
    UserMinusIcon,
    UserPlusIcon
} from '@heroicons/vue/24/outline';
import DashboardStatsCard from '@/components/admin/DashboardStatsCard.vue';
import DashboardLineChart from '@/components/charts/DashboardLineChart.vue';
import DashboardDoughnutChart from '@/components/charts/DashboardDoughnutChart.vue';
import { SelectFilter } from '@/components/ui';
import api from '@/lib/api';
import AppReviewModeration from '@/components/admin/AppReviewModeration.vue';

const moderationRef = ref(null);

const sentiment = ref({
    average_rating: 0,
    total_reviews: 0,
    recent_reviews: 0,
    sentiment_trend: 0,
    vibe_status: 'Mixed'
});

const engagement = ref({
    total_users: 0,
    active_users: 0,
    new_users: 0,
    active_teams: 0,
    churn_rate: 0,
    retention_rate: 0,
    feature_usage: [],
    services_count: 0,
    contracts_count: 0,
    policies_count: 0
});

const loadingSentiment = ref(true);
const loadingEngagement = ref(true);
const selectedPeriod = ref('30d');

const periods = [
    { label: 'Last 24 Hours', value: '24h' },
    { label: 'Last 7 Days', value: '7d' },
    { label: 'Last 30 Days', value: '30d' },
    { label: 'Last 90 Days', value: '90d' },
    { label: 'Last Year', value: 'year' },
];

const fetchSentiment = async () => {
    loadingSentiment.value = true;
    try {
        const response = await api.get('/api/admin/system/maintenance/analytics/sentiment');
        if (response.data && response.data.data) {
            sentiment.value = response.data.data;
        }
    } catch (error) {
        console.error('Failed to fetch sentiment data', error);
    } finally {
        loadingSentiment.value = false;
    }
};

const fetchEngagement = async () => {
    loadingEngagement.value = true;
    try {
        const response = await api.get('/api/admin/system/maintenance/analytics/engagement', {
            params: { period: selectedPeriod.value }
        });
        if (response.data && response.data.data) {
            engagement.value = response.data.data;
        }
    } catch (error) {
        console.error('Failed to fetch engagement data', error);
    } finally {
        loadingEngagement.value = false;
    }
};

const refreshData = () => {
    fetchSentiment();
    fetchEngagement();
};

watch(selectedPeriod, () => {
    fetchEngagement();
});

onMounted(() => {
    refreshData();
    window.addEventListener('review-status-updated', fetchSentiment);
});

const getVibeColor = (status) => {
    switch (status?.toLowerCase()) {
        case 'excellent':
            return 'text-emerald-500';
        case 'positive':
            return 'text-blue-500';
        case 'mixed':
            return 'text-yellow-500';
        case 'needs attention':
            return 'text-red-500';
        default:
            return 'text-(--text-tertiary)';
    }
};

const getVibeBg = (status) => {
    switch (status?.toLowerCase()) {
        case 'excellent':
            return 'bg-emerald-500/10';
        case 'positive':
            return 'bg-blue-500/10';
        case 'mixed':
            return 'bg-yellow-500/10';
        case 'needs attention':
            return 'bg-red-500/10';
        default:
            return 'bg-(--surface-tertiary)';
    }
};
</script>

<template>
    <div class="p-4 sm:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-8">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-(--surface-card) p-6 rounded-2xl shadow-sm border border-(--border-default)">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-(--text-primary) flex items-center gap-3">
                    <FaceSmileIcon class="w-8 h-8 text-violet-500" />
                    User Pulse & Engagement
                </h1>
                <p class="text-(--text-secondary) mt-1 text-lg">
                    Understand how users feel and how they are engaging with WorkSphere.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <button
                    @click="refreshData"
                    class="btn btn-secondary flex items-center gap-2 px-6 hover:shadow-md transition-all duration-200"
                    :disabled="loadingSentiment || loadingEngagement"
                >
                    <ArrowPathIcon class="w-5 h-5" :class="{ 'animate-spin': loadingSentiment || loadingEngagement }" />
                    Refresh
                </button>
            </div>
        </div>

        <!-- Sentiment & High Level Stats -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <DashboardStatsCard
                title="Community Sentiment"
                :value="sentiment.average_rating + '/5'"
                icon="HeartIcon"
                :color="getVibeColor(sentiment.vibe_status)"
                :bg-color="getVibeBg(sentiment.vibe_status)"
                :change="sentiment.sentiment_trend + '%'"
                :trend="sentiment.sentiment_trend >= 0 ? 'up' : 'down'"
            />
            <DashboardStatsCard
                title="Engagement Score"
                :value="engagement.retention_rate + '%'"
                icon="ArrowTrendingUpIcon"
                color="text-emerald-500"
                bg-color="bg-emerald-500/10"
                description="Based on activity frequency"
            />
            <DashboardStatsCard
                title="Active Pulse (30d)"
                :value="engagement.active_users"
                icon="UsersIcon"
                color="text-blue-500"
                bg-color="bg-blue-500/10"
            />
            <DashboardStatsCard
                title="Business Vibe"
                :value="engagement.services_count + ' Services'"
                icon="ChartBarIcon"
                color="text-amber-500"
                bg-color="bg-amber-500/10"
                :description="engagement.contracts_count + ' Contracts active'"
            />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Sentiment & Retention Details -->
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-(--surface-card) p-6 rounded-2xl shadow-sm border border-(--border-default)">
                    <h3 class="text-lg font-bold mb-6 text-(--text-primary) flex items-center gap-2">
                        <FaceSmileIcon class="w-5 h-5 text-(--interactive-primary)" />
                        Community Vibe
                    </h3>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between p-4 rounded-xl bg-(--surface-secondary) border border-(--border-default)">
                            <div>
                                <p class="text-sm text-(--text-secondary)">Status</p>
                                <p class="font-bold text-lg text-(--text-primary)" :class="getVibeColor(sentiment.vibe_status)">
                                    {{ sentiment.vibe_status }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm text-(--text-secondary)">Total Reviews</p>
                                <p class="font-bold text-lg text-(--text-primary)">{{ sentiment.total_reviews }}</p>
                            </div>
                        </div>
                        <div class="p-4 rounded-xl border border-(--border-subtle) bg-(--surface-tertiary)/50">
                            <p class="text-sm text-(--text-secondary) mb-2">Recent Feedback (Last 30d)</p>
                            <div class="flex items-center gap-2">
                                <ChatBubbleLeftRightIcon class="w-5 h-5 text-violet-500" />
                                <span class="font-bold text-(--text-primary)">{{ sentiment.recent_reviews }} submissions</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-(--surface-card) p-6 rounded-2xl shadow-sm border border-(--border-default)">
                    <h3 class="text-lg font-bold mb-6 text-(--text-primary) flex items-center gap-2">
                        <UserPlusIcon class="w-5 h-5 text-(--interactive-primary)" />
                        Growth Metrics
                    </h3>
                    <div class="space-y-6">
                        <div class="flex items-end justify-between">
                            <div>
                                <p class="text-sm text-(--text-secondary)">Total Citizens</p>
                                <p class="text-2xl font-bold text-(--text-primary)">{{ engagement.total_users }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm text-(--text-secondary)">Active Teams</p>
                                <p class="text-2xl font-bold text-(--text-primary)">{{ engagement.active_teams }}</p>
                            </div>
                        </div>
                        <div class="pt-4 border-t border-(--border-subtle) space-y-3">
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-(--text-secondary)">New Signups</span>
                                <span class="font-bold text-emerald-500">+{{ engagement.new_users }}</span>
                            </div>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-(--text-secondary)">Policies Active</span>
                                <span class="font-bold text-violet-500">{{ engagement.policies_count }}</span>
                            </div>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-(--text-secondary)">Churn Probability</span>
                                <span class="font-bold" :class="engagement.churn_rate > 15 ? 'text-red-500' : 'text-emerald-500'">{{ engagement.churn_rate }}%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Feature Usage & Vibe Analysis -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-(--surface-card) p-6 rounded-2xl shadow-sm border border-(--border-default)">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                        <h3 class="text-lg font-bold text-(--text-primary) flex items-center gap-2">
                            <ChartBarIcon class="w-5 h-5 text-(--interactive-primary)" />
                            Feature Engagement Distribution
                        </h3>
                        <div class="flex items-center gap-2">
                            <ClockIcon class="w-4 h-4 text-(--text-tertiary)" />
                            <SelectFilter
                                v-model="selectedPeriod"
                                :options="periods"
                                size="sm"
                                :show-placeholder="false"
                                class="w-[160px]"
                            />
                        </div>
                    </div>

                    <div v-if="loadingEngagement" class="h-80 flex items-center justify-center">
                        <ArrowPathIcon class="w-8 h-8 animate-spin text-(--text-tertiary)" />
                    </div>
                    <div v-else-if="engagement.feature_usage && engagement.feature_usage.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div>
                            <DashboardDoughnutChart
                                :labels="engagement.feature_usage.map(f => f.event_type)"
                                :data="engagement.feature_usage.map(f => f.count)"
                            />
                        </div>
                        <div class="flex flex-col justify-center space-y-3">
                            <div v-for="(feat, idx) in engagement.feature_usage" :key="idx" class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="w-2.5 h-2.5 rounded-full" :style="{ backgroundColor: ['#8B5CF6', '#F59E0B', '#10B981', '#3B82F6', '#EF4444', '#EC4899', '#6366F1'][idx % 7] }"></div>
                                    <span class="text-sm font-medium text-(--text-primary) capitalize">{{ feat.event_type.replace(/_/g, ' ') }}</span>
                                </div>
                                <span class="text-xs font-bold text-(--text-secondary)">{{ feat.count }} interactions</span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="h-80 flex flex-col items-center justify-center text-(--text-tertiary)">
                        <ChartBarIcon class="w-12 h-12 mb-2 opacity-20" />
                        <p>No engagement data found for this period</p>
                    </div>
                </div>

                <!-- Moderation Queue -->
                <AppReviewModeration ref="moderationRef" />
            </div>
        </div>
    </div>
</template>
