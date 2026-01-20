<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRouter, useRoute } from "vue-router";
import { toast } from "vue-sonner";
import api from "@/lib/api";
import { useAuthStore } from "@/stores/auth";
import { useRecaptcha } from "@/composables/useRecaptcha";
import {
    Button,
    Input,
    Checkbox,
    Avatar,
    PasswordStrengthMeter,
    PinInput,
} from "@/components/ui";
import {
    Mail,
    Lock,
    User,
    ArrowLeft,
    AlertCircle,
    Smartphone,
    MessageSquare,
    Key,
    RefreshCw,
    ChevronRight,
} from "lucide-vue-next";
import RecaptchaChallengeModal from "@/components/common/RecaptchaChallengeModal.vue";
import { animate } from "animejs";

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();

const {
    executeRecaptcha,
    isEnabled: recaptchaEnabled,
    siteKey: recaptchaSiteKey,
} = useRecaptcha();

// Passkey support
import { useWebAuthn } from "@/composables/useWebAuthn";
const {
    isSupported: isWebAuthnSupported,
    isPlatformAuthenticatorAvailable,
    authenticateWithPasskey,
} = useWebAuthn();
const passkeySupported = ref(false);
const isAuthenticatingPasskey = ref(false);

// Check passkey support on mount
const checkPasskeySupport = async () => {
    // We only check if the browser supports WebAuthn API.
    // We do NOT check isPlatformAuthenticatorAvailable() anymore, because
    // we want to allow "Cross-Device Authentication" (QR Code flow) for users
    // who have passkeys on their phones but not on this specific device.
    passkeySupported.value = isWebAuthnSupported();
};

// Handle passkey login
async function handlePasskeyLogin() {
    isAuthenticatingPasskey.value = true;
    clearErrors();

    const result = await authenticateWithPasskey();

    if (result.success && result.user) {
        authStore.user = result.user;
        toast.success("Welcome back!");
        animateExit("/dashboard");
    } else {
        errors.value.loginGeneral =
            result.error || "Passkey authentication failed";
    }

    isAuthenticatingPasskey.value = false;
}

// Config state
const socialLoginEnabled = ref(false);
const hintsLoaded = ref(false);

// Helper for proper casing
function toTitleCase(str: string | null | undefined): string {
    if (!str) return "";
    return str.replace(
        /\w\S*/g,
        (txt) => txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase()
    );
}

onMounted(async () => {
    // Handle social login errors
    if (route.query.error) {
        errors.value.loginGeneral = route.query.error as string;
        router.replace({ path: route.path });
    }

    // Handle social login verification required
    if (route.query.verification_required) {
        toast.error(
            (route.query.message as string) || "Verification required."
        );
        router.replace({ path: route.path });
    }

    // Handle social login 2FA redirect
    if (route.query.action === "2fa") {
        try {
            const { data } = await api.get("/api/two-factor-challenge/methods");
            twoFactorMethods.value = data.methods;
            twoFactorContactInfo.value = {
                phone: data.phone,
                email: data.email,
            };

            // Set default method
            if (data.methods.includes("totp")) {
                twoFactorForm.value.method = "totp";
            } else if (data.methods.includes("sms")) {
                twoFactorForm.value.method = "sms";
            } else if (data.methods.includes("email")) {
                twoFactorForm.value.method = "email";
            }

            // If SMS is the default or selected method, we don't automatically send code here
            // User will click "Send Code" manually or we can trigger it:
            // if (twoFactorForm.value.method === 'sms') send2FACode();

            currentView.value = "2fa";
        } catch (e) {
            console.error("Failed to load 2FA methods", e);
            errors.value.loginGeneral =
                "Failed to load 2FA challenge. Please try logging in again.";
        }
        router.replace({ path: route.path });
    }

    // Handle email verification status from query params
    const verification = route.query.verification as string;
    if (verification) {
        if (verification === "success") {
            toast.success("Email verified successfully! You can now sign in.");
        } else if (verification === "already_verified") {
            toast.info("Your email is already verified. Please sign in.");
        } else if (verification === "invalid") {
            const reason = route.query.reason as string;
            if (reason === "expired_or_invalid_link") {
                toast.error(
                    "This verification link has expired or is invalid. Please request a new one."
                );
            } else if (reason === "user_not_found") {
                toast.error("Account not found. Please register first.");
            } else {
                toast.error(
                    "Invalid verification link. Please request a new one."
                );
            }
        }
        // Clean up the URL by removing query params
        router.replace({ path: route.path });
    }

    // Fetch auth config
    try {
        const { data } = await api.get("/api/auth/config");
        socialLoginEnabled.value = data.social_login_enabled;

        // Sync Recaptcha with backend config
        if (
            data.recaptcha_enabled &&
            data.recaptcha_site_key &&
            recaptchaSiteKey
        ) {
            recaptchaSiteKey.value = data.recaptcha_site_key;
            if (recaptchaEnabled) recaptchaEnabled.value = true;
        }
    } catch (e) {
        console.error("Failed to load auth config", e);
    }

    // Fetch user hints if publicId is present
    if (authStore.userHints.publicId) {
        const success = await authStore.fetchUserHints();
        hintsLoaded.value = success;
    }

    // Check passkey support
    await checkPasskeySupport();
});

// Form state
type AuthView = "login" | "register" | "forgot" | "forgot-success" | "2fa";
const currentView = ref<AuthView>("login");

// Login form
const loginForm = ref({
    email: "", // Can be email or username
    password: "",
    remember: false,
});

// Register form
const registerForm = ref({
    name: "",
    email: "",
    password: "",
    confirmPassword: "",
    terms: false,
});

// Forgot password form
const forgotForm = ref({
    email: "",
});

// 2FA Challenge form
const twoFactorForm = ref({
    code: "",
    method: "totp" as string | null,
    useRecoveryCode: false,
    recoveryCode: "",
});
const twoFactorMethods = ref<string[]>([]);
const twoFactorContactInfo = ref<{
    phone: string | null;
    email: string | null;
}>({ phone: null, email: null });
const isSending2FA = ref(false);
const hasSent2FACode = ref(false);
const twoFactorCountdown = ref(0);

// Error states
interface AuthErrors {
    loginEmail?: string;
    loginPassword?: string;
    loginGeneral?: string;
    registerName?: string;
    registerEmail?: string;
    registerPassword?: string;
    registerConfirmPassword?: string;
    registerTerms?: string;
    registerGeneral?: string;
    forgotEmail?: string;
    forgotGeneral?: string;
    twoFactorGeneral?: string;
    [key: string]: string | undefined;
}
const errors = ref<AuthErrors>({});

// Computed
const hasUserHints = computed(
    () => authStore.userHints.hasVisited && hintsLoaded.value
);
const userHintName = computed(
    () => toTitleCase(authStore.userHints.nameHint) || "there"
);
const userHintInitials = computed(
    () => authStore.initialsHint || authStore.initials || "U"
);

// Password strength validation
const passwordMeetsRequirements = computed(() => {
    const password = registerForm.value.password;
    if (!password) return false;
    return (
        password.length >= 8 &&
        /[a-z]/.test(password) &&
        /[A-Z]/.test(password) &&
        /[0-9]/.test(password) &&
        /[^A-Za-z0-9]/.test(password)
    );
});

// Methods
function validateEmail(email: string): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function clearErrors() {
    errors.value = {};
}

async function send2FACode() {
    if (twoFactorCountdown.value > 0) return;
    isSending2FA.value = true;
    errors.value.twoFactorGeneral = "";
    try {
        await api.post(`/api/two-factor-challenge/send`, {
            method: twoFactorForm.value.method,
        });
        hasSent2FACode.value = true;
        twoFactorCountdown.value = 60;
        const timer = setInterval(() => {
            twoFactorCountdown.value--;
            if (twoFactorCountdown.value === 0) clearInterval(timer);
        }, 1000);
    } catch (e: any) {
        errors.value.twoFactorGeneral =
            e.response?.data?.message || "Failed to send code";
    } finally {
        isSending2FA.value = false;
    }
}

function select2FAMethod(method: string) {
    twoFactorForm.value.method = method;
    twoFactorForm.value.code = "";
    hasSent2FACode.value = false;
    twoFactorCountdown.value = 0;
    errors.value.twoFactorGeneral = "";
}

async function handleLogin() {
    clearErrors();

    // Only require email if no user hints (identity login mode)
    if (!hasUserHints.value && !loginForm.value.email) {
        errors.value.loginEmail = "Email or Username is required";
        return;
    }
    // We allow usernames now, so no strict email check
    if (!loginForm.value.password) {
        errors.value.loginPassword = "Password is required";
        return;
    }

    // Get reCAPTCHA token if enabled
    const recaptchaToken = recaptchaEnabled.value ? 'generate_fresh' : undefined;

    const result = await authStore.login({
        ...loginForm.value,
        recaptcha_token: recaptchaToken,
    });
    if (result.success) {
        animateExit("/dashboard");
    } else if (result.requires_challenge) {
        showChallenge.value = true;
    } else if (result.requires_2fa) {
        // Show 2FA challenge
        twoFactorMethods.value = result.methods || ["totp"];

        hasSent2FACode.value = false;
        twoFactorCountdown.value = 0;

        // Fetch detailed methods info (masked phone/email)
        try {
            const { data } = await api.get("/api/two-factor-challenge/methods");
            if (data.methods) twoFactorMethods.value = data.methods;
            twoFactorContactInfo.value = {
                phone: data.phone,
                email: data.email,
            };
        } catch (e) {
            console.error("Failed to fetch 2FA details", e);
        }

        if (twoFactorMethods.value.length === 1) {
            twoFactorForm.value.method = twoFactorMethods.value[0];
        } else {
            twoFactorForm.value.method = null;
        }
        currentView.value = "2fa";
    } else {
        errors.value.loginGeneral = result.error || "Login failed";
    }
}

async function handle2FA() {
    clearErrors();

    if (twoFactorForm.value.useRecoveryCode) {
        if (!twoFactorForm.value.recoveryCode) {
            errors.value.twoFactorGeneral = "Recovery code is required";
            return;
        }
    } else {
        if (
            !twoFactorForm.value.code ||
            twoFactorForm.value.code.length !== 6
        ) {
            errors.value.twoFactorGeneral = "Please enter a 6-digit code";
            return;
        }
    }

    const result = await authStore.verify2FA(
        twoFactorForm.value.code,
        twoFactorForm.value.method || "totp",
        twoFactorForm.value.useRecoveryCode
            ? twoFactorForm.value.recoveryCode
            : undefined
    );

    if (result.success) {
        animateExit("/dashboard");
    } else {
        errors.value.twoFactorGeneral = result.error || "Verification failed";
    }
}

async function handleRegister() {
    clearErrors();

    console.log("[LoginView] handleRegister called");
    console.log("[LoginView] Form data:", {
        name: registerForm.value.name,
        email: registerForm.value.email,
        hasPassword: !!registerForm.value.password,
        passwordLength: registerForm.value.password?.length,
        hasConfirmPassword: !!registerForm.value.confirmPassword,
        passwordsMatch:
            registerForm.value.password === registerForm.value.confirmPassword,
        terms: registerForm.value.terms,
    });

    if (!registerForm.value.name) {
        errors.value.registerName = "Name is required";
        return;
    }
    if (!registerForm.value.email) {
        errors.value.registerEmail = "Email is required";
        return;
    }
    if (!validateEmail(registerForm.value.email)) {
        errors.value.registerEmail = "Please enter a valid email";
        return;
    }
    if (!registerForm.value.password) {
        errors.value.registerPassword = "Password is required";
        return;
    }
    if (!passwordMeetsRequirements.value) {
        errors.value.registerPassword = "Password must meet all requirements";
        return;
    }
    if (registerForm.value.password !== registerForm.value.confirmPassword) {
        errors.value.registerConfirmPassword = "Passwords do not match";
        return;
    }
    if (!registerForm.value.terms) {
        errors.value.registerTerms = "You must accept the terms";
        return;
    }

    console.log("[LoginView] All validations passed");

    // Get reCAPTCHA token if enabled
    const recaptchaToken = recaptchaEnabled.value ? 'generate_fresh' : undefined;

    const payload = {
        name: registerForm.value.name,
        email: registerForm.value.email,
        password: registerForm.value.password,
        password_confirmation: registerForm.value.confirmPassword,
        recaptcha_token: recaptchaToken,
    };
    console.log("[LoginView] Calling authStore.register with payload:", {
        ...payload,
        password: "***",
        password_confirmation: "***",
        recaptcha_token: recaptchaToken ? "***" : undefined,
    });

    const result = await authStore.register(payload);
    console.log("[LoginView] Registration result:", result);

    if (result.success) {
        console.log(
            "[LoginView] Registration successful, redirecting to dashboard"
        );
        animateExit("/dashboard?welcome=1");
    } else {
        console.error("[LoginView] Registration failed:", result.error);
        errors.value.registerGeneral = result.error || "Registration failed";
    }
}

async function handleForgotPassword() {
    clearErrors();

    if (!forgotForm.value.email) {
        errors.value.forgotEmail = "Email is required";
        return;
    }
    if (!validateEmail(forgotForm.value.email)) {
        errors.value.forgotEmail = "Please enter a valid email";
        return;
    }

    const result = await authStore.forgotPassword(forgotForm.value.email);
    if (result.success) {
        currentView.value = "forgot-success";
    } else {
        errors.value.forgotGeneral =
            result.error || "Failed to send reset email";
    }
}

function switchView(view: AuthView) {
    clearErrors();
    currentView.value = view;
}

function clearUserHints() {
    authStore.clearHints();
    hintsLoaded.value = false;
}

async function socialLogin(provider: string) {
    try {
        const { data } = await api.get(`/api/auth/${provider}/redirect`);
        if (data.url) {
            window.location.href = data.url;
        }
    } catch (e) {
        console.error("Social login error", e);
        errors.value.loginGeneral = "Failed to connect to social provider.";
    }
}

// Challenge state
const showChallenge = ref(false);

async function handleChallengeVerified(token: string) {
    showChallenge.value = false;
    // Retry login with the new v2 token
    // We need to pass it to authStore.login
    
    // Refresh v3 token as well, as the previous one likely expired
    let freshV3Token: string | null = null;
    if (recaptchaEnabled.value) {
        freshV3Token = await executeRecaptcha("login");
    }

    const payload = {
        ...loginForm.value,
        recaptcha_v2_token: token,
        recaptcha_token: freshV3Token || undefined
    };
    
    const result = await authStore.login(payload);
    
     if (result.success) {
        animateExit("/dashboard");
    } else if (result.requires_2fa) {
        // Reuse logic from handleLogin - ideally refactor this common logic
        // For brevity, copy-paste or extract. I'll extract common 2FA handler if it gets complex.
         twoFactorMethods.value = result.methods || ["totp"];
         // ... rest of 2FA setup ...
         // Copying essential parts
        try {
            const { data } = await api.get("/api/two-factor-challenge/methods");
            if (data.methods) twoFactorMethods.value = data.methods;
             twoFactorContactInfo.value = { phone: data.phone, email: data.email };
        } catch (e) {}
        
        if (twoFactorMethods.value.length === 1) {
            twoFactorForm.value.method = twoFactorMethods.value[0];
        } else {
            twoFactorForm.value.method = null;
        }
        currentView.value = "2fa";
    } else {
        errors.value.loginGeneral = result.error || "Login failed after challenge.";
    }
}

// Animations
import { remove } from "animejs";

const enter = (el: Element, done: () => void) => {
    if (!el) { done(); return; }
    remove(el);
    animate(el as HTMLElement, {
        opacity: [0, 1],
        translateY: [20, 0],
        duration: 400,
        easing: 'easeOutExpo',
    }).then(() => done());
};

const leave = (el: Element, done: () => void) => {
    if (!el) { done(); return; }
    remove(el);
    animate(el as HTMLElement, {
        opacity: 0,
        translateY: -20,
        duration: 200,
        easing: 'easeInQuad',
    }).then(() => done());
};

const slideFadeEnter = (el: Element, done: () => void) => {
    if (!el) { done(); return; }
    remove(el);
    // Prepare initial state
    const target = el as HTMLElement;
    target.style.opacity = '0';
    target.style.height = '0';
    target.style.overflow = 'hidden';
    target.style.transform = 'translateY(-10px)';

    // Measure full height
    const targetHeight = target.scrollHeight + 'px';

    animate(target, {
        opacity: [0, 1],
        height: [0, targetHeight],
        translateY: [-10, 0],
        duration: 400,
        easing: 'easeOutExpo',
    }).then(() => {
        target.style.height = 'auto'; // Reset to auto after animation
        target.style.overflow = '';
        done();
    });
};

const slideFadeLeave = (el: Element, done: () => void) => {
    if (!el) { done(); return; }
    remove(el);
    const target = el as HTMLElement;
    target.style.overflow = 'hidden';
    target.style.height = target.scrollHeight + 'px'; // Set explicit height to animate from

    animate(target, {
        opacity: 0,
        height: 0,
        translateY: -10,
        marginTop: 0,
        marginBottom: 0,
        paddingTop: 0,
        paddingBottom: 0,
        duration: 300,
        easing: 'easeInExpo',
    }).then(() => done());
};

// Exit animation before redirect
async function animateExit(path: string) {
    // Select the main container (first child of the transition wrapper)
    const container = document.querySelector('.auth-container'); 
    
    if (container) {
        await animate(container as HTMLElement, {
            opacity: [1, 0],
            translateY: [0, -20],
            scale: [1, 0.95],
            duration: 400,
            easing: 'easeInExpo'
        }).finished;
    }
    
    router.push(path);
}
</script>

<template>
    <div class="relative min-h-[400px]">
        <Transition
            mode="out-in"
            :css="false"
            @enter="enter"
            @leave="leave"
        >
            <!-- Login View -->
            <div v-if="currentView === 'login'" key="login" class="space-y-6 auth-container">
                <!-- Welcome Back Banner (if user has visited before) -->
                <Transition
                    :css="false"
                    @enter="slideFadeEnter"
                    @leave="slideFadeLeave"
                >
                    <div
                        v-if="hasUserHints"
                        class="rounded-xl bg-[var(--surface-secondary)] p-4"
                    >
                        <div class="flex items-center gap-3">
                            <Avatar :fallback="userHintInitials" size="md" />
                            <div class="flex-1 min-w-0">
                                <p
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                >
                                    Welcome back, {{ userHintName }}!
                                </p>
                                <p
                                    class="text-xs text-[var(--text-muted)] truncate"
                                >
                                    {{ authStore.maskedEmailHint }}
                                </p>
                            </div>
                            <button
                                class="text-xs text-[var(--text-muted)] hover:text-[var(--interactive-primary)] hover:underline transition-all active:scale-95"
                                @click="clearUserHints"
                            >
                                Not you?
                            </button>
                        </div>
                    </div>
                </Transition>

                <!-- Header -->
                <div class="space-y-2">
                    <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                        {{
                            hasUserHints
                                ? "Sign in to continue"
                                : "Welcome back"
                        }}
                    </h1>
                    <p class="text-sm text-[var(--text-secondary)]">
                        Enter your credentials to access your account
                    </p>
                </div>

                <!-- Login Form -->
                <form class="space-y-4" @submit.prevent="handleLogin">
                    <Transition
                        :css="false"
                        @enter="slideFadeEnter"
                        @leave="slideFadeLeave"
                    >
                        <div v-if="!hasUserHints">
                            <Input
                                v-model="loginForm.email"
                                type="text"
                                label="Email or Username"
                                placeholder="Enter your email or username"
                                :icon="User"
                                :error="errors.loginEmail"
                            />
                        </div>
                    </Transition>
                    <div v-if="hasUserHints">
                        <!-- Hidden placeholder if needed, but likely unnecessary with transition -->
                    </div>

                    <Input
                        v-model="loginForm.password"
                        type="password"
                        label="Password"
                        placeholder="Enter your password"
                        :icon="Lock"
                        :error="errors.loginPassword"
                    />

                    <div class="flex items-center justify-between">
                        <Checkbox
                            v-model="loginForm.remember"
                            label="Remember me"
                        />
                        <button
                            type="button"
                            class="text-sm font-medium text-[var(--interactive-primary)] hover:text-[var(--interactive-primary-hover)] transition-colors"
                            @click="switchView('forgot')"
                        >
                            Forgot password?
                        </button>
                    </div>

                    <div
                        v-if="errors.loginGeneral"
                        class="p-3 rounded-lg bg-[var(--color-error)]/10 border border-[var(--color-error)]/20 text-[var(--color-error)] text-sm flex items-start gap-2"
                    >
                        <AlertCircle class="w-4 h-4 mt-0.5 shrink-0" />
                        <span>{{ errors.loginGeneral }}</span>
                    </div>

                    <Button
                        type="submit"
                        full-width
                        :loading="authStore.isLoading"
                    >
                        Sign in
                    </Button>
                </form>

                <!-- Divider -->
                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <div
                            class="w-full border-t border-[var(--border-default)]"
                        />
                    </div>
                    <div class="relative flex justify-center text-xs uppercase">
                        <span
                            class="bg-[var(--surface-elevated)] px-2 text-[var(--text-muted)]"
                        >
                            Or continue with
                        </span>
                    </div>
                </div>

                <!-- Social Login -->
                <div v-if="socialLoginEnabled" class="grid grid-cols-2 gap-3">
                    <Button
                        variant="outline"
                        type="button"
                        @click="socialLogin('google')"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24">
                            <path
                                fill="currentColor"
                                d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                            />
                            <path
                                fill="currentColor"
                                d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                            />
                            <path
                                fill="currentColor"
                                d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                            />
                            <path
                                fill="currentColor"
                                d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                            />
                        </svg>
                        Google
                    </Button>
                    <Button
                        variant="outline"
                        type="button"
                        @click="socialLogin('github')"
                    >
                        <svg
                            class="h-4 w-4"
                            fill="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"
                            />
                        </svg>
                        GitHub
                    </Button>
                </div>

                <!-- Passkey Login -->
                <div v-if="passkeySupported" class="mt-3">
                    <Button
                        variant="outline"
                        type="button"
                        class="w-full gap-2"
                        :loading="isAuthenticatingPasskey"
                        @click="handlePasskeyLogin"
                    >
                        <Key class="h-4 w-4" />
                        Sign in with Passkey
                    </Button>
                </div>

                <!-- Register Link -->
                <p class="text-center text-sm text-[var(--text-secondary)]">
                    Don't have an account?
                    <button
                        type="button"
                        class="font-medium text-[var(--interactive-primary)] hover:text-[var(--interactive-primary-hover)] transition-colors"
                        @click="switchView('register')"
                    >
                        Create one
                    </button>
                </p>

                <!-- reCAPTCHA Legal -->
                <p
                    v-if="recaptchaEnabled"
                    class="text-xs text-center text-[var(--text-muted)] mt-4"
                >
                    This site is protected by reCAPTCHA and the Google
                    <a
                        href="https://policies.google.com/privacy"
                        target="_blank"
                        class="hover:underline"
                        >Privacy Policy</a
                    >
                    and
                    <a
                        href="https://policies.google.com/terms"
                        target="_blank"
                        class="hover:underline"
                        >Terms of Service</a
                    >
                    apply.
                </p>
            </div>

            <!-- 2FA Challenge View -->
            <div v-else-if="currentView === '2fa'" key="2fa" class="space-y-6">

                <!-- Header -->
                <div class="space-y-2">
                    <button
                        type="button"
                        class="flex items-center gap-1 text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                        @click="currentView = 'login'"
                    >
                        <ArrowLeft class="h-4 w-4" />
                        Back to login
                    </button>
                    <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                        Two-Factor Authentication
                    </h1>
                    <p class="text-sm text-[var(--text-secondary)]">
                        Secure your account with an extra layer of protection.
                    </p>
                </div>

                <!-- Method Selection (if multiple methods & none selected) -->
                <div
                    v-if="
                        !twoFactorForm.method &&
                        !twoFactorForm.useRecoveryCode &&
                        twoFactorMethods.length > 0
                    "
                    class="space-y-4"
                >
                    <button
                        v-for="method in twoFactorMethods"
                        :key="method"
                        type="button"
                        @click="select2FAMethod(method)"
                        class="w-full relative group flex items-center gap-4 p-4 rounded-xl border border-[var(--border-default)] bg-[var(--surface-primary)] hover:border-[var(--interactive-primary)] hover:bg-[var(--surface-elevated)] transition-all text-left"
                    >
                        <div
                            class="p-2 rounded-lg bg-[var(--surface-secondary)] group-hover:bg-[var(--surface-primary)] transition-colors"
                        >
                            <Smartphone
                                v-if="method === 'totp'"
                                class="h-5 w-5 text-[var(--text-primary)]"
                            />
                            <MessageSquare
                                v-else-if="method === 'sms'"
                                class="h-5 w-5 text-[var(--text-primary)]"
                            />
                            <Mail
                                v-else-if="method === 'email'"
                                class="h-5 w-5 text-[var(--text-primary)]"
                            />
                        </div>
                        <div class="flex-1">
                            <h3 class="font-medium text-[var(--text-primary)]">
                                {{
                                    method === "totp"
                                        ? "Authenticator App"
                                        : method === "sms"
                                        ? "SMS Verification"
                                        : "Email Verification"
                                }}
                            </h3>
                            <p
                                class="text-xs text-[var(--text-secondary)] mt-0.5"
                            >
                                {{
                                    method === "totp"
                                        ? "Use a code from your authenticator app"
                                        : method === "sms"
                                        ? "Receive a code via text message"
                                        : "Receive a code via email"
                                }}
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
                        type="button"
                        @click="twoFactorForm.useRecoveryCode = true"
                        class="w-full flex items-center justify-center gap-2 text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)]"
                    >
                        <Key class="h-4 w-4" />
                        Use a recovery code
                    </button>
                </div>

                <!-- Verification Form -->
                <form v-else class="space-y-6" @submit.prevent="handle2FA">
                    <!-- Back to Methods -->
                    <div
                        class="flex items-center gap-2"
                        v-if="
                            twoFactorMethods.length > 1 &&
                            !twoFactorForm.useRecoveryCode
                        "
                    >
                        <button
                            type="button"
                            @click="twoFactorForm.method = null"
                            class="flex items-center gap-1 text-xs font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)]"
                        >
                            <ArrowLeft class="h-3 w-3" />
                            Choose another method
                        </button>
                    </div>

                    <div class="space-y-6">
                        <!-- Method Info -->
                        <div
                            class="text-center"
                            v-if="!twoFactorForm.useRecoveryCode"
                        >
                            <div class="mb-4 flex justify-center">
                                <Smartphone
                                    v-if="twoFactorForm.method === 'totp'"
                                    class="h-8 w-8 text-[var(--interactive-primary)]"
                                />
                                <MessageSquare
                                    v-else-if="twoFactorForm.method === 'sms'"
                                    class="h-8 w-8 text-[var(--interactive-primary)]"
                                />
                                <Mail
                                    v-else-if="twoFactorForm.method === 'email'"
                                    class="h-8 w-8 text-[var(--interactive-primary)]"
                                />
                            </div>
                            <h3
                                class="text-lg font-semibold text-[var(--text-primary)]"
                            >
                                {{
                                    twoFactorForm.method === "totp"
                                        ? "Authenticator App"
                                        : twoFactorForm.method === "sms"
                                        ? "SMS Verification"
                                        : "Email Verification"
                                }}
                            </h3>
                            <p
                                class="text-sm text-[var(--text-secondary)] mt-1"
                            >
                                {{
                                    twoFactorForm.method === "totp"
                                        ? "Enter the 6-digit code from your app"
                                        : hasSent2FACode
                                        ? "Code sent to " +
                                          (twoFactorForm.method === "sms"
                                              ? twoFactorContactInfo.phone ||
                                                "your phone"
                                              : twoFactorContactInfo.email ||
                                                "your email")
                                        : "We need to verify your identity."
                                }}
                            </p>
                        </div>

                        <!-- Manual Send Button -->
                        <div
                            v-if="
                                !twoFactorForm.useRecoveryCode &&
                                (twoFactorForm.method === 'sms' ||
                                    twoFactorForm.method === 'email') &&
                                !hasSent2FACode
                            "
                        >
                            <Button
                                type="button"
                                full-width
                                @click="send2FACode"
                                :loading="isSending2FA"
                            >
                                Send Verification Code
                            </Button>
                        </div>

                        <!-- Inputs -->
                        <template v-else>
                            <div
                                v-if="!twoFactorForm.useRecoveryCode"
                                class="space-y-2"
                            >
                                <label
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                >
                                    Verification Code
                                </label>
                                <PinInput
                                    v-model="twoFactorForm.code"
                                    :length="6"
                                    :disabled="authStore.isLoading"
                                    @complete="handle2FA"
                                />
                            </div>

                            <!-- Recovery Code -->
                            <div v-else class="space-y-2 text-center">
                                <div class="mb-4 flex justify-center">
                                    <Key
                                        class="h-8 w-8 text-[var(--interactive-primary)]"
                                    />
                                </div>
                                <h3
                                    class="text-lg font-semibold text-[var(--text-primary)] mb-4"
                                >
                                    Recovery Code
                                </h3>
                                <input
                                    v-model="twoFactorForm.recoveryCode"
                                    type="text"
                                    class="w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] px-4 py-3 text-center font-mono text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20"
                                    placeholder="xxxx-xxxx-xxxx"
                                    :disabled="authStore.isLoading"
                                />
                            </div>

                            <!-- Resend Button -->
                            <div
                                v-if="
                                    !twoFactorForm.useRecoveryCode &&
                                    (twoFactorForm.method === 'sms' ||
                                        twoFactorForm.method === 'email')
                                "
                                class="text-center"
                            >
                                <button
                                    type="button"
                                    :disabled="
                                        twoFactorCountdown > 0 || isSending2FA
                                    "
                                    :class="[
                                        'inline-flex items-center gap-1.5 text-xs font-medium transition-colors',
                                        twoFactorCountdown === 0
                                            ? 'text-[var(--interactive-primary)] hover:text-[var(--interactive-primary-hover)]'
                                            : 'text-[var(--text-muted)] cursor-not-allowed',
                                    ]"
                                    @click="send2FACode"
                                >
                                    <RefreshCw
                                        :class="[
                                            'h-3 w-3',
                                            isSending2FA && 'animate-spin',
                                        ]"
                                    />
                                    {{
                                        twoFactorCountdown > 0
                                            ? `Resend code in ${twoFactorCountdown}s`
                                            : "Resend code"
                                    }}
                                </button>
                            </div>

                            <!-- Error -->
                            <p
                                v-if="errors.twoFactorGeneral"
                                class="text-sm text-[var(--color-error)] text-center"
                            >
                                {{ errors.twoFactorGeneral }}
                            </p>

                            <!-- Verify Button -->
                            <Button
                                type="submit"
                                class="w-full"
                                :loading="authStore.isLoading"
                            >
                                Verify
                            </Button>
                        </template>

                        <!-- Recovery Toggle -->
                        <div class="text-center pt-2">
                            <div class="relative mb-4">
                                <div class="absolute inset-0 flex items-center">
                                    <div
                                        class="w-full border-t border-[var(--border-default)]"
                                    />
                                </div>
                                <div
                                    class="relative flex justify-center text-xs"
                                >
                                    <span
                                        class="bg-[var(--surface-primary)] px-2 text-[var(--text-muted)]"
                                        >or</span
                                    >
                                </div>
                            </div>
                            <button
                                type="button"
                                class="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                                @click="
                                    twoFactorForm.useRecoveryCode =
                                        !twoFactorForm.useRecoveryCode;
                                    errors.twoFactorGeneral = '';
                                "
                            >
                                {{
                                    twoFactorForm.useRecoveryCode
                                        ? "Use verification code instead"
                                        : "Use a recovery code instead"
                                }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Register View -->
            <div
                v-else-if="currentView === 'register'"
                key="register"
                class="space-y-6"
            >
                <!-- Header -->
                <div class="space-y-2">
                    <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                        Create an account
                    </h1>
                    <p class="text-sm text-[var(--text-secondary)]">
                        Enter your details to get started
                    </p>
                </div>

                <!-- Register Form -->
                <form class="space-y-4" @submit.prevent="handleRegister">
                    <Input
                        v-model="registerForm.name"
                        type="text"
                        label="Full Name"
                        placeholder="Enter your name"
                        :icon="User"
                        :error="errors.registerName"
                    />

                    <Input
                        v-model="registerForm.email"
                        type="email"
                        label="Email"
                        placeholder="Enter your email"
                        :icon="Mail"
                        :error="errors.registerEmail"
                    />

                    <div class="space-y-2">
                        <Input
                            v-model="registerForm.password"
                            type="password"
                            label="Password"
                            placeholder="Create a password"
                            :icon="Lock"
                            :error="errors.registerPassword"
                        />
                        <PasswordStrengthMeter
                            :password="registerForm.password"
                        />
                    </div>

                    <Input
                        v-model="registerForm.confirmPassword"
                        type="password"
                        label="Confirm Password"
                        placeholder="Confirm your password"
                        :icon="Lock"
                        :error="errors.registerConfirmPassword"
                    />

                    <Checkbox v-model="registerForm.terms">
                        I agree to the
                        <router-link
                            to="/terms"
                            class="text-[var(--interactive-primary)] hover:text-[var(--interactive-primary-hover)] underline"
                            target="_blank"
                            @click.stop
                            >Terms of Service</router-link
                        >
                        and
                        <router-link
                            to="/privacy"
                            class="text-[var(--interactive-primary)] hover:text-[var(--interactive-primary-hover)] underline"
                            target="_blank"
                            @click.stop
                            >Privacy Policy</router-link
                        >
                    </Checkbox>
                    <p
                        v-if="errors.registerTerms"
                        class="text-sm text-[var(--color-error)] -mt-2"
                    >
                        {{ errors.registerTerms }}
                    </p>

                    <p
                        v-if="errors.registerGeneral"
                        class="text-sm text-[var(--color-error)]"
                    >
                        {{ errors.registerGeneral }}
                    </p>

                    <Button
                        type="submit"
                        full-width
                        :loading="authStore.isLoading"
                    >
                        Create account
                    </Button>
                </form>

                <!-- Login Link -->
                <p class="text-center text-sm text-[var(--text-secondary)]">
                    Already have an account?
                    <button
                        type="button"
                        class="font-medium text-[var(--interactive-primary)] hover:text-[var(--interactive-primary-hover)] transition-colors"
                        @click="switchView('login')"
                    >
                        Sign in
                    </button>
                </p>

                <!-- reCAPTCHA Legal -->
                <p
                    v-if="recaptchaEnabled"
                    class="text-xs text-center text-[var(--text-muted)] mt-4"
                >
                    This site is protected by reCAPTCHA and the Google
                    <a
                        href="https://policies.google.com/privacy"
                        target="_blank"
                        class="hover:underline"
                        >Privacy Policy</a
                    >
                    and
                    <a
                        href="https://policies.google.com/terms"
                        target="_blank"
                        class="hover:underline"
                        >Terms of Service</a
                    >
                    apply.
                </p>
            </div>

            <!-- Forgot Password View -->
            <div
                v-else-if="currentView === 'forgot'"
                key="forgot"
                class="space-y-6"
            >
                <!-- Back Button -->
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                    @click="switchView('login')"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Back to login
                </button>

                <!-- Header -->
                <div class="space-y-2">
                    <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                        Forgot password?
                    </h1>
                    <p class="text-sm text-[var(--text-secondary)]">
                        No worries, we'll send you reset instructions.
                    </p>
                </div>

                <!-- Forgot Form -->
                <form class="space-y-4" @submit.prevent="handleForgotPassword">
                    <Input
                        v-model="forgotForm.email"
                        type="email"
                        label="Email"
                        placeholder="Enter your email"
                        :icon="Mail"
                        :error="errors.forgotEmail"
                    />

                    <p
                        v-if="errors.forgotGeneral"
                        class="text-sm text-[var(--color-error)]"
                    >
                        {{ errors.forgotGeneral }}
                    </p>

                    <Button
                        type="submit"
                        full-width
                        :loading="authStore.isLoading"
                    >
                        Send reset link
                    </Button>
                </form>
            </div>

            <!-- Forgot Password Success View -->
            <div
                v-else-if="currentView === 'forgot-success'"
                key="forgot-success"
                class="space-y-6 text-center"
            >
                <!-- Success Icon -->
                <div
                    class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30"
                >
                    <svg
                        class="h-8 w-8 text-green-600 dark:text-green-400"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M5 13l4 4L19 7"
                        />
                    </svg>
                </div>

                <!-- Header -->
                <div class="space-y-2">
                    <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                        Check your email
                    </h1>
                    <p class="text-sm text-[var(--text-secondary)]">
                        We've sent a password reset link to<br />
                        <span class="font-medium text-[var(--text-primary)]">{{
                            forgotForm.email
                        }}</span>
                    </p>
                </div>

                <Button full-width @click="switchView('login')">
                    Back to login
                </Button>

                <p class="text-sm text-[var(--text-muted)]">
                    Didn't receive the email?
                    <button
                        type="button"
                        class="font-medium text-[var(--interactive-primary)] hover:text-[var(--interactive-primary-hover)] transition-colors"
                        @click="handleForgotPassword"
                    >
                        Click to resend
                    </button>
                </p>
            </div>
        </Transition>

        <RecaptchaChallengeModal 
            :show="showChallenge"
            @close="showChallenge = false"
            @verified="handleChallengeVerified"
        />
    </div>
</template>

<style scoped>
/* Scoped styles if needed */
</style>
