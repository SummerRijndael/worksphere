<script setup>
import { ref, computed, onMounted } from "vue";
import { useRouter, useRoute } from "vue-router";
import { Button, Card, Input, PinInput } from "@/components/ui";
import { useAuthStore } from "@/stores/auth";
import api from "@/lib/api";
import { toast } from "vue-sonner";
import {
    Shield,
    Smartphone,
    MessageSquare,
    Mail,
    Key,
    ArrowLeft,
    RefreshCw,
    ChevronRight,
    Lock,
} from "lucide-vue-next";

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();

// State
const isLoading = ref(false);
const isSending = ref(false);
const code = ref("");
const useRecoveryCode = ref(false);
const recoveryCode = ref("");
const error = ref("");
const countdown = ref(0);
const hasSentCode = ref(false); // Track if code has been sent for SMS/Email

// Available methods
const availableMethods = ref([]);
const currentMethod = ref(null);
const contactInfo = ref({ phone: null, email: null });

// Method configuration
const methodConfig = {
    totp: {
        id: "totp",
        name: "Authenticator App",
        description: "Use a code from your authentication app.",
        icon: Smartphone,
        placeholder: "000000",
        inputMode: "numeric",
        maxLength: 6,
        instructions: "Enter the 6-digit code from your authenticator app.",
        requiresSend: false,
    },
    sms: {
        id: "sms",
        name: "SMS Verification",
        description: "Receive a code via text message.",
        icon: MessageSquare,
        placeholder: "000000",
        inputMode: "numeric",
        maxLength: 6,
        instructions: "Enter the 6-digit code sent to your phone.",
        requiresSend: true,
        canResend: true,
    },
    email: {
        id: "email",
        name: "Email Verification",
        description: "Receive a code via email.",
        icon: Mail,
        placeholder: "000000",
        inputMode: "numeric",
        maxLength: 6,
        instructions: "Enter the 6-digit code sent to your email.",
        requiresSend: true,
        canResend: true,
    },
};

// Computed
const currentConfig = computed(() =>
    currentMethod.value ? methodConfig[currentMethod.value] : null,
);
const canResend = computed(
    () => currentConfig.value?.canResend && countdown.value === 0,
);
const maskedContact = computed(() => {
    if (currentMethod.value === "sms") {
        return contactInfo.value.phone || route.query.phone || "***-***-****";
    }
    if (currentMethod.value === "email") {
        return contactInfo.value.email || route.query.email || "***@***.***";
    }
    return "";
});

// Fetch available methods
async function fetchMethods() {
    try {
        const response = await api.get("/api/two-factor-challenge/methods");
        if (response.data.methods && response.data.methods.length > 0) {
            availableMethods.value = response.data.methods;
            contactInfo.value = {
                phone: response.data.phone,
                email: response.data.email,
            };

            // If only one method, select it automatically
            if (availableMethods.value.length === 1) {
                currentMethod.value = availableMethods.value[0];
            } else {
                // Determine if we should prioritize TOTP or prompt list
                // For better UX, we can default to TOTP if available
                if (availableMethods.value.includes("totp")) {
                    currentMethod.value = "totp";
                }
            }
        }
    } catch (err) {
        console.error("Failed to fetch 2FA methods");
    }
}

// Select method (from list)
function selectMethod(method) {
    currentMethod.value = method;
    code.value = "";
    error.value = "";
    hasSentCode.value = false;
    countdown.value = 0;
}

// Send code (for SMS/Email)
async function sendCode() {
    if (countdown.value > 0) return;

    isSending.value = true;
    try {
        await api.post(`/api/two-factor-challenge/send`, {
            method: currentMethod.value,
        });
        toast.success(`Code sent via ${currentConfig.value.name}`);
        hasSentCode.value = true;

        // Start countdown
        countdown.value = 60;
        const timer = setInterval(() => {
            countdown.value--;
            if (countdown.value === 0) {
                clearInterval(timer);
            }
        }, 1000);
    } catch (err) {
        if (err.response?.status === 429) {
            toast.error(
                err.response.data.message || "Please wait before resending.",
            );
        } else {
            toast.error("Failed to send code. Please try again.");
        }
    } finally {
        isSending.value = false;
    }
}

// Verify code
async function verify() {
    const codeToVerify = useRecoveryCode.value
        ? recoveryCode.value
        : code.value;

    if (!codeToVerify) {
        error.value = "Please enter a code";
        return;
    }

    if (!useRecoveryCode.value) {
        if (
            currentConfig.value &&
            currentConfig.value.requiresSend &&
            !hasSentCode.value
        ) {
            // In case they manually try to verify without sending? Layout shouldn't allow this.
        }
        if (codeToVerify.length !== 6) {
            error.value = "Please enter a valid 6-digit code";
            return;
        }
    }

    isLoading.value = true;
    error.value = "";

    try {
        console.log("[TwoFactorView] Verify called", {
            method: currentMethod.value,
            useRecovery: useRecoveryCode.value,
        });
        const result = await authStore.verify2FA(
            code.value,
            currentMethod.value,
            useRecoveryCode.value ? recoveryCode.value : null,
        );

        console.log("[TwoFactorView] Verify success", result);

        if (result.success) {
            toast.success("Verified successfully");

            // Add delay to let session/CSRF cookies fully propagate before navigation
            // This prevents race conditions where API calls are made before the new session is recognized
            console.log("[TwoFactorView] Waiting for session to stabilize...");
            await new Promise((resolve) => setTimeout(resolve, 500));
            console.log("[TwoFactorView] Navigating to dashboard");

            router.push(route.query.redirect || "/dashboard");
        } else {
            error.value = result.error || "Verification failed";
        }
    } catch (err) {
        console.error("[TwoFactorView] Verify error", err);
        error.value = "Verification failed. Please try again.";
    } finally {
        isLoading.value = false;
    }
}

// Cancel and go back
async function cancel() {
    await authStore.logout();
    router.push("/auth/login");
}

onMounted(() => {
    fetchMethods();
});
</script>

<template>
    <div
        class="min-h-screen flex items-center justify-center bg-[var(--surface-primary)] p-4"
    >
        <div class="w-full max-w-md space-y-8">
            <!-- Header -->
            <div class="text-center">
                <div
                    class="mx-auto h-12 w-12 rounded-xl bg-gradient-to-br from-[var(--interactive-primary)] to-[var(--interactive-primary-hover)] flex items-center justify-center shadow-lg mb-6"
                >
                    <Lock class="h-6 w-6 text-white" />
                </div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                    Two-Factor Authentication
                </h1>
                <p class="mt-2 text-sm text-[var(--text-secondary)]">
                    Secure your account with an extra layer of protection.
                </p>
            </div>

            <!-- View: Method Selection (if multiple methods & none selected) -->
            <div
                v-if="
                    !currentMethod &&
                    !useRecoveryCode &&
                    availableMethods.length > 0
                "
                class="space-y-4"
            >
                <button
                    v-for="method in availableMethods"
                    :key="method"
                    @click="selectMethod(method)"
                    class="w-full relative group flex items-center gap-4 p-4 rounded-xl border border-[var(--border-default)] bg-[var(--surface-primary)] hover:border-[var(--interactive-primary)] hover:bg-[var(--surface-elevated)] transition-all text-left"
                >
                    <div
                        class="p-2 rounded-lg bg-[var(--surface-secondary)] group-hover:bg-[var(--surface-primary)] transition-colors"
                    >
                        <component
                            :is="methodConfig[method]?.icon"
                            class="h-5 w-5 text-[var(--text-primary)]"
                        />
                    </div>
                    <div class="flex-1">
                        <h3 class="font-medium text-[var(--text-primary)]">
                            {{ methodConfig[method]?.name }}
                        </h3>
                        <p class="text-xs text-[var(--text-secondary)] mt-0.5">
                            {{ methodConfig[method]?.description }}
                        </p>
                    </div>
                    <ChevronRight
                        class="h-4 w-4 text-[var(--text-muted)] group-hover:text-[var(--text-primary)]"
                    />
                </button>

                <div class="relative py-4">
                    <div class="absolute inset-0 flex items-center">
                        <div
                            class="w-full border-t border-[var(--border-default)]"
                        />
                    </div>
                    <div class="relative flex justify-center text-xs">
                        <span
                            class="bg-[var(--surface-primary)] px-2 text-[var(--text-muted)]"
                            >or</span
                        >
                    </div>
                </div>

                <button
                    @click="useRecoveryCode = true"
                    class="w-full flex items-center justify-center gap-2 text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)]"
                >
                    <Key class="h-4 w-4" />
                    Use a recovery code
                </button>

                <button
                    @click="cancel"
                    class="block w-full text-center text-sm text-[var(--text-muted)] hover:text-[var(--text-primary)] mt-4"
                >
                    Cancel Login
                </button>
            </div>

            <!-- View: Verification (Selected Method) -->
            <Card v-else class="p-6">
                <!-- Back Button -->
                <div
                    class="flex items-center gap-2 mb-6"
                    v-if="availableMethods.length > 1 && !useRecoveryCode"
                >
                    <button
                        @click="currentMethod = null"
                        class="flex items-center gap-1 text-xs font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)]"
                    >
                        <ArrowLeft class="h-3 w-3" />
                        Choose another method
                    </button>
                </div>

                <!-- Method Header -->
                <div class="text-center mb-6" v-if="!useRecoveryCode">
                    <component
                        :is="currentConfig?.icon"
                        class="h-8 w-8 mx-auto text-[var(--interactive-primary)] mb-2"
                    />
                    <h3
                        class="text-lg font-semibold text-[var(--text-primary)]"
                    >
                        {{ currentConfig?.name }}
                    </h3>
                    <div
                        v-if="currentConfig?.requiresSend"
                        class="text-sm text-[var(--text-secondary)]"
                    >
                        {{
                            hasSentCode
                                ? "Code sent to " + maskedContact
                                : "We need to verify your identity."
                        }}
                    </div>
                    <div v-else class="text-sm text-[var(--text-secondary)]">
                        {{ currentConfig?.instructions }}
                    </div>
                </div>

                <div v-if="useRecoveryCode" class="text-center mb-6">
                    <Key
                        class="h-8 w-8 mx-auto text-[var(--interactive-primary)] mb-2"
                    />
                    <h3
                        class="text-lg font-semibold text-[var(--text-primary)]"
                    >
                        Recovery Code
                    </h3>
                    <p class="text-sm text-[var(--text-secondary)]">
                        Enter one of your emergency recovery codes.
                    </p>
                </div>

                <div class="space-y-6">
                    <!-- Manual Send Button (SMS/Email) -->
                    <div
                        v-if="
                            !useRecoveryCode &&
                            currentConfig?.requiresSend &&
                            !hasSentCode
                        "
                    >
                        <p
                            class="text-sm text-[var(--text-secondary)] text-center mb-4"
                        >
                            Click below to send a verification code to
                            {{ maskedContact }}.
                        </p>
                        <Button
                            full-width
                            @click="sendCode"
                            :loading="isSending"
                        >
                            Send Verification Code
                        </Button>
                    </div>

                    <!-- Input & Verify (TOTP or Sent Code) -->
                    <template v-else>
                        <div class="space-y-2">
                            <label
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                {{
                                    useRecoveryCode
                                        ? "Recovery Code"
                                        : "Verification Code"
                                }}
                            </label>
                            <Input
                                v-if="useRecoveryCode"
                                v-model="recoveryCode"
                                type="text"
                                placeholder="Enter recovery code"
                                class="text-center font-mono"
                                :error="error"
                                @keyup.enter="verify"
                            />
                            <PinInput
                                v-else
                                v-model="code"
                                :length="6"
                                :error="error"
                                @complete="verify"
                            />
                        </div>

                        <!-- Resend Button -->
                        <div
                            v-if="!useRecoveryCode && currentConfig?.canResend"
                            class="text-center"
                        >
                            <button
                                :disabled="!canResend"
                                :class="[
                                    'inline-flex items-center gap-1.5 text-xs font-medium transition-colors',
                                    canResend
                                        ? 'text-[var(--interactive-primary)] hover:text-[var(--interactive-primary-hover)]'
                                        : 'text-[var(--text-muted)] cursor-not-allowed',
                                ]"
                                @click="sendCode"
                            >
                                <RefreshCw
                                    :class="[
                                        'h-3 w-3',
                                        isSending && 'animate-spin',
                                    ]"
                                />
                                {{
                                    countdown > 0
                                        ? `Resend code in ${countdown}s`
                                        : "Resend code"
                                }}
                            </button>
                        </div>

                        <Button full-width :loading="isLoading" @click="verify">
                            Verify
                        </Button>
                    </template>

                    <!-- Recovery Toggle -->
                    <div v-if="!useRecoveryCode" class="pt-2 text-center">
                        <button
                            class="text-xs text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                            @click="
                                useRecoveryCode = true;
                                error = '';
                            "
                        >
                            Lost access? Use a recovery code
                        </button>
                    </div>
                    <div v-else class="pt-2 text-center">
                        <button
                            class="text-xs text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                            @click="
                                useRecoveryCode = false;
                                error = '';
                            "
                        >
                            Use verification method
                        </button>
                    </div>
                </div>
            </Card>

            <div
                v-if="
                    (currentMethod || useRecoveryCode) &&
                    availableMethods.length > 0
                "
                class="text-center"
            >
                <button
                    @click="cancel"
                    class="text-xs text-[var(--text-muted)] hover:text-[var(--text-secondary)] transition-colors"
                >
                    Cancel and back to login
                </button>
            </div>
        </div>
    </div>
</template>
