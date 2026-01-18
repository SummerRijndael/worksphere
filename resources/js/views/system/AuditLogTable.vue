<script setup>
import { ref, onMounted, watch, computed } from 'vue';
import { useRouter } from 'vue-router';
import api from '@/lib/api';
import { debounce } from 'lodash';
import { Button, Badge, SearchInput, SelectFilter } from '@/components/ui';
import {
    Loader2,
    Download,
    Filter,
    X,
    FileText,
    Eye,
    Clock,
    AlertTriangle,
    CheckCircle,
    Shield,
    Key,
    LogIn,
    LogOut,
    UserPlus,
    Edit,
    Trash2,
    RefreshCw,
    Settings
} from 'lucide-vue-next';
import { toast } from 'vue-sonner';

const router = useRouter();

// State
const logs = ref([]);
const isLoading = ref(false);
const isExporting = ref(false);
const searchQuery = ref('');
const actionFilter = ref('');
const categoryFilter = ref('');
const severityFilter = ref('');
const dateRange = ref({ start: '', end: '' });
const perPage = ref(20);
const showFilters = ref(false);

// Filter options from API
const filterOptions = ref({
    actions: [],
    categories: [],
    severities: []
});

// Statistics
const statistics = ref({
    total_logs: 0,
    logs_today: 0,
    critical_count: 0,
    by_category: {}
});

const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 20,
    from: 0,
    to: 0
});

const jumpToPage = ref(1);

watch(() => pagination.value.current_page, (newPage) => {
    jumpToPage.value = newPage;
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

// Icon mapping for actions
const actionIconMap = {
    'login': LogIn,
    'logout': LogOut,
    'login_failed': AlertTriangle,
    'password_reset': Key,
    'password_changed': Key,
    'email_verified': CheckCircle,
    'created': UserPlus,
    'updated': Edit,
    'deleted': Trash2,
    'role_assigned': Shield,
    'role_removed': Shield,
    'permission_granted': Key,
    'permission_revoked': Key,
    'data_exported': Download,
    'settings_changed': Settings,
    'mfa_enabled': Shield,
    'mfa_disabled': Shield,
    'session_revoked': LogOut,
    'account_locked': AlertTriangle,
    'account_unlocked': CheckCircle
};

// Category color mapping
const categoryColors = {
    'authentication': 'bg-blue-500/10 text-blue-600 border-blue-200 dark:border-blue-800 dark:text-blue-400',
    'authorization': 'bg-purple-500/10 text-purple-600 border-purple-200 dark:border-purple-800 dark:text-purple-400',
    'user_management': 'bg-green-500/10 text-green-600 border-green-200 dark:border-green-800 dark:text-green-400',
    'team_management': 'bg-cyan-500/10 text-cyan-600 border-cyan-200 dark:border-cyan-800 dark:text-cyan-400',
    'data_modification': 'bg-orange-500/10 text-orange-600 border-orange-200 dark:border-orange-800 dark:text-orange-400',
    'security': 'bg-red-500/10 text-red-600 border-red-200 dark:border-red-800 dark:text-red-400',
    'system': 'bg-gray-500/10 text-gray-600 border-gray-200 dark:border-gray-700 dark:text-gray-400',
    'api': 'bg-indigo-500/10 text-indigo-600 border-indigo-200 dark:border-indigo-800 dark:text-indigo-400'
};

// Severity color mapping
const severityColors = {
    'debug': 'bg-gray-500/10 text-gray-500 border-gray-200 dark:border-gray-700',
    'info': 'bg-blue-500/10 text-blue-600 border-blue-200 dark:border-blue-800',
    'notice': 'bg-cyan-500/10 text-cyan-600 border-cyan-200 dark:border-cyan-800',
    'warning': 'bg-yellow-500/10 text-yellow-600 border-yellow-200 dark:border-yellow-800',
    'error': 'bg-red-500/10 text-red-600 border-red-200 dark:border-red-800',
    'critical': 'bg-red-600/20 text-red-700 border-red-300 dark:border-red-700 dark:text-red-400',
    'alert': 'bg-orange-600/20 text-orange-700 border-orange-300 dark:border-orange-700 dark:text-orange-400',
    'emergency': 'bg-red-700/30 text-red-800 border-red-400 dark:border-red-600 dark:text-red-300'
};

// Active filters count
const activeFiltersCount = computed(() => {
    let count = 0;
    if (actionFilter.value) count++;
    if (categoryFilter.value) count++;
    if (severityFilter.value) count++;
    if (dateRange.value.start) count++;
    if (dateRange.value.end) count++;
    return count;
});

// Fetch logs
const fetchLogs = debounce(async (page = 1) => {
    isLoading.value = true;
    try {
        const params = {
            page,
            per_page: perPage.value,
            search: searchQuery.value,
            action: actionFilter.value,
            category: categoryFilter.value,
            severity: severityFilter.value,
            date_from: dateRange.value.start,
            date_to: dateRange.value.end
        };

        const cleanParams = Object.fromEntries(
            Object.entries(params).filter(([_, v]) => v != null && v !== '')
        );

        const response = await api.get('/api/audit-logs', { params: cleanParams });
        logs.value = response.data.data;
        pagination.value = {
            current_page: response.data.meta.current_page,
            last_page: response.data.meta.last_page,
            total: response.data.meta.total,
            per_page: response.data.meta.per_page,
            from: response.data.meta.from,
            to: response.data.meta.to
        };
    } catch (error) {
        console.error('Failed to fetch audit logs:', error);
        toast.error('Failed to load audit logs');
    } finally {
        isLoading.value = false;
    }
}, 300);

// Fetch filter options
const fetchFilters = async () => {
    try {
        const response = await api.get('/api/audit-logs/filters');
        filterOptions.value = response.data;
    } catch (error) {
        console.error('Failed to fetch filter options:', error);
    }
};

// Fetch statistics
const fetchStatistics = async () => {
    try {
        const response = await api.get('/api/audit-logs/statistics');
        statistics.value = response.data.data;
    } catch (error) {
        console.error('Failed to fetch statistics:', error);
    }
};

// View log details
const viewLogDetails = (log) => {
    router.push({ name: 'system-log-details', params: { public_id: log.public_id } });
};

// Export logs
const exportLogs = async () => {
    isExporting.value = true;
    try {
        const params = {
            action: actionFilter.value,
            category: categoryFilter.value,
            severity: severityFilter.value,
            date_from: dateRange.value.start,
            date_to: dateRange.value.end,
            search: searchQuery.value,
            limit: 10000
        };

        const cleanParams = Object.fromEntries(
            Object.entries(params).filter(([_, v]) => v != null && v !== '')
        );

        const response = await api.get('/api/audit-logs/export', { params: cleanParams });

        const blob = new Blob([JSON.stringify(response.data.data, null, 2)], { type: 'application/json' });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `audit-logs-${new Date().toISOString().split('T')[0]}.json`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);

        toast.success(`Exported ${response.data.meta.total} audit logs`);
    } catch (error) {
        console.error('Failed to export logs:', error);
        toast.error('Failed to export audit logs');
    } finally {
        isExporting.value = false;
    }
};

// Clear all filters
const clearFilters = () => {
    searchQuery.value = '';
    actionFilter.value = '';
    categoryFilter.value = '';
    severityFilter.value = '';
    dateRange.value = { start: '', end: '' };
};

// Get action icon
const getActionIcon = (action) => {
    return actionIconMap[action] || FileText;
};

// Format action label
const formatActionLabel = (action) => {
    return action.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
};

// Watchers
watch([searchQuery, actionFilter, categoryFilter, severityFilter, perPage, () => dateRange.value.start, () => dateRange.value.end], () => {
    fetchLogs(1);
});

onMounted(() => {
    fetchLogs();
    fetchFilters();
    fetchStatistics();
});
</script>

<template>
    <div class="space-y-6">
        <!-- Toolbar and Stats are layout specific, but Toolbar actions are tied to this component logic -->
        
         <!-- Header / Toolbar Actions -->
         <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <!-- Left side empty in this component, handled by parent if needed -->
             <div></div>
             
            <div class="flex items-center gap-3 w-full sm:w-auto justify-end">
                <Button
                    variant="outline"
                    size="sm"
                    @click="showFilters = !showFilters"
                    :class="{ 'ring-2 ring-[var(--interactive-primary)]': activeFiltersCount > 0 }"
                >
                    <Filter class="w-4 h-4" />
                    Filters
                    <Badge v-if="activeFiltersCount > 0" variant="primary" class="ml-1 h-5 min-w-5 px-1.5">
                        {{ activeFiltersCount }}
                    </Badge>
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    @click="exportLogs"
                    :loading="isExporting"
                >
                    <Download class="w-4 h-4" />
                    Export
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    @click="fetchLogs(pagination.current_page)"
                    title="Refresh"
                >
                    <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': isLoading }" />
                </Button>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-blue-500/10 flex items-center justify-center">
                        <FileText class="w-5 h-5 text-blue-600" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-[var(--text-primary)]">{{ statistics.total_logs?.toLocaleString() || 0 }}</p>
                        <p class="text-xs text-[var(--text-muted)]">Total Logs</p>
                    </div>
                </div>
            </div>
            <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-green-500/10 flex items-center justify-center">
                        <Clock class="w-5 h-5 text-green-600" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-[var(--text-primary)]">{{ statistics.logs_today || 0 }}</p>
                        <p class="text-xs text-[var(--text-muted)]">Today</p>
                    </div>
                </div>
            </div>
            <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-red-500/10 flex items-center justify-center">
                        <AlertTriangle class="w-5 h-5 text-red-600" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-[var(--text-primary)]">{{ statistics.critical_count || 0 }}</p>
                        <p class="text-xs text-[var(--text-muted)]">Critical Events</p>
                    </div>
                </div>
            </div>
            <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-purple-500/10 flex items-center justify-center">
                        <Shield class="w-5 h-5 text-purple-600" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-[var(--text-primary)]">{{ statistics.by_category?.security || 0 }}</p>
                        <p class="text-xs text-[var(--text-muted)]">Security Events</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters Panel -->
        <Transition
            enter-active-class="transition-all duration-200 ease-out"
            leave-active-class="transition-all duration-150 ease-in"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-2"
        >
            <div v-if="showFilters" class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4 space-y-4 overflow-visible relative z-20">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-medium text-[var(--text-primary)]">Filter Logs</h3>
                    <button
                        v-if="activeFiltersCount > 0"
                        @click="clearFilters"
                        class="text-xs text-[var(--text-muted)] hover:text-[var(--text-primary)] flex items-center gap-1"
                    >
                        <X class="w-3 h-3" />
                        Clear all
                    </button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-[var(--text-secondary)]">Action</label>
                        <SelectFilter
                            v-model="actionFilter"
                            :options="filterOptions.actions"
                            placeholder="All Actions"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-[var(--text-secondary)]">Category</label>
                        <SelectFilter
                            v-model="categoryFilter"
                            :options="filterOptions.categories"
                            placeholder="All Categories"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-[var(--text-secondary)]">Severity</label>
                        <SelectFilter
                            v-model="severityFilter"
                            :options="filterOptions.severities"
                            placeholder="All Severities"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-[var(--text-secondary)]">Date Range</label>
                        <div class="flex items-center gap-2">
                            <input v-model="dateRange.start" type="date" class="input h-9 text-sm flex-1" />
                            <span class="text-[var(--text-muted)]">-</span>
                            <input v-model="dateRange.end" type="date" class="input h-9 text-sm flex-1" />
                        </div>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Search Bar -->
        <div class="flex flex-col sm:flex-row gap-4">
            <SearchInput
                v-model="searchQuery"
                placeholder="Search logs by user, action, or IP address..."
                class="flex-1"
            />
            <div class="min-w-[100px]">
                <SelectFilter
                    v-model="perPage"
                    :options="[
                        { value: 20, label: '20' },
                        { value: 50, label: '50' },
                        { value: 100, label: '100' },
                        { value: 200, label: '200' }
                    ]"
                    :show-placeholder="false"
                    size="lg"
                />
            </div>
        </div>

        <!-- Logs Table -->
        <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden flex flex-col h-[calc(100vh-26rem)]">
            <div class="overflow-y-auto flex-1">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[var(--surface-secondary)] text-[var(--text-secondary)] font-medium sticky top-0 z-10 border-b border-[var(--border-default)]">
                        <tr>
                            <th class="px-4 py-3 whitespace-nowrap">Action</th>
                            <th class="px-4 py-3 whitespace-nowrap">Category</th>
                            <th class="px-4 py-3 whitespace-nowrap">User</th>
                            <th class="px-4 py-3 whitespace-nowrap">IP Address</th>
                            <th class="px-4 py-3 whitespace-nowrap">Severity</th>
                            <th class="px-4 py-3 whitespace-nowrap text-right">Time</th>
                            <th class="px-4 py-3 w-16"></th>
                        </tr>
                    </thead>
                    <Transition name="fade" mode="out-in">
                        <tbody v-if="isLoading" key="loading">
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-[var(--text-muted)]">
                                    <div class="flex flex-col items-center gap-2">
                                        <Loader2 class="w-6 h-6 animate-spin" />
                                        <span>Loading logs...</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tbody v-else-if="logs.length === 0" key="empty">
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-[var(--text-muted)]">
                                    <div class="flex flex-col items-center gap-2">
                                        <FileText class="w-8 h-8 opacity-50" />
                                        <span>No audit logs found.</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tbody v-else key="content" class="divide-y divide-[var(--border-default)]">
                            <tr
                                v-for="log in logs"
                                :key="log.public_id"
                                class="group hover:bg-[var(--surface-secondary)]/50 transition-colors cursor-pointer"
                                @click="viewLogDetails(log)"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-lg bg-[var(--surface-tertiary)] flex items-center justify-center text-[var(--text-muted)]">
                                            <component :is="getActionIcon(log.action)" class="w-3.5 h-3.5" />
                                        </div>
                                        <span class="font-medium text-[var(--text-primary)]">{{ formatActionLabel(log.action) }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        :class="[categoryColors[log.category] || categoryColors.system, 'inline-flex px-2 py-0.5 text-xs font-medium rounded-full border capitalize']"
                                    >
                                        {{ log.category?.replace(/_/g, ' ') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-6 h-6 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center text-[10px] font-bold text-[var(--text-muted)]">
                                            {{ log.user_name?.split(' ').map(n => n[0]).slice(0, 2).join('') || '?' }}
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-sm text-[var(--text-primary)]">{{ log.user_name || 'System' }}</span>
                                            <span v-if="log.user_email" class="text-xs text-[var(--text-muted)]">{{ log.user_email }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-[var(--text-secondary)]">
                                    <div>{{ log.context?.ip_address || log.ip_address || '-' }}</div>
                                    <div v-if="log.context?.location" class="text-[var(--text-muted)] truncate max-w-[150px]" :title="`${log.context.location.city}, ${log.context.location.state}, ${log.context.location.country}`">
                                        {{ log.context.location.city }}, {{ log.context.location.iso_code }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        :class="[severityColors[log.severity] || severityColors.info, 'inline-flex px-2 py-0.5 text-xs font-medium rounded border capitalize']"
                                    >
                                        {{ log.severity }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-xs text-[var(--text-secondary)] whitespace-nowrap">
                                    {{ new Date(log.created_at).toLocaleString() }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        class="p-1.5 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors"
                                        @click.stop="viewLogDetails(log)"
                                    >
                                        <Eye class="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </Transition>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-4 py-3 border-t border-[var(--border-default)] bg-[var(--surface-elevated)] flex items-center justify-between">
                <div class="text-xs text-[var(--text-tertiary)]">
                    Showing <span class="font-medium text-[var(--text-primary)]">{{ pagination.from || 0 }}</span> to <span class="font-medium text-[var(--text-primary)]">{{ pagination.to || 0 }}</span> of <span class="font-medium text-[var(--text-primary)]">{{ pagination.total }}</span> results
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
</template>
