<script setup lang="ts">
import { ref, computed } from 'vue';
import { Card, Button, Input, PasswordStrengthMeter, Avatar } from '@/components/ui';
import { User, Lock, Eye, EyeOff, Shield, Mail, Send } from 'lucide-vue-next';
import { useAuthStore } from '@/stores/auth';
import api from '@/lib/api';
import { toast } from 'vue-sonner';

const authStore = useAuthStore();

const activeTab = ref('security');

const tabs = [
    { id: 'profile', label: 'Profile', icon: User },
    { id: 'security', label: 'Security', icon: Shield },
];

// Password change form
const passwordForm = ref({
    current_password: '',
    password: '',
    password_confirmation: '',
});
const showCurrentPassword = ref(false);
const showNewPassword = ref(false);
const isChangingPassword = ref(false);

// Info update request
const infoUpdateForm = ref({
    message: '',
    fields: [] as string[],
});
const isRequestingUpdate = ref(false);

const fieldOptions = [
    { value: 'name', label: 'Company/Contact Name' },
    { value: 'email', label: 'Email Address' },
    { value: 'phone', label: 'Phone Number' },
    { value: 'address', label: 'Billing Address' },
    { value: 'other', label: 'Other' },
];

const toggleField = (field: string) => {
    const index = infoUpdateForm.value.fields.indexOf(field);
    if (index === -1) {
        infoUpdateForm.value.fields.push(field);
    } else {
        infoUpdateForm.value.fields.splice(index, 1);
    }
};

const changePassword = async () => {
    if (!passwordForm.value.password || !passwordForm.value.password_confirmation) {
        toast.error('Please fill in all password fields');
        return;
    }

    if (passwordForm.value.password !== passwordForm.value.password_confirmation) {
        toast.error('Passwords do not match');
        return;
    }

    isChangingPassword.value = true;
    try {
        if (authStore.user?.is_password_set) {
            await api.put('/api/user/password', passwordForm.value);
            toast.success('Password updated successfully');
        } else {
            await api.post('/api/user/setup-password', {
                password: passwordForm.value.password,
                password_confirmation: passwordForm.value.password_confirmation,
            });
            toast.success('Password set successfully');
        }

        // Clear form
        passwordForm.value = {
            current_password: '',
            password: '',
            password_confirmation: '',
        };
    } catch (err: any) {
        const message = err.response?.data?.message || 'Failed to update password';
        toast.error(message);
    } finally {
        isChangingPassword.value = false;
    }
};

const requestInfoUpdate = async () => {
    if (!infoUpdateForm.value.message.trim()) {
        toast.error('Please describe the changes you need');
        return;
    }

    isRequestingUpdate.value = true;
    try {
        await api.post('/api/client-portal/request-info-update', {
            message: infoUpdateForm.value.message,
            fields: infoUpdateForm.value.fields,
        });
        toast.success('Update request submitted. Our team will contact you shortly.');

        // Clear form
        infoUpdateForm.value = {
            message: '',
            fields: [],
        };
    } catch (err: any) {
        toast.error(err.response?.data?.message || 'Failed to submit request');
    } finally {
        isRequestingUpdate.value = false;
    }
};

const canChangePassword = computed(() => {
    if (authStore.user?.is_password_set) {
        return (
            passwordForm.value.current_password &&
            passwordForm.value.password &&
            passwordForm.value.password_confirmation
        );
    }
    return (
        passwordForm.value.password &&
        passwordForm.value.password_confirmation
    );
});
</script>

<template>
    <div class="p-6 max-w-4xl mx-auto space-y-6">
        <!-- Header -->
        <div>
            <h1 class="text-2xl font-bold text-[var(--text-primary)]">Account Settings</h1>
            <p class="text-[var(--text-secondary)]">Manage your account security and preferences</p>
        </div>

        <!-- Profile Summary Card -->
        <Card padding="lg">
            <div class="flex items-center gap-4">
                <Avatar
                    :name="authStore.user?.name"
                    :src="authStore.avatarUrl"
                    size="lg"
                />
                <div class="flex-1">
                    <h2 class="font-semibold text-[var(--text-primary)]">{{ authStore.user?.name }}</h2>
                    <p class="text-sm text-[var(--text-muted)]">{{ authStore.user?.email }}</p>
                </div>
            </div>
        </Card>

        <!-- Tab Navigation -->
        <div class="flex gap-1 bg-[var(--surface-secondary)] p-1 rounded-lg w-fit">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                class="flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-all"
                :class="activeTab === tab.id
                    ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                    : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'"
                @click="activeTab = tab.id"
            >
                <component :is="tab.icon" class="w-4 h-4" />
                {{ tab.label }}
            </button>
        </div>

        <!-- Profile Tab -->
        <div v-if="activeTab === 'profile'" class="space-y-6">
            <!-- View Only Info -->
            <Card padding="lg">
                <h3 class="font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2">
                    <User class="w-5 h-5" />
                    Profile Information
                </h3>
                <p class="text-sm text-[var(--text-muted)] mb-6">
                    Your profile information is managed by our team. To request changes, please use the form below.
                </p>

                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-[var(--surface-secondary)] p-4 rounded-lg">
                        <dt class="text-xs font-semibold uppercase text-[var(--text-muted)] mb-1">Name</dt>
                        <dd class="text-[var(--text-primary)]">{{ authStore.user?.name }}</dd>
                    </div>
                    <div class="bg-[var(--surface-secondary)] p-4 rounded-lg">
                        <dt class="text-xs font-semibold uppercase text-[var(--text-muted)] mb-1">Email</dt>
                        <dd class="text-[var(--text-primary)]">{{ authStore.user?.email }}</dd>
                    </div>
                </dl>
            </Card>

            <!-- Request Info Update -->
            <Card padding="lg">
                <h3 class="font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2">
                    <Mail class="w-5 h-5" />
                    Request Information Update
                </h3>
                <p class="text-sm text-[var(--text-muted)] mb-6">
                    Need to update your contact information or billing details? Let us know what needs to be changed.
                </p>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-2">
                            What information needs updating?
                        </label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="option in fieldOptions"
                                :key="option.value"
                                type="button"
                                class="px-3 py-1.5 rounded-full text-sm border transition-all"
                                :class="infoUpdateForm.fields.includes(option.value)
                                    ? 'bg-[var(--interactive-primary)] text-white border-[var(--interactive-primary)]'
                                    : 'bg-transparent border-[var(--border-default)] text-[var(--text-secondary)] hover:border-[var(--interactive-primary)]'"
                                @click="toggleField(option.value)"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-2">
                            Describe the changes needed
                        </label>
                        <textarea
                            v-model="infoUpdateForm.message"
                            rows="4"
                            placeholder="Please describe the information you'd like to update..."
                            class="w-full px-3 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 focus:border-[var(--interactive-primary)] transition-all resize-none"
                        ></textarea>
                    </div>

                    <div>
                        <Button
                            :loading="isRequestingUpdate"
                            :disabled="!infoUpdateForm.message.trim()"
                            @click="requestInfoUpdate"
                        >
                            <Send class="w-4 h-4 mr-2" />
                            Submit Request
                        </Button>
                    </div>
                </div>
            </Card>
        </div>

        <!-- Security Tab -->
        <div v-if="activeTab === 'security'" class="space-y-6">
            <!-- Change Password -->
            <Card padding="lg">
                <h3 class="font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2">
                    <Lock class="w-5 h-5" />
                    {{ authStore.user?.is_password_set ? 'Change Password' : 'Set Password' }}
                </h3>
                <p class="text-sm text-[var(--text-muted)] mb-6">
                    {{ authStore.user?.is_password_set
                        ? 'Update your password to keep your account secure.'
                        : 'Set a password for your account to enable password login.'
                    }}
                </p>

                <div class="space-y-4 max-w-md">
                    <!-- Current Password (only if already set) -->
                    <div v-if="authStore.user?.is_password_set" class="space-y-2">
                        <label class="block text-sm font-medium text-[var(--text-primary)]">
                            Current Password
                        </label>
                        <div class="relative">
                            <Input
                                v-model="passwordForm.current_password"
                                :type="showCurrentPassword ? 'text' : 'password'"
                                placeholder="Enter current password"
                            />
                            <button
                                type="button"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] hover:text-[var(--text-secondary)]"
                                @click="showCurrentPassword = !showCurrentPassword"
                            >
                                <Eye v-if="!showCurrentPassword" class="w-4 h-4" />
                                <EyeOff v-else class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    <!-- New Password -->
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-[var(--text-primary)]">
                            New Password
                        </label>
                        <div class="relative">
                            <Input
                                v-model="passwordForm.password"
                                :type="showNewPassword ? 'text' : 'password'"
                                placeholder="Enter new password"
                            />
                            <button
                                type="button"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] hover:text-[var(--text-secondary)]"
                                @click="showNewPassword = !showNewPassword"
                            >
                                <Eye v-if="!showNewPassword" class="w-4 h-4" />
                                <EyeOff v-else class="w-4 h-4" />
                            </button>
                        </div>
                        <PasswordStrengthMeter :password="passwordForm.password" />
                    </div>

                    <!-- Confirm Password -->
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-[var(--text-primary)]">
                            Confirm New Password
                        </label>
                        <Input
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            placeholder="Confirm new password"
                        />
                        <p
                            v-if="passwordForm.password && passwordForm.password_confirmation && passwordForm.password !== passwordForm.password_confirmation"
                            class="text-xs text-[var(--color-error)]"
                        >
                            Passwords do not match
                        </p>
                    </div>

                    <div class="pt-2">
                        <Button
                            :loading="isChangingPassword"
                            :disabled="!canChangePassword"
                            @click="changePassword"
                        >
                            {{ authStore.user?.is_password_set ? 'Update Password' : 'Set Password' }}
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
    </div>
</template>
