<script setup>
import { ref, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import debounce from 'lodash/debounce';
import api from '@/lib/api';
import { Button, SelectFilter, SearchInput, StatsCard } from '@/components/ui';
import ClientList from '@/components/clients/ClientList.vue';
import ClientFormModal from '@/components/clients/ClientFormModal.vue';
import { 
    LayoutGrid, 
    List, 
    Plus,
    Users,
    Activity,
    Briefcase
} from 'lucide-vue-next';

import { useAuthStore } from '@/stores/auth';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();

// Helper to check permissions
const can = (permission) => {
    // Check if user is administrator
    if (authStore.user?.roles?.some(r => r.name === 'administrator')) {
        return true;
    }
    // Check specific permission
    return authStore.user?.permissions?.some(p => p.name === permission) || false;
};

// State
const clients = ref([]);
const stats = ref({
    total: 0,
    active: 0,
    total_projects: 0
});
const isLoading = ref(true);
const viewMode = ref(localStorage.getItem('clients_view_mode') || 'grid');
const searchQuery = ref('');
const statusFilter = ref('');
const teamFilter = ref(''); // Admin only
const selectedClients = ref(new Set());
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const clientToDelete = ref(null);
const clientToEdit = ref(null);
const pagination = ref({
    currentPage: 1,
    lastPage: 1,
    total: 0,
    perPage: 15
});

// Admin Team Options
const teamOptions = ref([]);

// Fetch stats
const fetchStats = async () => {
    try {
        const response = await api.get('/api/clients/stats');
        stats.value = response.data;
    } catch (error) {
        console.error('Failed to fetch client stats', error);
    }
};

const fetchTeams = async () => {
    if (!can('clients.manage_any_team')) return;
    try {
        const response = await api.get('/api/teams?per_page=100');
        const teams = response.data.data.map(t => ({
            label: t.name,
            value: t.id
        }));
        teamOptions.value = [{ label: 'All Teams', value: '' }, ...teams];
    } catch (e) {
        console.error('Failed to fetch teams', e);
    }
};

// Form Data (Handled by Component now, but we track Modal State)
const isSubmitting = ref(false);

// Methods
const fetchClients = async (page = 1) => {
    isLoading.value = true;
    try {
        const params = {
            page,
            search: searchQuery.value,
            status: statusFilter.value,
            per_page: pagination.value.perPage,
            team_id: teamFilter.value || undefined
        };

        const response = await api.get('/api/clients', { params });
        clients.value = response.data.data;
        
        const meta = response.data.meta || response.data;
        pagination.value = {
            currentPage: meta.current_page,
            lastPage: meta.last_page,
            total: meta.total,
            perPage: meta.per_page
        };
    } catch (error) {
        console.error('Failed to fetch clients:', error);
    } finally {
        isLoading.value = false;
    }
};

const debouncedSearch = debounce(() => {
    fetchClients(1);
}, 300);

const toggleViewMode = (mode) => {
    viewMode.value = mode;
    localStorage.setItem('clients_view_mode', mode);
};

const openCreateModal = () => {
    if (!can('clients.create')) return;
    clientToEdit.value = null; // Reset for create
    showCreateModal.value = true;
};

const openEditModal = (client) => {
    clientToEdit.value = client;
    showCreateModal.value = true; // Use same modal component
};

const confirmDelete = (client) => {
    clientToDelete.value = client;
    showDeleteModal.value = true;
};

const deleteClient = async () => {
    isSubmitting.value = true;
    try {
        await api.delete(`/api/clients/${clientToDelete.value.public_id}`);
        showDeleteModal.value = false;
        fetchClients(pagination.value.currentPage);
        selectedClients.value.delete(clientToDelete.value.public_id);
    } catch (error) {
        console.error('Failed to delete client:', error);
    } finally {
        isSubmitting.value = false;
    }
};

const handlePageChange = (page) => {
    fetchClients(page);
};

const handleViewClient = (client) => {
    const detailRoute = route.name === 'admin-clients' ? 'admin-client-detail' : 'client-detail';
    router.push({ name: detailRoute, params: { public_id: client.public_id } });
};

// Watchers
watch(searchQuery, debouncedSearch);
watch([statusFilter, teamFilter], () => fetchClients(1));

// Handle Query Params (Deep Linking)
const fetchAndOpenClient = async (publicId) => {
    try {
        const response = await api.get(`/api/clients/${publicId}`);
        openEditModal(response.data);
    } catch (e) {
        console.error('Failed to load client', e);
    }
};

watch(() => route.query, (newQuery) => {
    if (newQuery.create === 'true') {
        openCreateModal();
    }
    if (newQuery.view) {
        fetchAndOpenClient(newQuery.view);
    }
}, { immediate: true });

// Lifecycle
onMounted(() => {
    fetchClients();
    fetchStats();
    if (can('clients.manage_any_team')) {
        fetchTeams();
    }
});
</script>

<template>
    <div class="p-6 space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">Clients</h1>
                <p class="text-[var(--text-secondary)] mt-1">Manage your client organizations</p>
            </div>
            <Button v-if="can('clients.create')" @click="openCreateModal" class="gap-2">
                <Plus class="w-4 h-4" />
                Add Client
            </Button>
        </div>

        <!-- Stats Overview -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 animate-fade-in-down" style="animation-delay: 0.1s">
            <StatsCard
                :label="can('clients.manage_any_team') ? 'Total Clients' : 'My Team Clients'"
                :value="stats.total"
                :icon="Users"
                variant="primary"
            />
            <StatsCard
                label="Active Clients"
                :value="stats.active"
                :icon="Activity"
                variant="success"
            />
             <StatsCard
                label="Total Projects"
                :value="stats.total_projects"
                :icon="Briefcase"
                variant="info"
            />
        </div>

        <!-- Filters -->
        <div class="flex flex-col sm:flex-row gap-4 bg-[var(--surface-primary)] p-4 rounded-xl border border-[var(--border-muted)] shadow-sm">
            <div class="flex-1 flex flex-col sm:flex-row gap-3">
                <div class="relative flex-1">
                    <SearchInput 
                        v-model="searchQuery" 
                        placeholder="Search clients..." 
                        class="w-full"
                    />
                </div>
                
                <!-- Admin Team Filter -->
                <div v-if="can('clients.manage_any_team') && teamOptions.length > 0" class="w-[200px]">
                    <SelectFilter
                        v-model="teamFilter"
                        :options="teamOptions"
                        placeholder="Filter by Team"
                        class="h-10 text-sm"
                    />
                </div>

                <div class="flex gap-3">
                    <div class="relative min-w-[140px]">
                        <select v-model="statusFilter" class="input h-10 text-sm">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2 border-l border-[var(--border-muted)] pl-4 ml-2">
                <div class="flex bg-[var(--surface-secondary)] rounded-lg p-1">
                    <button 
                        @click="toggleViewMode('grid')"
                        class="p-1.5 rounded-md transition-all"
                        :class="viewMode === 'grid' ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'"
                    >
                        <LayoutGrid class="w-4 h-4" />
                    </button>
                    <button 
                        @click="toggleViewMode('list')"
                        class="p-1.5 rounded-md transition-all"
                        :class="viewMode === 'list' ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'"
                    >
                        <List class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Content -->
        <ClientList 
            :clients="clients"
            :loading="isLoading"
            :view-mode="viewMode"
            :pagination="pagination"
            :selected-clients="selectedClients"
            @edit="openEditModal"
            @delete="confirmDelete"
            @view="handleViewClient"
            @page-change="handlePageChange"
        >
             <template #empty-actions>
                <Button variant="outline" class="mt-4" @click="openCreateModal">
                    Add Client
                </Button>
            </template>
        </ClientList>

        <!-- Create/Edit Modal -->
        <ClientFormModal
            :open="showCreateModal"
            :client="clientToEdit"
            @close="showCreateModal = false"
            @saved="fetchClients(pagination.currentPage)"
        />
        
        <!-- Delete Modal (Keeping it simple here or could extract) -->
         <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @click.self="showDeleteModal = false">
            <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] shadow-xl w-full max-w-md overflow-hidden">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-[var(--text-primary)] mb-2">Delete Client</h3>
                    <p class="text-[var(--text-secondary)]">
                        Are you sure you want to delete <strong>{{ clientToDelete?.name }}</strong>? This action cannot be undone.
                    </p>
                </div>
                <div class="px-6 py-4 bg-[var(--surface-secondary)] flex justify-end gap-3">
                    <button @click="showDeleteModal = false" class="btn btn-ghost">Cancel</button>
                    <Button variant="destructive" :loading="isSubmitting" @click="deleteClient">Delete Client</Button>
                </div>
            </div>
        </div>

    </div>
</template>
