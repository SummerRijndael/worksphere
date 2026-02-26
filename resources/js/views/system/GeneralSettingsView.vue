<script setup>
import { ref, onMounted, computed } from "vue";
import { Button, Input, Switch, Modal } from "@/components/ui";
import {
    Settings,
    Globe,
    Shield,
    Mail,
    Save,
    RefreshCw,
    Megaphone,
    Plus,
    Trash2,
    Edit2,
    Key,
    Eye,
    EyeOff,
    Copy,
    Share2,
    Upload,
    X,
    Lock,
} from "lucide-vue-next";
import { toast } from "vue-sonner";
import axios from "axios";
import EmailAccountsSection from "@/components/settings/EmailAccountsSection.vue";
import SupportTicketsSection from "@/components/settings/SupportTicketsSection.vue";
import BlockedUrlManager from "@/components/settings/BlockedUrlManager.vue";
import { useAuthStore } from "@/stores/auth";
import { useDate } from "@/composables/useDate";

const { formatDate: formatDateComposible } = useDate();

const authStore = useAuthStore();

const isLoading = ref(true);
const isSaving = ref(false);
const showPasswords = ref({});

// Settings state - organized by group
const originalSettings = ref({});
const settings = ref({
    // General
    "app.name": "",
    "app.url": "",
    "app.timezone": "UTC",
    "app.timezone": "UTC",
    "app.locale": "en",
    "app.is_demo_mode": false,
    "features.public_pricing_page.enabled": true,
    // Security
    "auth.registration_enabled": true,
    "auth.email_verification": true,
    "auth.social_login_enabled": true,
    "session.lifetime": 120,
    // Storage
    "storage.max_team_storage": 1024,
    // Mail (Default System Sender)
    "mail.from_address": "",
    "mail.from_name": "",
    "mail.host": "",
    "mail.port": 587,
    "mail.username": "",
    "mail.password": "",
    "mail.encryption": "tls",
    // Integrations
    "recaptcha.site_key": "",
    "recaptcha.secret_key": "",
    "google.enabled": true,
    "google.client_id": "",
    "google.client_secret": "",
    "github.enabled": true,
    "github.client_id": "",
    "github.client_secret": "",
    // Twilio
    "twilio.sid": "",
    "twilio.auth_token": "",
    "twilio.verify_sid": "",
    // OpenAI
    "openai.api_key": "",
    "openai.organization": "",
    // Branding
    "app.logo": "",
    "app.favicon": "",
    "app.opengraph": "",
    // Team Management
    "teams.max_owned": 5,
    "teams.max_joined": 20,
    "teams.max_joined": 20,
    "teams.dormant_days": 90,
    "teams.deletion_grace_days": 30,
    "teams.auto_delete": false,
    "teams.require_approval": false,
    // Contact Information
    "contact.support": "",
    "contact.legal": "",
    "contact.dmca": "",
    "contact.privacy": "",
    // Tickets SLA
    "tickets.sla.enabled": true,
    "tickets.sla.business_hours_enabled": false,
    "tickets.sla.business_hours_start": "09:00",
    "tickets.sla.business_hours_end": "17:00",
    "tickets.sla.business_days": [1, 2, 3, 4, 5],
    "tickets.sla.holiday_country": "US",
    "tickets.sla.exclude_holidays": false,
    "tickets.sla.warning_threshold": 80,
    "tickets.sla.default_response_hours.critical": 1,
    "tickets.sla.default_response_hours.high": 2,
    "tickets.sla.default_response_hours.medium": 4,
    "tickets.sla.default_response_hours.low": 8,
    "tickets.sla.default_resolution_hours.critical": 4,
    "tickets.sla.default_resolution_hours.high": 8,
    "tickets.sla.default_resolution_hours.medium": 24,
    "tickets.sla.default_resolution_hours.low": 48,
});

// Sensitive fields that should be masked
const sensitiveFields = [
    "mail.password",
    "recaptcha.secret_key",
    "google.client_secret",
    "github.client_secret",
    "twilio.auth_token",
    "openai.api_key",
];

// Critical settings that require password verification
const criticalSettings = [
    "app.url",
    "app.is_demo_mode",
    "auth.registration_enabled",
    "auth.email_verification",
    "auth.social_login_enabled",
    "mail.host",
    "mail.username",
    "mail.password",
    "mail.imap_host",
    "mail.imap_username",
    "mail.imap_password",
    "recaptcha.site_key",
    "recaptcha.secret_key",
    "google.client_id",
    "google.client_secret",
    "github.client_id",
    "github.client_secret",
    "twilio.sid",
    "twilio.auth_token",
    "twilio.verify_sid",
    "openai.api_key",
];

const showCriticalConfirmation = ref(false);
const criticalConfirmationForm = ref({ password: "", reason: "" });
const isConfirmingCritical = ref(false);
const criticalChangesList = ref([]);
let resolveCriticalSave = null;
let rejectCriticalSave = null;

const confirmCriticalSave = async () => {
    if (
        !criticalConfirmationForm.value.password ||
        !criticalConfirmationForm.value.reason
    ) {
        toast.error("Password and reason are required");
        return;
    }

    showCriticalConfirmation.value = false;
    if (resolveCriticalSave) resolveCriticalSave(true);
};

const cancelCriticalSave = () => {
    showCriticalConfirmation.value = false;
    criticalConfirmationForm.value = { password: "", reason: "" };
    if (rejectCriticalSave) rejectCriticalSave(new Error("Cancelled by user"));
};

// Timezone options
const timezones = [
    "UTC",
    "America/New_York",
    "America/Los_Angeles",
    "America/Chicago",
    "America/Denver",
    "Europe/London",
    "Europe/Paris",
    "Europe/Berlin",
    "Europe/Moscow",
    "Asia/Tokyo",
    "Asia/Shanghai",
    "Asia/Singapore",
    "Asia/Dubai",
    "Asia/Manila",
    "Australia/Sydney",
    "Australia/Melbourne",
    "Pacific/Auckland",
];

const locales = [
    { value: "en", label: "English" },
    { value: "es", label: "Spanish" },
    { value: "fr", label: "French" },
    { value: "de", label: "German" },
    { value: "zh", label: "Chinese" },
    { value: "ja", label: "Japanese" },
];

const encryptionOptions = [
    { value: "tls", label: "TLS" },
    { value: "ssl", label: "SSL" },
    { value: "", label: "None" },
];

// Announcements state
const announcements = ref([]);
const isLoadingAnnouncements = ref(false);
const showAnnouncementModal = ref(false);
const editingAnnouncement = ref(null);
const announcementForm = ref({
    title: "",
    message: "",
    type: "info",
    action_text: "",
    action_url: "",
    is_dismissable: true,
    is_public: true,
    is_active: true,
    starts_at: "",
    ends_at: "",
});
const announcementErrors = ref({});
const isSavingAnnouncement = ref(false);

const announcementTypes = [
    { value: "info", label: "Information", color: "bg-blue-500" },
    { value: "warning", label: "Warning", color: "bg-amber-500" },
    { value: "danger", label: "Danger", color: "bg-red-500" },
    { value: "success", label: "Success", color: "bg-green-500" },
];

// Toggle password visibility
const togglePasswordVisibility = (field) => {
    showPasswords.value[field] = !showPasswords.value[field];
};

// Copy to clipboard
const copyToClipboard = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success("Copied to clipboard");
    } catch (err) {
        toast.error("Failed to copy");
    }
};
// Fetch settings
const fetchSettings = async () => {
    try {
        const response = await axios.get("/api/settings");
        const definitions = response.data.data.definitions;

        // Flatten definitions into settings
        Object.values(definitions).forEach((group) => {
            group.forEach((setting) => {
                if (
                    setting.value !== null &&
                    settings.value.hasOwnProperty(setting.key)
                ) {
                    settings.value[setting.key] = setting.value;
                }
            });
        });

        // Store original for change detection
        originalSettings.value = JSON.parse(JSON.stringify(settings.value));
    } catch (error) {
        console.error("Failed to fetch settings:", error);
        toast.error("Failed to load settings");
    }
};

// Fetch announcements
const fetchAnnouncements = async () => {
    isLoadingAnnouncements.value = true;
    try {
        const response = await axios.get("/api/admin/announcements");
        announcements.value = response.data.data || [];
    } catch (error) {
        // Silently fail if user doesn't have permission
    } finally {
        isLoadingAnnouncements.value = false;
    }
};

// Test System SMTP
const isTestingSmtp = ref(false);
const testSystemSmtp = async () => {
    isTestingSmtp.value = true;
    try {
        // Send current settings (even unsaved) to test
        const response = await axios.post("/api/settings/test-smtp", {
            settings: settings.value,
        });

        if (response.data.success) {
            toast.success("SMTP connection successful!");
        } else {
            toast.error(response.data.message || "Connection failed");
        }
    } catch (error) {
        toast.error("SMTP test failed. Check your settings.");
    } finally {
        isTestingSmtp.value = false;
    }
};

// Branding Upload State
const selectedFiles = ref({
    logo: null,
    favicon: null,
    opengraph: null,
});

const brandingUploading = ref({
    logo: false,
    favicon: false,
    opengraph: false,
});

const handleFileSelect = (event, type) => {
    const file = event.target.files[0];
    if (file) {
        selectedFiles.value[type] = file;
    }
};

const updateBrowserFavicon = (url) => {
    const link =
        document.querySelector("link[rel*='icon']") ||
        document.createElement("link");
    link.type = "image/x-icon";
    link.rel = "shortcut icon";
    link.href = url;
    document.getElementsByTagName("head")[0].appendChild(link);
};

const uploadBranding = async (type) => {
    const file = selectedFiles.value[type];
    if (!file) return;

    brandingUploading.value[type] = true;
    const formData = new FormData();
    formData.append(type, file);

    try {
        const response = await axios.post(
            `/api/settings/upload-${type}`,
            formData,
            {
                headers: {
                    "Content-Type": "multipart/form-data",
                },
            },
        );

        settings.value[`app.${type}`] = response.data.url;
        selectedFiles.value[type] = null; // Clear selection

        // Reset file input
        const fileInput = document.getElementById(`${type}-upload`);
        if (fileInput) fileInput.value = "";

        if (type === "favicon") {
            updateBrowserFavicon(response.data.url);
        }

        toast.success(
            `${
                type.charAt(0).toUpperCase() + type.slice(1)
            } uploaded successfully`,
        );
    } catch (error) {
        console.error(`Failed to upload ${type}:`, error);
        toast.error(
            error.response?.data?.message || `Failed to upload ${type}`,
        );
    } finally {
        brandingUploading.value[type] = false;
    }
};

// Save settings
const saveSettings = async () => {
    isSaving.value = true;
    try {
        // Detect changes
        const changedKeys = [];
        const criticalKeys = [];

        Object.keys(settings.value).forEach((key) => {
            if (
                JSON.stringify(settings.value[key]) !==
                JSON.stringify(originalSettings.value[key])
            ) {
                changedKeys.push(key);
                if (criticalSettings.includes(key)) {
                    criticalKeys.push(key);
                }
            }
        });

        if (changedKeys.length === 0) {
            toast.info("No changes to save");
            isSaving.value = false;
            return;
        }

        // Handle Critical Settings
        let authData = {};
        if (criticalKeys.length > 0) {
            criticalChangesList.value = criticalKeys;
            criticalConfirmationForm.value = { password: "", reason: "" };
            showCriticalConfirmation.value = true;

            try {
                await new Promise((resolve, reject) => {
                    resolveCriticalSave = resolve;
                    rejectCriticalSave = reject;
                });

                authData = {
                    password: criticalConfirmationForm.value.password,
                    reason: criticalConfirmationForm.value.reason,
                };
            } catch (e) {
                isSaving.value = false;
                return; // Cancelled
            }
        }

        const settingsArray = Object.entries(settings.value)
            .map(([key, value]) => {
                const isSensitive = sensitiveFields.includes(key);
                return {
                    key,
                    value,
                    type:
                        typeof value === "boolean"
                            ? "boolean"
                            : typeof value === "number"
                              ? "integer"
                              : "string",
                    group: key.split(".")[0],
                    is_sensitive: isSensitive,
                };
            })
            .filter((s) => s.value !== "" && s.value !== null); // Only save non-empty values

        await axios.put("/api/settings", {
            settings: settingsArray,
            ...authData,
        });

        // Update original settings after successful save
        originalSettings.value = JSON.parse(JSON.stringify(settings.value));

        toast.success("Settings saved successfully");
    } catch (error) {
        console.error("Failed to save settings:", error);

        if (error.response?.status === 423 || error.response?.status === 403) {
            toast.error(
                error.response.data.message || "Security verification failed",
            );
        } else {
            toast.error("Failed to save settings");
        }
    } finally {
        isSaving.value = false;
        criticalConfirmationForm.value = { password: "", reason: "" }; // Clear sensitive data
    }
};

// Announcement functions
const openAnnouncementModal = (announcement = null) => {
    editingAnnouncement.value = announcement;
    if (announcement) {
        announcementForm.value = {
            title: announcement.title,
            message: announcement.message,
            type: announcement.type,
            action_text: announcement.action_text || "",
            action_url: announcement.action_url || "",
            is_dismissable: announcement.is_dismissable,
            is_public: announcement.is_public ?? true,
            is_active: announcement.is_active,
            starts_at: announcement.starts_at
                ? announcement.starts_at.slice(0, 16)
                : "",
            ends_at: announcement.ends_at
                ? announcement.ends_at.slice(0, 16)
                : "",
        };
    } else {
        announcementForm.value = {
            title: "",
            message: "",
            type: "info",
            action_text: "",
            action_url: "",
            is_dismissable: true,
            is_public: true,
            is_active: true,
            starts_at: "",
            ends_at: "",
        };
    }
    announcementErrors.value = {};
    showAnnouncementModal.value = true;
};

const saveAnnouncement = async () => {
    announcementErrors.value = {};
    isSavingAnnouncement.value = true;
    try {
        const payload = {
            ...announcementForm.value,
            starts_at: announcementForm.value.starts_at || null,
            ends_at: announcementForm.value.ends_at || null,
        };
        if (editingAnnouncement.value) {
            await axios.put(
                `/api/admin/announcements/${editingAnnouncement.value.id}`,
                payload,
            );
            toast.success("Announcement updated");
        } else {
            await axios.post("/api/admin/announcements", payload);
            toast.success("Announcement created");
        }
        showAnnouncementModal.value = false;
        await fetchAnnouncements();
    } catch (error) {
        if (error.response?.status === 422) {
            announcementErrors.value = error.response.data.errors || {};
        } else {
            toast.error("Failed to save announcement");
        }
    } finally {
        isSavingAnnouncement.value = false;
    }
};

const deleteAnnouncement = async (id) => {
    if (!confirm("Are you sure you want to delete this announcement?")) return;
    try {
        await axios.delete(`/api/admin/announcements/${id}`);
        toast.success("Announcement deleted");
        await fetchAnnouncements();
    } catch (error) {
        toast.error("Failed to delete announcement");
    }
};

const toggleAnnouncementActive = async (announcement) => {
    try {
        await axios.put(`/api/admin/announcements/${announcement.id}`, {
            is_active: !announcement.is_active,
        });
        announcement.is_active = !announcement.is_active;
        toast.success(
            `Announcement ${
                announcement.is_active ? "activated" : "deactivated"
            }`,
        );
    } catch (error) {
        toast.error("Failed to update announcement");
    }
};

// Demo Mode Verification
const showDemoPasswordModal = ref(false);
const demoPassword = ref("");
const isVerifyingDemoPassword = ref(false);
const pendingDemoState = ref(false);

const handleDemoModeToggle = (newValue) => {
    // If enabling, or disabling, we require password?
    // User requirement: "guard demo mode with password confirm and secret"
    // Let's require it for BOTH to be safe, or just ensuring accidental toggles don't happen.
    // Usually disabling is the sensitive part (re-enabling writes), but enabling can be DOS.
    // Let's require it for toggle.

    // Reset toggle visually until verified (we manually handle v-model update)
    // Actually, v-model will update it. We need to revert if cancelled.

    pendingDemoState.value = newValue;
    // settings.value['app.is_demo_mode'] is already updated by v-model before this likely change event?
    // If using <Switch :checked="val" @update:checked="..."> it's better.
    // If using v-model, we might need to revert.

    // We'll assume we catch it before save?
    // Wait, the "Save Changes" button saves ALL settings.
    // So the toggle just changes the local state.
    // If we want to guard the CHANGE, we should intercept the change.

    // But typically "Demo Mode" is a setting that takes effect immediately or on save?
    // If it's just a setting in the big form, the password should probably be required *to change the setting in the form*.

    // Revert change first
    settings.value["app.is_demo_mode"] = !newValue;

    showDemoPasswordModal.value = true;
};

const verifyDemoPassword = async () => {
    if (!demoPassword.value) return;

    isVerifyingDemoPassword.value = true;
    try {
        const response = await axios.post("/api/settings/verify-demo", {
            password: demoPassword.value,
        });

        if (response.data.success) {
            settings.value["app.is_demo_mode"] = pendingDemoState.value;
            showDemoPasswordModal.value = false;
            demoPassword.value = "";
            toast.success(
                "Demo mode access verified. Click 'Save Changes' to apply.",
            );
        }
    } catch (error) {
        toast.error(error.response?.data?.message || "Invalid secret phrase");
    } finally {
        isVerifyingDemoPassword.value = false;
    }
};

onMounted(async () => {
    try {
        await Promise.all([fetchSettings(), fetchAnnouncements()]);
    } finally {
        isLoading.value = false;
    }
});
</script>

<template>
    <div>
        <div class="p-6 space-y-6">
        <!-- Header -->
        <div
            class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4"
        >
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                    General Settings
                </h1>
                <p class="text-[var(--text-secondary)]">
                    Configure system-wide settings and preferences.
                </p>
            </div>
            <Button variant="primary" @click="saveSettings" :loading="isSaving">
                <Save class="w-4 h-4" />
                Save Changes
            </Button>
        </div>

        <!-- Loading State -->
        <div v-if="isLoading" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div
                v-for="i in 4"
                :key="i"
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-6 animate-pulse"
            >
                <div
                    class="h-4 bg-[var(--surface-secondary)] rounded w-32 mb-4"
                ></div>
                <div class="space-y-3">
                    <div
                        class="h-10 bg-[var(--surface-secondary)] rounded"
                    ></div>
                    <div
                        class="h-10 bg-[var(--surface-secondary)] rounded"
                    ></div>
                </div>
            </div>
        </div>

        <!-- Settings Sections -->
        <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Application Settings -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center"
                    >
                        <Globe class="w-4 h-4 text-blue-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Application
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="space-y-1.5">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >Application Name</label
                        >
                        <Input
                            v-model="settings['app.name']"
                            placeholder="My Application"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)] flex items-center gap-1.5"
                            >Application URL
                            <Lock class="w-3 h-3 text-amber-500"
                        /></label>
                        <Input
                            v-model="settings['app.url']"
                            type="url"
                            placeholder="https://example.com"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Timezone</label
                            >
                            <select
                                v-model="settings['app.timezone']"
                                class="w-full px-3 py-2 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]"
                            >
                                <option
                                    v-for="tz in timezones"
                                    :key="tz"
                                    :value="tz"
                                >
                                    {{ tz }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Locale</label
                            >
                            <select
                                v-model="settings['app.locale']"
                                class="w-full px-3 py-2 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]"
                            >
                                <option
                                    v-for="loc in locales"
                                    :key="loc.value"
                                    :value="loc.value"
                                >
                                    {{ loc.label }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Demo Mode Toggle -->
                    <div
                        class="p-4 bg-orange-500/5 rounded-lg border border-orange-200 mt-4 flex items-center justify-between"
                    >
                        <div class="flex items-start gap-3">
                            <div
                                class="w-8 h-8 rounded-lg bg-orange-500/10 flex items-center justify-center shrink-0"
                            >
                                <Key class="w-4 h-4 text-orange-600" />
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-orange-900">
                                    Demo Mode
                                </h4>
                                <p class="text-xs text-orange-700 mt-0.5">
                                    Restrict destructive actions. Requires
                                    secret phrase to toggle.
                                </p>
                            </div>
                        </div>
                        <Switch
                            :model-value="settings['app.is_demo_mode']"
                            @update:model-value="handleDemoModeToggle"
                        />
                    </div>

                    <!-- Public Pricing Toggle -->
                    <div
                        class="p-4 bg-blue-500/5 rounded-lg border border-blue-200 mt-4 flex items-center justify-between"
                    >
                        <div class="flex items-start gap-3">
                            <div
                                class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center shrink-0"
                            >
                                <Settings class="w-4 h-4 text-blue-600" />
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-blue-900">
                                    Public Pricing Page
                                </h4>
                                <p class="text-xs text-blue-700 mt-0.5">
                                    Show or hide the pricing section on the landing page and header/footer links.
                                </p>
                            </div>
                        </div>
                        <Switch
                            v-model="settings['features.public_pricing_page.enabled']"
                        />
                    </div>
                </div>
            </div>

            <!-- Contact Information Settings -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-green-500/10 flex items-center justify-center"
                    >
                        <Mail class="w-4 h-4 text-green-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Contact Information
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <p class="text-sm text-[var(--text-secondary)] mb-4">
                        These email addresses will be displayed in legal
                        documents (Terms, Privacy) and throughout the
                        application.
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Support Email</label
                            >
                            <Input
                                v-model="settings['contact.support']"
                                type="email"
                                placeholder="support@example.com"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Legal Email</label
                            >
                            <Input
                                v-model="settings['contact.legal']"
                                type="email"
                                placeholder="legal@example.com"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >DMCA / Copyright Email</label
                            >
                            <Input
                                v-model="settings['contact.dmca']"
                                type="email"
                                placeholder="dmca@example.com"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Privacy Officer Email</label
                            >
                            <Input
                                v-model="settings['contact.privacy']"
                                type="email"
                                placeholder="privacy@example.com"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Branding Settings -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-pink-500/10 flex items-center justify-center"
                    >
                        <Megaphone class="w-4 h-4 text-pink-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Branding
                    </h3>
                </div>
                <div class="p-6 space-y-6">
                    <!-- Logo Upload -->
                    <div class="flex flex-col sm:flex-row items-center gap-4">
                        <div class="flex-1 space-y-1">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                            >
                                Application Logo
                            </label>
                            <p class="text-xs text-[var(--text-muted)]">
                                Upload a logo for your application (min
                                100x100).
                            </p>
                        </div>
                        <div
                            class="flex flex-row items-center gap-4 w-full sm:w-auto"
                        >
                            <div
                                class="w-16 h-16 shrink-0 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)] flex items-center justify-center overflow-hidden relative group"
                            >
                                <img
                                    v-if="settings['app.logo']"
                                    :src="settings['app.logo']"
                                    alt="Logo"
                                    class="w-full h-full object-contain p-2"
                                />
                                <span
                                    v-else
                                    class="text-[10px] text-[var(--text-muted)]"
                                    >No Logo</span
                                >
                            </div>

                            <div class="flex flex-col gap-2 min-w-[120px]">
                                <div class="relative w-full">
                                    <input
                                        id="logo-upload"
                                        type="file"
                                        @change="
                                            (e) => handleFileSelect(e, 'logo')
                                        "
                                        class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                                        accept="image/*"
                                    />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="w-full justify-center"
                                    >
                                        <div class="truncate">
                                            {{
                                                selectedFiles.logo
                                                    ? "Change"
                                                    : "Select File"
                                            }}
                                        </div>
                                    </Button>
                                </div>
                                <Button
                                    v-if="selectedFiles.logo"
                                    variant="primary"
                                    size="xs"
                                    class="w-full"
                                    :loading="brandingUploading.logo"
                                    @click="uploadBranding('logo')"
                                >
                                    <Upload class="w-3 h-3 mr-1" />
                                    Upload
                                </Button>
                                <p
                                    v-if="selectedFiles.logo"
                                    class="text-[10px] text-[var(--text-primary)] truncate max-w-[120px]"
                                >
                                    {{ selectedFiles.logo.name }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="h-px bg-[var(--border-default)]"></div>

                    <!-- Favicon Upload -->
                    <div class="flex flex-col sm:flex-row items-center gap-4">
                        <div class="flex-1 space-y-1">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                            >
                                Favicon
                            </label>
                            <p class="text-xs text-[var(--text-muted)]">
                                Upload a favicon (max 512x512). ICO, PNG, or
                                SVG.
                            </p>
                        </div>
                        <div
                            class="flex flex-row items-center gap-4 w-full sm:w-auto"
                        >
                            <div
                                class="w-12 h-12 shrink-0 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)] flex items-center justify-center overflow-hidden"
                            >
                                <img
                                    v-if="settings['app.favicon']"
                                    :src="settings['app.favicon']"
                                    alt="Favicon"
                                    class="w-6 h-6 object-contain"
                                />
                                <span
                                    v-else
                                    class="text-[10px] text-[var(--text-muted)]"
                                    >None</span
                                >
                            </div>

                            <div class="flex flex-col gap-2 min-w-[120px]">
                                <div class="relative w-full">
                                    <input
                                        id="favicon-upload"
                                        type="file"
                                        @change="
                                            (e) =>
                                                handleFileSelect(e, 'favicon')
                                        "
                                        class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                                        accept="image/x-icon,image/png,image/svg+xml"
                                    />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="w-full justify-center"
                                    >
                                        <div class="truncate">
                                            {{
                                                selectedFiles.favicon
                                                    ? "Change"
                                                    : "Select File"
                                            }}
                                        </div>
                                    </Button>
                                </div>
                                <Button
                                    v-if="selectedFiles.favicon"
                                    variant="primary"
                                    size="xs"
                                    class="w-full"
                                    :loading="brandingUploading.favicon"
                                    @click="uploadBranding('favicon')"
                                >
                                    <Upload class="w-3 h-3 mr-1" />
                                    Upload
                                </Button>
                                <p
                                    v-if="selectedFiles.favicon"
                                    class="text-[10px] text-[var(--text-primary)] truncate max-w-[120px]"
                                >
                                    {{ selectedFiles.favicon.name }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="h-px bg-[var(--border-default)]"></div>

                    <!-- OpenGraph Upload -->
                    <div class="flex flex-col sm:flex-row items-center gap-4">
                        <div class="flex-1 space-y-1">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                            >
                                Social Share Image (OpenGraph)
                            </label>
                            <p class="text-xs text-[var(--text-muted)]">
                                The image displayed when sharing links on social
                                media.
                            </p>
                        </div>
                        <div
                            class="flex flex-row items-center gap-4 w-full sm:w-auto"
                        >
                            <div
                                class="w-32 h-16 shrink-0 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)] flex items-center justify-center overflow-hidden relative"
                            >
                                <img
                                    v-if="settings['app.opengraph']"
                                    :src="settings['app.opengraph']"
                                    alt="OpenGraph Image"
                                    class="w-full h-full object-cover"
                                />
                                <div
                                    v-else
                                    class="flex flex-col items-center justify-center text-[var(--text-muted)]"
                                >
                                    <Share2 class="w-4 h-4 mb-1 opacity-50" />
                                    <span class="text-[10px]">No Image</span>
                                </div>
                            </div>

                            <div class="flex flex-col gap-2 min-w-[120px]">
                                <div class="relative w-full">
                                    <input
                                        id="opengraph-upload"
                                        type="file"
                                        @change="
                                            (e) =>
                                                handleFileSelect(e, 'opengraph')
                                        "
                                        class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                                        accept="image/png,image/jpeg,image/webp"
                                    />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="w-full justify-center"
                                    >
                                        <div class="truncate">
                                            {{
                                                selectedFiles.opengraph
                                                    ? "Change"
                                                    : "Select File"
                                            }}
                                        </div>
                                    </Button>
                                </div>
                                <Button
                                    v-if="selectedFiles.opengraph"
                                    variant="primary"
                                    size="xs"
                                    class="w-full"
                                    :loading="brandingUploading.opengraph"
                                    @click="uploadBranding('opengraph')"
                                >
                                    <Upload class="w-3 h-3 mr-1" />
                                    Upload
                                </Button>
                                <p
                                    v-if="selectedFiles.opengraph"
                                    class="text-[10px] text-[var(--text-primary)] truncate max-w-[120px]"
                                >
                                    {{ selectedFiles.opengraph.name }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Settings -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-red-500/10 flex items-center justify-center"
                    >
                        <Shield class="w-4 h-4 text-red-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Security
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                User Registration
                                <Lock
                                    class="w-3 h-3 inline-block ml-1 text-amber-500"
                                />
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Allow new users to register
                            </p>
                        </div>
                        <Switch
                            v-model="settings['auth.registration_enabled']"
                        />
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                Email Verification
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Require email verification
                            </p>
                        </div>
                        <Switch v-model="settings['auth.email_verification']" />
                    </div>
                    <div class="space-y-1.5">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >Session Lifetime (minutes)</label
                        >
                        <Input
                            v-model.number="settings['session.lifetime']"
                            type="number"
                            min="1"
                        />
                    </div>
                </div>
            </div>

            <!-- Blocked URL Manager -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden lg:col-span-2"
            >
                <BlockedUrlManager />
            </div>

            <!-- Support Tickets Settings -->
            <div
                v-if="authStore.hasPermission('system.settings.manage')"
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden lg:col-span-2"
            >
                <SupportTicketsSection
                    :settings="settings"
                    @update:settings="(s) => Object.assign(settings, s)"
                />
            </div>

            <!-- Storage Settings -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-orange-500/10 flex items-center justify-center"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="w-4 h-4 text-orange-600"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path
                                d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"
                            />
                            <path d="M4 6v12a2 2 0 0 0 2 2h14v-4" />
                            <path
                                d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"
                            />
                        </svg>
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Storage
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="space-y-1.5">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >Max Team Storage (MB)</label
                        >
                        <Input
                            v-model.number="
                                settings['storage.max_team_storage']
                            "
                            type="number"
                            min="1"
                            placeholder="1024"
                        />
                        <p class="text-xs text-[var(--text-muted)]">
                            Maximum allowed storage per team in Megabytes.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Team Management Settings -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-purple-500/10 flex items-center justify-center"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="w-4 h-4 text-purple-600"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M18 21a8 8 0 0 0-16 0" />
                            <circle cx="10" cy="8" r="5" />
                            <path
                                d="M22 20c0-3.37-2-6.5-4-8a5 5 0 0 0-.45-8.3"
                            />
                        </svg>
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Team Management
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Max Teams Owned</label
                            >
                            <Input
                                v-model.number="settings['teams.max_owned']"
                                type="number"
                                min="1"
                                placeholder="5"
                            />
                            <p class="text-xs text-[var(--text-muted)]">
                                Maximum teams a user can own.
                            </p>
                        </div>
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Max Teams Joined</label
                            >
                            <Input
                                v-model.number="settings['teams.max_joined']"
                                type="number"
                                min="1"
                                placeholder="20"
                            />
                            <p class="text-xs text-[var(--text-muted)]">
                                Maximum teams a user can join.
                            </p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Days Until Dormant</label
                            >
                            <Input
                                v-model.number="settings['teams.dormant_days']"
                                type="number"
                                min="1"
                                placeholder="90"
                            />
                            <p class="text-xs text-[var(--text-muted)]">
                                Days without activity before dormant.
                            </p>
                        </div>
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Deletion Grace Period</label
                            >
                            <Input
                                v-model.number="
                                    settings['teams.deletion_grace_days']
                                "
                                type="number"
                                min="1"
                                placeholder="30"
                            />
                            <p class="text-xs text-[var(--text-muted)]">
                                Days after dormant before deletion.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                Auto-Delete Teams
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Automatically delete after grace period
                            </p>
                        </div>
                        <Switch v-model="settings['teams.auto_delete']" />
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                Require Team Creation Approval
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Require admin approval for new teams
                            </p>
                        </div>
                        <Switch v-model="settings['teams.require_approval']" />
                    </div>
                </div>
            </div>

            <!-- Mail Settings (System Default Sender) -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden lg:col-span-2"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-green-500/10 flex items-center justify-center"
                    >
                        <Mail class="w-4 h-4 text-green-600" />
                    </div>
                    <div>
                        <h3 class="font-medium text-[var(--text-primary)]">
                            Mail Configuration
                        </h3>
                        <p class="text-xs text-[var(--text-muted)]">
                            Default system email sender (SMTP)
                        </p>
                    </div>
                </div>
                <div
                    class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"
                >
                    <div class="space-y-1.5">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >From Address</label
                        >
                        <Input
                            v-model="settings['mail.from_address']"
                            type="email"
                            placeholder="noreply@example.com"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >From Name</label
                        >
                        <Input
                            v-model="settings['mail.from_name']"
                            placeholder="My Application"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)] flex items-center gap-1.5"
                            >SMTP Host
                            <Lock class="w-3 h-3 text-amber-500" />
                        </label>
                        <Input
                            v-model="settings['mail.host']"
                            placeholder="smtp.example.com"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >SMTP Port</label
                        >
                        <Input
                            v-model.number="settings['mail.port']"
                            type="number"
                            placeholder="587"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >Encryption</label
                        >
                        <select
                            v-model="settings['mail.encryption']"
                            class="w-full px-3 py-2 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]"
                        >
                            <option
                                v-for="enc in encryptionOptions"
                                :key="enc.value"
                                :value="enc.value"
                            >
                                {{ enc.label }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >SMTP Username</label
                        >
                        <Input
                            v-model="settings['mail.username']"
                            placeholder="username"
                        />
                    </div>
                    <div class="space-y-1.5 md:col-span-2 lg:col-span-1">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >SMTP Password</label
                        >
                        <div class="relative">
                            <Input
                                v-model="settings['mail.password']"
                                :type="
                                    showPasswords['mail.password']
                                        ? 'text'
                                        : 'password'
                                "
                                placeholder="••••••••"
                            />
                            <button
                                type="button"
                                @click="
                                    togglePasswordVisibility('mail.password')
                                "
                                class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                            >
                                <Eye
                                    v-if="!showPasswords['mail.password']"
                                    class="w-4 h-4"
                                />
                                <EyeOff v-else class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                    <!-- Test Button row -->
                    <div
                        class="md:col-span-2 lg:col-span-3 flex justify-end pt-2 border-t border-[var(--border-default)]"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            @click="testSystemSmtp"
                            :loading="isTestingSmtp"
                            :disabled="isSaving"
                        >
                            <RefreshCw
                                class="w-4 h-4 mr-2"
                                :class="{ 'animate-spin': isTestingSmtp }"
                            />
                            Test SMTP Connection
                        </Button>
                    </div>
                </div>
            </div>

            <!-- OAuth & Integrations -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden lg:col-span-2"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-purple-500/10 flex items-center justify-center"
                    >
                        <Key class="w-4 h-4 text-purple-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        OAuth & Integrations
                    </h3>
                </div>
                <div class="p-6 space-y-6">
                    <!-- Global Social Login Toggle -->
                    <div
                        class="flex items-center justify-between border-b border-[var(--border-default)] pb-6"
                    >
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                Enable Social Login
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Allow users to login with social providers
                            </p>
                        </div>
                        <Switch
                            v-model="settings['auth.social_login_enabled']"
                        />
                    </div>

                    <!-- Google -->
                    <div
                        class="p-4 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]"
                    >
                        <div class="flex items-center justify-between mb-4">
                            <h4
                                class="text-sm font-medium text-[var(--text-primary)] flex items-center gap-2"
                            >
                                <svg class="w-4 h-4" viewBox="0 0 24 24">
                                    <path
                                        fill="#4285F4"
                                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                                    />
                                    <path
                                        fill="#34A853"
                                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                                    />
                                    <path
                                        fill="#FBBC05"
                                        d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                                    />
                                    <path
                                        fill="#EA4335"
                                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                                    />
                                </svg>
                                Google OAuth
                            </h4>
                            <Switch v-model="settings['google.enabled']" />
                        </div>
                        <div
                            class="space-y-4"
                            :class="{
                                'opacity-50 pointer-events-none':
                                    !settings['google.enabled'],
                            }"
                        >
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="space-y-1.5">
                                    <label
                                        class="text-sm text-[var(--text-secondary)]"
                                        >Client ID</label
                                    >
                                    <Input
                                        v-model="settings['google.client_id']"
                                        placeholder="Google Client ID"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label
                                        class="text-sm text-[var(--text-secondary)]"
                                        >Client Secret</label
                                    >
                                    <div class="relative">
                                        <Input
                                            v-model="
                                                settings['google.client_secret']
                                            "
                                            :type="
                                                showPasswords[
                                                    'google.client_secret'
                                                ]
                                                    ? 'text'
                                                    : 'password'
                                            "
                                            placeholder="••••••••"
                                        />
                                        <button
                                            type="button"
                                            @click="
                                                togglePasswordVisibility(
                                                    'google.client_secret',
                                                )
                                            "
                                            class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-[var(--text-muted)]"
                                        >
                                            <Eye
                                                v-if="
                                                    !showPasswords[
                                                        'google.client_secret'
                                                    ]
                                                "
                                                class="w-4 h-4"
                                            />
                                            <EyeOff v-else class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Callback URL</label
                                >
                                <div class="flex items-center gap-2">
                                    <code
                                        class="flex-1 px-3 py-2 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg text-xs font-mono text-[var(--text-secondary)] truncate"
                                    >
                                        {{
                                            settings["app.url"] ||
                                            window.location.origin
                                        }}/api/auth/google/callback
                                    </code>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            copyToClipboard(
                                                (settings['app.url'] ||
                                                    window.location.origin) +
                                                    '/api/auth/google/callback',
                                            )
                                        "
                                    >
                                        <Copy class="w-4 h-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- GitHub -->
                    <div
                        class="p-4 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]"
                    >
                        <div class="flex items-center justify-between mb-4">
                            <h4
                                class="text-sm font-medium text-[var(--text-primary)] flex items-center gap-2"
                            >
                                <svg
                                    class="w-4 h-4"
                                    fill="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"
                                    />
                                </svg>
                                GitHub OAuth
                            </h4>
                            <Switch v-model="settings['github.enabled']" />
                        </div>
                        <div
                            class="space-y-4"
                            :class="{
                                'opacity-50 pointer-events-none':
                                    !settings['github.enabled'],
                            }"
                        >
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="space-y-1.5">
                                    <label
                                        class="text-sm text-[var(--text-secondary)]"
                                        >Client ID</label
                                    >
                                    <Input
                                        v-model="settings['github.client_id']"
                                        placeholder="GitHub Client ID"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label
                                        class="text-sm text-[var(--text-secondary)]"
                                        >Client Secret</label
                                    >
                                    <div class="relative">
                                        <Input
                                            v-model="
                                                settings['github.client_secret']
                                            "
                                            :type="
                                                showPasswords[
                                                    'github.client_secret'
                                                ]
                                                    ? 'text'
                                                    : 'password'
                                            "
                                            placeholder="••••••••"
                                        />
                                        <button
                                            type="button"
                                            @click="
                                                togglePasswordVisibility(
                                                    'github.client_secret',
                                                )
                                            "
                                            class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-[var(--text-muted)]"
                                        >
                                            <Eye
                                                v-if="
                                                    !showPasswords[
                                                        'github.client_secret'
                                                    ]
                                                "
                                                class="w-4 h-4"
                                            />
                                            <EyeOff v-else class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Callback URL</label
                                >
                                <div class="flex items-center gap-2">
                                    <code
                                        class="flex-1 px-3 py-2 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg text-xs font-mono text-[var(--text-secondary)] truncate"
                                    >
                                        {{
                                            settings["app.url"] ||
                                            window.location.origin
                                        }}/api/auth/github/callback
                                    </code>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            copyToClipboard(
                                                (settings['app.url'] ||
                                                    window.location.origin) +
                                                    '/api/auth/github/callback',
                                            )
                                        "
                                    >
                                        <Copy class="w-4 h-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- reCAPTCHA -->
                    <div>
                        <h4
                            class="text-sm font-medium text-[var(--text-primary)] mb-3"
                        >
                            reCAPTCHA v3
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Site Key</label
                                >
                                <Input
                                    v-model="settings['recaptcha.site_key']"
                                    placeholder="Site Key"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Secret Key</label
                                >
                                <div class="relative">
                                    <Input
                                        v-model="
                                            settings['recaptcha.secret_key']
                                        "
                                        :type="
                                            showPasswords[
                                                'recaptcha.secret_key'
                                            ]
                                                ? 'text'
                                                : 'password'
                                        "
                                        placeholder="••••••••"
                                    />
                                    <button
                                        type="button"
                                        @click="
                                            togglePasswordVisibility(
                                                'recaptcha.secret_key',
                                            )
                                        "
                                        class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-[var(--text-muted)]"
                                    >
                                        <Eye
                                            v-if="
                                                !showPasswords[
                                                    'recaptcha.secret_key'
                                                ]
                                            "
                                            class="w-4 h-4"
                                        />
                                        <EyeOff v-else class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Twilio -->
                    <div>
                        <h4
                            class="text-sm font-medium text-[var(--text-primary)] mb-3"
                        >
                            Twilio (SMS)
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Account SID</label
                                >
                                <Input
                                    v-model="settings['twilio.sid']"
                                    placeholder="ACxxxxxx"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Auth Token</label
                                >
                                <div class="relative">
                                    <Input
                                        v-model="settings['twilio.auth_token']"
                                        :type="
                                            showPasswords['twilio.auth_token']
                                                ? 'text'
                                                : 'password'
                                        "
                                        placeholder="••••••••"
                                    />
                                    <button
                                        type="button"
                                        @click="
                                            togglePasswordVisibility(
                                                'twilio.auth_token',
                                            )
                                        "
                                        class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-[var(--text-muted)]"
                                    >
                                        <Eye
                                            v-if="
                                                !showPasswords[
                                                    'twilio.auth_token'
                                                ]
                                            "
                                            class="w-4 h-4"
                                        />
                                        <EyeOff v-else class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Verify Service SID</label
                                >
                                <Input
                                    v-model="settings['twilio.verify_sid']"
                                    placeholder="VAxxxxxx"
                                />
                            </div>
                        </div>
                    </div>
                    <!-- OpenAI -->
                    <div>
                        <h4
                            class="text-sm font-medium text-[var(--text-primary)] mb-3"
                        >
                            OpenAI
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >API Key</label
                                >
                                <div class="relative">
                                    <Input
                                        v-model="settings['openai.api_key']"
                                        :type="
                                            showPasswords['openai.api_key']
                                                ? 'text'
                                                : 'password'
                                        "
                                        placeholder="sk-••••••••"
                                    />
                                    <button
                                        type="button"
                                        @click="
                                            togglePasswordVisibility(
                                                'openai.api_key',
                                            )
                                        "
                                        class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-[var(--text-muted)]"
                                    >
                                        <Eye
                                            v-if="
                                                !showPasswords['openai.api_key']
                                            "
                                            class="w-4 h-4"
                                        />
                                        <EyeOff v-else class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Organization ID</label
                                >
                                <Input
                                    v-model="settings['openai.organization']"
                                    placeholder="org-xxxxxx (optional)"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Email Accounts Section -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden lg:col-span-2 p-6"
            >
                <EmailAccountsSection mode="system" />
            </div>

            <!-- Announcements Section -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden lg:col-span-2"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center justify-between"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="w-8 h-8 rounded-lg bg-amber-500/10 flex items-center justify-center"
                        >
                            <Megaphone class="w-4 h-4 text-amber-600" />
                        </div>
                        <h3 class="font-medium text-[var(--text-primary)]">
                            Site Announcements
                        </h3>
                    </div>
                    <Button
                        variant="primary"
                        size="sm"
                        @click="openAnnouncementModal()"
                    >
                        <Plus class="w-4 h-4" />
                        New Announcement
                    </Button>
                </div>
                <div class="p-4">
                    <div
                        v-if="isLoadingAnnouncements"
                        class="text-center py-8 text-[var(--text-muted)]"
                    >
                        <RefreshCw class="w-6 h-6 mx-auto mb-2 animate-spin" />
                        Loading...
                    </div>
                    <div
                        v-else-if="announcements.length === 0"
                        class="text-center py-8"
                    >
                        <Megaphone
                            class="w-10 h-10 text-[var(--text-muted)] mx-auto mb-2 opacity-50"
                        />
                        <p class="text-[var(--text-muted)]">
                            No announcements yet
                        </p>
                    </div>
                    <table v-else class="w-full text-sm">
                        <thead
                            class="text-xs text-[var(--text-muted)] uppercase"
                        >
                            <tr>
                                <th class="text-left py-2">Title</th>
                                <th class="text-left py-2">Type</th>
                                <th class="text-left py-2">Status</th>
                                <th class="text-left py-2">Schedule</th>
                                <th class="text-right py-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border-default)]">
                            <tr v-for="a in announcements" :key="a.id">
                                <td class="py-3">
                                    <p
                                        class="font-medium text-[var(--text-primary)]"
                                    >
                                        {{ a.title }}
                                    </p>
                                    <p
                                        class="text-xs text-[var(--text-muted)] truncate max-w-xs"
                                    >
                                        {{ a.message }}
                                    </p>
                                </td>
                                <td class="py-3">
                                    <span
                                        :class="[
                                            'inline-flex px-2 py-1 rounded text-xs font-medium capitalize',
                                            a.type === 'danger'
                                                ? 'bg-red-500/10 text-red-600'
                                                : a.type === 'warning'
                                                  ? 'bg-amber-500/10 text-amber-600'
                                                  : a.type === 'success'
                                                    ? 'bg-green-500/10 text-green-600'
                                                    : 'bg-blue-500/10 text-blue-600',
                                        ]"
                                        >{{ a.type }}</span
                                    >
                                </td>
                                <td class="py-3">
                                    <button
                                        @click="toggleAnnouncementActive(a)"
                                        :class="[
                                            'inline-flex px-2 py-1 rounded text-xs font-medium',
                                            a.is_active
                                                ? 'bg-green-500/10 text-green-600'
                                                : 'bg-gray-500/10 text-gray-500',
                                        ]"
                                    >
                                        {{
                                            a.is_active ? "Active" : "Inactive"
                                        }}
                                    </button>
                                </td>
                                <td
                                    class="py-3 text-xs text-[var(--text-secondary)]"
                                >
                                    <span v-if="a.starts_at || a.ends_at"
                                        >{{
                                            a.starts_at
                                                ? formatDateComposible(
                                                      a.starts_at,
                                                  )
                                                : "Now"
                                        }}
                                        →
                                        {{
                                            a.ends_at
                                                ? formatDateComposible(
                                                      a.ends_at,
                                                  )
                                                : "Forever"
                                        }}</span
                                    >
                                    <span
                                        v-else
                                        class="text-[var(--text-muted)]"
                                        >Always</span
                                    >
                                </td>
                                <td class="py-3 text-right">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-7 w-7"
                                        @click="openAnnouncementModal(a)"
                                        ><Edit2 class="w-3.5 h-3.5"
                                    /></Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-7 w-7 text-[var(--color-error)]"
                                        @click="deleteAnnouncement(a.id)"
                                        ><Trash2 class="w-3.5 h-3.5"
                                    /></Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Announcement Modal -->
        <Modal
            :open="showAnnouncementModal"
            :title="
                editingAnnouncement ? 'Edit Announcement' : 'New Announcement'
            "
            @update:open="showAnnouncementModal = $event"
        >
            <div class="space-y-4">
                <!-- Announcement Title Field -->
                <div class="space-y-1.5">
                    <label class="text-sm font-medium">Title</label>
                    <Input
                        v-model="announcementForm.title"
                        placeholder="System Maintenance"
                    />
                    <p
                        v-if="announcementErrors.title"
                        class="text-xs text-red-500"
                    >
                        {{ announcementErrors.title[0] }}
                    </p>
                </div>

                <!-- Message -->
                <div class="space-y-1.5">
                    <label class="text-sm font-medium">Message</label>
                    <textarea
                        v-model="announcementForm.message"
                        rows="3"
                        class="w-full px-3 py-2 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]"
                        placeholder="We will be undergoing maintenance..."
                    ></textarea>
                    <p
                        v-if="announcementErrors.message"
                        class="text-xs text-red-500"
                    >
                        {{ announcementErrors.message[0] }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <!-- Type -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium">Type</label>
                        <select
                            v-model="announcementForm.type"
                            class="w-full px-3 py-2 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]"
                        >
                            <option
                                v-for="type in announcementTypes"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </option>
                        </select>
                    </div>

                    <!-- Active Toggle -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium">Status</label>
                        <div class="flex items-center h-[38px] px-1">
                            <label
                                class="flex items-center gap-2 cursor-pointer"
                            >
                                <Switch v-model="announcementForm.is_active" />
                                <span class="text-sm">{{
                                    announcementForm.is_active
                                        ? "Active"
                                        : "Inactive"
                                }}</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <!-- Action Text -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium"
                            >Action Text (Optional)</label
                        >
                        <Input
                            v-model="announcementForm.action_text"
                            placeholder="Read More"
                        />
                    </div>

                    <!-- Action URL -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium"
                            >Action URL (Optional)</label
                        >
                        <Input
                            v-model="announcementForm.action_url"
                            placeholder="https://..."
                        />
                    </div>
                </div>

                <!-- Toggles -->
                <div class="flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <Switch v-model="announcementForm.is_public" />
                        <span class="text-sm"
                            >Show to public (non-logged-in users)</span
                        >
                    </div>
                    <div class="flex items-center gap-2">
                        <Switch v-model="announcementForm.is_dismissable" />
                        <span class="text-sm">Users can dismiss</span>
                    </div>
                </div>
            </div>

            <template #footer>
                <Button variant="ghost" @click="showAnnouncementModal = false">
                    Cancel
                </Button>
                <Button
                    variant="primary"
                    @click="saveAnnouncement"
                    :loading="isSavingAnnouncement"
                >
                    Save Announcement
                </Button>
            </template>
        </Modal>

        <!-- Demo Mode Password Modal -->
        <Modal
            :open="showDemoPasswordModal"
            @update:open="showDemoPasswordModal = $event"
            title="Verification Required"
            :description="`${pendingDemoState ? 'Enabling' : 'Disabling'} Demo Mode requires authentication. Please enter the secret phrase.`"
        >
            <div class="space-y-4">
                <Input
                    v-model="demoPassword"
                    type="password"
                    placeholder="Enter secret phrase..."
                    @keyup.enter="verifyDemoPassword"
                    autoFocus
                />
                <div class="flex justify-end gap-3">
                    <Button
                        variant="ghost"
                        @click="showDemoPasswordModal = false"
                        >Cancel</Button
                    >
                    <Button
                        variant="primary"
                        :loading="isVerifyingDemoPassword"
                        @click="verifyDemoPassword"
                    >
                        Verify & Toggle
                    </Button>
                </div>
            </div>
        </Modal>
    </div>

    <!-- Critical Settings Confirmation Modal -->
    <Modal
        :open="showCriticalConfirmation"
        @update:open="if (!$event) cancelCriticalSave();"
        title="Critical Setting Change"
        description="You are about to modify sensitive system configurations. Verification and a reason are required."
    >
        <div class="space-y-4">
            <div
                class="bg-amber-500/10 border border-amber-500/20 rounded-lg p-3"
            >
                <p class="text-xs text-amber-600 font-medium mb-1">
                    Changing the following settings:
                </p>
                <ul class="list-disc list-inside text-xs text-amber-700">
                    <li v-for="key in criticalChangesList" :key="key">
                        {{ key }}
                    </li>
                </ul>
            </div>

            <div class="space-y-1.5">
                <label class="text-sm font-medium">Your Password</label>
                <Input
                    v-model="criticalConfirmationForm.password"
                    type="password"
                    placeholder="Current password..."
                    autoFocus
                    @keyup.enter="confirmCriticalSave"
                />
            </div>

            <div class="space-y-1.5">
                <label class="text-sm font-medium">Reason for Change</label>
                <textarea
                    v-model="criticalConfirmationForm.reason"
                    rows="2"
                    class="w-full px-3 py-2 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]"
                    placeholder="e.g., Updating mail host for new provider..."
                ></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <Button variant="ghost" @click="cancelCriticalSave"
                    >Cancel</Button
                >
                <Button
                    variant="primary"
                    @click="confirmCriticalSave"
                    :disabled="
                        !criticalConfirmationForm.password ||
                        !criticalConfirmationForm.reason
                    "
                >
                    Confirm & Save
                </Button>
            </div>
        </div>
    </Modal>
    </div>
</template>
