<script setup lang="ts">
import { ref, onUnmounted, watch } from "vue";
import {
    StepperRoot,
    StepperItem,
    StepperIndicator,
    StepperTrigger,
    
    StepperTitle,
    
} from "reka-ui";
import { Modal, Button, Input } from "@/components/ui";
import {
    Mail,
    ArrowRight,
    
    CheckCircle2,
    AlertTriangle,
    AlertCircle,
    Loader2,
    Server,
    Shield,
    Info,
    ExternalLink,
    Check,
    Inbox,
    Send,
    Activity,
} from "lucide-vue-next";
import { toast } from "vue-sonner";
import api from "@/lib/api";

const props = defineProps<{
    open: boolean;
    mode?: "personal" | "system";
    account?: any; // For editing
}>();

const emit = defineEmits(["update:open", "saved"]);

// State
const step = ref(1);
const isLoading = ref(false);
const isTestingConfig = ref(false);
const isCheckingHealth = ref(false);
const healthResults = ref<any>(null);
const accountId = ref<string | null>(null);
const remoteFolders = ref<any[]>([]);
const selectedFolders = ref<string[]>([]);

const form = ref({
    provider: "",
    account_type: "full", // 'full' or 'smtp'
    email: "",
    name: "",
    // Custom IMAP
    imap_host: "",
    imap_port: 993,
    imap_encryption: "ssl",
    smtp_host: "",
    smtp_port: 587,
    smtp_encryption: "tls",
    username: "",
    password: "",
});

const providers = [
    {
        id: "gmail",
        name: "Gmail",
        icon: Mail,
        image: "/static/images/brands/gmail.svg",
        color: "text-red-600",
        bg: "bg-red-50 dark:bg-red-900/10",
        type: "full",
        supports_oauth: true,
    },
    {
        id: "outlook",
        name: "Outlook",
        icon: Mail,
        image: "/static/images/brands/outlook.svg",
        color: "text-blue-600",
        bg: "bg-blue-50 dark:bg-blue-900/10",
        type: "full",
        supports_oauth: true,
    },
    {
        id: "yahoo",
        name: "Yahoo",
        icon: Mail,
        image: "/static/images/brands/yahoo.svg",
        color: "text-purple-600",
        bg: "bg-purple-50 dark:bg-purple-900/10",
        type: "full",
        supports_oauth: false,
        imap: "imap.mail.yahoo.com",
        iport: 993,
        ienc: "ssl",
        smtp: "smtp.mail.yahoo.com",
        sport: 465,
        senc: "ssl",
    },
    {
        id: "zoho",
        name: "Zoho",
        icon: Mail,
        color: "text-red-500",
        bg: "bg-red-50 dark:bg-red-900/10",
        type: "full",
        supports_oauth: false,
        imap: "imap.zoho.com",
        iport: 993,
        ienc: "ssl",
        smtp: "smtp.zoho.com",
        sport: 465,
        senc: "ssl",
    },
    {
        id: "fastmail",
        name: "Fastmail",
        icon: Mail,
        color: "text-blue-800",
        bg: "bg-blue-50 dark:bg-blue-900/10",
        type: "full",
        supports_oauth: false,
        imap: "imap.fastmail.com",
        iport: 993,
        ienc: "ssl",
        smtp: "smtp.fastmail.com",
        sport: 465,
        senc: "ssl",
    },
    {
        id: "yandex",
        name: "Yandex",
        icon: Mail,
        color: "text-red-600",
        bg: "bg-red-50 dark:bg-red-900/10",
        type: "full",
        supports_oauth: false,
        imap: "imap.yandex.com",
        iport: 993,
        ienc: "ssl",
        smtp: "smtp.yandex.com",
        sport: 465,
        senc: "ssl",
    },
    {
        id: "gmx",
        name: "GMX",
        icon: Mail,
        color: "text-blue-700",
        bg: "bg-blue-50 dark:bg-blue-900/10",
        type: "full",
        supports_oauth: false,
        imap: "imap.gmx.com",
        iport: 993,
        ienc: "ssl",
        smtp: "mail.gmx.com",
        sport: 587,
        senc: "tls",
    },
    {
        id: "webde",
        name: "Web.de",
        icon: Mail,
        color: "text-blue-600",
        bg: "bg-blue-50 dark:bg-blue-900/10",
        type: "full",
        supports_oauth: false,
        imap: "imap.web.de",
        iport: 993,
        ienc: "ssl",
        smtp: "smtp.web.de",
        sport: 587,
        senc: "tls",
    },
    {
        id: "sendgrid",
        name: "SendGrid",
        icon: Server,
        color: "text-blue-500",
        bg: "bg-blue-50 dark:bg-blue-900/10",
        type: "smtp",
        host: "smtp.sendgrid.net",
        port: 587,
        enc: "tls",
    },
    {
        id: "ses",
        name: "Amazon SES",
        icon: Server,
        color: "text-orange-500",
        bg: "bg-orange-50 dark:bg-orange-900/10",
        type: "smtp",
        host: "email-smtp.us-east-1.amazonaws.com",
        port: 587,
        enc: "tls",
    },
    {
        id: "mailchimp",
        name: "Mailchimp",
        icon: Server,
        color: "text-yellow-600",
        bg: "bg-yellow-50 dark:bg-yellow-900/10",
        type: "smtp",
        host: "smtp.mandrillapp.com",
        port: 587,
        enc: "tls",
    },
    {
        id: "postmark",
        name: "Postmark",
        icon: Server,
        color: "text-yellow-500",
        bg: "bg-yellow-50 dark:bg-yellow-900/10",
        type: "smtp",
        host: "smtp.postmarkapp.com",
        port: 587,
        enc: "tls",
    },
    {
        id: "mailgun",
        name: "Mailgun",
        icon: Server,
        color: "text-red-500",
        bg: "bg-red-50 dark:bg-red-900/10",
        type: "smtp",
        host: "smtp.mailgun.org",
        port: 587,
        enc: "tls",
    },
    {
        id: "custom",
        name: "Other / Custom",
        icon: Server,
        color: "text-gray-600",
        bg: "bg-gray-100 dark:bg-gray-800",
        type: "any",
    },
];

const encryptionOptions = [
    { value: "ssl", label: "SSL" },
    { value: "tls", label: "TLS" },
    { value: "none", label: "None" },
];

const steps = [
    { number: 1, title: "Privacy" },
    { number: 2, title: "Mode" },
    { number: 3, title: "Provider" },
    { number: 4, title: "Connect" },
    { number: 5, title: "Health" },
    { number: 6, title: "Folders" },
    { number: 7, title: "Finish" },
];

// const totalSteps = 7;

const reset = () => {
    step.value = 1;
    accountId.value = null;
    remoteFolders.value = [];
    selectedFolders.value = [];
    healthResults.value = null;
    form.value = {
        provider: "",
        account_type: "full",
        email: "",
        name: "",
        imap_host: "",
        imap_port: 993,
        imap_encryption: "ssl",
        smtp_host: "",
        smtp_port: 587,
        smtp_encryption: "tls",
        username: "",
        password: "",
    };
};

const close = () => {
    emit("update:open", false);
    setTimeout(reset, 300);
};

// Initialize
watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            if (props.account) {
                accountId.value = props.account.id;
                form.value = { ...props.account };
                if (!form.value.provider) form.value.provider = "custom";
                fetchRemoteFolders();
            } else {
                reset();
            }
        }
    },
);

// Actions
const selectProvider = (p: any) => {
    form.value.provider = p.id;

    // Apply presets if available
    if (p.type === "smtp") {
        form.value.smtp_host = p.host || "";
        form.value.smtp_port = p.port || 587;
        form.value.smtp_encryption = p.enc || "tls";
        form.value.account_type = "smtp";
    } else if (p.type === "full") {
        form.value.account_type = "full";
        // Apply IMAP/SMTP presets for standard full providers if provided
        if (p.imap) {
            form.value.imap_host = p.imap;
            form.value.imap_port = p.iport || 993;
            form.value.imap_encryption = p.ienc || "ssl";
        }
        if (p.smtp) {
            form.value.smtp_host = p.smtp;
            form.value.smtp_port = p.sport || 587;
            form.value.smtp_encryption = p.senc || "tls";
        }
    }

    step.value = 4;
};

const runHealthCheck = async () => {
    if (!form.value.email) {
        toast.error("Please enter an email address first.");
        return;
    }

    try {
        isCheckingHealth.value = true;
        const { data } = await api.post("/api/email-accounts/pre-check", {
            email: form.value.email,
        });
        healthResults.value = data.data;
        
        // Smart Logic: Check for duplicates
        if (healthResults.value.existing_account) {
            const existing = healthResults.value.existing_account;
            if (existing.has_full_sync && form.value.account_type === 'smtp') {
                toast.warning(`${form.value.email} is already connected with Full Sync. SMTP-only setup is not recommended.`);
            } else if (existing.has_full_sync && form.value.account_type === 'full') {
                toast.info(`${form.value.email} is already connected. Checking health only.`);
            }
        }
        
        toast.success("Health check completed.");
    } catch (e: any) {
        toast.error("Health check failed.");
    } finally {
        isCheckingHealth.value = false;
    }
};

const connectOAuth = async () => {
    try {
        isLoading.value = true;
        const { data } = await api.get(
            `/api/email-accounts/oauth/${form.value.provider}/redirect?popup=1`,
        );

        const width = 600;
        const height = 700;
        const left = (window.screen.width - width) / 2;
        const top = (window.screen.height - height) / 2;

        const popup = window.open(
            data.redirect_url,
            "oauth_window",
            `width=${width},height=${height},top=${top},left=${left}`,
        );

        if (popup) {
            window.addEventListener("message", handleOAuthMessage);

            // Poll for popup closure as fallback
            const timer = setInterval(() => {
                if (popup.closed) {
                    clearInterval(timer);
                    if (!accountId.value && isLoading.value) {
                        checkRecentAccount();
                    }
                }
            }, 1000);
        } else {
            toast.error("Popup blocked. Please allow popups for this site.");
            isLoading.value = false;
        }
    } catch (e) {
        toast.error("Failed to start connection");
        isLoading.value = false;
    }
};

const checkRecentAccount = async () => {
    try {
        // Fallback: Check if account was created recently
        const { data } = await api.get("/api/email-accounts");
        const accounts = data.data || [];

        // Find account created in last 2 minutes with matching provider
        const recent = accounts.find((a: any) => {
            const created = new Date(a.created_at).getTime();
            const now = new Date().getTime();
            return a.provider === form.value.provider && now - created < 120000;
        });

        if (recent) {
            window.removeEventListener("message", handleOAuthMessage);
            accountId.value = recent.id;
            toast.success("Account connected successfully!");
            fetchRemoteFolders();
        } else {
            toast.error("Connection cancelled or failed.");
            isLoading.value = false;
        }
    } catch (e) {
        // If check fails
        isLoading.value = false;
    }
};

const handleOAuthMessage = (event: MessageEvent) => {
    if (event.data?.type === "oauth_success") {
        window.removeEventListener("message", handleOAuthMessage);
        accountId.value = event.data.account_id;
        if (event.data.email) {
            form.value.email = event.data.email;
        }
        toast.success("Account connected successfully!");
        
        // Refresh health check if we have email
        if (form.value.email) {
            runHealthCheck();
        }
        
        step.value = 5; // Go to Health Check step next
    } else if (event.data?.type === "oauth_error") {
        window.removeEventListener("message", handleOAuthMessage);
        toast.error(event.data.error || "Connection failed");
        isLoading.value = false;
    }
};

const connectCustom = async () => {
    try {
        isLoading.value = true;
        const { data } = await api.post("/api/email-accounts", {
            ...form.value,
            auth_type: "password",
            name: form.value.name || form.value.email,
        });

        accountId.value = data.data.id;
        toast.success("Account connected successfully!");
        
        // Proactive health check after connection if not already run
        if (!healthResults.value) {
            runHealthCheck();
        }
        
        step.value = 5; // Always go to Health Check for full sync too
    } catch (e: any) {
        toast.error(e.response?.data?.message || "Connection failed");
        isLoading.value = false;
    }
};

const testConfiguration = async () => {
    try {
        isTestingConfig.value = true;
        const { data } = await api.post(
            "/api/email-accounts/test-configuration",
            {
                ...form.value,
                auth_type: "password",
                provider: form.value.provider || "custom",
            },
        );

        if (data.success) {
            toast.success("Connection test successful!");
        } else {
            toast.error(data.message || "Connection test failed");
        }
    } catch (e: any) {
        toast.error(e.response?.data?.message || "Connection test failed");
    } finally {
        isTestingConfig.value = false;
    }
};

const fetchRemoteFolders = async () => {
    step.value = 6;
    isLoading.value = true;
    try {
        const { data } = await api.get(
            `/api/email-accounts/${accountId.value}/remote-folders`,
        );
        remoteFolders.value = data.data;
        // Default select all if new, or maintain selection if edit logic needed (but here we just fetch fresh)
        // If editing, we probably want to respect `disabled_folders` from account props,
        // but `fetchRemoteFolders` just gets the list.
        // We should calculate `selectedFolders` based on `remoteFolders` AND `props.account.disabled_folders` if exists.

        if (props.account && props.account.disabled_folders) {
            const disabled = props.account.disabled_folders || [];
            selectedFolders.value = remoteFolders.value
                .map((f) => f.name)
                .filter((name) => !disabled.includes(name));
        } else {
            selectedFolders.value = remoteFolders.value.map((f) => f.name);
        }
    } catch (e) {
        console.error(e);
        toast.error("Failed to fetch folders");
    } finally {
        isLoading.value = false;
    }
};

const toggleFolder = (name: string) => {
    if (selectedFolders.value.includes(name)) {
        selectedFolders.value = selectedFolders.value.filter((f) => f !== name);
    } else {
        selectedFolders.value.push(name);
    }
};

const saveFolders = async () => {
    if (!accountId.value) return;

    const disabled = remoteFolders.value
        .map((f) => f.name)
        .filter((name) => !selectedFolders.value.includes(name));

    try {
        isLoading.value = true;
        await api.put(`/api/email-accounts/${accountId.value}`, {
            disabled_folders: disabled,
        });
        // emit("saved"); // MOVED to Step 7 "Return to Dashboard"
        step.value = 7;
    } catch (e) {
        toast.error("Failed to save folder preferences");
    } finally {
        isLoading.value = false;
    }
};

onUnmounted(() => {
    window.removeEventListener("message", handleOAuthMessage);
});
</script>

<template>
    <Modal
        :open="open"
        @update:open="emit('update:open', $event)"
        title="Connect Email Account"
        size="3xl"
    >
        <template #title>
            <span>{{
                props.account ? "Edit Email Account" : "Connect Email Account"
            }}</span>
        </template>

        <div class="space-y-8 min-h-[450px] flex flex-col">
            <!-- Stepper -->
            <StepperRoot
                :model-value="step"
                class="flex items-start justify-between px-10 w-full max-w-2xl mx-auto relative mb-10"
            >
                <StepperItem
                    v-for="s in steps"
                    :key="s.number"
                    :step="s.number"
                    class="flex flex-col items-center relative z-10 flex-1 group"
                >
                    <StepperTrigger
                        class="focus:outline-none focus:ring-2 focus:ring-(--brand-primary)/10 rounded-full cursor-default mb-2 transition-transform duration-300"
                    >
                        <StepperIndicator
                            class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold transition-all duration-500 border shadow-xs"
                            :class="[
                                step > s.number
                                    ? 'bg-linear-to-br from-emerald-500 to-emerald-600 border-emerald-400 text-white shadow-emerald-500/10'
                                    : step === s.number
                                      ? 'bg-(--surface-primary) border-(--brand-primary) text-(--brand-primary) ring-2 ring-(--brand-primary)/5 shadow-sm scale-110'
                                      : 'bg-(--surface-secondary) border-(--border-subtle) text-(--text-muted) opacity-50',
                            ]"
                        >
                            <Check
                                v-if="step > s.number"
                                class="w-3.5 h-3.5 stroke-[4px]"
                            />
                            <span v-else>{{ s.number }}</span>
                        </StepperIndicator>
                    </StepperTrigger>

                    <div
                        class="flex flex-col items-center justify-center w-full min-h-6 px-1"
                    >
                        <StepperTitle
                            class="text-[10px] font-bold transition-all duration-300 text-center leading-tight whitespace-nowrap"
                            :class="[
                                step > s.number
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : step === s.number
                                      ? 'text-(--brand-primary)'
                                      : 'text-(--text-muted) opacity-40',
                            ]"
                        >
                            {{ s.title }}
                        </StepperTitle>
                    </div>

                    <!-- Enhanced Separator -->
                    <div
                        v-if="s.number < steps.length"
                        class="absolute top-3.5 left-[calc(50%+0.875rem)] right-[calc(-50%+0.875rem)] h-px bg-(--border-default)/30 rounded-full -z-10"
                    >
                        <div
                            class="absolute left-0 h-full bg-linear-to-r from-emerald-500 to-emerald-600 rounded-full transition-all duration-700 ease-in-out"
                            :style="{ width: step > s.number ? '100%' : '0%' }"
                        ></div>
                    </div>
                </StepperItem>
            </StepperRoot>

            <!-- Content Area -->
            <div
                class="flex-1 relative mt-4 overflow-hidden flex flex-col min-h-[420px]"
            >
                <Transition name="fade" mode="out-in">
                    <!-- Step 1: Privacy Notice -->
                    <div
                        v-if="step === 1"
                        class="space-y-6 w-full max-w-2xl mx-auto animate-in fade-in slide-in-from-bottom-4 duration-500 px-4"
                    >
                        <div class="text-center space-y-2">
                            <h2
                                class="text-2xl font-bold tracking-tight bg-linear-to-r from-(--brand-primary) to-(--brand-primary-hover) bg-clip-text text-transparent"
                            >
                                Private & Secure Email Sync
                            </h2>
                            <p
                                class="text-(--text-secondary) text-sm max-w-xl mx-auto"
                            >
                                We prioritize your privacy with strict data
                                retention policies.
                            </p>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div
                                class="p-6 rounded-2xl border border-(--border-default) bg-(--surface-secondary)/40 backdrop-blur-sm space-y-4 hover:border-(--brand-primary)/30 transition-colors"
                            >
                                <div
                                    class="w-12 h-12 rounded-xl bg-(--brand-primary)/10 flex items-center justify-center shadow-sm"
                                >
                                    <Server
                                        class="w-6 h-6 text-(--brand-primary)"
                                    />
                                </div>
                                <h3 class="font-bold text-lg">
                                    Non-Permanent Storage
                                </h3>
                                <p
                                    class="text-sm text-(--text-secondary) leading-relaxed"
                                >
                                    Emails are automatically removed after
                                    <strong class="text-(--text-primary)"
                                        >90 days</strong
                                    >. Trash & Spam are cleared after
                                    <strong class="text-(--text-primary)"
                                        >30 days</strong
                                    >.
                                </p>
                            </div>

                            <div
                                class="p-6 rounded-2xl border border-(--border-default) bg-(--surface-secondary)/40 backdrop-blur-sm space-y-4 hover:border-emerald-500/30 transition-colors"
                            >
                                <div
                                    class="w-12 h-12 rounded-xl bg-emerald-500/10 flex items-center justify-center shadow-sm"
                                >
                                    <Shield class="w-6 h-6 text-emerald-600" />
                                </div>
                                <h3 class="font-bold text-lg">
                                    Read-Only Sync
                                </h3>
                                <p
                                    class="text-sm text-(--text-secondary) leading-relaxed"
                                >
                                    Deleting emails in WorkSphere
                                    <strong class="text-(--text-primary)"
                                        >does not</strong
                                    >
                                    delete them from your provider.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Account Type Selection -->
                    <div
                        v-else-if="step === 2"
                        class="space-y-8 w-full max-w-2xl mx-auto animate-in fade-in slide-in-from-bottom-4 duration-500 px-4"
                    >
                        <div class="text-center space-y-2">
                            <h2 class="text-2xl font-bold tracking-tight">
                                How will you use this account?
                            </h2>
                            <p class="text-(--text-secondary) text-sm">
                                Select the mode that best fits your needs.
                            </p>
                        </div>

                        <div class="grid md:grid-cols-2 gap-6">
                            <button
                                @click="
                                    form.account_type = 'full';
                                    step = 3;
                                "
                                class="p-8 rounded-3xl border-2 transition-all text-left space-y-4 relative group overflow-hidden"
                                :class="
                                    form.account_type === 'full'
                                        ? 'border-(--brand-primary) bg-(--brand-primary)/5 shadow-xl shadow-(--brand-primary)/10'
                                        : 'border-(--border-default) bg-(--surface-secondary)/30 hover:border-(--brand-primary)/40'
                                "
                            >
                                <div
                                    class="w-16 h-16 rounded-2xl bg-(--brand-primary)/10 flex items-center justify-center shrink-0"
                                >
                                    <Inbox
                                        class="w-8 h-8 text-(--brand-primary)"
                                    />
                                </div>
                                <div class="space-y-2">
                                    <h3 class="font-bold text-xl">
                                        Full Synchronization
                                    </h3>
                                    <p
                                        class="text-sm text-(--text-secondary) leading-relaxed"
                                    >
                                        Sync all folders, read emails, and send
                                        replies. Perfect for personal or
                                        professional mailboxes.
                                    </p>
                                </div>
                                <div
                                    v-if="form.account_type === 'full'"
                                    class="absolute top-4 right-4 text-(--brand-primary)"
                                >
                                    <CheckCircle2 class="w-6 h-6" />
                                </div>
                            </button>

                            <button
                                @click="
                                    form.account_type = 'smtp';
                                    step = 3;
                                "
                                class="p-8 rounded-3xl border-2 transition-all text-left space-y-4 relative group overflow-hidden"
                                :class="
                                    form.account_type === 'smtp'
                                        ? 'border-orange-500 bg-orange-500/5 shadow-xl shadow-orange-500/10'
                                        : 'border-(--border-default) bg-(--surface-secondary)/30 hover:border-orange-500/40'
                                "
                            >
                                <div
                                    class="w-16 h-16 rounded-2xl bg-orange-500/10 flex items-center justify-center shrink-0 text-orange-600"
                                >
                                    <Send class="w-8 h-8" />
                                </div>
                                <div class="space-y-2">
                                    <h3 class="font-bold text-xl">
                                        SMTP Only (Sending)
                                    </h3>
                                    <p
                                        class="text-sm text-(--text-secondary) leading-relaxed"
                                    >
                                        For transactional providers like
                                        SendGrid or SES. Send emails without
                                        syncing an inbox.
                                    </p>
                                </div>
                                <div
                                    v-if="form.account_type === 'smtp'"
                                    class="absolute top-4 right-4 text-orange-600"
                                >
                                    <CheckCircle2 class="w-6 h-6" />
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Step 3: Provider Selection -->
                    <div
                        v-else-if="step === 3"
                        class="space-y-6 w-full max-w-2xl mx-auto animate-in fade-in slide-in-from-bottom-4 duration-500 px-4"
                    >
                        <div class="text-center space-y-2">
                            <h2 class="text-2xl font-bold tracking-tight">
                                {{
                                    form.account_type === "smtp"
                                        ? "Select SMTP Provider"
                                        : "Select Email Provider"
                                }}
                            </h2>
                            <div v-if="healthResults?.existing_account?.has_full_sync" class="mt-2 p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl text-[12px] text-amber-600 flex items-center gap-3">
                                <AlertTriangle class="w-4 h-4 shrink-0" />
                                <p>You already have a <strong>Full Sync</strong> account for this email. We recommend using it instead of adding another SMTP-only account.</p>
                            </div>
                            <p class="text-(--text-secondary) text-sm">
                                Choose the service you're currently using.
                            </p>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <button
                                v-for="p in providers.filter(
                                    (p) =>
                                        p.type === form.account_type ||
                                        p.type === 'any',
                                )"
                                :key="p.id"
                                @click="selectProvider(p)"
                                class="flex flex-col items-center gap-4 p-6 rounded-2xl border border-(--border-default) bg-(--surface-secondary)/50 backdrop-blur-sm hover:border-(--brand-primary) hover:shadow-xl hover:shadow-(--brand-primary)/5 hover:bg-(--surface-tertiary) transition-all group relative overflow-hidden active:scale-[0.98]"
                            >
                                <div
                                    class="w-16 h-16 rounded-2xl flex items-center justify-center shrink-0 transition-all duration-300 group-hover:scale-110 shadow-sm overflow-hidden"
                                    :class="p.bg"
                                >
                                    <img
                                        v-if="p.image"
                                        :src="p.image"
                                        class="w-8 h-8 object-contain"
                                        :alt="p.name"
                                    />
                                    <component
                                        v-else
                                        :is="p.icon"
                                        class="w-8 h-8"
                                        :class="p.color"
                                    />
                                </div>
                                <div class="text-center">
                                    <h4
                                        class="font-bold text-(--text-primary) text-sm"
                                    >
                                        {{ p.name }}
                                    </h4>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Step 4: Connection -->
                    <div
                        v-else-if="step === 4"
                        class="space-y-6 w-full max-w-xl mx-auto animate-in fade-in slide-in-from-bottom-4 duration-500 px-4"
                    >
                        <div class="text-center space-y-4">
                            <div
                                class="w-24 h-24 rounded-[32px] flex items-center justify-center mx-auto shadow-lg ring-8 ring-(--surface-secondary)/50 overflow-hidden"
                                :class="
                                    providers.find(
                                        (p) => p.id === form.provider,
                                    )?.bg || 'bg-gray-100'
                                "
                            >
                                <img
                                    v-if="
                                        providers.find(
                                            (p) => p.id === form.provider,
                                        )?.image
                                    "
                                    :src="
                                        providers.find(
                                            (p) => p.id === form.provider,
                                        )?.image
                                    "
                                    class="w-12 h-12 object-contain"
                                    :alt="
                                        providers.find(
                                            (p) => p.id === form.provider,
                                        )?.name
                                    "
                                />
                                <component
                                    v-else
                                    :is="
                                        providers.find(
                                            (p) => p.id === form.provider,
                                        )?.icon
                                    "
                                    class="w-12 h-12"
                                    :class="
                                        providers.find(
                                            (p) => p.id === form.provider,
                                        )?.color
                                    "
                                />
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold tracking-tight">
                                    Connect
                                    {{
                                        providers.find(
                                            (p) => p.id === form.provider,
                                        )?.name
                                    }}
                                </h2>
                                <p class="text-(--text-secondary) text-sm mt-1">
                                    {{
                                        form.provider === "gmail" ||
                                        form.provider === "outlook"
                                            ? "Authenticate via popup window."
                                            : "Enter your server details."
                                    }}
                                </p>
                            </div>
                        </div>

                        <!-- OAuth Button -->
                        <div
                            v-if="
                                form.provider === 'gmail' ||
                                form.provider === 'outlook'
                            "
                            class="w-full pt-4"
                        >
                            <Button
                                size="lg"
                                class="w-full h-14 text-base relative overflow-hidden font-bold rounded-2xl shadow-lg hover:shadow-(--brand-primary)/25"
                                :disabled="isLoading"
                                @click="connectOAuth"
                            >
                                <Loader2
                                    v-if="isLoading"
                                    class="w-5 h-5 animate-spin mr-2"
                                />
                                <ExternalLink v-else class="w-5 h-5 mr-3" />
                                {{
                                    isLoading
                                        ? "Waiting for popup..."
                                        : `Authorize ${providers.find((p) => p.id === form.provider)?.name}`
                                }}
                            </Button>
                            <div
                                class="flex items-start gap-3 mt-6 p-4 bg-blue-500/5 border border-blue-500/10 rounded-2xl text-[13px] text-(--text-secondary) leading-relaxed"
                            >
                                <Info
                                    class="w-5 h-5 shrink-0 mt-0.5 text-blue-500"
                                />
                                <p>
                                    Ensure popups are allowed for this site. The
                                    authentication happens securely on your
                                    provider's page.
                                </p>
                            </div>
                        </div>

                        <!-- Manual Form -->
                        <div v-else class="space-y-4">
                            <div class="space-y-4">
                                <div class="space-y-2">
                                    <label
                                        class="text-[10px] font-bold uppercase tracking-wider text-(--text-muted) px-1"
                                        >Account Info</label
                                    >
                                    <div class="grid grid-cols-1 gap-2.5">
                                        <Input
                                            v-model="form.email"
                                            placeholder="Email Address"
                                            class="h-11 rounded-xl"
                                            @blur="form.email && runHealthCheck()"
                                        />
                                        <div v-if="healthResults?.existing_account?.has_full_sync" class="p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl text-[11px] text-amber-600 flex items-start gap-2">
                                            <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5" />
                                            <div>
                                                <p class="font-bold">Account already exists</p>
                                                <p v-if="form.account_type === 'smtp'">A Full Sync account for this email is already connected. Adding SMTP-only is redundant.</p>
                                                <p v-else>A Full Sync account for this email is already connected. You can edit it instead.</p>
                                            </div>
                                        </div>
                                        <Input
                                            v-model="form.name"
                                            placeholder="Display Name (e.g. Work Email)"
                                            class="h-11 rounded-xl"
                                        />
                                    </div>
                                </div>

                                <div
                                    class="border-t border-dashed border-(--border-default) opacity-50 py-1"
                                ></div>

                                <div
                                    v-if="form.account_type === 'full'"
                                    class="space-y-2"
                                >
                                    <label
                                        class="text-[10px] font-bold uppercase tracking-wider text-(--text-muted) px-1"
                                        >Incoming (IMAP)</label
                                    >
                                    <div class="grid grid-cols-12 gap-2.5">
                                        <div class="col-span-6">
                                            <Input
                                                v-model="form.imap_host"
                                                placeholder="imap.server.com"
                                                class="h-11 rounded-xl"
                                            />
                                        </div>
                                        <div class="col-span-3">
                                            <Input
                                                v-model.number="form.imap_port"
                                                placeholder="Port"
                                                class="h-11 rounded-xl"
                                            />
                                        </div>
                                        <div class="col-span-3">
                                            <select
                                                v-model="form.imap_encryption"
                                                class="w-full h-11 px-3 bg-(--surface-primary) border border-(--border-default) rounded-xl text-sm focus:outline-none focus:ring-4 focus:ring-(--brand-primary)/10 focus:border-(--brand-primary) transition-all"
                                            >
                                                <option
                                                    v-for="opt in encryptionOptions"
                                                    :key="opt.value"
                                                    :value="opt.value"
                                                >
                                                    {{ opt.label }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <label
                                        class="text-[10px] font-bold uppercase tracking-wider text-(--text-muted) px-1"
                                        >Outgoing (SMTP)</label
                                    >
                                    <div class="grid grid-cols-12 gap-2.5">
                                        <div class="col-span-6">
                                            <Input
                                                v-model="form.smtp_host"
                                                placeholder="smtp.server.com"
                                                class="h-11 rounded-xl"
                                            />
                                        </div>
                                        <div class="col-span-3">
                                            <Input
                                                v-model.number="form.smtp_port"
                                                placeholder="Port"
                                                class="h-11 rounded-xl"
                                            />
                                        </div>
                                        <div class="col-span-3">
                                            <select
                                                v-model="form.smtp_encryption"
                                                class="w-full h-11 px-3 bg-(--surface-primary) border border-(--border-default) rounded-xl text-sm focus:outline-none focus:ring-4 focus:ring-(--brand-primary)/10 focus:border-(--brand-primary) transition-all"
                                            >
                                                <option
                                                    v-for="opt in encryptionOptions"
                                                    :key="opt.value"
                                                    :value="opt.value"
                                                >
                                                    {{ opt.label }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="border-t border-dashed border-(--border-default) opacity-50 py-1"
                                ></div>

                                <div class="space-y-2">
                                    <label
                                        class="text-[10px] font-bold uppercase tracking-wider text-(--text-muted) px-1"
                                        >Credentials</label
                                    >
                                    <div class="grid grid-cols-1 gap-2.5">
                                        <Input
                                            v-model="form.username"
                                            placeholder="Username (usually same as email)"
                                            class="h-11 rounded-xl"
                                        />
                                        <Input
                                            v-model="form.password"
                                            type="password"
                                            :placeholder="
                                                form.account_type === 'smtp'
                                                    ? 'API Key / Auth Token'
                                                    : 'App Password / Login Password'
                                            "
                                            class="h-11 rounded-xl"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="flex gap-4 pt-4">
                                <Button
                                    variant="secondary"
                                    class="flex-1 rounded-xl h-12 font-bold"
                                    :disabled="isLoading || isTestingConfig"
                                    @click="testConfiguration"
                                >
                                    <Loader2
                                        v-if="isTestingConfig"
                                        class="w-4 h-4 animate-spin mr-2"
                                    />
                                    <CheckCircle2 v-else class="w-4 h-4 mr-2" />
                                    Test Connection
                                </Button>
                                <Button
                                    class="flex-1 rounded-xl h-12 font-bold shadow-lg shadow-(--brand-primary)/20"
                                    :disabled="isLoading || isTestingConfig || (form.account_type === 'smtp' && healthResults?.existing_account?.has_full_sync)"
                                    @click="connectCustom"
                                >
                                    <Loader2
                                        v-if="isLoading"
                                        class="w-4 h-4 animate-spin mr-2"
                                    />
                                    <Check v-else class="w-4 h-4 mr-2" />
                                    {{ (form.account_type === 'smtp' && healthResults?.existing_account?.has_full_sync) ? 'Already Connected' : 'Connect' }}
                                </Button>
                            </div>
                        </div>
                    </div>

                    <!-- Step 5: Health Check -->
                    <div
                        v-else-if="step === 5"
                        class="space-y-6 w-full max-w-2xl mx-auto animate-in fade-in slide-in-from-bottom-4 duration-500 px-4"
                    >
                        <div class="text-center space-y-2">
                            <h2
                                class="text-2xl font-bold tracking-tight text-(--text-primary)"
                            >
                                Domain Health Status
                            </h2>
                            <p class="text-(--text-secondary) text-sm">
                                Verifying DNS settings for
                                <strong class="text-(--text-primary)">{{
                                    form.email
                                }}</strong>
                            </p>
                        </div>

                        <div
                            v-if="!healthResults"
                            class="flex flex-col items-center justify-center py-12 space-y-4 bg-(--surface-secondary)/20 rounded-3xl border border-dashed border-(--border-default)"
                        >
                            <div
                                class="w-20 h-20 rounded-full bg-(--brand-primary)/10 flex items-center justify-center animate-pulse"
                            >
                                <Activity
                                    class="w-10 h-10 text-(--brand-primary)"
                                />
                            </div>
                            <Button
                                @click="runHealthCheck"
                                :disabled="isCheckingHealth"
                                size="lg"
                                class="shadow-lg shadow-(--brand-primary)/20"
                            >
                                <Loader2
                                    v-if="isCheckingHealth"
                                    class="w-4 h-4 animate-spin mr-2"
                                />
                                Run Health Check Now
                            </Button>
                            <p
                                class="text-[11px] text-(--text-muted) max-w-sm text-center font-medium leading-relaxed"
                            >
                                This check verifies MX, SPF, DKIM, and DMARC
                                records to ensure excellent deliverability and
                                inbox security.
                            </p>
                        </div>

                        <div v-else class="space-y-4">
                            <div class="grid gap-3">
                                <!-- MX Record -->
                                <div
                                    class="p-5 rounded-3xl border transition-all duration-300"
                                    :class="
                                        healthResults.mx.status
                                            ? 'bg-emerald-500/3 border-emerald-500/20'
                                            : 'bg-red-500/3 border-red-500/20'
                                    "
                                >
                                    <div
                                        class="flex items-center justify-between mb-1.5"
                                    >
                                        <span
                                            class="font-black text-[11px] uppercase tracking-widest text-(--text-secondary)"
                                            >MX Records (Receiving)</span
                                        >
                                        <span
                                            :class="
                                                healthResults.mx.status
                                                    ? 'text-emerald-500'
                                                    : 'text-red-500'
                                            "
                                            class="flex items-center gap-1.5 text-xs font-black"
                                        >
                                            <CheckCircle2
                                                v-if="healthResults.mx.status"
                                                class="w-4 h-4"
                                            />
                                            <AlertCircle
                                                v-else
                                                class="w-4 h-4"
                                            />
                                            {{
                                                healthResults.mx.status
                                                    ? "PASSED"
                                                    : "REQUIRED"
                                            }}
                                        </span>
                                    </div>
                                    <p
                                        class="text-sm font-medium text-(--text-primary) leading-snug"
                                    >
                                        {{ healthResults.mx.message }}
                                    </p>
                                </div>

                                <!-- SPF Record -->
                                <div
                                    class="p-5 rounded-3xl border transition-all duration-300"
                                    :class="
                                        healthResults.spf.status
                                            ? 'bg-emerald-500/3 border-emerald-500/20'
                                            : 'bg-yellow-500/3 border-yellow-500/20'
                                    "
                                >
                                    <div
                                        class="flex items-center justify-between mb-1.5"
                                    >
                                        <span
                                            class="font-black text-[11px] uppercase tracking-widest text-(--text-secondary)"
                                            >SPF Record (Identity)</span
                                        >
                                        <span
                                            :class="
                                                healthResults.spf.status
                                                    ? 'text-emerald-500'
                                                    : 'text-yellow-600'
                                            "
                                            class="flex items-center gap-1.5 text-xs font-black"
                                        >
                                            <CheckCircle2
                                                v-if="healthResults.spf.status"
                                                class="w-4 h-4"
                                            />
                                            <AlertTriangle
                                                v-else
                                                class="w-4 h-4"
                                            />
                                            {{
                                                healthResults.spf.status
                                                    ? "PASSED"
                                                    : "WARNING"
                                            }}
                                        </span>
                                    </div>
                                    <p
                                        class="text-sm font-medium text-(--text-primary) leading-snug"
                                    >
                                        {{ healthResults.spf.message }}
                                    </p>
                                </div>

                                <!-- DMARC Record -->
                                <div
                                    class="p-5 rounded-3xl border transition-all duration-300"
                                    :class="
                                        healthResults.dmarc.status
                                            ? 'bg-emerald-500/3 border-emerald-500/20'
                                            : 'bg-yellow-500/3 border-yellow-500/20'
                                    "
                                >
                                    <div
                                        class="flex items-center justify-between mb-1.5"
                                    >
                                        <span
                                            class="font-black text-[11px] uppercase tracking-widest text-(--text-secondary)"
                                            >DMARC Policy (Security)</span
                                        >
                                        <span
                                            :class="
                                                healthResults.dmarc.status
                                                    ? 'text-emerald-500'
                                                    : 'text-yellow-600'
                                            "
                                            class="flex items-center gap-1.5 text-xs font-black"
                                        >
                                            <CheckCircle2
                                                v-if="
                                                    healthResults.dmarc.status
                                                "
                                                class="w-4 h-4"
                                            />
                                            <AlertTriangle
                                                v-else
                                                class="w-4 h-4"
                                            />
                                            {{
                                                healthResults.dmarc.status
                                                    ? "PASSED"
                                                    : "WARNING"
                                            }}
                                        </span>
                                    </div>
                                    <p
                                        class="text-sm font-medium text-(--text-primary) leading-snug"
                                    >
                                        {{ healthResults.dmarc.message }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="
                                    !healthResults.mx.status &&
                                    form.account_type === 'full'
                                "
                                class="p-5 rounded-[24px] bg-red-500/10 border border-red-500/20 text-red-600 text-[13px] flex gap-4 font-medium animate-in zoom-in-95 duration-300 shadow-xl shadow-red-500/5"
                            >
                                <AlertCircle class="w-6 h-6 shrink-0" />
                                <p class="leading-relaxed">
                                    MX records are missing. You
                                    <strong>cannot receive emails</strong> in
                                    Full Synchronization mode until these are
                                    configured.
                                </p>
                            </div>
                            <div
                                v-else-if="
                                    !healthResults.spf.status ||
                                    !healthResults.dmarc.status ||
                                    !healthResults.mx.status
                                "
                                class="p-5 rounded-[24px] bg-yellow-500/10 border border-yellow-500/20 text-yellow-700 text-[13px] flex gap-4 font-medium animate-in zoom-in-95 duration-300 shadow-xl shadow-yellow-500/5"
                            >
                                <AlertTriangle class="w-6 h-6 shrink-0" />
                                <p class="leading-relaxed">
                                    Some health checks have warnings. Your
                                    emails may be flagged as spam by recipients,
                                    but you can still proceed.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Step 6: Folder Selection -->
                    <div
                        v-else-if="step === 6"
                        class="w-full h-full flex flex-col animate-in fade-in slide-in-from-bottom-4 duration-500 px-4"
                    >
                        <div class="mb-6 text-center">
                            <h2
                                class="text-2xl font-bold tracking-tight text-(--text-primary)"
                            >
                                Sync Folders
                            </h2>
                            <p class="text-(--text-secondary) text-sm mt-1">
                                Select the folders you want to access in
                                WorkSphere.
                            </p>
                        </div>

                        <div
                            v-if="isLoading"
                            class="flex-1 flex flex-col items-center justify-center min-h-[200px]"
                        >
                            <Loader2
                                class="w-12 h-12 animate-spin text-(--brand-primary) mb-4 opacity-50"
                            />
                            <p
                                class="text-base font-semibold text-(--text-secondary)"
                            >
                                Discovering folders...
                            </p>
                        </div>

                        <div v-else class="flex-1 flex flex-col">
                            <div class="flex justify-end px-2 mb-3">
                                <button
                                    @click="
                                        selectedFolders = remoteFolders.map(
                                            (f) => f.name,
                                        )
                                    "
                                    class="text-xs font-black uppercase tracking-widest text-(--brand-primary) hover:text-(--brand-primary-hover) px-3 py-1.5 transition-colors"
                                >
                                    Select All
                                </button>
                                <div
                                    class="w-px h-3 bg-(--border-default) self-center mx-1"
                                ></div>
                                <button
                                    @click="selectedFolders = []"
                                    class="text-xs font-black uppercase tracking-widest text-(--text-muted) hover:text-(--text-secondary) px-3 py-1.5 transition-colors"
                                >
                                    Deselect All
                                </button>
                            </div>

                            <div
                                class="flex-1 overflow-y-auto border border-(--border-default) rounded-[32px] p-3 max-h-[380px] bg-(--surface-secondary)/20 backdrop-blur-xl space-y-2.5 scrollbar-thin"
                            >
                                <div
                                    v-for="folder in remoteFolders"
                                    :key="folder.name"
                                    @click="toggleFolder(folder.name)"
                                    class="flex items-center gap-4 p-5 rounded-[24px] cursor-pointer select-none transition-all duration-300 group border-2"
                                    :class="
                                        selectedFolders.includes(folder.name)
                                            ? 'bg-(--brand-primary)/10 border-(--brand-primary) shadow-xl shadow-(--brand-primary)/10 scale-[1.02]'
                                            : 'bg-(--surface-primary)/50 border-(--border-subtle) hover:bg-(--surface-primary) hover:border-(--brand-primary)/40'
                                    "
                                >
                                    <div
                                        class="relative flex items-center justify-center w-8 h-8 rounded-xl border-2 transition-all duration-300 shrink-0"
                                        :class="
                                            selectedFolders.includes(
                                                folder.name,
                                            )
                                                ? 'bg-(--brand-primary) border-(--brand-primary) shadow-sm'
                                                : 'border-(--border-strong) bg-(--surface-elevated) group-hover:border-(--brand-primary)/50'
                                        "
                                    >
                                        <Check
                                            v-if="
                                                selectedFolders.includes(
                                                    folder.name,
                                                )
                                            "
                                            class="w-5 h-5 text-white stroke-[3.5px]"
                                        />
                                    </div>
                                    <div class="flex flex-col min-w-0">
                                        <span
                                            class="text-base font-black tracking-tight transition-colors"
                                            :class="
                                                selectedFolders.includes(
                                                    folder.name,
                                                )
                                                    ? 'text-(--brand-primary)'
                                                    : 'text-(--text-secondary) group-hover:text-(--text-primary)'
                                            "
                                        >
                                            {{ folder.name }}
                                        </span>
                                        <span
                                            class="text-[10px] font-black uppercase tracking-widest transition-colors"
                                            :class="
                                                selectedFolders.includes(
                                                    folder.name,
                                                )
                                                    ? 'text-(--brand-primary)/70'
                                                    : 'text-(--text-muted) opacity-50'
                                            "
                                        >
                                            {{
                                                selectedFolders.includes(
                                                    folder.name,
                                                )
                                                    ? "Synchronized"
                                                    : "Hidden"
                                            }}
                                        </span>
                                    </div>
                                </div>
                                <div
                                    v-if="remoteFolders.length === 0"
                                    class="p-10 text-center text-(--text-muted) italic"
                                >
                                    <p>No folders discovered.</p>
                                    <p
                                        class="text-xs mt-2 font-black uppercase tracking-widest opacity-50"
                                    >
                                        We will sync your Inbox by default.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 7: Finish -->
                    <div
                        v-else-if="step === 7"
                        class="flex-1 w-full max-w-2xl mx-auto flex flex-col items-center justify-center text-center p-8 space-y-8 animate-in fade-in duration-500"
                    >
                        <div
                            class="w-24 h-24 rounded-full bg-emerald-500/10 flex items-center justify-center mb-8 mx-auto relative group"
                        >
                            <div
                                class="rounded-full bg-emerald-500/10 animate-ping duration-1000"
                            ></div>
                            <CheckCircle2
                                class="w-12 h-12 text-emerald-600 transition-transform duration-500 group-hover:scale-110"
                            />
                        </div>

                        <div class="space-y-4">
                            <h2
                                class="text-4xl font-black tracking-tight text-(--text-primary)"
                            >
                                You're all set!
                            </h2>
                            <p
                                class="text-(--text-secondary) text-lg font-medium leading-relaxed"
                            >
                                Your account is now connected. We've started
                                syncing your emails in the background.
                            </p>
                        </div>

                        <div class="mt-12">
                            <Button
                                size="xl"
                                class="min-w-[240px] rounded-2xl shadow-2xl shadow-(--brand-primary)/25"
                                @click="
                                    emit('saved');
                                    close();
                                "
                            >
                                Return to Dashboard
                            </Button>
                        </div>
                    </div>
                </Transition>
            </div>
        </div>

        <template #footer>
            <div
                class="flex w-full items-center justify-between pt-6 border-t border-(--border-default) mt-6"
                v-if="step < 7"
            >
                <Button
                    variant="ghost"
                    @click="step > 1 ? step-- : close()"
                    :disabled="isLoading || isTestingConfig"
                    class="px-6 rounded-xl font-bold"
                >
                    {{ step === 1 ? "Cancel" : "Back" }}
                </Button>

                <div v-if="step === 1">
                    <Button
                        @click="step++"
                        class="px-8 rounded-xl font-black uppercase tracking-widest text-[11px] shadow-lg shadow-(--brand-primary)/10"
                    >
                        I Understand & Continue
                    </Button>
                </div>
                <div v-else-if="step === 5">
                    <Button
                        class="px-8 rounded-xl font-black uppercase tracking-widest text-[11px] shadow-lg shadow-(--brand-primary)/10"
                        :disabled="
                            isCheckingHealth ||
                            (healthResults &&
                                !healthResults.mx.status &&
                                form.account_type === 'full')
                        "
                        @click="
                            form.account_type === 'smtp'
                                ? (step = 7)
                                : fetchRemoteFolders()
                        "
                    >
                        {{
                            healthResults &&
                            !healthResults.mx.status &&
                            form.account_type === "full"
                                ? "Fix DNS to Continue"
                                : "Next Step"
                        }}
                        <ArrowRight class="w-4 h-4 ml-2" />
                    </Button>
                </div>
                <div v-else-if="step === 6">
                    <Button
                        :disabled="isLoading"
                        @click="saveFolders"
                        class="px-8 rounded-xl font-black uppercase tracking-widest text-[11px] shadow-lg shadow-(--brand-primary)/10"
                    >
                        <Loader2
                            v-if="isLoading"
                            class="w-4 h-4 animate-spin mr-2"
                        />
                        Finish Setup
                    </Button>
                </div>
            </div>
        </template>
    </Modal>
</template>
<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
