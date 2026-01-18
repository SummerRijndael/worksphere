<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue';
import {
    Modal, Button, Input, Textarea, SelectFilter
} from '@/components/ui';
import { useForm } from 'vee-validate';
import { toTypedSchema } from '@vee-validate/zod';
import * as z from 'zod';
import axios from 'axios';
import { toast } from 'vue-sonner';

interface Props {
    open: boolean;
    task?: any; // If editing
    teamId?: string;
    projectId?: string;
    projectMembers?: any[];
}

import { useAuthStore } from '@/stores/auth';
const authStore = useAuthStore();

const props = withDefaults(defineProps<Props>(), {
    projectMembers: () => [],
});
const emit = defineEmits(['update:open', 'task-saved', 'close']);

const isEditing = computed(() => !!props.task);
const isLoading = ref(false);
const isFetchingMembers = ref(false);

const isOpen = computed({
    get: () => props.open,
    set: (val) => {
        emit('update:open', val);
        if (!val) emit('close');
    },
});

const schema = toTypedSchema(z.object({
    title: z.string().min(1, 'Title is required').max(255),
    description: z.string().optional(),
    status: z.string().min(1, 'Status is required'),
    priority: z.number().min(1, 'Priority is required'),
    due_date: z.string().optional(),
    assigned_to: z.string().optional(),
    estimated_hours: z.number().min(0).optional(),
}));

const { setValues, resetForm } = useForm({
    validationSchema: schema,
    initialValues: {
        status: 'open',
        priority: 2,
    }
});

const formValues = ref({
    title: '',
    description: '',
    status: 'open',
    priority: 2,
    due_date: '',
    assigned_to: '',
    estimated_hours: 0,
});

const statusOptions = [
    { value: 'open', label: 'To Do' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'in_qa', label: 'In QA' },
    { value: 'completed', label: 'Done' },
];

const priorityOptions = [
    { value: 1, label: 'Low' },
    { value: 2, label: 'Medium' },
    { value: 3, label: 'High' },
    { value: 4, label: 'Urgent' },
];

const localMembers = ref<any[]>([]);

const memberOptions = computed(() => {
    // First priority: use provided projectMembers if they exist and we have a matching project
    if (props.projectMembers && props.projectMembers.length > 0) {
        return props.projectMembers.map(member => ({
            value: member.public_id || member.id,
            label: member.name,
        }));
    }
    // Fallback to locally fetched members
    return localMembers.value.map(member => ({
        value: member.public_id || member.id,
        label: member.name,
    }));
});

// Dynamic State for Selectors
const selectedTeamId = ref('');
const selectedProjectId = ref('');
const projectOptions = ref<any[]>([]);

const teamOptions = computed(() => {
    return authStore.user?.teams?.map(team => ({
        value: team.public_id,
        label: team.name
    })) || [];
});

// Fetch projects when team changes
const fetchProjects = async () => {
    if (!selectedTeamId.value) return;
    try {
        const response = await axios.get(`/api/teams/${selectedTeamId.value}/projects`);
        projectOptions.value = response.data.data.map((p: any) => ({
            value: p.public_id,
            label: p.name
        }));
    } catch (error) {
        console.error('Failed to fetch projects', error);
    }
};

// Fetch members - only if not provided via props
const fetchMembers = async () => {
    // If members are already provided via props, don't fetch
    if (props.projectMembers && props.projectMembers.length > 0) {
        return;
    }

    // Need both team and project to fetch
    if (!selectedTeamId.value || !selectedProjectId.value) return;

    try {
        isFetchingMembers.value = true;
        const response = await axios.get(`/api/teams/${selectedTeamId.value}/projects/${selectedProjectId.value}`);
        localMembers.value = response.data.data?.members || [];
    } catch (error) {
        console.error('Failed to fetch project members', error);
    } finally {
        isFetchingMembers.value = false;
    }
};

// Watch team changes - only when user manually changes team (not from props)
watch(() => selectedTeamId.value, (newVal, oldVal) => {
    // Only reset project and fetch if this is a manual team change (not initial prop sync)
    if (oldVal && newVal !== oldVal) {
        projectOptions.value = [];
        selectedProjectId.value = '';
        localMembers.value = [];
    }
    if (newVal) {
        fetchProjects();
    }
});

// Watch project changes for fetching members
watch(() => selectedProjectId.value, (newVal, oldVal) => {
    if (newVal && newVal !== oldVal) {
        localMembers.value = [];
        fetchMembers();
    }
});

// Templates Logic
import { taskTemplateService, type TaskTemplate } from '@/services/task-template.service';

const templates = ref<TaskTemplate[]>([]);
const selectedTemplateId = ref('');

const templateOptions = computed(() => {
    return templates.value.map(t => ({
        value: t.public_id,
        label: t.name
    }));
});

const fetchTemplates = async () => {
    if (!selectedTeamId.value) return;
    try {
        const data = await taskTemplateService.getAll(selectedTeamId.value);
        templates.value = data;
    } catch (error) {
        console.error('Failed to fetch templates', error);
    }
};

watch(() => selectedTeamId.value, (newVal) => {
    if (newVal) {
        fetchTemplates();
    } else {
        templates.value = [];
    }
});

// Apply template
watch(() => selectedTemplateId.value, (newVal) => {
    const template = templates.value.find(t => t.public_id === newVal);
    if (template) {
        setValues({
            ...formValues.value, // Keep existing values if compatible? No, overwrite supported fields.
            // But we might want to keep status/due_date/assignee if previously set.
            // Let's overwrite fields defined in template.
            title: template.name, // Use template name as default title
            description: template.description || formValues.value.description,
            priority: template.default_priority === 'low' ? 1 
                    : template.default_priority === 'medium' ? 2 
                    : template.default_priority === 'high' ? 3 
                    : template.default_priority === 'urgent' ? 4 : 2,
            estimated_hours: template.default_estimated_hours || 0,
        });

        // Also update local formValues for v-model binding
        formValues.value.title = template.name;
        if (template.description) formValues.value.description = template.description;
        if (template.default_priority) {
            formValues.value.priority = template.default_priority === 'low' ? 1 
                                      : template.default_priority === 'medium' ? 2 
                                      : template.default_priority === 'high' ? 3 
                                      : template.default_priority === 'urgent' ? 4 : 2;
        }
        if (template.default_estimated_hours) formValues.value.estimated_hours = template.default_estimated_hours;
        
        toast.success("Template loaded");
    }
});

// Initialize modal state when opened
const initializeModal = async () => {
    // Set team and project from props synchronously
    if (props.teamId) {
        selectedTeamId.value = props.teamId;
    } else if (!selectedTeamId.value && authStore.user?.teams?.length === 1) {
        // Auto-select if user has only one team
        selectedTeamId.value = authStore.user.teams[0].public_id;
    }

    if (props.projectId) {
        selectedProjectId.value = props.projectId;
    }

    // Fetch projects list if we have a team selected but no projects yet
    if (selectedTeamId.value && projectOptions.value.length === 0) {
        await fetchProjects();
    }

    // Fetch templates if we have a team
    if (selectedTeamId.value && templates.value.length === 0) {
        await fetchTemplates();
    }

    // Fetch members if we have both IDs and no members provided via props
    if (selectedTeamId.value && selectedProjectId.value &&
        (!props.projectMembers || props.projectMembers.length === 0)) {
        await fetchMembers();
    }
};

watch(() => props.open, async (isOpenVal) => {
    if (isOpenVal) {
        await nextTick();
        await initializeModal();
    }
}, { immediate: true });

watch(() => props.task, (newTask) => {
    if (newTask) {
        // Extract status and priority values - they can be objects or strings
        const statusValue = typeof newTask.status === 'object' ? newTask.status?.value : newTask.status;
        const priorityValue = typeof newTask.priority === 'object' ? newTask.priority?.value : newTask.priority;
        
        setValues({
            title: newTask.title,
            description: newTask.description,
            status: statusValue || 'open',
            priority: priorityValue || 2,
            due_date: newTask.due_date ? new Date(newTask.due_date).toISOString() : '',
            assigned_to: newTask.assigned_to?.public_id || '',
            estimated_hours: Number(newTask.estimated_hours) || 0,
        });
        
        formValues.value = {
            title: newTask.title,
            description: newTask.description || '',
            status: statusValue || 'open',
            priority: priorityValue || 2,
            due_date: newTask.due_date ? new Date(newTask.due_date).toISOString().split('T')[0] : '',
            assigned_to: newTask.assigned_to?.public_id || '',
            estimated_hours: Number(newTask.estimated_hours) || 0,
        };
    } else {
        resetForm();
        formValues.value = {
            title: '',
            description: '',
            status: 'open',
            priority: 2,
            due_date: '',
            assigned_to: '',
            estimated_hours: 0,
        };
    }
}, { immediate: true });

const onSubmit = async () => {
    // Manual validation
    if (!formValues.value.title) {
        toast.error('Title is required');
        return;
    }

    try {
        isLoading.value = true;
        
        const payload = {
            ...formValues.value,
        };

        // Resolve team and project IDs with fallbacks
        // Task from API has nested project.team_id and project.id
        const teamId = selectedTeamId.value || props.teamId || props.task?.project?.team_id || props.task?.project?.team?.public_id || '';
        const projectId = selectedProjectId.value || props.projectId || props.task?.project?.id || props.task?.project?.public_id || props.task?.project_id || '';

        if (!teamId || !projectId) {
            toast.error('Please select a team and project');
            isLoading.value = false;
            return;
        }

        if (isEditing.value && props.task) {
            const taskId = props.task.public_id || props.task.id;
            const response = await axios.put(`/api/teams/${teamId}/projects/${projectId}/tasks/${taskId}`, payload);
            emit('task-saved', response.data.data);
            toast.success('Task updated successfully');
        } else {
            const response = await axios.post(`/api/teams/${teamId}/projects/${projectId}/tasks`, payload);
            emit('task-saved', response.data.data);
            toast.success('Task created successfully');
        }
        
        isOpen.value = false;
    } catch (err: any) {
        console.error('Failed to save task', err);
        toast.error(err.response?.data?.message || 'Failed to save task');
    } finally {
        isLoading.value = false;
    }
};

</script>

<template>
    <Modal v-model:open="isOpen" :title="isEditing ? 'Edit Task' : 'Create New Task'" size="md">
        <template #default>
            <form id="task-form" @submit.prevent="onSubmit" class="space-y-4 py-2">
                <!-- Template Selector -->
                <div v-if="!isEditing && templates.length > 0" class="p-3 bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-subtle)] mb-4">
                    <label class="block text-xs font-medium text-[var(--text-secondary)] mb-1">Load from Template</label>
                    <SelectFilter
                        v-model="selectedTemplateId"
                        :options="templateOptions"
                        placeholder="Select a template to auto-fill..."
                        class="w-full"
                    />
                </div>

                <div class="space-y-2">
                    <label class="block text-sm font-medium text-[var(--text-primary)]">Title <span class="text-red-500">*</span></label>
                    <Input v-model="formValues.title" placeholder="Task title" required />
                </div>
                
                <!-- Project Selector (if not provided via props) -->
                <div v-if="!props.projectId && !isEditing" class="grid grid-cols-2 gap-4">
                     <div class="space-y-2">
                        <label class="block text-sm font-medium text-[var(--text-primary)]">Team <span class="text-red-500">*</span></label>
                        <SelectFilter 
                            v-model="selectedTeamId" 
                            :options="teamOptions" 
                            placeholder="Select Team"
                        />
                    </div>
                     <div class="space-y-2">
                        <label class="block text-sm font-medium text-[var(--text-primary)]">Project <span class="text-red-500">*</span></label>
                        <SelectFilter 
                            v-model="selectedProjectId" 
                            :options="projectOptions" 
                            placeholder="Select Project"
                            :disabled="!selectedTeamId"
                        />
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="block text-sm font-medium text-[var(--text-primary)]">Description</label>
                    <Textarea v-model="formValues.description" placeholder="Describe the task..." rows="3" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-[var(--text-primary)]">Status</label>
                        <SelectFilter 
                            v-model="formValues.status" 
                            :options="statusOptions" 
                            placeholder="Select status"
                        />
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-[var(--text-primary)]">Priority</label>
                        <SelectFilter 
                            v-model="formValues.priority" 
                            :options="priorityOptions" 
                            placeholder="Select priority"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-[var(--text-primary)]">Assignee</label>
                        <div v-if="isFetchingMembers" class="h-9 flex items-center justify-center bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-default)]">
                            <span class="text-xs text-[var(--text-muted)]">Loading members...</span>
                        </div>
                        <SelectFilter
                            v-else
                            v-model="formValues.assigned_to"
                            :options="memberOptions"
                            :placeholder="memberOptions.length === 0 ? 'No members available' : 'Unassigned'"
                            :disabled="memberOptions.length === 0"
                        />
                        <p v-if="!isFetchingMembers && memberOptions.length === 0 && selectedProjectId" class="text-xs text-[var(--text-muted)]">
                            Select a project with members to assign
                        </p>
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-[var(--text-primary)]">Due Date</label>
                        <Input type="date" v-model="formValues.due_date" />
                    </div>
                </div>

                <div class="space-y-2">
                     <label class="block text-sm font-medium text-[var(--text-primary)]">Estimated Hours</label>
                     <Input type="number" step="0.5" v-model="formValues.estimated_hours" placeholder="e.g. 2.5" />
                </div>
            </form>
        </template>
        
        <template #footer>
            <Button variant="outline" @click="isOpen = false">Cancel</Button>
            <Button :loading="isLoading" @click="onSubmit">
                {{ isEditing ? 'Save Changes' : 'Create Task' }}
            </Button>
        </template>
    </Modal>
</template>
