<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Shield, ArrowLeft, Users, Key, Filter, Search, Calendar, Pencil, ChevronLeft, ChevronRight, Check } from 'lucide-vue-next';
import { usePermissions, useRoles } from '@/composables/usePermissions.ts';
import { Button, Badge, Input, Card, StatusBadge, SelectFilter } from '@/components/ui';
import EditRoleModal from '@/components/permissions/EditRoleModal.vue';

const route = useRoute();
const router = useRouter();
const roleId = route.params.id;

const { role, loading: roleLoading, fetchRole, fetchRoleUsers } = useRoles();
const { fetchPermissions } = usePermissions();

// Local state
const users = ref([]);
const usersPagination = ref({});
const usersLoading = ref(false);
const activeTab = ref('users');
const searchQuery = ref('');
const userSearchQuery = ref('');
const showEditModal = ref(false);
const allPermissions = ref([]);
const permissionsLoading = ref(false);
const perPage = ref(10);

const perPageOptions = [
    { label: '10 per page', value: 10 },
    { label: '25 per page', value: 25 },
    { label: '50 per page', value: 50 },
    { label: '100 per page', value: 100 },
];

// Computed
const roleData = ref(null);

const groupedPermissions = computed(() => {
    if (!roleData.value) return {};
    
    // If admin or all access, return all permissions from system if loaded
    if (roleData.value.permissions?.includes('*') || roleData.value.name === 'administrator') {
        const fullGroups = {};
        allPermissions.value.forEach(group => {
            fullGroups[group.label] = group.permissions.map(p => ({
                name: p.name,
                label: p.label,
                granted: true
            }));
        });
        return fullGroups;
    }

    const permissions = roleData.value.permissions || [];
    if (!permissions.length) return {};

    const groups = {};
    permissions.forEach(perm => {
        const name = typeof perm === 'string' ? perm : perm.name;
        const label = typeof perm === 'string' ? perm : (perm.label || perm.name);
        const parts = name.split('.');
        const category = parts.length > 1 ? parts[0] : 'Other';
        const categoryLabel = category.charAt(0).toUpperCase() + category.slice(1);

        if (!groups[categoryLabel]) {
            groups[categoryLabel] = [];
        }
        groups[categoryLabel].push({ name, label });
    });
    return groups;
});

const filteredPermissions = computed(() => {
    if (!searchQuery.value) return groupedPermissions.value;
    const query = searchQuery.value.toLowerCase();
    
    const filtered = {};
    Object.keys(groupedPermissions.value).forEach(category => {
        const matches = groupedPermissions.value[category].filter(p => 
            p.name.toLowerCase().includes(query) || 
            p.label.toLowerCase().includes(query) ||
            category.toLowerCase().includes(query)
        );
        if (matches.length) filtered[category] = matches;
    });
    return filtered;
});

// Actions
async function loadRole() {
    roleData.value = await fetchRole(roleId);
}

async function loadUsers(page = 1) {
    usersLoading.value = true;
    try {
        const response = await fetchRoleUsers(roleId, page, perPage.value, userSearchQuery.value);
        if (response) {
            users.value = response.data;
            usersPagination.value = response.meta;
        }
    } finally {
        usersLoading.value = false;
    }
}

async function loadAllPermissions() {
    permissionsLoading.value = true;
    try {
        allPermissions.value = await fetchPermissions();
    } finally {
        permissionsLoading.value = false;
    }
}

async function handleRoleSaved() {
    await loadRole();
}

function handlePageChange(page) {
    if (page >= 1 && page <= usersPagination.value.last_page) {
        loadUsers(page);
    }
}

function formatDate(date) {
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function getInitials(name) {
    return name
        .split(' ')
        .map(word => word[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

onMounted(async () => {
    await loadRole();
    await loadUsers();
    
    if (roleData.value && (roleData.value.permissions?.includes('*') || roleData.value.name === 'administrator')) {
        loadAllPermissions();
    }
});
</script>

<template>
    <div class="p-6 space-y-6">
        <!-- Back Navigation -->
        <div>
            <Button variant="ghost" size="sm" class="gap-2 pl-0 hover:bg-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)]" @click="router.push('/admin/roles')">
                <ArrowLeft class="w-4 h-4" />
                Back to Roles
            </Button>
        </div>

        <div v-if="!roleData && roleLoading" class="flex justify-center py-12">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--color-primary-500)]"></div>
        </div>

        <div v-else-if="roleData" class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-lg bg-[var(--color-primary-100)] dark:bg-[var(--color-primary-900)]/30">
                        <Shield class="w-8 h-8 text-[var(--color-primary-600)]" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-[var(--text-primary)]">{{ roleData.label || roleData.name }}</h1>
                        <p class="text-[var(--text-secondary)]">{{ roleData.description }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <Badge variant="outline" class="text-sm px-3 py-1">
                        {{ usersPagination.total || roleData.users_count || 0 }} users assigned
                    </Badge>
                     <Button variant="outline" size="sm" @click="showEditModal = true">
                        <Pencil class="w-4 h-4 mr-2" />
                        Edit Role
                    </Button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-6 border-b border-[var(--border-default)]">
                <button
                    class="pb-3 text-sm font-medium transition-colors border-b-2"
                    :class="activeTab === 'users' ? 'border-[var(--color-primary-500)] text-[var(--color-primary-600)]' : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)]'"
                    @click="activeTab = 'users'"
                >
                    <div class="flex items-center gap-2">
                        <Users class="w-4 h-4" />
                        Users
                    </div>
                </button>
                <button
                    class="pb-3 text-sm font-medium transition-colors border-b-2"
                    :class="activeTab === 'permissions' ? 'border-[var(--color-primary-500)] text-[var(--color-primary-600)]' : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)]'"
                    @click="activeTab = 'permissions'"
                >
                    <div class="flex items-center gap-2">
                        <Key class="w-4 h-4" />
                        Permissions
                    </div>
                </button>
            </div>

            <!-- Users Tab -->
            <div v-if="activeTab === 'users'" class="space-y-4">
                <div class="max-w-md">
                    <div class="relative">
                        <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--text-muted)]" />
                        <Input
                            v-model="userSearchQuery"
                            placeholder="Search users by name or email..."
                            class="pl-10"
                            @input="loadUsers(1)"
                        />
                    </div>
                </div>

                <Card>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-[var(--surface-secondary)] border-b border-[var(--border-default)]">
                                <tr>
                                    <th class="px-6 py-3 font-medium text-[var(--text-secondary)]">User</th>
                                    <th class="px-6 py-3 font-medium text-[var(--text-secondary)]">Status</th>
                                    <th class="px-6 py-3 font-medium text-[var(--text-secondary)]">Joined</th>
                                    <th class="px-6 py-3 font-medium text-[var(--text-secondary)] text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--border-default)]">
                                <tr v-for="user in users" :key="user.id" class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="relative w-8 h-8 rounded-full overflow-hidden bg-[var(--surface-secondary)] flex items-center justify-center text-xs font-medium text-[var(--text-secondary)]">
                                                <img 
                                                    v-if="user.avatar" 
                                                    :src="user.avatar" 
                                                    class="w-full h-full object-cover" 
                                                    alt=""
                                                />
                                                <span v-else>{{ getInitials(user.name) }}</span>
                                            </div>
                                            <div>
                                                <div class="font-medium text-[var(--text-primary)]">{{ user.name }}</div>
                                                <div class="text-xs text-[var(--text-muted)]">{{ user.email }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <StatusBadge :status="user.status" />
                                    </td>
                                    <td class="px-6 py-4 text-[var(--text-secondary)]">
                                        {{ formatDate(user.created_at) }}
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <Button 
                                            variant="ghost" 
                                            size="sm" 
                                            @click="router.push(`/admin/users/${user.public_id}`)"
                                        >
                                            View
                                        </Button>
                                    </td>
                                </tr>
                                <tr v-if="users.length === 0 && !usersLoading">
                                    <td colspan="4" class="px-6 py-12 text-center text-[var(--text-secondary)]">
                                        No users assigned to this role.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                
                    <!-- Pagination -->
                    <div v-if="usersPagination.total > 0" class="p-4 border-t border-[var(--border-default)] flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <span class="text-sm text-[var(--text-secondary)]">
                                Showing {{ usersPagination.from }} to {{ usersPagination.to }} of {{ usersPagination.total }} users
                            </span>
                             <SelectFilter
                                v-model="perPage"
                                :options="perPageOptions"
                                placeholder="Per page"
                                class="w-32"
                                @update:modelValue="loadUsers(1)"
                            />
                        </div>
                        <div class="flex items-center gap-2">
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="usersPagination.current_page === 1"
                                @click="handlePageChange(usersPagination.current_page - 1)"
                            >
                                <ChevronLeft class="h-4 w-4" />
                            </Button>
                            <span class="text-sm font-medium text-[var(--text-primary)]">
                                Page {{ usersPagination.current_page }} of {{ usersPagination.last_page }}
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="usersPagination.current_page === usersPagination.last_page"
                                @click="handlePageChange(usersPagination.current_page + 1)"
                            >
                                <ChevronRight class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Permissions Tab -->
            <div v-if="activeTab === 'permissions'" class="space-y-6">
                <div class="max-w-md">
                    <div class="relative">
                        <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--text-muted)]" />
                        <Input
                            v-model="searchQuery"
                            placeholder="Search permissions..."
                            class="pl-10"
                        />
                    </div>
                </div>

                <div v-if="permissionsLoading" class="flex justify-center py-12">
                     <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--color-primary-500)]"></div>
                </div>

                <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="(perms, category) in filteredPermissions" :key="category" class="space-y-3">
                        <h3 class="text-sm font-semibold text-[var(--text-primary)] uppercase tracking-wider flex items-center gap-2">
                            <div class="w-1.5 h-1.5 rounded-full bg-[var(--color-primary-500)]"></div>
                            {{ category }}
                        </h3>
                        <div class="bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg overflow-hidden">
                            <div v-for="perm in perms" :key="perm.name" class="p-3 border-b border-[var(--border-default)] last:border-0 hover:bg-[var(--surface-secondary)] transition-colors">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-sm font-medium text-[var(--text-primary)]">{{ perm.label }}</div>
                                        <div class="text-xs text-[var(--text-muted)] font-mono">{{ perm.name }}</div>
                                    </div>
                                    <Check v-if="perm.granted" class="h-4 w-4 text-[var(--color-success-fg)]" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div v-if="!permissionsLoading && Object.keys(filteredPermissions).length === 0" class="text-center py-12 text-[var(--text-secondary)]">
                    No permissions found matching your search.
                </div>
            </div>
        </div>
        <EditRoleModal
            v-if="roleData"
            v-model:open="showEditModal"
            :role="roleData"
            @saved="handleRoleSaved"
        />
    </div>
</template>
