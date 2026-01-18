<script setup>
import { ref, onMounted, computed, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useThemeStore } from "@/stores/theme";
import { useAuthStore } from "@/stores/auth";
import api from "@/lib/api";
import { debounce } from "lodash";
import {
    Card,
    Button,
    Input,
    Switch,
    Avatar,
    Badge,
    PasswordStrengthMeter,
} from "@/components/ui";
import {
    User,
    Bell,
    Shield,
    Palette,
    Link2,
    Trash2,
    Monitor,
    Smartphone,
    LogOut,
    Loader2,
    Check,
    X,
    Lock,
    Mail,
    Eye,
    EyeOff,
    Camera,
    Plus,
    FileText,
} from "lucide-vue-next";
import { toast } from "vue-sonner";

const themeStore = useThemeStore();
const authStore = useAuthStore();
const router = useRouter();

const activeTab = ref("profile");
const isLoading = ref(false);
const isSaving = ref(false);

const tabs = [
    { id: "profile", label: "Profile", icon: User },
    { id: "security", label: "Security", icon: Shield },
    { id: "notifications", label: "Notifications", icon: Bell },
    { id: "appearance", label: "Appearance", icon: Palette },
    { id: "connections", label: "Connections", icon: Link2 },
];

// Profile settings
const profile = ref({
    name: "",
    username: "",
    email: "",
    title: "",
    bio: "",
    location: "",
    website: "",
    skills: [],
});

const newSkill = ref("");

const addSkill = () => {
    if (!newSkill.value.trim()) return;

    // Split by comma if present
    const skillsToAdd = newSkill.value
        .split(",")
        .map((s) => s.trim())
        .filter((s) => s);

    skillsToAdd.forEach((skill) => {
        if (!profile.value.skills.includes(skill)) {
            profile.value.skills.push(skill);
        }
    });

    newSkill.value = "";
};

const handleSkillKeydown = (e) => {
    if (e.key === "," || e.key === "Enter") {
        e.preventDefault();
        addSkill();
    }
};

const handleSkillPaste = (e) => {
    e.preventDefault();
    const pastedText = e.clipboardData.getData("text");
    const skillsToAdd = pastedText
        .split(",")
        .map((s) => s.trim())
        .filter((s) => s);

    skillsToAdd.forEach((skill) => {
        if (!profile.value.skills.includes(skill)) {
            profile.value.skills.push(skill);
        }
    });
};

const removeSkill = (index) => {
    profile.value.skills.splice(index, 1);
};
// Password change
const passwordForm = ref({
    current_password: "",
    password: "",
    password_confirmation: "",
});
const showCurrentPassword = ref(false);
const showNewPassword = ref(false);
const isChangingPassword = ref(false);

// Notification preferences
const notifications = ref({
    email: true,
    push: true,
    marketing: false,
    updates: true,
    mentions: true,
    tasks: true,
});

// Appearance settings
const appearance = ref({
    mode: themeStore.currentMode, // Changed from theme to mode
    reducedMotion: false,
    compactMode: false,
});

// Sessions
const sessions = ref([]);
const isLoadingSessions = ref(false);
const isRevokingSession = ref(null);

// Social accounts
const socialAccounts = ref([]);
const isLoadingSocials = ref(false);

// Passkeys (WebAuthn)
import { useWebAuthn } from "@/composables/useWebAuthn";
const {
    isSupported: isWebAuthnSupported,
    isPlatformAuthenticatorAvailable,
    registerPasskey,
} = useWebAuthn();
const passkeys = ref([]);
const isLoadingPasskeys = ref(false);
const isRegisteringPasskey = ref(false);
const showPasskeyNameModal = ref(false);
const newPasskeyName = ref("");
const passkeySupported = ref(false);

// Check passkey support
const checkPasskeySupport = async () => {
    if (!isWebAuthnSupported()) {
        passkeySupported.value = false;
        return;
    }
    passkeySupported.value = await isPlatformAuthenticatorAvailable();
};

// Fetch passkeys
const fetchPasskeys = async () => {
    isLoadingPasskeys.value = true;
    try {
        const response = await api.get("/api/user/passkeys");
        passkeys.value = response.data;
    } catch (error) {
        console.error("Failed to fetch passkeys");
    } finally {
        isLoadingPasskeys.value = false;
    }
};

// Register new passkey
const startPasskeyRegistration = () => {
    newPasskeyName.value = "";
    showPasskeyNameModal.value = true;
};

const confirmPasskeyRegistration = async () => {
    isRegisteringPasskey.value = true;
    showPasskeyNameModal.value = false;

    const result = await registerPasskey(newPasskeyName.value || "Passkey");

    if (result.success) {
        toast.success("Passkey registered successfully!");
        await fetchPasskeys();
    } else {
        toast.error(result.error || "Failed to register passkey");
    }

    isRegisteringPasskey.value = false;
};

// Delete passkey
const deletePasskey = async (id) => {
    if (!confirm("Are you sure you want to delete this passkey?")) return;

    try {
        await api.delete(`/api/user/passkeys/${id}`);
        passkeys.value = passkeys.value.filter((p) => p.id !== id);
        toast.success("Passkey deleted");
    } catch (error) {
        toast.error("Failed to delete passkey");
    }
};

// 2FA state
const twoFactorStatus = ref({
    enabled: false,
    confirmed: false,
    totpPending: false,
    smsPending: false,
    enabledMethods: [], // ['totp', 'sms', 'email']
    primaryMethods: [], // ['totp', 'sms'] - methods excluding passive email
    emailIsFallback: false, // email is auto-enabled as fallback
    phone: null, // masked phone if SMS enabled
});
const twoFactorQrCode = ref("");
const twoFactorSecret = ref("");
const twoFactorRecoveryCodes = ref([]);
const twoFactorConfirmCode = ref("");
const isEnabling2FA = ref(false);
const isConfirming2FA = ref(false);
const isDisabling2FA = ref(false);
const show2FAModal = ref(false);
const twoFAStep = ref("setup"); // 'setup', 'confirm', 'recovery'
const showManualKey = ref(false);

// SMS 2FA state
const smsPhoneNumber = ref("");
const smsVerificationCode = ref("");
const showSmsSetupModal = ref(false);
const smsSetupStep = ref("phone"); // 'phone', 'verify'
const isEnablingSms = ref(false);
const isSendingSmsCode = ref(false);
const isVerifyingSmsCode = ref(false);
const isDisablingSms = ref(false);
const smsResendCountdown = ref(0);
let smsCountdownInterval = null;

// Email 2FA state
const isEnablingEmail = ref(false);
const isDisablingEmail = ref(false);

// Computed safe accessors for 2FA methods
const isTotpEnabled = computed(
    () => twoFactorStatus.value.enabledMethods?.includes("totp") ?? false
);
const isSmsEnabled = computed(
    () => twoFactorStatus.value.enabledMethods?.includes("sms") ?? false
);
const isEmailEnabled = computed(
    () => twoFactorStatus.value.enabledMethods?.includes("email") ?? false
);

// File Upload Refs
const avatarInput = ref(null);
const coverInput = ref(null);
// We no longer upload immediately, so we track selection
const selectedAvatar = ref(null);
const avatarPreview = ref(null);
const selectedCover = ref(null);
const coverPreview = ref(null);

// Initialize profile from auth store
const initProfile = () => {
    if (authStore.user) {
        profile.value = {
            name: authStore.user.name || "",
            username: authStore.user.username || "",
            email: authStore.user.email || "",
            title: authStore.user.title || "",
            bio: authStore.user.bio || "",
            location: authStore.user.location || "",
            website: authStore.user.website || "",
            skills: authStore.user.skills ? [...authStore.user.skills] : [],
        };

        // Load preferences from user
        if (authStore.user.preferences) {
            if (authStore.user.preferences.notifications) {
                // ... (existing code) ...
            }
            if (authStore.user.preferences.appearance) {
                appearance.value = {
                    ...appearance.value,
                    ...authStore.user.preferences.appearance,
                };
            }
        }
    }
};

// ...



// Save profile
const saveProfile = async () => {
    isSaving.value = true;

    // Add any pending skill in the input
    if (newSkill.value.trim()) {
        addSkill();
    }

    try {
        // Capture original email to detect change
        const originalEmail = authStore.user.email;

        // Upload Avatar if changed
        if (selectedAvatar.value) {
            const formData = new FormData();
            formData.append("avatar", selectedAvatar.value);
            await api.post("/api/user/avatar", formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });
        }

        // Upload Cover if changed
        if (selectedCover.value) {
            const formData = new FormData();
            formData.append("cover", selectedCover.value);
            await api.post("/api/user/cover", formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });
        }

        await api.put("/api/user/profile", {
            name: profile.value.name,
            username: profile.value.username,
            email: profile.value.email,
            title: profile.value.title,
            bio: profile.value.bio,
            location: profile.value.location,
            website: profile.value.website,
            skills: profile.value.skills,
        });

        await authStore.fetchUser();

        // Check if email changed and redirect to verification
        if (originalEmail !== profile.value.email) {
            router.push({ name: "verification.notice" });
            return;
        }

        // Reset selections
        selectedAvatar.value = null;
        avatarPreview.value = null;
        selectedCover.value = null;
        coverPreview.value = null;

        toast.success("Profile updated successfully");
    } catch (error) {
        toast.error(
            error.response?.data?.message || "Failed to update profile"
        );
    } finally {
        isSaving.value = false;
    }
};

const triggerAvatarUpload = () => {
    avatarInput.value.click();
};

const triggerCoverUpload = () => {
    coverInput.value.click();
};

const handleAvatarUpload = (event) => {
    const file = event.target.files[0];
    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
        toast.error("Avatar must be less than 2MB");
        return;
    }

    selectedAvatar.value = file;
    avatarPreview.value = URL.createObjectURL(file);
    // Reset input so same file can be selected again if needed (though unlikely)
    event.target.value = "";
};

const handleCoverUpload = (event) => {
    const file = event.target.files[0];
    if (!file) return;

    if (file.size > 4 * 1024 * 1024) {
        toast.error("Cover photo must be less than 4MB");
        return;
    }

    selectedCover.value = file;
    coverPreview.value = URL.createObjectURL(file);
    event.target.value = "";
};

// Document Uploads
const documentInput = ref(null);
const isUploadingDocument = ref(false);

const handleDocumentUpload = async (event) => {
    const file = event.target.files[0];
    if (!file) return;

    if (file.size > 10 * 1024 * 1024) {
        toast.error("Document must be less than 10MB");
        return;
    }

    const formData = new FormData();
    formData.append("document", file);

    isUploadingDocument.value = true;
    try {
        await api.post("/api/user/documents", formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });
        await authStore.fetchUser();
        // We also need to re-fetch details if we want the files list to update immediately if it relies on a separate endpoint?
        // Actually UserResource includes 'files' when 'media' is loaded.
        // But authStore.fetchUser calls /api/user which might not include media by default unless we updated AuthController too?
        // Let's check AuthController... It returns UserResource. UserResource includes 'files' if loaded.
        // AuthController::user() uses UserResource.
        // The UserResource whenLoaded('media') check...
        // We need to ensure /api/user loads media.
        // Wait, AuthController just does $request->user().
        // We should explicitly update the local files list if possible or ensure user object has it.
        // For now let's assume we can fetch details to get updated files list.
        await fetchUserDetails();
        toast.success("Document uploaded successfully");
    } catch (error) {
        console.error(error);
        toast.error("Failed to upload document");
    } finally {
        isUploadingDocument.value = false;
        event.target.value = "";
    }
};

const deleteMedia = async (mediaId) => {
    if (!confirm("Are you sure you want to delete this file?")) return;

    try {
        await api.delete(`/api/user/media/${mediaId}`);
        await fetchUserDetails(); // Refresh list
        toast.success("File deleted");
    } catch (error) {
        toast.error("Failed to delete file");
    }
};

// We need a way to get the files list in SettingsView.
// ProfileView uses /api/user/details. SettingsView relies on authStore.
// Let's add fetchUserDetails here too or just use the one from api/user if we update it.
// I'll add a simple local fetch for now to keep it consistent with ProfileView logic for files.
const userFiles = ref([]);
const files = computed(() => userFiles.value);

const fetchUserDetails = async () => {
    try {
        const response = await api.get("/api/user/details");
        userFiles.value = response.data.files || [];
        // Also update authStore user to keep everything in sync if needed
        // authStore.user = response.data; // Only if compatible
    } catch (error) {
        console.error("Failed to fetch user details for files");
    }
};

// Change/Setup password
const changePassword = async () => {
    isChangingPassword.value = true;
    try {
        if (authStore.user?.is_password_set) {
            // Standard Change Password
            await api.put("/api/user/password", passwordForm.value);
            toast.success("Password updated successfully");
        } else {
            // Setup Password
            await api.post("/api/user/setup-password", {
                password: passwordForm.value.password,
                password_confirmation: passwordForm.value.password_confirmation,
            });
            toast.success("Password set successfully");
        }

        await authStore.fetchUser();

        passwordForm.value = {
            current_password: "",
            password: "",
            password_confirmation: "",
        };
    } catch (error) {
        const message =
            error.response?.data?.errors?.current_password?.[0] ||
            error.response?.data?.message ||
            "Failed to update password";
        toast.error(message);
    } finally {
        isChangingPassword.value = false;
    }
};

// Save notification preferences
const saveNotifications = debounce(async () => {
    try {
        await api.put("/api/user/preferences", {
            notifications: notifications.value,
        });
        toast.success("Notification preferences saved");
    } catch (error) {
        toast.error("Failed to save preferences");
    }
}, 1000);

// Save appearance preferences
const saveAppearance = debounce(async () => {
    try {
        await api.put("/api/user/preferences", {
            appearance: appearance.value,
        });
    } catch (error) {
        console.error("Failed to save appearance preferences");
    }
}, 1000);

// Watch notification changes and auto-save
watch(notifications, saveNotifications, { deep: true });

// Update theme
const updateTheme = (mode) => {
    appearance.value.mode = mode;
    themeStore.setMode(mode);
    // Removed explicit saveAppearance() as themeStore.setMode handles it with debounce
};

const updateColorTheme = (color) => {
    themeStore.setThemeColor(color);
    // We could save this to appearance.value too if backend supported it,
    // but for now it's persisted in Pinia/LocalStorage.
};

// Fetch sessions
const fetchSessions = async () => {
    isLoadingSessions.value = true;
    try {
        const response = await api.get("/api/user/sessions");
        sessions.value = response.data;
    } catch (error) {
        console.error("Failed to fetch sessions");
    } finally {
        isLoadingSessions.value = false;
    }
};

// Revoke a session
const revokeSession = async (sessionId) => {
    isRevokingSession.value = sessionId;
    try {
        await api.delete(`/api/user/sessions/${sessionId}`);
        sessions.value = sessions.value.filter((s) => s.id !== sessionId);
        toast.success("Session revoked");
    } catch (error) {
        toast.error(
            error.response?.data?.message || "Failed to revoke session"
        );
    } finally {
        isRevokingSession.value = null;
    }
};

// Revoke all other sessions
const revokeAllSessions = async () => {
    if (!confirm("Are you sure you want to log out of all other devices?"))
        return;

    try {
        await api.delete("/api/user/sessions");
        await fetchSessions();
        toast.success("All other sessions revoked");
    } catch (error) {
        toast.error("Failed to revoke sessions");
    }
};

// Fetch social accounts
const fetchSocialAccounts = async () => {
    isLoadingSocials.value = true;
    try {
        const response = await api.get("/api/user/social-accounts");
        socialAccounts.value = response.data;
    } catch (error) {
        console.error("Failed to fetch social accounts");
    } finally {
        isLoadingSocials.value = false;
    }
};

// Disconnect social account
const disconnectSocial = async (provider) => {
    if (
        !confirm(
            `Disconnect ${provider}? You'll need to use your password to login.`
        )
    )
        return;

    try {
        await api.delete(`/api/user/social-accounts/${provider}`);
        socialAccounts.value = socialAccounts.value.filter(
            (a) => a.provider !== provider
        );
        toast.success(`${provider} disconnected`);
    } catch (error) {
        toast.error(error.response?.data?.message || "Failed to disconnect");
    }
};

// 2FA Functions
const fetch2FAStatus = async () => {
    try {
        const response = await api.get("/api/user/two-factor-status");
        twoFactorStatus.value = {
            enabled: response.data.enabled || false,
            confirmed: response.data.confirmed || false,
            totpPending: response.data.totp_pending || false,
            smsPending: response.data.sms_pending || false,
            enabledMethods: response.data.enabled_methods || [],
            primaryMethods: response.data.primary_methods || [],
            emailIsFallback: response.data.email_is_fallback || false,
            phone: response.data.phone || null,
        };
    } catch (error) {
        console.error("Failed to fetch 2FA status");
    }
};

const enable2FA = async () => {
    isEnabling2FA.value = true;
    try {
        const response = await api.post("/api/user/two-factor-authentication");
        twoFactorQrCode.value = response.data.qr_code || "";
        twoFactorSecret.value = response.data.secret || "";
        twoFAStep.value = "confirm";
        show2FAModal.value = true;
        showManualKey.value = false;
        await fetch2FAStatus();
    } catch (error) {
        toast.error(error.response?.data?.message || "Failed to enable 2FA");
    } finally {
        isEnabling2FA.value = false;
    }
};

const confirm2FA = async () => {
    if (!twoFactorConfirmCode.value) {
        toast.error("Please enter the verification code");
        return;
    }

    isConfirming2FA.value = true;
    try {
        await api.post("/api/user/confirmed-two-factor-authentication", {
            code: twoFactorConfirmCode.value,
        });
        // Fetch recovery codes
        const codesResponse = await api.get(
            "/api/user/two-factor-recovery-codes"
        );
        twoFactorRecoveryCodes.value = codesResponse.data || [];
        twoFAStep.value = "recovery";
        await fetch2FAStatus();
        toast.success("Two-factor authentication enabled");
    } catch (error) {
        toast.error(
            error.response?.data?.message || "Invalid verification code"
        );
    } finally {
        isConfirming2FA.value = false;
    }
};

// Disable 2FA - now shows password modal
const showDisable2FAModal = ref(false);
const disablePassword = ref("");

const disable2FA = async () => {
    if (!disablePassword.value) {
        toast.error("Please enter your password");
        return;
    }

    isDisabling2FA.value = true;
    try {
        await api.delete("/api/user/two-factor-authentication", {
            data: { password: disablePassword.value },
        });
        twoFactorStatus.value = {
            enabled: false,
            confirmed: false,
            totpPending: false,
            enabledMethods: [],
            primaryMethods: [],
            emailIsFallback: false,
            phone: null,
        };
        twoFactorQrCode.value = "";
        twoFactorRecoveryCodes.value = [];
        showDisable2FAModal.value = false;
        disablePassword.value = "";
        toast.success("Two-factor authentication disabled");
    } catch (error) {
        toast.error(
            error.response?.data?.errors?.password?.[0] ||
                error.response?.data?.message ||
                "Failed to disable 2FA"
        );
    } finally {
        isDisabling2FA.value = false;
    }
};

// Regenerate recovery codes
const isRegeneratingCodes = ref(false);

const regenerateRecoveryCodes = async () => {
    isRegeneratingCodes.value = true;
    try {
        const response = await api.post("/api/user/two-factor-recovery-codes");
        twoFactorRecoveryCodes.value = response.data || [];
        twoFAStep.value = "recovery";
        show2FAModal.value = true;
        toast.success("New recovery codes generated");
    } catch (error) {
        toast.error(
            error.response?.data?.message || "Failed to regenerate codes"
        );
    } finally {
        isRegeneratingCodes.value = false;
    }
};

const close2FAModal = () => {
    show2FAModal.value = false;
    twoFAStep.value = "setup";
    twoFactorConfirmCode.value = "";
};

const copyToClipboard = (text) => {
    if (navigator && navigator.clipboard) {
        navigator.clipboard.writeText(text);
        toast.success("Copied to clipboard");
    } else {
        // Fallback for older browsers or non-secure contexts
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand("copy");
            toast.success("Copied to clipboard");
        } catch (err) {
            toast.error("Failed to copy");
        }
        document.body.removeChild(textArea);
    }
};

// SMS 2FA Functions
const openSmsSetup = (step = "phone") => {
    // If called via event handler without args, step is an Event object
    if (typeof step !== "string") {
        step = "phone";
    }

    if (step === "phone") {
        smsPhoneNumber.value = "";
        smsVerificationCode.value = "";
    }
    smsSetupStep.value = step;
    showSmsSetupModal.value = true;
};

const closeSmsSetupModal = () => {
    showSmsSetupModal.value = false;
    smsPhoneNumber.value = "";
    smsVerificationCode.value = "";
    smsSetupStep.value = "phone";
};

const enableSms2FA = async () => {
    if (!smsPhoneNumber.value) {
        toast.error("Please enter a phone number");
        return;
    }

    isEnablingSms.value = true;
    try {
        await api.post("/api/user/two-factor-sms", {
            phone: smsPhoneNumber.value,
        });
        // Send verification code
        await api.post("/api/user/two-factor-sms/verify/send");
        smsSetupStep.value = "verify";
        startSmsCountdown();
        toast.success("Verification code sent to your phone");
    } catch (error) {
        toast.error(error.response?.data?.message || "Failed to setup SMS 2FA");
    } finally {
        isEnablingSms.value = false;
    }
};

const resendSmsCode = async () => {
    if (smsResendCountdown.value > 0) return;
    
    isSendingSmsCode.value = true;
    try {
        await api.post("/api/user/two-factor-sms/verify/send");
        startSmsCountdown();
        toast.success("Verification code resent");
    } catch (error) {
        if (error.response?.status === 429) {
            // Rate limited - get retry_after from response
            const retryAfter = error.response?.data?.retry_after || 60;
            smsResendCountdown.value = retryAfter;
            startSmsCountdown();
            toast.error(`Please wait ${retryAfter} seconds before requesting another code`);
        } else {
            toast.error(error.response?.data?.message || "Failed to send code");
        }
    } finally {
        isSendingSmsCode.value = false;
    }
};

const startSmsCountdown = () => {
    if (smsResendCountdown.value <= 0) {
        smsResendCountdown.value = 60;
    }
    if (smsCountdownInterval) {
        clearInterval(smsCountdownInterval);
    }
    smsCountdownInterval = setInterval(() => {
        smsResendCountdown.value--;
        if (smsResendCountdown.value <= 0) {
            clearInterval(smsCountdownInterval);
            smsCountdownInterval = null;
        }
    }, 1000);
};

const verifySmsCode = async () => {
    if (!smsVerificationCode.value || smsVerificationCode.value.length !== 6) {
        toast.error("Please enter a 6-digit code");
        return;
    }

    isVerifyingSmsCode.value = true;
    try {
        await api.post("/api/user/two-factor-sms/verify", {
            code: smsVerificationCode.value,
        });
        toast.success("SMS two-factor authentication enabled");
        closeSmsSetupModal();
        await fetch2FAStatus();
    } catch (error) {
        toast.error(
            error.response?.data?.message || "Invalid verification code"
        );
    } finally {
        isVerifyingSmsCode.value = false;
    }
};

const disableSms2FA = async () => {
    isDisablingSms.value = true;
    try {
        await api.delete("/api/user/two-factor-sms");
        toast.success("SMS two-factor authentication disabled");
        await fetch2FAStatus();
    } catch (error) {
        toast.error(
            error.response?.data?.message || "Failed to disable SMS 2FA"
        );
    } finally {
        isDisablingSms.value = false;
    }
};

// Email 2FA Functions
const enableEmail2FA = async () => {
    isEnablingEmail.value = true;
    try {
        await api.post("/api/user/two-factor-email");
        toast.success("Email two-factor authentication enabled");
        await fetch2FAStatus();
    } catch (error) {
        toast.error(
            error.response?.data?.message || "Failed to enable Email 2FA"
        );
    } finally {
        isEnablingEmail.value = false;
    }
};

const disableEmail2FA = async () => {
    isDisablingEmail.value = true;
    try {
        await api.delete("/api/user/two-factor-email");
        toast.success("Email two-factor authentication disabled");
        await fetch2FAStatus();
    } catch (error) {
        toast.error(
            error.response?.data?.message || "Failed to disable Email 2FA"
        );
    } finally {
        isDisablingEmail.value = false;
    }
};

// On tab change, load relevant data
watch(activeTab, (tab) => {
    if (tab === "security") {
        if (sessions.value.length === 0) fetchSessions();
        fetch2FAStatus();
        fetchPasskeys();
        checkPasskeySupport();
    }
    if (tab === "connections" && socialAccounts.value.length === 0) {
        fetchSocialAccounts();
    }
});

const route = useRoute();

onMounted(() => {
    initProfile();
    fetch2FAStatus();
    fetchUserDetails(); // Fetch files and other details
    checkPasskeySupport(); // Check if passkeys are supported

    // Check for tab query param
    if (route.query.tab && tabs.some((t) => t.id === route.query.tab)) {
        activeTab.value = route.query.tab;
    }
});
</script>

<template>
    <div class="w-full space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                Settings
            </h1>
            <p class="text-[var(--text-secondary)] mt-1">
                Manage your account settings and preferences.
            </p>
        </div>

        <!-- Tabs -->
        <div
            class="flex gap-1 border-b border-[var(--border-default)] overflow-x-auto overflow-y-hidden [&::-webkit-scrollbar]:hidden [-ms-overflow-style:none] [scrollbar-width:none]"
        >
            <button
                v-for="tab in tabs"
                :key="tab.id"
                :class="[
                    'flex items-center gap-2 px-4 py-3 text-sm font-medium transition-colors whitespace-nowrap border-b-2 -mb-px',
                    activeTab === tab.id
                        ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]'
                        : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-strong)]',
                ]"
                @click="activeTab = tab.id"
            >
                <component :is="tab.icon" class="h-4 w-4" />
                {{ tab.label }}
            </button>
        </div>

        <!-- Tab Content -->
        <Transition name="fade" mode="out-in">
            <!-- Profile Tab -->
            <div v-if="activeTab === 'profile'" key="profile" class="space-y-6">
                <Card padding="lg">
                    <h3
                        class="text-lg font-semibold text-[var(--text-primary)] mb-6"
                    >
                        Profile Information
                    </h3>

                    <!-- Cover Photo & Avatar -->
                    <div class="mb-8">
                        <label
                            class="block text-sm font-medium text-[var(--text-primary)] mb-3"
                            >Profile Images</label
                        >

                        <div
                            class="relative group h-32 w-full rounded-xl overflow-hidden bg-[var(--surface-secondary)] border border-[var(--border-default)]"
                        >
                            <img
                                v-if="
                                    coverPreview ||
                                    authStore.user?.cover_photo_url
                                "
                                :src="
                                    coverPreview ||
                                    authStore.user.cover_photo_url
                                "
                                class="w-full h-full object-cover"
                                alt="Cover Photo"
                            />
                            <div
                                v-else
                                class="w-full h-full bg-gradient-to-r from-[var(--color-primary-500)] to-[var(--color-primary-700)]"
                            ></div>

                            <Button
                                variant="ghost"
                                size="sm"
                                class="absolute top-4 right-4 bg-black/20 text-white hover:bg-black/30 backdrop-blur-sm opacity-0 group-hover:opacity-100 transition-opacity"
                                @click="triggerCoverUpload"
                            >
                                <Camera class="h-4 w-4 mr-2" />
                                Change cover
                            </Button>
                        </div>

                        <div
                            class="flex items-end gap-6 px-4 -mt-10 relative z-10"
                        >
                            <div class="relative group">
                                <Avatar
                                    :fallback="authStore.initials"
                                    :src="
                                        avatarPreview ||
                                        authStore.user?.avatar_url
                                    "
                                    size="3xl"
                                    class="border-4 border-[var(--surface-primary)] shadow-md bg-[var(--surface-primary)]"
                                    :status="authStore.user?.presence"
                                />
                                <button
                                    class="absolute bottom-0 right-0 flex h-7 w-7 items-center justify-center rounded-full bg-[var(--surface-elevated)] border border-[var(--border-default)] text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors shadow-sm cursor-pointer"
                                    @click="triggerAvatarUpload"
                                >
                                    <Camera class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <div class="pb-2">
                                <p
                                    class="text-[var(--text-secondary)] text-sm mb-1"
                                >
                                    Max file size: Avatar (2MB), Cover (4MB)
                                </p>
                            </div>
                        </div>

                        <!-- Hidden File Inputs -->
                        <input
                            type="file"
                            ref="avatarInput"
                            accept="image/*"
                            class="hidden"
                            @change="handleAvatarUpload"
                        />
                        <input
                            type="file"
                            ref="coverInput"
                            accept="image/*"
                            class="hidden"
                            @change="handleCoverUpload"
                        />
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="space-y-4">
                            <Input
                                v-model="profile.name"
                                label="Full Name"
                                placeholder="Your name"
                            />
                            <Input
                                v-model="profile.title"
                                label="Job Title"
                                placeholder="e.g. Senior Developer"
                            />
                            <div class="space-y-1.5">
                                <label
                                    class="block text-sm font-medium text-[var(--text-primary)]"
                                    >Username</label
                                >
                                <div class="flex">
                                    <span
                                        class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-[var(--border-default)] bg-[var(--surface-secondary)] text-[var(--text-muted)] text-sm"
                                        >@</span
                                    >
                                    <input
                                        v-model="profile.username"
                                        type="text"
                                        class="flex-1 rounded-r-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3.5 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20"
                                        placeholder="username"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <Input
                                v-model="profile.location"
                                label="Location"
                                placeholder="e.g. San Francisco, CA"
                            />
                            <Input
                                v-model="profile.website"
                                label="Website"
                                placeholder="https://"
                            />
                        </div>

                        <div class="sm:col-span-2">
                            <label
                                class="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
                                >Bio</label
                            >
                            <textarea
                                v-model="profile.bio"
                                rows="3"
                                class="w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3.5 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 resize-none"
                                placeholder="Tell us a little about yourself"
                                maxlength="1000"
                            ></textarea>
                            <p
                                class="text-xs text-[var(--text-muted)] mt-1 ml-1 text-right"
                            >
                                {{ profile.bio.length }}/1000 characters
                            </p>
                        </div>

                        <div class="sm:col-span-2">
                            <label
                                class="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
                                >Skills</label
                            >
                            <div
                                class="w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3.5 py-2.5 text-sm text-[var(--text-primary)] focus-within:border-[var(--interactive-primary)] focus-within:ring-2 focus-within:ring-[var(--interactive-primary)]/20 transition-all"
                            >
                                <div
                                    class="flex flex-wrap gap-2 mb-2"
                                    v-if="profile.skills.length > 0"
                                >
                                    <Badge
                                        v-for="(skill, index) in profile.skills"
                                        :key="index"
                                        variant="secondary"
                                        size="md"
                                        class="pl-2 pr-1 py-1"
                                    >
                                        {{ skill }}
                                        <button
                                            @click="removeSkill(index)"
                                            class="ml-1 p-0.5 rounded-full hover:bg-black/10 text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors"
                                        >
                                            <X class="h-3 w-3" />
                                        </button>
                                    </Badge>
                                </div>
                                <input
                                    v-model="newSkill"
                                    @keydown.enter.prevent="addSkill"
                                    @keydown.backspace="
                                        newSkill === '' &&
                                        profile.skills.length > 0
                                            ? removeSkill(
                                                  profile.skills.length - 1
                                              )
                                            : null
                                    "
                                    type="text"
                                    class="w-full bg-transparent border-none outline-none placeholder:text-[var(--text-muted)] p-0"
                                    placeholder="Type a skill and press Enter..."
                                />
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label
                            class="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
                            >Email</label
                        >
                        <div class="flex items-center gap-2">
                            <Input
                                v-model="profile.email"
                                type="email"
                                placeholder="Your email"
                                class="flex-1"
                            />
                            <Badge
                                v-if="authStore.user?.email_verified_at"
                                variant="success"
                                size="sm"
                            >
                                <Check class="w-3 h-3 mr-1" /> Verified
                            </Badge>
                            <Badge v-else variant="warning" size="sm"
                                >Unverified</Badge
                            >
                        </div>
                        <p class="text-xs text-[var(--text-muted)] mt-1">
                            Changing email will require re-verification.
                        </p>
                    </div>

                    <!-- Recent Files Management -->
                    <div
                        class="mt-6 border-t border-[var(--border-default)] pt-6"
                    >
                        <div class="flex items-center justify-between mb-4">
                            <h3
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                Recent Files
                            </h3>
                            <Button
                                size="sm"
                                variant="outline"
                                @click="$refs.documentInput.click()"
                                :loading="isUploadingDocument"
                            >
                                <Plus class="h-4 w-4 mr-2" />
                                Upload Document
                            </Button>
                            <input
                                type="file"
                                ref="documentInput"
                                class="hidden"
                                @change="handleDocumentUpload"
                            />
                        </div>

                        <div v-if="files.length > 0" class="space-y-2">
                            <div
                                v-for="file in files"
                                :key="file.id"
                                class="flex items-center justify-between p-3 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]"
                            >
                                <div
                                    class="flex items-center gap-3 overflow-hidden"
                                >
                                    <div
                                        class="h-8 w-8 rounded bg-[var(--surface-elevated)] flex items-center justify-center shrink-0 border border-[var(--border-default)]"
                                    >
                                        <FileText
                                            class="h-4 w-4 text-[var(--text-secondary)]"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <p
                                            class="text-sm font-medium text-[var(--text-primary)] truncate"
                                        >
                                            {{ file.name }}
                                        </p>
                                        <p
                                            class="text-xs text-[var(--text-muted)]"
                                        >
                                            {{ (file.size / 1024).toFixed(1) }}
                                            KB
                                        </p>
                                    </div>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
                                    @click="deleteMedia(file.id)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                        <div
                            v-else
                            class="text-center py-4 text-xs text-[var(--text-muted)] italic"
                        >
                            No documents uploaded.
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <Button @click="saveProfile" :loading="isSaving"
                            >Save changes</Button
                        >
                    </div>
                </Card>

                <!-- Danger Zone -->
                <Card padding="lg" class="border-red-200 dark:border-red-900">
                    <h3
                        class="text-lg font-semibold text-[var(--color-error)] mb-2"
                    >
                        Danger Zone
                    </h3>
                    <p class="text-sm text-[var(--text-secondary)] mb-4">
                        Once you delete your account, there is no going back.
                        Please be certain.
                    </p>
                    <Button variant="danger">
                        <Trash2 class="h-4 w-4" />
                        Delete account
                    </Button>
                </Card>
            </div>

            <!-- Security Tab -->
            <div
                v-else-if="activeTab === 'security'"
                key="security"
                class="space-y-6"
            >
                <!-- Password Change -->
                <Card padding="lg">
                    <h3
                        class="text-lg font-semibold text-[var(--text-primary)] mb-6"
                    >
                        {{
                            authStore.user?.is_password_set
                                ? "Change Password"
                                : "Setup Password"
                        }}
                    </h3>

                    <div class="space-y-4 max-w-md">
                        <div
                            v-if="authStore.user?.is_password_set"
                            class="space-y-1.5"
                        >
                            <label
                                class="block text-sm font-medium text-[var(--text-primary)]"
                                >Current Password</label
                            >
                            <div class="relative">
                                <input
                                    v-model="passwordForm.current_password"
                                    :type="
                                        showCurrentPassword
                                            ? 'text'
                                            : 'password'
                                    "
                                    class="w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3.5 py-2.5 pr-10 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20"
                                    placeholder="Enter current password"
                                />
                                <button
                                    type="button"
                                    @click="
                                        showCurrentPassword =
                                            !showCurrentPassword
                                    "
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                                >
                                    <component
                                        :is="showCurrentPassword ? EyeOff : Eye"
                                        class="w-4 h-4"
                                    />
                                </button>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label
                                class="block text-sm font-medium text-[var(--text-primary)]"
                                >New Password</label
                            >
                            <div class="relative">
                                <input
                                    v-model="passwordForm.password"
                                    :type="
                                        showNewPassword ? 'text' : 'password'
                                    "
                                    class="w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3.5 py-2.5 pr-10 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20"
                                    placeholder="Enter new password"
                                />
                                <button
                                    type="button"
                                    @click="showNewPassword = !showNewPassword"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                                >
                                    <component
                                        :is="showNewPassword ? EyeOff : Eye"
                                        class="w-4 h-4"
                                    />
                                </button>
                            </div>
                            <PasswordStrengthMeter
                                :password="passwordForm.password"
                            />
                        </div>
                        <Input
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            label="Confirm New Password"
                            placeholder="Confirm new password"
                        />
                    </div>

                    <div class="mt-6">
                        <Button
                            @click="changePassword"
                            :loading="isChangingPassword"
                            :disabled="
                                (authStore.user?.is_password_set &&
                                    !passwordForm.current_password) ||
                                !passwordForm.password ||
                                !passwordForm.password_confirmation
                            "
                        >
                            Update password
                        </Button>
                    </div>
                </Card>

                <!-- Passkeys -->
                <Card padding="lg">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3
                                class="text-lg font-semibold text-[var(--text-primary)]"
                            >
                                Passkeys
                            </h3>
                            <p
                                class="text-sm text-[var(--text-secondary)] mt-1"
                            >
                                Sign in with fingerprint, face recognition, or
                                security key
                            </p>
                        </div>
                        <Button
                            v-if="passkeySupported"
                            size="sm"
                            @click="startPasskeyRegistration"
                            :loading="isRegisteringPasskey"
                        >
                            <Plus class="w-4 h-4 mr-2" />
                            Add Passkey
                        </Button>
                    </div>

                    <!-- Not Supported Warning -->
                    <div
                        v-if="!passkeySupported"
                        class="p-4 rounded-lg bg-yellow-500/10 border border-yellow-500/30"
                    >
                        <p class="text-sm text-yellow-600 dark:text-yellow-400">
                            Passkeys are not supported in this browser or
                            device. Try using Chrome, Safari, or Edge on a
                            device with biometric authentication.
                        </p>
                    </div>

                    <!-- Loading -->
                    <div
                        v-else-if="isLoadingPasskeys"
                        class="flex items-center justify-center py-8"
                    >
                        <Loader2
                            class="w-6 h-6 animate-spin text-[var(--text-muted)]"
                        />
                    </div>

                    <!-- Empty State -->
                    <div
                        v-else-if="passkeys.length === 0"
                        class="text-center py-8"
                    >
                        <Lock
                            class="w-12 h-12 mx-auto text-[var(--text-muted)] mb-3"
                        />
                        <p class="text-[var(--text-secondary)]">
                            No passkeys registered
                        </p>
                        <p class="text-sm text-[var(--text-muted)] mt-1">
                            Add a passkey for faster, more secure logins
                        </p>
                    </div>

                    <!-- Passkeys List -->
                    <div v-else class="space-y-3">
                        <div
                            v-for="passkey in passkeys"
                            :key="passkey.id"
                            class="flex items-center justify-between p-4 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]/50"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="p-2.5 rounded-lg bg-[var(--surface-secondary)]"
                                >
                                    <Lock
                                        class="w-5 h-5 text-[var(--text-secondary)]"
                                    />
                                </div>
                                <div>
                                    <p
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                    >
                                        {{ passkey.name }}
                                    </p>
                                    <p class="text-xs text-[var(--text-muted)]">
                                        Added
                                        {{
                                            new Date(
                                                passkey.created_at
                                            ).toLocaleDateString()
                                        }}
                                    </p>
                                </div>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-red-500 hover:text-red-600 hover:bg-red-500/10"
                                @click="deletePasskey(passkey.id)"
                            >
                                <Trash2 class="w-4 h-4" />
                            </Button>
                        </div>
                    </div>
                </Card>

                <!-- Two-Factor Authentication -->
                <Card padding="lg">
                    <h3
                        class="text-lg font-semibold text-[var(--text-primary)] mb-6"
                    >
                        Two-Factor Authentication
                    </h3>

                    <div class="space-y-6">
                        <!-- Authenticator App (TOTP) -->
                        <div
                            class="flex items-start justify-between p-4 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]"
                        >
                            <div class="space-y-1">
                                <p
                                    class="text-sm font-medium text-[var(--text-primary)] flex items-center gap-2"
                                >
                                    <Shield class="w-4 h-4" />
                                    Authenticator App
                                    <Badge
                                        v-if="isTotpEnabled"
                                        variant="success"
                                        size="sm"
                                        >Enabled</Badge
                                    >
                                    <Badge
                                        v-else-if="twoFactorStatus.totpPending"
                                        variant="warning"
                                        size="sm"
                                        >Pending Confirmation</Badge
                                    >
                                </p>
                                <p class="text-xs text-[var(--text-secondary)]">
                                    Use Google Authenticator, Authy, or similar
                                    apps
                                </p>
                            </div>
                            <Button
                                v-if="
                                    !isTotpEnabled &&
                                    !twoFactorStatus.totpPending
                                "
                                variant="outline"
                                size="sm"
                                @click="enable2FA"
                                :loading="isEnabling2FA"
                            >
                                Setup
                            </Button>
                            <Button
                                v-else-if="twoFactorStatus.totpPending"
                                variant="outline"
                                size="sm"
                                @click="enable2FA"
                            >
                                Continue Setup
                            </Button>
                            <div v-else class="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="regenerateRecoveryCodes"
                                    :loading="isRegeneratingCodes"
                                >
                                    Recovery Codes
                                </Button>
                                <Button
                                    variant="danger"
                                    size="sm"
                                    @click="showDisable2FAModal = true"
                                >
                                    Disable
                                </Button>
                            </div>
                        </div>

                        <!-- SMS Authentication -->
                        <div
                            class="flex items-start justify-between p-4 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]"
                        >
                            <div class="space-y-1">
                                <p
                                    class="text-sm font-medium text-[var(--text-primary)] flex items-center gap-2"
                                >
                                    <Smartphone class="w-4 h-4" />
                                    SMS Authentication
                                    <Badge
                                        v-if="isSmsEnabled"
                                        variant="success"
                                        size="sm"
                                        >Enabled</Badge
                                    >
                                    <Badge
                                        v-else-if="twoFactorStatus.smsPending"
                                        variant="warning"
                                        size="sm"
                                        >Pending Confirmation</Badge
                                    >
                                </p>
                                <p class="text-xs text-[var(--text-secondary)]">
                                    <span v-if="twoFactorStatus.phone"
                                        >Phone:
                                        {{ twoFactorStatus.phone }}</span
                                    >
                                    <span v-else
                                        >Receive codes via SMS to your
                                        phone</span
                                    >
                                </p>
                            </div>
                            <Button
                                v-if="
                                    !isSmsEnabled && !twoFactorStatus.smsPending
                                "
                                variant="outline"
                                size="sm"
                                @click="openSmsSetup"
                            >
                                Setup
                            </Button>
                            <Button
                                v-else-if="twoFactorStatus.smsPending"
                                variant="outline"
                                size="sm"
                                @click="openSmsSetup('verify')"
                            >
                                Continue Setup
                            </Button>
                            <Button
                                v-else
                                variant="danger"
                                size="sm"
                                @click="disableSms2FA"
                                :loading="isDisablingSms"
                            >
                                Disable
                            </Button>
                        </div>

                        <!-- Email Authentication -->
                        <div
                            class="flex items-start justify-between p-4 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]"
                        >
                            <div class="space-y-1">
                                <p
                                    class="text-sm font-medium text-[var(--text-primary)] flex items-center gap-2"
                                >
                                    <Mail class="w-4 h-4" />
                                    Email Authentication
                                    <Badge
                                        v-if="twoFactorStatus.emailIsFallback"
                                        variant="secondary"
                                        size="sm"
                                        >Fallback</Badge
                                    >
                                    <Badge
                                        v-else-if="isEmailEnabled"
                                        variant="success"
                                        size="sm"
                                        >Enabled</Badge
                                    >
                                </p>
                                <p class="text-xs text-[var(--text-secondary)]">
                                    <template
                                        v-if="twoFactorStatus.emailIsFallback"
                                    >
                                        Automatically enabled as fallback when
                                        other methods are active
                                    </template>
                                    <template v-else>
                                        Receive codes via email (backup option)
                                    </template>
                                </p>
                            </div>
                            <!-- Only show enable/disable if NOT a fallback -->
                            <template v-if="!twoFactorStatus.emailIsFallback">
                                <Button
                                    v-if="!isEmailEnabled"
                                    variant="outline"
                                    size="sm"
                                    @click="enableEmail2FA"
                                    :loading="isEnablingEmail"
                                >
                                    Enable
                                </Button>
                                <Button
                                    v-else
                                    variant="danger"
                                    size="sm"
                                    @click="disableEmail2FA"
                                    :loading="isDisablingEmail"
                                >
                                    Disable
                                </Button>
                            </template>
                        </div>
                    </div>
                </Card>

                <!-- Active Sessions -->
                <Card padding="lg">
                    <div class="flex items-center justify-between mb-6">
                        <h3
                            class="text-lg font-semibold text-[var(--text-primary)]"
                        >
                            Active Sessions
                        </h3>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="revokeAllSessions"
                            :disabled="
                                sessions.filter((s) => !s.is_current_device)
                                    .length === 0
                            "
                        >
                            <LogOut class="w-4 h-4" />
                            Logout all others
                        </Button>
                    </div>

                    <div
                        v-if="isLoadingSessions"
                        class="flex items-center justify-center py-8"
                    >
                        <Loader2
                            class="w-6 h-6 animate-spin text-[var(--text-muted)]"
                        />
                    </div>

                    <div
                        v-else-if="sessions.length === 0"
                        class="text-center py-8 text-[var(--text-muted)]"
                    >
                        <p>No active sessions found.</p>
                    </div>

                    <div v-else class="space-y-4">
                        <div
                            v-for="session in sessions"
                            :key="session.id"
                            class="flex items-center justify-between p-4 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="p-2 rounded-lg bg-[var(--surface-elevated)]"
                                >
                                    <component
                                        :is="
                                            session.device?.is_phone ||
                                            session.device?.device === 'Mobile'
                                                ? Smartphone
                                                : Monitor
                                        "
                                        class="w-5 h-5 text-[var(--text-secondary)]"
                                    />
                                </div>
                                <div>
                                    <p
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                    >
                                        {{
                                            session.device?.browser ||
                                            "Unknown Browser"
                                        }}
                                        on
                                        {{
                                            session.device?.platform ||
                                            "Unknown OS"
                                        }}
                                        <Badge
                                            v-if="session.is_current_device"
                                            variant="success"
                                            size="sm"
                                            class="ml-2"
                                            >Current</Badge
                                        >
                                    </p>
                                    <p
                                        class="text-xs text-[var(--text-muted)] mt-0.5"
                                    >
                                        <span v-if="session.location">
                                            {{ session.location.city }},
                                            {{ session.location.country }} ·
                                        </span>
                                        {{ session.ip_address }} · Last active
                                        {{ session.last_activity }}
                                    </p>
                                </div>
                            </div>
                            <Button
                                v-if="!session.is_current_device"
                                variant="ghost"
                                size="sm"
                                @click="revokeSession(session.id)"
                                :loading="isRevokingSession === session.id"
                            >
                                <X class="w-4 h-4" />
                            </Button>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Notifications Tab -->
            <div
                v-else-if="activeTab === 'notifications'"
                key="notifications"
                class="space-y-6"
            >
                <Card padding="lg">
                    <h3
                        class="text-lg font-semibold text-[var(--text-primary)] mb-6"
                    >
                        Notification Preferences
                    </h3>

                    <div class="space-y-6">
                        <Switch
                            v-model="notifications.email"
                            label="Email notifications"
                            description="Receive notifications via email"
                        />
                        <Switch
                            v-model="notifications.push"
                            label="Push notifications"
                            description="Receive push notifications in your browser"
                        />
                        <Switch
                            v-model="notifications.mentions"
                            label="Mentions"
                            description="Get notified when someone mentions you"
                        />
                        <Switch
                            v-model="notifications.tasks"
                            label="Task updates"
                            description="Get notified about task assignments and updates"
                        />
                        <Switch
                            v-model="notifications.updates"
                            label="Product updates"
                            description="News about product and feature updates"
                        />
                        <Switch
                            v-model="notifications.marketing"
                            label="Marketing emails"
                            description="Receive emails about new features and offers"
                        />
                    </div>
                </Card>
            </div>

            <!-- Appearance Tab -->
            <div
                v-else-if="activeTab === 'appearance'"
                key="appearance"
                class="space-y-6"
            >
                <Card padding="lg">
                    <h3
                        class="text-lg font-semibold text-[var(--text-primary)] mb-6"
                    >
                        Theme Settings
                    </h3>

                    <div class="space-y-6">
                        <div>
                            <label
                                class="block text-sm font-medium text-[var(--text-primary)] mb-3"
                                >Mode</label
                            >
                            <div class="grid gap-3 sm:grid-cols-3">
                                <button
                                    v-for="theme in ['light', 'dark', 'system']"
                                    :key="theme"
                                    :class="[
                                        'flex flex-col items-center gap-3 p-4 rounded-xl border-2 transition-all',
                                        appearance.theme === theme
                                            ? 'border-[var(--interactive-primary)] bg-[var(--color-primary-50)] dark:bg-[var(--color-primary-900)]/20'
                                            : 'border-[var(--border-default)] hover:border-[var(--border-strong)]',
                                    ]"
                                    @click="updateTheme(theme)"
                                >
                                    <div
                                        class="h-20 w-full rounded-lg overflow-hidden border border-[var(--border-default)]"
                                    >
                                        <div
                                            v-if="theme === 'light'"
                                            class="h-full bg-white"
                                        >
                                            <div class="h-4 bg-gray-100" />
                                        </div>
                                        <div
                                            v-else-if="theme === 'dark'"
                                            class="h-full bg-gray-900"
                                        >
                                            <div class="h-4 bg-gray-800" />
                                        </div>
                                        <div v-else class="h-full flex">
                                            <div class="w-1/2 bg-white">
                                                <div class="h-4 bg-gray-100" />
                                            </div>
                                            <div class="w-1/2 bg-gray-900">
                                                <div class="h-4 bg-gray-800" />
                                            </div>
                                        </div>
                                    </div>
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)] capitalize"
                                        >{{ theme }}</span
                                    >
                                </button>
                            </div>
                        </div>

                        <!-- Accent Color Section -->
                        <div>
                            <label
                                class="block text-sm font-medium text-[var(--text-primary)] mb-3"
                                >Accent Color</label
                            >
                            <div
                                class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3"
                            >
                                <button
                                    v-for="color in [
                                        'default',
                                        'ocean',
                                        'forest',
                                        'wine',
                                        'sunset',
                                        'midnight',
                                    ]"
                                    :key="color"
                                    :class="[
                                        'flex flex-col items-center gap-2 p-3 rounded-xl border-2 transition-all',
                                        themeStore.themeColor === color
                                            ? 'border-[var(--interactive-primary)] bg-[var(--surface-secondary)]'
                                            : 'border-[var(--border-default)] hover:border-[var(--border-strong)]',
                                    ]"
                                    @click="updateColorTheme(color)"
                                >
                                    <div
                                        :class="`h-10 w-10 rounded-full theme-${color} bg-[var(--color-primary-500)] shadow-sm`"
                                    />
                                    <span
                                        class="text-xs font-medium text-[var(--text-primary)] capitalize"
                                    >
                                        {{ color }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-lg font-semibold text-[var(--text-primary)] mb-6"
                    >
                        Accessibility
                    </h3>

                    <div class="space-y-6">
                        <Switch
                            v-model="appearance.reducedMotion"
                            label="Reduce motion"
                            description="Reduce the amount of animations"
                            @update:model-value="saveAppearance"
                        />
                        <Switch
                            v-model="appearance.compactMode"
                            label="Compact mode"
                            description="Use a more compact UI with less spacing"
                            @update:model-value="saveAppearance"
                        />
                    </div>
                </Card>
            </div>

            <!-- Connections Tab -->
            <div
                v-else-if="activeTab === 'connections'"
                key="connections"
                class="space-y-6"
            >
                <Card padding="lg">
                    <h3
                        class="text-lg font-semibold text-[var(--text-primary)] mb-6"
                    >
                        Connected Accounts
                    </h3>

                    <div
                        v-if="isLoadingSocials"
                        class="flex items-center justify-center py-8"
                    >
                        <Loader2
                            class="w-6 h-6 animate-spin text-[var(--text-muted)]"
                        />
                    </div>

                    <div
                        v-else-if="socialAccounts.length === 0"
                        class="text-center py-8"
                    >
                        <Link2
                            class="w-12 h-12 mx-auto text-[var(--text-muted)] mb-3"
                        />
                        <p class="text-[var(--text-secondary)]">
                            No connected accounts.
                        </p>
                        <p class="text-sm text-[var(--text-muted)] mt-1">
                            Connect a social account for easier login.
                        </p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="account in socialAccounts"
                            :key="account.provider"
                            class="flex items-center justify-between p-4 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]/50"
                        >
                            <div class="flex items-center gap-4">
                                <!-- Provider Icon -->
                                <div
                                    :class="[
                                        'p-2.5 rounded-lg',
                                        account.provider === 'google'
                                            ? 'bg-red-500/10'
                                            : account.provider === 'github'
                                            ? 'bg-gray-500/10'
                                            : account.provider === 'facebook'
                                            ? 'bg-blue-500/10'
                                            : 'bg-[var(--surface-secondary)]',
                                    ]"
                                >
                                    <!-- Google Icon -->
                                    <svg
                                        v-if="account.provider === 'google'"
                                        class="w-5 h-5"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            fill="#EA4335"
                                            d="M5.27 9.76A7.08 7.08 0 0 1 16.42 6.5L19.9 3A11.97 11.97 0 0 0 1.24 8.71l4.03 3.05z"
                                        />
                                        <path
                                            fill="#34A853"
                                            d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47a5.5 5.5 0 0 1-2.4 3.58l3.88 3c2.26-2.09 3.54-5.17 3.54-8.82z"
                                        />
                                        <path
                                            fill="#4A90E2"
                                            d="M5.27 14.24l-4.03 3.05a11.97 11.97 0 0 0 10.76 6.7c3.22 0 5.93-1.06 7.9-2.88l-3.88-3a7.14 7.14 0 0 1-10.75-3.87z"
                                        />
                                        <path
                                            fill="#FBBC05"
                                            d="M5.27 14.24A7.08 7.08 0 0 1 5.27 9.76l-4.03-3.05a11.97 11.97 0 0 0 0 10.58l4.03-3.05z"
                                        />
                                    </svg>
                                    <!-- GitHub Icon -->
                                    <svg
                                        v-else-if="
                                            account.provider === 'github'
                                        "
                                        class="w-5 h-5 text-[var(--text-primary)]"
                                        fill="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"
                                        />
                                    </svg>
                                    <!-- Facebook Icon -->
                                    <svg
                                        v-else-if="
                                            account.provider === 'facebook'
                                        "
                                        class="w-5 h-5 text-blue-600"
                                        fill="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073c0 6.026 4.388 11.017 10.125 11.927v-8.44H7.078v-3.487h3.047V9.43c0-3.007 1.79-4.668 4.533-4.668 1.312 0 2.686.235 2.686.235v2.953h-1.513c-1.49 0-1.956.927-1.956 1.874v2.25h3.328l-.532 3.487h-2.796v8.44C19.612 23.09 24 18.1 24 12.073z"
                                        />
                                    </svg>
                                    <!-- Default Icon -->
                                    <Link2
                                        v-else
                                        class="w-5 h-5 text-[var(--text-secondary)]"
                                    />
                                </div>
                                <div>
                                    <p
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                    >
                                        {{
                                            account.provider_name ||
                                            account.provider
                                        }}
                                    </p>
                                    <p
                                        v-if="account.provider_email"
                                        class="text-xs text-[var(--text-muted)]"
                                    >
                                        {{ account.provider_email }}
                                    </p>
                                    <p
                                        v-else
                                        class="text-xs text-[var(--text-muted)]"
                                    >
                                        Connected
                                        {{
                                            account.connected_at
                                                ? new Date(
                                                      account.connected_at
                                                  ).toLocaleDateString()
                                                : ""
                                        }}
                                    </p>
                                </div>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                class="text-red-500 hover:text-red-600 hover:border-red-300"
                                @click="disconnectSocial(account.provider)"
                            >
                                Disconnect
                            </Button>
                        </div>
                    </div>

                    <!-- Connect More Section -->
                    <div
                        class="mt-6 pt-6 border-t border-[var(--border-default)]"
                    >
                        <p class="text-sm text-[var(--text-secondary)] mb-4">
                            Connect more accounts for additional login options:
                        </p>
                        <div class="flex flex-wrap gap-3">
                            <Button
                                v-if="
                                    !socialAccounts.some(
                                        (a) => a.provider === 'google'
                                    )
                                "
                                variant="outline"
                                size="sm"
                                as="a"
                                href="/auth/google/redirect"
                                class="gap-2"
                            >
                                <svg class="w-4 h-4" viewBox="0 0 24 24">
                                    <path
                                        fill="#EA4335"
                                        d="M5.27 9.76A7.08 7.08 0 0 1 16.42 6.5L19.9 3A11.97 11.97 0 0 0 1.24 8.71l4.03 3.05z"
                                    />
                                    <path
                                        fill="#34A853"
                                        d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47a5.5 5.5 0 0 1-2.4 3.58l3.88 3c2.26-2.09 3.54-5.17 3.54-8.82z"
                                    />
                                    <path
                                        fill="#4A90E2"
                                        d="M5.27 14.24l-4.03 3.05a11.97 11.97 0 0 0 10.76 6.7c3.22 0 5.93-1.06 7.9-2.88l-3.88-3a7.14 7.14 0 0 1-10.75-3.87z"
                                    />
                                    <path
                                        fill="#FBBC05"
                                        d="M5.27 14.24A7.08 7.08 0 0 1 5.27 9.76l-4.03-3.05a11.97 11.97 0 0 0 0 10.58l4.03-3.05z"
                                    />
                                </svg>
                                Google
                            </Button>
                            <Button
                                v-if="
                                    !socialAccounts.some(
                                        (a) => a.provider === 'github'
                                    )
                                "
                                variant="outline"
                                size="sm"
                                as="a"
                                href="/auth/github/redirect"
                                class="gap-2"
                            >
                                <svg
                                    class="w-4 h-4"
                                    fill="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"
                                    />
                                </svg>
                                GitHub
                            </Button>
                            <span
                                v-if="socialAccounts.length >= 2"
                                class="text-sm text-[var(--text-muted)] self-center"
                            >
                                All available providers connected ✓
                            </span>
                        </div>
                    </div>
                </Card>
            </div>
        </Transition>
        <!-- 2FA Setup Modal -->
        <Teleport to="body">
            <Transition name="fade">
                <div
                    v-if="show2FAModal"
                    class="fixed inset-0 z-50 flex items-center justify-center p-4"
                >
                    <div
                        class="fixed inset-0 bg-black/50"
                        @click="close2FAModal"
                    ></div>
                    <div
                        class="relative bg-[var(--surface-primary)] rounded-2xl shadow-xl max-w-md w-full p-6 space-y-6"
                    >
                        <!-- Setup Step - Show QR Code -->
                        <template v-if="twoFAStep === 'confirm'">
                            <div>
                                <h3
                                    class="text-lg font-semibold text-[var(--text-primary)]"
                                >
                                    Set up Two-Factor Authentication
                                </h3>
                                <p
                                    class="text-sm text-[var(--text-secondary)] mt-1"
                                >
                                    Scan this QR code with your authenticator
                                    app
                                </p>
                            </div>

                            <div
                                class="flex justify-center p-4 bg-white rounded-lg"
                            >
                                <div
                                    v-html="twoFactorQrCode"
                                    class="w-48 h-48"
                                ></div>
                            </div>

                            <!-- Manual Key Entry -->
                            <div class="text-center">
                                <button
                                    type="button"
                                    class="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                                    @click="showManualKey = !showManualKey"
                                >
                                    {{
                                        showManualKey
                                            ? "Hide manual key"
                                            : "Can't scan? Enter key manually"
                                    }}
                                </button>
                            </div>

                            <div
                                v-if="showManualKey"
                                class="bg-[var(--surface-secondary)] rounded-lg p-4 text-center border border-[var(--border-dim)]"
                            >
                                <p
                                    class="text-xs text-[var(--text-muted)] mb-2 uppercase tracking-wide font-medium"
                                >
                                    Manual Entry Key
                                </p>
                                <div
                                    class="flex items-center justify-center gap-3"
                                >
                                    <code
                                        class="text-base font-mono font-bold text-[var(--text-primary)] tracking-wider select-all"
                                    >
                                        {{ twoFactorSecret }}
                                    </code>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        class="shrink-0"
                                        @click="
                                            copyToClipboard(twoFactorSecret)
                                        "
                                        title="Copy to clipboard"
                                    >
                                        <svg
                                            class="w-4 h-4"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
                                            />
                                        </svg>
                                    </Button>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <label
                                    class="block text-sm font-medium text-[var(--text-primary)]"
                                    >Enter the 6-digit code from your app</label
                                >
                                <input
                                    v-model="twoFactorConfirmCode"
                                    type="text"
                                    maxlength="6"
                                    class="w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] px-4 py-3 text-center text-2xl font-mono tracking-widest text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20"
                                    placeholder="000000"
                                    @keyup.enter="confirm2FA"
                                />
                            </div>

                            <div class="flex gap-3">
                                <Button
                                    variant="outline"
                                    class="flex-1"
                                    @click="close2FAModal"
                                    >Cancel</Button
                                >
                                <Button
                                    class="flex-1"
                                    @click="confirm2FA"
                                    :loading="isConfirming2FA"
                                    >Verify & Enable</Button
                                >
                            </div>
                        </template>

                        <!-- Recovery Codes Step -->
                        <template v-else-if="twoFAStep === 'recovery'">
                            <div>
                                <h3
                                    class="text-lg font-semibold text-[var(--text-primary)]"
                                >
                                    Save Your Recovery Codes
                                </h3>
                                <p
                                    class="text-sm text-[var(--text-secondary)] mt-1"
                                >
                                    Store these codes in a safe place. You can
                                    use them to access your account if you lose
                                    your device.
                                </p>
                            </div>

                            <div
                                class="bg-[var(--surface-secondary)] p-4 rounded-lg space-y-2 relative group"
                            >
                                <div
                                    v-for="code in twoFactorRecoveryCodes"
                                    :key="code"
                                    class="font-mono text-sm text-[var(--text-primary)] text-center py-1 tracking-wider"
                                >
                                    {{ code }}
                                </div>
                                <!-- Copy Overlay -->
                                <div
                                    class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity"
                                >
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="
                                            navigator.clipboard.writeText(
                                                twoFactorRecoveryCodes.join(
                                                    '\n'
                                                )
                                            );
                                            toast.success('Codes copied!');
                                        "
                                    >
                                        Copy
                                    </Button>
                                </div>
                            </div>

                            <div class="flex gap-3">
                                <Button
                                    variant="outline"
                                    class="flex-1"
                                    @click="
                                        () => {
                                            const element =
                                                document.createElement('a');
                                            const file = new Blob(
                                                [
                                                    twoFactorRecoveryCodes.join(
                                                        '\n'
                                                    ),
                                                ],
                                                { type: 'text/plain' }
                                            );
                                            element.href =
                                                URL.createObjectURL(file);
                                            element.download =
                                                'recovery-codes.txt';
                                            document.body.appendChild(element);
                                            element.click();
                                            document.body.removeChild(element);
                                        }
                                    "
                                >
                                    Download .txt
                                </Button>
                                <Button class="flex-1" @click="close2FAModal"
                                    >Done</Button
                                >
                            </div>

                            <p
                                class="text-xs text-[var(--text-muted)] text-center"
                            >
                                Each code can only be used once. Generate new
                                codes if you run out.
                            </p>
                        </template>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Disable 2FA Password Confirmation Modal -->
        <Teleport to="body">
            <Transition name="fade">
                <div
                    v-if="showDisable2FAModal"
                    class="fixed inset-0 z-50 flex items-center justify-center p-4"
                >
                    <div
                        class="fixed inset-0 bg-black/50"
                        @click="
                            showDisable2FAModal = false;
                            disablePassword = '';
                        "
                    ></div>
                    <div
                        class="relative bg-[var(--surface-primary)] rounded-2xl shadow-xl max-w-sm w-full p-6 space-y-4"
                    >
                        <div>
                            <h3
                                class="text-lg font-semibold text-[var(--text-primary)]"
                            >
                                Disable Two-Factor Authentication
                            </h3>
                            <p
                                class="text-sm text-[var(--text-secondary)] mt-1"
                            >
                                Enter your password to confirm this action.
                            </p>
                        </div>

                        <div class="space-y-2">
                            <label
                                class="block text-sm font-medium text-[var(--text-primary)]"
                                >Password</label
                            >
                            <input
                                v-model="disablePassword"
                                type="password"
                                class="w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3.5 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20"
                                placeholder="Enter your password"
                                @keyup.enter="disable2FA"
                            />
                        </div>

                        <div class="flex gap-3">
                            <Button
                                variant="outline"
                                class="flex-1"
                                @click="
                                    showDisable2FAModal = false;
                                    disablePassword = '';
                                "
                                >Cancel</Button
                            >
                            <Button
                                variant="danger"
                                class="flex-1"
                                @click="disable2FA"
                                :loading="isDisabling2FA"
                                >Disable 2FA</Button
                            >
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Passkey Name Modal -->
        <Teleport to="body">
            <Transition name="fade">
                <div
                    v-if="showPasskeyNameModal"
                    class="fixed inset-0 z-50 flex items-center justify-center p-4"
                >
                    <div
                        class="fixed inset-0 bg-black/50"
                        @click="showPasskeyNameModal = false"
                    ></div>
                    <div
                        class="relative bg-[var(--surface-primary)] rounded-2xl shadow-xl max-w-sm w-full p-6 space-y-4"
                    >
                        <div>
                            <h3
                                class="text-lg font-semibold text-[var(--text-primary)]"
                            >
                                Add Passkey
                            </h3>
                            <p
                                class="text-sm text-[var(--text-secondary)] mt-1"
                            >
                                Give your passkey a name to identify it later.
                            </p>
                        </div>

                        <div class="space-y-2">
                            <label
                                class="block text-sm font-medium text-[var(--text-primary)]"
                                >Passkey Name</label
                            >
                            <input
                                v-model="newPasskeyName"
                                type="text"
                                class="w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3.5 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20"
                                placeholder="e.g., MacBook Touch ID, iPhone Face ID"
                                @keyup.enter="confirmPasskeyRegistration"
                            />
                        </div>

                        <div class="flex gap-3">
                            <Button
                                variant="outline"
                                class="flex-1"
                                @click="showPasskeyNameModal = false"
                                >Cancel</Button
                            >
                            <Button
                                class="flex-1"
                                @click="confirmPasskeyRegistration"
                                :loading="isRegisteringPasskey"
                                >Continue</Button
                            >
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- SMS 2FA Setup Modal -->
        <Teleport to="body">
            <Transition name="fade">
                <div
                    v-if="showSmsSetupModal"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
                    @click.self="closeSmsSetupModal"
                >
                    <div
                        class="bg-[var(--surface-elevated)] rounded-xl p-6 w-full max-w-md shadow-2xl border border-[var(--border-default)]"
                    >
                        <h3
                            class="text-lg font-semibold text-[var(--text-primary)] mb-4"
                        >
                            Setup SMS Authentication
                        </h3>

                        <!-- Step 1: Phone Number -->
                        <div v-if="smsSetupStep === 'phone'" class="space-y-4">
                            <p class="text-sm text-[var(--text-secondary)]">
                                Enter your phone number to receive verification
                                codes via SMS.
                            </p>
                            <Input
                                v-model="smsPhoneNumber"
                                label="Phone Number"
                                placeholder="+1234567890"
                                :icon="Smartphone"
                            />
                            <p class="text-xs text-[var(--text-muted)]">
                                Use international format (e.g., +1 for US, +44
                                for UK)
                            </p>
                            <div class="flex gap-3 pt-2">
                                <Button
                                    variant="ghost"
                                    class="flex-1"
                                    @click="closeSmsSetupModal"
                                    >Cancel</Button
                                >
                                <Button
                                    class="flex-1"
                                    @click="enableSms2FA"
                                    :loading="isEnablingSms"
                                    :disabled="!smsPhoneNumber"
                                    >Send Code</Button
                                >
                            </div>
                        </div>

                        <!-- Step 2: Verify Code -->
                        <div
                            v-else-if="smsSetupStep === 'verify'"
                            class="space-y-4"
                        >
                            <p class="text-sm text-[var(--text-secondary)]">
                                Enter the 6-digit code we sent to your phone.
                            </p>
                            <Input
                                v-model="smsVerificationCode"
                                label="Verification Code"
                                placeholder="000000"
                                maxlength="6"
                                class="text-center tracking-widest font-mono text-lg"
                            />
                            <button
                                type="button"
                                class="text-sm transition-colors"
                                :class="smsResendCountdown > 0 || isSendingSmsCode ? 'text-[var(--text-muted)] cursor-not-allowed' : 'text-[var(--interactive-primary)] hover:underline'"
                                :disabled="isSendingSmsCode || smsResendCountdown > 0"
                                @click="resendSmsCode"
                            >
                                <template v-if="isSendingSmsCode">Sending...</template>
                                <template v-else-if="smsResendCountdown > 0">Resend code in {{ smsResendCountdown }}s</template>
                                <template v-else>Didn't receive code? Resend</template>
                            </button>
                            <div class="flex gap-3 pt-2">
                                <Button
                                    variant="ghost"
                                    class="flex-1"
                                    @click="smsSetupStep = 'phone'"
                                    >Back</Button
                                >
                                <Button
                                    class="flex-1"
                                    @click="verifySmsCode"
                                    :loading="isVerifyingSmsCode"
                                    :disabled="smsVerificationCode.length !== 6"
                                    >Verify & Enable</Button
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
