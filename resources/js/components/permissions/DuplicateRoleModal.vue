<script setup>
import { ref, watch, computed } from 'vue';
import { usePermissions, useRoles, useRoleChangeRequests } from '@/composables/usePermissions.ts';
import { Modal, Input, Button, Alert } from '@/components/ui';
import PermissionSelector from './PermissionSelector.vue';

const props = defineProps({
    open: Boolean,
    sourceRole: Object,
});

const emit = defineEmits(['update:open', 'saved']);

const { createRole, loading: createLoading } = useRoles();
const { fetchPermissions, loading: permsLoading } = usePermissions();
const { createRequest, loading: requestLoading } = useRoleChangeRequests();

const form = ref({
    name: '',
    permissions: [],
    reason: '',
});

const allPermissions = ref([]);
const requiresApproval = ref(false);
const approvalType = ref(null);
const error = ref(null);
const searchQuery = ref('');

const loading = computed(() => createLoading.value || requestLoading.value || permsLoading.value);

watch(() => props.open, async (isOpen) => {
    if (isOpen) {
        // Reset state
        form.value.name = '';
        form.value.permissions = props.sourceRole ? [...(props.sourceRole.permissions || [])] : [];
        form.value.reason = '';
        requiresApproval.value = false;
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

    if (!form.value.name) {
        error.value = 'Role name is required';
        return;
    }

    // Prepare payload
    const payload = {
        name: form.value.name,
        permissions: form.value.permissions,
    };

    if (requiresApproval.value) {
        // Submit as request
        if (form.value.reason.length < 20) {
            error.value = 'Reason must be at least 20 characters.';
            return;
        }

        try {
            await createRequest({
                type: 'role_create',
                role_data: payload,
                reason: form.value.reason,
            });
            emit('saved');
            handleClose();
        } catch (e) {
            // Error handled by composable toast usually
        }
    } else {
        // Try direct creation
        try {
            const result = await createRole(payload);
            
            if (result && result.requiresApproval) {
                requiresApproval.value = true;
                approvalType.value = result.type;
                error.value = 'This action requires administrative approval. Please provide a reason.';
            } else {
                emit('saved');
                handleClose();
            }
        } catch (e) {
            // Handled by composable or generic catch
        }
    }
}

function handleClose() {
    emit('update:open', false);
}
</script>

<template>
    <Modal
        :open="open"
        title="Duplicate Role"
        :description="requiresApproval ? 'Approval Required' : `Create a copy of ${sourceRole?.label || 'role'}`"
        @update:open="handleClose"
        size="5xl"
    >
        <div class="space-y-6 max-h-[70vh] overflow-y-auto pr-2 custom-scrollbar">
            <Alert v-if="error" variant="danger" title="Error">
                {{ error }}
            </Alert>
            
            <Alert v-if="requiresApproval" variant="warning" title="Approval Required">
                Creating this role requires administrator approval. Please provide a reason.
            </Alert>

            <div class="space-y-2">
                <label class="text-sm font-medium text-[var(--text-primary)]">New Role Name <span class="text-[var(--color-error-fg)]">*</span></label>
                <Input
                    v-model="form.name"
                    placeholder="Enter new role name"
                    :disabled="loading"
                />
            </div>

            <div v-if="requiresApproval" class="space-y-2">
                <label class="text-sm font-medium text-[var(--text-primary)]">Reason for Creation <span class="text-[var(--color-error-fg)]">*</span></label>
                <textarea
                    v-model="form.reason"
                    rows="3"
                    placeholder="Explain why this new role is needed (min 20 characters)..."
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
                {{ requiresApproval ? 'Submit Request' : 'Duplicate Role' }}
            </Button>
        </template>
    </Modal>
</template>
