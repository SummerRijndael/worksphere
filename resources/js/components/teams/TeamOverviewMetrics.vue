<script setup>
import { ref, onMounted } from "vue";
import axios from "axios";
import { useRoute } from "vue-router";
import Card from "@/components/ui/Card.vue";
import {
    CheckCircle,
    Clock,
    AlertCircle,
    PlayCircle,
    Zap,
    TrendingUp,
    ShieldCheck,
    Activity,
    BarChart3,
} from "lucide-vue-next";

const route = useRoute();
const stats = ref(null);
const loading = ref(true);

const fetchOverviewStats = async () => {
    loading.value = true;
    try {
        const response = await axios.get(
            `/api/teams/${route.params.public_id}/stats/analytics-overview`,
        );
        stats.value = response.data;
    } catch (error) {
        console.error("Error fetching overview stats:", error);
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchOverviewStats();
});
</script>

<template>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Adherence Rate -->
        <Card
            class="p-4 flex flex-col justify-between h-full bg-gradient-to-br from-[var(--bg-secondary)] to-[var(--bg-primary)] border-[var(--border-color)]"
        >
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h4
                        class="text-sm font-medium text-[var(--text-secondary)]"
                    >
                        Adherence Rate
                    </h4>
                    <p class="text-xs text-[var(--text-muted)] mt-1">
                        On-time completion
                    </p>
                </div>
                <div class="p-2 bg-blue-500/10 rounded-lg text-blue-500">
                    <CheckCircle class="w-5 h-5" />
                </div>
            </div>
            <div class="flex items-end gap-2" v-if="!loading && stats">
                <span class="text-2xl font-bold text-[var(--text-primary)]"
                    >{{ stats.adherence_rate }}%</span
                >
                <span class="text-xs text-[var(--text-secondary)] mb-1"
                    >target 90%</span
                >
            </div>
            <div
                v-else
                class="h-8 w-24 bg-[var(--bg-tertiary)] animate-pulse rounded"
            ></div>
        </Card>

        <!-- Avg Cycle Time -->
        <Card
            class="p-4 flex flex-col justify-between h-full bg-gradient-to-br from-[var(--bg-secondary)] to-[var(--bg-primary)] border-[var(--border-color)]"
        >
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h4
                        class="text-sm font-medium text-[var(--text-secondary)]"
                    >
                        Avg. Cycle Time
                    </h4>
                    <p class="text-xs text-[var(--text-muted)] mt-1">
                        Start to Finish
                    </p>
                </div>
                <div class="p-2 bg-purple-500/10 rounded-lg text-purple-500">
                    <Clock class="w-5 h-5" />
                </div>
            </div>
            <div class="flex items-end gap-2" v-if="!loading && stats">
                <span class="text-2xl font-bold text-[var(--text-primary)]">{{
                    stats.avg_cycle_time_days
                }}</span>
                <span
                    class="text-sm font-medium text-[var(--text-secondary)] mb-1"
                    >days</span
                >
            </div>
            <div
                v-else
                class="h-8 w-24 bg-[var(--bg-tertiary)] animate-pulse rounded"
            ></div>
        </Card>

        <!-- Due This Week -->
        <Card
            class="p-4 flex flex-col justify-between h-full bg-gradient-to-br from-[var(--bg-secondary)] to-[var(--bg-primary)] border-[var(--border-color)]"
        >
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h4
                        class="text-sm font-medium text-[var(--text-secondary)]"
                    >
                        Due This Week
                    </h4>
                    <p class="text-xs text-[var(--text-muted)] mt-1">
                        Upcoming Deadlines
                    </p>
                </div>
                <div class="p-2 bg-orange-500/10 rounded-lg text-orange-500">
                    <AlertCircle class="w-5 h-5" />
                </div>
            </div>
            <div class="flex items-end gap-2" v-if="!loading && stats">
                <span class="text-2xl font-bold text-[var(--text-primary)]">{{
                    stats.due_this_week
                }}</span>
                <span
                    class="text-sm font-medium text-[var(--text-secondary)] mb-1"
                    >tasks</span
                >
            </div>
            <div
                v-else
                class="h-8 w-24 bg-[var(--bg-tertiary)] animate-pulse rounded"
            ></div>
        </Card>

        <!-- Active Projects -->
        <Card
            class="p-4 flex flex-col justify-between h-full bg-gradient-to-br from-[var(--bg-secondary)] to-[var(--bg-primary)] border-[var(--border-color)]"
        >
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h4
                        class="text-sm font-medium text-[var(--text-secondary)]"
                    >
                        Active Projects
                    </h4>
                    <p class="text-xs text-[var(--text-muted)] mt-1">
                        Currently Running
                    </p>
                </div>
                <div class="p-2 bg-green-500/10 rounded-lg text-green-500">
                    <PlayCircle class="w-5 h-5" />
                </div>
            </div>
            <div class="flex items-end gap-2" v-if="!loading && stats">
                <span class="text-2xl font-bold text-[var(--text-primary)]">{{
                    stats.active_projects_count
                }}</span>
                <span
                    class="text-sm font-medium text-[var(--text-secondary)] mb-1"
                    >projects</span
                >
            </div>
            <div
                v-else
                class="h-8 w-24 bg-[var(--bg-tertiary)] animate-pulse rounded"
            ></div>
        </Card>
    </div>

    <!-- Core Business Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <!-- Operator Efficiency -->
        <Card
            class="p-5 flex flex-col justify-between h-full bg-gradient-to-br from-[var(--surface-secondary)] to-[var(--surface-primary)] border-[var(--border-color)] relative overflow-hidden group"
        >
            <div
                class="absolute top-0 right-0 p-8 -mr-4 -mt-4 opacity-[0.03] group-hover:opacity-[0.05] transition-opacity"
            >
                <BarChart3 class="w-24 h-24" />
            </div>
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h4
                        class="text-sm font-semibold text-[var(--text-primary)] flex items-center gap-2"
                    >
                        <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                        Operator Efficiency
                    </h4>
                    <p class="text-xs text-[var(--text-secondary)] mt-1">
                        Actual vs Estimated Output
                    </p>
                </div>
                <div class="p-2.5 bg-indigo-500/10 rounded-xl text-indigo-500">
                    <Zap class="w-5 h-5" />
                </div>
            </div>
            <div
                class="flex items-end justify-between"
                v-if="!loading && stats"
            >
                <div class="flex items-baseline gap-1">
                    <span class="text-3xl font-bold text-[var(--text-primary)]">
                        {{ stats.operator_efficiency }}%
                    </span>
                    <span
                        class="text-xs font-medium"
                        :class="
                            stats.operator_efficiency >= 100
                                ? 'text-green-500'
                                : 'text-orange-500'
                        "
                    >
                        {{
                            stats.operator_efficiency >= 100
                                ? "Above Target"
                                : "Below Target"
                        }}
                    </span>
                </div>
            </div>
            <div
                v-else
                class="h-10 w-32 bg-[var(--bg-tertiary)] animate-pulse rounded-lg"
            ></div>
        </Card>

        <!-- Project Burn Rate -->
        <Card
            class="p-5 flex flex-col justify-between h-full bg-gradient-to-br from-[var(--surface-secondary)] to-[var(--surface-primary)] border-[var(--border-color)] relative overflow-hidden group"
        >
            <div
                class="absolute top-0 right-0 p-8 -mr-4 -mt-4 opacity-[0.03] group-hover:opacity-[0.05] transition-opacity"
            >
                <Activity class="w-24 h-24" />
            </div>
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h4
                        class="text-sm font-semibold text-[var(--text-primary)] flex items-center gap-2"
                    >
                        <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                        Project Burn Rate
                    </h4>
                    <p class="text-xs text-[var(--text-secondary)] mt-1">
                        Effort consumption health
                    </p>
                </div>
                <div class="p-2.5 bg-rose-500/10 rounded-xl text-rose-500">
                    <TrendingUp class="w-5 h-5" />
                </div>
            </div>
            <div class="flex flex-col gap-2" v-if="!loading && stats">
                <div class="flex items-baseline justify-between">
                    <span class="text-3xl font-bold text-[var(--text-primary)]">
                        {{ stats.project_burn_rate }}%
                    </span>
                    <span
                        class="text-xs font-medium text-[var(--text-secondary)]"
                    >
                        Capacity used
                    </span>
                </div>
                <div
                    class="w-full h-1.5 bg-[var(--bg-tertiary)] rounded-full overflow-hidden"
                >
                    <div
                        class="h-full transition-all duration-500"
                        :class="
                            stats.project_burn_rate > 90
                                ? 'bg-rose-500'
                                : stats.project_burn_rate > 70
                                  ? 'bg-orange-500'
                                  : 'bg-emerald-500'
                        "
                        :style="{
                            width: `${Math.min(stats.project_burn_rate, 100)}%`,
                        }"
                    ></div>
                </div>
            </div>
            <div
                v-else
                class="h-12 w-full bg-[var(--bg-tertiary)] animate-pulse rounded-lg"
            ></div>
        </Card>

        <!-- PM Approval Rate -->
        <Card
            class="p-5 flex flex-col justify-between h-full bg-gradient-to-br from-[var(--surface-secondary)] to-[var(--surface-primary)] border-[var(--border-color)] relative overflow-hidden group"
        >
            <div
                class="absolute top-0 right-0 p-8 -mr-4 -mt-4 opacity-[0.03] group-hover:opacity-[0.05] transition-opacity"
            >
                <CheckCircle class="w-24 h-24" />
            </div>
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h4
                        class="text-sm font-semibold text-[var(--text-primary)] flex items-center gap-2"
                    >
                        <span
                            class="w-2 h-2 rounded-full bg-emerald-500"
                        ></span>
                        First-Submission Pass Rate
                    </h4>
                    <p class="text-xs text-[var(--text-secondary)] mt-1">
                        PM & QA Quality Standard
                    </p>
                </div>
                <div
                    class="p-2.5 bg-emerald-500/10 rounded-xl text-emerald-500"
                >
                    <ShieldCheck class="w-5 h-5" />
                </div>
            </div>
            <div
                class="flex items-end justify-between"
                v-if="!loading && stats"
            >
                <div class="flex items-baseline gap-1">
                    <span class="text-3xl font-bold text-[var(--text-primary)]">
                        {{ stats.pm_pass_rate }}%
                    </span>
                    <span class="text-xs font-medium text-emerald-500">
                        Accuracy
                    </span>
                </div>
            </div>
            <div
                v-else
                class="h-10 w-32 bg-[var(--bg-tertiary)] animate-pulse rounded-lg"
            ></div>
        </Card>
    </div>
</template>
