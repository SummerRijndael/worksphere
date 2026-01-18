<script setup>
import { ref, onMounted, watch } from 'vue';
import api from '@/lib/api';
import { debounce } from 'lodash';
import { SearchInput, SelectFilter } from '@/components/ui';
import {
    Trash2,
    Plus,
    Edit2,
    Loader2,
    List,
    LayoutGrid,
    X,
    Users as UsersIcon
} from 'lucide-vue-next';

import { useAuthStore } from '@/stores/auth';

// State
const authStore = useAuthStore();
const teams = ref([]);
const isLoading = ref(false);
const searchQuery = ref('');
const statusFilter = ref('');
const perPage = ref(20);
const dateRange = ref({ start: '', end: '' });
const selectedTeams = ref([]);
const selectAll = ref(false);
const viewMode = ref('list'); // 'list' or 'grid'

const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 20
});

const showCreateModal = ref(false);
const showEditModal = ref(false);
const currentTeam = ref({});
const users = ref([]); // For owner selection

const formData = ref({
    name: '',
    description: '',
    owner_id: '',
    status: 'active',
});

const errors = ref({});

// Fetch teams
const fetchTeams = debounce(async (page = 1) => {
    isLoading.value = true;
    try {
        const params = {
            page,
            search: searchQuery.value,
            per_page: perPage.value,
            date_from: dateRange.value.start,
            date_to: dateRange.value.end
        };
        
        // Filter out empty values
        const cleanParams = Object.fromEntries(
            Object.entries(params).filter(([_, v]) => v != null && v !== '')
        );

        const response = await api.get('/api/teams', { params: cleanParams });
        teams.value = response.data.data;
        pagination.value = {
            current_page: response.data.current_page,
            last_page: response.data.last_page,
            total: response.data.total,
            per_page: response.data.per_page,
            from: response.data.from,
            to: response.data.to,
        };
    } catch (error) {
        console.error(error);
    } finally {
        isLoading.value = false;
    }
}, 300);

// Fetch potential owners (users)
const fetchUsers = async (search = '') => {
    try {
        const response = await api.get('/api/users', { params: { search, per_page: 20 } });
        users.value = response.data.data;
    } catch (error) {
        console.error(error);
    }
};

// Selection Logic
const toggleSelectAll = () => {
    if (selectAll.value) {
        selectedTeams.value = teams.value.map(team => team.public_id);
    } else {
        selectedTeams.value = [];
    }
};

const toggleSelection = () => {
    selectAll.value = selectedTeams.value.length === teams.value.length && teams.value.length > 0;
};

// Debounced search
const debouncedUserSearch = debounce((query) => {
    fetchUsers(query);
}, 300);

// Watchers
watch([searchQuery, perPage, () => dateRange.value.start, () => dateRange.value.end], () => {
    fetchTeams(1);
});

// CRUD Actions
const openCreateModal = () => {
    formData.value = {
        name: '',
        description: '',
        owner_id: '',
        status: 'active',
    };
    errors.value = {};
    fetchUsers(); // Pre-load users
    showCreateModal.value = true;
};

const createTeam = async () => {
    try {
        await api.post('/api/teams', formData.value);
        showCreateModal.value = false;
        fetchTeams(1);
        authStore.fetchUser(); // Refresh user to update teams list
    } catch (error) {
        if (error.response && error.response.data.errors) {
            errors.value = error.response.data.errors;
        }
    }
};

const openEditModal = (team) => {
    currentTeam.value = team;
    formData.value = {
        name: team.name,
        description: team.description,
        owner_id: team.owner_id,
        status: team.status,
    };
    errors.value = {};
    fetchUsers(); // Pre-load users
    showEditModal.value = true;
};

const updateTeam = async () => {
    try {
        await api.put(`/api/teams/${currentTeam.value.public_id}`, formData.value);
        showEditModal.value = false;
        fetchTeams(pagination.value.current_page);
        authStore.fetchUser(); // Refresh user to update team details
    } catch (error) {
        if (error.response && error.response.data.errors) {
            errors.value = error.response.data.errors;
        }
    }
};

const deleteTeam = async (team) => {
    if (!confirm('Are you sure you want to delete this team?')) return;
    try {
        await api.delete(`/api/teams/${team.public_id}`);
        fetchTeams(pagination.value.current_page);
        authStore.fetchUser(); // Refresh user to remove deleted team
    } catch (error) {
        console.error(error);
    }
};

onMounted(() => {
    fetchTeams();
});
</script>

<template>
    <div class="p-6 space-y-6">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">Teams</h1>
                <p class="text-[var(--text-secondary)]">Manage teams and their members.</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center bg-[var(--surface-elevated)] rounded-lg p-1 border border-[var(--border-default)]">
                    <button @click="viewMode = 'list'" :class="{'bg-[var(--surface-secondary)] text-[var(--text-primary)] shadow-sm': viewMode === 'list', 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]': viewMode !== 'list'}" class="p-1.5 rounded-md transition-all">
                        <List class="w-4 h-4" />
                    </button>
                    <button @click="viewMode = 'grid'" :class="{'bg-[var(--surface-secondary)] text-[var(--text-primary)] shadow-sm': viewMode === 'grid', 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]': viewMode !== 'grid'}" class="p-1.5 rounded-md transition-all">
                        <LayoutGrid class="w-4 h-4" />
                    </button>
                </div>
                <div class="h-8 w-px bg-[var(--border-default)]"></div>
                <button v-if="selectedTeams.length > 0" class="btn btn-secondary btn-sm text-[var(--color-error)] border border-[var(--color-error)] hover:bg-red-50 dark:hover:bg-red-900/20">
                    <Trash2 class="w-4 h-4" />
                    Delete Selected ({{ selectedTeams.length }})
                </button>
                <button @click="openCreateModal" class="btn btn-primary btn-sm">
                    <Plus class="w-4 h-4" />
                    Create Team
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-col xl:flex-row gap-4 p-4 bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)]">
            <div class="flex-1 flex flex-col sm:flex-row gap-3">
                <SearchInput
                    v-model="searchQuery"
                    placeholder="Search teams..."
                    class="flex-1"
                />
            </div>

            <div class="h-px xl:h-auto xl:w-px bg-[var(--border-default)]"></div>

            <div class="flex flex-col sm:flex-row gap-3">
                <div class="flex items-center gap-2">
                    <input v-model="dateRange.start" type="date" class="input w-auto h-10 text-sm" placeholder="Start Date">
                    <span class="text-[var(--text-muted)]">-</span>
                    <input v-model="dateRange.end" type="date" class="input w-auto h-10 text-sm" placeholder="End Date">
                </div>
                <div class="w-20">
                    <SelectFilter
                        v-model="perPage"
                        :options="[
                            { value: 20, label: '20' },
                            { value: 50, label: '50' },
                            { value: 100, label: '100' },
                            { value: 200, label: '200' }
                        ]"
                        placeholder="20"
                        size="lg"
                    />
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden flex flex-col h-[calc(100vh-18rem)]">
            <Transition name="fade" mode="out-in">
                <div v-if="viewMode === 'list'" key="list" class="overflow-auto flex-1">
                    <table class="w-full text-left text-sm relative border-collapse">
                        <thead class="bg-[var(--surface-secondary)]/50 text-[var(--text-secondary)] font-medium sticky top-0 z-10 border-b border-[var(--border-default)] backdrop-blur-sm">
                            <tr>
                                <th class="w-12 px-6 py-3 font-medium text-xs uppercase tracking-wider">
                                    <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)]">
                                </th>
                                <th class="px-6 py-3 font-medium text-xs uppercase tracking-wider">Team</th>
                                <th class="px-6 py-3 font-medium text-xs uppercase tracking-wider">Owner</th>
                                <th class="px-6 py-3 font-medium text-xs uppercase tracking-wider">Members</th>
                                <th class="px-6 py-3 font-medium text-xs uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 font-medium text-xs uppercase tracking-wider text-right">Actions</th>
                            </tr>
                        </thead>
                        <Transition name="fade" mode="out-in">
                        <tbody v-if="isLoading" key="loading">
                             <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-[var(--text-muted)] border border-[var(--border-default)]">
                                    <div class="flex flex-col items-center gap-2">
                                        <Loader2 class="w-6 h-6 animate-spin" />
                                        <span>Loading teams...</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tbody v-else-if="teams.length === 0" key="empty">
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-[var(--text-muted)] border border-[var(--border-default)]">
                                    <div class="flex flex-col items-center gap-2">
                                        <UsersIcon class="w-8 h-8 text-[var(--text-muted)] opacity-50" />
                                        <span>No teams found.</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <TransitionGroup v-else tag="tbody" name="list" appear class="divide-y divide-[var(--border-default)]">
                            <tr v-for="team in teams" :key="team.public_id" class="group hover:bg-[var(--surface-secondary)] transition-colors">
                                <td class="px-6 py-3">
                                    <input type="checkbox" :value="team.public_id" v-model="selectedTeams" @change="toggleSelection" class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)]">
                                </td>
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-[var(--surface-tertiary)] flex items-center justify-center text-[var(--interactive-primary)] text-xs font-bold ring-1 ring-[var(--border-default)]">
                                            {{ team.initials }}
                                        </div>
                                        <div>
                                            <div class="font-medium text-[var(--text-primary)] leading-tight">{{ team.name }}</div>
                                            <div class="text-xs text-[var(--text-secondary)]">/{{ team.slug }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3">
                                     <div v-if="team.owner" class="flex items-center gap-2">
                                        <div class="w-5 h-5 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center text-[10px] text-[var(--text-secondary)] ring-1 ring-[var(--border-default)]">
                                            {{ team.owner.initials }}
                                        </div>
                                        <span class="text-[var(--text-primary)]">{{ team.owner.name }}</span>
                                    </div>
                                    <span v-else class="text-[var(--text-muted)] font-italic">No Owner</span>
                                </td>
                                <td class="px-6 py-3">
                                    <span class="text-[var(--text-secondary)]">{{ team.member_count }} members</span>
                                </td>
                                <td class="px-6 py-3">
                                    <span :class="{
                                        'bg-green-500/10 text-green-700 dark:text-green-400 border-green-200 dark:border-green-800': team.status === 'active',
                                        'bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border-[var(--border-default)]': team.status === 'inactive'
                                    }" class="inline-flex items-center gap-1.5 px-2 py-0.5 text-xs font-medium rounded-full border capitalize">
                                        <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                        {{ team.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button 
                                            type="button"
                                            @click="openEditModal(team)" 
                                            class="flex items-center justify-center p-1.5 h-8 w-8 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)] transition-colors" 
                                            aria-label="Edit Team"
                                        >
                                            <Edit2 class="w-4 h-4" />
                                        </button>
                                        <button 
                                            type="button"
                                            @click="deleteTeam(team)" 
                                            class="flex items-center justify-center p-1.5 h-8 w-8 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 text-[var(--color-error)] transition-colors" 
                                            aria-label="Delete Team"
                                        >
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </TransitionGroup>
                        </Transition>
                    </table>
                </div>

                <!-- Grid View -->
                <div v-else key="grid" class="overflow-auto flex-1 p-6 bg-[var(--surface-secondary)]/30">
                    <Transition name="fade" mode="out-in">
                        <div v-if="isLoading" key="loading" class="flex flex-col items-center justify-center p-12 text-[var(--text-muted)] h-full">
                            <Loader2 class="w-8 h-8 animate-spin mb-4" />
                            <span>Loading teams...</span>
                        </div>
                        <div v-else-if="teams.length === 0" key="empty" class="flex flex-col items-center justify-center p-12 text-[var(--text-muted)] h-full">
                            <UsersIcon class="w-12 h-12 mb-4 opacity-50" />
                            <span>No teams found.</span>
                        </div>
                        <div v-else key="content">
                             <TransitionGroup tag="div" name="list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                                <div v-for="team in teams" :key="team.public_id" class="group bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-xl p-4 hover:border-[var(--interactive-primary)] hover:shadow-md transition-all relative">
                                    <!-- Checkbox -->
                                    <div class="absolute top-4 left-4 z-10">
                                        <input type="checkbox" :value="team.public_id" v-model="selectedTeams" @change="toggleSelection" class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)]">
                                    </div>
            
                                    <!-- Actions -->
                                    <div class="absolute top-2 right-2 flex gap-1">
                                        <button @click="openEditModal(team)" class="btn btn-ghost p-1 h-7 w-7 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)]">
                                            <Edit2 class="w-3.5 h-3.5" />
                                        </button>
                                        <button @click="deleteTeam(team)" class="btn btn-ghost p-1 h-7 w-7 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 text-[var(--color-error)]">
                                            <Trash2 class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                    
                                    <div class="flex flex-col items-center text-center mt-2">
                                         <div class="w-16 h-16 rounded-lg bg-[var(--surface-tertiary)] flex items-center justify-center text-[var(--interactive-primary)] text-xl font-bold border-2 border-[var(--surface-secondary)] mb-3">
                                            {{ team.initials }}
                                        </div>
                                        <h3 class="font-bold text-[var(--text-primary)] truncate max-w-full px-2">{{ team.name }}</h3>
                                        <p class="text-xs text-[var(--text-secondary)] truncate max-w-full px-2 mb-3">/{{ team.slug }}</p>
            
                                         <div class="flex items-center gap-2 mb-4">
                                            <span class="inline-flex px-2 py-0.5 text-[10px] font-medium rounded border border-[var(--border-default)] bg-[var(--surface-secondary)] text-[var(--text-secondary)]">
                                                {{ team.member_count }} members
                                            </span>
                                            <span :class="{
                                                'bg-green-500/10 text-green-900 border-green-200 dark:border-green-800 dark:text-green-400': team.status === 'active',
                                                'bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border-[var(--border-default)]': team.status === 'inactive'
                                            }" class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] font-medium rounded-full border capitalize">
                                                <span class="w-1 h-1 rounded-full bg-current"></span>
                                                {{ team.status }}
                                            </span>
                                        </div>
            
                                        <div class="w-full pt-3 border-t border-[var(--border-default)] flex justify-between items-center text-xs text-[var(--text-tertiary)]">
                                            <span>Owner</span>
                                            <div v-if="team.owner" class="flex items-center gap-1.5">
                                                 <div class="w-4 h-4 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center text-[8px] text-[var(--text-secondary)]">
                                                    {{ team.owner.initials }}
                                                </div>
                                                <span class="max-w-[80px] truncate">{{ team.owner.name }}</span>
                                            </div>
                                            <span v-else class="italic">None</span>
                                        </div>
                                    </div>
                                </div>
                            </TransitionGroup>
                        </div>
                    </Transition>
                </div>
            </Transition>

            <!-- Pagination -->
            <div class="px-4 py-3 border-t border-[var(--border-default)] bg-[var(--surface-elevated)] flex items-center justify-between">
                <div class="text-xs text-[var(--text-tertiary)]">
                    Showing <span class="font-medium text-[var(--text-primary)]">{{ (pagination.current_page - 1) * pagination.per_page + 1 }}</span> to <span class="font-medium text-[var(--text-primary)]">{{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }}</span> of <span class="font-medium text-[var(--text-primary)]">{{ pagination.total }}</span> results
                </div>
                <div class="flex items-center gap-2">
                    <button 
                        @click="fetchTeams(pagination.current_page - 1)" 
                        :disabled="pagination.current_page === 1"
                        class="btn btn-secondary py-1.5 px-3 h-8 text-xs disabled:opacity-50"
                    >
                        Previous
                    </button>
                    <button 
                        @click="fetchTeams(pagination.current_page + 1)" 
                        :disabled="pagination.current_page === pagination.last_page"
                        class="btn btn-secondary py-1.5 px-3 h-8 text-xs disabled:opacity-50"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <div v-if="showCreateModal" class="modal-overlay flex items-center justify-center p-4">
            <div class="modal-content w-full max-w-lg animate-fade-in-up">
                <div class="px-6 py-4 border-b border-[var(--border-default)] flex items-center justify-between">
                    <h3 class="text-lg font-medium text-[var(--text-primary)]">Create New Team</h3>
                    <button @click="showCreateModal = false" class="text-[var(--text-secondary)] hover:text-[var(--text-primary)]">
                        <X class="w-5 h-5" />
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-[var(--text-secondary)]">Team Name</label>
                        <input v-model="formData.name" type="text" class="input">
                        <p v-if="errors.name" class="text-xs text-[var(--color-error)]">{{ errors.name[0] }}</p>
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-[var(--text-secondary)]">Description</label>
                        <textarea v-model="formData.description" rows="3" class="input"></textarea>
                         <p v-if="errors.description" class="text-xs text-[var(--color-error)]">{{ errors.description[0] }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Owner</label>
                            <select v-model="formData.owner_id" class="input">
                                <option value="" disabled>Select Owner</option>
                                <option v-for="user in users" :key="user.id" :value="user.id">
                                    {{ user.name }}
                                </option>
                            </select>
                            <p v-if="errors.owner_id" class="text-xs text-[var(--color-error)]">{{ errors.owner_id[0] }}</p>
                        </div>
                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Status</label>
                            <select v-model="formData.status" class="input">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-[var(--surface-secondary)] flex justify-end gap-3">
                    <button @click="showCreateModal = false" class="btn btn-ghost">Cancel</button>
                    <button @click="createTeam" class="btn btn-primary">Create Team</button>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div v-if="showEditModal" class="modal-overlay flex items-center justify-center p-4">
             <div class="modal-content w-full max-w-lg animate-fade-in-up">
                <div class="px-6 py-4 border-b border-[var(--border-default)] flex items-center justify-between">
                    <h3 class="text-lg font-medium text-[var(--text-primary)]">Edit Team</h3>
                    <button @click="showEditModal = false" class="text-[var(--text-secondary)] hover:text-[var(--text-primary)]">
                        <X class="w-5 h-5" />
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-[var(--text-secondary)]">Team Name</label>
                        <input v-model="formData.name" type="text" class="input">
                        <p v-if="errors.name" class="text-xs text-[var(--color-error)]">{{ errors.name[0] }}</p>
                    </div>
                     <div class="space-y-1">
                        <label class="text-sm font-medium text-[var(--text-secondary)]">Description</label>
                        <textarea v-model="formData.description" rows="3" class="input"></textarea>
                         <p v-if="errors.description" class="text-xs text-[var(--color-error)]">{{ errors.description[0] }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Owner</label>
                            <select v-model="formData.owner_id" class="input">
                                <option value="" disabled>Select Owner</option>
                                <option v-for="user in users" :key="user.id" :value="user.id">
                                    {{ user.name }}
                                </option>
                            </select>
                            <p v-if="errors.owner_id" class="text-xs text-[var(--color-error)]">{{ errors.owner_id[0] }}</p>
                        </div>
                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Status</label>
                            <select v-model="formData.status" class="input">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-[var(--surface-secondary)] flex justify-end gap-3">
                    <button @click="showEditModal = false" class="btn btn-ghost">Cancel</button>
                    <button @click="updateTeam" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </div>
    </div>
</template>
