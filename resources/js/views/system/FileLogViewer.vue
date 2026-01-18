<script setup>
import { ref, onMounted, watch, computed } from 'vue';
import { Button, SearchInput, SelectFilter, Badge, Checkbox } from '@/components/ui';
import api from '@/lib/api';
import { toast } from 'vue-sonner';
import { 
    RefreshCw, 
    Download, 
    AlertTriangle, 
    Info, 
    AlertCircle, 
    XCircle,
    ChevronDown,
    ChevronRight,
    FileText,
    Copy,
    Check
} from 'lucide-vue-next';
import { debounce } from 'lodash';

// State
const files = ref([]);
const selectedFile = ref('');
const logs = ref([]);
const isLoading = ref(false);
const isLoadingFiles = ref(false);
const searchQuery = ref('');
const selectedLevel = ref('');
const autoRefresh = ref(false);
const expandedLogs = ref(new Set());
const selectedLogs = ref(new Set());

const isAllSelected = computed(() => {
    return logs.value.length > 0 && selectedLogs.value.size === logs.value.length;
});

const isIndeterminate = computed(() => {
    return selectedLogs.value.size > 0 && selectedLogs.value.size < logs.value.length;
});

const perPage = ref(20);
const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 20
});

const jumpToPage = ref(1);

watch(() => pagination.value.current_page, (newPage) => {
    jumpToPage.value = newPage;
});

const logStats = computed(() => {
    const total = pagination.value.total;
    if (total === 0) return { from: 0, to: 0, total: 0 };
    
    const page = pagination.value.current_page;
    const perPageVal = pagination.value.per_page;
    const from = (page - 1) * perPageVal + 1;
    const to = Math.min(page * perPageVal, total);
    
    return { from, to, total };
});

const handlePageJump = () => {
    const page = parseInt(jumpToPage.value);
    if (page && page >= 1 && page <= pagination.value.last_page) {
        fetchLogs(page);
        jumpToPage.value = '';
    } else {
        toast.error(`Please enter a page between 1 and ${pagination.value.last_page}`);
    }
};

// Levels
const logLevels = [
    { value: 'debug', label: 'Debug', class: 'text-gray-600 bg-gray-100 dark:bg-gray-800 dark:text-gray-400' },
    { value: 'info', label: 'Info', class: 'text-blue-600 bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400' },
    { value: 'notice', label: 'Notice', class: 'text-cyan-600 bg-cyan-100 dark:bg-cyan-900/30 dark:text-cyan-400' },
    { value: 'warning', label: 'Warning', class: 'text-orange-600 bg-orange-100 dark:bg-orange-900/30 dark:text-orange-400' },
    { value: 'error', label: 'Error', class: 'text-red-600 bg-red-100 dark:bg-red-900/30 dark:text-red-400' },
    { value: 'critical', label: 'Critical', class: 'text-red-700 bg-red-200 dark:bg-red-900/50 dark:text-red-300' },
    { value: 'alert', label: 'Alert', class: 'text-purple-600 bg-purple-100 dark:bg-purple-900/30 dark:text-purple-400' },
    { value: 'emergency', label: 'Emergency', class: 'text-red-800 bg-red-300 dark:bg-red-900/70 dark:text-red-200' },
];

const getLevelStyle = (level) => {
    return logLevels.find(l => l.value === level)?.class || 'text-gray-600 bg-gray-100';
};

const getLevelIcon = (level) => {
    switch (level) {
        case 'info': return Info;
        case 'warning': return AlertTriangle;
        case 'error': 
        case 'critical': 
        case 'alert': 
        case 'emergency': return XCircle;
        default: return AlertCircle;
    }
};

// Fetch list of files
const fetchFiles = async () => {
    isLoadingFiles.value = true;
    try {
        const response = await api.get('/api/system-logs');
        files.value = response.data.data;
        if (files.value.length > 0 && !selectedFile.value) {
            selectedFile.value = files.value[0].name;
            fetchLogs();
        }
    } catch (error) {
        toast.error('Failed to load log files');
    } finally {
        isLoadingFiles.value = false;
    }
};

// Fetch logs for selected file
const fetchLogs = debounce(async (page = 1) => {
    if (!selectedFile.value) return;
    
    isLoading.value = true;
    logs.value = []; // Clear logs to trigger exit transition
    expandedLogs.value.clear(); // Clear expanded state
    selectedLogs.value.clear(); // Clear selection on fetch
    try {
        const params = {
            file: selectedFile.value,
            page,
            per_page: perPage.value,
            search: searchQuery.value,
            level: selectedLevel.value
        };
        
        const response = await api.get('/api/system-logs/file', { params });
        logs.value = response.data.data;
        pagination.value = response.data.meta;
        
        // Reset expanded state on new fetch
        expandedLogs.value.clear();
    } catch (error) {
        toast.error('Failed to load logs');
    } finally {
        isLoading.value = false;
    }
}, 300);

const downloadLog = () => {
    if (!selectedFile.value) return;
    window.open(`/api/system-logs/download?file=${selectedFile.value}`, '_blank');
};

const toggleExpand = (index) => {
    if (expandedLogs.value.has(index)) {
        expandedLogs.value.delete(index);
    } else {
        expandedLogs.value.add(index);
    }
};

const toggleSelectAll = (checked) => {
    if (checked) {
        logs.value.forEach((_, index) => selectedLogs.value.add(index));
    } else {
        selectedLogs.value.clear();
    }
};

const toggleSelect = (index) => {
    if (selectedLogs.value.has(index)) {
        selectedLogs.value.delete(index);
    } else {
        selectedLogs.value.add(index);
    }
};

const exportSelected = () => {
    if (selectedLogs.value.size === 0) return;

    const selectedData = logs.value.filter((_, index) => selectedLogs.value.has(index));
    const textContent = selectedData.map(log => 
        `[${log.timestamp}] [${log.env}] [${log.level}] ${log.message}\n${log.stack_trace || ''}`
    ).join('\n---\n');

    const blob = new Blob([textContent], { type: 'text/plain' });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `selected_logs_${new Date().toISOString()}.log`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
    
    toast.success(`Exported ${selectedLogs.value.size} logs`);
};



const copyToClipboard = async (text, label = 'Content') => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`${label} copied to clipboard`);
    } catch (err) {
        toast.error('Failed to copy to clipboard');
    }
};

const formatTime = (timestamp) => {
    return timestamp; // Already formatted in controller? If not, new Date(timestamp)...
};

// Watchers
watch([selectedFile, selectedLevel, searchQuery, perPage], () => {
    fetchLogs(1);
});

// Auto-refresh interval
let refreshInterval;
watch(autoRefresh, (enabled) => {
    if (enabled) {
        refreshInterval = setInterval(() => fetchLogs(pagination.value.current_page), 5000);
    } else {
        clearInterval(refreshInterval);
    }
});

onMounted(() => {
    fetchFiles();
});
</script>

<template>
    <div class="space-y-4">
        <!-- Toolbar -->
        <div class="flex flex-col xl:flex-row gap-4 justify-between items-start xl:items-center bg-[var(--surface-elevated)] p-4 rounded-xl border border-[var(--border-default)]">
            <div class="flex flex-col sm:flex-row gap-4 w-full xl:w-auto">
                <!-- File Selector -->
                <div class="w-full sm:w-64">
                    <SelectFilter
                        v-model="selectedFile"
                        :options="files.map(f => ({ value: f.name, label: `${f.name} (${(f.size / 1024).toFixed(1)} KB)` }))"
                        placeholder="Select Log File"
                    />
                </div>
                
                <!-- Level Filter -->
                <div class="w-full sm:w-48">
                    <SelectFilter
                        v-model="selectedLevel"
                        :options="logLevels"
                        placeholder="All Levels"
                    />
                </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-4 w-full xl:w-auto">
                <!-- Search -->
                <div class="flex-1 sm:w-64">
                    <SearchInput
                        v-model="searchQuery"
                        placeholder="Search logs..."
                    />
                </div>
                
                <!-- Per Page -->
                <div class="w-full sm:w-auto min-w-[100px]">
                     <SelectFilter
                        v-model="perPage"
                        :options="[
                            { value: 20, label: '20' },
                            { value: 50, label: '50' },
                            { value: 100, label: '100' },
                            { value: 200, label: '200' }
                        ]"
                        :show-placeholder="false"
                    />
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="icon"
                        @click="autoRefresh = !autoRefresh"
                        :class="{ 'bg-blue-50 text-blue-600 border-blue-200': autoRefresh }"
                        title="Auto-refresh (5s)"
                    >
                        <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': autoRefresh || isLoading }" />
                    </Button>
                    <Button 
                        v-if="selectedLogs.size > 0"
                        variant="outline" 
                        @click="exportSelected"
                        class="animate-in fade-in slide-in-from-right-4 bg-[var(--surface-tertiary)]"
                    >
                        <Download class="w-4 h-4 mr-2" />
                        Export ({{ selectedLogs.size }})
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        @click="downloadLog"
                        title="Download Log"
                    >
                        <Download class="w-4 h-4" />
                    </Button>
                </div>
            </div>
        </div>

        <!-- Log Viewer -->
        <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden min-h-[500px] flex flex-col">
            <!-- Logs List -->
            <div class="flex-1">
                 <table class="w-full text-left text-sm font-mono">
                    <thead class="bg-[var(--surface-secondary)] text-[var(--text-secondary)] border-b border-[var(--border-default)]">
                        <tr>
                            <th class="px-4 py-3 w-16">
                                <div class="flex items-center gap-2">
                                    <Checkbox 
                                        :model-value="isAllSelected"
                                        :indeterminate="isIndeterminate"
                                        @update:model-value="toggleSelectAll"
                                    />
                                </div>
                            </th>
                            <th class="px-4 py-3 w-40">Timestamp</th>
                            <th class="px-4 py-3 w-24">Env</th>
                            <th class="px-4 py-3 w-24">Level</th>
                            <th class="px-4 py-3">Message</th>
                        </tr>
                    </thead>
                    <Transition name="list" mode="out-in">
                        <tbody v-if="isLoading" key="loading">
                             <tr>
                                <td colspan="5" class="px-4 py-12 text-center text-[var(--text-muted)]">
                                    <div class="flex items-center justify-center gap-2">
                                        <RefreshCw class="w-5 h-5 animate-spin" />
                                        <span>Loading logs...</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tbody v-else-if="logs.length === 0" key="empty">
                             <tr>
                                <td colspan="5" class="px-4 py-12 text-center text-[var(--text-muted)]">
                                    No logs found.
                                </td>
                            </tr>
                        </tbody>
                        <tbody v-else key="content" class="divide-y divide-[var(--border-default)]">
                             <template v-for="(log, index) in logs" :key="`log-${log.timestamp}-${index}`">
                                <tr 
                                    class="hover:bg-[var(--surface-secondary)]/50 cursor-pointer transition-colors"
                                    @click="toggleExpand(index)"
                                    :class="{ 'bg-[var(--surface-secondary)]/30': expandedLogs.has(index) }"
                                >
                                    <td class="px-4 py-3 text-center" @click.stop>
                                        <div class="flex items-center gap-3">
                                            <Checkbox 
                                                :model-value="selectedLogs.has(index)"
                                                @update:model-value="() => toggleSelect(index)"
                                            />
                                            <div class="flex items-center gap-1">
                                                <ChevronRight 
                                                    class="w-4 h-4 text-[var(--text-muted)] transition-transform duration-200"
                                                    :class="{ 'rotate-90': expandedLogs.has(index) }"
                                                />
                                                <button 
                                                    @click.stop="copyToClipboard(`[${log.timestamp}] [${log.env}] [${log.level}] ${log.message}\n${log.stack_trace || ''}`, 'Full Log')"
                                                    class="p-1 hover:bg-[var(--surface-tertiary)] rounded text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-all duration-200 hover:scale-110 active:scale-95"
                                                    title="Copy Log Entry"
                                                >
                                                    <Copy class="w-3 h-3" />
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-[var(--text-secondary)] whitespace-nowrap">
                                        {{ formatTime(log.timestamp) }}
                                    </td>
                                    <td class="px-4 py-3 text-[var(--text-secondary)]">
                                        <span class="px-2 py-0.5 rounded textxs bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                                            {{ log.env }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span 
                                            :class="['px-2 py-0.5 rounded text-xs font-medium uppercase border border-transparent', getLevelStyle(log.level)]"
                                        >
                                            {{ log.level }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 max-w-xl">
                                        <div class="truncate" :title="log.message">
                                            {{ log.message }}
                                        </div>
                                    </td>
                                </tr>
                                <!-- Details/Stack Trace Row -->
                                <tr v-if="expandedLogs.has(index)">
                                    <td colspan="5" class="px-4 py-4 bg-[var(--surface-tertiary)]/30 border-b border-[var(--border-default)]">
                                        <Transition name="expand" appear>
                                            <div class="space-y-4">
                                            <div>
                                                <div class="flex items-center gap-2 mb-2">
                                                    <h4 class="text-xs font-semibold text-[var(--text-secondary)] uppercase tracking-wider">Message</h4>
                                                    <button 
                                                        @click="copyToClipboard(log.message, 'Message')"
                                                        class="text-xs text-[var(--text-muted)] hover:text-[var(--text-primary)] flex items-center gap-1 transition-all duration-200 hover:scale-105 active:scale-95"
                                                    >
                                                        <Copy class="w-3 h-3" /> Copy
                                                    </button>
                                                </div>
                                                <p class="text-sm text-[var(--text-primary)] whitespace-pre-wrap">{{ log.message }}</p>
                                            </div>
                                            <div v-if="log.stack_trace">
                                                <div class="flex items-center gap-2 mb-2">
                                                    <h4 class="text-xs font-semibold text-[var(--text-secondary)] uppercase tracking-wider">Stack Trace / Context</h4>
                                                    <button 
                                                        @click="copyToClipboard(log.stack_trace, 'Stack Trace')"
                                                        class="text-xs text-[var(--text-muted)] hover:text-[var(--text-primary)] flex items-center gap-1 transition-all duration-200 hover:scale-105 active:scale-95"
                                                    >
                                                        <Copy class="w-3 h-3" /> Copy
                                                    </button>
                                                </div>
                                                <pre class="text-xs bg-[var(--surface-primary)] p-4 rounded-lg border border-[var(--border-default)] overflow-x-auto overflow-y-auto max-h-96 text-[var(--text-secondary)]">{{ log.stack_trace }}</pre>
                                            </div>
                                        </div>
                                        </Transition>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </Transition>
                 </table>
            </div>

            <!-- Pagination -->
            <div class="px-4 py-3 border-t border-[var(--border-default)] bg-[var(--surface-elevated)]">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-xs text-[var(--text-tertiary)]">
                         Showing <span class="font-medium text-[var(--text-primary)]">{{ logStats.from || 0 }}</span> to <span class="font-medium text-[var(--text-primary)]">{{ logStats.to || 0 }}</span> of <span class="font-medium text-[var(--text-primary)]">{{ logStats.total }}</span> results
                    </div>
                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="fetchLogs(pagination.current_page - 1)"
                            :disabled="pagination.current_page === 1"
                        >
                            Previous
                        </Button>
                        <div class="flex items-center gap-2">
                            <input 
                                v-model="jumpToPage"
                                type="number" 
                                min="1" 
                                :max="pagination.last_page"
                                class="h-8 w-14 text-sm text-center rounded-md border border-[var(--border-default)] bg-[var(--surface-primary)] px-1 focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 focus:border-[var(--interactive-primary)]"
                                @keydown.enter="handlePageJump"
                            />
                            <span class="text-xs text-[var(--text-secondary)]">of {{ pagination.last_page }}</span>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="fetchLogs(pagination.current_page + 1)"
                            :disabled="pagination.current_page === pagination.last_page"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.expand-enter-active,
.expand-leave-active {
    transition: all 0.3s ease-out;
    max-height: 500px;
    opacity: 1;
}

.expand-enter-from,
.expand-leave-to {
    max-height: 0;
    opacity: 0;
    transform: translateY(-10px);
}

.list-enter-active,
.list-leave-active {
    transition: all 0.4s cubic-bezier(0.25, 1, 0.5, 1);
}

.list-enter-from,
.list-leave-to {
    opacity: 0;
    transform: translateX(10px);
}
</style>
