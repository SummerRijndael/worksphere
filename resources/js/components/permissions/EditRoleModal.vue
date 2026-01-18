<script setup>
import { ref, watch, computed } from 'vue';
import { useRoles, useRoleChangeRequests, usePermissions } from '@/composables/usePermissions.ts';
import { Modal, Input, Button, Alert } from '@/components/ui';
import PermissionSelector from './PermissionSelector.vue';

const props = defineProps({
    open: Boolean,
    role: Object,
});

const emit = defineEmits(['update:open', 'saved']);

const { updateRole, loading: updateLoading } = useRoles();
const { createRequest, loading: requestLoading } = useRoleChangeRequests();
const { fetchPermissions, loading: permsLoading } = usePermissions();

const form = ref({
    name: '',
    permissions: [],
    reason: '',
});

const allPermissions = ref([]);
const requiresApproval = ref(false);
const approvalType = ref(null); // 'role_title_change' or 'role_permission_change'
const error = ref(null);
const searchQuery = ref('');

const loading = computed(() => updateLoading.value || requestLoading.value || permsLoading.value);

watch(() => props.open, async (isOpen) => {
    if (isOpen && props.role) {
        // Reset state
        form.value.name = props.role.label || props.role.name;
        form.value.permissions = [...(props.role.permissions || [])];
        form.value.reason = '';
        requiresApproval.value = false;
        approvalType.value = null;
        error.value = null;
        searchQuery.value = '';

        // Load permissions if needed
        if (allPermissions.value.length === 0) {
            allPermissions.value = await fetchPermissions();
        }
    }
});

async function handleSubmit() {
    error.value = null;

    if (requiresApproval.value) {
        // Submit as request
        if (form.value.reason.length < 20) {
            error.value = 'Reason must be at least 20 characters.';
            return;
        }

        try {
            const requestData = {
                type: approvalType.value,
                role_id: props.role.id,
                reason: form.value.reason,
            };

            if (approvalType.value === 'role_title_change') {
                requestData.new_title = form.value.name;
            } else if (approvalType.value === 'role_permission_change') {
                requestData.permissions = form.value.permissions;
            }

            await createRequest(requestData);
            emit('saved');
            handleClose();
        } catch (e) {
            // Error handled by composable toast usually
        }
    } else {
        // Try direct update
        try {
            // Check what changed to optimize payload
            const payload = {};
            const titleChanged = form.value.name !== (props.role.label || props.role.name);
            const permissionsChanged = JSON.stringify(form.value.permissions.sort()) !== JSON.stringify([...(props.role.permissions || [])].sort());

            if (titleChanged) payload.name = form.value.name;
            if (permissionsChanged) payload.permissions = form.value.permissions;

            if (Object.keys(payload).length === 0) {
                handleClose();
                return;
            }

             const result = await updateRole(props.role.id, payload);

            if (result && result.requiresApproval) {
                requiresApproval.value = true;
                approvalType.value = result.type;
                error.value = `This action (${result.type.replaceAll('_', ' ')}) requires administrative approval. Please provide a reason.`;
            } else {
                emit('saved');
                handleClose();
            }
        } catch (e) {
             if (e.response && e.response.status === 422 && e.response.data.requires_approval) {
                requiresApproval.value = true;
                approvalType.value = e.response.data.approval_type;
                error.value = e.response.data.message;
            } else {
                error.value = e.response?.data?.message || 'Failed to update role';
            }
        }
    }
}

function handleClose() {
    emit('update:open', false);
    requiresApproval.value = false;
    error.value = null;
    form.value.reason = '';
}
</script>

<template>
    <Modal
        :open="open"
        title="Edit Role"
        :description="requiresApproval ? 'Approval Required' : 'Update role details and permissions.'"
        @update:open="handleClose"
        size="5xl"
    >
        <div class="space-y-6 max-h-[70vh] overflow-y-auto pr-2 custom-scrollbar">
            <Alert v-if="error" variant="danger" title="Error">
                {{ error }}
            </Alert>
            
            <Alert v-if="requiresApproval" variant="warning" title="Approval Required">
                Changes to roles require administrator approval. Please provide a reason.
            </Alert>

            <div class="space-y-2">
                <label class="text-sm font-medium text-[var(--text-primary)]">Role Name</label>
                <Input
                    v-model="form.name"
                    placeholder="Enter role name"
                    :disabled="loading"
                />
            </div>

            <div v-if="requiresApproval" class="space-y-2">
                <label class="text-sm font-medium text-[var(--text-primary)]">Reason for Change <span class="text-[var(--color-error-fg)]">*</span></label>
                <textarea
                    v-model="form.reason"
                    rows="3"
                    placeholder="Explain why this change is needed (min 20 characters)..."
                    class="w-full px-3 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] resize-none"
                    :disabled="loading"
                ></textarea>
                <p class="text-xs text-[var(--text-muted)] text-right">
                    {{ form.reason.length }} / 20 characters
                </p>
            </div>

             <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium text-[var(--text-primary)]">Permissions</label>
                    <div class="w-64">
                         <Input
                            v-model="searchQuery"
                            placeholder="Filter permissions..."
                            class="h-8 text-xs"
                        />
                    </div>
                </div>
                
                <div class="border border-[var(--border-default)] rounded-xl p-4 bg-[var(--surface-primary)]">
                    <PermissionSelector
                        v-model="form.permissions"
                        :all-permissions="allPermissions"
                        :search-query="searchQuery"
                        :readonly="loading"
                    />
                </div>
            </div>
        </div>

        <template #footer>
            <Button variant="ghost" @click="handleClose" :disabled="loading">
                Cancel
            </Button>
            <Button
                variant="primary"
                @click="handleSubmit"
                :loading="loading"
                :disabled="loading || (requiresApproval && form.reason.length < 20)"
            >
                {{ requiresApproval ? 'Submit Request' : 'Save Changes' }}
            </Button>
        </template>
    </Modal>
</template>
