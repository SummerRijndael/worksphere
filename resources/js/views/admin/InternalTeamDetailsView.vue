<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "@/lib/api";
import { Avatar, Modal, Input, Button } from "@/components/ui";
import {
    Users,
    ArrowLeft,
    Plus,
    Trash2,
    Loader2,
    Edit2,
    Building2,
    ShieldCheck,
    Award,
    UserPlus,
    ChevronDown,
    Check,
    X,
    BookOpen,
    Crown,
    Zap,
} from "lucide-vue-next";

// ---- Types ----
interface Member {
    id: number;
    public_id: string;
    name: string;
    email: string;
    avatar_url: string | null;
    team_role: string;
    joined_at: string;
}

interface InternalTeam {
    id: number;
    name: string;
    slug: string;
    department: string | null;
    status: string;
    members_count?: number;
    members?: Member[];
    created_at: string;
}

interface SupportSkill {
    id: number;
    name: string;
    slug: string;
}

interface UserOption {
    id: number;
    public_id: string;
    name: string;
    email: string;
    avatar_url: string | null;
}

// ---- State ----
const route = useRoute();
const router = useRouter();
const teamId = computed(() => route.params.id as string);

const team = ref<InternalTeam | null>(null);
const members = ref<Member[]>([]);
const allSkills = ref<SupportSkill[]>([]);
const teamSkills = ref<SupportSkill[]>([]);
const isLoading = ref(true);
const isSaving = ref(false);

const activeTab = ref<"members" | "skills" | "settings">("members");

// Add member
const showAddMemberModal = ref(false);
const userSearch = ref("");
const userOptions = ref<UserOption[]>([]);
const isSearchingUsers = ref(false);
const addMemberForm = ref({ user_id: "", role: "agent" });
const addMemberErrors = ref<Record<string, string[]>>({});

// Edit team name / dept
const showEditModal = ref(false);
const editForm = ref({ name: "", department: "", status: "active" });
const editErrors = ref<Record<string, string[]>>({});

// Role update inline state
const updatingRoleFor = ref<number | null>(null);

// ---- Fetch data ----
const fetchTeam = async () => {
    try {
        const resp = await api.get(`/api/internal-teams/${teamId.value}`);
        team.value = resp.data.data ?? resp.data;
    } catch (e) {
        console.error(e);
    }
};

const fetchMembers = async () => {
    try {
        const resp = await api.get(`/api/internal-teams/${teamId.value}/members`);
        members.value = resp.data.data ?? resp.data;
    } catch (e) {
        console.error(e);
    }
};

const fetchSkills = async () => {
    try {
        // Fetch all skills available in system
        const resp = await api.get("/api/support/skills");
        allSkills.value = resp.data.data ?? resp.data ?? [];
    } catch {
        allSkills.value = [];
    }
    // For team skills we check via the team's synced skills (if endpoint exists)
    // We fall back to an empty list; the sync action will use the whole list
};

const init = async () => {
    isLoading.value = true;
    await Promise.all([fetchTeam(), fetchMembers(), fetchSkills()]);
    isLoading.value = false;
};

onMounted(init);

// ---- User search for add-member ----
let searchTimeout: ReturnType<typeof setTimeout> | null = null;
const handleUserSearch = (query: string) => {
    userSearch.value = query;
    if (searchTimeout) clearTimeout(searchTimeout);
    if (query.length < 2) { userOptions.value = []; return; }
    searchTimeout = setTimeout(async () => {
        isSearchingUsers.value = true;
        try {
            const resp = await api.get("/api/users", { params: { search: query, per_page: 10 } });
            userOptions.value = resp.data.data ?? [];
        } finally {
            isSearchingUsers.value = false;
        }
    }, 300);
};

const selectUser = (user: UserOption) => {
    addMemberForm.value.user_id = user.public_id;
    userSearch.value = user.name;
    userOptions.value = [];
};

const openAddMember = () => {
    addMemberForm.value = { user_id: "", role: "agent" };
    userSearch.value = "";
    userOptions.value = [];
    addMemberErrors.value = {};
    showAddMemberModal.value = true;
};

const addMember = async () => {
    isSaving.value = true;
    addMemberErrors.value = {};
    try {
        await api.post(`/api/internal-teams/${teamId.value}/members`, {
            user_id: addMemberForm.value.user_id,
            role: addMemberForm.value.role,
        });
        showAddMemberModal.value = false;
        fetchMembers();
    } catch (e: any) {
        if (e.response?.data?.errors) addMemberErrors.value = e.response.data.errors;
    } finally {
        isSaving.value = false;
    }
};

const removeMember = async (member: Member) => {
    if (!confirm(`Remove ${member.name} from this team?`)) return;
    try {
        await api.delete(`/api/internal-teams/${teamId.value}/members/${member.public_id}`);
        fetchMembers();
    } catch (e) {
        console.error(e);
    }
};

const updateRole = async (member: Member, newRole: string) => {
    updatingRoleFor.value = member.id;
    try {
        await api.put(`/api/internal-teams/${teamId.value}/members/${member.public_id}/role`, {
            role: newRole,
        });
        member.team_role = newRole;
    } catch (e) {
        console.error(e);
    } finally {
        updatingRoleFor.value = null;
    }
};

// ---- Edit team ----
const openEdit = () => {
    if (!team.value) return;
    editForm.value = { name: team.value.name, department: team.value.department ?? "", status: team.value.status };
    editErrors.value = {};
    showEditModal.value = true;
};

const saveEdit = async () => {
    isSaving.value = true;
    editErrors.value = {};
    try {
        const resp = await api.put(`/api/internal-teams/${teamId.value}`, editForm.value);
        team.value = resp.data.data ?? resp.data;
        showEditModal.value = false;
    } catch (e: any) {
        if (e.response?.data?.errors) editErrors.value = e.response.data.errors;
    } finally {
        isSaving.value = false;
    }
};

// ---- Helpers ----
const roleConfig: Record<string, { label: string; color: string; icon: any }> = {
    manager: { label: "Manager", color: "bg-violet-500/10 text-violet-600 border-violet-500/20", icon: Crown },
    lead: { label: "Lead", color: "bg-amber-500/10 text-amber-600 border-amber-500/20", icon: Zap },
    agent: { label: "Agent", color: "bg-blue-500/10 text-blue-600 border-blue-500/20", icon: ShieldCheck },
};

const initials = (name: string) =>
    name.split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase();

const formatDate = (d: string) =>
    new Date(d).toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
</script>

<template>
    <div class="relative min-h-screen p-6 space-y-6">
        <!-- BG orbs -->
        <div class="fixed inset-0 overflow-hidden pointer-events-none -z-10">
            <div class="absolute -top-32 -right-32 w-[500px] h-[500px] bg-violet-500/8 rounded-full blur-3xl" />
            <div class="absolute -bottom-32 -left-32 w-[500px] h-[500px] bg-blue-500/8 rounded-full blur-3xl" />
        </div>

        <!-- Loading screen -->
        <div v-if="isLoading" class="flex flex-col items-center justify-center min-h-[60vh] gap-3">
            <Loader2 class="w-9 h-9 animate-spin text-[var(--interactive-primary)]" />
            <span class="text-[var(--text-secondary)] animate-pulse">Loading team…</span>
        </div>

        <template v-else-if="team">
            <!-- Back -->
            <button
                @click="router.push({ name: 'admin-internal-teams' })"
                class="inline-flex items-center gap-2 text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
            >
                <ArrowLeft class="w-4 h-4" />
                Back to Internal Teams
            </button>

            <!-- Hero header -->
            <div
                class="relative overflow-hidden rounded-2xl border border-[var(--border-default)] bg-gradient-to-br from-[var(--surface-secondary)] via-[var(--surface-primary)] to-[var(--surface-secondary)] p-6"
            >
                <div class="absolute inset-0 bg-gradient-to-br from-violet-500/5 to-blue-500/5 pointer-events-none" />
                <div class="relative flex flex-col sm:flex-row items-start sm:items-center gap-5">
                    <!-- Team avatar -->
                    <div
                        class="w-16 h-16 rounded-2xl bg-gradient-to-br from-violet-500 to-blue-500 flex items-center justify-center text-white text-xl font-bold shadow-lg shadow-violet-500/20 shrink-0"
                    >
                        {{ initials(team.name) }}
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-3">
                            <h1 class="text-2xl font-bold text-[var(--text-primary)] tracking-tight">
                                {{ team.name }}
                            </h1>
                            <span
                                class="px-2.5 py-1 rounded-full text-xs font-semibold capitalize"
                                :class="team.status === 'active'
                                    ? 'bg-emerald-500/10 text-emerald-600 border border-emerald-500/20'
                                    : 'bg-gray-500/10 text-gray-500 border'"
                            >{{ team.status }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-4 mt-2 text-sm text-[var(--text-secondary)]">
                            <span v-if="team.department" class="flex items-center gap-1.5">
                                <Building2 class="w-3.5 h-3.5" /> {{ team.department }}
                            </span>
                            <span class="flex items-center gap-1.5">
                                <Users class="w-3.5 h-3.5" /> {{ members.length }} member{{ members.length !== 1 ? "s" : "" }}
                            </span>
                            <span class="font-mono text-xs text-[var(--text-muted)]">/{{ team.slug }}</span>
                        </div>
                    </div>

                    <button
                        @click="openEdit"
                        class="inline-flex items-center gap-2 btn btn-secondary text-sm shrink-0"
                    >
                        <Edit2 class="w-3.5 h-3.5" />
                        Edit
                    </button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 bg-[var(--surface-secondary)]/60 rounded-xl p-1 w-fit border border-[var(--border-default)]">
                <button
                    v-for="tab in (['members', 'skills', 'settings'] as const)"
                    :key="tab"
                    @click="activeTab = tab"
                    :class="[
                        'px-4 py-2 rounded-lg text-sm font-medium transition-all capitalize',
                        activeTab === tab
                            ? 'bg-[var(--surface-primary)] text-[var(--text-primary)] shadow-sm'
                            : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                    ]"
                >
                    {{ tab }}
                </button>
            </div>

            <!-- ======== MEMBERS TAB ======== -->
            <div v-if="activeTab === 'members'" class="space-y-4">
                <!-- Add member header -->
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-[var(--text-primary)]">
                        Team Members
                        <span class="text-sm font-normal text-[var(--text-muted)] ml-2">({{ members.length }})</span>
                    </h2>
                    <button @click="openAddMember" class="btn btn-primary gap-2 text-sm">
                        <UserPlus class="w-4 h-4" />
                        Add Member
                    </button>
                </div>

                <!-- Empty members -->
                <div
                    v-if="members.length === 0"
                    class="flex flex-col items-center justify-center py-20 gap-4 bg-[var(--surface-primary)]/60 rounded-2xl border border-[var(--border-default)] border-dashed"
                >
                    <div class="w-14 h-14 rounded-2xl bg-[var(--surface-secondary)] flex items-center justify-center">
                        <Users class="w-7 h-7 text-[var(--text-muted)]" />
                    </div>
                    <div class="text-center">
                        <p class="font-semibold text-[var(--text-primary)]">No members yet</p>
                        <p class="text-sm text-[var(--text-secondary)] mt-1">Add users to this team to get started.</p>
                    </div>
                    <button @click="openAddMember" class="btn btn-primary gap-2 text-sm">
                        <UserPlus class="w-4 h-4" /> Add Member
                    </button>
                </div>

                <!-- Members list -->
                <div
                    v-else
                    class="bg-[var(--surface-primary)]/60 backdrop-blur-xl rounded-2xl border border-[var(--border-default)] shadow-lg divide-y divide-[var(--border-default)] overflow-hidden"
                >
                    <div
                        v-for="member in members"
                        :key="member.id"
                        class="flex items-center gap-4 px-6 py-4 group hover:bg-[var(--surface-secondary)]/30 transition-colors"
                    >
                        <!-- Avatar -->
                        <Avatar
                            :src="member.avatar_url"
                            :fallback="initials(member.name)"
                            size="md"
                            class="shrink-0"
                        />

                        <!-- Info -->
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-[var(--text-primary)] truncate">{{ member.name }}</p>
                            <p class="text-xs text-[var(--text-muted)] truncate">{{ member.email }}</p>
                        </div>

                        <!-- Role badge + inline change -->
                        <div class="shrink-0">
                            <div class="relative group/role">
                                <button
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border transition-all"
                                    :class="roleConfig[member.team_role]?.color ?? 'bg-gray-500/10 text-gray-600'"
                                >
                                    <component
                                        :is="roleConfig[member.team_role]?.icon ?? ShieldCheck"
                                        class="w-3 h-3"
                                    />
                                    {{ roleConfig[member.team_role]?.label ?? member.team_role }}
                                    <ChevronDown class="w-3 h-3 opacity-60" />
                                    <Loader2 v-if="updatingRoleFor === member.id" class="w-3 h-3 animate-spin" />
                                </button>
                                <!-- Dropdown -->
                                <div
                                    class="absolute right-0 top-full mt-1 w-36 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-xl shadow-xl z-20 overflow-hidden opacity-0 invisible group-hover/role:opacity-100 group-hover/role:visible transition-all duration-150"
                                >
                                    <button
                                        v-for="(cfg, role) in roleConfig"
                                        :key="role"
                                        @click="updateRole(member, role)"
                                        class="flex w-full items-center gap-2.5 px-3 py-2.5 text-xs font-medium hover:bg-[var(--surface-secondary)] transition-colors"
                                        :class="member.team_role === role ? 'text-[var(--interactive-primary)]' : 'text-[var(--text-primary)]'"
                                    >
                                        <component :is="cfg.icon" class="w-3.5 h-3.5" />
                                        {{ cfg.label }}
                                        <Check v-if="member.team_role === role" class="w-3 h-3 ml-auto" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Joined date -->
                        <span class="hidden lg:block text-xs text-[var(--text-muted)] shrink-0">
                            Joined {{ formatDate(member.joined_at) }}
                        </span>

                        <!-- Remove -->
                        <button
                            @click="removeMember(member)"
                            class="p-1.5 rounded-lg hover:bg-red-500/10 text-[var(--text-muted)] hover:text-red-500 opacity-0 group-hover:opacity-100 transition-all shrink-0"
                            title="Remove member"
                        >
                            <X class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- ======== SKILLS TAB ======== -->
            <div v-else-if="activeTab === 'skills'" class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-[var(--text-primary)]">Support Skills</h2>
                        <p class="text-sm text-[var(--text-secondary)] mt-0.5">
                            Skills define what types of support tickets this team can handle.
                        </p>
                    </div>
                </div>

                <div
                    v-if="allSkills.length === 0"
                    class="flex flex-col items-center justify-center py-20 gap-4 bg-[var(--surface-primary)]/60 rounded-2xl border border-[var(--border-default)] border-dashed"
                >
                    <div class="w-14 h-14 rounded-2xl bg-[var(--surface-secondary)] flex items-center justify-center">
                        <BookOpen class="w-7 h-7 text-[var(--text-muted)]" />
                    </div>
                    <div class="text-center">
                        <p class="font-semibold text-[var(--text-primary)]">No skills configured</p>
                        <p class="text-sm text-[var(--text-secondary)] mt-1">
                            Create support skills in the system settings first.
                        </p>
                    </div>
                </div>

                <div
                    v-else
                    class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"
                >
                    <div
                        v-for="skill in allSkills"
                        :key="skill.id"
                        class="group flex items-center gap-3 p-4 rounded-xl border border-[var(--border-default)] bg-[var(--surface-primary)]/60 hover:border-violet-500/30 hover:bg-violet-500/5 transition-all duration-200 cursor-pointer"
                    >
                        <div class="w-9 h-9 rounded-lg bg-violet-500/10 flex items-center justify-center shrink-0">
                            <Award class="w-4.5 h-4.5 text-violet-500" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-sm text-[var(--text-primary)] truncate">{{ skill.name }}</p>
                            <p class="text-xs text-[var(--text-muted)] font-mono truncate">{{ skill.slug }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ======== SETTINGS TAB ======== -->
            <div v-else-if="activeTab === 'settings'" class="space-y-6 max-w-2xl">
                <h2 class="text-lg font-semibold text-[var(--text-primary)]">Team Settings</h2>

                <div class="bg-[var(--surface-primary)]/60 rounded-2xl border border-[var(--border-default)] p-6 space-y-4">
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-[var(--text-muted)] text-xs mb-1 uppercase tracking-wide font-semibold">Name</p>
                            <p class="text-[var(--text-primary)] font-medium">{{ team.name }}</p>
                        </div>
                        <div>
                            <p class="text-[var(--text-muted)] text-xs mb-1 uppercase tracking-wide font-semibold">Slug</p>
                            <p class="text-[var(--text-primary)] font-mono">{{ team.slug }}</p>
                        </div>
                        <div>
                            <p class="text-[var(--text-muted)] text-xs mb-1 uppercase tracking-wide font-semibold">Department</p>
                            <p class="text-[var(--text-primary)] font-medium">{{ team.department ?? "—" }}</p>
                        </div>
                        <div>
                            <p class="text-[var(--text-muted)] text-xs mb-1 uppercase tracking-wide font-semibold">Status</p>
                            <span
                                class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold capitalize"
                                :class="team.status === 'active' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-gray-500/10 text-gray-500'"
                            >{{ team.status }}</span>
                        </div>
                        <div>
                            <p class="text-[var(--text-muted)] text-xs mb-1 uppercase tracking-wide font-semibold">Created</p>
                            <p class="text-[var(--text-primary)]">{{ formatDate(team.created_at) }}</p>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-[var(--border-default)]">
                        <button @click="openEdit" class="btn btn-secondary gap-2 text-sm">
                            <Edit2 class="w-3.5 h-3.5" />
                            Edit Team Info
                        </button>
                    </div>
                </div>

                <!-- Danger zone -->
                <div class="bg-red-500/5 rounded-2xl border border-red-500/20 p-6">
                    <h3 class="text-sm font-semibold text-red-600 mb-1">Danger Zone</h3>
                    <p class="text-xs text-[var(--text-secondary)] mb-4">
                        Deleting this team removes all member assignments permanently.
                    </p>
                    <button
                        @click="router.push({ name: 'admin-internal-teams' })"
                        class="btn text-sm border border-red-500/30 bg-red-500/5 text-red-600 hover:bg-red-500/15 gap-2"
                    >
                        <Trash2 class="w-3.5 h-3.5" />
                        Delete Team
                    </button>
                </div>
            </div>
        </template>

        <!-- ======== ADD MEMBER MODAL ======== -->
        <Modal :show="showAddMemberModal" @close="showAddMemberModal = false" max-width="lg">
            <template #title>
                <div class="flex items-center gap-2">
                    <div class="p-1.5 rounded-lg bg-violet-500/10">
                        <UserPlus class="w-4 h-4 text-violet-500" />
                    </div>
                    Add Member to {{ team?.name }}
                </div>
            </template>
            <template #body>
                <div class="space-y-4">
                    <!-- User search -->
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">
                            Search User <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <Input
                                :model-value="userSearch"
                                @update:model-value="handleUserSearch"
                                placeholder="Type a name or email…"
                                :error="addMemberErrors.user_id?.[0]"
                            />
                            <!-- Dropdown results -->
                            <div
                                v-if="userOptions.length > 0"
                                class="absolute left-0 right-0 top-full mt-1 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-xl shadow-xl z-30 overflow-hidden max-h-52 overflow-y-auto"
                            >
                                <button
                                    v-for="u in userOptions"
                                    :key="u.id"
                                    @click="selectUser(u)"
                                    class="flex w-full items-center gap-3 px-4 py-2.5 hover:bg-[var(--surface-secondary)] transition-colors"
                                >
                                    <Avatar :src="u.avatar_url" :fallback="initials(u.name)" size="sm" class="shrink-0" />
                                    <div class="text-left">
                                        <p class="text-sm font-medium text-[var(--text-primary)]">{{ u.name }}</p>
                                        <p class="text-xs text-[var(--text-muted)]">{{ u.email }}</p>
                                    </div>
                                </button>
                            </div>
                            <div
                                v-else-if="isSearchingUsers"
                                class="absolute left-0 right-0 top-full mt-1 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-xl shadow-xl z-30 p-4 flex items-center gap-2 text-sm text-[var(--text-muted)]"
                            >
                                <Loader2 class="w-4 h-4 animate-spin" /> Searching…
                            </div>
                        </div>
                    </div>

                    <!-- Role -->
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Role</label>
                        <select v-model="addMemberForm.role" class="input w-full">
                            <option value="agent">Agent</option>
                            <option value="lead">Lead</option>
                            <option value="manager">Manager</option>
                        </select>
                        <p class="text-xs text-[var(--text-muted)] mt-1.5">
                            <span class="font-semibold">Manager</span> — full team control ·
                            <span class="font-semibold">Lead</span> — can assign tickets ·
                            <span class="font-semibold">Agent</span> — handles support tasks
                        </p>
                    </div>
                </div>
            </template>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <Button variant="secondary" @click="showAddMemberModal = false">Cancel</Button>
                    <Button
                        variant="primary"
                        :loading="isSaving"
                        :disabled="!addMemberForm.user_id"
                        @click="addMember"
                    >
                        Add Member
                    </Button>
                </div>
            </template>
        </Modal>

        <!-- ======== EDIT MODAL ======== -->
        <Modal :show="showEditModal" @close="showEditModal = false" max-width="lg">
            <template #title>
                <div class="flex items-center gap-2">
                    <div class="p-1.5 rounded-lg bg-blue-500/10">
                        <Edit2 class="w-4 h-4 text-blue-500" />
                    </div>
                    Edit — {{ team?.name }}
                </div>
            </template>
            <template #body>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Team Name <span class="text-red-500">*</span></label>
                        <Input v-model="editForm.name" :error="editErrors.name?.[0]" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Department</label>
                        <Input v-model="editForm.department" placeholder="e.g. IT, Finance, Marketing" :error="editErrors.department?.[0]" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Status</label>
                        <select v-model="editForm.status" class="input w-full">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </template>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <Button variant="secondary" @click="showEditModal = false">Cancel</Button>
                    <Button variant="primary" :loading="isSaving" @click="saveEdit">Save Changes</Button>
                </div>
            </template>
        </Modal>
    </div>
</template>
