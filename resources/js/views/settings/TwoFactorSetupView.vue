<script setup>
import { ref, computed, onMounted } from 'vue';
import { Button, Card, Input, Switch, Badge, Modal, PinInput } from '@/components/ui';
import { useAuthStore } from '@/stores/auth';
import api from '@/lib/api';
import { toast } from 'vue-sonner';
import {
    Smartphone,
    Shield,
    Mail,
    MessageSquare,
    Key,
    Check,
    Copy,
    Download,
    RefreshCw,
    AlertTriangle,
    QrCode,
    Lock,
} from 'lucide-vue-next';

const authStore = useAuthStore();

// State
const isLoading = ref(false);
const showQRCode = ref(false);
const showRecoveryCodes = ref(false);
const showConfirmDisable = ref(false);
const showPasswordConfirm = ref(false);
const pendingAction = ref(null); // 'enable-totp', 'enable-sms', 'enable-email', 'disable'

const qrCodeSvg = ref('');
const secretKey = ref('');
const recoveryCodes = ref([]);
const verificationCode = ref('');
const password = ref('');
const phoneNumber = ref('');

const currentMethod = ref(null); // 'totp', 'sms', 'email', null

// 2FA Methods configuration
const methods = ref([
    {
        id: 'totp',
        name: 'Authenticator App',
        description: 'Use an authenticator app like Google Authenticator, Authy, or 1Password',
        icon: Smartphone,
        recommended: true,
        enabled: false,
        priority: 1,
    },
    {
        id: 'sms',
        name: 'SMS Text Message',
        description: 'Receive a code via SMS to your phone number',
        icon: MessageSquare,
        recommended: false,
        enabled: false,
        priority: 2,
        requiresPhone: true,
    },
    {
        id: 'email',
        name: 'Email Code',
        description: 'Receive a code via email as a fallback option',
        icon: Mail,
        recommended: false,
        enabled: false,
        priority: 3,
        isFallback: true,
    },
]);

// Computed
const is2FAEnabled = computed(() => methods.value.some(m => m.enabled));
const enabledMethods = computed(() => methods.value.filter(m => m.enabled));
const primaryMethod = computed(() => enabledMethods.value.sort((a, b) => a.priority - b.priority)[0] || null);

// Fetch current 2FA status
async function fetchStatus() {
    isLoading.value = true;
    try {
        const response = await api.get('/api/user/two-factor-status');
        if (response.data) {
            currentMethod.value = response.data.method;
            methods.value.forEach(m => {
                m.enabled = response.data.enabled_methods?.includes(m.id) || false;
            });
            if (response.data.phone) {
                phoneNumber.value = response.data.phone;
            }
        }
    } catch (error) {
        console.error('Failed to fetch 2FA status:', error);
    } finally {
        isLoading.value = false;
    }
}

// Confirm password before enabling/disabling
function confirmPassword(action) {
    pendingAction.value = action;
    showPasswordConfirm.value = true;
}

async function handlePasswordConfirm() {
    if (!password.value) {
        toast.error('Please enter your password');
        return;
    }

    isLoading.value = true;
    try {
        // Verify password
        await api.post('/api/user/confirm-password', { password: password.value });

        showPasswordConfirm.value = false;
        password.value = '';

        // Execute pending action
        switch (pendingAction.value) {
            case 'enable-totp':
                await enableTOTP();
                break;
            case 'enable-sms':
                await enableSMS();
                break;
            case 'enable-email':
                await enableEmail();
                break;
            case 'disable':
                await disable2FA();
                break;
        }
    } catch (error) {
        toast.error('Incorrect password');
    } finally {
        isLoading.value = false;
        pendingAction.value = null;
    }
}

// Enable TOTP
async function enableTOTP() {
    isLoading.value = true;
    try {
        const response = await api.post('/api/user/two-factor-authentication');
        qrCodeSvg.value = response.data.qr_code;
        secretKey.value = response.data.secret;
        showQRCode.value = true;
    } catch (error) {
        toast.error('Failed to enable authenticator app');
    } finally {
        isLoading.value = false;
    }
}

// Confirm TOTP setup
async function confirmTOTP() {
    if (!verificationCode.value || verificationCode.value.length !== 6) {
        toast.error('Please enter a valid 6-digit code');
        return;
    }

    isLoading.value = true;
    try {
        const response = await api.post('/api/user/confirmed-two-factor-authentication', {
            code: verificationCode.value,
        });

        // Get recovery codes
        const codesResponse = await api.get('/api/user/two-factor-recovery-codes');
        recoveryCodes.value = codesResponse.data;

        showQRCode.value = false;
        showRecoveryCodes.value = true;
        verificationCode.value = '';

        // Update method status
        const totpMethod = methods.value.find(m => m.id === 'totp');
        if (totpMethod) totpMethod.enabled = true;
        currentMethod.value = 'totp';

        toast.success('Authenticator app enabled successfully');
    } catch (error) {
        toast.error('Invalid verification code');
    } finally {
        isLoading.value = false;
    }
}

// Enable SMS
async function enableSMS() {
    if (!phoneNumber.value) {
        toast.error('Please enter your phone number');
        return;
    }

    isLoading.value = true;
    try {
        await api.post('/api/user/two-factor-sms', {
            phone: phoneNumber.value,
        });

        // Send verification code
        await api.post('/api/user/two-factor-sms/verify/send');

        pendingAction.value = 'verify-sms';
        toast.success('Verification code sent to your phone');
    } catch (error) {
        toast.error('Failed to enable SMS verification');
    } finally {
        isLoading.value = false;
    }
}

// Confirm SMS setup
async function confirmSMS() {
    if (!verificationCode.value || verificationCode.value.length !== 6) {
        toast.error('Please enter a valid 6-digit code');
        return;
    }

    isLoading.value = true;
    try {
        await api.post('/api/user/two-factor-sms/verify', {
            code: verificationCode.value,
        });

        // Update method status
        const smsMethod = methods.value.find(m => m.id === 'sms');
        if (smsMethod) smsMethod.enabled = true;

        verificationCode.value = '';
        pendingAction.value = null;

        toast.success('SMS verification enabled successfully');
    } catch (error) {
        toast.error('Invalid verification code');
    } finally {
        isLoading.value = false;
    }
}

// Enable Email fallback
async function enableEmail() {
    isLoading.value = true;
    try {
        await api.post('/api/user/two-factor-email');

        // Update method status
        const emailMethod = methods.value.find(m => m.id === 'email');
        if (emailMethod) emailMethod.enabled = true;

        toast.success('Email fallback enabled successfully');
    } catch (error) {
        toast.error('Failed to enable email fallback');
    } finally {
        isLoading.value = false;
    }
}

// Disable 2FA
async function disable2FA() {
    isLoading.value = true;
    try {
        await api.delete('/api/user/two-factor-authentication');

        // Reset all methods
        methods.value.forEach(m => m.enabled = false);
        currentMethod.value = null;
        showConfirmDisable.value = false;

        toast.success('Two-factor authentication disabled');
    } catch (error) {
        toast.error('Failed to disable two-factor authentication');
    } finally {
        isLoading.value = false;
    }
}

// Regenerate recovery codes
async function regenerateRecoveryCodes() {
    isLoading.value = true;
    try {
        const response = await api.post('/api/user/two-factor-recovery-codes');
        recoveryCodes.value = response.data;
        toast.success('Recovery codes regenerated');
    } catch (error) {
        toast.error('Failed to regenerate recovery codes');
    } finally {
        isLoading.value = false;
    }
}

// Copy to clipboard
function copyToClipboard(text) {
    navigator.clipboard.writeText(text);
    toast.success('Copied to clipboard');
}

// Download recovery codes
function downloadRecoveryCodes() {
    const content = recoveryCodes.value.join('\n');
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'coresync-recovery-codes.txt';
    a.click();
    URL.revokeObjectURL(url);
}

onMounted(() => {
    fetchStatus();
});
</script>

<template>
    <div class="space-y-6">
        <!-- Header -->
        <div>
            <h2 class="text-xl font-semibold text-[var(--text-primary)]">
                Two-Factor Authentication
            </h2>
            <p class="mt-1 text-sm text-[var(--text-secondary)]">
                Add an extra layer of security to your account by requiring a second form of verification.
            </p>
        </div>

        <!-- Status Card -->
        <Card class="p-6">
            <div class="flex items-start gap-4">
                <div
                    :class="[
                        'flex h-12 w-12 items-center justify-center rounded-xl',
                        is2FAEnabled
                            ? 'bg-green-100 dark:bg-green-900/30'
                            : 'bg-amber-100 dark:bg-amber-900/30'
                    ]"
                >
                    <Shield
                        :class="[
                            'h-6 w-6',
                            is2FAEnabled
                                ? 'text-green-600 dark:text-green-400'
                                : 'text-amber-600 dark:text-amber-400'
                        ]"
                    />
                </div>
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <h3 class="text-lg font-semibold text-[var(--text-primary)]">
                            {{ is2FAEnabled ? 'Two-Factor Authentication is Enabled' : 'Two-Factor Authentication is Disabled' }}
                        </h3>
                        <Badge :variant="is2FAEnabled ? 'success' : 'warning'">
                            {{ is2FAEnabled ? 'Active' : 'Inactive' }}
                        </Badge>
                    </div>
                    <p class="mt-1 text-sm text-[var(--text-secondary)]">
                        {{
                            is2FAEnabled
                                ? `Your account is protected with ${primaryMethod?.name}.`
                                : 'Your account is not protected with two-factor authentication.'
                        }}
                    </p>
                    <div v-if="is2FAEnabled && enabledMethods.length > 1" class="mt-2 flex flex-wrap gap-2">
                        <Badge v-for="method in enabledMethods" :key="method.id" variant="outline">
                            <component :is="method.icon" class="h-3 w-3 mr-1" />
                            {{ method.name }}
                        </Badge>
                    </div>
                </div>
            </div>
        </Card>

        <!-- Available Methods -->
        <div class="space-y-4">
            <h3 class="text-sm font-semibold text-[var(--text-primary)] uppercase tracking-wider">
                Available Methods
            </h3>

            <div class="space-y-3">
                <Card
                    v-for="method in methods"
                    :key="method.id"
                    :class="[
                        'p-4 transition-all',
                        method.enabled && 'ring-2 ring-[var(--color-primary-500)]'
                    ]"
                >
                    <div class="flex items-start gap-4">
                        <div
                            :class="[
                                'flex h-10 w-10 items-center justify-center rounded-lg',
                                method.enabled
                                    ? 'bg-[var(--color-primary-500)] text-white'
                                    : 'bg-[var(--surface-secondary)] text-[var(--text-secondary)]'
                            ]"
                        >
                            <component :is="method.icon" class="h-5 w-5" />
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h4 class="font-medium text-[var(--text-primary)]">
                                    {{ method.name }}
                                </h4>
                                <Badge v-if="method.recommended" variant="primary" size="sm">
                                    Recommended
                                </Badge>
                                <Badge v-if="method.isFallback" variant="outline" size="sm">
                                    Fallback
                                </Badge>
                                <Badge v-if="method.enabled" variant="success" size="sm">
                                    <Check class="h-3 w-3 mr-1" />
                                    Enabled
                                </Badge>
                            </div>
                            <p class="mt-0.5 text-sm text-[var(--text-secondary)]">
                                {{ method.description }}
                            </p>

                            <!-- Phone input for SMS -->
                            <div v-if="method.id === 'sms' && !method.enabled" class="mt-3 max-w-xs">
                                <Input
                                    v-model="phoneNumber"
                                    type="tel"
                                    placeholder="+1 (555) 123-4567"
                                    label="Phone Number"
                                    size="sm"
                                />
                            </div>
                        </div>

                        <Button
                            v-if="!method.enabled"
                            variant="outline"
                            size="sm"
                            @click="confirmPassword(`enable-${method.id}`)"
                        >
                            Enable
                        </Button>
                        <Button
                            v-else-if="method.id === currentMethod"
                            variant="ghost"
                            size="sm"
                            class="text-[var(--color-error)]"
                            @click="showConfirmDisable = true"
                        >
                            Disable
                        </Button>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Recovery Codes Section (when 2FA is enabled) -->
        <div v-if="is2FAEnabled" class="space-y-4">
            <h3 class="text-sm font-semibold text-[var(--text-primary)] uppercase tracking-wider">
                Recovery Options
            </h3>

            <Card class="p-4">
                <div class="flex items-start gap-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--surface-secondary)]">
                        <Key class="h-5 w-5 text-[var(--text-secondary)]" />
                    </div>
                    <div class="flex-1">
                        <h4 class="font-medium text-[var(--text-primary)]">Recovery Codes</h4>
                        <p class="mt-0.5 text-sm text-[var(--text-secondary)]">
                            Save these codes in a secure place. They can be used to access your account if you lose your device.
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="showRecoveryCodes = true; regenerateRecoveryCodes()"
                        >
                            <RefreshCw class="h-4 w-4 mr-1.5" />
                            View Codes
                        </Button>
                    </div>
                </div>
            </Card>
        </div>

        <!-- QR Code Modal -->
        <Modal
            :open="showQRCode"
            @update:open="showQRCode = $event"
            title="Set Up Authenticator App"
        >
            <div class="space-y-6 py-4">
                <div class="text-center space-y-4">
                    <p class="text-sm text-[var(--text-secondary)]">
                        Scan this QR code with your authenticator app, then enter the 6-digit code below.
                    </p>

                    <!-- QR Code -->
                    <div
                        class="mx-auto w-48 h-48 bg-white rounded-xl p-4 flex items-center justify-center"
                        v-html="qrCodeSvg"
                    />

                    <!-- Secret Key -->
                    <div class="bg-[var(--surface-secondary)] rounded-lg p-3">
                        <p class="text-xs text-[var(--text-muted)] mb-1">
                            Or enter this code manually:
                        </p>
                        <div class="flex items-center justify-center gap-2">
                            <code class="text-sm font-mono text-[var(--text-primary)]">
                                {{ secretKey }}
                            </code>
                            <button
                                class="p-1 hover:bg-[var(--surface-tertiary)] rounded transition-colors"
                                @click="copyToClipboard(secretKey)"
                            >
                                <Copy class="h-4 w-4 text-[var(--text-secondary)]" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Verification Code Input -->
                <div class="space-y-2">
                    <label class="text-sm font-medium text-[var(--text-primary)]">
                        Verification Code
                    </label>
                    <PinInput
                        v-model="verificationCode"
                        :length="6"
                        @complete="confirmTOTP"
                    />
                </div>

                <div class="flex gap-3">
                    <Button
                        variant="outline"
                        class="flex-1"
                        @click="showQRCode = false; verificationCode = ''"
                    >
                        Cancel
                    </Button>
                    <Button
                        class="flex-1"
                        :loading="isLoading"
                        @click="confirmTOTP"
                    >
                        Verify & Enable
                    </Button>
                </div>
            </div>
        </Modal>

        <!-- Recovery Codes Modal -->
        <Modal
            :open="showRecoveryCodes"
            @update:open="showRecoveryCodes = $event"
            title="Recovery Codes"
        >
            <div class="space-y-6 py-4">
                <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                    <div class="flex gap-3">
                        <AlertTriangle class="h-5 w-5 text-amber-600 dark:text-amber-400 shrink-0" />
                        <div class="text-sm">
                            <p class="font-medium text-amber-800 dark:text-amber-200">
                                Save these codes somewhere safe
                            </p>
                            <p class="mt-1 text-amber-700 dark:text-amber-300">
                                Each code can only be used once. If you lose access to your authentication method, you can use one of these codes to sign in.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Recovery Codes Grid -->
                <div class="grid grid-cols-2 gap-2 bg-[var(--surface-secondary)] rounded-lg p-4">
                    <code
                        v-for="(code, index) in recoveryCodes"
                        :key="index"
                        class="text-sm font-mono text-center py-2 px-3 bg-[var(--surface-elevated)] rounded-lg text-[var(--text-primary)]"
                    >
                        {{ code }}
                    </code>
                </div>

                <div class="flex gap-3">
                    <Button
                        variant="outline"
                        class="flex-1"
                        @click="copyToClipboard(recoveryCodes.join('\n'))"
                    >
                        <Copy class="h-4 w-4 mr-1.5" />
                        Copy All
                    </Button>
                    <Button
                        variant="outline"
                        class="flex-1"
                        @click="downloadRecoveryCodes"
                    >
                        <Download class="h-4 w-4 mr-1.5" />
                        Download
                    </Button>
                </div>

                <Button
                    class="w-full"
                    @click="showRecoveryCodes = false"
                >
                    Done
                </Button>
            </div>
        </Modal>

        <!-- Password Confirmation Modal -->
        <Modal
            :open="showPasswordConfirm"
            @update:open="showPasswordConfirm = $event"
            title="Confirm Your Password"
        >
            <div class="space-y-6 py-4">
                <p class="text-sm text-[var(--text-secondary)]">
                    For your security, please confirm your password before making changes to two-factor authentication.
                </p>

                <Input
                    v-model="password"
                    type="password"
                    label="Current Password"
                    placeholder="Enter your password"
                    :icon="Lock"
                    @keyup.enter="handlePasswordConfirm"
                />

                <div class="flex gap-3">
                    <Button
                        variant="outline"
                        class="flex-1"
                        @click="showPasswordConfirm = false; password = ''"
                    >
                        Cancel
                    </Button>
                    <Button
                        class="flex-1"
                        :loading="isLoading"
                        @click="handlePasswordConfirm"
                    >
                        Confirm
                    </Button>
                </div>
            </div>
        </Modal>

        <!-- Disable Confirmation Modal -->
        <Modal
            :open="showConfirmDisable"
            @update:open="showConfirmDisable = $event"
            title="Disable Two-Factor Authentication?"
        >
            <div class="space-y-6 py-4">
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                    <div class="flex gap-3">
                        <AlertTriangle class="h-5 w-5 text-red-600 dark:text-red-400 shrink-0" />
                        <div class="text-sm">
                            <p class="font-medium text-red-800 dark:text-red-200">
                                This will reduce your account security
                            </p>
                            <p class="mt-1 text-red-700 dark:text-red-300">
                                Your account will only be protected by your password. We recommend keeping two-factor authentication enabled.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3">
                    <Button
                        variant="outline"
                        class="flex-1"
                        @click="showConfirmDisable = false"
                    >
                        Keep Enabled
                    </Button>
                    <Button
                        variant="danger"
                        class="flex-1"
                        @click="confirmPassword('disable')"
                    >
                        Disable 2FA
                    </Button>
                </div>
            </div>
        </Modal>
    </div>
</template>
