<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { usePresence, getStatusColor, getStatusLabel } from '@/composables/usePresence.ts';
import { useAuthStore } from '@/stores/auth';
import echo, { getConnectionState, onConnectionChange, isEchoAvailable } from '@/echo';
import api from '@/lib/api';
import { cn } from '@/lib/utils';
import { Avatar } from '@/components/ui';
import { 
    RefreshCw, 
    Wifi, 
    WifiOff, 
    Clock, 
    Users, 
    Activity,
    Circle,
    Zap,
    Send,
    Terminal,
    Radio,
    Trash2,
    AlertCircle,
    PauseCircle,
    Copy,
    Check
} from 'lucide-vue-next';
import { useClipboard } from '@vueuse/core';

// Only show in development
const isDev = import.meta.env.DEV;

const authStore = useAuthStore();

const {
    currentStatus,
    preferredStatus,
    isOnline,
    connectionHealth,
    lastHeartbeat,
    presenceUsers,
    setStatus,
    sendHeartbeat,
    fetchMyPresence,
    reconnect,
    updateUserPresence,
} = usePresence({ manageLifecycle: false });

// WebSocket connection state (reactive from echo.js)
const wsConnectionState = getConnectionState();
const wsState = ref(wsConnectionState.value);

// Debug state
const debugLog = ref([]);
const manualStatus = ref('online');
const isBroadcasting = ref(false);
const debugChannelConnected = ref(false);
const isSubscribed = ref(false);
const { copy, copied } = useClipboard();

function copyToClipboard(text) {
    copy(text);
}

// Event listeners cleanup
let cleanupListeners = [];

// Convert Map to array for display
const onlineUsersList = computed(() => {
    return Array.from(presenceUsers.value.values());
});

const onlineCount = computed(() => {
    return onlineUsersList.value.filter(u => u.status === 'online').length;
});

const awayCount = computed(() => {
    return onlineUsersList.value.filter(u => u.status === 'away').length;
});

const busyCount = computed(() => {
    return onlineUsersList.value.filter(u => u.status === 'busy').length;
});

const lastHeartbeatFormatted = computed(() => {
    if (!lastHeartbeat.value) return 'Never';
    const seconds = Math.floor((Date.now() - lastHeartbeat.value) / 1000);
    if (seconds < 60) return `${seconds}s ago`;
    return `${Math.floor(seconds / 60)}m ${seconds % 60}s ago`;
});

const userPublicId = computed(() => authStore.user?.public_id);

// Refresh interval for display
const refreshKey = ref(0);
let refreshInterval = null;

function addLog(type, message, data = null) {
    const entry = {
        id: Date.now(),
        // HH:MM:ss.ms
        time: new Date().toISOString().slice(11, 23),
        type,
        message,
        data: data ? JSON.stringify(data, null, 2) : null,
    };
    debugLog.value.unshift(entry);
    // Keep only last 100 entries
    if (debugLog.value.length > 100) {
        debugLog.value.pop();
    }
}

function clearLog() {
    debugLog.value = [];
    addLog('info', 'Log cleared');
}

async function forceHeartbeat() {
    addLog('send', '→ Sending heartbeat...');
    const result = await sendHeartbeat('manual');
    
    if (result.throttled) {
        addLog('info', '← Heartbeat Throttled (Rate Limited)', result);
    } else if (result.success) {
        addLog('receive', '← Heartbeat response', result);
    } else {
        addLog('error', '← Heartbeat failed', result);
    }
}

function forceReconnect() {
    addLog('info', 'Forcing reconnect...');
    reconnect();
}

async function handleStatusChange(status) {
    addLog('send', `→ Changing status to: ${status}`);
    const result = await setStatus(status);
    if (result.success) {
        addLog('receive', '← Status change response', result);
    } else {
        addLog('error', '← Status change failed', result);
    }
}

async function manualBroadcast() {
    if (!userPublicId.value) {
        addLog('error', 'No user public_id available');
        return;
    }
    
    isBroadcasting.value = true;
    addLog('send', `→ Manual broadcast: ${manualStatus.value}`);
    
    try {
        const response = await api.post('/api/presence/debug/broadcast', {
            status: manualStatus.value,
        });
        addLog('receive', '← Broadcast success response', response.data);
    } catch (error) {
        addLog('error', `← Broadcast failed: ${error.message}`, error.response?.data);
    } finally {
        isBroadcasting.value = false;
    }
}

function subscribeToDebugChannel() {
    if (!isEchoAvailable() || !userPublicId.value) {
        addLog('error', 'Echo not available or no user public_id (Waiting...)');
        return;
    }

    if (isSubscribed.value) {
         addLog('info', 'Already subscribed to debug channels');
         return;
    }

    const eventName = '.presence.changed'; // Dot prefix to bypass namespace
    const altEventName = 'presence.changed'; // Standard name just in case

    // Subscribe to personal presence channel for debug
    addLog('info', `Subscribing to presence.${userPublicId.value}`);
    
    echo.private(`presence.${userPublicId.value}`)
        .listen(eventName, (data) => {
            addLog('receive', '← [PERSONAL] .presence.changed', data);
            currentStatus.value = data.status;
        })
        .listen(altEventName, (data) => {
            addLog('receive', '← [PERSONAL] presence.changed', data);
            currentStatus.value = data.status;
        });
    
    // Subscribe to online-users presence channel
    addLog('info', 'Subscribing to online-users');
    
    echo.join('online-users')
        .here((users) => {
            addLog('receive', '← [GLOBAL] here', { count: users.length, users });
            users.forEach(user => {
                updateUserPresence(user, 'online');
            });
            debugChannelConnected.value = true;
        })
        .joining((user) => {
            addLog('receive', '← [GLOBAL] joining', user);
            updateUserPresence(user, 'online');
        })
        .leaving((user) => {
            addLog('receive', '← [GLOBAL] leaving', user);
            updateUserPresence(user, 'offline');
        })
        .listen(eventName, (data) => {
            addLog('receive', '← [GLOBAL] presence.changed', data);
            updateUserPresence(data, data.status);
        })
        .listenForWhisper('activity', (e) => {
            addLog('receive', '← [WHISPER] client-activity', e);
            const newStatus = e.status || 'online';
            updateUserPresence({ public_id: e.public_id }, newStatus);
        })
        .error((error) => {
            addLog('error', '← [GLOBAL] channel error', error);
        });

    isSubscribed.value = true;
}

function trySubscribe() {
    if (echo && userPublicId.value && wsState.value === 'connected') {
        subscribeToDebugChannel();
    }
}

onMounted(() => {
    if (!isDev) return;

    addLog('info', 'Debug dashboard mounted');
    addLog('info', `User: ${authStore.user?.name} (${userPublicId.value})`);
    
    refreshInterval = setInterval(() => {
        refreshKey.value++;
    }, 1000);

    // 1. Subscribe to internal echo state changes
    cleanupListeners.push(onConnectionChange((state, error) => {
        wsState.value = state;
        if (state === 'connected') {
             trySubscribe();
        } else if (state === 'disconnected') {
             isSubscribed.value = false;
        }
    }));

    // Watch for Auth Ready
    watch(userPublicId, (newId) => {
        if (newId) trySubscribe();
    }, { immediate: true });

    // 2. Listen to global echo events (richer info)
    const onReconnecting = (e) => {
        addLog('info', `WebSocket Reconnecting (Attempt ${e.detail.attempt})...`, { delay: `${e.detail.delay}ms` });
    };
    window.addEventListener('echo:reconnecting', onReconnecting);
    cleanupListeners.push(() => window.removeEventListener('echo:reconnecting', onReconnecting));
    
    const onConnected = () => {
        addLog('info', 'WebSocket Connected (Global Event)');
        debugChannelConnected.value = true; // Optimistic update
        trySubscribe(); // Try subscribing on global connect event too
    };
    window.addEventListener('echo:connected', onConnected);
    cleanupListeners.push(() => window.removeEventListener('echo:connected', onConnected));

    const onDisconnected = (e) => {
        addLog('error', 'WebSocket Disconnected (Global Event)', e.detail?.error);
        debugChannelConnected.value = false;
        isSubscribed.value = false;
    };
    window.addEventListener('echo:disconnected', onDisconnected);
    cleanupListeners.push(() => window.removeEventListener('echo:disconnected', onDisconnected));

    // Initial Try
    trySubscribe();
});

onUnmounted(() => {
    if (!isDev) return;

    try {
        if (subscribeTimer) {
            clearTimeout(subscribeTimer);
            subscribeTimer = null;
        }

        if (refreshInterval) {
            clearInterval(refreshInterval);
        }
        
        cleanupListeners.forEach(cleanup => {
            try {
                if (typeof cleanup === 'function') cleanup();
            } catch (e) {
                console.error('Debug cleanup error:', e);
            }
        });
        cleanupListeners = [];
    } catch (error) {
        console.error('PresenceDebugView unmount error:', error);
    }
    
    // Do NOT leave channels as they are shared with the main application
    // echo.leave(...) would disconnect the global presence system
});

const statusOptions = [
    { value: 'online', label: 'Online', colorClass: 'bg-emerald-500' },
    { value: 'busy', label: 'Busy', colorClass: 'bg-rose-500' },
    { value: 'away', label: 'Away', colorClass: 'bg-amber-500' },
    { value: 'invisible', label: 'Invisible', colorClass: 'bg-slate-400' },
];
</script>

<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-[var(--text-primary)]">
                    Presence Monitor
                </h2>
                <p class="text-sm text-[var(--text-secondary)]">
                    Real-time presence system status and controls
                </p>
            </div>
            <div class="flex items-center gap-3">
                <button
                    @click="forceHeartbeat"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg bg-[var(--surface-elevated)] border border-[var(--border-default)] hover:border-[var(--border-strong)] text-sm font-medium transition-colors text-[var(--text-secondary)] shadow-sm"
                >
                    <Activity class="h-4 w-4" />
                    Force Heartbeat
                </button>
                <button
                    @click="forceReconnect"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg bg-[var(--surface-elevated)] border border-[var(--border-default)] hover:border-[var(--border-strong)] text-sm font-medium transition-colors text-[var(--text-secondary)] shadow-sm"
                >
                    <RefreshCw class="h-4 w-4" />
                    Reconnect
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <!-- Connection Status -->
            <div class="p-4 rounded-xl bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm">
                <div class="flex items-center gap-3">
                    <div :class="cn(
                        'p-2 rounded-lg',
                        wsState === 'connected' ? 'bg-emerald-500/10' : 
                        wsState === 'connecting' || wsState === 'reconnecting' ? 'bg-amber-500/10' : 'bg-rose-500/10'
                    )">
                        <component 
                            :is="wsState === 'connected' ? Wifi : (wsState === 'connecting' || wsState === 'reconnecting') ? RefreshCw : WifiOff" 
                            :class="cn(
                                'h-5 w-5', 
                                wsState === 'connected' ? 'text-emerald-500' : 
                                (wsState === 'connecting' || wsState === 'reconnecting') ? 'text-amber-500 animate-spin' : 'text-rose-500'
                            )"
                        />
                    </div>
                    <div>
                        <p class="text-xs text-[var(--text-muted)] uppercase tracking-wider">WebSocket</p>
                        <p :class="cn(
                            'font-semibold',
                            wsState === 'connected' && 'text-emerald-500',
                            (wsState === 'connecting' || wsState === 'reconnecting') && 'text-amber-500',
                            ['disconnected', 'error', 'unavailable', 'failed'].includes(wsState) && 'text-rose-500'
                        )">
                            {{ wsState }}
                        </p>
                    </div>
                </div>
                <div class="mt-3 text-xs text-[var(--text-secondary)]">
                    API Health: <span :class="cn(
                        'font-medium',
                        connectionHealth === 'healthy' && 'text-emerald-500',
                        connectionHealth === 'degraded' && 'text-amber-500',
                        connectionHealth === 'disconnected' && 'text-rose-500'
                    )">
                        {{ connectionHealth }}
                        <span v-if="connectionHealth === 'degraded'" class="ml-1 text-[10px] bg-amber-500/20 text-amber-500 px-1 rounded">PAUSED</span>
                    </span>
                </div>
            </div>

            <!-- Your Status -->
            <div class="p-4 rounded-xl bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-blue-500/10">
                        <Circle class="h-5 w-5 text-blue-500" />
                    </div>
                    <div>
                        <p class="text-xs text-[var(--text-muted)] uppercase tracking-wider">Your Status</p>
                        <div class="flex items-center gap-2">
                            <span :class="cn('h-2 w-2 rounded-full', getStatusColor(currentStatus))" />
                            <p class="font-semibold text-[var(--text-primary)]">{{ getStatusLabel(currentStatus) }}</p>
                        </div>
                    </div>
                </div>
                <div class="mt-3 text-xs text-[var(--text-secondary)]">
                    Preference: {{ preferredStatus }}
                </div>
            </div>

            <!-- Last Heartbeat -->
            <div class="p-4 rounded-xl bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-amber-500/10">
                        <Clock class="h-5 w-5 text-amber-500" />
                    </div>
                    <div>
                        <p class="text-xs text-[var(--text-muted)] uppercase tracking-wider">Last Heartbeat</p>
                        <p class="font-semibold text-[var(--text-primary)]" :key="refreshKey">
                            {{ lastHeartbeatFormatted }}
                        </p>
                    </div>
                </div>
                <div class="mt-3 text-xs text-[var(--text-secondary)]">
                    Raw: {{ lastHeartbeat || 'null' }}
                </div>
            </div>

            <!-- Online Users Count -->
            <div class="p-4 rounded-xl bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-indigo-500/10">
                        <Users class="h-5 w-5 text-indigo-500" />
                    </div>
                    <div>
                        <p class="text-xs text-[var(--text-muted)] uppercase tracking-wider">Online Users</p>
                        <p class="font-semibold text-[var(--text-primary)]">
                            {{ onlineUsersList.length }} tracked
                        </p>
                    </div>
                </div>
                <div class="mt-3 flex gap-3 text-xs">
                    <span class="text-emerald-500">{{ onlineCount }} online</span>
                    <span class="text-amber-500">{{ awayCount }} away</span>
                    <span class="text-rose-500">{{ busyCount }} busy</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Left Column: Controls -->
            <div class="space-y-6">
                <!-- Status Selector -->
                <div class="p-4 rounded-xl bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm">
                    <h2 class="font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2">
                        <Circle class="h-4 w-4" />
                        Change Status (via API)
                    </h2>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            v-for="option in statusOptions"
                            :key="option.value"
                            @click="handleStatusChange(option.value)"
                            :class="cn(
                                'flex items-center gap-2 px-3 py-2 rounded-lg transition-all',
                                'border text-sm font-medium',
                                preferredStatus === option.value
                                    ? 'border-blue-500 bg-blue-50 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300'
                                    : 'border-[var(--border-default)] hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)]'
                            )"
                        >
                            <span :class="cn('h-2.5 w-2.5 rounded-full', option.colorClass)" />
                            {{ option.label }}
                        </button>
                    </div>
                </div>

                <!-- Manual Broadcast -->
                <div class="p-4 rounded-xl bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm">
                    <h2 class="font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2">
                        <Radio class="h-4 w-4" />
                        Manual Broadcast (Direct Event)
                    </h2>
                    <div class="flex gap-2">
                        <select 
                            v-model="manualStatus"
                            class="flex-1 px-3 py-2 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-default)] text-sm text-[var(--text-primary)]"
                        >
                            <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <button
                            @click="manualBroadcast"
                            :disabled="isBroadcasting"
                            class="flex items-center gap-2 px-4 py-2 rounded-lg bg-[var(--interactive-primary)] text-white text-sm font-medium hover:bg-[var(--interactive-primary-hover)] disabled:opacity-50 transition-colors"
                        >
                            <Send class="h-4 w-4" />
                            Broadcast
                        </button>
                    </div>
                    <p class="text-xs text-[var(--text-muted)] mt-2">
                        Sends a UserPresenceChanged event directly (ShouldBroadcastNow)
                    </p>
                </div>

                <!-- Channel Info -->
                <div class="p-4 rounded-xl bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm">
                    <h2 class="font-semibold text-[var(--text-primary)] mb-3 flex items-center gap-2">
                        <Zap class="h-4 w-4 text-amber-500" />
                        Active WebSocket Channels
                    </h2>
                    <div class="space-y-3 text-sm">
                        <div class="p-3 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-muted)]">
                            <div class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                <p class="font-mono text-xs text-[var(--text-muted)]">Private Channel</p>
                            </div>
                            <p class="font-mono text-[var(--text-secondary)]">presence.{{ userPublicId?.slice(0, 8) }}...</p>
                        </div>
                        <div class="p-3 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-muted)]">
                            <div class="flex items-center gap-2">
                                <span :class="cn('h-2 w-2 rounded-full', debugChannelConnected ? 'bg-emerald-500' : 'bg-amber-500 animate-pulse')"></span>
                                <p class="font-mono text-xs text-[var(--text-muted)]">Presence Channel</p>
                            </div>
                            <p class="font-mono text-[var(--text-secondary)]">presence-online-users</p>
                        </div>
                    </div>
                </div>

                <!-- Online Users List -->
                <div class="p-4 rounded-xl bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm">
                    <h2 class="font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2">
                        <Users class="h-4 w-4" />
                        Online Users ({{ onlineUsersList.length }})
                    </h2>

                    <div v-if="onlineUsersList.length === 0" class="text-center py-4 text-[var(--text-muted)] text-sm">
                        No users tracked yet
                    </div>

                    <div v-else class="space-y-2">
                        <div
                            v-for="user in onlineUsersList"
                            :key="user.public_id"
                            class="flex items-center gap-3 p-2 rounded-lg bg-[var(--surface-secondary)]"
                        >
                            <Avatar
                                :src="user.avatar_thumb_url"
                                :fallback="user.name?.charAt(0) || '?'"
                                :status="user.status"
                                size="sm"
                            />
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-sm text-[var(--text-primary)] truncate">
                                    {{ user.name || 'Unknown' }}
                                </p>
                                <div class="flex items-center gap-1.5 text-xs text-[var(--text-secondary)]">
                                    <span :class="cn('h-1.5 w-1.5 rounded-full', getStatusColor(user.status))" />
                                    {{ getStatusLabel(user.status) }}
                                </div>
                            </div>
                            <div class="text-xs font-mono text-[var(--text-muted)]">
                                {{ user.public_id?.slice(0, 8) }}...
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Debug Log -->
            <div class="p-4 rounded-xl bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-semibold text-[var(--text-primary)] flex items-center gap-2">
                        <Terminal class="h-4 w-4" />
                        Debug Log
                    </h2>
                    <div class="flex items-center gap-2">
                        <button
                            @click="clearLog"
                            class="flex items-center gap-1 px-2 py-1 rounded text-xs text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors"
                        >
                            <Trash2 class="h-3 w-3" />
                            Clear
                        </button>
                        <button
                            @click="copyToClipboard(JSON.stringify(debugLog, null, 2))"
                            :disabled="debugLog.length === 0"
                            class="flex items-center gap-1 px-2 py-1 rounded text-xs text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors disabled:opacity-50"
                        >
                             <component :is="copied ? Check : Copy" class="h-3 w-3" />
                             {{ copied ? 'Copied!' : 'Copy' }}
                        </button>
                    </div>
                </div>
                
                <div class="h-[600px] overflow-y-auto bg-gray-900 text-gray-300 rounded-lg p-3 font-mono text-xs space-y-2">
                    <!-- Note: Log is explicitly dark theme to mimic terminal, so we use fixed colors -->
                    <div
                        v-for="entry in debugLog"
                        :key="entry.id"
                        :class="cn(
                            'p-2 rounded border-l-2',
                            entry.type === 'send' && 'bg-blue-900/20 border-blue-500',
                            entry.type === 'receive' && 'bg-emerald-900/20 border-emerald-500',
                            entry.type === 'error' && 'bg-rose-900/20 border-rose-500',
                            entry.type === 'info' && 'bg-slate-700/30 border-slate-500'
                        )"
                    >
                        <div class="flex items-center gap-2 text-gray-400">
                            <span>{{ entry.time }}</span>
                            <span :class="cn(
                                'px-1.5 py-0.5 rounded text-[10px] uppercase font-semibold',
                                entry.type === 'send' && 'bg-blue-500/20 text-blue-400',
                                entry.type === 'receive' && 'bg-emerald-500/20 text-emerald-400',
                                entry.type === 'error' && 'bg-rose-500/20 text-rose-400',
                                entry.type === 'info' && 'bg-slate-500/20 text-slate-400'
                            )">{{ entry.type }}</span>
                        </div>
                        <p class="text-gray-200 mt-1 break-words">{{ entry.message }}</p>
                        <pre v-if="entry.data" class="mt-1 text-gray-500 whitespace-pre-wrap break-all">{{ entry.data }}</pre>
                    </div>
                    
                    <div v-if="debugLog.length === 0" class="text-center text-gray-600 py-8">
                        No log entries yet. Interact with the controls to see activity.
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
