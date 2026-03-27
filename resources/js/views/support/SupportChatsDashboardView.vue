<script setup>
import { onMounted, onBeforeUnmount, ref } from "vue";
import { Card, Button, Badge } from "@/components/ui";
import { Loader2, MessageSquare, Users, AlertCircle, Inbox, Clock, Bot, User as UserIcon } from "lucide-vue-next";
import DashboardLineChart from "@/components/charts/DashboardLineChart.vue";
import api from "@/lib/api";
import { formatDistanceToNow } from "date-fns";
import { useRouter } from "vue-router";
import { setSupportRealtimeToken } from "@/echo";
import { useToast } from "@/composables/useToast";
import { getSupportStatusColor, getSupportStatusLabel } from "@/composables/usePresence";

const router = useRouter();
const toast = useToast();

const loading = ref(true);
const metricsLoading = ref(false);
const availability = ref({
    available: false,
    available_agents: 0,
    agents: [],
    message: "Checking support availability...",
});
const waitingQueue = ref([]);
const aiQueue = ref([]);
const assignedQueue = ref([]);
const metrics = ref({
    average_wait_time_seconds: 0,
    ai_resolution_rate: 0,
    resolved_today: 0,
    ai_resolved_today: 0,
    today_trend: {
        labels: [],
        incoming_chats: [],
        resolved_chats: [],
        peak_hour_label: null,
        peak_hour_count: 0,
    },
});
const uiTimers = ref({
    last_response_warn_minutes: 5,
    last_response_alert_minutes: 15,
});
const liveClockNow = ref(Date.now());

const loadError = ref("");
let refreshInterval = null;
let supportInboxChannel = null;
let presenceChannel = null;
let liveDurationInterval = null;
let realtimeRefreshTimeout = null;

async function loadMetrics() {
    metricsLoading.value = true;
    try {
        const response = await api.get("/api/support/chats/metrics");
        metrics.value = response.data?.data ?? metrics.value;
    } catch (error) {
        console.error("Failed to load metrics:", error);
    } finally {
        metricsLoading.value = false;
    }
}

async function loadDashboard(showLoading = true) {
    if (showLoading) loading.value = true;
    loadError.value = "";

    try {
        const [availabilityResponse, waitingRes, aiRes, assignedRes] = await Promise.allSettled([
            api.get("/api/support/chats/availability"),
            api.get("/api/support/chats/inbox", { params: { scope: "waiting", per_page: 50 } }),
            api.get("/api/support/chats/inbox", { params: { scope: "ai", per_page: 50 } }),
            api.get("/api/support/chats/inbox", { params: { scope: "assigned_all", per_page: 50 } }),
        ]);

        if (availabilityResponse.status === "fulfilled") {
            availability.value = availabilityResponse.value.data?.data ?? availability.value;
        }
        if (waitingRes.status === "fulfilled") {
            waitingQueue.value = sortWaitingQueue(waitingRes.value.data?.data ?? []);
        }
        if (aiRes.status === "fulfilled") {
            aiQueue.value = sortAiQueue(aiRes.value.data?.data ?? []);
        }
        if (assignedRes.status === "fulfilled") {
            assignedQueue.value = sortAssignedQueue(assignedRes.value.data?.data ?? []);
        }

        // Update realtime token if provided in metadata
        const meta = waitingRes.status === "fulfilled" ? (waitingRes.value.data?.meta || {}) : {};
        if (meta.realtime?.token) {
            setSupportRealtimeToken(meta.realtime.token, 'agent');
            subscribeToRealtime();
        }
        if (meta.ui_timers) {
            uiTimers.value = meta.ui_timers;
        }

        loadMetrics();

        const failedRequests = [availabilityResponse, waitingRes, aiRes, assignedRes]
            .filter((result) => result.status === "rejected");

        if (failedRequests.length > 0) {
            console.warn("[SupportDashboard] Partial dashboard refresh failure.", failedRequests);

            if (
                availabilityResponse.status === "rejected" &&
                waitingRes.status === "rejected" &&
                aiRes.status === "rejected" &&
                assignedRes.status === "rejected"
            ) {
                throw waitingRes.reason || availabilityResponse.reason || aiRes.reason || assignedRes.reason;
            }
        }
    } catch (error) {
        console.error("Failed to load support chat dashboard:", error);
        loadError.value = "Unable to load dashboard data. Please refresh.";
    } finally {
        if (showLoading) loading.value = false;
    }
}

function subscribeToRealtime() {
    const echo = window.Echo;
    if (!echo) return;

    const scheduleRealtimeRefresh = () => {
        if (realtimeRefreshTimeout) {
            clearTimeout(realtimeRefreshTimeout);
        }

        realtimeRefreshTimeout = setTimeout(() => {
            loadDashboard(false);
        }, 250);
    };

    if (!supportInboxChannel) {
        supportInboxChannel = echo.private('support.agent.inbox')
            .listen('.SupportConversationChanged', (event) => {
                console.debug('[SupportDashboard] Support inbox update received:', event);
                scheduleRealtimeRefresh();
            });
    }

    if (!presenceChannel) {
        presenceChannel = echo.join('online-users')
            .joining((user) => {
                console.debug('[SupportDashboard] Presence joining:', user?.public_id);
                scheduleRealtimeRefresh();
            })
            .leaving((user) => {
                console.debug('[SupportDashboard] Presence leaving:', user?.public_id);
                scheduleRealtimeRefresh();
            })
            .listen('.presence.changed', (event) => {
                console.debug('[SupportDashboard] Presence update received:', event);
                scheduleRealtimeRefresh();
            });
    }
}

function unsubscribeFromRealtime() {
    if (realtimeRefreshTimeout) {
        clearTimeout(realtimeRefreshTimeout);
        realtimeRefreshTimeout = null;
    }

    if (!window.Echo) return;

    if (supportInboxChannel) {
        window.Echo.leave('support.agent.inbox');
        supportInboxChannel = null;
    }

    if (presenceChannel) {
        window.Echo.leave('online-users');
        presenceChannel = null;
    }
}

onMounted(() => {
    loadDashboard();
    // Fallback polling if realtime fails or for background consistency
    refreshInterval = setInterval(() => loadDashboard(false), 60000);
    liveDurationInterval = setInterval(() => {
        liveClockNow.value = Date.now();
    }, 1000);
});

onBeforeUnmount(() => {
    if (refreshInterval) clearInterval(refreshInterval);
    if (liveDurationInterval) clearInterval(liveDurationInterval);
    unsubscribeFromRealtime();
});

function formatWaitTime(dateString) {
    if (!dateString) return "Unknown";
    return formatDistanceToNow(new Date(dateString), { addSuffix: true });
}

function parseTimestamp(value) {
    if (typeof value !== "string" || value.trim() === "") return null;
    const timestamp = new Date(value).getTime();
    return Number.isNaN(timestamp) ? null : timestamp;
}

function formatLiveDurationFrom(value) {
    const timestamp = parseTimestamp(value);
    if (timestamp === null) return "00:00";

    const diff = Math.max(0, Math.floor((liveClockNow.value - timestamp) / 1000));
    const hours = Math.floor(diff / 3600);
    const minutes = Math.floor((diff % 3600) / 60);
    const seconds = diff % 60;

    if (hours > 0) {
        return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
    }

    return `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
}

function customerDisplayName(chat) {
    return chat?.guest_name || chat?.requester?.name || "Anonymous";
}

function conversationSubject(chat) {
    return chat?.subject || "No subject";
}

function assigneeName(chat) {
    return chat?.assignee?.name || "Unassigned";
}

function queuePosition(chat) {
    const value = Number(chat?.queue?.position ?? chat?.queue_position ?? 0);
    return Number.isFinite(value) && value > 0 ? Math.floor(value) : null;
}

function compareTimestampAsc(a, b) {
    const aTime = parseTimestamp(a) ?? Number.MAX_SAFE_INTEGER;
    const bTime = parseTimestamp(b) ?? Number.MAX_SAFE_INTEGER;
    return aTime - bTime;
}

function sortWaitingQueue(items) {
    return [...items].sort((a, b) => {
        const aPosition = queuePosition(a) ?? Number.MAX_SAFE_INTEGER;
        const bPosition = queuePosition(b) ?? Number.MAX_SAFE_INTEGER;
        if (aPosition !== bPosition) return aPosition - bPosition;

        return compareTimestampAsc(waitingSinceAt(a), waitingSinceAt(b));
    });
}

function sortAiQueue(items) {
    return [...items].sort((a, b) => compareTimestampAsc(aiAssistingSinceAt(a), aiAssistingSinceAt(b)));
}

function sortAssignedQueue(items) {
    return [...items].sort((a, b) => compareTimestampAsc(assignedSinceAt(a), assignedSinceAt(b)));
}

function queuePositionLabel(chat) {
    const position = queuePosition(chat);
    if (!position) return "In queue";
    return position === 1 ? "Next in queue" : `Queue #${position}`;
}

function waitingSinceAt(chat) {
    return chat?.timers?.waiting_since_at || chat?.metadata?.waiting_for_agent_since || chat?.created_at || null;
}

function aiAssistingSinceAt(chat) {
    return chat?.timers?.ai_assisting_since_at || chat?.metadata?.ai_assisting_since || chat?.created_at || null;
}

function assignedSinceAt(chat) {
    return chat?.timers?.assigned_since_at || chat?.assigned_at || chat?.first_response_at || chat?.created_at || null;
}

function getSLAStatus(chat) {
    if (!chat.last_message_at) return 'normal';
    
    // Waiting human state is most critical
    if (chat.status !== 'waiting_human') return 'normal';

    const lastMsg = new Date(chat.last_message_at);
    const now = new Date();
    const diffMinutes = (now - lastMsg) / (1000 * 60);

    if (diffMinutes >= uiTimers.value.last_response_alert_minutes) return 'alert';
    if (diffMinutes >= uiTimers.value.last_response_warn_minutes) return 'warn';
    
    return 'normal';
}

function formatAverageWait(seconds) {
    if (!seconds) return "0m";
    if (seconds < 60) return `${Math.round(seconds)}s`;
    return `${Math.round(seconds / 60)}m`;
}

function formatHandleTime(seconds) {
    if (seconds === null || typeof seconds === "undefined") return "—";
    if (seconds < 60) return `${Math.round(seconds)}s`;

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainingSeconds = seconds % 60;

    if (hours > 0) {
        return `${hours}h ${String(minutes).padStart(2, "0")}m`;
    }

    if (remainingSeconds === 0) {
        return `${minutes}m`;
    }

    return `${minutes}m ${remainingSeconds}s`;
}

function getLongestChatToneClasses(seconds) {
    if (seconds === null || typeof seconds === "undefined") {
        return "text-[var(--text-primary)]";
    }

    if (seconds >= 2400) {
        return "animate-pulse text-red-600 dark:text-red-400";
    }

    if (seconds >= 1800) {
        return "text-red-600 dark:text-red-400";
    }

    if (seconds >= 1200) {
        return "text-orange-600 dark:text-orange-400";
    }

    if (seconds >= 600) {
        return "text-amber-600 dark:text-amber-400";
    }

    return "text-[var(--text-primary)]";
}

function getLiveLongestActiveChatSeconds(agent) {
    const workingSinceAt = agent?.working_since_at;
    if (!workingSinceAt) {
        return agent?.longest_active_chat_seconds ?? null;
    }

    const start = new Date(workingSinceAt).getTime();
    if (Number.isNaN(start)) {
        return agent?.longest_active_chat_seconds ?? null;
    }

    const diff = Math.floor((liveClockNow.value - start) / 1000);
    return diff >= 0 ? diff : 0;
}

function formatActiveChatsLoad(agent) {
    const current = Number(agent?.active_chats || 0);
    const max = Number(agent?.agent_capacity || 0);

    if (max > 0) {
        return `${current}/${max}`;
    }

    return `${current}`;
}

function formatSurveyMetric(agent) {
    const responses = Number(agent?.survey_responses_today || 0);
    const average = agent?.survey_csat_average_today;

    if (average !== null && typeof average !== "undefined") {
        return `${Number(average).toFixed(1)}/5`;
    }

    if (responses > 0) {
        return `${responses} resp`;
    }

    return "—";
}

function formatSurveySubtext(agent) {
    const responses = Number(agent?.survey_responses_today || 0);
    if (responses <= 0) return null;
    return `${responses} ${responses === 1 ? "response" : "responses"}`;
}

function shouldShowStatusDuration(status) {
    return ["available", "working", "break", "lunch", "acw", "bio"].includes(String(status || "").toLowerCase());
}

function formatStatusDuration(statusAt, status) {
    if (!statusAt || !shouldShowStatusDuration(status)) return null;

    const start = new Date(statusAt).getTime();
    if (Number.isNaN(start)) return null;

    const diff = Math.floor((liveClockNow.value - start) / 1000);
    if (diff < 0) return "00:00";

    const hours = Math.floor(diff / 3600);
    const minutes = Math.floor((diff % 3600) / 60);
    const seconds = diff % 60;

    if (hours > 0) {
        return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
    }

    return `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
}

function getAgentDisplayStatus(agent) {
    const baseStatus = String(agent?.support_status || "unavailable").toLowerCase();

    if (baseStatus === "available" && Number(agent?.active_chats || 0) > 0) {
        return "working";
    }

    return baseStatus;
}

function getAgentDisplayStatusAt(agent) {
    const displayStatus = getAgentDisplayStatus(agent);

    if (displayStatus === "working") {
        return agent?.working_since_at || agent?.support_status_at || null;
    }

    return agent?.support_status_at || null;
}

function conversationIdFor(chat) {
    if (!chat || typeof chat !== "object") {
        return "";
    }

    if (typeof chat.id === "string" && chat.id.trim() !== "") {
        return chat.id.trim();
    }

    if (typeof chat.public_id === "string" && chat.public_id.trim() !== "") {
        return chat.public_id.trim();
    }

    return "";
}

function openQueueChat(chat, preferredScope = "all") {
    const conversationId = conversationIdFor(chat);
    if (!conversationId) {
        toast.error("This conversation is missing an ID and cannot be opened yet.");
        return;
    }

    router.push({
        name: "support.inbox",
        query: {
            conversation: conversationId,
            scope: preferredScope,
        },
    });
}

function getStatusColor(status) {
    switch(status) {
        case 'waiting_human': return 'warning';
        case 'bot_active': return 'info';
        case 'open': return 'primary';
        case 'resolved': return 'success';
        case 'closed': return 'secondary';
        default: return 'secondary';
    }
}

function getStatusLabel(status) {
    switch(status) {
        case 'waiting_human': return 'Waiting for Agent';
        case 'bot_active': return 'AI Assistant';
        case 'open': return 'Active';
        case 'resolved': return 'Resolved';
        case 'closed': return 'Closed';
        default: return status;
    }
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-[var(--text-primary)]">Live Chat Dashboard</h1>
                <p class="text-[var(--text-secondary)]">Queue health and active conversations overview.</p>
            </div>
            <Button variant="outline" @click="loadDashboard" :disabled="loading">
                <Loader2 v-if="loading" class="mr-2 h-4 w-4 animate-spin" />
                Refresh
            </Button>
        </div>

        <div v-if="loadError" class="rounded-lg border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-600 dark:text-red-300">
            {{ loadError }}
        </div>

        <!-- Top Metrics -->
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <Card class="p-3 space-y-1 border-l-4 border-l-[var(--color-primary-500)]">
                <div class="flex items-center justify-between">
                    <Users class="h-5 w-5 text-[var(--interactive-primary)]" />
                    <Badge :variant="availability.available ? 'success' : 'warning'" size="sm">
                        {{ availability.available ? "Online" : "Offline" }}
                    </Badge>
                </div>
                <p class="text-sm text-[var(--text-secondary)]">Available Agents</p>
                <p class="text-2xl font-semibold text-[var(--text-primary)]">{{ availability.available_agents }}</p>
            </Card>

            <Card class="p-3 space-y-1 border-l-4 border-l-[var(--color-warning-500)]">
                <div class="flex items-center justify-between">
                    <Clock class="h-5 w-5 text-[var(--color-warning-500)]" />
                    <Badge variant="warning" size="sm">Last 24h</Badge>
                </div>
                <p class="text-sm text-[var(--text-secondary)]">Avg. Wait Time</p>
                <div class="flex items-baseline gap-2">
                    <p class="text-2xl font-semibold text-[var(--text-primary)]">
                        {{ formatAverageWait(metrics.average_wait_time_seconds) }}
                    </p>
                    <span v-if="metricsLoading" class="animate-pulse text-xs text-[var(--text-muted)]">Updating...</span>
                </div>
            </Card>

            <Card class="p-3 space-y-1 border-l-4 border-l-[var(--color-info-500)]">
                <div class="flex items-center justify-between">
                    <Bot class="h-5 w-5 text-[var(--color-info-500)]" />
                    <Badge variant="info" size="sm">Automation</Badge>
                </div>
                <p class="text-sm text-[var(--text-secondary)]">AI Resolution Rate</p>
                <div class="flex items-baseline gap-2">
                    <p class="text-2xl font-semibold text-[var(--text-primary)]">{{ metrics.ai_resolution_rate }}%</p>
                    <span v-if="metricsLoading" class="animate-pulse text-xs text-[var(--text-muted)]">Updating...</span>
                </div>
            </Card>

            <Card class="p-3 space-y-1 border-l-4 border-l-[var(--color-success-500)]">
                <div class="flex items-center justify-between">
                    <Inbox class="h-5 w-5 text-[var(--color-success-500)]" />
                    <Badge variant="success" size="sm">Resolved</Badge>
                </div>
                <p class="text-sm text-[var(--text-secondary)]">Resolved Today</p>
                <p class="text-2xl font-semibold text-[var(--text-primary)]">{{ metrics.resolved_today }}</p>
            </Card>
        </div>

        <div class="space-y-6">
            <!-- Queues Grid -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Waiting Queue -->
                <Card class="flex h-[600px] flex-col overflow-hidden border-[var(--border-default)] bg-[var(--surface-primary)]">
                    <div class="flex items-center justify-between border-b border-[var(--border-muted)] px-2.5 py-2">
                        <div class="flex items-center gap-2">
                            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-amber-500/15">
                                <AlertCircle class="h-4 w-4 text-amber-600 dark:text-amber-300" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-[var(--text-primary)]">Waiting for Agent</h3>
                                <p class="text-[11px] text-[var(--text-secondary)]">Queued chats awaiting human pickup</p>
                            </div>
                        </div>
                        <Badge variant="warning" size="sm">{{ waitingQueue.length }}</Badge>
                    </div>
                    <div class="flex-1 space-y-1 overflow-y-auto p-1.5">
                        <div v-if="waitingQueue.length === 0" class="flex flex-col items-center justify-center py-12 text-[var(--text-muted)]">
                            <Inbox class="h-8 w-8 mb-2 opacity-50" />
                            <p class="text-sm">No chats waiting.</p>
                        </div>
                        <div
                            v-for="(chat, index) in waitingQueue"
                            :key="conversationIdFor(chat) || `waiting-${index}`"
                            @click="openQueueChat(chat, 'waiting')"
                            class="group cursor-pointer rounded-lg border border-amber-500/20 bg-[var(--surface-primary)] p-2 shadow-[0_8px_20px_rgba(15,23,42,0.04)] transition-all hover:-translate-y-0.5 hover:border-amber-500/50"
                            :class="{
                                'ring-1 ring-red-500/40': getSLAStatus(chat) === 'alert',
                                'ring-1 ring-amber-500/40': getSLAStatus(chat) === 'warn'
                            }"
                        >
                            <div class="mb-2 flex items-start justify-between gap-2">
                                <p class="truncate pr-2 text-sm font-semibold text-[var(--text-primary)]">{{ customerDisplayName(chat) }}</p>
                                <div class="flex shrink-0 items-center gap-1">
                                    <Badge :variant="getStatusColor(chat.status)" size="sm">{{ getStatusLabel(chat.status) }}</Badge>
                                    <Badge variant="outline" size="sm">{{ queuePositionLabel(chat) }}</Badge>
                                </div>
                            </div>
                            <p class="mb-2 truncate text-xs text-[var(--text-secondary)]">{{ conversationSubject(chat) }}</p>
                            <div class="grid grid-cols-2 gap-1.5 text-[11px]">
                                <div class="rounded-md border border-[var(--border-subtle)] bg-[var(--surface-secondary)]/60 px-1.5 py-1">
                                    <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Waiting</p>
                                    <p class="font-mono text-[var(--text-primary)]">{{ formatLiveDurationFrom(waitingSinceAt(chat)) }}</p>
                                </div>
                                <div class="rounded-md border border-[var(--border-subtle)] bg-[var(--surface-secondary)]/60 px-1.5 py-1">
                                    <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Last Update</p>
                                    <p class="truncate text-[var(--text-primary)]">{{ formatWaitTime(chat.last_message_at || chat.updated_at) }}</p>
                                </div>
                            </div>
                            <p
                                v-if="getSLAStatus(chat) !== 'normal'"
                                class="mt-2 text-[11px] font-medium"
                                :class="getSLAStatus(chat) === 'alert' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'"
                            >
                                {{ getSLAStatus(chat) === 'alert' ? 'SLA breach risk: urgent follow-up needed.' : 'SLA warning: nearing response threshold.' }}
                            </p>
                        </div>
                    </div>
                </Card>

                <!-- AI Handled Queue -->
                <Card class="flex h-[600px] flex-col overflow-hidden border-[var(--border-default)] bg-[var(--surface-primary)]">
                    <div class="flex items-center justify-between border-b border-[var(--border-muted)] px-2.5 py-2">
                        <div class="flex items-center gap-2">
                            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-sky-500/15">
                                <Bot class="h-4 w-4 text-sky-600 dark:text-sky-300" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-[var(--text-primary)]">Handled by AI</h3>
                                <p class="text-[11px] text-[var(--text-secondary)]">Chats currently guided by automation</p>
                            </div>
                        </div>
                        <Badge variant="info" size="sm">{{ aiQueue.length }}</Badge>
                    </div>
                    <div class="flex-1 space-y-1 overflow-y-auto p-1.5">
                        <div v-if="aiQueue.length === 0" class="flex flex-col items-center justify-center py-12 text-[var(--text-muted)]">
                            <Bot class="h-8 w-8 mb-2 opacity-50" />
                            <p class="text-sm">No active AI chats.</p>
                        </div>
                        <div
                            v-for="(chat, index) in aiQueue"
                            :key="conversationIdFor(chat) || `ai-${index}`"
                            @click="openQueueChat(chat, 'ai')"
                            class="group cursor-pointer rounded-lg border border-sky-500/20 bg-[var(--surface-primary)] p-2 shadow-[0_8px_20px_rgba(15,23,42,0.04)] transition-all hover:-translate-y-0.5 hover:border-sky-500/45"
                        >
                            <div class="mb-2 flex items-start justify-between gap-2">
                                <p class="truncate pr-2 text-sm font-semibold text-[var(--text-primary)]">{{ customerDisplayName(chat) }}</p>
                                <Badge variant="info" size="sm">Auto-pilot</Badge>
                            </div>
                            <p class="mb-2 truncate text-xs text-[var(--text-secondary)]">{{ conversationSubject(chat) }}</p>
                            <div class="grid grid-cols-2 gap-1.5 text-[11px]">
                                <div class="rounded-md border border-[var(--border-subtle)] bg-[var(--surface-secondary)]/60 px-1.5 py-1">
                                    <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">AI Assisting</p>
                                    <p class="font-mono text-[var(--text-primary)]">{{ formatLiveDurationFrom(aiAssistingSinceAt(chat)) }}</p>
                                </div>
                                <div class="rounded-md border border-[var(--border-subtle)] bg-[var(--surface-secondary)]/60 px-1.5 py-1">
                                    <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Last Update</p>
                                    <p class="truncate text-[var(--text-primary)]">{{ formatWaitTime(chat.last_message_at || chat.updated_at) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Assigned Queue -->
                <Card class="flex h-[600px] flex-col overflow-hidden border-[var(--border-default)] bg-[var(--surface-primary)]">
                    <div class="flex items-center justify-between border-b border-[var(--border-muted)] px-2.5 py-2">
                        <div class="flex items-center gap-2">
                            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-500/15">
                                <UserIcon class="h-4 w-4 text-emerald-600 dark:text-emerald-300" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-[var(--text-primary)]">Assigned Chats</h3>
                                <p class="text-[11px] text-[var(--text-secondary)]">Conversations currently owned by agents</p>
                            </div>
                        </div>
                        <Badge variant="success" size="sm">{{ assignedQueue.length }}</Badge>
                    </div>
                    <div class="flex-1 space-y-1 overflow-y-auto p-1.5">
                        <div v-if="assignedQueue.length === 0" class="flex flex-col items-center justify-center py-12 text-[var(--text-muted)]">
                            <UserIcon class="h-8 w-8 mb-2 opacity-50" />
                            <p class="text-sm">No assigned chats.</p>
                        </div>
                        <div
                            v-for="(chat, index) in assignedQueue"
                            :key="conversationIdFor(chat) || `assigned-${index}`"
                            @click="openQueueChat(chat, 'all')"
                            class="group cursor-pointer rounded-lg border border-emerald-500/20 bg-[var(--surface-primary)] p-2 shadow-[0_8px_20px_rgba(15,23,42,0.04)] transition-all hover:-translate-y-0.5 hover:border-emerald-500/45"
                        >
                            <div class="mb-2 flex items-start justify-between gap-2">
                                <p class="truncate pr-2 text-sm font-semibold text-[var(--text-primary)]">{{ customerDisplayName(chat) }}</p>
                                <div class="flex flex-col items-end gap-1">
                                    <Badge variant="success" size="sm">Assigned</Badge>
                                </div>
                            </div>
                            <p class="mb-2 truncate text-xs text-[var(--text-secondary)]">{{ conversationSubject(chat) }}</p>
                            <div class="grid grid-cols-2 gap-1.5 text-[11px]">
                                <div class="rounded-md border border-[var(--border-subtle)] bg-[var(--surface-secondary)]/60 px-1.5 py-1">
                                    <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Assigned To</p>
                                    <p class="truncate text-[var(--text-primary)]">{{ assigneeName(chat) }}</p>
                                </div>
                                <div class="rounded-md border border-[var(--border-subtle)] bg-[var(--surface-secondary)]/60 px-1.5 py-1">
                                    <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Assigned For</p>
                                    <p class="font-mono text-[var(--text-primary)]">{{ formatLiveDurationFrom(assignedSinceAt(chat)) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>

            <Card class="overflow-hidden">
                <div class="flex items-center justify-between border-b border-[var(--border-muted)] bg-[var(--surface-secondary)]/50 p-3">
                    <div>
                        <h3 class="font-semibold text-[var(--text-primary)]">Today's Trend</h3>
                        <p class="text-sm text-[var(--text-secondary)]">
                            See when inbound chats surged and when closures peaked.
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs uppercase tracking-wider text-[var(--text-muted)]">Peak Hour</p>
                        <p class="text-sm font-semibold text-[var(--text-primary)]">
                            {{ metrics.today_trend.peak_hour_label || "—" }}
                            <span class="text-[var(--text-secondary)]">
                                ({{ metrics.today_trend.peak_hour_count || 0 }} chats)
                            </span>
                        </p>
                    </div>
                </div>
                <div class="p-3">
                    <DashboardLineChart
                        :labels="metrics.today_trend.labels || []"
                        :datasets="[
                            {
                                label: 'New Chats',
                                data: metrics.today_trend.incoming_chats || [],
                                borderColor: 'rgb(14, 165, 233)',
                                backgroundColor: 'rgba(14, 165, 233, 0.12)',
                            },
                            {
                                label: 'Resolved / Closed',
                                data: metrics.today_trend.resolved_chats || [],
                                borderColor: 'rgb(34, 197, 94)',
                                backgroundColor: 'rgba(34, 197, 94, 0.10)',
                            },
                        ]"
                        title="Chats By Hour"
                        :height="320"
                    />
                </div>
            </Card>

            <!-- Agent Availability Table -->
            <Card class="overflow-hidden">
                <div class="px-3 py-2.5 border-b border-[var(--border-muted)] bg-[var(--surface-secondary)]/50 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <Users class="h-4 w-4 text-[var(--interactive-primary)]" />
                        <h3 class="font-semibold text-[var(--text-primary)]">Online Agents</h3>
                    </div>
                    <Badge :variant="availability.available ? 'success' : 'secondary'" size="sm">
                        {{ availability.available_agents || 0 }}
                    </Badge>
                </div>
                <div v-if="!availability.agents || availability.agents.length === 0" class="flex flex-col items-center justify-center py-10 text-[var(--text-muted)] text-center">
                    <Users class="h-8 w-8 mb-2 opacity-30" />
                    <p class="text-sm">No active agents right now. Routing will queue or rely on AI.</p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-[var(--border-muted)]">
                        <thead class="bg-[var(--surface-secondary)]/30">
                            <tr class="text-xs uppercase tracking-wider text-[var(--text-muted)]">
                                <th class="px-4 py-3 text-left font-semibold">Agent Name</th>
                                <th class="px-4 py-3 text-center font-semibold">Current Status</th>
                                <th class="px-4 py-3 text-center font-semibold">Current Active Chats</th>
                                <th class="px-4 py-3 text-center font-semibold">Longest Active Chat</th>
                                <th class="px-4 py-3 text-center font-semibold">Completed Today</th>
                                <th class="px-4 py-3 text-center font-semibold">Missed</th>
                                <th class="px-4 py-3 text-center font-semibold">Rejected</th>
                                <th class="px-4 py-3 text-center font-semibold">AHT</th>
                                <th class="px-4 py-3 text-center font-semibold">Transfers</th>
                                <th class="px-4 py-3 text-center font-semibold">Survey</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border-subtle)] bg-[var(--surface-primary)]">
                            <tr v-for="agent in availability.agents" :key="agent.public_id" class="hover:bg-[var(--surface-secondary)]/35 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="relative flex-shrink-0">
                                            <img v-if="agent.avatar_thumb_url" :src="agent.avatar_thumb_url" :alt="agent.name" class="h-9 w-9 rounded-full border border-[var(--border-subtle)] object-cover" />
                                            <div v-else class="flex h-9 w-9 items-center justify-center rounded-full border border-[var(--border-subtle)] bg-[var(--surface-tertiary)] text-xs font-semibold text-[var(--text-secondary)]">
                                                {{ agent.name.substring(0, 2).toUpperCase() }}
                                            </div>
                                            <span
                                                class="absolute bottom-0 right-0 h-2.5 w-2.5 rounded-full border-2 border-[var(--surface-primary)]"
                                                :class="getSupportStatusColor(getAgentDisplayStatus(agent))"
                                            ></span>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-[var(--text-primary)]">{{ agent.name }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-1.5 text-sm text-[var(--text-secondary)]">
                                        <span>{{ getSupportStatusLabel(getAgentDisplayStatus(agent)) }}</span>
                                        <template v-if="formatStatusDuration(getAgentDisplayStatusAt(agent), getAgentDisplayStatus(agent))">
                                            <span class="opacity-40 select-none">•</span>
                                            <span class="font-mono tabular-nums opacity-80">
                                                {{ formatStatusDuration(getAgentDisplayStatusAt(agent), getAgentDisplayStatus(agent)) }}
                                            </span>
                                        </template>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center text-sm font-medium text-[var(--text-primary)]">
                                    {{ formatActiveChatsLoad(agent) }}
                                </td>
                                <td class="px-4 py-3 text-center text-sm font-medium text-[var(--text-primary)]">
                                    <span :class="getLongestChatToneClasses(getLiveLongestActiveChatSeconds(agent))">
                                        {{ formatHandleTime(getLiveLongestActiveChatSeconds(agent)) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center text-sm font-medium text-[var(--text-primary)]">
                                    {{ agent.completed_today }}
                                </td>
                                <td class="px-4 py-3 text-center text-sm font-medium text-[var(--color-warning-600)] dark:text-[var(--color-warning-400)]">
                                    {{ agent.missed_today || 0 }}
                                </td>
                                <td class="px-4 py-3 text-center text-sm font-medium text-red-600 dark:text-red-400">
                                    {{ agent.rejected_today || 0 }}
                                </td>
                                <td class="px-4 py-3 text-center text-sm font-medium text-[var(--text-primary)]">
                                    {{ formatHandleTime(agent.average_handle_time_seconds) }}
                                </td>
                                <td class="px-4 py-3 text-center text-sm font-medium text-[var(--text-primary)]">
                                    {{ agent.transfers_today }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="text-sm font-medium text-[var(--text-primary)]">
                                        {{ formatSurveyMetric(agent) }}
                                    </div>
                                    <div v-if="formatSurveySubtext(agent)" class="text-xs text-[var(--text-secondary)]">
                                        {{ formatSurveySubtext(agent) }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>
    </div>
</template>
