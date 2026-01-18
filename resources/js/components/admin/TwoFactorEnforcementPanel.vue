<script setup>
import { ref, computed, onMounted } from 'vue';
import { Button, Card, Modal, Badge, Alert, Checkbox } from '@/components/ui';
import api from '@/lib/api';
import { toast } from 'vue-sonner';
import {
    Shield,
    ShieldCheck,
    ShieldOff,
    Smartphone,
    Key,
    Mail,
    AlertTriangle,
    Info,
} from 'lucide-vue-next';

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['updated']);

// 2FA methods configuration
const twoFactorMethods = [
    { value: 'totp', label: 'Authenticator App', icon: Smartphone, description: 'Time-based one-time password apps like Google Authenticator' },
    { value: 'sms', label: 'SMS Verification', icon: Mail, description: 'One-time codes sent via text message' },
    { value: 'webauthn', label: 'Security Key', icon: Key, description: 'Hardware security keys or passkeys' },
];

// State
const showEnforceModal = ref(false);
const selectedMethods = ref([]);
const isEnforcing = ref(false);
const isRemoving = ref(false);

// Computed
const isEnforced = computed(() => props.user.two_factor_enforced);

const allowedMethods = computed(() => {
    if (!props.user.two_factor_allowed_methods) return [];
    return Array.isArray(props.user.two_factor_allowed_methods)
        ? props.user.two_factor_allowed_methods
        : [];
});

const has2FAConfigured = computed(() => {
    // Check if user has any 2FA method configured
    return props.user.two_factor_confirmed_at ||
           props.user.phone_verified_at ||
           (props.user.webauthn_credentials && props.user.webauthn_credentials.length > 0);
});

const enforcementStatus = computed(() => {
    if (!isEnforced.value) return null;
    return {
        enforced: true,
        methods: allowedMethods.value,
        configured: has2FAConfigured.value,
    };
});

// Methods
function openEnforceModal() {
    selectedMethods.value = allowedMethods.value.length > 0
        ? [...allowedMethods.value]
        : ['totp']; // Default to TOTP
    showEnforceModal.value = true;
}

function toggleMethod(method) {
    const index = selectedMethods.value.indexOf(method);
    if (index === -1) {
        selectedMethods.value.push(method);
    } else if (selectedMethods.value.length > 1) {
        // Don't allow removing the last method
        selectedMethods.value.splice(index, 1);
    }
}

async function enforceForUser() {
    if (selectedMethods.value.length === 0) {
        toast.error('Please select at least one 2FA method');
        return;
    }

    isEnforcing.value = true;
    try {
        await api.post('/api/admin/2fa-enforcement', {
            target_type: 'user',
            target_id: props.user.public_id,
            allowed_methods: selectedMethods.value,
            enforce: true,
        });

        toast.success('2FA enforcement applied');
        showEnforceModal.value = false;
        emit('updated');
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to enforce 2FA');
    } finally {
        isEnforcing.value = false;
    }
}

async function removeEnforcement() {
    if (!confirm('Are you sure you want to remove 2FA enforcement for this user?')) {
        return;
    }

    isRemoving.value = true;
    try {
        await api.post('/api/admin/2fa-enforcement', {
            target_type: 'user',
            target_id: props.user.public_id,
            enforce: false,
        });
        toast.success('2FA enforcement removed');
        emit('updated');
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to remove enforcement');
    } finally {
        isRemoving.value = false;
    }
}


</script>

<template>
    <div class="space-y-4">
        <!-- User 2FA Enforcement Status -->
        <Card padding="md">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-[var(--text-muted)] mb-1">Two-Factor Authentication</p>
                    <div class="flex items-center gap-2">
                        <Badge :variant="isEnforced ? 'warning' : (has2FAConfigured ? 'success' : 'secondary')">
                            <component
                                :is="isEnforced ? ShieldCheck : (has2FAConfigured ? Shield : ShieldOff)"
                                class="w-3 h-3 mr-1"
                            />
                            {{ isEnforced ? 'Enforced' : (has2FAConfigured ? 'Enabled' : 'Not Configured') }}
                        </Badge>
                    </div>

                    <!-- Show enforced methods -->
                    <div v-if="isEnforced && allowedMethods.length > 0" class="mt-2">
                        <p class="text-xs text-[var(--text-muted)] mb-1">Allowed methods:</p>
                        <div class="flex flex-wrap gap-1">
                            <Badge
                                v-for="method in allowedMethods"
                                :key="method"
                                variant="outline"
                                size="sm"
                            >
                                {{ getMethodLabel(method) }}
                            </Badge>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Button
                        v-if="isEnforced"
                        variant="ghost"
                        size="sm"
                        :loading="isRemoving"
                        @click="removeEnforcement"
                    >
                        <ShieldOff class="w-4 h-4" />
                        Remove
                    </Button>
                    <Button variant="outline" size="sm" @click="openEnforceModal">
                        <ShieldCheck class="w-4 h-4" />
                        {{ isEnforced ? 'Update' : 'Enforce 2FA' }}
                    </Button>
                </div>
            </div>
        </Card>



        <!-- Enforce 2FA Modal for User -->
        <Modal
            :open="showEnforceModal"
            @update:open="showEnforceModal = $event"
            title="Enforce Two-Factor Authentication"
        >
            <div class="space-y-4">
                <Alert variant="info">
                    <span>The user will be required to set up 2FA using one of the selected methods before they can access the application.</span>
                </Alert>

                <div>
                    <label class="block text-sm font-medium text-[var(--text-primary)] mb-3">
                        Allowed 2FA Methods
                    </label>
                    <div class="space-y-2">
                        <div
                            v-for="method in twoFactorMethods"
                            :key="method.value"
                            class="flex items-start gap-3 p-3 rounded-lg border border-[var(--border-primary)] cursor-pointer hover:bg-[var(--surface-secondary)] transition-colors"
                            :class="{ 'bg-[var(--surface-secondary)] border-[var(--interactive-primary)]': selectedMethods.includes(method.value) }"
                            @click="toggleMethod(method.value)"
                        >
                            <Checkbox
                                :model-value="selectedMethods.includes(method.value)"
                                @update:model-value="toggleMethod(method.value)"
                            />
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <component :is="method.icon" class="w-4 h-4 text-[var(--text-secondary)]" />
                                    <span class="font-medium text-[var(--text-primary)]">{{ method.label }}</span>
                                </div>
                                <p class="text-xs text-[var(--text-muted)] mt-1">{{ method.description }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <Alert v-if="!has2FAConfigured" variant="warning">
                    <span>This user hasn't configured any 2FA method yet. They will be prompted to set one up immediately.</span>
                </Alert>

                <div class="flex gap-3 pt-2">
                    <Button variant="outline" class="flex-1" @click="showEnforceModal = false">
                        Cancel
                    </Button>
                    <Button
                        class="flex-1"
                        :loading="isEnforcing"
                        @click="enforceForUser"
                    >
                        Enforce 2FA
                    </Button>
                </div>
            </div>
        </Modal>


    </div>
</template>
