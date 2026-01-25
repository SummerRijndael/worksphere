<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { 
    Modal, Button, Input, Textarea, SelectFilter
} from '@/components/ui';
import { useForm } from 'vee-validate';
import { toTypedSchema } from '@vee-validate/zod';
import * as z from 'zod';
import axios from 'axios';
import { toast } from 'vue-sonner';
import { useAuthStore } from '@/stores/auth';

interface Props {
    open: boolean;
    project?: any; // If editing
}

const props = defineProps<Props>();
const emit = defineEmits(['update:open', 'saved']);

const authStore = useAuthStore();
const currentTeamId = computed(() => authStore.user?.teams?.length === 1 ? authStore.user.teams[0].public_id : (authStore.currentTeam?.public_id || formValues.value.team_id));
const isEditing = computed(() => !!props.project);
const isLoading = ref(false);
const clients = ref<any[]>([]);

const isOpen = computed({
    get: () => props.open,
    set: (val) => emit('update:open', val),
});

const schema = toTypedSchema(z.object({
    name: z.string().min(1, 'Name is required').max(255),
    description: z.string().optional(),
    team_id: z.string().optional(),
    client_id: z.string().nullable().optional(),
    status: z.string().min(1, 'Status is required'),
    priority: z.string().min(1, 'Priority is required'),
    start_date: z.string().optional(),
    due_date: z.string().optional(),
    budget: z.number().min(0).optional(),
}));

const { setValues, resetForm } = useForm({
    validationSchema: schema,
    initialValues: {
        status: 'active',
        priority: 'medium',
    }
});

const formValues = ref({
    name: '',
    description: '',
    team_id: '',
    client_id: '',
    status: 'active',
    priority: 'medium',
    start_date: '',
    due_date: '',
    budget: 0,
});

const statusOptions = [
    { value: 'draft', label: 'Draft' },
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On Hold' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'archived', label: 'Archived' },
];

const priorityOptions = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'urgent', label: 'Urgent' },
];

// Fetch clients
const fetchClients = async () => {
    try {
        const teamId = currentTeamId.value;
        if (!teamId) return;

        const response = await axios.get(`/api/teams/${teamId}/clients`);
        console.log('Fetched clients:', response.data.data);
        clients.value = response.data.data || [];
    } catch (e) {
        console.error('Failed to fetch clients', e);
    }
};

watch(() => currentTeamId.value, (newVal) => {
    if (newVal) {
        fetchClients();
    }
});

const fetchTeams = async () => {
    if ((authStore.user?.teams?.length ?? 0) > 0) return; // Already have teams
    try {
        await authStore.fetchUser(); // Refresh user to get latest teams
    } catch (e) {
        console.error('Failed to fetch teams', e);
    }
};

const clientOptions = computed(() => {
    return clients.value.map(c => ({ value: c.public_id, label: c.name }));
});

const teamOptions = computed(() => {
    return authStore.user?.teams?.map(t => ({ value: t.public_id, label: t.name })) || [];
});

watch(() => props.project, (newProject) => {
    if (newProject) {
        setValues({
            name: newProject.name,
            description: newProject.description,
            team_id: newProject.team_id,
            client_id: newProject.client?.public_id || '',
            status: newProject.status,
            priority: newProject.priority,
            start_date: newProject.start_date ? newProject.start_date.split('T')[0] : '',
            due_date: newProject.due_date ? newProject.due_date.split('T')[0] : '',
            budget: Number(newProject.budget) || 0,
        });
        formValues.value = {
            name: newProject.name,
            description: newProject.description || '',
            team_id: newProject.team_id,
            client_id: newProject.client?.id || newProject.client?.public_id || '',
            status: newProject.status?.value || newProject.status || 'active',
            priority: newProject.priority?.value || newProject.priority || 'medium',
            start_date: newProject.start_date ? newProject.start_date.split('T')[0] : '',
            due_date: newProject.due_date ? newProject.due_date.split('T')[0] : '',
            budget: Number(newProject.budget) || 0,
        };
    } else {
        resetForm();
        formValues.value = {
            name: '',
            description: '',
            team_id: authStore.user?.teams?.length === 1 ? authStore.user.teams[0].public_id : '',
            client_id: '',
            status: 'active',
            priority: 'medium',
            start_date: '',
            due_date: '',
            budget: 0,
        };
    }
}, { immediate: true });

watch(() => props.open, (val) => {
    if (val) {
        fetchClients();
        fetchTeams();
    }
}, { immediate: true });

onMounted(() => {
    fetchClients();
    fetchTeams();
});

const onSubmit = async () => {
    if (!currentTeamId.value) {
        toast.error('Please select a team.');
        return;
    }
    
    // Manual validation check since we are binding to formValues ref
    if (!formValues.value.name) {
        toast.error('Project name is required');
        return;
    }

    try {
        isLoading.value = true;
        
        const payload = {
            ...formValues.value,
        };

        if (isEditing.value && props.project) {
            const response = await axios.put(`/api/teams/${currentTeamId.value}/projects/${props.project.public_id}`, payload);
            emit('saved', response.data.data);
            toast.success('Project updated successfully');
        } else {
            const response = await axios.post(`/api/teams/${currentTeamId.value}/projects`, payload);
            emit('saved', response.data.data);
            toast.success('Project created successfully');
        }
        
        isOpen.value = false;
    } catch (err: any) {
        console.error('Failed to save project', err);
        toast.error(err.response?.data?.message || 'Failed to save project');
    } finally {
        isLoading.value = false;
    }
};
</script>

<template>
    <Modal v-model:open="isOpen" :title="isEditing ? 'Edit Project' : 'Create New Project'" size="lg">
        <template #default>
            <form id="project-form" @submit.prevent="onSubmit" class="space-y-4 py-2">
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-[var(--text-primary)]">Project Name <span class="text-red-500">*</span></label>
                    <Input v-model="formValues.name" placeholder="Enter project name" required />
                </div>

                <div class="space-y-2">
                    <label class="block text-sm font-medium text-[var(--text-primary)]">Description</label>
                    <Textarea v-model="formValues.description" placeholder="Describe the project..." rows="3" />
                </div>

                <div v-if="!isEditing && teamOptions.length > 0" class="space-y-2">
                    <label class="block text-sm font-medium text-[var(--text-primary)]">Team <span class="text-red-500">*</span></label>
                   <SelectFilter 
                        v-model="formValues.team_id" 
                        :options="teamOptions" 
                        placeholder="Select team"
                    />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-[var(--text-primary)]">Client</label>
                        <SelectFilter 
                            v-model="formValues.client_id" 
                            :options="clientOptions" 
                            placeholder="Select client"
                            searchable
                        />
                    </div>
                     <div class="space-y-2">
                        <label class="block text-sm font-medium text-[var(--text-primary)]">Budget</label>
                        <Input type="number" v-model="formValues.budget" placeholder="0.00" />
                    </div>
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
                        <label class="block text-sm font-medium text-[var(--text-primary)]">Start Date</label>
                        <Input type="date" v-model="formValues.start_date" />
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-[var(--text-primary)]">Due Date</label>
                        <Input type="date" v-model="formValues.due_date" />
                    </div>
                </div>
            </form>
        </template>
        
        <template #footer>
            <Button variant="outline" @click="isOpen = false">Cancel</Button>
            <Button :loading="isLoading" @click="onSubmit">
                {{ isEditing ? 'Save Changes' : 'Create Project' }}
            </Button>
        </template>
    </Modal>
</template>
