<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue';
import axios from 'axios';
import { Loader2, LayoutList, LayoutGrid, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Card from '@/components/ui/Card.vue';
import { format } from 'date-fns';

interface Props {
    title: string;
    filters: Record<string, any>; // Specific filters for this list (e.g. status='open')
    globalFilters: Record<string, any>; // Shared filters (search, dates)
}

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:selected': [tickets: any[]];
}>();

const tickets = ref<any[]>([]);
const meta = ref<any>({});
const loading = ref(false);
const perPage = ref(20);
const viewMode = ref<'list' | 'grid'>('list');
const selectedTickets = ref<Set<number>>(new Set());
const selectAllCheckbox = ref<HTMLInputElement | null>(null);

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

const fetchTickets = async (page = 1) => {
    loading.value = true;
    try {
        const params = {
            ...props.filters,
            ...props.globalFilters,
            page,
            per_page: perPage.value
        };
        const { data } = await axios.get('/api/tickets', { params });
        tickets.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
};

watch(() => props.globalFilters, () => {
    fetchTickets(1);
}, { deep: true });

watch(perPage, () => fetchTickets(1));

// Update checkbox indeterminate state
watch(someSelected, (value) => {
    if (selectAllCheckbox.value) {
        selectAllCheckbox.value.indeterminate = value;
    }
});

onMounted(() => {
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
</script>

<template>
    <Card padding="none" class="flex flex-col h-full overflow-hidden">
        <!-- Header -->
        <div class="p-4 border-b border-[var(--border-default)] bg-[var(--surface-elevated)] flex items-center justify-between">
            <div class="flex items-center gap-3">
                <input
                    ref="selectAllCheckbox"
                    type="checkbox"
                    :checked="allSelected"
                    @change="allSelected = !allSelected"
                    class="w-4 h-4 rounded border-[var(--border-default)] text-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20"
                    title="Select all"
                />
                <h3 class="font-semibold text-[var(--text-primary)] flex items-center gap-2">
                    {{ title }}
                    <Badge variant="neutral" size="sm" class="ml-1">{{ meta.total || 0 }}</Badge>
                </h3>
            </div>

            <div class="flex items-center gap-2">
                <!-- View Toggle -->
                <div class="flex items-center bg-[var(--surface-secondary)] rounded-lg p-0.5">
                    <button
                        @click="viewMode = 'list'"
                        class="p-1.5 rounded-md transition-all"
                        :class="viewMode === 'list' ? 'bg-[var(--surface-elevated)] shadow-sm text-[var(--text-primary)]' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'"
                    >
                        <LayoutList class="w-4 h-4" />
                    </button>
                    <button
                        @click="viewMode = 'grid'"
                        class="p-1.5 rounded-md transition-all"
                        :class="viewMode === 'grid' ? 'bg-[var(--surface-elevated)] shadow-sm text-[var(--text-primary)]' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'"
                    >
                        <LayoutGrid class="w-4 h-4" />
                    </button>
                </div>

                <!-- Per Page -->
                <select
                    v-model="perPage"
                    class="h-8 px-2 text-xs font-medium bg-[var(--surface-base)] text-[var(--text-primary)] border border-[var(--border-default)] rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/20 focus:border-[var(--accent)] [&>option]:bg-[var(--surface-elevated)] [&>option]:text-[var(--text-primary)]"
                >
                    <option :value="20">20</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                    <option :value="200">200</option>
                </select>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-auto max-h-[500px] min-h-[300px] relative bg-[var(--surface-elevated)]">
            <div v-if="loading" class="absolute inset-0 flex items-center justify-center bg-[var(--surface-base)]/50 backdrop-blur-sm z-10">
                <Loader2 class="w-6 h-6 animate-spin text-[var(--accent)]" />
            </div>

            <div v-if="tickets.length === 0 && !loading" class="flex flex-col items-center justify-center h-full text-[var(--text-muted)] p-8">
                <svg class="w-12 h-12 mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                </svg>
                <p class="font-medium">No tickets found</p>
            </div>

            <!-- List View -->
            <div v-else-if="viewMode === 'list'" class="divide-y divide-[var(--border-muted)]">
                <div
                    v-for="ticket in tickets"
                    :key="ticket.id"
                    class="p-3 hover:bg-[var(--surface-secondary)] transition-colors group"
                >
                    <div class="flex items-start gap-3">
                        <input
                            type="checkbox"
                            :checked="selectedTickets.has(ticket.id)"
                            @change.stop="toggleTicket(ticket.id)"
                            class="mt-0.5 w-4 h-4 rounded border-[var(--border-default)] text-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20"
                        />
                        <div class="flex items-start justify-between gap-3 flex-1 cursor-pointer" @click="$router.push(`/tickets/${ticket.id}`)">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="font-mono text-xs text-[var(--text-secondary)] font-medium">{{ ticket.number || ticket.display_id }}</span>
                                    <Badge :variant="getPriorityVariant(ticket.priority)" size="sm">{{ ticket.priority.label || ticket.priority }}</Badge>
                                </div>
                                <h4 class="text-sm font-medium text-[var(--text-primary)] truncate group-hover:text-[var(--accent)] transition-colors">
                                    {{ ticket.title }}
                                </h4>
                            </div>
                            <div class="flex-shrink-0 text-right">
                                <div class="text-xs text-[var(--text-secondary)] mb-1">{{ format(new Date(ticket.created_at), 'MMM d') }}</div>
                                <img
                                    v-if="ticket.assignee"
                                    :src="ticket.assignee.avatar_thumb_url || ticket.assignee.avatar_url"
                                    :alt="ticket.assignee.name"
                                    class="w-5 h-5 rounded-full ring-1 ring-[var(--border-default)] ml-auto"
                                    :title="`Assigned to ${ticket.assignee.name}`"
                                />
                                <div v-else class="w-5 h-5 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center ring-1 ring-[var(--border-default)] ml-auto">
                                    <span class="text-[10px] text-[var(--text-muted)]">?</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grid View -->
            <div v-else class="p-4 grid grid-cols-2 gap-3">
                 <div
                    v-for="ticket in tickets"
                    :key="ticket.id"
                    class="p-3 bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-default)] hover:border-[var(--accent)]/50 hover:shadow-sm transition-all flex flex-col gap-2 relative"
                >
                    <input
                        type="checkbox"
                        :checked="selectedTickets.has(ticket.id)"
                        @change.stop="toggleTicket(ticket.id)"
                        class="absolute top-2 left-2 w-4 h-4 rounded border-[var(--border-default)] text-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20 z-10"
                    />
                    <div class="cursor-pointer" @click="$router.push(`/tickets/${ticket.id}`)">
                        <div class="flex items-center justify-between pl-6">
                             <span class="font-mono text-xs text-[var(--text-secondary)] font-medium">{{ ticket.number || ticket.display_id }}</span>
                             <Badge :variant="getPriorityVariant(ticket.priority)" size="sm">{{ ticket.priority.label || ticket.priority }}</Badge>
                        </div>
                         <h4 class="text-sm font-medium text-[var(--text-primary)] line-clamp-2 mt-2">
                            {{ ticket.title }}
                        </h4>
                        <div class="mt-auto pt-2 flex items-center justify-between text-xs text-[var(--text-secondary)] border-t border-[var(--border-muted)]">
                            <span>{{ format(new Date(ticket.created_at), 'MMM d') }}</span>
                             <img
                                v-if="ticket.assignee"
                                :src="ticket.assignee.avatar_thumb_url || ticket.assignee.avatar_url"
                                class="w-5 h-5 rounded-full ring-1 ring-[var(--border-default)]"
                            />
                            <div v-else class="w-5 h-5 rounded-full bg-[var(--surface-tertiary)] ring-1 ring-[var(--border-default)]"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer / Pagination -->
        <div class="px-3 py-2 border-t border-[var(--border-default)] bg-[var(--surface-base)] flex items-center justify-between text-xs">
            <span class="text-[var(--text-secondary)] font-medium">
                Page {{ meta.current_page || 1 }} of {{ meta.last_page || 1 }}
            </span>
            <div class="flex gap-1">
                <button
                    @click="fetchTickets(meta.current_page - 1)"
                    :disabled="meta.current_page <= 1"
                    class="p-1.5 rounded-lg text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                    <ChevronLeft class="w-4 h-4" />
                </button>
                <button
                    @click="fetchTickets(meta.current_page + 1)"
                    :disabled="meta.current_page >= meta.last_page"
                    class="p-1.5 rounded-lg text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                    <ChevronRight class="w-4 h-4" />
                </button>
            </div>
        </div>
    </Card>
</template>
