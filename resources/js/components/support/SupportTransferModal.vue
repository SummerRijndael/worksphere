<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { 
    Users, 
    Layers, 
    Search, 
    Check, 
    Loader2,
    Monitor,
    Shield
} from "lucide-vue-next";
import Modal from "@/components/ui/Modal.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Avatar from "@/components/ui/Avatar.vue";
import api from "@/lib/api";
import { toast } from "vue-sonner";

const props = defineProps<{
    open: boolean;
    conversationId: string | null;
}>();

const emit = defineEmits(["update:open", "success", "close"]);

const activeTab = ref<"agents" | "queues">("agents");
const searchQuery = ref("");
const loading = ref(false);
const transferring = ref(false);

const agents = ref<any[]>([]);
const skills = ref<any[]>([]);

const selectedTargetId = ref<string | null>(null);

async function fetchData() {
    if (!props.open) return;
    
    loading.value = true;
    try {
        const [agentsRes, skillsRes] = await Promise.all([
            api.get("/api/support/chats/agents"),
            api.get("/api/support/chats/skills")
        ]);
        agents.value = agentsRes.data.data;
        // The skill controller returns paginated data in 'data.data' usually, or just 'data' if simple.
        // Let's check SupportSkillController index. It returns $this->paginatedSkillResponse
        // which has 'data' as the collection.
        skills.value = skillsRes.data.data;
    } catch (error) {
        toast.error("Failed to load transfer targets.");
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (newVal) => {
    if (newVal) {
        selectedTargetId.value = null;
        searchQuery.value = "";
        fetchData();
    }
});

const filteredAgents = computed(() => {
    const q = searchQuery.value.toLowerCase().trim();
    if (!q) return agents.value;
    return agents.value.filter(a => 
        (a.name || '').toLowerCase().includes(q) || 
        (a.email || '').toLowerCase().includes(q)
    );
});

const filteredSkills = computed(() => {
    const q = searchQuery.value.toLowerCase().trim();
    if (!q) return skills.value;
    return skills.value.filter(s => 
        (s.name || '').toLowerCase().includes(q) || 
        (s.department || '').toLowerCase().includes(q)
    );
});

async function handleTransfer() {
    if (!selectedTargetId.value || !props.conversationId || transferring.value) return;

    transferring.value = true;
    try {
        if (activeTab.value === "agents") {
            await api.post(`/api/support/chats/${props.conversationId}/transfer`, {
                agent_public_id: selectedTargetId.value
            });
        } else {
            await api.post(`/api/support/chats/${props.conversationId}/routing/skill`, {
                support_skill_id: selectedTargetId.value
            });
        }
        toast.success(`Chat transferred to ${activeTab.value === "agents" ? "agent" : "queue"} successfully.`);
        emit("success", props.conversationId);
        emit("update:open", false);
    } catch (error: any) {
        toast.error(error?.response?.data?.message || "Transfer failed.");
    } finally {
        transferring.value = false;
    }
}

function selectTarget(id: string) {
    selectedTargetId.value = id;
}
</script>

<template>
    <Modal 
        :open="open" 
        title="Transfer Chat" 
        description="Select an agent or queue to transfer this conversation to."
        size="md"
        @update:open="$emit('update:open', $event)"
        @close="$emit('close')"
    >
        <div class="space-y-4">
            <!-- Tabs -->
            <div class="flex p-1 bg-(--surface-secondary)/50 rounded-lg border border-(--border-default)/50">
                <button 
                    type="button"
                    class="flex-1 flex items-center justify-center gap-2 py-1.5 text-xs font-semibold rounded-md transition-all"
                    :class="activeTab === 'agents' ? 'bg-(--surface-primary) text-(--text-primary) shadow-sm' : 'text-(--text-secondary) hover:text-(--text-primary)'"
                    @click="activeTab = 'agents'; selectedTargetId = null"
                >
                    <Users class="h-3.5 w-3.5" />
                    Agents
                </button>
                <button 
                    type="button"
                    class="flex-1 flex items-center justify-center gap-2 py-1.5 text-xs font-semibold rounded-md transition-all"
                    :class="activeTab === 'queues' ? 'bg-(--surface-primary) text-(--text-primary) shadow-sm' : 'text-(--text-secondary) hover:text-(--text-primary)'"
                    @click="activeTab = 'queues'; selectedTargetId = null"
                >
                    <Layers class="h-3.5 w-3.5" />
                    Queues
                </button>
            </div>

            <!-- Search -->
            <div class="relative">
                <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-(--text-muted)" />
                <Input 
                    v-model="searchQuery" 
                    placeholder="Search targets..." 
                    class="pl-9 h-10 text-sm bg-(--surface-secondary)/30 border-(--border-default)/50 focus:border-(--interactive-primary)"
                />
            </div>

            <!-- List -->
            <div class="min-h-[300px] max-h-[400px] overflow-y-auto border border-(--border-default)/50 rounded-xl bg-(--surface-primary)/50 scrollbar-thin">
                <div v-if="loading" class="flex flex-col items-center justify-center h-[300px] text-(--text-muted) space-y-3">
                    <Loader2 class="h-8 w-8 animate-spin" />
                    <p class="text-sm">Loading available targets...</p>
                </div>

                <div v-else-if="activeTab === 'agents'">
                    <div 
                        v-for="agent in filteredAgents" 
                        :key="agent.id"
                        class="group flex items-center gap-3 p-3 cursor-pointer border-b border-(--border-default)/30 last:border-0 hover:bg-(--interactive-primary)/5 transition-colors"
                        :class="{ 'bg-(--interactive-primary)/10': selectedTargetId === agent.id }"
                        @click="selectTarget(agent.id)"
                    >
                        <Avatar 
                            :alt="agent.name" 
                            size="sm" 
                            class="ring-2 ring-offset-2 ring-offset-(--surface-elevated) transition-all"
                            :class="selectedTargetId === agent.id ? 'ring-(--interactive-primary)' : 'ring-transparent'"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold truncate text-(--text-primary)">{{ agent.name }}</p>
                            <p class="text-[11px] truncate text-(--text-secondary)">{{ agent.email }}</p>
                        </div>
                        <div v-if="selectedTargetId === agent.id" class="h-5 w-5 shrink-0 rounded-full bg-(--interactive-primary) flex items-center justify-center">
                            <Check class="h-3 w-3 text-white" />
                        </div>
                    </div>
                </div>

                <div v-else-if="activeTab === 'queues'">
                    <div 
                        v-for="skill in filteredSkills" 
                        :key="skill.id"
                        class="group flex items-center gap-3 p-3 cursor-pointer border-b border-(--border-default)/30 last:border-0 hover:bg-(--interactive-primary)/5 transition-colors"
                        :class="{ 'bg-(--interactive-primary)/10': selectedTargetId === skill.id }"
                        @click="selectTarget(skill.id)"
                    >
                        <div 
                            class="h-10 w-10 shrink-0 flex items-center justify-center rounded-xl bg-(--surface-secondary) text-(--text-secondary) group-hover:bg-(--interactive-primary)/10 group-hover:text-(--interactive-primary) transition-all"
                            :class="{ 'bg-(--interactive-primary)/20 text-(--interactive-primary)': selectedTargetId === skill.id }"
                        >
                            <Monitor v-if="skill.slug === 'technical-support'" class="h-5 w-5" />
                            <Shield v-else-if="skill.slug === 'compliance'" class="h-5 w-5" />
                            <Layers v-else class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold truncate text-(--text-primary)">{{ skill.name }}</p>
                            <p v-if="skill.department" class="text-[11px] truncate text-(--text-secondary)">{{ skill.department }}</p>
                        </div>
                        <div v-if="selectedTargetId === skill.id" class="h-5 w-5 shrink-0 rounded-full bg-(--interactive-primary) flex items-center justify-center">
                            <Check class="h-3 w-3 text-white" />
                        </div>
                    </div>
                </div>

                <div v-if="!loading && (activeTab === 'agents' ? filteredAgents.length === 0 : filteredSkills.length === 0)" class="flex flex-col items-center justify-center h-[300px] text-(--text-muted) p-6 text-center">
                    <Search class="h-10 w-10 opacity-20 mb-3" />
                    <p class="text-sm font-medium">No results found for "{{ searchQuery }}"</p>
                    <p class="text-xs mt-1">Try a different search term or tab.</p>
                </div>
            </div>
        </div>

        <template #footer>
            <Button variant="ghost" @click="$emit('close')" :disabled="transferring">Cancel</Button>
            <Button 
                variant="primary" 
                class="min-w-[120px]" 
                :disabled="!selectedTargetId || transferring"
                @click="handleTransfer"
            >
                <Loader2 v-if="transferring" class="mr-2 h-4 w-4 animate-spin" />
                Transfer Chat
            </Button>
        </template>
    </Modal>
</template>
