<script setup>
import { ref } from 'vue';
import { Button, Checkbox } from '@/components/ui';
import { 
    LayoutGrid, 
    List, 
    User,
    Mail,
    Phone,
    Trash2,
    Edit2,
    ChevronLeft,
    ChevronRight,
    Building2
} from 'lucide-vue-next';
import { useAuthStore } from '@/stores/auth';

const props = defineProps({
    clients: {
        type: Array,
        required: true
    },
    loading: {
        type: Boolean,
        default: false
    },
    viewMode: {
        type: String,
        default: 'grid'
    },
    pagination: {
        type: Object,
        required: true
    },
    selectedClients: {
        type: Object, // Set
        default: () => new Set()
    }
});

const emit = defineEmits([
    'view',
    'edit', 
    'delete', 
    'update:viewMode', 
    'page-change', 
    'toggle-selection', 
    'toggle-all'
]);

const authStore = useAuthStore();

// Permissions
const can = (permission) => {
    if (authStore.user?.roles?.some(r => r.name === 'administrator')) {
        return true;
    }
    return authStore.user?.permissions?.some(p => p.name === permission) || false;
};

// Selection Helpers (Pass through events ideally, but for UI state we might need local or pass prop)
const toggleSelection = (id) => emit('toggle-selection', id);
const toggleAll = (e) => emit('toggle-all', e.target.checked);

</script>

<template>
    <div class="relative min-h-[400px]">
        <Transition name="fade" mode="out-in">
            <!-- Loading State -->
            <div v-if="loading" key="loading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div v-for="i in 6" :key="i" class="animate-pulse bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] p-6 h-[200px]"></div>
            </div>

            <!-- Empty State -->
            <div v-else-if="clients.length === 0" key="empty" class="text-center py-20">
                <div class="bg-[var(--surface-secondary)] w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                    <Building2 class="w-8 h-8 text-[var(--text-muted)]" />
                </div>
                <h3 class="text-lg font-medium text-[var(--text-primary)]">No clients found</h3>
                <p class="text-[var(--text-secondary)] mt-1">Try adjusting your filters or create a new client</p>
                <!-- NOTE: 'Add Client' button logic is usually in parent, or we can emit 'create' -->
                <slot name="empty-actions"></slot>
            </div>

            <!-- Data State -->
            <div v-else key="data">
                <Transition name="fade" mode="out-in">
                    <!-- Grid View -->
                    <div v-if="viewMode === 'grid'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <TransitionGroup name="list" appear>
                            <div v-for="client in clients" :key="client.public_id" 
                                class="group bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] hover:border-[var(--border-default)] transition-all hover:shadow-md p-6 relative cursor-pointer"
                                @click="$emit('view', client)"
                            >
                                <div class="absolute top-4 right-4 transition-opacity">
                                    <div class="flex items-center gap-1 bg-[var(--surface-elevated)] rounded-lg shadow-sm border border-[var(--border-muted)] p-1">
                                        <button v-if="can('clients.update')" @click.stop="$emit('edit', client)" class="p-1.5 text-[var(--text-muted)] hover:text-[var(--interactive-primary)] rounded-md transition-colors">
                                            <Edit2 class="w-4 h-4" />
                                        </button>
                                        <button v-if="can('clients.delete')" @click.stop="$emit('delete', client)" class="p-1.5 text-[var(--text-muted)] hover:text-[var(--color-error)] rounded-md transition-colors">
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>

                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="h-12 w-12 rounded-lg bg-[var(--surface-secondary)] flex items-center justify-center text-lg font-bold text-[var(--text-secondary)] uppercase">
                                            {{ client.initials }}
                                        </div>
                                        <div>
                                            <h3 class="font-medium text-[var(--text-primary)] line-clamp-1">{{ client.name }}</h3>
                                            <span 
                                                class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium mt-1"
                                                :class="client.status === 'active' ? 'bg-green-100 text-green-900 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400'"
                                            >
                                                {{ client.status }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-3 text-sm text-[var(--text-secondary)]">
                                    <div class="flex items-center gap-2" v-if="client.contact_person">
                                        <User class="w-4 h-4 text-[var(--text-muted)]" />
                                        <span class="truncate">{{ client.contact_person }}</span>
                                    </div>
                                    <div class="flex items-center gap-2" v-if="client.email">
                                        <Mail class="w-4 h-4 text-[var(--text-muted)]" />
                                        <span class="truncate">{{ client.email }}</span>
                                    </div>
                                    <div class="flex items-center gap-2" v-if="client.phone">
                                        <Phone class="w-4 h-4 text-[var(--text-muted)]" />
                                        <span class="truncate">{{ client.phone }}</span>
                                    </div>
                                </div>
                            </div>
                        </TransitionGroup>
                    </div>

                    <!-- List View -->
                    <div v-else class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-[var(--surface-secondary)] border-b border-[var(--border-default)]">
                                    <tr>
                                        <th class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] w-[50px] border border-[var(--border-default)]">
                                            <Checkbox :checked="selectedClients.size === clients.length && clients.length > 0" @change="toggleAll" />
                                        </th>
                                        <th class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] border border-[var(--border-default)]">Company</th>
                                        <th class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] border border-[var(--border-default)]">Contact</th>
                                    <th v-if="can('clients.manage_any_team')" class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] border border-[var(--border-default)]">Team</th>
                                        <th class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] border border-[var(--border-default)]">Status</th>
                                        <th class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] border border-[var(--border-default)]">Details</th>
                                        <th class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] border border-[var(--border-default)]">Created</th>
                                        <th class="px-6 py-3 text-right font-medium text-[var(--text-secondary)] w-[100px] border border-[var(--border-default)]">Actions</th>
                                    </tr>
                                </thead>
                                <TransitionGroup tag="tbody" name="list" appear>
                                    <tr v-for="client in clients" :key="client.public_id" 
                                        class="group border-b border-[var(--border-default)] last:border-0 hover:bg-[var(--surface-secondary)]/50 transition-colors cursor-pointer"
                                        @click="$emit('view', client)"
                                    >
                                        <td class="px-6 py-3 border border-[var(--border-default)]">
                                            <Checkbox :checked="selectedClients.has(client.public_id)" @change="toggleSelection(client.public_id)" @click.stop />
                                        </td>
                                        <td class="px-6 py-3 border border-[var(--border-default)]">
                                            <div class="flex items-center gap-3">
                                                <div class="h-8 w-8 rounded bg-[var(--surface-secondary)] flex items-center justify-center text-xs font-bold text-[var(--text-secondary)] uppercase">
                                                    {{ client.initials }}
                                                </div>
                                                <div>
                                                    <div class="font-medium text-[var(--text-primary)]">{{ client.name }}</div>
                                                    <div class="text-xs text-[var(--text-muted)]">{{ client.slug }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-3 border border-[var(--border-default)]">
                                            <div v-if="client.contact_person" class="font-medium text-[var(--text-primary)]">{{ client.contact_person }}</div>
                                            <div v-else class="text-[var(--text-muted)]">-</div>
                                        </td>
                                        <td v-if="can('clients.manage_any_team')" class="px-6 py-3 border border-[var(--border-default)]">
                                            <div v-if="client.team" class="flex items-center gap-2">
                                                <div class="h-6 w-6 rounded bg-[var(--surface-tertiary)] flex items-center justify-center text-[10px] font-bold text-[var(--text-secondary)] uppercase">
                                                    {{ client.team.name.substring(0, 2) }}
                                                </div>
                                                <span class="text-sm text-[var(--text-primary)]">{{ client.team.name }}</span>
                                            </div>
                                            <span v-else class="text-[var(--text-muted)] text-xs">Global/Unassigned</span>
                                        </td>
                                        <td class="px-6 py-3 border border-[var(--border-default)]">
                                            <span 
                                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                                                :class="client.status === 'active' ? 'bg-green-100 text-green-900 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400'"
                                            >
                                                {{ client.status }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-3 border border-[var(--border-default)]">
                                            <div class="space-y-1 text-xs">
                                                 <div v-if="client.email" class="flex items-center gap-1.5 text-[var(--text-secondary)]">
                                                    <Mail class="w-3.5 h-3.5" />
                                                    {{ client.email }}
                                                </div>
                                                 <div v-if="client.phone" class="flex items-center gap-1.5 text-[var(--text-secondary)]">
                                                    <Phone class="w-3.5 h-3.5" />
                                                    {{ client.phone }}
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-3 text-[var(--text-secondary)] border border-[var(--border-default)]">
                                            {{ new Date(client.created_at).toLocaleDateString() }}
                                        </td>
                                        <td class="px-6 py-3 text-right border border-[var(--border-default)]">
                                            <div class="flex items-center justify-end gap-2 transition-opacity">
                                                <button v-if="can('clients.update')" @click.stop="$emit('edit', client)" class="p-1.5 text-[var(--text-muted)] hover:text-[var(--interactive-primary)] rounded transition-colors">
                                                    <Edit2 class="w-4 h-4" />
                                                </button>
                                                <button v-if="can('clients.delete')" @click.stop="$emit('delete', client)" class="p-1.5 text-[var(--text-muted)] hover:text-[var(--color-error)] rounded transition-colors">
                                                    <Trash2 class="w-4 h-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </TransitionGroup>
                            </table>
                        </div>
                    </div>
                </Transition>
                 <!-- Pagination -->
                <div class="flex items-center justify-between border-t border-[var(--border-muted)] pt-4 mt-6">
                    <p class="text-sm text-[var(--text-secondary)]">
                        Showing <span class="font-medium text-[var(--text-primary)]">{{ (pagination.currentPage - 1) * pagination.perPage + 1 }}</span> to <span class="font-medium text-[var(--text-primary)]">{{ Math.min(pagination.currentPage * pagination.perPage, pagination.total) }}</span> of <span class="font-medium text-[var(--text-primary)]">{{ pagination.total }}</span> results
                    </p>
                    <div class="flex gap-2">
                        <Button 
                            variant="outline" 
                            size="sm" 
                            :disabled="pagination.currentPage === 1"
                            @click="$emit('page-change', pagination.currentPage - 1)"
                        >
                            <ChevronLeft class="w-4 h-4" />
                        </Button>
                        <Button 
                            variant="outline" 
                            size="sm" 
                            :disabled="pagination.currentPage === pagination.lastPage"
                            @click="$emit('page-change', pagination.currentPage + 1)"
                        >
                            <ChevronRight class="w-4 h-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>
