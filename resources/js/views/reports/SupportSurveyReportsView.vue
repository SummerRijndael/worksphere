<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import api from "@/lib/api";
import { Badge, Button, Card, Input } from "@/components/ui";
import {
    MessageSquareHeart,
    RefreshCw,
    SmilePlus,
    ThumbsUp,
    Gauge,
    FilterX,
    UserRound,
} from "lucide-vue-next";

interface SupportAgentOption {
    id: string;
    name: string;
    email?: string | null;
}

interface SurveyTotals {
    responses: number;
    csat: {
        responses: number;
        average_score: number | null;
        positive_count: number;
        positive_rate: number | null;
    };
    nps: {
        responses: number;
        promoters: number;
        passives: number;
        detractors: number;
        score: number | null;
    };
}

interface SurveyMetricsByAgent {
    agent: {
        id: string | null;
        name: string;
        email: string | null;
    };
    responses: number;
    csat: {
        responses: number;
        average_score: number | null;
        positive_count: number;
        positive_rate: number | null;
    };
    nps: {
        responses: number;
        promoters: number;
        passives: number;
        detractors: number;
        score: number | null;
    };
}

interface SurveyMetricsPayload {
    totals: SurveyTotals;
    by_agent: SurveyMetricsByAgent[];
}

const loading = ref(false);
const loadError = ref("");
const agents = ref<SupportAgentOption[]>([]);
const metrics = ref<SurveyMetricsPayload | null>(null);

const filters = ref({
    survey_type: "",
    agent_public_id: "",
    from: "",
    to: "",
});

const hasFilters = computed(
    () =>
        filters.value.survey_type !== "" ||
        filters.value.agent_public_id !== "" ||
        filters.value.from !== "" ||
        filters.value.to !== "",
);

const rowsByAgent = computed(() => {
    return [...(metrics.value?.by_agent ?? [])].sort((a, b) => b.responses - a.responses);
});

const totals = computed<SurveyTotals>(() => {
    return (
        metrics.value?.totals ?? {
            responses: 0,
            csat: {
                responses: 0,
                average_score: null,
                positive_count: 0,
                positive_rate: null,
            },
            nps: {
                responses: 0,
                promoters: 0,
                passives: 0,
                detractors: 0,
                score: null,
            },
        }
    );
});

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

function formatScore(value: number | null, digits = 2): string {
    if (value === null || Number.isNaN(value)) {
        return "--";
    }

    return Number(value).toFixed(digits);
}

function clearFilters(): void {
    filters.value = {
        survey_type: "",
        agent_public_id: "",
        from: "",
        to: "",
    };
    void loadMetrics();
}

async function loadAgents(): Promise<void> {
    try {
        const response = await api.get("/api/support/chats/agents");
        const items = Array.isArray(response.data?.data) ? response.data.data : [];

        agents.value = items
            .map((agent: any) => ({
                id: String(agent.id),
                name: String(agent.name ?? "Unknown"),
                email: agent.email ? String(agent.email) : null,
            }))
            .sort((a: SupportAgentOption, b: SupportAgentOption) => a.name.localeCompare(b.name));
    } catch (error) {
        console.error("[SupportSurveyReports] Failed to load agents", error);
    }
}

async function loadMetrics(): Promise<void> {
    loading.value = true;
    loadError.value = "";

    try {
        const params: Record<string, string> = {};

        if (filters.value.survey_type) {
            params.survey_type = filters.value.survey_type;
        }
        if (filters.value.agent_public_id) {
            params.agent_public_id = filters.value.agent_public_id;
        }
        if (filters.value.from) {
            params.from = filters.value.from;
        }
        if (filters.value.to) {
            params.to = filters.value.to;
        }

        const response = await api.get("/api/support/chats/surveys/metrics", { params });
        metrics.value = (response.data?.data ?? null) as SurveyMetricsPayload | null;
    } catch (error) {
        console.error("[SupportSurveyReports] Failed to load metrics", error);
        loadError.value = "Unable to load survey metrics. Please try again.";
    } finally {
        loading.value = false;
    }
}

watch(
    filters,
    () => {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        debounceTimer = setTimeout(() => {
            void loadMetrics();
        }, 300);
    },
    { deep: true },
);

onMounted(async () => {
    await loadAgents();
    await loadMetrics();
});

onBeforeUnmount(() => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
});
</script>

<template>
    <div class="min-h-screen bg-[var(--surface-base)] pb-10">
        <div class="border-b border-[var(--border-default)] bg-[var(--surface-elevated)] shadow-sm">
            <div class="px-6 py-7">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-[var(--text-primary)]">Survey Reports</h1>
                        <p class="mt-1 text-sm text-[var(--text-secondary)]">
                            CSAT and NPS performance from live support conversations.
                        </p>
                    </div>
                    <Button variant="outline" :disabled="loading" @click="loadMetrics">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': loading }" />
                        Refresh
                    </Button>
                </div>
            </div>
        </div>

        <div class="space-y-6 px-6 py-6">
            <Card class="border border-[var(--border-default)]" padding="lg">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">
                            Survey Type
                        </label>
                        <select
                            v-model="filters.survey_type"
                            class="h-10 w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] px-3 text-sm text-[var(--text-primary)] focus:border-[var(--interactive-primary)] focus:outline-none"
                        >
                            <option value="">All surveys</option>
                            <option value="csat">CSAT</option>
                            <option value="nps">NPS</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">
                            Agent
                        </label>
                        <select
                            v-model="filters.agent_public_id"
                            class="h-10 w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] px-3 text-sm text-[var(--text-primary)] focus:border-[var(--interactive-primary)] focus:outline-none"
                        >
                            <option value="">All agents</option>
                            <option v-for="agent in agents" :key="agent.id" :value="agent.id">
                                {{ agent.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">
                            From
                        </label>
                        <Input v-model="filters.from" type="date" class="h-10" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">
                            To
                        </label>
                        <Input v-model="filters.to" type="date" class="h-10" />
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-between">
                    <p class="text-xs text-[var(--text-muted)]">Filters update metrics automatically.</p>
                    <Button v-if="hasFilters" variant="ghost" size="sm" @click="clearFilters">
                        <FilterX class="mr-2 h-4 w-4" />
                        Clear filters
                    </Button>
                </div>
            </Card>

            <div
                v-if="loadError"
                class="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-500 dark:text-red-300"
            >
                {{ loadError }}
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <Card padding="lg" class="border border-[var(--border-default)]">
                    <div class="mb-2 flex items-center justify-between">
                        <MessageSquareHeart class="h-5 w-5 text-[var(--interactive-primary)]" />
                        <Badge variant="info" size="sm">All</Badge>
                    </div>
                    <p class="text-xs uppercase tracking-wide text-[var(--text-secondary)]">Responses</p>
                    <p class="mt-2 text-3xl font-semibold text-[var(--text-primary)]">{{ totals.responses }}</p>
                </Card>

                <Card padding="lg" class="border border-[var(--border-default)]">
                    <div class="mb-2 flex items-center justify-between">
                        <SmilePlus class="h-5 w-5 text-emerald-500" />
                        <Badge variant="success" size="sm">CSAT</Badge>
                    </div>
                    <p class="text-xs uppercase tracking-wide text-[var(--text-secondary)]">Average Score</p>
                    <p class="mt-2 text-3xl font-semibold text-[var(--text-primary)]">{{ formatScore(totals.csat.average_score) }}</p>
                </Card>

                <Card padding="lg" class="border border-[var(--border-default)]">
                    <div class="mb-2 flex items-center justify-between">
                        <ThumbsUp class="h-5 w-5 text-blue-500" />
                        <Badge variant="primary" size="sm">CSAT</Badge>
                    </div>
                    <p class="text-xs uppercase tracking-wide text-[var(--text-secondary)]">Positive Rate</p>
                    <p class="mt-2 text-3xl font-semibold text-[var(--text-primary)]">
                        {{ totals.csat.positive_rate !== null ? `${formatScore(totals.csat.positive_rate)}%` : "--" }}
                    </p>
                </Card>

                <Card padding="lg" class="border border-[var(--border-default)]">
                    <div class="mb-2 flex items-center justify-between">
                        <Gauge class="h-5 w-5 text-amber-500" />
                        <Badge variant="warning" size="sm">NPS</Badge>
                    </div>
                    <p class="text-xs uppercase tracking-wide text-[var(--text-secondary)]">NPS Score</p>
                    <p class="mt-2 text-3xl font-semibold text-[var(--text-primary)]">{{ formatScore(totals.nps.score) }}</p>
                </Card>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Card padding="lg" class="border border-[var(--border-default)]">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-[var(--text-primary)]">CSAT Breakdown</h2>
                        <Badge variant="neutral" size="sm">{{ totals.csat.responses }} responses</Badge>
                    </div>
                    <div class="space-y-2 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-[var(--text-secondary)]">Positive (4-5)</span>
                            <span class="font-medium text-[var(--text-primary)]">{{ totals.csat.positive_count }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-[var(--text-secondary)]">Average</span>
                            <span class="font-medium text-[var(--text-primary)]">{{ formatScore(totals.csat.average_score) }}</span>
                        </div>
                    </div>
                </Card>

                <Card padding="lg" class="border border-[var(--border-default)]">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-[var(--text-primary)]">NPS Breakdown</h2>
                        <Badge variant="neutral" size="sm">{{ totals.nps.responses }} responses</Badge>
                    </div>
                    <div class="grid grid-cols-3 gap-3 text-sm">
                        <div class="rounded-lg border border-[var(--border-default)] p-3">
                            <p class="text-xs uppercase tracking-wide text-[var(--text-secondary)]">Promoters</p>
                            <p class="mt-1 text-lg font-semibold text-emerald-500">{{ totals.nps.promoters }}</p>
                        </div>
                        <div class="rounded-lg border border-[var(--border-default)] p-3">
                            <p class="text-xs uppercase tracking-wide text-[var(--text-secondary)]">Passives</p>
                            <p class="mt-1 text-lg font-semibold text-amber-500">{{ totals.nps.passives }}</p>
                        </div>
                        <div class="rounded-lg border border-[var(--border-default)] p-3">
                            <p class="text-xs uppercase tracking-wide text-[var(--text-secondary)]">Detractors</p>
                            <p class="mt-1 text-lg font-semibold text-red-500">{{ totals.nps.detractors }}</p>
                        </div>
                    </div>
                </Card>
            </div>

            <Card padding="lg" class="border border-[var(--border-default)]">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-[var(--text-primary)]">By Agent</h2>
                    <Badge variant="neutral" size="sm">{{ rowsByAgent.length }} agents</Badge>
                </div>

                <div v-if="rowsByAgent.length === 0" class="rounded-lg border border-dashed border-[var(--border-default)] p-8 text-center">
                    <UserRound class="mx-auto h-8 w-8 text-[var(--text-muted)]" />
                    <p class="mt-2 text-sm text-[var(--text-secondary)]">No survey responses found for the selected filters.</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead>
                            <tr class="border-b border-[var(--border-default)] text-xs uppercase tracking-wide text-[var(--text-secondary)]">
                                <th class="pb-3 font-medium">Agent</th>
                                <th class="pb-3 font-medium">Responses</th>
                                <th class="pb-3 font-medium">CSAT Avg</th>
                                <th class="pb-3 font-medium">CSAT Positive</th>
                                <th class="pb-3 font-medium">NPS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in rowsByAgent"
                                :key="`${row.agent.id ?? 'none'}-${row.responses}`"
                                class="border-b border-[var(--border-subtle)] last:border-b-0"
                            >
                                <td class="py-3">
                                    <div class="font-medium text-[var(--text-primary)]">{{ row.agent.name }}</div>
                                    <div class="text-xs text-[var(--text-muted)]">{{ row.agent.email || "No email" }}</div>
                                </td>
                                <td class="py-3 font-medium text-[var(--text-primary)]">{{ row.responses }}</td>
                                <td class="py-3 text-[var(--text-primary)]">{{ formatScore(row.csat.average_score) }}</td>
                                <td class="py-3 text-[var(--text-primary)]">
                                    {{ row.csat.positive_rate !== null ? `${formatScore(row.csat.positive_rate)}%` : "--" }}
                                </td>
                                <td class="py-3 text-[var(--text-primary)]">{{ formatScore(row.nps.score) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>
    </div>
</template>
