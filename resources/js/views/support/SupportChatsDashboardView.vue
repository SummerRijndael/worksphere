<script setup>
import { onMounted, onBeforeUnmount, ref } from "vue";
import { Card, Button, Badge } from "@/components/ui";
import { Loader2, MessageSquare, Users, AlertCircle, Inbox, Clock, Bot, User as UserIcon } from "lucide-vue-next";
import api from "@/lib/api";
import { formatDistanceToNow } from "date-fns";
import { useRouter } from "vue-router";
import { setSupportRealtimeToken } from "@/echo";
import { useToast } from "@/composables/useToast";

const router = useRouter();
const toast = useToast();

const loading = ref(true);
const metricsLoading = ref(false);
const availability = ref({
    available: false,
    available_agents: 0,
    message: "Checking support availability...",
});
const waitingQueue = ref([]);
const aiQueue = ref([]);
const assignedQueue = ref([]);
const metrics = ref({
    average_wait_time_seconds: 0,
    ai_resolution_rate: 0,
    resolved_today: 0,
    ai_resolved_today: 0
});
const uiTimers = ref({
    last_response_warn_minutes: 5,
    last_response_alert_minutes: 15,
});

const loadError = ref("");
let refreshInterval = null;
let echoChannel = null;

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
        const [availabilityResponse, waitingRes, aiRes, assignedRes] = await Promise.all([
            api.get("/api/support/chats/availability"),
            api.get("/api/support/chats/inbox", { params: { scope: "waiting", per_page: 50 } }),
            api.get("/api/support/chats/inbox", { params: { scope: "ai", per_page: 50 } }),
            api.get("/api/support/chats/inbox", { params: { scope: "assigned_all", per_page: 50 } }),
        ]);

        availability.value = availabilityResponse.data?.data ?? availability.value;
        waitingQueue.value = waitingRes.data?.data ?? [];
        aiQueue.value = aiRes.data?.data ?? [];
        assignedQueue.value = assignedRes.data?.data ?? [];

        // Update realtime token if provided in metadata
        const meta = waitingRes.data?.meta || {};
        if (meta.realtime?.token) {
            setSupportRealtimeToken(meta.realtime.token, 'agent');
            subscribeToRealtime();
        }
        if (meta.ui_timers) {
            uiTimers.value = meta.ui_timers;
        }

        loadMetrics();
    } catch (error) {
        console.error("Failed to load support chat dashboard:", error);
        loadError.value = "Unable to load dashboard data. Please refresh.";
    } finally {
        if (showLoading) loading.value = false;
    }
}

function subscribeToRealtime() {
    if (echoChannel) return;

    const echo = window.Echo;
    if (!echo) return;

    echoChannel = echo.private('support.agent.inbox')
        .listen('.SupportConversationChanged', (event) => {
            console.debug('[SupportDashboard] Real-time update received:', event);
            // Refresh dashboard data without showing full loading state
            loadDashboard(false);
        });
}

function unsubscribeFromRealtime() {
    if (echoChannel && window.Echo) {
        window.Echo.leave('support.agent.inbox');
        echoChannel = null;
    }
}

onMounted(() => {
    loadDashboard();
    // Fallback polling if realtime fails or for background consistency
    refreshInterval = setInterval(() => loadDashboard(false), 60000);
});

onBeforeUnmount(() => {
    if (refreshInterval) clearInterval(refreshInterval);
    unsubscribeFromRealtime();
});

function formatWaitTime(dateString) {
    if (!dateString) return "Unknown";
    return formatDistanceToNow(new Date(dateString), { addSuffix: true });
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

function openChat(publicId) {
    router.push(`/support/chats/${publicId}`);
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
            <Card class="p-5 space-y-2 border-l-4 border-l-[var(--color-primary-500)]">
                <div class="flex items-center justify-between">
                    <Users class="h-5 w-5 text-[var(--interactive-primary)]" />
                    <Badge :variant="availability.available ? 'success' : 'warning'" size="sm">
                        {{ availability.available ? "Online" : "Offline" }}
                    </Badge>
                </div>
                <p class="text-sm text-[var(--text-secondary)]">Available Agents</p>
                <p class="text-2xl font-semibold text-[var(--text-primary)]">{{ availability.available_agents }}</p>
            </Card>

            <Card class="p-5 space-y-2 border-l-4 border-l-[var(--color-warning-500)]">
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

            <Card class="p-5 space-y-2 border-l-4 border-l-[var(--color-info-500)]">
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

            <Card class="p-5 space-y-2 border-l-4 border-l-[var(--color-success-500)]">
                <div class="flex items-center justify-between">
                    <Inbox class="h-5 w-5 text-[var(--color-success-500)]" />
                    <Badge variant="success" size="sm">Resolved</Badge>
                </div>
                <p class="text-sm text-[var(--text-secondary)]">Resolved Today</p>
                <p class="text-2xl font-semibold text-[var(--text-primary)]">{{ metrics.resolved_today }}</p>
            </Card>
        </div>

        <!-- Main Layout with Sidebar -->
        <div class="flex flex-col xl:flex-row gap-6 items-start">
            
            <!-- Queues Grid -->
            <div class="flex-1 grid grid-cols-1 lg:grid-cols-3 gap-6 w-full">
                <!-- Waiting Queue -->
                <Card class="flex flex-col h-[600px] overflow-hidden">
                    <div class="p-4 border-b border-[var(--border-muted)] bg-[var(--surface-secondary)]/50 flex items-center gap-2">
                        <AlertCircle class="h-4 w-4 text-[var(--color-warning-500)]" />
                        <h3 class="font-semibold text-[var(--text-primary)]">Waiting for Agent ({{ waitingQueue.length }})</h3>
                    </div>
                    <div class="flex-1 overflow-y-auto p-2 space-y-2">
                        <div v-if="waitingQueue.length === 0" class="flex flex-col items-center justify-center py-12 text-[var(--text-muted)]">
                            <Inbox class="h-8 w-8 mb-2 opacity-50" />
                            <p class="text-sm">No chats waiting.</p>
                        </div>
                        <div v-for="chat in waitingQueue" :key="chat.public_id" 
                             @click="openChat(chat.public_id)"
                             class="p-3 rounded-md border border-[var(--border-subtle)] bg-[var(--surface-primary)] hover:border-[var(--interactive-primary)] cursor-pointer transition-colors group relative overflow-hidden"
                             :class="{
                                 'border-red-500/50 bg-red-50/10': getSLAStatus(chat) === 'alert',
                                 'border-amber-500/50 bg-amber-50/10': getSLAStatus(chat) === 'warn'
                             }">
                            <!-- SLA Indicators -->
                            <div v-if="getSLAStatus(chat) !== 'normal'" 
                                 class="absolute top-0 right-0 h-1" 
                                 :class="getSLAStatus(chat) === 'alert' ? 'bg-red-500 w-full' : 'bg-amber-500 w-1/2'">
                            </div>

                            <div class="flex justify-between items-start mb-2">
                                <span class="font-medium text-[var(--text-primary)] text-sm truncate pr-2">{{ chat.guest_name || chat.requester?.name || 'Anonymous' }}</span>
                                <Badge :variant="getStatusColor(chat.status)" size="sm">{{ getStatusLabel(chat.status) }}</Badge>
                            </div>
                            <p class="text-xs text-[var(--text-secondary)] truncate mb-2">{{ chat.subject || 'No subject' }}</p>
                            <div class="flex items-center text-xs text-[var(--text-muted)] justify-between">
                                <div class="flex items-center gap-1 font-medium" 
                                     :class="{
                                         'text-red-600 dark:text-red-400': getSLAStatus(chat) === 'alert',
                                         'text-amber-600 dark:text-amber-400': getSLAStatus(chat) === 'warn',
                                         'text-[var(--color-warning-600)] dark:text-[var(--color-warning-400)]': getSLAStatus(chat) === 'normal'
                                     }">
                                    <Clock class="h-3 w-3" />
                                    <span>{{ formatWaitTime(chat.created_at) }}</span>
                                </div>
                                <div v-if="getSLAStatus(chat) === 'alert'" class="flex items-center gap-1 text-red-600 font-bold">
                                    <AlertCircle class="h-3 w-3" />
                                    <span>SLA Breach</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- AI Handled Queue -->
                <Card class="flex flex-col h-[600px] overflow-hidden">
                    <div class="p-4 border-b border-[var(--border-muted)] bg-[var(--surface-secondary)]/50 flex items-center gap-2">
                        <Bot class="h-4 w-4 text-[var(--color-info-500)]" />
                        <h3 class="font-semibold text-[var(--text-primary)]">Handled by AI ({{ aiQueue.length }})</h3>
                    </div>
                    <div class="flex-1 overflow-y-auto p-2 space-y-2">
                        <div v-if="aiQueue.length === 0" class="flex flex-col items-center justify-center py-12 text-[var(--text-muted)]">
                            <Bot class="h-8 w-8 mb-2 opacity-50" />
                            <p class="text-sm">No active AI chats.</p>
                        </div>
                        <div v-for="chat in aiQueue" :key="chat.public_id" 
                             @click="openChat(chat.public_id)"
                             class="p-3 rounded-md border border-[var(--border-subtle)] bg-[var(--surface-primary)] hover:border-[var(--color-info-500)] cursor-pointer transition-colors group">
                            <div class="flex justify-between items-start mb-2">
                                <span class="font-medium text-[var(--text-primary)] text-sm truncate pr-2">{{ chat.guest_name || chat.requester?.name || 'Anonymous' }}</span>
                                <Badge variant="info" size="sm">Auto-pilot</Badge>
                            </div>
                            <p class="text-xs text-[var(--text-secondary)] truncate mb-2">{{ chat.subject || 'No subject' }}</p>
                            <div class="flex items-center text-xs text-[var(--text-muted)] justify-between">
                                <div class="flex items-center gap-1">
                                    <Clock class="h-3 w-3" />
                                    <span>{{ formatWaitTime(chat.created_at) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Assigned Queue -->
                <Card class="flex flex-col h-[600px] overflow-hidden">
                    <div class="p-4 border-b border-[var(--border-muted)] bg-[var(--surface-secondary)]/50 flex items-center gap-2">
                        <UserIcon class="h-4 w-4 text-[var(--color-success-500)]" />
                        <h3 class="font-semibold text-[var(--text-primary)]">Assigned Chats ({{ assignedQueue.length }})</h3>
                    </div>
                    <div class="flex-1 overflow-y-auto p-2 space-y-2">
                        <div v-if="assignedQueue.length === 0" class="flex flex-col items-center justify-center py-12 text-[var(--text-muted)]">
                            <UserIcon class="h-8 w-8 mb-2 opacity-50" />
                            <p class="text-sm">No assigned chats.</p>
                        </div>
                        <div v-for="chat in assignedQueue" :key="chat.public_id" 
                             @click="openChat(chat.public_id)"
                             class="p-3 rounded-md border border-[var(--border-subtle)] bg-[var(--surface-primary)] hover:border-[var(--color-success-500)] cursor-pointer transition-colors group">
                            <div class="flex justify-between items-start mb-2">
                                <span class="font-medium text-[var(--text-primary)] text-sm truncate pr-2">{{ chat.guest_name || chat.requester?.name || 'Anonymous' }}</span>
                                <div class="flex flex-col items-end gap-1">
                                    <Badge variant="success" size="sm">Active</Badge>
                                </div>
                            </div>
                            <p class="text-xs text-[var(--text-secondary)] truncate mb-2">{{ chat.subject || 'No subject' }}</p>
                            <div class="flex items-center justify-between mt-2 pt-2 border-t border-[var(--border-subtle)]">
                                <div class="flex items-center gap-2 text-xs text-[var(--text-secondary)]">
                                    <Users class="h-3 w-3" />
                                    <span>{{ chat.assignee?.name || 'Loading...' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Agent Availability Sidebar -->
            <Card class="w-full xl:w-72 flex-shrink-0 flex flex-col h-[600px] overflow-hidden">
                <div class="p-4 border-b border-[var(--border-muted)] bg-[var(--surface-secondary)]/50 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <Users class="h-4 w-4 text-[var(--interactive-primary)]" />
                        <h3 class="font-semibold text-[var(--text-primary)]">Online Agents</h3>
                    </div>
                    <Badge :variant="availability.available ? 'success' : 'secondary'" size="sm">
                        {{ availability.agents?.length || 0 }}
                    </Badge>
                </div>
                <div class="flex-1 overflow-y-auto p-3 space-y-3">
                    <div v-if="!availability.agents || availability.agents.length === 0" class="flex flex-col items-center justify-center py-8 text-[var(--text-muted)] text-center">
                        <Users class="h-8 w-8 mb-2 opacity-30" />
                        <p class="text-xs">No active agents right now. Routing will queue or rely on AI.</p>
                    </div>
                    <div v-else class="space-y-3">
                        <div v-for="agent in availability.agents" :key="agent.public_id" class="flex items-center justify-between group">
                            <div class="flex items-center gap-3 overflow-hidden">
                                <div class="relative flex-shrink-0">
                                    <img v-if="agent.avatar_thumb_url" :src="agent.avatar_thumb_url" :alt="agent.name" class="w-8 h-8 rounded-full border border-[var(--border-subtle)] object-cover" />
                                    <div v-else class="w-8 h-8 rounded-full bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border border-[var(--border-subtle)] flex items-center justify-center text-xs font-semibold">
                                        {{ agent.name.substring(0, 2).toUpperCase() }}
                                    </div>
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 bg-[var(--color-success-500)] border-2 border-[var(--surface-primary)] rounded-full"></span>
                                </div>
                                <div class="min-w-0 pr-2">
                                    <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ agent.name }}</p>
                                    <p class="text-xs text-[var(--text-secondary)] truncate text-ellipsis overflow-hidden">
                                        {{ agent.active_chats }} active {{ agent.active_chats === 1 ? 'chat' : 'chats' }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    </div>
</template>
