<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import api from "@/lib/api";

const router = useRouter();
import { debounce } from "lodash";
import {
    SearchInput,
    SelectFilter,
    StatsCard,
    Avatar,
    Modal,
    Input,
    Button,
} from "@/components/ui";
import { useNavigationStore } from "@/stores/navigation";
import {
    Trash2,
    Plus,
    Edit2,
    Loader2,
    List,
    LayoutGrid,
    X,
    Users as UsersIcon,
    Activity,
    UserPlus,
    Sparkles,
} from "lucide-vue-next";

import { useAuthStore } from "@/stores/auth";

// State
const authStore = useAuthStore();
const navStore = useNavigationStore();
const teams = ref([]);
const stats = ref({
    total: 0,
    active: 0,
    total_members: 0,
    new_this_month: 0,
});
const isLoading = ref(false);
const searchQuery = ref("");
const statusFilter = ref("");
const perPage = ref(20);
const dateRange = ref({ start: "", end: "" });
const selectedTeams = ref([]);
const selectAll = ref(false);
const viewMode = ref("list"); // 'list' or 'grid'

const ownedTeams = computed(() =>
    teams.value.filter(
        (team) =>
            team.owner_id === authStore.user?.id ||
            team.owner_id === authStore.user?.public_id,
    ),
);
const joinedTeams = computed(() =>
    teams.value.filter(
        (team) =>
            team.owner_id !== authStore.user?.id &&
            team.owner_id !== authStore.user?.public_id,
    ),
);

const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 20,
});

// Route-based scope detection
const isPersonalScope = computed(
    () => router.currentRoute.value.path === "/teams",
);
const currentScope = computed(() =>
    isPersonalScope.value ? "personal" : "all",
);

const showCreateModal = ref(false);
const showEditModal = ref(false);
const currentTeam = ref({});
const users = ref([]); // For owner selection

const formData = ref({
    name: "",
    description: "",
    owner_id: "",
    status: "active",
});

const errors = ref({});

// Fetch stats
const fetchStats = async () => {
    try {
        const response = await api.get("/api/teams/stats", {
            params: { scope: currentScope.value },
        });
        stats.value = response.data;
    } catch (error) {
        console.error("Failed to fetch team stats", error);
    }
};

// Fetch teams
const fetchTeams = debounce(async (page = 1) => {
    isLoading.value = true;
    try {
        const params = {
            page,
            search: searchQuery.value,
            per_page: perPage.value,
            date_from: dateRange.value.start,
            date_to: dateRange.value.end,
            scope: currentScope.value,
        };

        // Filter out empty values
        const cleanParams = Object.fromEntries(
            Object.entries(params).filter(([_, v]) => v != null && v !== ""),
        );

        const response = await api.get("/api/teams", { params: cleanParams });
        teams.value = response.data.data;

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

// Fetch potential owners (users)
const fetchUsers = async (search = "") => {
    try {
        const response = await api.get("/api/users", {
            params: { search, per_page: 20 },
        });
        users.value = response.data.data;
    } catch (error) {
        console.error(error);
    }
};

// Selection Logic
const toggleSelectAll = () => {
    if (selectAll.value) {
        selectedTeams.value = teams.value.map((team) => team.public_id);
    } else {
        selectedTeams.value = [];
    }
};

const toggleSelection = () => {
    selectAll.value =
        selectedTeams.value.length === teams.value.length &&
        teams.value.length > 0;
};

// Debounced search
const debouncedUserSearch = debounce((query) => {
    fetchUsers(query);
}, 300);

// Watchers
watch(
    [
        searchQuery,
        perPage,
        () => dateRange.value.start,
        () => dateRange.value.end,
    ],
    () => {
        fetchTeams(1);
    },
);

// CRUD Actions
const openCreateModal = () => {
    formData.value = {
        name: "",
        description: "",
        owner_id: authStore.user?.id || "", // Default to current user
        status: "active",
    };
    errors.value = {};

    // Only administrators can pick other owners
    if (authStore.hasRole("administrator")) {
        fetchUsers();
    }

    showCreateModal.value = true;
};

const createTeam = async () => {
    try {
        await api.post("/api/teams", formData.value);
        showCreateModal.value = false;
        fetchTeams(1);
        fetchStats();
        authStore.fetchUser(); // Refresh user to update teams list
        navStore.fetchNavigation(); // Refresh sidebar
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
        await api.put(
            `/api/teams/${currentTeam.value.public_id}`,
            formData.value,
        );
        showEditModal.value = false;
        fetchTeams(pagination.value.current_page);
        fetchStats();
        authStore.fetchUser(); // Refresh user to update team details
        navStore.fetchNavigation(); // Refresh sidebar
    } catch (error) {
        if (error.response && error.response.data.errors) {
            errors.value = error.response.data.errors;
        }
    }
};

const deleteTeam = async (team) => {
    if (!confirm("Are you sure you want to delete this team?")) return;
    try {
        await api.delete(`/api/teams/${team.public_id}`);
        fetchTeams(pagination.value.current_page);
        fetchStats();
        authStore.fetchUser(); // Refresh user to remove deleted team
        navStore.fetchNavigation(); // Refresh sidebar
    } catch (error) {
        console.error(error);
    }
};

onMounted(() => {
    fetchTeams();
    fetchStats();

    // Auto-open modal if create=true is in query
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get("create") === "true") {
        openCreateModal();
        // Clean up URL without reload
        router.replace({ query: {} });
    }
});
</script>

<template>
    <div class="relative min-h-screen p-6 space-y-8 font-sans">
        <!-- Background Gradient Orb (Optional aesthetic touch) -->
        <div
            class="fixed top-0 left-0 w-full h-full overflow-hidden pointer-events-none -z-10"
        >
            <div
                class="absolute top-[-10%] right-[-5%] w-[500px] h-[500px] bg-purple-500/10 rounded-full blur-3xl"
            ></div>
            <div
                class="absolute bottom-[-10%] left-[-5%] w-[600px] h-[600px] bg-blue-500/10 rounded-full blur-3xl"
            ></div>
        </div>

        <!-- Header Section -->
        <div
            class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 animate-fade-in-down"
        >
            <div>
                <h1
                    class="text-3xl font-bold text-(--text-primary) tracking-tight"
                >
                    Teams
                </h1>
                <p class="text-(--text-secondary) mt-1 text-lg">
                    Manage your organization's teams and members.
                </p>
            </div>

            <div class="flex items-center gap-4">
                <!-- View Toggles -->
                <div
                    class="flex items-center bg-(--surface-primary)/80 backdrop-blur-sm rounded-xl p-1.5 border border-(--border-default) shadow-sm"
                >
                    <button
                        @click="viewMode = 'list'"
                        :class="{
                            'bg-(--surface-elevated) text-(--text-primary) shadow-sm':
                                viewMode === 'list',
                            'text-(--text-secondary) hover:text-(--text-primary)':
                                viewMode !== 'list',
                        }"
                        class="p-2 rounded-lg transition-all duration-200"
                    >
                        <List class="w-4 h-4" />
                    </button>
                    <button
                        @click="viewMode = 'grid'"
                        :class="{
                            'bg-(--surface-elevated) text-(--text-primary) shadow-sm':
                                viewMode === 'grid',
                            'text-(--text-secondary) hover:text-(--text-primary)':
                                viewMode !== 'grid',
                        }"
                        class="p-2 rounded-lg transition-all duration-200"
                    >
                        <LayoutGrid class="w-4 h-4" />
                    </button>
                </div>

                <div class="h-8 w-px bg-(--border-default)"></div>

                <button
                    v-if="selectedTeams.length > 0"
                    @click="deleteSelected"
                    class="btn btn-secondary btn-sm text-error border border-error/20 hover:bg-red-500/10 backdrop-blur-sm shadow-sm hover:shadow transition-all"
                >
                    <Trash2 class="w-4 h-4" />
                    Delete Selected ({{ selectedTeams.length }})
                </button>

                <button
                    @click="openCreateModal"
                    class="btn btn-primary shadow-lg shadow-purple-500/20 hover:shadow-purple-500/30 transition-all active:scale-95"
                >
                    <Plus class="w-4 h-4" />
                    Create Team
                </button>
            </div>
        </div>

        <!-- Stats Overview -->
        <div
            class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 animate-fade-in-down"
            style="animation-delay: 0.1s"
        >
            <StatsCard
                label="Total Teams"
                :value="stats.total"
                :icon="UsersIcon"
                variant="primary"
            />
            <StatsCard
                label="Active Teams"
                :value="stats.active"
                :icon="Activity"
                variant="success"
            />
            <StatsCard
                label="Total Members"
                :value="stats.total_members"
                :icon="UserPlus"
                variant="info"
            />
            <StatsCard
                label="New This Month"
                :value="stats.new_this_month"
                :icon="Sparkles"
                variant="warning"
            />
        </div>

        <!-- Filter Bar -->
        <div
            class="flex flex-col xl:flex-row gap-4 p-5 bg-(--surface-primary)/70 backdrop-blur-md rounded-2xl border border-(--border-default) shadow-sm sticky top-4 z-20 transition-all duration-300 hover:shadow-md hover:bg-(--surface-primary)/80"
        >
            <div class="flex-1 flex flex-col sm:flex-row gap-4 items-center">
                <SearchInput
                    v-model="searchQuery"
                    placeholder="Search teams..."
                    class="flex-1 w-full sm:w-auto shadow-sm"
                />
            </div>

            <div
                class="hidden xl:block w-px bg-(--border-default) mx-2 my-2"
            ></div>

            <div
                class="flex flex-col sm:flex-row gap-4 items-center w-full xl:w-auto"
            >
                <div
                    class="flex flex-wrap items-center justify-center gap-2 bg-(--surface-elevated)/50 rounded-lg p-1 border border-(--border-default)"
                >
                    <input
                        v-model="dateRange.start"
                        type="date"
                        class="bg-transparent border-none text-sm text-(--text-primary) focus:ring-0 p-1.5"
                    />
                    <span class="text-(--text-muted) text-xs">to</span>
                    <input
                        v-model="dateRange.end"
                        type="date"
                        class="bg-transparent border-none text-sm text-(--text-primary) focus:ring-0 p-1.5"
                    />
                </div>

                <div class="w-full sm:w-24">
                    <SelectFilter
                        v-model="perPage"
                        :options="[
                            { value: 20, label: '20' },
                            { value: 50, label: '50' },
                            { value: 100, label: '100' },
                            { value: 200, label: '200' },
                        ]"
                        :show-placeholder="false"
                        size="md"
                        class="w-full shadow-sm"
                    />
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div
            class="bg-[var(--surface-primary)]/60 backdrop-blur-xl rounded-2xl border border-[var(--border-default)] shadow-xl overflow-hidden min-h-[500px] flex flex-col relative transition-all duration-500"
        >
            <Transition name="fade" mode="out-in">
                <!-- List View (Table on Desktop, Cards on Mobile) -->
                <div
                    v-if="viewMode === 'list'"
                    key="list"
                    class="flex-1 overflow-x-auto"
                >
                    <table
                        class="hidden md:table w-full text-left text-sm relative border-collapse"
                    >
                        <!-- ... (Keep table the same but hidden on mobile) ... -->
                        <thead
                            class="bg-[var(--surface-tertiary)]/50 text-[var(--text-secondary)] font-semibold text-xs uppercase tracking-wider backdrop-blur-md sticky top-0 z-10 border-b border-[var(--border-default)]"
                        >
                            <tr>
                                <th class="w-16 px-6 py-4 text-center">
                                    <input
                                        type="checkbox"
                                        v-model="selectAll"
                                        @change="toggleSelectAll"
                                        class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)] w-4 h-4 cursor-pointer"
                                    />
                                </th>
                                <th class="px-6 py-4">Team Info</th>
                                <th class="px-6 py-4">Owner</th>
                                <th class="px-6 py-4">Stats</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody v-if="isLoading" key="loading">
                            <tr>
                                <td colspan="6" class="px-6 py-20 text-center">
                                    <div
                                        class="flex flex-col items-center gap-3"
                                    >
                                        <Loader2
                                            class="w-8 h-8 animate-spin text-[var(--interactive-primary)]"
                                        />
                                        <span
                                            class="text-[var(--text-secondary)] animate-pulse"
                                            >Syncing teams...</span
                                        >
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tbody v-else-if="teams.length === 0" key="empty">
                            <tr>
                                <td
                                    colspan="6"
                                    class="px-6 py-20 text-center bg-[var(--surface-primary)]/20"
                                >
                                    <div
                                        class="flex flex-col items-center gap-4"
                                    >
                                        <div
                                            class="w-16 h-16 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center"
                                        >
                                            <UsersIcon
                                                class="w-8 h-8 text-[var(--text-muted)]"
                                            />
                                        </div>
                                        <div>
                                            <h3
                                                class="text-lg font-medium text-[var(--text-primary)]"
                                            >
                                                No teams found
                                            </h3>
                                            <p
                                                class="text-[var(--text-secondary)] max-w-xs mx-auto mt-1"
                                            >
                                                Try adjusting your filters or
                                                create a new team to get
                                                started.
                                            </p>
                                        </div>
                                        <button
                                            @click="openCreateModal"
                                            class="btn btn-primary mt-2"
                                        >
                                            <Plus class="w-4 h-4" />
                                            Create Team
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tbody
                            v-else
                            class="divide-y divide-[var(--border-default)]"
                        >
                            <tr
                                v-for="team in isPersonalScope
                                    ? [...ownedTeams, ...joinedTeams]
                                    : teams"
                                :key="team.public_id"
                                class="group hover:bg-[var(--surface-secondary)]/60 transition-colors duration-200"
                            >
                                <td class="px-6 py-4 text-center">
                                    <input
                                        type="checkbox"
                                        :value="team.public_id"
                                        v-model="selectedTeams"
                                        @change="toggleSelection"
                                        class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)] w-4 h-4 cursor-pointer opacity-50 group-hover:opacity-100 transition-opacity"
                                    />
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-4">
                                        <Avatar
                                            :src="team.avatar_url"
                                            size="md"
                                            class="ring-1 ring-[var(--border-default)] shadow-sm"
                                        />
                                        <div>
                                            <div
                                                class="font-semibold text-[var(--text-primary)] text-base"
                                            >
                                                {{ team.name }}
                                            </div>
                                            <div
                                                class="text-xs text-[var(--text-secondary)] font-mono"
                                            >
                                                /{{ team.slug }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div
                                        v-if="team.owner"
                                        class="flex items-center gap-3"
                                    >
                                        <Avatar
                                            :src="team.owner.avatar_url"
                                            size="sm"
                                        />
                                        <span
                                            class="text-sm text-[var(--text-primary)]"
                                            >{{ team.owner.name }}</span
                                        >
                                    </div>
                                </td>
                                <td
                                    class="px-6 py-4 text-sm text-[var(--text-secondary)]"
                                >
                                    {{ team.member_count }} Members
                                </td>
                                <td class="px-6 py-4">
                                    <div
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold"
                                        :class="{
                                            'bg-emerald-500/10 text-emerald-700': team.status === 'active',
                                            'bg-amber-500/10 text-amber-700': team.status === 'pending',
                                            'bg-gray-500/10 text-gray-700': !['active', 'pending'].includes(team.status)
                                        }"
                                    >
                                        {{ team.status === 'pending' ? 'Pending Approval' : team.status }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div
                                        class="flex items-center justify-end gap-2"
                                    >
                                        <button
                                            :disabled="team.status === 'pending'"
                                            @click="
                                                team.status !== 'pending' && router.push({
                                                    name: 'team-profile',
                                                    params: {
                                                        identifier: team.slug || team.public_id,
                                                    },
                                                })
                                            "
                                            :class="{'opacity-50 cursor-not-allowed': team.status === 'pending'}"
                                        >
                                            <LayoutGrid class="w-4 h-4" />
                                        </button>
                                        <button
                                            v-if="team.can?.update"
                                            @click="openEditModal(team)"
                                        >
                                            <Edit2 class="w-4 h-4" />
                                        </button>
                                        <button
                                            v-if="team.can?.delete"
                                            @click="deleteTeam(team)"
                                        >
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Mobile Card View -->
                    <div
                        class="md:hidden flex flex-col divide-y divide-[var(--border-default)]"
                    >
                        <div v-if="isLoading" class="p-10 text-center">
                            <Loader2
                                class="w-8 h-8 animate-spin mx-auto text-[var(--interactive-primary)]"
                            />
                        </div>
                        <div
                            v-else-if="teams.length === 0"
                            class="p-10 text-center text-[var(--text-secondary)]"
                        >
                            No teams found.
                        </div>
                        <div
                            v-for="team in isPersonalScope
                                ? [...ownedTeams, ...joinedTeams]
                                : teams"
                            :key="'mob-' + team.public_id"
                            class="p-4 space-y-4"
                        >
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <input
                                        type="checkbox"
                                        :value="team.public_id"
                                        v-model="selectedTeams"
                                        @change="toggleSelection"
                                        class="rounded border-[var(--border-strong)] w-4 h-4"
                                    />
                                    <Avatar :src="team.avatar_url" size="md" />
                                    <div>
                                        <div
                                            class="font-bold text-[var(--text-primary)]"
                                        >
                                            {{ team.name }}
                                        </div>
                                        <div
                                            class="text-xs text-[var(--text-secondary)] font-mono"
                                        >
                                            /{{ team.slug }}
                                        </div>
                                    </div>
                                </div>
                                <div
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider"
                                    :class="{
                                        'bg-emerald-500/10 text-emerald-600': team.status === 'active',
                                        'bg-amber-500/10 text-amber-600': team.status === 'pending',
                                        'bg-gray-500/10 text-gray-600': !['active', 'pending'].includes(team.status)
                                    }"
                                >
                                    {{ team.status === 'pending' ? 'Pending Approval' : team.status }}
                                </div>
                            </div>
                            <div
                                class="flex items-center justify-between text-sm"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="text-[var(--text-muted)] text-xs"
                                        >Owner:</span
                                    >
                                    <span
                                        class="text-[var(--text-primary)] font-medium"
                                        >{{
                                            team.owner?.name || "Unassigned"
                                        }}</span
                                    >
                                </div>
                                <div
                                    class="text-[var(--text-secondary)] font-medium"
                                >
                                    {{ team.member_count }} Members
                                </div>
                            </div>
                            <div class="flex items-center gap-2 pt-2">
                                <button
                                    :disabled="team.status === 'pending'"
                                    @click="
                                        team.status !== 'pending' && router.push({
                                            name: 'team-profile',
                                            params: {
                                                identifier: team.slug || team.public_id,
                                            },
                                        })
                                    "
                                    class="btn btn-secondary flex-1 py-1 px-2 text-xs h-9"
                                    :class="{'opacity-50 cursor-not-allowed': team.status === 'pending'}"
                                >
                                    <LayoutGrid class="w-3.5 h-3.5 mr-1" />
                                    Dashboard
                                </button>
                                <button
                                    v-if="team.can?.update"
                                    @click="openEditModal(team)"
                                    class="btn btn-secondary py-1 px-2 text-xs h-9"
                                >
                                    <Edit2 class="w-3.5 h-3.5" />
                                </button>
                                <button
                                    v-if="team.can?.delete"
                                    @click="deleteTeam(team)"
                                    class="btn btn-secondary text-error py-1 px-2 text-xs h-9 border-error/10"
                                >
                                    <Trash2 class="w-3.5 h-3.5" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Grid View -->
                <div v-else key="grid" class="flex-1 p-6 overflow-y-auto">
                    <div
                        v-if="isLoading"
                        class="flex flex-col items-center justify-center p-20 text-[var(--text-muted)] h-full"
                    >
                        <Loader2
                            class="w-10 h-10 animate-spin mb-4 text-[var(--interactive-primary)]"
                        />
                        <span class="animate-pulse">Loading teams...</span>
                    </div>
                    <div
                        v-else-if="teams.length === 0"
                        class="flex flex-col items-center justify-center p-20 text-[var(--text-muted)] h-full"
                    >
                        <div
                            class="w-20 h-20 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center mb-6"
                        >
                            <UsersIcon
                                class="w-10 h-10 text-[var(--text-muted)]"
                            />
                        </div>
                        <span class="text-xl font-medium">No teams found.</span>
                        <button
                            @click="openCreateModal"
                            class="btn btn-primary mt-4"
                        >
                            Create Team
                        </button>
                    </div>
                    <div v-else-if="isPersonalScope" class="space-y-12">
                        <!-- Owned Teams Grid -->
                        <section v-if="ownedTeams.length > 0">
                            <h2
                                class="text-lg font-bold text-[var(--text-primary)] mb-6 flex items-center gap-2"
                            >
                                <Sparkles class="w-5 h-5 text-purple-500" />
                                Owned Teams
                            </h2>
                            <div
                                class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"
                            >
                                <div
                                    v-for="team in ownedTeams"
                                    :key="'owned-grid-' + team.public_id"
                                    class="group bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-2xl p-6 hover:shadow-xl hover:shadow-purple-500/5 hover:-translate-y-1 transition-all duration-300 relative overflow-hidden backdrop-blur-sm"
                                >
                                    <!-- Card Content -->
                                    <div
                                        class="absolute top-4 left-4 z-10 opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                                    >
                                        <input
                                            type="checkbox"
                                            :value="team.public_id"
                                            v-model="selectedTeams"
                                            @change="toggleSelection"
                                            class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)] w-5 h-5 cursor-pointer shadow-sm"
                                        />
                                    </div>
                                    <div
                                        class="flex items-start justify-between mb-6"
                                    >
                                        <div
                                            class="w-14 h-14 rounded-2xl bg-gradient-to-br from-[var(--surface-tertiary)] to-[var(--surface-secondary)] flex items-center justify-center text-[var(--interactive-primary)] text-xl font-bold shadow-inner ring-1 ring-[var(--border-default)] group-hover:rotate-3 transition-transform duration-300"
                                        >
                                            {{ team.initials }}
                                        </div>
                                        <div
                                            class="flex gap-1 opacity-100 transition-opacity duration-200"
                                        >
                                            <button
                                                v-if="team.can?.update"
                                                @click="openEditModal(team)"
                                                class="p-2 rounded-lg text-[var(--text-secondary)] hover:text-[var(--interactive-primary)] hover:bg-[var(--interactive-primary)]/10 transition-colors"
                                            >
                                                <Edit2 class="w-4 h-4" />
                                            </button>
                                            <button
                                                v-if="team.can?.delete"
                                                @click="deleteTeam(team)"
                                                class="p-2 rounded-lg text-[var(--text-secondary)] hover:text-[var(--color-error)] hover:bg-[var(--color-error)]/10 transition-colors"
                                            >
                                                <Trash2 class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mb-6">
                                        <h3
                                            class="font-bold text-lg text-[var(--text-primary)] mb-1 truncate"
                                        >
                                            {{ team.name }}
                                        </h3>
                                        <p
                                            class="text-xs font-mono text-[var(--text-secondary)] truncate bg-[var(--surface-tertiary)]/50 w-fit px-2 py-0.5 rounded-md"
                                        >
                                            /{{ team.slug }}
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap gap-2 mb-6">
                                        <div
                                            class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-[var(--surface-tertiary)]/50 text-xs font-medium text-[var(--text-secondary)] border border-[var(--border-default)]"
                                        >
                                            <UsersIcon class="w-3.5 h-3.5" />{{
                                                team.member_count
                                            }}
                                        </div>
                                        <div
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium border"
                                            :class="{
                                                'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20':
                                                    team.status === 'active',
                                                'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20':
                                                    team.status === 'pending',
                                                'bg-gray-500/10 text-gray-600 dark:text-gray-400 border-gray-500/20':
                                                    !['active', 'pending'].includes(team.status),
                                            }"
                                        >
                                            <span
                                                class="w-1.5 h-1.5 rounded-full"
                                                :class="{
                                                    'bg-emerald-500': team.status === 'active',
                                                    'bg-amber-500': team.status === 'pending',
                                                    'bg-gray-500': !['active', 'pending'].includes(team.status)
                                                }"
                                            ></span>
                                            {{ team.status === 'pending' ? 'Pending Approval' : team.status }}
                                        </div>
                                    </div>
                                    <div
                                        class="pt-4 border-t border-[var(--border-default)] flex justify-between items-center"
                                    >
                                        <span
                                            class="text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                            >Owner</span
                                        >
                                        <div
                                            v-if="team.owner"
                                            class="flex items-center gap-2"
                                        >
                                            <span
                                                class="text-xs font-semibold text-[var(--text-primary)]"
                                                >{{ team.owner.name }}</span
                                            >
                                            <div
                                                class="w-6 h-6 rounded-full bg-gradient-to-tr from-purple-500 to-blue-500 flex items-center justify-center text-[10px] text-white font-bold shadow-sm"
                                            >
                                                {{ team.owner.initials }}
                                            </div>
                                        </div>
                                        <span
                                            v-else
                                            class="text-xs text(--text-muted)] italic"
                                            >Unassigned</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Joined Teams Grid -->
                        <section v-if="joinedTeams.length > 0">
                            <h2
                                class="text-lg font-bold text-[var(--text-primary)] mb-6 flex items-center gap-2"
                            >
                                <UsersIcon class="w-5 h-5 text-blue-500" />
                                Joined Teams
                            </h2>
                            <div
                                class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"
                            >
                                <div
                                    v-for="team in joinedTeams"
                                    :key="'joined-grid-' + team.public_id"
                                    class="group bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-2xl p-6 hover:shadow-xl hover:shadow-purple-500/5 hover:-translate-y-1 transition-all duration-300 relative overflow-hidden backdrop-blur-sm"
                                >
                                    <!-- Card Content -->
                                    <div
                                        class="absolute top-4 left-4 z-10 opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                                    >
                                        <input
                                            type="checkbox"
                                            :value="team.public_id"
                                            v-model="selectedTeams"
                                            @change="toggleSelection"
                                            class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)] w-5 h-5 cursor-pointer shadow-sm"
                                        />
                                    </div>
                                    <div
                                        class="flex items-start justify-between mb-6"
                                    >
                                        <div
                                            class="w-14 h-14 rounded-2xl bg-gradient-to-br from-[var(--surface-tertiary)] to-[var(--surface-secondary)] flex items-center justify-center text-[var(--interactive-primary)] text-xl font-bold shadow-inner ring-1 ring-[var(--border-default)] group-hover:rotate-3 transition-transform duration-300"
                                        >
                                            {{ team.initials }}
                                        </div>
                                        <div
                                            class="flex gap-1 opacity-100 transition-opacity duration-200"
                                        >
                                            <button
                                                v-if="team.can?.update"
                                                @click="openEditModal(team)"
                                                class="p-2 rounded-lg text-[var(--text-secondary)] hover:text-[var(--interactive-primary)] hover:bg-[var(--interactive-primary)]/10 transition-colors"
                                            >
                                                <Edit2 class="w-4 h-4" />
                                            </button>
                                            <button
                                                v-if="team.can?.delete"
                                                @click="deleteTeam(team)"
                                                class="p-2 rounded-lg text-[var(--text-secondary)] hover:text-[var(--color-error)] hover:bg-[var(--color-error)]/10 transition-colors"
                                            >
                                                <Trash2 class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mb-6">
                                        <h3
                                            class="font-bold text-lg text-[var(--text-primary)] mb-1 truncate"
                                        >
                                            {{ team.name }}
                                        </h3>
                                        <p
                                            class="text-xs font-mono text-[var(--text-secondary)] truncate bg-[var(--surface-tertiary)]/50 w-fit px-2 py-0.5 rounded-md"
                                        >
                                            /{{ team.slug }}
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap gap-2 mb-6">
                                        <div
                                            class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-[var(--surface-tertiary)]/50 text-xs font-medium text-[var(--text-secondary)] border border-[var(--border-default)]"
                                        >
                                            <UsersIcon class="w-3.5 h-3.5" />{{
                                                team.member_count
                                            }}
                                        </div>
                                        <div
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium border"
                                            :class="{
                                                'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20':
                                                    team.status === 'active',
                                                'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20':
                                                    team.status === 'pending',
                                                'bg-gray-500/10 text-gray-600 dark:text-gray-400 border-gray-500/20':
                                                    !['active', 'pending'].includes(team.status),
                                            }"
                                        >
                                            <span
                                                class="w-1.5 h-1.5 rounded-full"
                                                :class="{
                                                    'bg-emerald-500': team.status === 'active',
                                                    'bg-amber-500': team.status === 'pending',
                                                    'bg-gray-500': !['active', 'pending'].includes(team.status)
                                                }"
                                            ></span>
                                            {{ team.status === 'pending' ? 'Pending Approval' : team.status }}
                                        </div>
                                    </div>
                                    <div
                                        class="pt-4 border-t border-[var(--border-default)] flex justify-between items-center"
                                    >
                                        <span
                                            class="text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                            >Owner</span
                                        >
                                        <div
                                            v-if="team.owner"
                                            class="flex items-center gap-2"
                                        >
                                            <span
                                                class="text-xs font-semibold text-[var(--text-primary)]"
                                                >{{ team.owner.name }}</span
                                            >
                                            <div
                                                class="w-6 h-6 rounded-full bg-gradient-to-tr from-purple-500 to-blue-500 flex items-center justify-center text-[10px] text-white font-bold shadow-sm"
                                            >
                                                {{ team.owner.initials }}
                                            </div>
                                        </div>
                                        <span
                                            v-else
                                            class="text-xs text-[var(--text-muted)] italic"
                                            >Unassigned</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                    <TransitionGroup
                        v-else
                        tag="div"
                        name="list-stagger"
                        class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"
                    >
                        <div
                            v-for="team in teams"
                            :key="team.public_id"
                            class="group bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-2xl p-6 hover:shadow-xl hover:shadow-purple-500/5 hover:-translate-y-1 transition-all duration-300 relative overflow-hidden backdrop-blur-sm"
                        >
                            <!-- Selection Overlay on Hover -->
                            <div
                                class="absolute top-4 left-4 z-10 opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                            >
                                <input
                                    type="checkbox"
                                    :value="team.public_id"
                                    v-model="selectedTeams"
                                    @change="toggleSelection"
                                    class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)] w-5 h-5 cursor-pointer shadow-sm"
                                />
                            </div>

                            <!-- Card Header -->
                            <div class="flex items-start justify-between mb-6">
                                <div
                                    class="w-14 h-14 rounded-2xl bg-gradient-to-br from-[var(--surface-tertiary)] to-[var(--surface-secondary)] flex items-center justify-center text-[var(--interactive-primary)] text-xl font-bold shadow-inner ring-1 ring-[var(--border-default)] group-hover:rotate-3 transition-transform duration-300"
                                >
                                    {{ team.initials }}
                                </div>
                                <div
                                    class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity duration-200 translate-x-2 group-hover:translate-x-0"
                                >
                                    <button
                                        v-if="team.can?.update"
                                        @click="openEditModal(team)"
                                        class="p-2 rounded-lg text-[var(--text-secondary)] hover:text-[var(--interactive-primary)] hover:bg-[var(--interactive-primary)]/10 transition-colors"
                                    >
                                        <span class="sr-only">Edit Team</span>
                                        <Edit2 class="w-4 h-4" />
                                    </button>
                                    <button
                                        v-if="team.can?.delete"
                                        @click="deleteTeam(team)"
                                        class="p-2 rounded-lg text-[var(--text-secondary)] hover:text-[var(--color-error)] hover:bg-[var(--color-error)]/10 transition-colors"
                                    >
                                        <span class="sr-only">Delete Team</span>
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>

                            <!-- Card Body -->
                            <div class="mb-6">
                                <h3
                                    class="font-bold text-lg text-[var(--text-primary)] mb-1 truncate"
                                >
                                    {{ team.name }}
                                </h3>
                                <p
                                    class="text-xs font-mono text-[var(--text-secondary)] truncate bg-[var(--surface-tertiary)]/50 w-fit px-2 py-0.5 rounded-md"
                                >
                                    /{{ team.slug }}
                                </p>
                            </div>

                            <!-- Stats Badges -->
                            <div class="flex flex-wrap gap-2 mb-6">
                                <div
                                    class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-[var(--surface-tertiary)]/50 text-xs font-medium text-[var(--text-secondary)] border border-[var(--border-default)]"
                                >
                                    <UsersIcon class="w-3.5 h-3.5" />
                                    {{ team.member_count }}
                                </div>
                                <div
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium border"
                                    :class="{
                                        'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20':
                                            team.status === 'active',
                                        'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20':
                                            team.status === 'pending',
                                        'bg-gray-500/10 text-gray-600 dark:text-gray-400 border-gray-500/20':
                                            !['active', 'pending'].includes(team.status),
                                    }"
                                >
                                    <span
                                        class="w-1.5 h-1.5 rounded-full"
                                        :class="{
                                            'bg-emerald-500': team.status === 'active',
                                            'bg-amber-500': team.status === 'pending',
                                            'bg-gray-500': !['active', 'pending'].includes(team.status)
                                        }"
                                    ></span>
                                    {{ team.status === 'pending' ? 'Pending Approval' : team.status }}
                                </div>
                            </div>

                            <!-- Card Footer -->
                            <div
                                class="pt-4 border-t border-[var(--border-default)] flex justify-between items-center"
                            >
                                <span
                                    class="text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                    >Owner</span
                                >
                                <div
                                    v-if="team.owner"
                                    class="flex items-center gap-2"
                                >
                                    <span
                                        class="text-xs font-semibold text-[var(--text-primary)]"
                                        >{{ team.owner.name }}</span
                                    >
                                    <div
                                        class="w-6 h-6 rounded-full bg-gradient-to-tr from-purple-500 to-blue-500 flex items-center justify-center text-[10px] text-white font-bold shadow-sm"
                                    >
                                        {{ team.owner.initials }}
                                    </div>
                                </div>
                                <span
                                    v-else
                                    class="text-xs text-[var(--text-muted)] italic"
                                    >Unassigned</span
                                >
                            </div>
                        </div>
                    </TransitionGroup>
                </div>
            </Transition>

            <!-- Pagination Grid - Sticky Footer -->
            <div
                class="px-6 py-4 border-t border-[var(--border-default)] bg-[var(--surface-elevated)]/80 backdrop-blur-md flex items-center justify-between sticky bottom-0 z-10"
            >
                <div class="text-sm text-[var(--text-secondary)]">
                    Showing
                    <span class="font-medium text-[var(--text-primary)]">{{
                        (pagination.current_page - 1) * pagination.per_page + 1
                    }}</span>
                    to
                    <span class="font-medium text-[var(--text-primary)]">{{
                        Math.min(
                            pagination.current_page * pagination.per_page,
                            pagination.total,
                        )
                    }}</span>
                    of
                    <span class="font-medium text-[var(--text-primary)]">{{
                        pagination.total
                    }}</span>
                    results
                </div>
                <div class="flex items-center gap-2">
                    <button
                        @click="fetchTeams(pagination.current_page - 1)"
                        :disabled="pagination.current_page === 1"
                        class="btn btn-secondary py-2 px-4 text-sm disabled:opacity-50 hover:bg-[var(--surface-tertiary)] transition-colors"
                    >
                        Previous
                    </button>
                    <button
                        @click="fetchTeams(pagination.current_page + 1)"
                        :disabled="
                            pagination.current_page === pagination.last_page
                        "
                        class="btn btn-secondary py-2 px-4 text-sm disabled:opacity-50 hover:bg-[var(--surface-tertiary)] transition-colors"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <Modal
            v-model:open="showCreateModal"
            title="Create New Team"
            description="Add a new team to your organization"
            size="lg"
        >
            <div class="space-y-5">
                <div class="space-y-1.5">
                    <label
                        class="text-sm font-semibold text-[var(--text-secondary)]"
                        >Team Name</label
                    >
                    <Input
                        v-model="formData.name"
                        placeholder="e.g. Engineering"
                        :error="errors.name ? errors.name[0] : ''"
                    />
                </div>
                <div class="space-y-1.5">
                    <label
                        class="text-sm font-semibold text-[var(--text-secondary)]"
                        >Description</label
                    >
                    <textarea
                        v-model="formData.description"
                        rows="3"
                        class="input w-full focus:ring-2 focus:ring-purple-500/20"
                        placeholder="What is this team responsible for?"
                    ></textarea>
                    <p
                        v-if="errors.description"
                        class="text-xs text-[var(--color-error)] mt-1 font-medium"
                    >
                        {{ errors.description[0] }}
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-5">
                    <div
                        v-if="authStore.hasRole('administrator')"
                        class="space-y-1.5"
                    >
                        <label
                            class="text-sm font-semibold text-[var(--text-secondary)]"
                            >Owner</label
                        >
                        <select
                            v-model="formData.owner_id"
                            class="input w-full focus:ring-2 focus:ring-purple-500/20"
                        >
                            <option value="" disabled>Select Owner</option>
                            <option
                                v-for="user in users"
                                :key="user.id"
                                :value="user.id"
                            >
                                {{ user.name }}
                            </option>
                        </select>
                        <p
                            v-if="errors.owner_id"
                            class="text-xs text-[var(--color-error)] mt-1 font-medium"
                        >
                            {{ errors.owner_id[0] }}
                        </p>
                    </div>
                    <div
                        v-if="authStore.hasRole('administrator')"
                        :class="
                            authStore.hasRole('administrator')
                                ? 'space-y-1.5'
                                : 'col-span-2 space-y-1.5'
                        "
                    >
                        <label
                            class="text-sm font-semibold text-[var(--text-secondary)]"
                            >Status</label
                        >
                        <select
                            v-model="formData.status"
                            class="input w-full focus:ring-2 focus:ring-purple-500/20"
                        >
                            <option value="active">Active</option>
                            <option value="pending">Pending Approval</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button variant="ghost" @click="showCreateModal = false"
                    >Cancel</Button
                >
                <Button
                    @click="createTeam"
                    variant="primary"
                    class="shadow-lg shadow-purple-500/20 hover:shadow-purple-500/30"
                    >Create Team</Button
                >
            </template>
        </Modal>

        <!-- Edit Modal -->
        <Modal
            v-model:open="showEditModal"
            title="Edit Team"
            description="Update team details and settings"
            size="lg"
        >
            <div class="space-y-5">
                <div class="space-y-1.5">
                    <label
                        class="text-sm font-semibold text-[var(--text-secondary)]"
                        >Team Name</label
                    >
                    <Input
                        v-model="formData.name"
                        :error="errors.name ? errors.name[0] : ''"
                    />
                </div>
                <div class="space-y-1.5">
                    <label
                        class="text-sm font-semibold text-[var(--text-secondary)]"
                        >Description</label
                    >
                    <textarea
                        v-model="formData.description"
                        rows="3"
                        class="input w-full focus:ring-2 focus:ring-purple-500/20"
                    ></textarea>
                    <p
                        v-if="errors.description"
                        class="text-xs text-[var(--color-error)] mt-1 font-medium"
                    >
                        {{ errors.description[0] }}
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-5">
                    <div
                        v-if="authStore.hasRole('administrator')"
                        class="space-y-1.5"
                    >
                        <label
                            class="text-sm font-semibold text-[var(--text-secondary)]"
                            >Owner</label
                        >
                        <select
                            v-model="formData.owner_id"
                            class="input w-full focus:ring-2 focus:ring-purple-500/20"
                        >
                            <option value="" disabled>Select Owner</option>
                            <option
                                v-for="user in users"
                                :key="user.id"
                                :value="user.id"
                            >
                                {{ user.name }}
                            </option>
                        </select>
                        <p
                            v-if="errors.owner_id"
                            class="text-xs text-[var(--color-error)] mt-1 font-medium"
                        >
                            {{ errors.owner_id[0] }}
                        </p>
                    </div>
                    <div v-if="authStore.hasRole('administrator')" class="space-y-1.5">
                        <label
                            class="text-sm font-semibold text-[var(--text-secondary)]"
                            >Status</label
                        >
                        <select
                            v-model="formData.status"
                            class="input w-full focus:ring-2 focus:ring-purple-500/20"
                        >
                            <option value="active">Active</option>
                            <option value="pending">Pending Approval</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button variant="ghost" @click="showEditModal = false"
                    >Cancel</Button
                >
                <Button
                    @click="updateTeam"
                    variant="primary"
                    class="shadow-lg shadow-purple-500/20 hover:shadow-purple-500/30"
                    >Save Changes</Button
                >
            </template>
        </Modal>
    </div>
</template>

<style scoped>
.animate-fade-in-down {
    animation: fadeInDown 0.5s ease-out;
}
.animate-scale-in {
    animation: scaleIn 0.3s ease-out;
}

@keyframes fadeInDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes scaleIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

/* Staggered list fade-in */
.list-stagger-enter-active,
.list-stagger-leave-active {
    transition: all 0.3s ease;
}
.list-stagger-enter-from,
.list-stagger-leave-to {
    opacity: 0;
    transform: translateY(10px);
}
</style>
