<script setup>
import { ref, watch, onMounted } from 'vue';
import { Button } from '@/components/ui';
import api from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import { SelectFilter } from '@/components/ui';

const props = defineProps({
    open: Boolean,
    client: {
        type: Object,
        default: null
    },
    teamId: {
        type: [String, Number],
        default: null
    }
});

const emit = defineEmits(['close', 'saved']);

const authStore = useAuthStore();
const isSubmitting = ref(false);
const errors = ref({});

// Admin Team Selection
const availableTeams = ref([]);
const selectedTeamId = ref(null);
const isLoadingTeams = ref(false);

const formData = ref({
    name: '',
    email: '',
    contact_person: '',
    phone: '',
    address: '',
    status: 'active',
});

const canManageAnyTeam = () => {
    return authStore.user?.roles?.some(r => r.name === 'administrator') || 
           authStore.user?.permissions?.some(p => p.name === 'clients.manage_any_team');
};

const hasMultipleTeams = () => {
    return (authStore.user?.teams?.length || 0) > 1;
};

const shouldShowTeamSelector = () => {
    if (props.teamId) return false;
    return canManageAnyTeam() || hasMultipleTeams();
};

const fetchTeams = async () => {
    if (!shouldShowTeamSelector()) return;
    
    if (canManageAnyTeam()) {
        isLoadingTeams.value = true;
        try {
            const response = await api.get('/api/teams?per_page=100'); // Simple fetch for now
            availableTeams.value = response.data.data.map(t => ({
                label: t.name,
                value: t.public_id // Use public_id
            }));
        } catch (e) {
            console.error('Failed to fetch teams', e);
        } finally {
            isLoadingTeams.value = false;
        }
    } else {
        // Use user's teams from auth store
        availableTeams.value = (authStore.user?.teams || []).map(t => ({
             label: t.name,
             value: t.public_id
        }));
        
        // Auto-select if no selection (e.g. first team)
        if (!selectedTeamId.value && availableTeams.value.length > 0) {
            // Don't auto-select here, let user choose, but we could default to currentTeamId
             selectedTeamId.value = authStore.currentTeamId;
        }
    }
};

// Initialize
onMounted(() => {
    if (shouldShowTeamSelector()) {
        fetchTeams();
    }
});

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        if (props.client) {
            // Edit Mode
            formData.value = {
                name: props.client.name,
                email: props.client.email || '',
                contact_person: props.client.contact_person || '',
                phone: props.client.phone || '',
                address: props.client.address || '',
                status: props.client.status,
            };
            selectedTeamId.value = props.client.team_id;
        } else {
            // Create Mode
            formData.value = {
                name: '',
                email: '',
                contact_person: '',
                phone: '',
                address: '',
                status: 'active',
            };
            selectedTeamId.value = props.teamId || authStore.currentTeamId; // Default to prop or current team
        }
        errors.value = {};
    }
});

const save = async () => {
    isSubmitting.value = true;
    errors.value = {};
    
    const data = { ...formData.value };
    
    // Append team_id if selection is active
    if (shouldShowTeamSelector()) {
        data.team_id = props.teamId || selectedTeamId.value;
    } else if (props.teamId) {
        data.team_id = props.teamId;
    } else if (!canManageAnyTeam() && authStore.currentTeamId) {
         // Default to current team if not selecting
         data.team_id = authStore.currentTeamId;
    }

    try {
        if (props.client) {
            await api.put(`/api/clients/${props.client.public_id}`, data);
        } else {
            await api.post('/api/clients', data);
        }
        emit('saved');
        emit('close');
    } catch (error) {
        if (error.response?.data?.errors) {
            errors.value = error.response.data.errors;
        }
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @click.self="$emit('close')">
        <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] shadow-xl w-full max-w-lg overflow-hidden">
            <div class="p-6 border-b border-[var(--border-muted)]">
                <h3 class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ client ? 'Edit Client' : 'Add New Client' }}
                </h3>
            </div>
            
            <div class="p-6 space-y-4">
                <!-- Team Selector (Admin or Multi-Team, when not pre-selected) -->
                <div v-if="shouldShowTeamSelector()" class="space-y-1">
                    <label class="text-sm font-medium text-[var(--text-secondary)]">Team</label>
                    <SelectFilter
                        v-model="selectedTeamId"
                        :options="availableTeams"
                        placeholder="Select Team"
                        class="w-full"
                    />
                    <p v-if="errors.team_id" class="text-xs text-[var(--color-error)]">{{ errors.team_id[0] }}</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-1 md:col-span-2">
                        <label class="text-sm font-medium text-[var(--text-secondary)]">Company Name</label>
                        <input v-model="formData.name" type="text" class="input">
                        <p v-if="errors.name" class="text-xs text-[var(--color-error)]">{{ errors.name[0] }}</p>
                    </div>
                    
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-[var(--text-secondary)]">Contact Person</label>
                        <input v-model="formData.contact_person" type="text" class="input">
                        <p v-if="errors.contact_person" class="text-xs text-[var(--color-error)]">{{ errors.contact_person[0] }}</p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-medium text-[var(--text-secondary)]">Email</label>
                        <input v-model="formData.email" type="email" class="input">
                        <p v-if="errors.email" class="text-xs text-[var(--color-error)]">{{ errors.email[0] }}</p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-medium text-[var(--text-secondary)]">Phone</label>
                        <input v-model="formData.phone" type="text" class="input">
                        <p v-if="errors.phone" class="text-xs text-[var(--color-error)]">{{ errors.phone[0] }}</p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-medium text-[var(--text-secondary)]">Status</label>
                        <select v-model="formData.status" class="input">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <p v-if="errors.status" class="text-xs text-[var(--color-error)]">{{ errors.status[0] }}</p>
                    </div>

                     <div class="space-y-1 md:col-span-2">
                        <label class="text-sm font-medium text-[var(--text-secondary)]">Address</label>
                        <textarea v-model="formData.address" rows="2" class="input"></textarea>
                        <p v-if="errors.address" class="text-xs text-[var(--color-error)]">{{ errors.address[0] }}</p>
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 bg-[var(--surface-secondary)] flex justify-end gap-3">
                <button @click="$emit('close')" class="btn btn-ghost">Cancel</button>
                <Button :loading="isSubmitting" @click="save">
                    {{ client ? 'Save Changes' : 'Create Client' }}
                </Button>
            </div>
        </div>
    </div>
</template>
