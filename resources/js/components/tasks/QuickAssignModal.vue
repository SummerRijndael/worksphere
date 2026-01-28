<script setup lang="ts">
import { ref, watch, onMounted, watchEffect } from 'vue';
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';
import SelectFilter from '@/components/ui/SelectFilter.vue';
import axios from 'axios';
import { toast } from 'vue-sonner';
import { useAuthStore } from '@/stores/auth';

const authStore = useAuthStore();

const props = defineProps<{
    open: boolean;
    task: any;
    assignType?: 'operator' | 'qa';
}>();

const emit = defineEmits(['update:open', 'assigned']);

const isOpen = ref(props.open);
const isLoading = ref(false);
const isFetchingMembers = ref(false);
const members = ref<any[]>([]);
const selectedMemberId = ref<string | null>(null);

// Roles allowed for QA assignment
const QA_ROLES = ['subject_matter_expert', 'quality_assessor', 'team_lead', 'admin', 'administrator', 'owner'];

const fetchMembers = async () => {
    const teamId = props.task.project?.team_id || 
                   props.task.project?.team?.public_id || 
                   props.task.team_id || 
                   props.task.team_public_id ||
                   authStore.currentTeamId;
    
    if (!teamId) {
        console.warn('QuickAssignModal: No team ID found for task or session', props.task);
        return;
    }

    try {
        isFetchingMembers.value = true;
        // Fetch TEAM members to allow assigning any team member (for Operator)
        // and to get roles for filtering (for QA)
        const response = await axios.get(
            `/api/teams/${teamId}/members?per_page=100`
        );
        
        const rawMembers = response.data.data || [];
        // console.log('QuickAssignModal: Raw members from API:', rawMembers);

        let filteredMembers = rawMembers;
        if (props.assignType === 'qa') {
            filteredMembers = rawMembers.filter((m: any) => QA_ROLES.includes(m.role));
        }
        
        members.value = filteredMembers.map((m: any) => ({
            label: m.name,
            value: m.public_id || m.id,
            avatar: m.avatar_url,
            subtitle: (m.team_role || m.role)?.replace(/_/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase()) || m.email
        }));
        console.log(`QuickAssignModal (${props.assignType}): Members:`, members.value);
    } catch (error) {
        console.error("Failed to fetch members", error);
        toast.error("Could not load team members.");
    } finally {
        isFetchingMembers.value = false;
    }
};

watch(() => props.open, (val) => {
    isOpen.value = val;
    if (val && props.task) {
        // Pre-select based on assignType
        if (props.assignType === 'qa') {
            selectedMemberId.value = props.task.qa_user?.id || props.task.qa_user?.public_id || props.task.qa_user_id || null;
        } else {
            selectedMemberId.value = props.task.assignee?.id || props.task.assignee?.public_id || props.task.assigned_to || props.task.assigned_to_id || null;
        }
        fetchMembers();
    }
}, { immediate: true });

watch(isOpen, (val) => {
    emit('update:open', val);
});

const onSave = async () => {
    const teamId = props.task.project?.team_id || 
                   props.task.project?.team?.public_id || 
                   props.task.team_id || 
                   props.task.team_public_id ||
                   authStore.currentTeamId;

    const projectId = props.task.project?.id || props.task.project?.public_id || props.task.project_id;
    const taskId = props.task.public_id || props.task.id;

    if (!teamId || !projectId || !taskId) {
        toast.error("Invalid task data. Cannot update.");
        return;
    }

    try {
        isLoading.value = true;
        
        const payload: any = {};
        if (props.assignType === 'qa') {
            payload.qa_user_id = selectedMemberId.value;
        } else {
            payload.assigned_to = selectedMemberId.value;
        }

        await axios.put(
            `/api/teams/${teamId}/projects/${projectId}/tasks/${taskId}`,
            payload
        );

        toast.success(`Task ${props.assignType === 'qa' ? 'QA owner' : 'assignment'} updated.`);
        emit('assigned', selectedMemberId.value);
        isOpen.value = false;
    } catch (error) {
        console.error("Failed to update task", error);
        toast.error("Failed to update assignment.");
    } finally {
        isLoading.value = false;
    }
};
</script>

<template>
    <Modal
        :open="isOpen"
        @update:open="isOpen = $event"
        :title="props.assignType === 'qa' ? 'Quick Assign QA Owner' : 'Quick Assign Operator'"
        size="sm"
    >
        <div class="p-1 space-y-4">
            <div v-if="task" class="p-3 bg-[var(--surface-secondary)]/50 rounded-lg border border-[var(--border-subtle)]">
                <p class="text-xs font-semibold uppercase text-[var(--text-muted)] mb-1">Task</p>
                <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ task.title }}</p>
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-[var(--text-primary)]">Assign To</label>
                <div v-if="isFetchingMembers" class="h-10 flex items-center justify-center bg-[var(--surface-primary)] rounded-lg border border-[var(--border-default)]">
                    <span class="text-xs text-[var(--text-muted)] animate-pulse">Loading members...</span>
                </div>
                <SelectFilter
                    v-else
                    v-model="selectedMemberId"
                    :options="members"
                    placeholder="Select a member..."
                    searchable
                    class="w-full"
                />
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2 w-full">
                <Button variant="ghost" @click="isOpen = false">Cancel</Button>
                <Button :loading="isLoading" @click="onSave">Assign</Button>
            </div>
        </template>
    </Modal>
</template>
```
