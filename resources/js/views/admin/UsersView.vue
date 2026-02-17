<script setup>
import { ref, onMounted, watch } from "vue";
import { useDate } from "@/composables/useDate";
const { formatDate, formatRelativeTime } = useDate();
import { useRouter } from "vue-router";
import AppLayout from "@/layouts/AppLayout.vue";
import api from "@/lib/api";
import { debounce } from "lodash";
import { SearchInput, SelectFilter } from "@/components/ui";
import Avatar from "@/components/ui/Avatar.vue";
import UserStatsCards from "@/views/admin/components/UserStatsCards.vue";
import UserTrendsChart from "@/views/admin/components/UserTrendsChart.vue";
import RoleDistributionChart from "@/views/admin/components/RoleDistributionChart.vue";
import {
    Trash2,
    Plus,
    Edit2,
    Loader2,
    List,
    LayoutGrid,
    X,
    Users as UsersIcon,
    Eye,
    Filter,
    MoreVertical,
    Check,
} from "lucide-vue-next";
import { useRoles } from "@/composables/useRoles";
import { usePresence } from "@/composables/usePresence";

const router = useRouter();
const { roleOptions, fetchRoles } = useRoles();
const { presenceUsers, fetchUsersPresence } = usePresence({
    manageLifecycle: false,
});

// State
const users = ref([]);
const isLoading = ref(false);
const searchQuery = ref("");
const statusFilter = ref("");
const roleFilter = ref("");
const perPage = ref(20);
const dateRange = ref({ start: "", end: "" });
const selectedUsers = ref([]);
const selectAll = ref(false);
const viewMode = ref("list"); // 'list' or 'grid'

const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 20,
});

const showCreateModal = ref(false);
const formData = ref({
    name: "",
    email: "",
    username: "",
    role: "",
    status: "active",
});
const errors = ref({});

// Stats
const stats = ref({
    total_users: 0,
    status_counts: {},
    role_counts: {},
    trends: { registrations: {} },
});
const statsLoading = ref(true);

// Methods
const getPresenceBorder = (user) => {
    const status = presenceUsers.value.get(user.public_id)?.status || "offline";
    switch (status) {
        case "online":
            return "border-green-500 ring-2 ring-green-500/20";
        case "away":
            return "border-yellow-500 ring-2 ring-yellow-500/20";
        case "busy":
            return "border-red-500 ring-2 ring-red-500/20";
        default:
            return "border-transparent";
    }
};

const fetchStats = async () => {
    try {
        const response = await api.get("/api/users/stats");
        stats.value = response.data;
    } catch (error) {
        console.error("Failed to fetch user stats", error);
    } finally {
        statsLoading.value = false;
    }
};

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
            date_to: dateRange.value.end,
        };

        const cleanParams = Object.fromEntries(
            Object.entries(params).filter(([_, v]) => v != null && v !== ""),
        );

        const response = await api.get("/api/users", { params: cleanParams });
        users.value = response.data.data;

        // Fetch presence for visible users
        const publicIds = users.value.map((u) => u.public_id);
        if (publicIds.length > 0) {
            fetchUsersPresence(publicIds);
        }

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

const toggleSelectAll = () => {
    if (selectAll.value) {
        selectedUsers.value = users.value.map((user) => user.public_id);
    } else {
        selectedUsers.value = [];
    }
};

const toggleSelection = () => {
    selectAll.value =
        selectedUsers.value.length === users.value.length &&
        users.value.length > 0;
};

// CRUD
const openCreateModal = () => {
    formData.value = {
        name: "",
        email: "",
        username: "",
        role: "user",
        status: "active",
    };
    errors.value = {};
    showCreateModal.value = true;
};

const createUser = async () => {
    try {
        await api.post("/api/users", formData.value);
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
    router.push(`/admin/users/${user.public_id}`);
};

const deleteUser = async (user) => {
    if (!confirm("Are you sure you want to delete this user?")) return;
    try {
        await api.delete(`/api/users/${user.public_id}`);
        fetchUsers(pagination.value.current_page);
    } catch (error) {
        console.error(error);
    }
};

watch(
    [
        searchQuery,
        statusFilter,
        roleFilter,
        perPage,
        () => dateRange.value.start,
        () => dateRange.value.end,
    ],
    () => {
        fetchUsers(1);
    },
);

onMounted(() => {
    fetchUsers();
    fetchStats();
    fetchRoles();
});
</script>

<template>
    <div class="w-full min-h-screen bg-[var(--surface-primary)]">
        <div class="mx-auto w-full max-w-[1920px] p-6 lg:p-8 space-y-8">
            <!-- Header Section -->
            <div
                class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4"
            >
                <div>
                    <h1
                        class="text-3xl font-display font-bold text-[var(--text-primary)]"
                    >
                        Users
                    </h1>
                    <p class="text-[var(--text-secondary)] mt-1">
                        Manage access, roles, and profiles for your
                        organization.
                    </p>
                </div>
                <div class="flex items-center gap-3 w-full md:w-auto">
                    <button
                        v-if="selectedUsers.length > 0"
                        class="btn btn-secondary text-red-600 border-red-200 hover:bg-red-50 dark:border-red-900/50 dark:hover:bg-red-900/20"
                    >
                        <Trash2 class="w-4 h-4" />
                        <span>Delete {{ selectedUsers.length }}</span>
                    </button>
                    <button
                        @click="openCreateModal"
                        class="btn btn-primary shadow-lg shadow-primary-500/20 w-full md:w-auto"
                    >
                        <Plus class="w-4 h-4" />
                        <span>Add New User</span>
                    </button>
                </div>
            </div>

            <!-- Hero Stats Row -->
            <UserStatsCards :stats="stats" :loading="statsLoading" />

            <!-- Insights Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2">
                    <UserTrendsChart
                        :data="stats.trends?.registrations || {}"
                        :loading="statsLoading"
                        class="h-full"
                    />
                </div>
                <div class="lg:col-span-1">
                    <RoleDistributionChart
                        :data="stats.role_counts || {}"
                        :loading="statsLoading"
                        class="h-full"
                    />
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="flex flex-col gap-4">
                <!-- Toolbar -->
                <div
                    class="flex flex-col xl:flex-row justify-between gap-4 bg-[var(--surface-elevated)] p-4 rounded-xl border border-[var(--border-default)] shadow-sm"
                >
                    <!-- Search & Primary Filters -->
                    <div class="flex flex-1 flex-col sm:flex-row gap-3">
                        <div class="relative flex-1">
                            <SearchInput
                                v-model="searchQuery"
                                placeholder="Search by name, email, or username..."
                                class="w-full"
                            />
                        </div>
                        <div class="flex gap-3">
                            <SelectFilter
                                v-model="statusFilter"
                                :options="[
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
                                    { value: 'suspended', label: 'Suspended' },
                                ]"
                                placeholder="Status"
                                class="w-[140px]"
                            />
                            <SelectFilter
                                v-model="roleFilter"
                                :options="roleOptions"
                                placeholder="Role"
                                class="w-[140px]"
                            />
                        </div>
                    </div>

                    <!-- View Options & Pagination Control -->
                    <div
                        class="flex items-center gap-3 border-t xl:border-t-0 pt-4 xl:pt-0 border-[var(--border-default)]"
                    >
                        <div
                            class="flex items-center bg-[var(--surface-secondary)] rounded-lg p-1 border border-[var(--border-default)]"
                        >
                            <button
                                @click="viewMode = 'list'"
                                :class="{
                                    'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm':
                                        viewMode === 'list',
                                    'text-[var(--text-secondary)] hover:text-[var(--text-primary)]':
                                        viewMode !== 'list',
                                }"
                                class="p-2 rounded-md transition-all"
                                title="List View"
                            >
                                <List class="w-4 h-4" />
                            </button>
                            <button
                                @click="viewMode = 'grid'"
                                :class="{
                                    'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm':
                                        viewMode === 'grid',
                                    'text-[var(--text-secondary)] hover:text-[var(--text-primary)]':
                                        viewMode !== 'grid',
                                }"
                                class="p-2 rounded-md transition-all"
                                title="Grid View"
                            >
                                <LayoutGrid class="w-4 h-4" />
                            </button>
                        </div>
                        <div
                            class="w-px h-8 bg-[var(--border-default)] mx-1"
                        ></div>
                        <span
                            class="text-sm text-[var(--text-secondary)] whitespace-nowrap"
                        >
                            Show:
                        </span>
                        <SelectFilter
                            v-model="perPage"
                            :options="[
                                { value: 20, label: '20' },
                                { value: 50, label: '50' },
                                { value: 100, label: '100' },
                            ]"
                            size="sm"
                            class="w-[70px]"
                        />
                    </div>
                </div>

                <!-- Users List/Grid -->
                <div class="relative min-h-[500px]">
                    <!-- Loading State -->
                    <div
                        v-if="isLoading && users.length === 0"
                        class="absolute inset-0 flex items-center justify-center bg-[var(--surface-primary)]/50 z-10"
                    >
                        <Loader2
                            class="w-10 h-10 animate-spin text-[var(--interactive-primary)]"
                        />
                    </div>

                    <!-- View: List -->
                    <div
                        v-if="viewMode === 'list'"
                        class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] shadow-sm overflow-hidden"
                    >
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead
                                    class="bg-[var(--surface-secondary)] border-b border-[var(--border-default)]"
                                >
                                    <tr>
                                        <th class="w-12 px-6 py-4">
                                            <input
                                                type="checkbox"
                                                v-model="selectAll"
                                                @change="toggleSelectAll"
                                                class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)]"
                                            />
                                        </th>
                                        <th
                                            class="px-6 py-4 font-semibold text-[var(--text-secondary)]"
                                        >
                                            User Profile
                                        </th>
                                        <th
                                            class="px-6 py-4 font-semibold text-[var(--text-secondary)]"
                                        >
                                            Role
                                        </th>
                                        <th
                                            class="px-6 py-4 font-semibold text-[var(--text-secondary)]"
                                        >
                                            Status
                                        </th>
                                        <th
                                            class="px-6 py-4 font-semibold text-[var(--text-secondary)]"
                                        >
                                            Activity
                                        </th>
                                        <th
                                            class="px-6 py-4 font-semibold text-[var(--text-secondary)] text-right"
                                        >
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody
                                    class="divide-y divide-[var(--border-default)]"
                                >
                                    <tr
                                        v-for="user in users"
                                        :key="user.public_id"
                                        class="group hover:bg-[var(--surface-primary)] transition-colors"
                                    >
                                        <td class="px-6 py-4">
                                            <input
                                                type="checkbox"
                                                :value="user.public_id"
                                                v-model="selectedUsers"
                                                @change="toggleSelection"
                                                class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)]"
                                            />
                                        </td>
                                        <td class="px-6 py-4">
                                            <div
                                                class="flex items-center gap-4"
                                            >
                                                <div class="relative">
                                                    <div class="relative">
                                                        <Avatar
                                                            :src="
                                                                user.avatar_thumb_url
                                                            "
                                                            :fallback="
                                                                user.initials
                                                            "
                                                            :alt="user.name"
                                                            size="md"
                                                            class="w-10 h-10 ring-2"
                                                            :class="
                                                                getPresenceBorder(
                                                                    user,
                                                                )
                                                            "
                                                        />
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="font-medium text-[var(--text-primary)]"
                                                    >
                                                        {{ user.name }}
                                                    </div>
                                                    <div
                                                        class="text-xs text-[var(--text-tertiary)]"
                                                    >
                                                        {{ user.email }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span
                                                class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-[var(--surface-secondary)] text-[var(--text-secondary)] border border-[var(--border-default)] capitalize"
                                            >
                                                {{
                                                    user.roles[0]?.label ||
                                                    "Member"
                                                }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <div
                                                    class="w-2 h-2 rounded-full"
                                                    :class="{
                                                        'bg-green-500':
                                                            user.status ===
                                                            'active',
                                                        'bg-red-500':
                                                            user.status ===
                                                            'suspended',
                                                        'bg-gray-400':
                                                            user.status ===
                                                            'inactive',
                                                    }"
                                                ></div>
                                                <span
                                                    class="capitalize text-[var(--text-secondary)]"
                                                    >{{ user.status }}</span
                                                >
                                            </div>
                                        </td>
                                        <td
                                            class="px-6 py-4 text-[var(--text-tertiary)]"
                                        >
                                            <div class="flex flex-col text-xs">
                                                <span
                                                    >Joined:
                                                    {{
                                                        formatDate(
                                                            user.created_at,
                                                        )
                                                    }}</span
                                                >
                                                <span v-if="user.last_active_at"
                                                    >Last seen:
                                                    {{
                                                        formatRelativeTime(
                                                            user.last_active_at,
                                                        )
                                                    }}</span
                                                >
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <div
                                                class="flex justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity"
                                            >
                                                <button
                                                    @click="openEditModal(user)"
                                                    class="p-2 rounded-lg hover:bg-[var(--surface-secondary)] text-[var(--text-secondary)] transition-colors"
                                                >
                                                    <Edit2 class="w-4 h-4" />
                                                </button>
                                                <button
                                                    @click="deleteUser(user)"
                                                    class="p-2 rounded-lg hover:bg-red-50 text-red-500 transition-colors"
                                                >
                                                    <Trash2 class="w-4 h-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="users.length === 0 && !isLoading">
                                        <td
                                            colspan="6"
                                            class="px-6 py-12 text-center text-[var(--text-muted)]"
                                        >
                                            No users found matching your
                                            filters.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- View: Grid -->
                    <div
                        v-else
                        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-6"
                    >
                        <div
                            v-for="user in users"
                            :key="user.public_id"
                            class="group relative bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] hover:border-[var(--interactive-primary)] hover:shadow-lg transition-all overflow-hidden flex flex-col items-center p-6"
                        >
                            <!-- Checkbox -->
                            <div
                                class="absolute top-4 left-4 z-10 opacity-0 group-hover:opacity-100 transition-opacity"
                                :class="{
                                    'opacity-100': selectedUsers.includes(
                                        user.public_id,
                                    ),
                                }"
                            >
                                <input
                                    type="checkbox"
                                    :value="user.public_id"
                                    v-model="selectedUsers"
                                    @change="toggleSelection"
                                    class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)]"
                                />
                            </div>

                            <!-- Menu -->
                            <div class="absolute top-4 right-4 z-10">
                                <button
                                    @click="openEditModal(user)"
                                    class="p-1 rounded hover:bg-[var(--surface-secondary)] text-[var(--text-tertiary)] hover:text-[var(--text-primary)]"
                                >
                                    <MoreVertical class="w-4 h-4" />
                                </button>
                            </div>

                            <!-- Avatar -->
                            <div class="relative mb-4">
                                <Avatar
                                    :src="user.avatar_url"
                                    :fallback="user.initials"
                                    :alt="user.name"
                                    size="xl"
                                    class="w-20 h-20 border-4 border-[var(--surface-elevated)] shadow-md"
                                    :class="getPresenceBorder(user)"
                                />
                                <div
                                    v-if="user.has_2fa_enabled"
                                    class="absolute bottom-0 right-0 bg-green-500 text-white p-1 rounded-full border-2 border-[var(--surface-elevated)]"
                                    title="2FA Enabled"
                                >
                                    <Check class="w-3 h-3" />
                                </div>
                            </div>

                            <!-- Info -->
                            <h3
                                class="font-bold text-[var(--text-primary)] text-lg mb-1 text-center"
                            >
                                {{ user.name }}
                            </h3>
                            <p
                                class="text-sm text-[var(--text-secondary)] mb-3 text-center truncate w-full px-2"
                            >
                                {{ user.email }}
                            </p>

                            <!-- Badges -->
                            <div
                                class="flex flex-wrap justify-center gap-2 mb-4"
                            >
                                <span
                                    class="px-2 py-0.5 rounded-full text-[10px] uppercase font-bold tracking-wider bg-[var(--surface-secondary)] text-[var(--text-secondary)] border border-[var(--border-default)]"
                                >
                                    {{ user.roles[0]?.label || "User" }}
                                </span>
                                <span
                                    class="px-2 py-0.5 rounded-full text-[10px] uppercase font-bold tracking-wider"
                                    :class="{
                                        'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400':
                                            user.status === 'active',
                                        'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400':
                                            user.status === 'suspended',
                                        'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400':
                                            user.status === 'inactive',
                                    }"
                                >
                                    {{ user.status }}
                                </span>
                            </div>

                            <!-- Footer -->
                            <div
                                class="w-full pt-4 border-t border-[var(--border-default)] flex justify-between text-xs text-[var(--text-tertiary)]"
                            >
                                <span>Joined</span>
                                <span>{{ formatDate(user.created_at) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div
                        class="flex items-center justify-between border-t border-[var(--border-default)] bg-[var(--surface-elevated)] p-4 rounded-b-xl mt-4 sm:mt-0"
                    >
                        <div
                            class="text-sm text-[var(--text-secondary)] hidden sm:block"
                        >
                            Showing
                            <span class="font-medium">{{
                                (pagination.current_page - 1) *
                                    pagination.per_page +
                                1
                            }}</span>
                            to
                            <span class="font-medium">{{
                                Math.min(
                                    pagination.current_page *
                                        pagination.per_page,
                                    pagination.total,
                                )
                            }}</span>
                            of
                            <span class="font-medium">{{
                                pagination.total
                            }}</span>
                            results
                        </div>
                        <div class="flex gap-2 w-full sm:w-auto justify-center">
                            <button
                                @click="fetchUsers(pagination.current_page - 1)"
                                :disabled="pagination.current_page === 1"
                                class="btn btn-secondary text-sm disabled:opacity-50"
                            >
                                Previous
                            </button>
                            <button
                                @click="fetchUsers(pagination.current_page + 1)"
                                :disabled="
                                    pagination.current_page ===
                                    pagination.last_page
                                "
                                class="btn btn-secondary text-sm disabled:opacity-50"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <div
            v-if="showCreateModal"
            class="modal-overlay flex items-center justify-center p-4"
        >
            <div class="modal-content w-full max-w-lg animate-fade-in-up">
                <div
                    class="px-6 py-4 border-b border-[var(--border-default)] flex items-center justify-between"
                >
                    <h3 class="text-lg font-medium text-[var(--text-primary)]">
                        Add New User
                    </h3>
                    <button
                        @click="showCreateModal = false"
                        class="text-[var(--text-secondary)] hover:text-[var(--text-primary)]"
                    >
                        <X class="w-5 h-5" />
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Full Name</label
                            >
                            <input
                                v-model="formData.name"
                                type="text"
                                class="input"
                            />
                            <p
                                v-if="errors.name"
                                class="text-xs text-[var(--color-error)]"
                            >
                                {{ errors.name[0] }}
                            </p>
                        </div>
                        <div class="space-y-1">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Username</label
                            >
                            <input
                                v-model="formData.username"
                                type="text"
                                class="input"
                            />
                            <p
                                v-if="errors.username"
                                class="text-xs text-[var(--color-error)]"
                            >
                                {{ errors.username[0] }}
                            </p>
                        </div>
                    </div>
                    <div class="space-y-1">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >Email Address</label
                        >
                        <input
                            v-model="formData.email"
                            type="email"
                            class="input"
                        />
                        <p
                            v-if="errors.email"
                            class="text-xs text-[var(--color-error)]"
                        >
                            {{ errors.email[0] }}
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Role</label
                            >
                            <select v-model="formData.role" class="input">
                                <option
                                    v-for="role in roleOptions"
                                    :key="role.value"
                                    :value="role.value"
                                >
                                    {{ role.label }}
                                </option>
                            </select>
                            <p
                                v-if="errors.role"
                                class="text-xs text-[var(--color-error)]"
                            >
                                {{ errors.role[0] }}
                            </p>
                        </div>
                        <div class="space-y-1">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Status</label
                            >
                            <select v-model="formData.status" class="input">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div
                    class="px-6 py-4 bg-[var(--surface-secondary)] flex justify-end gap-3"
                >
                    <button
                        @click="showCreateModal = false"
                        class="btn btn-ghost"
                    >
                        Cancel
                    </button>
                    <button @click="createUser" class="btn btn-primary">
                        Create User
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
