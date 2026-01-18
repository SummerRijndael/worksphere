<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue';
import axios from 'axios';
import { format } from 'date-fns';
import Badge from '@/components/ui/Badge.vue';
import Card from '@/components/ui/Card.vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps<{
    filters: Record<string, any>;
}>();

const emit = defineEmits<{
    'update:selected': [tickets: any[]];
}>();

const tickets = ref<any[]>([]);
const meta = ref<any>({});
const loading = ref(false);
const perPage = ref(20);
const selectedTickets = ref<Set<number>>(new Set());
const selectAllCheckbox = ref<HTMLInputElement | null>(null);

// Filter state
const localFilters = ref({
    status: '',
    priority: '',
    assignee: ''
});

// Available filter options (will be fetched from API)
const statuses = ref<any[]>([]);
const priorities = ref<any[]>([]);
const assignees = ref<any[]>([]);

const allSelected = computed({
    get: () => tickets.value.length > 0 && tickets.value.every(t => selectedTickets.value.has(t.id)),
    set: (value: boolean) => {
        if (value) {
            tickets.value.forEach(t => selectedTickets.value.add(t.id));
        } else {
            selectedTickets.value.clear();
        }
        emitSelected();
    }
});

const someSelected = computed(() =>
    tickets.value.some(t => selectedTickets.value.has(t.id)) && !allSelected.value
);

function toggleTicket(ticketId: number) {
    if (selectedTickets.value.has(ticketId)) {
        selectedTickets.value.delete(ticketId);
    } else {
        selectedTickets.value.add(ticketId);
    }
    emitSelected();
}

function emitSelected() {
    const selected = tickets.value.filter(t => selectedTickets.value.has(t.id));
    emit('update:selected', selected);
}

// Fetch filter options
const fetchFilterOptions = async () => {
    try {
        // Fetch statuses
        const statusRes = await axios.get('/api/statuses');
        statuses.value = statusRes.data;

        // Fetch priorities
        const priorityRes = await axios.get('/api/priorities');
        priorities.value = priorityRes.data;

        // Fetch users for assignee filter
        const userRes = await axios.get('/api/users');
        assignees.value = userRes.data;
    } catch (e) {
        console.error('Failed to fetch filter options:', e);
    }
};

// Use same fetch logic
const fetchTickets = async (page = 1) => {
    loading.value = true;
    try {
        const params: Record<string, any> = {
            ...props.filters,
            page,
            per_page: perPage.value
        };

        // Add local filters if they have values
        if (localFilters.value.status) {
            params.status = localFilters.value.status;
        }
        if (localFilters.value.priority) {
            params.priority = localFilters.value.priority;
        }
        if (localFilters.value.assignee) {
            params.assignee_id = localFilters.value.assignee;
        }

        const { data } = await axios.get('/api/tickets', { params });
        tickets.value = data.data;
        meta.value = data.meta;
    } finally {
        loading.value = false;
    }
};

const clearFilters = () => {
    localFilters.value = {
        status: '',
        priority: '',
        assignee: ''
    };
};

watch(() => props.filters, () => fetchTickets(1), { deep: true });
watch(perPage, () => fetchTickets(1));
watch(localFilters, () => fetchTickets(1), { deep: true });

// Update checkbox indeterminate state
watch(someSelected, (value) => {
    if (selectAllCheckbox.value) {
        selectAllCheckbox.value.indeterminate = value;
    }
});

onMounted(() => {
    fetchFilterOptions();
    fetchTickets(1);
});

const getPriorityVariant = (priority: any) => {
    if (priority && typeof priority === 'object' && priority.color) {
        switch (priority.color) {
            case 'ERROR': return 'destructive';
            case 'WARNING': return 'warning';
            case 'SUCCESS': return 'success';
            case 'PRIMARY': return 'default';
            default: return 'secondary';
        }
    }
    const p = typeof priority === 'string' ? priority.toLowerCase() : '';
    switch (p) {
        case 'critical': return 'destructive';
        case 'high': return 'orange';
        case 'medium': return 'amber';
        case 'low': return 'emerald';
        default: return 'secondary';
    }
};

const getStatusVariant = (status: any) => {
    if (status && typeof status === 'object' && status.color) {
        switch (status.color) {
            case 'ERROR': return 'destructive';
            case 'WARNING': return 'warning';
            case 'SUCCESS': return 'success';
            case 'PRIMARY': return 'default'; // In Progress
            default: return 'secondary';
        }
    }
    return 'secondary';
};
</script>

<template>
    <Card padding="none" class="overflow-hidden shadow-sm border border-[var(--border-default)]">
        <div class="px-6 py-4 border-b border-[var(--border-default)] bg-[var(--surface-elevated)]">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-bold text-[var(--text-primary)]">All Tickets Data</h3>
                <select
                    v-model="perPage"
                    class="px-3 py-1.5 text-sm font-medium bg-[var(--surface-base)] text-[var(--text-primary)] border border-[var(--border-default)] rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/20 focus:border-[var(--accent)] [&>option]:bg-[var(--surface-elevated)] [&>option]:text-[var(--text-primary)]"
                >
                    <option :value="20">20 / page</option>
                    <option :value="50">50 / page</option>
                    <option :value="100">100 / page</option>
                    <option :value="200">200 / page</option>
                </select>
            </div>

            <!-- Filters -->
            <div class="flex items-center gap-3 flex-wrap">
                <select
                    v-model="localFilters.status"
                    class="px-3 py-1.5 text-sm font-medium bg-[var(--surface-base)] text-[var(--text-primary)] border border-[var(--border-default)] rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/20 focus:border-[var(--accent)] [&>option]:bg-[var(--surface-elevated)] [&>option]:text-[var(--text-primary)]"
                >
                    <option value="">All Statuses</option>
                    <option v-for="status in statuses" :key="status.id" :value="status.id">
                        {{ status.label }}
                    </option>
                </select>

                <select
                    v-model="localFilters.priority"
                    class="px-3 py-1.5 text-sm font-medium bg-[var(--surface-base)] text-[var(--text-primary)] border border-[var(--border-default)] rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/20 focus:border-[var(--accent)] [&>option]:bg-[var(--surface-elevated)] [&>option]:text-[var(--text-primary)]"
                >
                    <option value="">All Priorities</option>
                    <option v-for="priority in priorities" :key="priority.id" :value="priority.id">
                        {{ priority.label }}
                    </option>
                </select>

                <select
                    v-model="localFilters.assignee"
                    class="px-3 py-1.5 text-sm font-medium bg-[var(--surface-base)] text-[var(--text-primary)] border border-[var(--border-default)] rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/20 focus:border-[var(--accent)] [&>option]:bg-[var(--surface-elevated)] [&>option]:text-[var(--text-primary)]"
                >
                    <option value="">All Assignees</option>
                    <option v-for="assignee in assignees" :key="assignee.id" :value="assignee.id">
                        {{ assignee.name }}
                    </option>
                </select>

                <button
                    v-if="localFilters.status || localFilters.priority || localFilters.assignee"
                    @click="clearFilters"
                    class="px-3 py-1.5 text-sm font-medium bg-[var(--surface-secondary)] text-[var(--text-secondary)] border border-[var(--border-default)] rounded-lg hover:bg-[var(--surface-tertiary)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/20 transition-colors"
                >
                    Clear Filters
                </button>

                <span class="text-sm text-[var(--text-secondary)] ml-auto">
                    {{ meta.total || 0 }} tickets
                </span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100 dark:bg-gray-800/50 border-b-2 border-[var(--border-default)]">
                    <tr>
                        <th class="px-6 py-4 w-12">
                            <input
                                ref="selectAllCheckbox"
                                type="checkbox"
                                :checked="allSelected"
                                @change="allSelected = !allSelected"
                                class="w-4 h-4 rounded border-[var(--border-default)] text-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20"
                                title="Select all"
                            />
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Ticket</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Title</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Priority</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Assignee</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-muted)] bg-[var(--surface-elevated)]">
                     <tr v-if="tickets.length === 0 && !loading">
                        <td colspan="7" class="px-6 py-12 text-center text-[var(--text-muted)]">
                            <div class="flex flex-col items-center gap-2">
                                <svg class="w-12 h-12 text-[var(--text-muted)] opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                                <span class="font-medium">No tickets found</span>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="ticket in tickets" :key="ticket.id" class="hover:bg-[var(--surface-secondary)] transition-colors">
                        <td class="px-6 py-4">
                            <input
                                type="checkbox"
                                :checked="selectedTickets.has(ticket.id)"
                                @change.stop="toggleTicket(ticket.id)"
                                class="w-4 h-4 rounded border-[var(--border-default)] text-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20"
                            />
                        </td>
                        <td class="px-6 py-4 font-mono text-sm text-[var(--text-secondary)] font-medium cursor-pointer" @click="$router.push(`/tickets/${ticket.id}`)">{{ ticket.number || ticket.display_id }}</td>
                        <td class="px-6 py-4 font-semibold text-[var(--text-primary)] cursor-pointer" @click="$router.push(`/tickets/${ticket.id}`)">{{ ticket.title }}</td>
                        <td class="px-6 py-4">
                            <Badge :variant="getStatusVariant(ticket.status)" size="sm">{{ ticket.status.label || ticket.status }}</Badge>
                        </td>
                        <td class="px-6 py-4">
                            <Badge :variant="getPriorityVariant(ticket.priority)" size="sm">{{ ticket.priority.label || ticket.priority }}</Badge>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2.5">
                                <img v-if="ticket.assignee" :src="ticket.assignee.avatar_thumb_url" class="w-6 h-6 rounded-full ring-2 ring-[var(--border-default)]" />
                                <span class="text-[var(--text-primary)] font-medium">{{ ticket.assignee?.name || 'Unassigned' }}</span>
                            </div>
                        </td>
                         <td class="px-6 py-4 text-[var(--text-secondary)]">{{ format(new Date(ticket.created_at), 'MMM d, yyyy') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
         <!-- Footer / Pagination -->
        <div class="px-6 py-4 border-t border-[var(--border-default)] bg-[var(--surface-base)] flex items-center justify-between">
            <span class="text-sm text-[var(--text-secondary)] font-medium">
                Showing page {{ meta.current_page || 1 }} of {{ meta.last_page || 1 }}
            </span>
            <div class="flex gap-2">
                <button
                    @click="fetchTickets(meta.current_page - 1)"
                    :disabled="meta.current_page <= 1"
                    class="px-3 py-1.5 rounded-lg text-sm font-medium bg-[var(--surface-elevated)] text-[var(--text-primary)] border border-[var(--border-default)] hover:bg-[var(--surface-secondary)] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                    <ChevronLeft class="w-4 h-4" />
                </button>
                <button
                    @click="fetchTickets(meta.current_page + 1)"
                    :disabled="meta.current_page >= meta.last_page"
                    class="px-3 py-1.5 rounded-lg text-sm font-medium bg-[var(--surface-elevated)] text-[var(--text-primary)] border border-[var(--border-default)] hover:bg-[var(--surface-secondary)] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                    <ChevronRight class="w-4 h-4" />
                </button>
            </div>
        </div>
    </Card>
</template>
