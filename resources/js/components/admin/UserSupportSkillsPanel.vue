<script setup>
import { ref, onMounted, computed } from "vue";
import api from "@/lib/api";
import { 
    Plus, 
    Trash2, 
    Check, 
    X, 
    Loader2, 
    Shield, 
    Star, 
    Zap,
    Settings2,
    Info
} from "lucide-vue-next";
import { Button, Badge, Modal, SelectFilter, Input } from "@/components/ui";
import { toast } from "vue-sonner";

const props = defineProps({
    user: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(["updated"]);

const availableSkills = ref([]);
const isSkillsLoading = ref(false);
const isSaving = ref(false);
const showAddModal = ref(false);

const newMembership = ref({
    skill_id: "",
    membership_role: "agent",
    is_primary: false,
    is_active: true,
    capacity: ""
});

const roles = [
    { value: "team_lead", label: "Team Lead (Supervisor)" },
    { value: "sme", label: "SME (Area Expert)" },
    { value: "qa", label: "QA (Quality Analyst)" },
    { value: "agent", label: "Standard Agent" }
];

const roleLabel = (role) => {
    if (role === "team_lead") return "Team Lead";
    if (role === "sme") return "SME";
    if (role === "qa") return "QA";
    return "Agent";
};

const fetchAvailableSkills = async () => {
    isSkillsLoading.value = true;
    try {
        const response = await api.get("/api/support/chats/skills", {
            params: { is_active: true }
        });
        availableSkills.value = Array.isArray(response.data?.data) ? response.data.data : [];
    } catch (error) {
        console.error("Failed to fetch skills:", error);
    } finally {
        isSkillsLoading.value = false;
    }
};

const addSkill = async () => {
    if (!newMembership.value.skill_id) {
        toast.error("Please select a skill");
        return;
    }

    isSaving.value = true;
    try {
        const skill = availableSkills.value.find(s => s.id === newMembership.value.skill_id);
        
        await api.post(`/api/support/chats/skills/${skill.id}/members`, {
            agent_public_id: props.user.public_id,
            membership_role: newMembership.value.membership_role,
            is_primary: newMembership.value.is_primary,
            is_active: newMembership.value.is_active,
            capacity: newMembership.value.capacity || null
        });

        toast.success(`User added to ${skill.name} skill`);
        showAddModal.value = false;
        emit("updated");
        
        // Reset form
        newMembership.value = {
            skill_id: "",
            membership_role: "agent",
            is_primary: false,
            is_active: true,
            capacity: ""
        };
    } catch (error) {
        console.error("Failed to add skill:", error);
        toast.error(error.response?.data?.message || "Failed to add skill");
    } finally {
        isSaving.value = false;
    }
};

const removeSkill = async (membership) => {
    if (!confirm(`Are you sure you want to remove the user from ${membership.skill.name}?`)) return;

    isSaving.value = true;
    try {
        // The endpoint is /api/support/chats/skills/{skill}/members/{user_public_id}
        await api.delete(`/api/support/chats/skills/${membership.skill.id}/members/${props.user.public_id}`);
        toast.success(`User removed from ${membership.skill.name}`);
        emit("updated");
    } catch (error) {
        console.error("Failed to remove skill:", error);
        toast.error(error.response?.data?.message || "Failed to remove skill");
    } finally {
        isSaving.value = false;
    }
};

const togglePrimary = async (membership) => {
    isSaving.value = true;
    try {
        await api.post(`/api/support/chats/skills/${membership.skill.id}/members`, {
            agent_public_id: props.user.public_id,
            is_primary: !membership.is_primary,
            membership_role: membership.membership_role,
            is_active: membership.is_active,
            capacity: membership.capacity || null
        });
        emit("updated");
    } catch (error) {
        toast.error("Failed to update status");
    } finally {
        isSaving.value = false;
    }
};

const filteredAvailableSkills = computed(() => {
    const assignedIds = new Set(props.user.support_skills?.map(s => s.skill.id) || []);
    return (availableSkills.value || []).filter(s => !assignedIds.has(s.id));
});

const availableSkillOptions = computed(() => {
    return filteredAvailableSkills.value.map(s => ({ 
        value: s.id, 
        label: s.name 
    }));
});

const isModalValid = computed(() => {
    return !!newMembership.value.skill_id;
});


onMounted(() => {
    fetchAvailableSkills();
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <Shield class="w-4 h-4 text-[var(--text-muted)]" />
                <h4 class="text-sm font-semibold text-(--text-primary)">Support Routing Skills</h4>
            </div>
            <Button variant="outline" size="sm" @click="showAddModal = true" class="h-8 gap-1">
                <Plus class="w-3.5 h-3.5" />
                Assign Skill
            </Button>
        </div>

        <div v-if="!user.support_skills?.length" class="flex flex-col items-center justify-center py-8 px-4 border border-dashed border-[var(--border-default)] rounded-xl bg-[var(--surface-secondary)]/30">
            <Zap class="w-8 h-8 text-[var(--text-muted)] opacity-20 mb-2" />
            <p class="text-sm text-[var(--text-muted)] text-center">No support skills assigned to this user.</p>
            <p class="text-xs text-[var(--text-tertiary)] text-center mt-1">Assign skills to enable chat routing and specialized support.</p>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div 
                v-for="membership in user.support_skills" 
                :key="membership.id"
                class="group bg-[var(--surface-secondary)]/50 rounded-xl border border-[var(--border-default)] p-4 hover:border-[var(--interactive-primary)]/30 transition-all shadow-sm"
            >
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-(--surface-primary) flex items-center justify-center text-[var(--interactive-primary)] shadow-sm border border-[var(--border-default)]">
                            <Zap class="w-5 h-5" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h5 class="font-bold text-(--text-primary)">{{ membership.skill.name }}</h5>
                                <Badge v-if="membership.is_primary" variant="primary" class="text-[10px] h-4 py-0 px-1.5 flex items-center gap-0.5">
                                    <Star class="w-2.5 h-2.5 fill-current" />
                                    Primary
                                </Badge>
                            </div>
                            <p class="text-xs text-[var(--text-muted)]">{{ roleLabel(membership.membership_role) }} <span v-if="membership.capacity">• Capacity: {{ membership.capacity }}</span></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        <Button 
                            variant="ghost" 
                            size="icon" 
                            class="h-8 w-8 text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                            @click="togglePrimary(membership)"
                            :title="membership.is_primary ? 'Unset Primary' : 'Set as Primary'"
                        >
                            <Star :class="{ 'fill-amber-400 text-amber-400': membership.is_primary }" class="w-4 h-4" />
                        </Button>
                        <Button 
                            variant="ghost" 
                            size="icon" 
                            class="h-8 w-8 text-[var(--text-muted)] hover:text-red-500"
                            @click="removeSkill(membership)"
                            title="Remove Skill"
                        >
                            <Trash2 class="w-4 h-4" />
                        </Button>
                    </div>
                </div>
                
                <div v-if="membership.skill.description" class="mt-3 text-xs text-[var(--text-muted)] line-clamp-1 italic">
                    "{{ membership.skill.description }}"
                </div>

                <div class="mt-3 pt-3 border-t border-[var(--border-default)] flex items-center justify-between">
                    <div class="flex items-center gap-1.5">
                        <div :class="membership.is_active ? 'bg-green-500' : 'bg-gray-400'" class="w-1.5 h-1.5 rounded-full"></div>
                        <span class="text-[10px] font-medium uppercase tracking-wider text-[var(--text-tertiary)]">
                            {{ membership.is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                    <Badge variant="secondary" class="text-[10px] py-0 h-4 bg-[var(--surface-primary)] border-[var(--border-default)]">
                        ID: {{ membership.skill.id.split('-')[0] }}
                    </Badge>
                </div>
            </div>
        </div>

        <!-- Add Skill Modal -->
        <Modal v-model:open="showAddModal" size="md" title="Add Support Skill">
            <div class="space-y-6">
                <div class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-100 dark:border-blue-800/30 flex gap-3">
                    <Info class="w-5 h-5 text-blue-600 shrink-0" />
                    <p class="text-xs text-blue-700 dark:text-blue-300 leading-relaxed">
                        Assigning a skill allows this user to receive routed chats and tickets associated with that category. 
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold text-(--text-secondary)">Skill Category</label>
                        <SelectFilter 
                            v-model="newMembership.skill_id"
                            :options="availableSkillOptions"
                            placeholder="Select a skill category..."
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-xs font-semibold text-(--text-secondary)">Agent Role</label>
                            <SelectFilter v-model="newMembership.membership_role" :options="roles" />
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-xs font-semibold text-(--text-secondary)">Case Capacity Override</label>
                            <Input
                                v-model="newMembership.capacity"
                                type="number"
                                min="1"
                                max="65535"
                                placeholder="System default if empty"
                                class="bg-(--surface-primary) border-(--border-muted)"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-between p-3 bg-[var(--surface-secondary)]/50 rounded-xl border border-[var(--border-default)]">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-[var(--surface-primary)] flex items-center justify-center text-amber-500 border border-[var(--border-default)]">
                                <Star class="w-4 h-4" />
                            </div>
                            <div>
                                <h6 class="text-xs font-bold text-[var(--text-primary)]">Set as Primary Skill</h6>
                                <p class="text-[10px] text-[var(--text-muted)]">Priority for routing</p>
                            </div>
                        </div>
                        <button 
                            @click="newMembership.is_primary = !newMembership.is_primary"
                            :class="newMembership.is_primary ? 'bg-[var(--interactive-primary)]' : 'bg-[var(--surface-tertiary)]'"
                            class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out"
                        >
                            <span :class="newMembership.is_primary ? 'translate-x-5' : 'translate-x-1'" class="pointer-events-none mt-1 inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
                        </button>
                    </div>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-end gap-3">
                    <Button variant="outline" @click="showAddModal = false">Cancel</Button>
                    <Button variant="primary" @click="addSkill" :loading="isSaving" :disabled="!isModalValid">Save Assignment</Button>
                </div>
            </template>
        </Modal>
    </div>
</template>
