<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import api from "@/lib/api";
import { Badge, Button, Card, Input, Textarea } from "@/components/ui";
import { useToast } from "@/composables/useToast.ts";
import { useAuthStore } from "@/stores/auth";
import { Cog, Plus, RefreshCw, ShieldCheck, Users, UserPlus2, Trash2, Save, Pencil } from "lucide-vue-next";

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

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <Card class="p-4">
                <div class="mb-2 flex items-center justify-between">
                    <ShieldCheck class="h-5 w-5 text-[var(--interactive-primary)]" />
                    <Badge variant="secondary" size="sm">Skills</Badge>
                </div>
                <p class="text-xs uppercase tracking-wide text-[var(--text-secondary)]">Total Skills</p>
                <p class="mt-2 text-2xl font-semibold text-[var(--text-primary)]">{{ totalSkills }}</p>
            </Card>
            <Card class="p-4">
                <div class="mb-2 flex items-center justify-between">
                    <Cog class="h-5 w-5 text-emerald-500" />
                    <Badge variant="success" size="sm">Active</Badge>
                </div>
                <p class="text-xs uppercase tracking-wide text-[var(--text-secondary)]">Active Skills</p>
                <p class="mt-2 text-2xl font-semibold text-[var(--text-primary)]">{{ activeSkills }}</p>
            </Card>
            <Card class="p-4">
                <div class="mb-2 flex items-center justify-between">
                    <Users class="h-5 w-5 text-blue-500" />
                    <Badge variant="info" size="sm">Coverage</Badge>
                </div>
                <p class="text-xs uppercase tracking-wide text-[var(--text-secondary)]">Active Skill Memberships</p>
                <p class="mt-2 text-2xl font-semibold text-[var(--text-primary)]">{{ totalMembers }}</p>
            </Card>
        </div>

        <Card class="p-4">
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <Input v-model="filters.q" placeholder="Search skill name, slug, or description..." />
                <select
                    v-model="filters.department"
                    class="h-10 rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] px-3 text-sm text-[var(--text-primary)] focus:border-[var(--interactive-primary)] focus:outline-none"
                >
                    <option value="">All departments</option>
                    <option v-for="department in departments" :key="department" :value="department">
                        {{ department }}
                    </option>
                </select>
                <select
                    v-model="filters.is_active"
                    class="h-10 rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] px-3 text-sm text-[var(--text-primary)] focus:border-[var(--interactive-primary)] focus:outline-none"
                >
                    <option value="">All statuses</option>
                    <option value="true">Active</option>
                    <option value="false">Inactive</option>
                </select>
            </div>
        </Card>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-12">
            <Card class="p-3 xl:col-span-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-[var(--text-primary)]">Skills</h2>
                    <Badge variant="secondary" size="sm">{{ skills.length }}</Badge>
                </div>
                <div class="max-h-[620px] space-y-2 overflow-y-auto pr-1">
                    <button
                        v-for="skill in skills"
                        :key="skill.id"
                        type="button"
                        class="w-full rounded-lg border p-3 text-left transition"
                        :class="selectedSkillId === skill.id
                            ? 'border-[var(--interactive-primary)] bg-[var(--interactive-primary)]/10'
                            : 'border-[var(--border-default)] hover:border-[var(--border-strong)]'"
                        @click="selectSkill(skill.id)"
                    >
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <p class="truncate text-sm font-semibold text-[var(--text-primary)]">{{ skill.name }}</p>
                            <Badge :variant="skill.is_active ? 'success' : 'secondary'" size="sm">
                                {{ skill.is_active ? "active" : "inactive" }}
                            </Badge>
                        </div>
                        <p class="truncate text-xs text-[var(--text-secondary)]">{{ skill.slug }}</p>
                        <div class="mt-2 flex items-center justify-between text-xs text-[var(--text-muted)]">
                            <span>{{ skill.department || "No department" }}</span>
                            <span>{{ skill.active_members_count }}/{{ skill.members_count }} members</span>
                        </div>
                    </button>
                    <p v-if="!loading && skills.length === 0" class="rounded-lg border border-dashed border-[var(--border-default)] p-4 text-sm text-[var(--text-secondary)]">
                        No support skills found for the current filters.
                    </p>
                </div>
            </Card>

            <div class="space-y-4 xl:col-span-8">
                <Card class="p-4">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-[var(--text-primary)]">
                            {{ skillForm.id ? "Edit Skill" : "Create Skill" }}
                        </h2>
                        <Button
                            v-if="selectedSkill && canManageRouting"
                            variant="ghost"
                            size="sm"
                            @click="populateSkillForm(selectedSkill)"
                        >
                            <Pencil class="mr-2 h-4 w-4" />
                            Reset Form
                        </Button>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">Name</label>
                            <Input v-model="skillForm.name" :disabled="!canManageRouting" placeholder="Billing Escalations" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">Slug</label>
                            <Input v-model="skillForm.slug" :disabled="!canManageRouting" placeholder="billing_escalations" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">Department</label>
                            <Input v-model="skillForm.department" :disabled="!canManageRouting" placeholder="Support" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">Priority</label>
                            <Input v-model.number="skillForm.priority" :disabled="!canManageRouting" type="number" min="1" max="65535" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">Description</label>
                            <Textarea
                                v-model="skillForm.description"
                                :disabled="!canManageRouting"
                                rows="3"
                                placeholder="Routing notes, escalation rules, and ownership context."
                            />
                        </div>
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                        <input id="skill-active" v-model="skillForm.is_active" :disabled="!canManageRouting" type="checkbox" class="h-4 w-4 rounded border-[var(--border-default)]" />
                        <label for="skill-active" class="text-sm text-[var(--text-primary)]">Skill is active</label>
                    </div>

                    <div class="mt-4 flex items-center justify-between">
                        <p class="text-xs text-[var(--text-muted)]">
                            Roles: Team Lead can assign/monitor, SME can assign, QA can resolve, Agent can reply.
                        </p>
                        <Button v-if="canManageRouting" variant="primary" :disabled="savingSkill" @click="saveSkill">
                            <Save class="mr-2 h-4 w-4" />
                            {{ savingSkill ? "Saving..." : "Save Skill" }}
                        </Button>
                    </div>
                </Card>

                <Card class="p-4">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-[var(--text-primary)]">Skill Members</h2>
                        <Badge variant="secondary" size="sm">{{ selectedSkill?.memberships?.length || 0 }}</Badge>
                    </div>

                    <div v-if="selectedSkill" class="space-y-4">
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">Agent</label>
                                <select
                                    v-model="memberForm.agent_public_id"
                                    :disabled="!canManageRouting"
                                    class="h-10 w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] px-3 text-sm text-[var(--text-primary)] focus:border-[var(--interactive-primary)] focus:outline-none"
                                >
                                    <option value="">Select agent</option>
                                    <option v-for="agent in agents" :key="agent.id" :value="agent.id">
                                        {{ agent.name }} {{ agent.email ? `(${agent.email})` : "" }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">Membership Role</label>
                                <select
                                    v-model="memberForm.membership_role"
                                    :disabled="!canManageRouting"
                                    class="h-10 w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] px-3 text-sm text-[var(--text-primary)] focus:border-[var(--interactive-primary)] focus:outline-none"
                                >
                                    <option value="team_lead">Team Lead</option>
                                    <option value="sme">SME</option>
                                    <option value="qa">QA</option>
                                    <option value="agent">Agent</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">Capacity</label>
                                <Input
                                    v-model="memberForm.capacity"
                                    :disabled="!canManageRouting"
                                    type="number"
                                    min="1"
                                    max="65535"
                                    placeholder="Optional"
                                />
                            </div>
                            <div class="flex items-center gap-4 pt-6">
                                <label class="inline-flex items-center gap-2 text-sm text-[var(--text-primary)]">
                                    <input v-model="memberForm.is_primary" :disabled="!canManageRouting" type="checkbox" class="h-4 w-4 rounded border-[var(--border-default)]" />
                                    Primary skill
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-[var(--text-primary)]">
                                    <input v-model="memberForm.is_active" :disabled="!canManageRouting" type="checkbox" class="h-4 w-4 rounded border-[var(--border-default)]" />
                                    Active
                                </label>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-2">
                            <Button variant="ghost" :disabled="!canManageRouting" @click="resetMemberForm">Reset</Button>
                            <Button v-if="canManageRouting" variant="primary" :disabled="savingMember" @click="saveMembership">
                                <UserPlus2 class="mr-2 h-4 w-4" />
                                {{ savingMember ? "Saving..." : "Save Member" }}
                            </Button>
                        </div>

                        <div class="space-y-2">
                            <div
                                v-for="member in selectedSkill.memberships || []"
                                :key="member.id"
                                class="rounded-lg border border-[var(--border-default)] p-3"
                            >
                                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-[var(--text-primary)]">
                                            {{ member.user?.name || "Unknown Agent" }}
                                        </p>
                                        <p class="text-xs text-[var(--text-secondary)]">{{ member.user?.email || "No email" }}</p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Badge variant="secondary" size="sm">{{ roleLabel(member.membership_role) }}</Badge>
                                        <Badge :variant="member.is_active ? 'success' : 'secondary'" size="sm">
                                            {{ member.is_active ? "active" : "inactive" }}
                                        </Badge>
                                        <Badge v-if="member.is_primary" variant="info" size="sm">primary</Badge>
                                        <Badge v-if="member.capacity" variant="outline" size="sm">cap {{ member.capacity }}</Badge>
                                    </div>
                                </div>
                                <div class="mt-2 flex items-center justify-end gap-2">
                                    <Button v-if="canManageRouting" variant="ghost" size="sm" @click="editMember(member)">Edit</Button>
                                    <Button
                                        v-if="canManageRouting"
                                        variant="ghost"
                                        size="sm"
                                        class="text-red-500 hover:text-red-400"
                                        :disabled="deletingMemberUserId === member.user?.id"
                                        @click="removeMembership(member)"
                                    >
                                        <Trash2 class="mr-2 h-4 w-4" />
                                        Remove
                                    </Button>
                                </div>
                            </div>
                            <p
                                v-if="(selectedSkill.memberships || []).length === 0"
                                class="rounded-lg border border-dashed border-[var(--border-default)] p-4 text-sm text-[var(--text-secondary)]"
                            >
                                No members assigned yet. Add at least one agent to enable skill-specific routing.
                            </p>
                        </div>
                    </div>

                    <p
                        v-else
                        class="rounded-lg border border-dashed border-[var(--border-default)] p-4 text-sm text-[var(--text-secondary)]"
                    >
                        Select a skill from the left panel or create a new one to manage memberships.
                    </p>
                </Card>
            </div>
        </div>
    </div>
</template>
