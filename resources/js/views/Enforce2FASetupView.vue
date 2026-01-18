<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRouter, onBeforeRouteLeave } from 'vue-router';
import { Button, Card, Input, Badge, Alert, Modal } from '@/components/ui';
import { useAuthStore } from '@/stores/auth';
import api from '@/lib/api';
import { toast } from 'vue-sonner';
import {
    Shield,
    Smartphone,
    Mail,
    MessageSquare,
    Key,
    Check,
    ArrowRight,
    ArrowLeft,
    Copy,
    Download,
    Lock,
    LogOut,

    Loader2,
} from 'lucide-vue-next';

const router = useRouter();
const authStore = useAuthStore();

// State
const isLoading = ref(true);
const isSubmitting = ref(false);
const enforcementData = ref(null);
const selectedMethod = ref(null);
const setupStep = ref('select'); // 'select', 'setup', 'verify', 'recovery', 'complete'

// TOTP state
const qrCodeSvg = ref('');
const secretKey = ref('');
const verificationCode = ref('');
const recoveryCodes = ref([]);

// SMS state
const phoneNumber = ref('');
const smsCode = ref('');
const smsSent = ref(false);
const smsCountdown = ref(0);
let smsCountdownInterval = null;

// Method configs
const methodsConfig = {
    totp: {
        id: 'totp',
        name: 'Authenticator App',
        description: 'Use Google Authenticator, Authy, 1Password, or similar apps',
        icon: Smartphone,
        recommended: true,
    },
    sms: {
        id: 'sms',
        name: 'SMS Text Message',
        description: 'Receive verification codes via SMS',
        icon: MessageSquare,
    },
    email: {
        id: 'email',
        name: 'Email Code',
        description: 'Receive verification codes via email',
        icon: Mail,
    },
    passkey: {
        id: 'passkey',
        name: 'Passkey / Security Key',
        description: 'Use a hardware security key or device biometrics',
        icon: Key,
    },
};

const availableMethods = computed(() => {
    if (!enforcementData.value?.allowed_methods) return [];
    return enforcementData.value.allowed_methods
        .map(m => methodsConfig[m])
        .filter(Boolean);
});

const enforcementSource = computed(() => {
    if (!enforcementData.value) return '';
    if (enforcementData.value.source === 'role') {
        return `Required for ${enforcementData.value.role} role`;
    }
    return 'Required for your account';
});

// Prevent navigation away
onBeforeRouteLeave((to, from, next) => {
    console.log('Navigation guard checking:', to.name, to.path);
    if (to.name === 'login') {
        next();
        return;
    }

    if (setupStep.value !== 'complete' && enforcementData.value?.requires_setup) {
        toast.error('Please complete 2FA setup before continuing');
        next(false);
    } else {
        next();
    }
});

async function fetchEnforcementStatus() {
    isLoading.value = true;
    try {
        const response = await api.get('/api/user/2fa-enforcement-status');
        enforcementData.value = response.data;

        if (!response.data.requires_setup) {
            // User already has valid 2FA, redirect to dashboard
            router.push({ name: 'dashboard' });
        }
    } catch (error) {
        console.error('Failed to fetch 2FA enforcement status:', error);
        toast.error('Failed to load 2FA requirements');
    } finally {
        isLoading.value = false;
    }
}

function selectMethod(methodId) {
    selectedMethod.value = methodId;
    setupStep.value = 'setup';

    if (methodId === 'totp') {
        initTOTPSetup();
    } else if (methodId === 'email') {
        // Email 2FA is simpler - just enable it
        enableEmail2FA();
    }
}

async function initTOTPSetup() {
    isSubmitting.value = true;
    try {
        await api.get('/sanctum/csrf-cookie'); // Ensure CSRF token is fresh
        const response = await api.post('/api/user/two-factor-authentication');
        qrCodeSvg.value = response.data.qr_code;
        secretKey.value = response.data.secret;
        setupStep.value = 'verify';
    } catch (error) {
        console.error('TOTP Setup Error:', error);
        toast.error('Failed to initialize: ' + (error.response?.data?.message || error.message));
        setupStep.value = 'select';
    } finally {
        isSubmitting.value = false;
    }
}

const handleLogout = async () => {
    try {
        await api.get('/sanctum/csrf-cookie'); // Ensure CSRF token is fresh
        await authStore.logout();
        await router.push({ name: 'login' });
    } catch (e) {
        console.error('Logout navigation failed:', e);
        // Force redirect if router fails
        window.location.href = '/auth/login';
    }
};

async function verifyTOTP() {
    if (!verificationCode.value || verificationCode.value.length !== 6) {
        toast.error('Please enter a valid 6-digit code');
        return;
    }

    isSubmitting.value = true;
    try {
        await api.post('/api/user/confirmed-two-factor-authentication', {
            code: verificationCode.value,
        });

        // Fetch recovery codes
        const codesResponse = await api.get('/api/user/two-factor-recovery-codes');
        recoveryCodes.value = codesResponse.data;

        // Enable email as fallback
        try {
            await api.post('/api/user/two-factor-email');
        } catch (e) {
            // Ignore if already enabled
        }

        setupStep.value = 'recovery';
        toast.success('Authenticator configured successfully!');
    } catch (error) {
        toast.error(error.response?.data?.message || 'Invalid verification code');
    } finally {
        isSubmitting.value = false;
    }
}

async function sendSMSCode() {
    if (!phoneNumber.value) {
        toast.error('Please enter your phone number');
        return;
    }

    isSubmitting.value = true;
    try {
        await api.post('/api/user/two-factor-sms', { phone: phoneNumber.value });
        await api.post('/api/user/two-factor-sms/verify/send');
        smsSent.value = true;
        setupStep.value = 'verify';
        startSMSCountdown();
        toast.success('Verification code sent to your phone');
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to send verification code');
    } finally {
        isSubmitting.value = false;
    }
}

async function verifySMS() {
    if (!smsCode.value || smsCode.value.length !== 6) {
        toast.error('Please enter a valid 6-digit code');
        return;
    }

    isSubmitting.value = true;
    try {
        await api.post('/api/user/two-factor-sms/verify', { code: smsCode.value });

        // Enable email as fallback
        try {
            await api.post('/api/user/two-factor-email');
        } catch (e) {
            // Ignore if already enabled
        }

        setupStep.value = 'complete';
        toast.success('SMS verification configured successfully!');
    } catch (error) {
        toast.error(error.response?.data?.message || 'Invalid verification code');
    } finally {
        isSubmitting.value = false;
    }
}

async function enableEmail2FA() {
    isSubmitting.value = true;
    try {
        await api.post('/api/user/two-factor-email');
        setupStep.value = 'complete';
        toast.success('Email verification enabled successfully!');
    } catch (error) {
        toast.error('Failed to enable email verification');
        setupStep.value = 'select';
    } finally {
        isSubmitting.value = false;
    }
}

function startSMSCountdown() {
    smsCountdown.value = 60;
    smsCountdownInterval = setInterval(() => {
        smsCountdown.value--;
        if (smsCountdown.value <= 0) {
            clearInterval(smsCountdownInterval);
        }
    }, 1000);
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text);
    toast.success('Copied to clipboard');
}

function downloadRecoveryCodes() {
    const content = recoveryCodes.value.join('\n');
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'recovery-codes.txt';
    a.click();
    URL.revokeObjectURL(url);
}

function finishSetup() {
    setupStep.value = 'complete';
}

function goToDashboard() {
    router.push({ name: 'dashboard' });
}

function goBack() {
    if (setupStep.value === 'verify' || setupStep.value === 'setup') {
        setupStep.value = 'select';
        selectedMethod.value = null;
        verificationCode.value = '';
        smsCode.value = '';
        smsSent.value = false;
    }
}

onMounted(() => {
    fetchEnforcementStatus();
});

onBeforeUnmount(() => {
    if (smsCountdownInterval) {
        clearInterval(smsCountdownInterval);
    }
});
</script>

<template>
    <div class="min-h-screen bg-[var(--surface-primary)] flex items-center justify-center p-4">
        <div class="w-full max-w-xl">
            <!-- Header -->
            <div class="text-center mb-8 relative">
                <Button
                    variant="ghost"
                    size="sm"
                    class="absolute -top-12 right-0 text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                    @click="handleLogout"
                >
                    <LogOut class="w-4 h-4 mr-2" />
                    Logout
                </Button>
                <div class="mx-auto w-16 h-16 rounded-full bg-amber-500/10 flex items-center justify-center mb-4">
                    <Shield class="w-8 h-8 text-amber-500" />
                </div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)] mb-2">
                    Two-Factor Authentication Required
                </h1>
                <p class="text-[var(--text-secondary)]">
                    {{ enforcementSource }}
                </p>
            </div>

            <!-- Loading State -->
            <div v-if="isLoading" class="flex justify-center py-12">
                <Loader2 class="w-8 h-8 animate-spin text-[var(--interactive-primary)]" />
            </div>

            <!-- Method Selection -->
            <template v-else-if="setupStep === 'select'">
                <Alert variant="warning" class="mb-6">

                    <span>Please configure at least one authentication method to continue.</span>
                </Alert>

                <div class="space-y-3">
                    <Card
                        v-for="method in availableMethods"
                        :key="method.id"
                        class="cursor-pointer hover:ring-2 hover:ring-[var(--interactive-primary)] transition-all"
                        padding="md"
                        @click="selectMethod(method.id)"
                    >
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-[var(--surface-secondary)] flex items-center justify-center shrink-0">
                                <component :is="method.icon" class="w-6 h-6 text-[var(--text-secondary)]" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-semibold text-[var(--text-primary)]">{{ method.name }}</h3>
                                    <Badge v-if="method.recommended" variant="primary" size="sm">Recommended</Badge>
                                </div>
                                <p class="text-sm text-[var(--text-secondary)] truncate">{{ method.description }}</p>
                            </div>
                            <ArrowRight class="w-5 h-5 text-[var(--text-muted)] shrink-0" />
                        </div>
                    </Card>
                </div>
            </template>

            <!-- TOTP Setup Step -->
            <template v-else-if="selectedMethod === 'totp' && setupStep === 'setup'">
                <Card padding="lg" class="text-center">
                    <Loader2 class="w-8 h-8 animate-spin mx-auto text-[var(--interactive-primary)]" />
                    <p class="mt-4 text-[var(--text-secondary)]">Generating QR code...</p>
                </Card>
            </template>

            <!-- TOTP Verify Step -->
            <template v-else-if="selectedMethod === 'totp' && setupStep === 'verify'">
                <Card padding="lg">
                    <div class="space-y-6">
                        <div class="text-center">
                            <h3 class="font-semibold text-[var(--text-primary)] mb-2">
                                Scan QR Code
                            </h3>
                            <p class="text-sm text-[var(--text-secondary)]">
                                Use your authenticator app to scan this code
                            </p>
                        </div>

                        <div
                            class="mx-auto w-48 h-48 bg-white rounded-xl p-4 flex items-center justify-center"
                            v-html="qrCodeSvg"
                        />

                        <div class="bg-[var(--surface-secondary)] rounded-lg p-4 text-center border border-[var(--border-dim)]">
                            <p class="text-xs text-[var(--text-muted)] mb-2 uppercase tracking-wide font-medium">Manual Entry Key</p>
                            <div class="flex items-center justify-center gap-3">
                                <code class="text-base font-mono font-bold text-[var(--text-primary)] tracking-wider select-all">{{ secretKey }}</code>
                                <Button variant="ghost" size="icon-sm" class="shrink-0" @click="copyToClipboard(secretKey)">
                                    <Copy class="w-4 h-4" />
                                </Button>
                            </div>
                        </div>

                        <div>
                            <Input
                                v-model="verificationCode"
                                type="text"
                                inputmode="numeric"
                                maxlength="6"
                                placeholder="Enter 6-digit code"
                                class="text-center text-xl tracking-widest"
                                @keyup.enter="verifyTOTP"
                            />
                        </div>

                        <div class="flex gap-3">
                            <Button variant="outline" class="flex-1" @click="goBack">
                                <ArrowLeft class="w-4 h-4" />
                                Back
                            </Button>
                            <Button class="flex-1" :loading="isSubmitting" @click="verifyTOTP">
                                Verify & Enable
                            </Button>
                        </div>
                    </div>
                </Card>
            </template>

            <!-- SMS Setup Step -->
            <template v-else-if="selectedMethod === 'sms' && setupStep === 'setup'">
                <Card padding="lg">
                    <div class="space-y-6">
                        <div class="text-center">
                            <h3 class="font-semibold text-[var(--text-primary)] mb-2">
                                Enter Your Phone Number
                            </h3>
                            <p class="text-sm text-[var(--text-secondary)]">
                                We'll send a verification code to this number
                            </p>
                        </div>

                        <Input
                            v-model="phoneNumber"
                            type="tel"
                            placeholder="+1 (555) 123-4567"
                            :icon="MessageSquare"
                        />

                        <div class="flex gap-3">
                            <Button variant="outline" class="flex-1" @click="goBack">
                                <ArrowLeft class="w-4 h-4" />
                                Back
                            </Button>
                            <Button class="flex-1" :loading="isSubmitting" @click="sendSMSCode">
                                Send Code
                            </Button>
                        </div>
                    </div>
                </Card>
            </template>

            <!-- SMS Verify Step -->
            <template v-else-if="selectedMethod === 'sms' && setupStep === 'verify'">
                <Card padding="lg">
                    <div class="space-y-6">
                        <div class="text-center">
                            <h3 class="font-semibold text-[var(--text-primary)] mb-2">
                                Enter Verification Code
                            </h3>
                            <p class="text-sm text-[var(--text-secondary)]">
                                We sent a code to {{ phoneNumber }}
                            </p>
                        </div>

                        <Input
                            v-model="smsCode"
                            type="text"
                            inputmode="numeric"
                            maxlength="6"
                            placeholder="Enter 6-digit code"
                            class="text-center text-xl tracking-widest"
                            @keyup.enter="verifySMS"
                        />

                        <div class="text-center">
                            <Button
                                variant="link"
                                :disabled="smsCountdown > 0"
                                @click="sendSMSCode"
                            >
                                {{ smsCountdown > 0 ? `Resend in ${smsCountdown}s` : 'Resend code' }}
                            </Button>
                        </div>

                        <div class="flex gap-3">
                            <Button variant="outline" class="flex-1" @click="goBack">
                                <ArrowLeft class="w-4 h-4" />
                                Back
                            </Button>
                            <Button class="flex-1" :loading="isSubmitting" @click="verifySMS">
                                Verify
                            </Button>
                        </div>
                    </div>
                </Card>
            </template>

            <!-- Recovery Codes -->
            <template v-else-if="setupStep === 'recovery'">
                <Card padding="lg">
                    <div class="space-y-6">
                        <Alert variant="warning">
        
                            <div>
                                <strong>Save Your Recovery Codes</strong>
                                <p class="text-sm mt-1">Store these codes safely. You can use them if you lose access to your authenticator.</p>
                            </div>
                        </Alert>

                        <div class="grid grid-cols-2 gap-2 bg-[var(--surface-secondary)] rounded-lg p-4">
                            <code
                                v-for="(code, i) in recoveryCodes"
                                :key="i"
                                class="text-sm font-mono text-center py-2 bg-[var(--surface-elevated)] rounded"
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
                                <Copy class="w-4 h-4" />
                                Copy All
                            </Button>
                            <Button
                                variant="outline"
                                class="flex-1"
                                @click="downloadRecoveryCodes"
                            >
                                <Download class="w-4 h-4" />
                                Download
                            </Button>
                        </div>

                        <Button fullWidth @click="finishSetup">
                            <Check class="w-4 h-4" />
                            I've Saved My Codes - Continue
                        </Button>
                    </div>
                </Card>
            </template>

            <!-- Complete -->
            <template v-else-if="setupStep === 'complete'">
                <Card padding="lg" class="text-center">
                    <div class="space-y-6">
                        <div class="mx-auto w-16 h-16 rounded-full bg-green-500/10 flex items-center justify-center">
                            <Check class="w-8 h-8 text-green-500" />
                        </div>

                        <div>
                            <h3 class="text-xl font-semibold text-[var(--text-primary)] mb-2">
                                Two-Factor Authentication Enabled
                            </h3>
                            <p class="text-[var(--text-secondary)]">
                                Your account is now protected with an additional layer of security.
                            </p>
                        </div>

                        <Button fullWidth @click="goToDashboard">
                            Continue to Dashboard
                            <ArrowRight class="w-4 h-4" />
                        </Button>
                    </div>
                </Card>
            </template>
        </div>
    </div>
</template>
