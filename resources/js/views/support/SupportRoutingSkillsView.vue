<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import api from "@/lib/api";
import { Badge, Button, Card, Input, Textarea, StatsCard } from "@/components/ui";
import { useToast } from "@/composables/useToast.ts";
import { useAuthStore } from "@/stores/auth";
import { Cog, Plus, RefreshCw, ShieldCheck, Users, UserPlus2, Trash2, Save, Pencil, BarChart2 } from "lucide-vue-next";

interface SkillMemberUser {
    id: string;
    name: string;
    email?: string | null;
    status?: string | null;
}

interface SkillMembership {
    id: number;
    membership_role: "team_lead" | "sme" | "qa" | "agent";
    is_primary: boolean;
    is_active: boolean;
    capacity: number | null;
    user: SkillMemberUser | null;
}

interface SupportSkill {
    id: string;
    name: string;
    slug: string;
    description?: string | null;
    department?: string | null;
    is_active: boolean;
    priority: number;
    members_count: number;
    active_members_count: number;
    memberships?: SkillMembership[];
}

interface AgentOption {
    id: string;
    name: string;
    email?: string | null;
    status?: string | null;
}

const toast = useToast();
const authStore = useAuthStore();

const loading = ref(false);
const savingSkill = ref(false);
const savingMember = ref(false);
const deletingMemberUserId = ref<string | null>(null);
const isCreatingNew = ref(false);

const skills = ref<SupportSkill[]>([]);
const selectedSkillId = ref<string>("");
const agents = ref<AgentOption[]>([]);

const filters = ref({
    q: "",
    department: "",
    is_active: "",
});

const skillForm = ref({
    id: "",
    name: "",
    slug: "",
    description: "",
    department: "",
    priority: 100,
    is_active: true,
});

const memberForm = ref({
    agent_public_id: "",
    membership_role: "agent" as "team_lead" | "sme" | "qa" | "agent",
    is_primary: false,
    is_active: true,
    capacity: "",
});

const canManageRouting = computed(() =>
    authStore.hasPermission(["support.chats.assign", "tickets.manage"]),
);

const departments = computed<string[]>(() => {
    const values = new Set<string>();
    for (const skill of skills.value) {
        const department = String(skill.department || "").trim();
        if (department !== "") {
            values.add(department);
        }
    }

    return Array.from(values).sort((a, b) => a.localeCompare(b));
});

const selectedSkill = computed<SupportSkill | null>(() => {
    return skills.value.find((item) => item.id === selectedSkillId.value) ?? null;
});

const totalSkills = computed(() => skills.value.length);
const activeSkills = computed(() => skills.value.filter((item) => item.is_active).length);
const totalMembers = computed(() =>
    skills.value.reduce((sum, item) => sum + Number(item.active_members_count || 0), 0),
);

let filtersDebounceTimer: ReturnType<typeof setTimeout> | null = null;

function resolveErrorMessage(error: any, fallback = "Request failed."): string {
    return String(error?.response?.data?.message || fallback);
}

function roleLabel(role: SkillMembership["membership_role"]): string {
    if (role === "team_lead") return "Team Lead";
    if (role === "sme") return "SME";
    if (role === "qa") return "QA";

    return "Agent";
}

function ensureSelectedSkill(): void {
    if (selectedSkill.value) {
        return;
    }

    selectedSkillId.value = skills.value[0]?.id || "";
}

function populateSkillForm(skill: SupportSkill): void {
    isCreatingNew.value = false;
    skillForm.value = {
        id: skill.id,
        name: skill.name || "",
        slug: skill.slug || "",
        description: skill.description || "",
        department: skill.department || "",
        priority: Number(skill.priority || 100),
        is_active: Boolean(skill.is_active),
    };
}

function resetSkillForm(): void {
    skillForm.value = {
        id: "",
        name: "",
        slug: "",
        description: "",
        department: "",
        priority: 100,
        is_active: true,
    };
}

function resetMemberForm(): void {
    memberForm.value = {
        agent_public_id: "",
        membership_role: "agent",
        is_primary: false,
        is_active: true,
        capacity: "",
    };
}

async function loadAgents(): Promise<void> {
    try {
        const response = await api.get("/api/support/chats/agents");
        const items = Array.isArray(response.data?.data) ? response.data.data : [];
        agents.value = items
            .map((item: any) => ({
                id: String(item.id),
                name: String(item.name || "Unknown"),
                email: item.email ? String(item.email) : null,
                status: item.status ? String(item.status) : null,
            }))
            .sort((a: AgentOption, b: AgentOption) => a.name.localeCompare(b.name));
    } catch (error: any) {
        toast.error("Unable to load agents", resolveErrorMessage(error, "Failed to load support agents."));
    }
}

async function loadSkills(withLoader = true): Promise<void> {
    if (withLoader) {
        loading.value = true;
    }

    try {
        const params: Record<string, string | number | boolean> = {
            include_members: true,
            per_page: 100,
        };

        if (filters.value.q.trim() !== "") {
            params.q = filters.value.q.trim();
        }
        if (filters.value.department.trim() !== "") {
            params.department = filters.value.department.trim();
        }
        if (filters.value.is_active !== "") {
            params.is_active = filters.value.is_active === "true";
        }

        const response = await api.get("/api/support/chats/skills", { params });
        skills.value = Array.isArray(response.data?.data) ? response.data.data : [];

        ensureSelectedSkill();

        if (selectedSkill.value) {
            populateSkillForm(selectedSkill.value);
        } else {
            resetSkillForm();
        }
    } catch (error: any) {
        toast.error("Unable to load skills", resolveErrorMessage(error, "Failed to load support skills."));
    } finally {
        loading.value = false;
    }
}

function selectSkill(skillId: string): void {
    selectedSkillId.value = skillId;

    const skill = skills.value.find((item) => item.id === skillId);
    if (skill) {
        populateSkillForm(skill);
    }

    resetMemberForm();
}

function newSkill(): void {
    selectedSkillId.value = "";
    isCreatingNew.value = true;
    resetSkillForm();
    resetMemberForm();
}

function editMember(member: SkillMembership): void {
    memberForm.value.agent_public_id = String(member.user?.id || "");
    memberForm.value.membership_role = member.membership_role;
    memberForm.value.is_primary = Boolean(member.is_primary);
    memberForm.value.is_active = Boolean(member.is_active);
    memberForm.value.capacity = member.capacity !== null && Number.isFinite(member.capacity)
        ? String(member.capacity)
        : "";
}

async function saveSkill(): Promise<void> {
    if (!canManageRouting.value) {
        toast.error("Access denied", "You do not have permission to manage support skills.");

        return;
    }

    const payload: Record<string, unknown> = {
        name: String(skillForm.value.name || "").trim(),
        slug: String(skillForm.value.slug || "").trim() || null,
        description: String(skillForm.value.description || "").trim() || null,
        department: String(skillForm.value.department || "").trim() || null,
        priority: Number(skillForm.value.priority || 100),
        is_active: Boolean(skillForm.value.is_active),
    };

    if (String(payload.name || "").trim() === "") {
        toast.error("Name required", "Skill name is required.");

        return;
    }

    savingSkill.value = true;
    try {
        if (skillForm.value.id) {
            await api.put(`/api/support/chats/skills/${skillForm.value.id}`, payload);
            toast.success("Updated", "Support skill updated.");
        } else {
            const response = await api.post("/api/support/chats/skills", payload);
            const createdId = String(response.data?.data?.id || "");
            if (createdId) {
                selectedSkillId.value = createdId;
            }
            toast.success("Created", "Support skill created.");
        }

        await loadSkills(false);
    } catch (error: any) {
        toast.error("Save failed", resolveErrorMessage(error, "Unable to save support skill."));
    } finally {
        savingSkill.value = false;
    }
}

async function saveMembership(): Promise<void> {
    if (!canManageRouting.value) {
        toast.error("Access denied", "You do not have permission to manage skill memberships.");

        return;
    }
    if (!selectedSkill.value) {
        toast.error("No skill selected", "Select or create a skill first.");

        return;
    }
    if (!memberForm.value.agent_public_id) {
        toast.error("Agent required", "Choose an agent to assign.");

        return;
    }

    const capacityRaw = String(memberForm.value.capacity || "").trim();
    const payload: Record<string, unknown> = {
        agent_public_id: memberForm.value.agent_public_id,
        membership_role: memberForm.value.membership_role,
        is_primary: Boolean(memberForm.value.is_primary),
        is_active: Boolean(memberForm.value.is_active),
    };

    if (capacityRaw !== "") {
        payload.capacity = Number(capacityRaw);
    }

    savingMember.value = true;
    try {
        await api.post(`/api/support/chats/skills/${selectedSkill.value.id}/members`, payload);
        toast.success("Saved", "Skill membership updated.");
        resetMemberForm();
        await loadSkills(false);
        if (selectedSkill.value) {
            populateSkillForm(selectedSkill.value);
        }
    } catch (error: any) {
        toast.error("Save failed", resolveErrorMessage(error, "Unable to save skill membership."));
    } finally {
        savingMember.value = false;
    }
}

async function removeMembership(member: SkillMembership): Promise<void> {
    if (!canManageRouting.value) {
        toast.error("Access denied", "You do not have permission to remove skill members.");

        return;
    }
    if (!selectedSkill.value || !member.user?.id) {
        return;
    }

    deletingMemberUserId.value = member.user.id;
    try {
        await api.delete(`/api/support/chats/skills/${selectedSkill.value.id}/members/${member.user.id}`);
        toast.success("Removed", "Skill member removed.");
        await loadSkills(false);
    } catch (error: any) {
        toast.error("Remove failed", resolveErrorMessage(error, "Unable to remove skill member."));
    } finally {
        deletingMemberUserId.value = null;
    }
}

watch(
    filters,
    () => {
        if (filtersDebounceTimer) {
            clearTimeout(filtersDebounceTimer);
        }
        filtersDebounceTimer = setTimeout(() => {
            void loadSkills(false);
        }, 250);
    },
    { deep: true },
);

watch(selectedSkillId, () => {
    if (selectedSkill.value) {
        populateSkillForm(selectedSkill.value);
    }
});

onMounted(async () => {
    await Promise.all([loadAgents(), loadSkills()]);
});

onBeforeUnmount(() => {
    if (filtersDebounceTimer) {
        clearTimeout(filtersDebounceTimer);
        filtersDebounceTimer = null;
    }
});
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-[var(--text-primary)]">Support Routing & Skills</h1>
                <p class="text-sm text-[var(--text-secondary)]">
                    Manage support skill groups, ownership roles, and agent capacities for auto-routing.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" :disabled="loading" @click="loadSkills()">
                    <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': loading }" />
                    Refresh
                </Button>
                <Button v-if="canManageRouting" variant="primary" @click="newSkill">
                    <Plus class="mr-2 h-4 w-4" />
                    New Skill
                </Button>
            </div>
        </div>

        <!-- Performance Stats -->
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <StatsCard
                label="Total Defined"
                :value="totalSkills"
                :icon="ShieldCheck"
                variant="primary"
                sub-value="Skill Groups"
            />
            <StatsCard
                label="Active Skills"
                :value="activeSkills"
                :icon="Cog"
                variant="success"
                sub-value="Ready for Routing"
            />
            <StatsCard
                label="Total Agents"
                :value="totalMembers"
                :icon="Users"
                variant="info"
                sub-value="Equipped Personnel"
            />
        </div>

        <Card class="p-4 shadow-sm border-(--border-muted) bg-(--surface-primary)/50 backdrop-blur-md">
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <div class="relative">
                    <Input v-model="filters.q" placeholder="Filter by name, slug, or tags..." class="pl-9 bg-(--surface-primary) border-(--border-muted)" />
                    <Cog class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-(--text-muted)" />
                </div>
                <select
                    v-model="filters.department"
                    class="h-10 rounded-lg border border-(--border-muted) bg-(--surface-primary) px-3 text-sm text-(--text-primary) focus:border-(--interactive-primary) focus:outline-none transition-all hover:border-(--border-default)"
                >
                    <option value="">All Regions / Departments</option>
                    <option v-for="department in departments" :key="department" :value="department">
                        {{ department }}
                    </option>
                </select>
                <select
                    v-model="filters.is_active"
                    class="h-10 rounded-lg border border-(--border-muted) bg-(--surface-primary) px-3 text-sm text-(--text-primary) focus:border-(--interactive-primary) focus:outline-none transition-all hover:border-(--border-default)"
                >
                    <option value="">Availability: All</option>
                    <option value="true">Online / Active</option>
                    <option value="false">Offline / Archived</option>
                </select>
            </div>
        </Card>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-12">
            <Card class="flex flex-col overflow-hidden bg-(--surface-base) xl:col-span-4">
                <div class="border-b-(--border-muted) border-b p-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-bold uppercase tracking-wider text-(--text-secondary)">Skill Registry</h2>
                        <Badge variant="secondary" size="sm" class="font-mono">{{ skills.length }}</Badge>
                    </div>
                </div>
                <div class="max-h-[700px] flex-1 space-y-2 overflow-y-auto p-3">
                    <button
                        v-for="skill in skills"
                        :key="skill.id"
                        type="button"
                        class="group relative w-full overflow-hidden rounded-xl border border-(--border-muted) p-4 text-left transition-all duration-200"
                        :class="selectedSkillId === skill.id
                            ? 'border-(--interactive-primary)/30 bg-(--interactive-primary)/10 shadow-sm'
                            : 'bg-(--surface-secondary)/20 hover:border-(--border-default) hover:bg-(--surface-secondary)/50'"
                        @click="selectSkill(skill.id)"
                    >
                        <div v-if="selectedSkillId === skill.id" class="absolute inset-y-0 left-0 w-1 bg-(interactive-primary)"></div>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p class="truncate text-base font-bold text-(--text-primary)">{{ skill.name }}</p>
                            <Badge :variant="skill.is_active ? 'success' : 'secondary'" size="sm" class="capitalize">
                                {{ skill.is_active ? "active" : "inactive" }}
                            </Badge>
                        </div>
                        <div class="mb-3 flex items-center gap-2">
                            <code class="rounded bg-(--surface-tertiary) px-1.5 py-0.5 text-[10px] font-medium text-(--text-muted)">{{ skill.slug }}</code>
                            <span class="text-[10px] text-(--text-tertiary)">•</span>
                            <span class="text-[10px] font-medium text-(--text-secondary)">{{ skill.department || "No Department" }}</span>
                        </div>
                        <div class="flex items-center justify-between border-t-(--border-muted)/50 border-t pt-2 text-[11px]">
                            <div class="flex items-center gap-1.5 text-(--text-muted)">
                                <Users class="h-3 w-3" />
                                <span>{{ skill.active_members_count }} Active</span>
                            </div>
                            <span class="font-medium text-(--text-secondary)">ID: {{ skill.id.substring(0, 8) }}</span>
                        </div>
                    </button>
                    <div v-if="!loading && skills.length === 0" class="flex flex-col items-center justify-center rounded-xl border border-dashed border-(--border-default) py-12 text-center">
                        <Cog class="mb-3 h-10 w-10 text-(--text-muted)/50" />
                        <p class="text-sm font-medium text-(--text-secondary)">No skills matched your search</p>
                        <Button variant="ghost" size="sm" class="mt-2" @click="filters = { q: '', department: '', is_active: '' }">Clear Filters</Button>
                    </div>
                </div>
            </Card>

            <div class="space-y-4 xl:col-span-8">
                <template v-if="selectedSkillId || isCreatingNew">
                    <Card class="overflow-hidden border-(--border-muted) bg-(--surface-primary) shadow-sm">
                        <div class="border-b-(--border-muted) border-b bg-(--surface-secondary)/30 px-6 py-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-(--interactive-primary)/10 text-(--interactive-primary)">
                                        <Cog class="h-5 w-5" />
                                    </div>
                                    <div>
                                        <h2 class="text-base font-bold text-(--text-primary)">
                                            {{ skillForm.id ? `Edit: ${skillForm.name}` : "Create New Skill Profile" }}
                                        </h2>
                                        <p class="text-xs text-(--text-muted)">Configure global routing attributes and priorities</p>
                                    </div>
                                </div>
                                <Button
                                    v-if="selectedSkill && canManageRouting"
                                    variant="ghost"
                                    size="sm"
                                    class="h-8 rounded-full border border-(--border-default) px-4 hover:bg-(--surface-tertiary)"
                                    @click="populateSkillForm(selectedSkill)"
                                >
                                    <RefreshCw class="mr-2 h-3.5 w-3.5" />
                                    Discard Changes
                                </Button>
                            </div>
                        </div>

                        <div class="space-y-6 p-6">
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div class="space-y-1.5">
                                    <label class="text-xs font-bold uppercase tracking-wider text-(--text-tertiary)">Official Name</label>
                                    <Input v-model="skillForm.name" :disabled="!canManageRouting" placeholder="e.g. Technical Support - Tier 2" class="bg-(--surface-secondary)/30 border-(--border-muted)" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-xs font-bold uppercase tracking-wider text-(--text-tertiary)">System Slug</label>
                                    <Input v-model="skillForm.slug" :disabled="!canManageRouting" placeholder="e.g. tech_support_t2" class="bg-(--surface-secondary)/30 border-(--border-muted)" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-xs font-bold uppercase tracking-wider text-(--text-tertiary)">Department / Region</label>
                                    <Input v-model="skillForm.department" :disabled="!canManageRouting" placeholder="e.g. Customer Success" class="bg-(--surface-secondary)/30 border-(--border-muted)" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-xs font-bold uppercase tracking-wider text-(--text-tertiary)">Routing Priority</label>
                                    <div class="flex items-center gap-3">
                                        <Input v-model.number="skillForm.priority" :disabled="!canManageRouting" type="number" min="1" max="65535" class="w-full bg-(--surface-secondary)/30 border-(--border-muted)" />
                                        <Badge variant="secondary" class="whitespace-nowrap border border-(--border-muted) bg-(--surface-secondary)/50 text-(--text-secondary)">Lower = Higher Priority</Badge>
                                    </div>
                                </div>
                                <div class="md:col-span-2 space-y-1.5">
                                    <label class="text-xs font-bold uppercase tracking-wider text-(--text-tertiary)">Internal Description</label>
                                    <Textarea
                                        v-model="skillForm.description"
                                        :disabled="!canManageRouting"
                                        rows="3"
                                        class="resize-none bg-(--surface-secondary)/30 shadow-none border-(--border-muted)"
                                        placeholder="Detailed escalation rules, required training level, or specific knowledge areas covered by this skill profile."
                                    />
                                </div>
                            </div>

                            <div class="flex items-center justify-between rounded-xl border border-(--border-muted) bg-(--surface-secondary)/30 p-4 transition-colors hover:bg-(--surface-secondary)/50">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex h-10 w-10 items-center justify-center rounded-full bg-(--surface-primary) shadow-sm ring-1 ring-(--border-muted) transition-colors"
                                        :class="skillForm.is_active ? 'text-emerald-500' : 'text-(--text-muted)'"
                                    >
                                        <Cog class="h-5 w-5" :class="{ 'animate-spin': skillForm.is_active }" />
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-(--text-primary)">Skill Availability</p>
                                        <p class="text-xs text-(--text-muted)">Inactive skills are excluded from automatic case assignment</p>
                                    </div>
                                </div>
                                <div
                                    class="relative h-6 w-11 cursor-pointer rounded-full transition-colors duration-200 focus:outline-none"
                                    :class="skillForm.is_active ? 'bg-emerald-500 shadow-inner' : 'bg-(--surface-tertiary) ring-1 ring-inset ring-(--border-muted)'"
                                    @click="canManageRouting && (skillForm.is_active = !skillForm.is_active)"
                                >
                                    <div
                                        class="absolute left-1 top-1 h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                        :class="skillForm.is_active ? 'translate-x-5' : 'translate-x-0'"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        <div class="border-t-(--border-muted) border-t bg-(--surface-secondary)/20 px-6 py-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2 text-xs text-(--text-muted)">
                                    <ShieldCheck class="h-3.5 w-3.5" />
                                    <span>Changes will propagate to routing engines within 60 seconds.</span>
                                </div>
                                <Button v-if="canManageRouting" variant="primary" :disabled="savingSkill" class="px-8 shadow-sm" @click="saveSkill">
                                    <Save v-if="!savingSkill" class="mr-2 h-4 w-4" />
                                    <RefreshCw v-else class="mr-2 h-4 w-4 animate-spin" />
                                    {{ savingSkill ? "Saving Profile..." : "Publish Profile" }}
                                </Button>
                            </div>
                        </div>
                    </Card>

                    <Card v-if="selectedSkillId" class="overflow-hidden bg-(--surface-base) shadow-sm">
                        <div class="border-t-(--border-muted) border-t px-6 py-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-500/10 text-blue-500">
                                        <Users class="h-5 w-5" />
                                    </div>
                                    <div>
                                        <h2 class="text-base font-bold text-(--text-primary)">Skill Personnel</h2>
                                        <p class="text-xs text-(--text-muted)">Assign agents and define their specific roles in this skill</p>
                                    </div>
                                </div>
                                <Badge variant="secondary" class="rounded-full px-3 font-mono border border-(--border-muted) bg-(--surface-secondary)/50 text-(--text-primary)">{{ selectedSkill?.memberships?.length || 0 }} Active Members</Badge>
                            </div>
                        </div>

                        <div class="space-y-6 p-6">
                            <div class="rounded-xl border border-(--border-muted) bg-(--surface-secondary)/10 p-4">
                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div class="space-y-1.5">
                                        <label class="text-xs font-bold uppercase tracking-wider text-(--text-tertiary)">Support Agent</label>
                                        <select
                                            v-model="memberForm.agent_public_id"
                                            :disabled="!canManageRouting"
                                            class="h-10 w-full rounded-lg border border-(--border-muted) bg-(--surface-primary) px-3 text-sm text-(--text-primary) focus:border-(--interactive-primary) focus:outline-none transition-all hover:border-(--border-default)"
                                        >
                                            <option value="">Search agents by name or email...</option>
                                            <option v-for="agent in agents" :key="agent.id" :value="agent.id">
                                                {{ agent.name }} — {{ agent.email || "No email" }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="text-xs font-bold uppercase tracking-wider text-(--text-tertiary)">Specialized Functional Role</label>
                                        <select
                                            v-model="memberForm.membership_role"
                                            :disabled="!canManageRouting"
                                            class="h-10 w-full rounded-lg border border-(--border-muted) bg-(--surface-primary) px-3 text-sm text-(--text-primary) focus:border-(--interactive-primary) focus:outline-none transition-all hover:border-(--border-default)"
                                        >
                                            <option value="team_lead">Team Lead (Supervisor)</option>
                                            <option value="sme">SME (Area Expert)</option>
                                            <option value="qa">QA (Quality Analyst)</option>
                                            <option value="agent">Standard Agent</option>
                                        </select>
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="text-xs font-bold uppercase tracking-wider text-(--text-tertiary)">Case Capacity Override</label>
                                        <Input
                                            v-model="memberForm.capacity"
                                            :disabled="!canManageRouting"
                                            type="number"
                                            min="1"
                                            max="65535"
                                            class="bg-(--surface-primary) border-(--border-muted)"
                                            placeholder="System default if empty"
                                        />
                                    </div>
                                    <div class="flex items-end gap-6 pb-2">
                                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-(--text-primary) select-none">
                                            <input v-model="memberForm.is_primary" :disabled="!canManageRouting" type="checkbox" class="h-4 w-4 rounded border-(--border-muted) text-(--interactive-primary) bg-(--surface-primary) focus:ring-0 focus:ring-offset-0" />
                                            Primary Focus
                                        </label>
                                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-(--text-primary) select-none">
                                            <input v-model="memberForm.is_active" :disabled="!canManageRouting" type="checkbox" class="h-4 w-4 rounded border-(--border-muted) text-(--interactive-primary) bg-(--surface-primary) focus:ring-0 focus:ring-offset-0" />
                                            Enabled
                                        </label>
                                    </div>
                                </div>

                                <div class="mt-4 flex items-center justify-end gap-2 border-t-(--border-muted) border-t pt-4">
                                    <Button variant="ghost" size="sm" :disabled="!canManageRouting" class="text-(--text-muted)" @click="resetMemberForm">Clear Entry</Button>
                                    <Button v-if="canManageRouting" variant="primary" size="sm" :disabled="savingMember" @click="saveMembership">
                                        <UserPlus2 v-if="!savingMember" class="mr-2 h-4 w-4" />
                                        <RefreshCw v-else class="mr-2 h-4 w-4 animate-spin" />
                                        {{ savingMember ? "Updating Member..." : "Add to Registry" }}
                                    </Button>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <TransitionGroup name="list">
                                    <div
                                        v-for="member in selectedSkill.memberships || []"
                                        :key="member.id"
                                        class="group overflow-hidden rounded-xl border border-(--border-muted) bg-(--surface-base) transition-all hover:border-(--border-strong) hover:shadow-sm"
                                    >
                                        <div class="flex items-center justify-between p-4">
                                            <div class="flex items-center gap-3">
                                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-(--surface-secondary) text-(--text-muted) font-bold">
                                                    {{ member.user?.name?.charAt(0) || "?" }}
                                                </div>
                                                <div>
                                                    <p class="text-sm font-bold text-(--text-primary)">{{ member.user?.name || "Unidentified Agent" }}</p>
                                                    <p class="text-xs text-(--text-muted)">{{ member.user?.email || "No direct contact" }}</p>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-3">
                                                <div class="hidden flex-col items-end gap-1 md:flex">
                                                    <div class="flex items-center gap-2">
                                                        <Badge variant="secondary" class="rounded-full text-[10px] uppercase tracking-tighter border border-(--border-muted) bg-(--surface-secondary)/50 text-(--text-primary)">{{ roleLabel(member.membership_role) }}</Badge>
                                                        <Badge :variant="member.is_active ? 'success' : 'secondary'" class="rounded-full text-[10px] uppercase tracking-tighter border border-(--border-muted)/50">{{ member.is_active ? "active" : "standby" }}</Badge>
                                                    </div>
                                                    <div class="flex items-center gap-2 text-[10px] text-(--text-muted)">
                                                        <span v-if="member.is_primary" class="font-bold text-blue-500/80">PRIMARY FOCUS</span>
                                                        <span v-if="member.is_primary && member.capacity">•</span>
                                                        <span v-if="member.capacity">CAPACITY: {{ member.capacity }}</span>
                                                    </div>
                                                </div>
                                                <div class="flex h-8 items-center gap-1 border-l-(--border-muted) border-l pl-2 opacity-0 transition-opacity group-hover:opacity-100">
                                                    <Button v-if="canManageRouting" variant="ghost" size="icon" class="h-7 w-7" @click="editMember(member)">
                                                        <Pencil class="h-3.5 w-3.5" />
                                                    </Button>
                                                    <Button
                                                        v-if="canManageRouting"
                                                        variant="ghost"
                                                        size="icon"
                                                        class="h-7 w-7 text-red-500 hover:bg-red-50 hover:text-red-600"
                                                        :disabled="deletingMemberUserId === member.user?.id"
                                                        @click="removeMembership(member)"
                                                    >
                                                        <Trash2 class="h-3.5 w-3.5" />
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </TransitionGroup>

                                <div
                                    v-if="(selectedSkill.memberships || []).length === 0"
                                    class="flex flex-col items-center justify-center rounded-xl border border-dashed border-(--border-default) py-12 text-center"
                                >
                                    <UserPlus2 class="mb-3 h-10 w-10 text-(--text-muted)/30" />
                                    <p class="text-sm font-medium text-(--text-secondary)">This skill set has no active members</p>
                                    <p class="mt-1 text-xs text-(--text-muted)">Tickets routed here will remain in the unassigned pool</p>
                                </div>
                            </div>
                        </div>
                    </Card>
                </template>

                <div v-else class="flex h-full min-h-[500px] flex-col items-center justify-center rounded-2xl border-2 border-dashed border-(--border-default) bg-(--surface-secondary)/20 p-12 text-center">
                    <div class="mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-(--surface-base) shadow-sm">
                        <ShieldCheck class="h-8 w-8 text-(--interactive-primary) opacity-40" />
                    </div>
                    <h3 class="text-lg font-bold text-(--text-primary)">No Skill Selected</h3>
                    <p class="mx-auto mt-2 max-w-md text-sm text-(--text-muted)">
                        Select a skill profile from the registry on the left to manage its routing configuration, priority, and assigned personnel.
                    </p>
                    <div class="mt-8 flex items-center gap-3">
                        <div class="flex items-center gap-2 text-xs font-medium text-(--text-secondary)">
                            <div class="h-1.5 w-1.5 rounded-full bg-orange-500"></div>
                            Configure Routes
                        </div>
                        <div class="h-4 w-px bg-(--border-default)"></div>
                        <div class="flex items-center gap-2 text-xs font-medium text-(--text-secondary)">
                            <div class="h-1.5 w-1.5 rounded-full bg-blue-500"></div>
                            Assign Personnel
                        </div>
                        <div class="h-4 w-px bg-(--border-default)"></div>
                        <div class="flex items-center gap-2 text-xs font-medium text-(--text-secondary)">
                            <div class="h-1.5 w-1.5 rounded-full bg-emerald-500"></div>
                            Define Capacities
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
