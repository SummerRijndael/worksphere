<script setup>
import { ref, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import AppLayout from '@/layouts/AppLayout.vue';
import api from '@/lib/api';
import { debounce } from 'lodash';
import { SearchInput, SelectFilter } from '@/components/ui';
import UserStatsCards from '@/views/admin/components/UserStatsCards.vue';
import UserTrendsChart from '@/views/admin/components/UserTrendsChart.vue';
import {
    Trash2,
    Plus,
    Edit2,
    Loader2,
    List,
    LayoutGrid,
    X,
    Users as UsersIcon,
    Eye
} from 'lucide-vue-next';

// State
// State
const users = ref([]);
const isLoading = ref(false);
const searchQuery = ref('');
const statusFilter = ref('');
const roleFilter = ref('');
const perPage = ref(20);
const dateRange = ref({ start: '', end: '' });
const selectedUsers = ref([]);
const selectAll = ref(false);
const viewMode = ref('list'); // 'list' or 'grid'

const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 20
});

const showCreateModal = ref(false);
const currentUser = ref({});
const router = useRouter();

// Stats
const stats = ref({
    total_users: 0,
    status_counts: {},
    role_counts: {},
    trends: { registrations: {} }
});
const statsLoading = ref(true);

const fetchStats = async () => {
    try {
        const response = await api.get('/api/users/stats');
        stats.value = response.data;
    } catch (error) {
        console.error('Failed to fetch user stats', error);
    } finally {
        statsLoading.value = false;
    }
};

const formData = ref({
    name: '',
    email: '',
    username: '',
    role: '',
    status: 'active',
});

const errors = ref({});

// Fetch users
const fetchUsers = debounce(async (page = 1) => {
    isLoading.value = true;
    try {
        const params = {
            page,
            search: searchQuery.value,
            per_page: perPage.value,
            status: statusFilter.value,
            role: roleFilter.value,
            date_from: dateRange.value.start,
            date_to: dateRange.value.end
        };
        
        // Filter out empty values
        const cleanParams = Object.fromEntries(
            Object.entries(params).filter(([_, v]) => v != null && v !== '')
        );

        const response = await api.get('/api/users', { params: cleanParams });
        users.value = response.data.data;
        
        const meta = response.data.meta || response.data;
        pagination.value = {
            current_page: meta.current_page,
            last_page: meta.last_page,
            total: meta.total,
            per_page: meta.per_page,
            from: meta.from,
            to: meta.to,
        };
    } catch (error) {
        console.error(error);
    } finally {
        isLoading.value = false;
    }
}, 300);

// Selection Logic
const toggleSelectAll = () => {
    if (selectAll.value) {
        selectedUsers.value = users.value.map(user => user.public_id);
    } else {
        selectedUsers.value = [];
    }
};

const toggleSelection = () => {
    selectAll.value = selectedUsers.value.length === users.value.length && users.value.length > 0;
};

// Watchers
watch([searchQuery, statusFilter, roleFilter, perPage, () => dateRange.value.start, () => dateRange.value.end], () => {
    fetchUsers(1);
});

// CRUD Actions
const openCreateModal = () => {
    formData.value = {
        name: '',
        email: '',
        username: '',
        role: 'member',
        status: 'active',
        role: 'member',
        status: 'active',
    };
    errors.value = {};
    showCreateModal.value = true;
};

const createUser = async () => {
    try {
        await api.post('/api/users', formData.value);
        showCreateModal.value = false;
        fetchUsers(1);
        // Show detail toast
    } catch (error) {
        if (error.response && error.response.data.errors) {
            errors.value = error.response.data.errors;
        }
    }
};

const openEditModal = (user) => {
    // Navigate to user details page for editing
    router.push(`/admin/users/${user.public_id}`);
};

const deleteUser = async (user) => {
    if (!confirm('Are you sure you want to delete this user?')) return;
    try {
        await api.delete(`/api/users/${user.public_id}`);
        fetchUsers(pagination.value.current_page);
        // Show success toast
    } catch (error) {
        console.error(error);
    }
};

onMounted(() => {
    fetchUsers();
    fetchStats();
});
</script>

<template>
    <div class="p-6 space-y-6">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">Users</h1>
                <p class="text-[var(--text-secondary)]">Manage system users and permissions.</p>
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
                <button v-if="selectedUsers.length > 0" class="btn btn-secondary btn-sm text-[var(--color-error)] border border-[var(--color-error)] hover:bg-red-50 dark:hover:bg-red-900/20">
                    <Trash2 class="w-4 h-4" />
                    Delete Selected ({{ selectedUsers.length }})
                </button>
                <button @click="openCreateModal" class="btn btn-primary btn-sm">
                    <Plus class="w-4 h-4" />
                    Add User
                </button>
            </div>
        </div>

        <!-- Statistics -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
             <div class="lg:col-span-3">
                <UserStatsCards :stats="stats" :loading="statsLoading" />
             </div>
             <div class="lg:col-span-1">
                <UserTrendsChart :data="stats.trends?.registrations || {}" :loading="statsLoading" />
             </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-col xl:flex-row gap-4 p-4 bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)]">
            <div class="flex-1 flex flex-col sm:flex-row gap-3">
                <SearchInput
                    v-model="searchQuery"
                    placeholder="Search users..."
                    class="flex-1"
                />
                <div class="flex gap-3">
                    <div class="min-w-[140px]">
                        <SelectFilter
                            v-model="statusFilter"
                            :options="[
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Inactive' },
                                { value: 'suspended', label: 'Suspended' }
                            ]"
                            placeholder="All Statuses"
                            size="lg"
                        />
                    </div>
                    <div class="min-w-[140px]">
                        <SelectFilter
                            v-model="roleFilter"
                            :options="[
                                { value: 'administrator', label: 'Administrator' },
                                { value: 'user', label: 'User' }
                            ]"
                            placeholder="All Roles"
                            size="lg"
                        />
                    </div>
                </div>
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
                    <thead class="bg-[var(--surface-secondary)] text-[var(--text-secondary)] font-medium sticky top-0 z-10 border-b border-[var(--border-default)]">
                        <tr>
                            <th class="w-12 px-6 py-3 border border-[var(--border-default)] bg-[var(--surface-secondary)]">
                                <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)]">
                            </th>
                            <th class="px-6 py-3 border border-[var(--border-default)] bg-[var(--surface-secondary)]">User</th>
                            <th class="px-6 py-3 border border-[var(--border-default)] bg-[var(--surface-secondary)]">Role</th>
                            <th class="px-6 py-3 border border-[var(--border-default)] bg-[var(--surface-secondary)]">Status</th>
                            <th class="px-6 py-3 border border-[var(--border-default)] bg-[var(--surface-secondary)]">Joined</th>
                            <th class="px-6 py-3 border border-[var(--border-default)] bg-[var(--surface-secondary)] text-right">Actions</th>
                        </tr>
                    </thead>
                    <Transition name="fade" mode="out-in">
                    <tbody v-if="isLoading" key="loading">
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-[var(--text-muted)] border border-[var(--border-default)]">
                                <div class="flex flex-col items-center gap-2">
                                    <Loader2 class="w-6 h-6 animate-spin" />
                                    <span>Loading users...</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tbody v-else-if="users.length === 0" key="empty">
                         <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-[var(--text-muted)] border border-[var(--border-default)]">
                                <div class="flex flex-col items-center gap-2">
                                    <UsersIcon class="w-8 h-8 text-[var(--text-muted)] opacity-50" />
                                    <span>No users found.</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <TransitionGroup v-else tag="tbody" name="list" appear class="divide-y divide-[var(--border-default)]">
                        <tr v-for="user in users" :key="user.public_id" class="group hover:bg-[var(--surface-secondary)] transition-colors">
                            <td class="px-6 py-3 border border-[var(--border-default)]">
                                <input type="checkbox" :value="user.public_id" v-model="selectedUsers" @change="toggleSelection" class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)]">
                            </td>
                            <td class="px-6 py-3 border border-[var(--border-default)]">
                                <div class="flex items-center gap-3">
                                    <img v-if="user.avatar_thumb_url" :src="user.avatar_thumb_url" alt="" class="w-8 h-8 rounded-full object-cover border border-[var(--border-default)]">
                                    <div v-else class="w-8 h-8 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center text-[var(--interactive-primary)] text-xs font-bold border border-[var(--border-default)]">
                                        {{ user.initials }}
                                    </div>
                                    <div>
                                        <div class="font-medium text-[var(--text-primary)] leading-tight">{{ user.name }}</div>
                                        <div class="text-xs text-[var(--text-secondary)]">{{ user.email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-3 border border-[var(--border-default)]">
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium rounded border border-[var(--border-default)] bg-[var(--surface-secondary)] text-[var(--text-secondary)] capitalize">
                                    {{ user.roles[0]?.label || 'Member' }}
                                </span>
                            </td>
                            <td class="px-6 py-3 border border-[var(--border-default)]">
                                <span :class="{
                                    'bg-green-500/10 text-green-900 border-green-200 dark:border-green-800 dark:text-green-400': user.status === 'active',
                                    'bg-red-500/10 text-red-700 border-red-200 dark:border-red-800 dark:text-red-400': user.status === 'suspended',
                                    'bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border-[var(--border-default)]': user.status === 'inactive'
                                }" class="inline-flex items-center gap-1.5 px-2 py-0.5 text-xs font-medium rounded-full border capitalize">
                                    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                    {{ user.status }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-xs text-[var(--text-tertiary)] font-mono border border-[var(--border-default)]">
                                {{ new Date(user.created_at).toLocaleDateString() }}
                            </td>
                            <td class="px-6 py-3 text-right border border-[var(--border-default)]">
                                <div class="flex items-center justify-end gap-1">
                                    <button 
                                        type="button"
                                        @click="router.push(`/admin/users/${user.public_id}`)"
                                        class="flex items-center justify-center p-1.5 h-8 w-8 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)] transition-colors" 
                                        title="View Details"
                                    >
                                        <Eye class="w-4 h-4" />
                                    </button>
                                    <button 
                                        type="button"
                                        @click="openEditModal(user)" 
                                        class="flex items-center justify-center p-1.5 h-8 w-8 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)] transition-colors" 
                                        aria-label="Edit User"
                                    >
                                        <Edit2 class="w-4 h-4" />
                                    </button>
                                    <button 
                                        type="button"
                                        @click="deleteUser(user)" 
                                        class="flex items-center justify-center p-1.5 h-8 w-8 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 text-[var(--color-error)] transition-colors" 
                                        aria-label="Delete User"
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
                        <span>Loading users...</span>
                    </div>
                    <div v-else-if="users.length === 0" key="empty" class="flex flex-col items-center justify-center p-12 text-[var(--text-muted)] h-full">
                        <UsersIcon class="w-12 h-12 mb-4 opacity-50" />
                        <span>No users found.</span>
                    </div>
                    <div v-else key="content">
                         <TransitionGroup tag="div" name="list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    <div v-for="user in users" :key="user.public_id" class="group bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-xl p-4 hover:border-[var(--interactive-primary)] hover:shadow-md transition-all relative">
                        <!-- Checkbox -->
                        <div class="absolute top-4 left-4 z-10">
                            <input type="checkbox" :value="user.public_id" v-model="selectedUsers" @change="toggleSelection" class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)]">
                        </div>
                        
                        <!-- Actions -->
                        <div class="absolute top-2 right-2 flex gap-1 z-20">
                            <button @click="router.push(`/admin/users/${user.public_id}`)" class="flex items-center justify-center p-1 h-7 w-7 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)] transition-colors" title="View Details">
                                <Eye class="w-4 h-4" />
                            </button>
                            <button @click="openEditModal(user)" class="flex items-center justify-center p-1 h-7 w-7 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)] transition-colors" title="Edit User">
                                <Edit2 class="w-4 h-4" />
                            </button>
                            <button @click="deleteUser(user)" class="flex items-center justify-center p-1 h-7 w-7 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 text-[var(--color-error)] transition-colors" title="Delete User">
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                        
                        <div class="flex flex-col items-center text-center mt-2">
                            <img v-if="user.avatar_url" :src="user.avatar_url" alt="" class="w-16 h-16 rounded-full object-cover border-2 border-[var(--surface-secondary)] mb-3">
                            <div v-else class="w-16 h-16 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center text-[var(--interactive-primary)] text-xl font-bold border-2 border-[var(--surface-secondary)] mb-3">
                                {{ user.initials }}
                            </div>
                            <h3 class="font-bold text-[var(--text-primary)] truncate max-w-full px-2">{{ user.name }}</h3>
                            <p class="text-xs text-[var(--text-secondary)] truncate max-w-full px-2 mb-3">{{ user.email }}</p>
                            
                            <div class="flex items-center gap-2 mb-4">
                                <span class="inline-flex px-2 py-0.5 text-[10px] font-medium rounded border border-[var(--border-default)] bg-[var(--surface-secondary)] text-[var(--text-secondary)] capitalize">
                                    {{ user.roles[0]?.label || 'Member' }}
                                </span>
                                <span :class="{
                                    'bg-green-500/10 text-green-700 border-green-200 dark:border-green-800 dark:text-green-400': user.status === 'active',
                                    'bg-red-500/10 text-red-700 border-red-200 dark:border-red-800 dark:text-red-400': user.status === 'suspended',
                                    'bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border-[var(--border-default)]': user.status === 'inactive'
                                }" class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] font-medium rounded-full border capitalize">
                                    <span class="w-1 h-1 rounded-full bg-current"></span>
                                    {{ user.status }}
                                </span>
                            </div>
                            
                            <div class="w-full pt-3 border-t border-[var(--border-default)] flex justify-between items-center text-xs text-[var(--text-tertiary)]">
                                <span>Joined</span>
                                <span class="font-mono">{{ new Date(user.created_at).toLocaleDateString() }}</span>
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
                        @click="fetchUsers(pagination.current_page - 1)" 
                        :disabled="pagination.current_page === 1"
                        class="btn btn-secondary py-1.5 px-3 h-8 text-xs disabled:opacity-50"
                    >
                        Previous
                    </button>
                    <button 
                        @click="fetchUsers(pagination.current_page + 1)" 
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
                    <h3 class="text-lg font-medium text-[var(--text-primary)]">Add New User</h3>
                    <button @click="showCreateModal = false" class="text-[var(--text-secondary)] hover:text-[var(--text-primary)]">
                        <X class="w-5 h-5" />
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Full Name</label>
                            <input v-model="formData.name" type="text" class="input">
                            <p v-if="errors.name" class="text-xs text-[var(--color-error)]">{{ errors.name[0] }}</p>
                        </div>
                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Username</label>
                            <input v-model="formData.username" type="text" class="input">
                             <p v-if="errors.username" class="text-xs text-[var(--color-error)]">{{ errors.username[0] }}</p>
                        </div>
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-[var(--text-secondary)]">Email Address</label>
                        <input v-model="formData.email" type="email" class="input">
                         <p v-if="errors.email" class="text-xs text-[var(--color-error)]">{{ errors.email[0] }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Role</label>
                            <select v-model="formData.role" class="input">
                                <option value="admin">Admin</option>
                                <option value="member">Member</option>
                            </select>
                             <p v-if="errors.role" class="text-xs text-[var(--color-error)]">{{ errors.role[0] }}</p>
                        </div>
                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Status</label>
                            <select v-model="formData.status" class="input">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-[var(--surface-secondary)] flex justify-end gap-3">
                    <button @click="showCreateModal = false" class="btn btn-ghost">Cancel</button>
                    <button @click="createUser" class="btn btn-primary">Create User</button>
                </div>
            </div>
        </div>
    </div>
</template>
