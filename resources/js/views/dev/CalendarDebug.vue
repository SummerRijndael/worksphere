<script setup>
import { ref, onMounted, computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import api from '@/lib/api';
import { cn } from '@/lib/utils';
import { 
    Calendar as CalendarIcon, 
    RefreshCw, 
    Terminal, 
    Trash2,
    Code,
    Play,
    AlertCircle,
    Copy,
    Check
} from 'lucide-vue-next';
import { useClipboard } from '@vueuse/core';

// Debug state
const debugLog = ref([]);
const recentEvents = ref([]);
const rawResponse = ref(null);
const isLoading = ref(false);
const isTriggering = ref(false);
const authStore = useAuthStore();
const { copy, copied } = useClipboard();

function copyToClipboard(text) {
    copy(text);
}

function addLog(type, message, data = null) {
    const entry = {
        id: Date.now(),
        time: new Date().toISOString().slice(11, 23),
        type,
        message,
        data: data ? JSON.stringify(data, null, 2) : null,
    };
    debugLog.value.unshift(entry);
    if (debugLog.value.length > 100) debugLog.value.pop();
}

async function triggerSeminder() {
    if (isTriggering.value) return;
    
    isTriggering.value = true;
    addLog('send', '→ Triggering simulated reminder...');
    
    try {
        const response = await api.post('/api/calendar/debug/reminder');
        addLog('receive', '← Reminder triggered', response.data);
    } catch (error) {
        addLog('error', '← Trigger failed', error.response?.data || error.message);
    } finally {
        isTriggering.value = false;
    }
}

function clearLog() {
    debugLog.value = [];
    addLog('info', 'Log cleared');
}

async function fetchEvents() {
    if (isLoading.value) return;
    
    isLoading.value = true;
    addLog('send', '→ Fetching calendar events...');
    
    try {
        const start = new Date();
        start.setDate(start.getDate() - 30); // 30 days ago
        const end = new Date();
        end.setDate(end.getDate() + 90); // 90 days future
        
        const params = {
            start: start.toISOString(),
            end: end.toISOString()
        };
        
        addLog('info', 'Query params', params);
        
        const response = await api.get('/api/calendar/events', { params });
        
        rawResponse.value = response.data;
        recentEvents.value = response.data; // Assuming direct array or data property
        
        addLog('receive', `← Received ${response.data.length} events`, response.data);
    } catch (error) {
        addLog('error', '← Fetch failed', error.response?.data || error.message);
        rawResponse.value = error.response?.data || { error: error.message };
    } finally {
        isLoading.value = false;
    }
}

// Formatters
const formatDate = (date) => new Date(date).toLocaleString();

onMounted(() => {
    addLog('info', 'Calendar Debug Mounted');
});
</script>

<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-[var(--text-primary)]">
                    Calendar Inspector
                </h2>
                <p class="text-sm text-[var(--text-secondary)]">
                    Debug calendar event fetching and data structure
                </p>
            </div>
            <div class="flex items-center gap-3">
                <button
                    @click="fetchEvents"
                    :disabled="isLoading"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg bg-[var(--surface-elevated)] border border-[var(--border-default)] hover:border-[var(--border-strong)] text-sm font-medium transition-colors text-[var(--text-secondary)] shadow-sm disabled:opacity-50"
                >
                    <RefreshCw :class="cn('h-4 w-4', isLoading && 'animate-spin')" />
                    Fetch Events
                </button>
                <button
                    @click="triggerSeminder"
                    :disabled="isTriggering"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg bg-[var(--surface-elevated)] border border-[var(--border-default)] hover:border-[var(--border-strong)] text-sm font-medium transition-colors text-[var(--text-secondary)] shadow-sm disabled:opacity-50"
                >
                    <AlertCircle :class="cn('h-4 w-4', isTriggering ? 'text-amber-500 animate-pulse' : 'text-amber-500')" />
                    Simulate Reminder
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Left Column: Controls & Data -->
            <div class="space-y-6">
                
                <!-- Recent Events List -->
                <div class="p-4 rounded-xl bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm">
                    <h2 class="font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2">
                        <CalendarIcon class="h-4 w-4" />
                        Fetched Events ({{ recentEvents.length }})
                    </h2>

                    <div v-if="recentEvents.length === 0" class="text-center py-8 text-[var(--text-muted)] text-sm">
                        No events fetched yet
                    </div>

                    <div v-else class="space-y-2 max-h-[500px] overflow-y-auto pr-2 custom-scrollbar">
                        <div
                            v-for="event in recentEvents"
                            :key="event.id"
                            class="p-3 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-muted)] hover:border-[var(--border-default)] transition-colors"
                        >
                            <div class="flex items-start justify-between mb-2">
                                <h3 class="font-medium text-[var(--text-primary)]">{{ event.title }}</h3>
                                <span :class="cn(
                                    'text-[10px] px-1.5 py-0.5 rounded font-mono uppercase', 
                                    event.is_all_day ? 'bg-blue-500/10 text-blue-500' : 'bg-slate-500/10 text-slate-500'
                                )">
                                    {{ event.is_all_day ? 'All Day' : 'Timed' }}
                                </span>
                            </div>
                            <div class="text-xs text-[var(--text-secondary)] space-y-1">
                                <p>Start: {{ formatDate(event.start) }}</p>
                                <p>End: {{ formatDate(event.end) }}</p>
                                <p v-if="event.location">📍 {{ event.location }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Raw Response Preview -->
                <div class="p-4 rounded-xl bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-semibold text-[var(--text-primary)] flex items-center gap-2">
                            <Code class="h-4 w-4" />
                            Raw Response Data
                        </h2>
                        <button
                            @click="copyToClipboard(JSON.stringify(rawResponse, null, 2))"
                            :disabled="!rawResponse"
                            class="flex items-center gap-1 px-2 py-1 rounded text-xs text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors disabled:opacity-50"
                        >
                            <component :is="copied ? Check : Copy" class="h-3 w-3" />
                            {{ copied ? 'Copied!' : 'Copy' }}
                        </button>
                    </div>
                    <div class="bg-gray-900 rounded-lg p-3 text-xs font-mono text-gray-300 h-[300px] overflow-auto">
                        <pre v-if="rawResponse">{{ JSON.stringify(rawResponse, null, 2) }}</pre>
                        <div v-else class="text-gray-600 h-full flex items-center justify-center">
                            No response data to inspector
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Debug Log (Shared style with PresenceDebug) -->
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
