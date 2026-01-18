<script setup>
import { ref, onMounted, onUnmounted, computed } from "vue";
import { useAuthStore } from "@/stores/auth";
import { useRouter, useRoute } from "vue-router";
import { Button, Card } from "@/components/ui";
import {
    Mail,
    ArrowRight,
    Loader2,
    LogOut,
    CheckCircle,
    Home,
} from "lucide-vue-next";
import { toast } from "vue-sonner";
import api from "@/lib/api";

const authStore = useAuthStore();
const router = useRouter();
const route = useRoute();

const isLoading = ref(false);
const cooldown = ref(0);
const isVerified = ref(false);
const redirectCountdown = ref(15);
let timer = null;
let redirectTimer = null;

// Check verification status on mount
onMounted(async () => {
    await checkVerificationStatus();
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
    if (redirectTimer) clearInterval(redirectTimer);
});

const checkVerificationStatus = async () => {
    // Check local Verified param first (from redirect)
    if (route.query.verified === '1') {
        isVerified.value = true;
        
        // If user is logged in, countdown to dashboard
        if (authStore.user) {
            startRedirectCountdown();
        }
        return;
    }

    try {
        await authStore.fetchUser();
        if (authStore.user?.email_verified_at) {
            isVerified.value = true;
            startRedirectCountdown();
        }
    } catch (error) {
        // If 401, we are guest. If verified=1 was absent, we assume unverified or just checking.
        // But since we removed requiresAuth, we might land here as guest.
        console.error("Failed to check verification status:", error);
    }
};

const startRedirectCountdown = () => {
    if (redirectTimer) clearInterval(redirectTimer);
    redirectCountdown.value = 15;

    redirectTimer = setInterval(() => {
        redirectCountdown.value--;
        if (redirectCountdown.value <= 0) {
            clearInterval(redirectTimer);
            redirectTimer = null;
            goToDashboard();
        }
    }, 1000);
};

const goToDashboard = () => {
    if (redirectTimer) clearInterval(redirectTimer);
    if (authStore.user) {
        router.push("/dashboard");
    } else {
        router.push("/auth/login");
    }
};

const startCooldown = (seconds) => {
    cooldown.value = seconds;
    if (timer) clearInterval(timer);

    timer = setInterval(() => {
        cooldown.value--;
        if (cooldown.value <= 0) {
            clearInterval(timer);
            timer = null;
        }
    }, 1000);
};

const refreshCsrfToken = async () => {
    await api.get("/sanctum/csrf-cookie");
    // Update meta tag with new token from cookie
    const getCookie = (name) => {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2)
            return decodeURIComponent(parts.pop().split(";").shift());
    };
    const newToken = getCookie("XSRF-TOKEN");
    if (newToken) {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            meta.setAttribute("content", newToken);
        }
    }
};

const resendVerification = async () => {
    if (cooldown.value > 0) return;

    isLoading.value = true;
    try {
        // Refresh CSRF token first and update meta tag
        await refreshCsrfToken();
        await api.post("/api/email/verification-notification");
        toast.success("Verification link sent!");
        startCooldown(60); // 60s throttle
    } catch (error) {
        if (error.response?.status === 429) {
            toast.error("Too many attempts. Please wait.");
            startCooldown(60);
        } else if (error.response?.status === 419) {
            // Session expired - try one more time with fresh token
            try {
                await refreshCsrfToken();
                await api.post("/api/email/verification-notification");
                toast.success("Verification link sent!");
                startCooldown(60);
            } catch (retryError) {
                toast.error("Session expired. Please refresh the page.");
            }
        } else {
            toast.error(
                error.response?.data?.message || "Failed to send email"
            );
        }
    } finally {
        isLoading.value = false;
    }
};

const logout = async () => {
    await authStore.logout();
    router.push("/auth/login");
};

const checkStatus = async () => {
    await checkVerificationStatus();
    if (!isVerified.value) {
        toast.info("Email not yet verified. Please check your inbox.");
    }
};
</script>

<template>
    <div
        class="min-h-screen flex items-center justify-center bg-[var(--surface-secondary)] p-4"
    >
        <div class="w-full max-w-md space-y-8">
            <!-- Verified State -->
            <template v-if="isVerified">
                <div class="text-center">
                    <div
                        class="mx-auto h-16 w-16 bg-emerald-500/10 rounded-full flex items-center justify-center mb-4"
                    >
                        <CheckCircle class="h-8 w-8 text-emerald-500" />
                    </div>
                    <h2 class="text-2xl font-bold text-[var(--text-primary)]">
                        Email Verified!
                    </h2>
                    <p class="mt-2 text-sm text-[var(--text-secondary)]">
                        Your email has been successfully verified.
                    </p>
                    <p v-if="!authStore.user" class="mt-1 text-sm text-[var(--text-muted)]">
                        Please sign in to continue.
                    </p>
                </div>

                <Card
                    padding="lg"
                    class="shadow-xl border-[var(--border-default)]"
                >
                    <div class="space-y-4">
                        <p
                            v-if="authStore.user"
                            class="text-sm text-[var(--text-secondary)] text-center"
                        >
                            Redirecting to dashboard in
                            <span
                                class="font-bold text-[var(--text-primary)]"
                                >{{ redirectCountdown }}</span
                            >
                            seconds...
                        </p>

                        <!-- Progress bar -->
                        <div
                            v-if="authStore.user"
                            class="w-full bg-[var(--surface-secondary)] rounded-full h-2 overflow-hidden"
                        >
                            <div
                                class="h-full bg-emerald-500 transition-all duration-1000 ease-linear"
                                :style="{
                                    width: `${
                                        ((15 - redirectCountdown) / 15) * 100
                                    }%`,
                                }"
                            />
                        </div>

                        <Button full-width @click="goToDashboard">
                            <Home class="mr-2 h-4 w-4" />
                            {{ authStore.user ? 'Go to Dashboard' : 'Sign In' }}
                        </Button>
                    </div>
                </Card>
            </template>

            <!-- Unverified State -->
            <template v-else>
                <div class="text-center">
                    <div
                        class="mx-auto h-16 w-16 bg-[var(--interactive-primary)]/10 rounded-full flex items-center justify-center mb-4"
                    >
                        <Mail
                            class="h-8 w-8 text-[var(--interactive-primary)]"
                        />
                    </div>
                    <h2 class="text-2xl font-bold text-[var(--text-primary)]">
                        Check your email
                    </h2>
                    <p class="mt-2 text-sm text-[var(--text-secondary)]">
                        We've sent a verification link to
                        <span
                            class="font-semibold text-[var(--text-primary)]"
                            >{{ authStore.user?.email }}</span
                        >.
                    </p>
                </div>

                <Card
                    padding="lg"
                    class="shadow-xl border-[var(--border-default)]"
                >
                    <div class="space-y-4">
                        <p
                            class="text-sm text-[var(--text-secondary)] text-center"
                        >
                            Click the link in the email to verify your account.
                            If you don't see it, check your spam folder.
                        </p>

                        <Button
                            full-width
                            @click="resendVerification"
                            :disabled="isLoading || cooldown > 0"
                            variant="outline"
                        >
                            <Loader2
                                v-if="isLoading"
                                class="mr-2 h-4 w-4 animate-spin"
                            />
                            <span v-else-if="cooldown > 0"
                                >Resend in {{ cooldown }}s</span
                            >
                            <span v-else>Resend Verification Email</span>
                        </Button>

                        <div class="relative py-2">
                            <div class="absolute inset-0 flex items-center">
                                <span
                                    class="w-full border-t border-[var(--border-muted)]"
                                />
                            </div>
                            <div
                                class="relative flex justify-center text-xs uppercase"
                            >
                                <span
                                    class="bg-[var(--surface-elevated)] px-2 text-[var(--text-muted)]"
                                    >Or</span
                                >
                            </div>
                        </div>

                        <Button
                            full-width
                            variant="ghost"
                            class="text-[var(--text-secondary)] hover:text-[var(--text-primary)]"
                            @click="logout"
                        >
                            <LogOut class="mr-2 h-4 w-4" />
                            Log Out
                        </Button>
                    </div>
                </Card>

                <div class="text-center">
                    <button
                        @click="checkStatus"
                        class="text-sm text-[var(--interactive-primary)] hover:underline inline-flex items-center gap-1"
                    >
                        I've verified my email <ArrowRight class="h-3 w-3" />
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>
