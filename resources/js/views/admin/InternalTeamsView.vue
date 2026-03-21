<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { debounce } from "lodash";
import api from "@/lib/api";
import {
    SearchInput,
    StatsCard,
    Avatar,
    Modal,
    Input,
    Button,
} from "@/components/ui";
import {
    Users,
    Plus,
    Edit2,
    Trash2,
    Loader2,
    ChevronRight,
    Building2,
    UserCheck,
    ShieldCheck,
    BarChart2,
} from "lucide-vue-next";

// ---- State ----
const router = useRouter();

interface InternalTeam {
    id: number;
    name: string;
    slug: string;
    department: string | null;
    status: string;
    members_count?: number;
    created_at: string;
    updated_at: string;
}

const teams = ref<InternalTeam[]>([]);
const isLoading = ref(false);
const searchQuery = ref("");
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });

const stats = ref({
    total: 0,
    active: 0,
    total_members: 0,
    departments: 0,
});

// Create / Edit
const showCreateModal = ref(false);
const showEditModal = ref(false);
const isSaving = ref(false);
const currentTeam = ref<InternalTeam | null>(null);
const errors = ref<Record<string, string[]>>({});

const formData = ref({
    name: "",
    department: "",
    status: "active",
});

// ---- API ----
const fetchTeams = debounce(async (page = 1) => {
    isLoading.value = true;
    try {
        const resp = await api.get("/api/internal-teams", {
            params: { search: searchQuery.value, per_page: 20, page },
        });
        teams.value = resp.data.data;
        const meta = resp.data.meta || resp.data;
        pagination.value = {
            current_page: meta.current_page,
            last_page: meta.last_page,
            total: meta.total,
        };

        // Derive simple stats
        stats.value.total = meta.total || teams.value.length;
        stats.value.active = teams.value.filter((t) => t.status === "active").length;
        stats.value.total_members = teams.value.reduce(
            (sum, t) => sum + (t.members_count ?? 0),
            0,
        );
        const depts = new Set(teams.value.map((t) => t.department).filter(Boolean));
        stats.value.departments = depts.size;
    } catch (e) {
        console.error("Failed to fetch internal teams", e);
    } finally {
        isLoading.value = false;
    }
}, 300);

const createTeam = async () => {
    isSaving.value = true;
    errors.value = {};
    try {
        await api.post("/api/internal-teams", formData.value);
        showCreateModal.value = false;
        fetchTeams(1);
    } catch (e: any) {
        if (e.response?.data?.errors) errors.value = e.response.data.errors;
    } finally {
        isSaving.value = false;
    }
};

const openEditModal = (team: InternalTeam) => {
    currentTeam.value = team;
    formData.value = { name: team.name, department: team.department ?? "", status: team.status };
    errors.value = {};
    showEditModal.value = true;
};

const updateTeam = async () => {
    if (!currentTeam.value) return;
    isSaving.value = true;
    errors.value = {};
    try {
        await api.put(`/api/internal-teams/${currentTeam.value.id}`, formData.value);
        showEditModal.value = false;
        fetchTeams(pagination.value.current_page);
    } catch (e: any) {
        if (e.response?.data?.errors) errors.value = e.response.data.errors;
    } finally {
        isSaving.value = false;
    }
};

const deleteTeam = async (team: InternalTeam) => {
    if (!confirm(`Delete "${team.name}"? This cannot be undone.`)) return;
    try {
        await api.delete(`/api/internal-teams/${team.id}`);
        fetchTeams(pagination.value.current_page);
    } catch (e) {
        console.error(e);
    }
};

const openCreate = () => {
    formData.value = { name: "", department: "", status: "active" };
    errors.value = {};
    showCreateModal.value = true;
};

watch(searchQuery, () => fetchTeams(1));

onMounted(() => fetchTeams());

// ---- Helpers ----
const departmentColor = (dept: string | null) => {
    const map: Record<string, string> = {
        Engineering: "bg-blue-500/10 text-blue-600",
        Marketing: "bg-pink-500/10 text-pink-600",
        Sales: "bg-emerald-500/10 text-emerald-600",
        Finance: "bg-amber-500/10 text-amber-600",
        "IT Support": "bg-violet-500/10 text-violet-600",
        Operations: "bg-cyan-500/10 text-cyan-600",
        HR: "bg-rose-500/10 text-rose-600",
    };
    return dept && map[dept]
        ? map[dept]
        : "bg-[var(--surface-tertiary)] text-[var(--text-secondary)]";
};

const initials = (name: string) =>
    name
        .split(" ")
        .map((w) => w[0])
        .slice(0, 2)
        .join("")
        .toUpperCase();

const statusBadge = (status: string) =>
    status === "active"
        ? "bg-emerald-500/10 text-emerald-600 border border-emerald-500/20"
        : "bg-gray-500/10 text-gray-500 border border-gray-500/20";
</script>

<template>
    <div class="relative min-h-screen p-6 space-y-8">
        <!-- BG orbs -->
        <div class="fixed inset-0 overflow-hidden pointer-events-none -z-10">
            <div
                class="absolute -top-32 -right-32 w-[500px] h-[500px] bg-violet-500/8 rounded-full blur-3xl"
            />
            <div
                class="absolute -bottom-32 -left-32 w-[500px] h-[500px] bg-blue-500/8 rounded-full blur-3xl"
            />
        </div>

        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h1 class="text-3xl font-bold text-[var(--text-primary)] tracking-tight">
                    Internal Teams
                </h1>
                <p class="text-[var(--text-secondary)] mt-1">
                    Manage departments, teams, roles and skills across your organization.
                </p>
            </div>
            <button
                @click="openCreate"
                class="btn btn-primary gap-2 shadow-lg shadow-violet-500/20 hover:shadow-violet-500/30 transition-all active:scale-95"
            >
                <Plus class="w-4 h-4" />
                New Team
            </button>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <StatsCard label="Total Teams" :value="stats.total" :icon="Users" variant="primary" />
            <StatsCard label="Active" :value="stats.active" :icon="ShieldCheck" variant="success" />
            <StatsCard label="Total Members" :value="stats.total_members" :icon="UserCheck" variant="info" />
            <StatsCard label="Departments" :value="stats.departments" :icon="Building2" variant="warning" />
        </div>

        <!-- Search bar -->
        <div class="flex items-center gap-4">
            <SearchInput
                v-model="searchQuery"
                placeholder="Search teams or departments…"
                class="max-w-sm"
            />
            <span class="text-xs text-[var(--text-muted)]">
                {{ pagination.total }} team{{ pagination.total !== 1 ? "s" : "" }}
            </span>
        </div>

        <!-- Table card -->
        <div
            class="bg-[var(--surface-primary)]/60 backdrop-blur-xl rounded-2xl border border-[var(--border-default)] shadow-xl overflow-hidden"
        >
            <!-- Loading -->
            <div v-if="isLoading" class="flex flex-col items-center justify-center py-24 gap-3">
                <Loader2 class="w-8 h-8 animate-spin text-[var(--interactive-primary)]" />
                <span class="text-[var(--text-secondary)] animate-pulse text-sm">Loading teams…</span>
            </div>

            <!-- Empty -->
            <div
                v-else-if="teams.length === 0"
                class="flex flex-col items-center justify-center py-28 gap-4"
            >
                <div
                    class="w-16 h-16 rounded-2xl bg-[var(--surface-secondary)] flex items-center justify-center"
                >
                    <Users class="w-8 h-8 text-[var(--text-muted)]" />
                </div>
                <div class="text-center">
                    <p class="font-semibold text-[var(--text-primary)]">No internal teams yet</p>
                    <p class="text-sm text-[var(--text-secondary)] mt-1">
                        Create your first team to start organising your workforce.
                    </p>
                </div>
                <button @click="openCreate" class="btn btn-primary mt-1 gap-2">
                    <Plus class="w-4 h-4" /> Create Team
                </button>
            </div>

            <!-- Table -->
            <table v-else class="w-full text-sm">
                <thead
                    class="bg-[var(--surface-secondary)]/60 text-[var(--text-secondary)] text-xs uppercase tracking-wider border-b border-[var(--border-default)]"
                >
                    <tr>
                        <th class="px-6 py-3 text-left">Team</th>
                        <th class="px-6 py-3 text-left hidden sm:table-cell">Department</th>
                        <th class="px-6 py-3 text-center hidden md:table-cell">Members</th>
                        <th class="px-6 py-3 text-center">Status</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-default)]">
                    <tr
                        v-for="team in teams"
                        :key="team.id"
                        class="group hover:bg-[var(--surface-secondary)]/40 transition-colors duration-150"
                    >
                        <!-- Team name + avatar initials -->
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-500 to-blue-500 flex items-center justify-center text-white text-xs font-bold shrink-0"
                                >
                                    {{ initials(team.name) }}
                                </div>
                                <div>
                                    <p class="font-semibold text-[var(--text-primary)]">{{ team.name }}</p>
                                    <p class="text-xs text-[var(--text-muted)] font-mono">{{ team.slug }}</p>
                                </div>
                            </div>
                        </td>

                        <!-- Department -->
                        <td class="px-6 py-4 hidden sm:table-cell">
                            <span
                                v-if="team.department"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium"
                                :class="departmentColor(team.department)"
                            >
                                <Building2 class="w-3 h-3" />
                                {{ team.department }}
                            </span>
                            <span v-else class="text-[var(--text-muted)] text-xs">—</span>
                        </td>

                        <!-- Members count -->
                        <td class="px-6 py-4 text-center hidden md:table-cell">
                            <span
                                class="inline-flex items-center gap-1.5 text-[var(--text-secondary)] font-medium"
                            >
                                <Users class="w-3.5 h-3.5 text-[var(--text-muted)]" />
                                {{ team.members_count ?? "—" }}
                            </span>
                        </td>

                        <!-- Status -->
                        <td class="px-6 py-4 text-center">
                            <span
                                class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold capitalize"
                                :class="statusBadge(team.status)"
                            >
                                {{ team.status }}
                            </span>
                        </td>

                        <!-- Actions -->
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                <!-- Manage detail button -->
                                <button
                                    @click="router.push({ name: 'admin-internal-team-details', params: { id: team.id } })"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-[var(--surface-tertiary)] hover:bg-[var(--interactive-primary)]/10 hover:text-[var(--interactive-primary)] text-[var(--text-secondary)] border border-[var(--border-default)] transition-all"
                                >
                                    Manage
                                    <ChevronRight class="w-3 h-3" />
                                </button>
                                <button
                                    @click="openEditModal(team)"
                                    class="p-1.5 rounded-lg hover:bg-[var(--surface-secondary)] text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors"
                                    title="Edit"
                                >
                                    <Edit2 class="w-3.5 h-3.5" />
                                </button>
                                <button
                                    @click="deleteTeam(team)"
                                    class="p-1.5 rounded-lg hover:bg-red-500/10 text-[var(--text-muted)] hover:text-red-500 transition-colors"
                                    title="Delete"
                                >
                                    <Trash2 class="w-3.5 h-3.5" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div
                v-if="pagination.last_page > 1"
                class="flex items-center justify-between px-6 py-3 border-t border-[var(--border-default)] text-sm"
            >
                <span class="text-[var(--text-muted)]">
                    Page {{ pagination.current_page }} of {{ pagination.last_page }}
                </span>
                <div class="flex gap-2">
                    <button
                        :disabled="pagination.current_page <= 1"
                        @click="fetchTeams(pagination.current_page - 1)"
                        class="btn btn-secondary btn-sm disabled:opacity-40"
                    >
                        Prev
                    </button>
                    <button
                        :disabled="pagination.current_page >= pagination.last_page"
                        @click="fetchTeams(pagination.current_page + 1)"
                        class="btn btn-secondary btn-sm disabled:opacity-40"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <Modal :show="showCreateModal" @close="showCreateModal = false" max-width="lg">
            <template #title>
                <div class="flex items-center gap-2">
                    <div class="p-1.5 rounded-lg bg-violet-500/10">
                        <Users class="w-4 h-4 text-violet-500" />
                    </div>
                    New Internal Team
                </div>
            </template>
            <template #body>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Team Name <span class="text-red-500">*</span></label>
                        <Input v-model="formData.name" placeholder="e.g. Tier 1 Support" :error="errors.name?.[0]" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Department</label>
                        <Input v-model="formData.department" placeholder="e.g. IT, Finance, Marketing" :error="errors.department?.[0]" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Status</label>
                        <select
                            v-model="formData.status"
                            class="input w-full"
                        >
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </template>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <Button variant="secondary" @click="showCreateModal = false">Cancel</Button>
                    <Button variant="primary" :loading="isSaving" @click="createTeam">Create Team</Button>
                </div>
            </template>
        </Modal>

        <!-- Edit Modal -->
        <Modal :show="showEditModal" @close="showEditModal = false" max-width="lg">
            <template #title>
                <div class="flex items-center gap-2">
                    <div class="p-1.5 rounded-lg bg-blue-500/10">
                        <Edit2 class="w-4 h-4 text-blue-500" />
                    </div>
                    Edit — {{ currentTeam?.name }}
                </div>
            </template>
            <template #body>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Team Name <span class="text-red-500">*</span></label>
                        <Input v-model="formData.name" :error="errors.name?.[0]" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Department</label>
                        <Input v-model="formData.department" :error="errors.department?.[0]" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Status</label>
                        <select v-model="formData.status" class="input w-full">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </template>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <Button variant="secondary" @click="showEditModal = false">Cancel</Button>
                    <Button variant="primary" :loading="isSaving" @click="updateTeam">Save Changes</Button>
                </div>
            </template>
        </Modal>
    </div>
</template>
