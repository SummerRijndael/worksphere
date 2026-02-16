<script setup>
import { ref, onMounted, onUnmounted, watch } from "vue";

import {
    ShieldCheckIcon,
    NoSymbolIcon,
    ExclamationTriangleIcon,
    ClockIcon,
    LockClosedIcon,
    ArrowPathIcon,
    GlobeAmericasIcon,
    MapIcon,
    UserMinusIcon, // Added UserMinusIcon
    PauseCircleIcon, // Added PauseCircleIcon
} from "@heroicons/vue/24/outline";
import DashboardStatsCard from "@/components/admin/DashboardStatsCard.vue";
import SecurityActivityFeed from "@/components/admin/SecurityActivityFeed.vue";
import BlockedIpsTable from "@/components/admin/BlockedIpsTable.vue";
import BannedUsersTable from "@/components/admin/BannedUsersTable.vue";
import SuspiciousActivityTable from "@/components/admin/SuspiciousActivityTable.vue";
import WhitelistedIpsTable from "@/components/admin/WhitelistedIpsTable.vue";
import DashboardLineChart from "@/components/charts/DashboardLineChart.vue";
import DashboardDoughnutChart from "@/components/charts/DashboardDoughnutChart.vue";
import SecurityMap from "@/components/admin/SecurityMap.vue";
import { SelectFilter } from "@/components/ui";
import api from "@/lib/api";

// Alias api to axios to avoid changing all calls
const axios = api;

const activeTab = ref("overview");
const stats = ref({
    blocked_ips: 0,
    whitelisted_ips: 0,
    banned_users: 0,
    suspended_users: 0,
    incidents_today: 0,
});
const loadingStats = ref(true);

// Chart Data
const chartData = ref({
    trend: { labels: [], datasets: [] },
    distribution: { labels: [], data: [] }
});
const loadingCharts = ref(true);

// Filter Data
const selectedPeriod = ref("1w");
const periods = [
    { label: "Last 24 Hours", value: "24h" },
    { label: "Last 7 Days", value: "1w" },
    { label: "Last 30 Days", value: "1m" },
    { label: "Last 3 Months", value: "3m" },
    { label: "Last 6 Months", value: "6m" },
    { label: "Last 12 Months", value: "1y" },
];

// Map Data
const mapData = ref([]);
const loadingMap = ref(true);

const fetchMapData = async () => {
    loadingMap.value = true;
    try {
        const response = await api.get("/api/admin/security/map-data", {
            params: { period: selectedPeriod.value }
        });
        mapData.value = response.data || [];
    } catch (error) {
        console.error("Failed to fetch map data", error);
    } finally {
        loadingMap.value = false;
    }
};

const fetchStats = async () => {
    loadingStats.value = true;
    try {
        const response = await api.get("/api/admin/security/stats", {
            params: { period: selectedPeriod.value }
        });
        stats.value = { ...stats.value, ...response.data };
    } catch (error) {
        console.error("Failed to fetch security stats", error);
    } finally {
        loadingStats.value = false;
    }
};

const fetchCharts = async () => {
    loadingCharts.value = true;
    try {
        const response = await api.get("/api/admin/security/charts", {
            params: { period: selectedPeriod.value }
        });
        const { trend = [], distribution = [] } = response.data || {};

        // Process Trend Data
        chartData.value.trend = {
            labels: trend.map(t => t.label),
            datasets: [
                {
                    label: 'Security Incidents',
                    data: trend.map(t => t.count),
                    borderColor: 'rgb(139, 92, 246)',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                }
            ]
        };

        // Process Distribution Data
        chartData.value.distribution = {
            labels: distribution.map(d => d.label),
            data: distribution.map(d => d.count)
        };
    } catch (error) {
        console.error("Failed to fetch security charts", error);
    } finally {
        loadingCharts.value = false;
    }
};

const analytics = ref({
    top_offenders: { ips: [], users: [] },
    active_blocks: [],
});

const fetchAnalytics = async () => {
    try {
        const response = await api.get("/api/admin/security/analytics", {
            params: { period: selectedPeriod.value }
        });
        analytics.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch security analytics", error);
    }
};

watch(selectedPeriod, () => {
    refreshDashboard();
});

const formatTimer = (seconds) => {
    if (seconds < 0) return "Permanent";
    if (seconds === 0) return "Expired";
    
    const d = Math.floor(seconds / (3600 * 24));
    const h = Math.floor((seconds % (3600 * 24)) / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;

    let parts = [];
    if (d > 0) parts.push(`${d}d`);
    if (h > 0) parts.push(`${h}h`);
    if (m > 0) parts.push(`${m}m`);
    if (parts.length < 2) parts.push(`${s}s`);
    
    return parts.join(" ");
};

const refreshDashboard = () => {
    fetchStats();
    fetchCharts();
    fetchMapData();
    fetchAnalytics();
};

onMounted(() => {
    refreshDashboard();
    
    // Simple interval to update countdowns every minute
    const timer = setInterval(() => {
        analytics.value.active_blocks = analytics.value.active_blocks.map(block => {
            if (block.remaining_seconds > 0) {
                return { ...block, remaining_seconds: block.remaining_seconds - 60 };
            }
            return block;
        });
    }, 60000);
    
    onUnmounted(() => clearInterval(timer));
});
</script>

<template>
    <div class="p-4 sm:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-8">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-(--surface-card) p-6 rounded-2xl shadow-sm border border-(--border-default)">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-(--text-primary)">
                    Security Dashboard
                </h1>
                <p class="text-(--text-secondary) mt-1 text-lg">
                    Real-time monitoring and management of system security protocols.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <!-- Period Selector -->
                <div class="flex items-center bg-(--surface-secondary) rounded-lg p-1 border border-(--border-default)">
                    <div class="flex items-center px-2 text-(--text-tertiary)">
                        <ClockIcon class="w-4 h-4" />
                    </div>
                    <SelectFilter
                        v-model="selectedPeriod"
                        :options="periods"
                        size="sm"
                        :show-placeholder="false"
                        class="w-[160px]"
                    />
                </div>
                <button
                    @click="refreshDashboard"
                    class="btn btn-secondary flex items-center gap-2 px-6 hover:shadow-md transition-all duration-200"
                    :disabled="loadingStats || loadingCharts"
                >
                    <ArrowPathIcon class="w-5 h-5" :class="{ 'animate-spin': loadingStats || loadingCharts }" />
                    Refresh Data
                </button>
            </div>
        </div>

        <!-- Stats Overview -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <DashboardStatsCard
                title="Blocked IP Addresses"
                :value="stats.blocked_ips ?? 0"
                icon="NoSymbolIcon"
                color="text-red-500"
                bg-color="bg-red-500/10"
                class="hover:scale-[1.02] transition-transform duration-200"
            />
            <DashboardStatsCard
                title="Whitelisted IPs"
                :value="stats.whitelisted_ips ?? 0"
                icon="ShieldCheckIcon"
                color="text-emerald-500"
                bg-color="bg-emerald-500/10"
                class="hover:scale-[1.02] transition-transform duration-200"
            />
            <DashboardStatsCard
                title="Banned User Logins"
                :value="stats.banned_users ?? 0"
                icon="UserMinusIcon"
                color="text-orange-500"
                bg-color="bg-orange-500/10"
                class="hover:scale-[1.02] transition-transform duration-200"
            />
            <DashboardStatsCard
                :title="`Critical Incidents (${selectedPeriod})`"
                :value="stats.incidents_period ?? stats.incidents_today ?? 0"
                icon="ExclamationTriangleIcon"
                color="text-blue-500"
                bg-color="bg-blue-500/10"
                class="hover:scale-[1.02] transition-transform duration-200"
            />
        </div>

        <!-- Navigation Tabs -->
        <div class="flex border-b border-(--border-default) overflow-x-auto gap-8 no-scrollbar bg-(--surface-card) px-6 rounded-xl shadow-sm">
            <button
                @click="activeTab = 'overview'"
                class="py-4 px-2 text-base font-semibold border-b-2 transition-all duration-200 whitespace-nowrap flex items-center gap-2"
                :class="activeTab === 'overview' ? 'border-(--interactive-primary) text-(--interactive-primary)' : 'border-transparent text-(--text-secondary) hover:text-(--text-primary)'"
            >
                <ShieldCheckIcon class="w-5 h-5" />
                Overview
            </button>
            <button
                @click="activeTab = 'threat-map'"
                class="py-4 px-2 text-base font-semibold border-b-2 transition-all duration-200 whitespace-nowrap flex items-center gap-2"
                :class="activeTab === 'threat-map' ? 'border-(--interactive-primary) text-(--interactive-primary)' : 'border-transparent text-(--text-secondary) hover:text-(--text-primary)'"
            >
                <GlobeAmericasIcon class="w-5 h-5" />
                Threat Map
            </button>
            <button
                @click="activeTab = 'suspicious-activity'"
                class="py-4 px-2 text-base font-semibold border-b-2 transition-all duration-200 whitespace-nowrap flex items-center gap-2"
                :class="activeTab === 'suspicious-activity' ? 'border-(--interactive-primary) text-(--interactive-primary)' : 'border-transparent text-(--text-secondary) hover:text-(--text-primary)'"
            >
                <ExclamationTriangleIcon class="w-5 h-5" />
                Suspicious Activity
            </button>
            <button
                @click="activeTab = 'active-blocks'"
                class="py-4 px-2 text-base font-semibold border-b-2 transition-all duration-200 whitespace-nowrap flex items-center gap-2"
                :class="activeTab === 'active-blocks' ? 'border-(--interactive-primary) text-(--interactive-primary)' : 'border-transparent text-(--text-secondary) hover:text-(--text-primary)'"
            >
                <ClockIcon class="w-5 h-5" />
                Active Blocks
            </button>
            <button
                @click="activeTab = 'banned-users'"
                class="py-4 px-2 text-base font-semibold border-b-2 transition-all duration-200 whitespace-nowrap flex items-center gap-2"
                :class="activeTab === 'banned-users' ? 'border-(--interactive-primary) text-(--interactive-primary)' : 'border-transparent text-(--text-secondary) hover:text-(--text-primary)'"
            >
                <LockClosedIcon class="w-5 h-5" />
                Banned Users
            </button>
            <button
                @click="activeTab = 'whitelisted-ips'"
                class="py-4 px-2 text-base font-semibold border-b-2 transition-all duration-200 whitespace-nowrap flex items-center gap-2"
                :class="activeTab === 'whitelisted-ips' ? 'border-(--interactive-primary) text-(--interactive-primary)' : 'border-transparent text-(--text-secondary) hover:text-(--text-primary)'"
            >
                <ShieldCheckIcon class="w-5 h-5" />
                Whitelist
            </button>
        </div>

        <!-- Tab Content -->
        <div class="mt-8 transition-all duration-300">
            <!-- Overview Tab -->
            <div v-if="activeTab === 'overview'" class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
                
                <!-- TOP OFFENDERS SECTION -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Top IP Offenders -->
                    <div class="bg-(--surface-card) p-6 rounded-2xl shadow-sm border border-(--border-default)">
                        <h3 class="text-lg font-bold mb-6 text-(--text-primary) flex items-center gap-2">
                            <NoSymbolIcon class="w-5 h-5 text-red-500" />
                            Top IP Offenders
                        </h3>
                        <div class="overflow-hidden">
                            <table class="w-full text-left">
                                <thead class="text-xs font-bold text-(--text-tertiary) uppercase tracking-wider">
                                    <tr>
                                        <th class="pb-3 px-2">IP Address</th>
                                        <th class="pb-3 px-2">Location</th>
                                        <th class="pb-3 px-2 text-right">Attempts</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-(--border-subtle)">
                                    <tr v-for="offender in analytics.top_offenders.ips" :key="offender.ip_address" class="hover:bg-(--surface-subtle) transition-colors">
                                        <td class="py-3 px-2 font-mono text-sm text-(--text-primary)">{{ offender.ip_address }}</td>
                                        <td class="py-3 px-2 text-sm text-(--text-secondary)">
                                            <div class="flex items-center gap-2">
                                                <span v-if="offender.country_code" class="text-lg">{{ offender.country_code.toUpperCase().replace(/./g, char => String.fromCodePoint(char.charCodeAt(0) + 127397)) }}</span>
                                                <span>{{ offender.city || offender.country_name || 'Unknown' }}</span>
                                            </div>
                                        </td>
                                        <td class="py-3 px-2 text-sm font-bold text-red-500 text-right">{{ offender.total_attempts }}</td>
                                    </tr>
                                    <tr v-if="analytics.top_offenders.ips.length === 0">
                                        <td colspan="3" class="py-8 text-center text-(--text-tertiary) italic">No data available</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Top User Offenders -->
                    <div class="bg-(--surface-card) p-6 rounded-2xl shadow-sm border border-(--border-default)">
                        <h3 class="text-lg font-bold mb-6 text-(--text-primary) flex items-center gap-2">
                            <LockClosedIcon class="w-5 h-5 text-orange-500" />
                            Top User Targets
                        </h3>
                        <div class="overflow-hidden">
                            <table class="w-full text-left">
                                <thead class="text-xs font-bold text-(--text-tertiary) uppercase tracking-wider">
                                    <tr>
                                        <th class="pb-3 px-2">User</th>
                                        <th class="pb-3 px-2">Account</th>
                                        <th class="pb-3 px-2 text-right">Failures</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-(--border-subtle)">
                                    <tr v-for="user in analytics.top_offenders.users" :key="user.user_id" class="hover:bg-(--surface-subtle) transition-colors">
                                        <td class="py-3 px-2 text-sm font-bold text-(--text-primary)">{{ user.user_name }}</td>
                                        <td class="py-3 px-2 text-sm text-(--text-secondary)">{{ user.user_email }}</td>
                                        <td class="py-3 px-2 text-sm font-bold text-orange-500 text-right">{{ user.count }}</td>
                                    </tr>
                                    <tr v-if="analytics.top_offenders.users.length === 0">
                                        <td colspan="3" class="py-8 text-center text-(--text-tertiary) italic">No data available</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
                    <!-- Charts Column -->
                    <div class="xl:col-span-2 space-y-8">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <div class="bg-(--surface-card) p-6 rounded-2xl shadow-sm border border-(--border-default)">
                                <h3 class="text-lg font-bold mb-6 text-(--text-primary) flex items-center gap-2">
                                    <ClockIcon class="w-5 h-5 text-(--interactive-primary)" />
                                    Security Incidents Trend
                                </h3>
                                <div v-if="loadingCharts" class="h-64 flex items-center justify-center">
                                    <ArrowPathIcon class="w-8 h-8 animate-spin text-(--text-tertiary)" />
                                </div>
                                <DashboardLineChart
                                    v-else
                                    :labels="chartData.trend.labels"
                                    :datasets="chartData.trend.datasets"
                                />
                            </div>

                            <div class="bg-(--surface-card) p-6 rounded-2xl shadow-sm border border-(--border-default)">
                                <h3 class="text-lg font-bold mb-6 text-(--text-primary) flex items-center gap-2">
                                    <ShieldCheckIcon class="w-5 h-5 text-(--interactive-primary)" />
                                    Incident Distribution
                                </h3>
                                <div v-if="loadingCharts" class="h-64 flex items-center justify-center">
                                    <ArrowPathIcon class="w-8 h-8 animate-spin text-(--text-tertiary)" />
                                </div>
                                <DashboardDoughnutChart
                                    v-else
                                    :labels="chartData.distribution.labels"
                                    :data="chartData.distribution.data"
                                />
                            </div>
                        </div>

                        <!-- Mini Map in Overview -->
                        <div class="bg-(--surface-card) p-6 rounded-2xl shadow-sm border border-(--border-default)">
                             <div class="flex justify-between items-center mb-6">
                                <h3 class="text-lg font-bold text-(--text-primary) flex items-center gap-2">
                                    <GlobeAmericasIcon class="w-5 h-5 text-(--interactive-primary)" />
                                    Global Threat Surface
                                </h3>
                                <button @click="activeTab = 'threat-map'" class="text-sm font-medium text-(--interactive-primary) hover:underline">
                                    Expand View &rarr;
                                </button>
                            </div>
                            <SecurityMap :data="mapData" :loading="loadingMap" class="h-[400px]" />
                        </div>
                    </div>

                    <!-- Side Activity Feed -->
                    <div class="xl:col-span-1">
                        <div class="bg-(--surface-card) p-6 rounded-2xl shadow-sm border border-(--border-default) h-full flex flex-col min-h-[600px] max-h-[900px]">
                            <div class="flex justify-between items-center mb-6 shrink-0">
                                <h3 class="text-lg font-bold text-(--text-primary) flex items-center gap-2">
                                    <ClockIcon class="w-5 h-5 text-(--interactive-primary)" />
                                    Security Audit Logs
                                </h3>
                                <button
                                    @click="activeTab = 'suspicious-activity'"
                                    class="text-sm font-medium text-(--interactive-primary) hover:underline"
                                >
                                    View Detailed &rarr;
                                </button>
                            </div>
                            <div class="flex-1 overflow-y-auto pr-2 custom-scrollbar">
                                <SecurityActivityFeed :activities="analytics.security_logs" :loading="false" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Threat Map Tab -->
            <div v-if="activeTab === 'threat-map'" class="animate-in fade-in slide-in-from-bottom-4 duration-500">
                <div class="bg-(--surface-card) p-8 rounded-2xl shadow-sm border border-(--border-default)">
                    <div class="mb-8">
                        <h2 class="text-2xl font-bold text-(--text-primary)">Global Threat Map</h2>
                        <p class="text-(--text-secondary) mt-2">Visualizing the origin and intensity of detected security threats across the globe.</p>
                    </div>
                    <SecurityMap :data="mapData" :loading="loadingMap" class="h-[600px]" />
                </div>
            </div>

            <!-- Suspicious Activity Tab -->
            <div v-if="activeTab === 'suspicious-activity'" class="animate-in fade-in slide-in-from-bottom-4 duration-500">
                <div class="bg-(--surface-card) p-8 rounded-2xl shadow-sm border border-(--border-default)">
                    <div class="mb-8">
                        <h2 class="text-2xl font-bold text-(--text-primary)">Security Audit Trail</h2>
                        <p class="text-(--text-secondary) mt-2">Comprehensive logs of all system-access attempts, profile changes, and security events.</p>
                    </div>
                    <SuspiciousActivityTable />
                </div>
            </div>

            <!-- Active Blocks Tab -->
            <div v-if="activeTab === 'active-blocks'" class="animate-in fade-in slide-in-from-bottom-4 duration-500">
                <div class="bg-(--surface-card) p-8 rounded-2xl shadow-sm border border-(--border-default)">
                    <div class="mb-8 flex justify-between items-center">
                        <div>
                            <h2 class="text-2xl font-bold text-(--text-primary)">Active IP Bans</h2>
                            <p class="text-(--text-secondary) mt-2">Currently enforced network-level blocks with remaining time visualization.</p>
                        </div>
                        <NoSymbolIcon class="w-12 h-12 text-red-500 opacity-20" />
                    </div>
                    
                    <div class="overflow-hidden bg-(--surface-subtle) rounded-xl border border-(--border-subtle)">
                        <table class="w-full text-left">
                            <thead class="text-xs font-bold text-(--text-tertiary) uppercase tracking-wider bg-(--surface-card)">
                                <tr>
                                    <th class="py-4 px-6">IP Address</th>
                                    <th class="py-4 px-6">Reason / Trigger</th>
                                    <th class="py-4 px-6 text-center">Remaining Time</th>
                                    <th class="py-4 px-6">Blocked At</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-(--border-subtle)">
                                <tr v-for="block in analytics.active_blocks" :key="block.id" class="hover:bg-(--surface-card) transition-colors">
                                    <td class="py-4 px-6 font-mono font-bold text-(--text-primary)">{{ block.ip }}</td>
                                    <td class="py-4 px-6 text-sm">
                                        <div class="flex items-center gap-2">
                                            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase" 
                                                  :class="block.remaining_seconds < 0 ? 'bg-red-500/10 text-red-500' : 'bg-orange-500/10 text-orange-500'">
                                                {{ block.remaining_seconds < 0 ? 'PERMANENT' : 'TEMPORARY' }}
                                            </span>
                                            <span class="text-(--text-secondary)">{{ block.reason }}</span>
                                        </div>
                                    </td>
                                    <td class="py-4 px-6 text-center">
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold"
                                              :class="block.remaining_seconds < 0 ? 'bg-red-500/10 text-red-500' : 'bg-blue-500/10 text-blue-500'">
                                            <ClockIcon class="w-3.5 h-3.5" />
                                            {{ formatTimer(block.remaining_seconds) }}
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 text-sm text-(--text-tertiary)">
                                        {{ new Date(block.created_at).toLocaleString() }}
                                    </td>
                                </tr>
                                <tr v-if="analytics.active_blocks.length === 0">
                                    <td colspan="4" class="py-12 text-center text-(--text-tertiary)">
                                        <ShieldCheckIcon class="w-12 h-12 mx-auto mb-3 opacity-20" />
                                        <p class="font-medium text-lg">No active IP blocks detected.</p>
                                        <p class="text-sm">The system is currently clear of enforced network bans.</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Banned Users Tab -->
            <div v-if="activeTab === 'banned-users'" class="animate-in fade-in duration-300">
                <BannedUsersTable />
            </div>

            <!-- Whitelisted IPs Tab -->
            <div v-if="activeTab === 'whitelisted-ips'" class="animate-in fade-in duration-300">
                <WhitelistedIpsTable @updated="refreshDashboard" />
            </div>
        </div>
    </div>
</template>
