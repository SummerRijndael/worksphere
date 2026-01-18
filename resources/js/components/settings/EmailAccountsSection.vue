<script setup>
import { ref, onMounted, computed } from "vue";
import { Button, Input, Modal, Switch } from "@/components/ui";
import {
    Mail,
    Plus,
    Trash2,
    Edit2,
    CheckCircle,
    XCircle,
    RefreshCw,
    ExternalLink,
    AlertCircle,
} from "lucide-vue-next";
import { toast } from "vue-sonner";
import axios from "axios";

const props = defineProps({
    teamId: {
        type: Number,
        default: null,
    },
    mode: {
        type: String,
        default: "personal", // 'personal' or 'system'
    },
});

const accounts = ref([]);
const providers = ref([]);
const isLoading = ref(true);
const showModal = ref(false);
const editingAccount = ref(null);
const isSaving = ref(false);
const isTesting = ref({});

const isSystem = computed(() => props.mode === "system");

const form = ref({
    name: "",
    email: "",
    provider: "custom",
    auth_type: "password",
    imap_host: "",
    imap_port: 993,
    imap_encryption: "ssl",
    smtp_host: "",
    smtp_port: 587,
    smtp_encryption: "tls",
    username: "",
    password: "",
});

const errors = ref({});

const encryptionOptions = [
    { value: "ssl", label: "SSL" },
    { value: "tls", label: "TLS" },
    { value: "none", label: "None" },
];

const selectedProvider = computed(() => {
    return providers.value.find((p) => p.id === form.value.provider) || {};
});

const isCustomProvider = computed(() => form.value.provider === "custom");

const fetchAccounts = async () => {
    try {
        const response = await axios.get("/api/email-accounts", {
            params: { mode: props.mode },
        });
        accounts.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch email accounts:", error);
    }
};

const fetchProviders = async () => {
    try {
        const response = await axios.get("/api/email-accounts/providers");
        providers.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch providers:", error);
    }
};

const openModal = (account = null) => {
    editingAccount.value = account;
    if (account) {
        form.value = {
            name: account.name,
            email: account.email,
            provider: account.provider,
            auth_type: account.auth_type,
            imap_host: account.imap_host || "",
            imap_port: account.imap_port,
            imap_encryption: account.imap_encryption,
            smtp_host: account.smtp_host || "",
            smtp_port: account.smtp_port,
            smtp_encryption: account.smtp_encryption,
            username: account.username || "",
            password: "",
        };
    } else {
        form.value = {
            name: "",
            email: "",
            provider: "custom",
            auth_type: "password",
            imap_host: "",
            imap_port: 993,
            imap_encryption: "ssl",
            smtp_host: "",
            smtp_port: 587,
            smtp_encryption: "tls",
            username: "",
            password: "",
        };
    }
    errors.value = {};
    showModal.value = true;
};

const onProviderChange = () => {
    const provider = selectedProvider.value;
    if (provider.imap_host) {
        form.value.imap_host = provider.imap_host;
        form.value.smtp_host = provider.smtp_host;
    }
};

const saveAccount = async () => {
    errors.value = {};
    isSaving.value = true;

    try {
        const payload = { ...form.value };
        if (props.teamId) {
            payload.team_id = props.teamId;
        }
        if (isSystem.value) {
            payload.is_system = true;
        }

        if (editingAccount.value) {
            await axios.put(
                `/api/email-accounts/${editingAccount.value.id}`,
                payload
            );
            toast.success("Email account updated");
        } else {
            await axios.post("/api/email-accounts", payload);
            toast.success("Email account created");
        }

        showModal.value = false;
        await fetchAccounts();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
        } else {
            toast.error("Failed to save email account");
        }
    } finally {
        isSaving.value = false;
    }
};

const deleteAccount = async (id) => {
    if (!confirm("Are you sure you want to delete this email account?")) return;

    try {
        await axios.delete(`/api/email-accounts/${id}`);
        toast.success("Email account deleted");
        await fetchAccounts();
    } catch (error) {
        toast.error("Failed to delete email account");
    }
};

const testConnection = async (account) => {
    isTesting.value[account.id] = true;
    try {
        const response = await axios.post(
            `/api/email-accounts/${account.id}/test`
        );
        if (response.data.success) {
            toast.success("Connection successful!");
            account.is_verified = true;
            account.last_error = null;
        } else {
            toast.error(response.data.message || "Connection failed");
            account.is_verified = false;
            account.last_error = response.data.message;
        }
    } catch (error) {
        toast.error("Connection test failed");
    } finally {
        isTesting.value[account.id] = false;
    }
};

const isTestingConfig = ref(false); // Add this ref for config testing state

const testConfiguration = async () => {
    isTestingConfig.value = true;
    try {
        const response = await axios.post(
            "/api/email-accounts/test-configuration",
            form.value
        );
        if (response.data.success) {
            toast.success("Configuration test successful!");
        } else {
            toast.error(response.data.message || "Configuration test failed");
        }
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
            toast.error("Please check your input fields");
        } else {
            toast.error("Connection test failed");
        }
    } finally {
        isTestingConfig.value = false;
    }
};

const connectOAuth = async (provider) => {
    try {
        const params = props.teamId ? `?team_id=${props.teamId}` : "";
        const response = await axios.get(
            `/api/email-accounts/oauth/${provider}/redirect${params}`
        );
        if (response.data.redirect_url) {
            window.location.href = response.data.redirect_url;
        }
    } catch (error) {
        toast.error("Failed to start OAuth flow");
    }
};

// Check for OAuth callback result
onMounted(async () => {
    // Check URL for OAuth result
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has("email_connected")) {
        const status = urlParams.get("email_connected");
        if (status === "success") {
            toast.success("Email account connected successfully!");
        } else if (status === "updated") {
            toast.success("Email account tokens updated!");
        }
        // Clean URL
        window.history.replaceState({}, "", window.location.pathname);
    } else if (urlParams.has("error")) {
        toast.error(urlParams.get("error"));
        window.history.replaceState({}, "", window.location.pathname);
    }

    try {
        await Promise.all([fetchAccounts(), fetchProviders()]);
    } finally {
        isLoading.value = false;
    }
});
</script>

<template>
    <div class="space-y-4">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div
                    class="w-8 h-8 rounded-lg bg-indigo-500/10 flex items-center justify-center"
                >
                    <Mail class="w-4 h-4 text-indigo-600" />
                </div>
                <div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        {{ isSystem ? 'System Email Accounts' : 'Email Accounts' }}
                    </h3>
                    <p class="text-xs text-[var(--text-muted)]">
                        {{ isSystem ? 'Connect accounts for system notifications. These accounts are for system internal use.' : 'Connect email accounts for sending' }}
                    </p>
                </div>
            </div>
            <Button variant="primary" size="sm" @click="openModal()">
                <Plus class="w-4 h-4" />
                Add Account
            </Button>
        </div>

        <!-- Loading -->
        <div v-if="isLoading" class="flex items-center justify-center py-8">
            <RefreshCw class="w-6 h-6 animate-spin text-[var(--text-muted)]" />
        </div>

        <!-- Empty State -->
        <div
            v-else-if="accounts.length === 0"
            class="text-center py-8 border border-dashed border-[var(--border-default)] rounded-lg"
        >
            <Mail
                class="w-10 h-10 text-[var(--text-muted)] mx-auto mb-2 opacity-50"
            />
            <p class="text-[var(--text-muted)]">No email accounts connected</p>
            <p class="text-sm text-[var(--text-muted)] mb-4">
                Add an account to send emails from the application
            </p>
            <div class="flex items-center justify-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    @click="connectOAuth('gmail')"
                >
                    Connect Gmail
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    @click="connectOAuth('outlook')"
                >
                    Connect Outlook
                </Button>
                <Button variant="ghost" size="sm" @click="openModal()">
                    Custom IMAP/SMTP
                </Button>
            </div>
        </div>

        <!-- Accounts List -->
        <div v-else class="space-y-2">
            <div
                v-for="account in accounts"
                :key="account.id"
                class="flex items-center justify-between p-4 bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-default)]"
            >
                <div class="flex items-center gap-3">
                    <div
                        :class="[
                            'w-10 h-10 rounded-full flex items-center justify-center text-white text-sm font-medium',
                            account.provider === 'gmail'
                                ? 'bg-red-500'
                                : account.provider === 'outlook'
                                ? 'bg-blue-500'
                                : 'bg-gray-500',
                        ]"
                    >
                        {{ account.email.charAt(0).toUpperCase() }}
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <p class="font-medium text-[var(--text-primary)]">
                                {{ account.name }}
                            </p>
                            <span
                                v-if="account.is_default"
                                class="px-1.5 py-0.5 text-xs bg-blue-500/10 text-blue-600 rounded"
                                >Default</span
                            >
                            <span
                                v-if="account.is_shared"
                                class="px-1.5 py-0.5 text-xs bg-purple-500/10 text-purple-600 rounded"
                                >Shared</span
                            >
                        </div>
                        <p class="text-sm text-[var(--text-secondary)]">
                            {{ account.email }}
                        </p>
                        <div class="flex items-center gap-2 mt-1">
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 text-xs',
                                    account.is_verified
                                        ? 'text-green-600'
                                        : 'text-amber-600',
                                ]"
                            >
                                <CheckCircle
                                    v-if="account.is_verified"
                                    class="w-3 h-3"
                                />
                                <AlertCircle v-else class="w-3 h-3" />
                                {{
                                    account.is_verified
                                        ? "Verified"
                                        : "Not verified"
                                }}
                            </span>
                            <span
                                class="text-xs text-[var(--text-muted)] capitalize"
                                >{{ account.provider }} ·
                                {{ account.auth_type }}</span
                            >
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <Button 
                        v-if="account.needs_reauth" 
                        @click="connectOAuth(account.provider)" 
                        size="xs" 
                        class="bg-red-50 text-red-600 hover:bg-red-100 border-red-200 mr-2"
                        variant="outline"
                    >
                        <AlertCircle class="w-3 h-3 mr-1" />
                        Reconnect
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8"
                        @click="testConnection(account)"
                        :disabled="isTesting[account.id]"
                    >
                        <RefreshCw
                            :class="[
                                'w-4 h-4',
                                isTesting[account.id] && 'animate-spin',
                            ]"
                        />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8"
                        @click="openModal(account)"
                    >
                        <Edit2 class="w-4 h-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-[var(--color-error)]"
                        @click="deleteAccount(account.id)"
                    >
                        <Trash2 class="w-4 h-4" />
                    </Button>
                </div>
            </div>

            <!-- Add More -->
            <div class="flex items-center gap-2 pt-2">
                <Button
                    variant="outline"
                    size="sm"
                    @click="connectOAuth('gmail')"
                >
                    <ExternalLink class="w-3 h-3" />
                    Connect Gmail
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    @click="connectOAuth('outlook')"
                >
                    <ExternalLink class="w-3 h-3" />
                    Connect Outlook
                </Button>
            </div>
        </div>

        <!-- Add/Edit Modal -->
        <Modal
            :open="showModal"
            @update:open="showModal = $event"
            :title="
                editingAccount ? 'Edit Email Account' : 'Connect Email Account'
            "
            size="lg"
        >
            <div class="space-y-6">
                <!-- Provider Selection (only when adding new) -->
                <div v-if="!editingAccount" class="grid grid-cols-3 gap-3">
                    <button
                        v-for="provider in providers"
                        :key="provider.id"
                        @click="
                            form.provider = provider.id;
                            onProviderChange();
                        "
                        :class="[
                            'p-4 rounded-xl border text-center transition-all duration-200',
                            form.provider === provider.id
                                ? 'border-indigo-600 bg-indigo-50 ring-1 ring-indigo-600 dark:bg-indigo-500/10'
                                : 'border-[var(--border-default)] hover:border-[var(--border-hover)] hover:bg-[var(--surface-hover)]',
                        ]"
                    >
                        <div class="flex flex-col items-center gap-2">
                            <!-- Icons -->
                            <div
                                v-if="provider.id === 'gmail'"
                                class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center text-red-600"
                            >
                                <Mail class="w-5 h-5" />
                            </div>
                            <div
                                v-else-if="provider.id === 'outlook'"
                                class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600"
                            >
                                <Mail class="w-5 h-5" />
                            </div>
                            <div
                                v-else
                                class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center text-gray-600"
                            >
                                <Mail class="w-5 h-5" />
                            </div>

                            <span
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                {{ provider.name }}
                            </span>
                        </div>
                    </button>
                </div>

                <!-- OAuth Connect View -->
                <div
                    v-if="selectedProvider.supports_oauth"
                    class="py-8 flex flex-col items-center text-center space-y-4"
                >
                    <div
                        class="p-3 rounded-full bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20"
                    >
                        <ExternalLink class="w-6 h-6" />
                    </div>
                    <div>
                        <h3
                            class="text-lg font-medium text-[var(--text-primary)]"
                        >
                            Connect with {{ selectedProvider.name }}
                        </h3>
                        <p
                            class="text-sm text-[var(--text-secondary)] mt-1 max-w-xs mx-auto"
                        >
                            You will be redirected to
                            {{ selectedProvider.name }} to authorize access to
                            your email account.
                        </p>
                    </div>
                    <Button
                        size="lg"
                        variant="primary"
                        @click="connectOAuth(form.provider)"
                        class="w-full max-w-sm mt-2"
                    >
                        Continue to {{ selectedProvider.name }}
                    </Button>
                </div>

                <!-- Manual Form -->
                <div v-else class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-[var(--text-primary)]"
                                >Account Name *</label
                            >
                            <Input
                                v-model="form.name"
                                placeholder="My Work Email"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-[var(--text-primary)]"
                                >Email Address *</label
                            >
                            <Input
                                v-model="form.email"
                                type="email"
                                placeholder="name@company.com"
                            />
                        </div>

                        <!-- IMAP Settings -->
                        <div
                            class="col-span-2 border-t border-[var(--border-default)] pt-4 mt-2"
                        >
                            <div class="flex items-center gap-2 mb-3">
                                <div
                                    class="w-1 h-4 bg-indigo-500 rounded-full"
                                ></div>
                                <p
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                >
                                    IMAP Settings (Incoming)
                                </p>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-sm text-[var(--text-secondary)]"
                                >IMAP Host</label
                            >
                            <Input
                                v-model="form.imap_host"
                                placeholder="imap.example.com"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Port</label
                                >
                                <Input
                                    v-model.number="form.imap_port"
                                    type="number"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Encryption</label
                                >
                                <select
                                    v-model="form.imap_encryption"
                                    class="w-full px-3 py-2 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                                >
                                    <option
                                        v-for="e in encryptionOptions"
                                        :key="e.value"
                                        :value="e.value"
                                    >
                                        {{ e.label }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <!-- SMTP Settings -->
                        <div
                            class="col-span-2 border-t border-[var(--border-default)] pt-4 mt-2"
                        >
                            <div class="flex items-center gap-2 mb-3">
                                <div
                                    class="w-1 h-4 bg-purple-500 rounded-full"
                                ></div>
                                <p
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                >
                                    SMTP Settings (Outgoing)
                                </p>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-sm text-[var(--text-secondary)]"
                                >SMTP Host</label
                            >
                            <Input
                                v-model="form.smtp_host"
                                placeholder="smtp.example.com"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Port</label
                                >
                                <Input
                                    v-model.number="form.smtp_port"
                                    type="number"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Encryption</label
                                >
                                <select
                                    v-model="form.smtp_encryption"
                                    class="w-full px-3 py-2 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                                >
                                    <option
                                        v-for="e in encryptionOptions"
                                        :key="e.value"
                                        :value="e.value"
                                    >
                                        {{ e.label }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <!-- Credentials -->
                        <div
                            class="col-span-2 border-t border-[var(--border-default)] pt-4 mt-2"
                        >
                            <div class="flex items-center gap-2 mb-3">
                                <div
                                    class="w-1 h-4 bg-green-500 rounded-full"
                                ></div>
                                <p
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                >
                                    Authentication
                                </p>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-sm text-[var(--text-secondary)]"
                                >Username</label
                            >
                            <Input
                                v-model="form.username"
                                :placeholder="form.email || 'Username'"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-sm text-[var(--text-secondary)]"
                                >Password</label
                            >
                            <Input
                                v-model="form.password"
                                type="password"
                                placeholder="••••••••"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <div class="flex w-full justify-between">
                    <Button variant="ghost" @click="showModal = false"
                        >Cancel</Button
                    >
                    <div class="flex gap-2">
                        <Button
                            v-if="!selectedProvider.supports_oauth"
                            variant="outline"
                            @click="testConfiguration"
                            :loading="isTestingConfig"
                            :disabled="isSaving"
                        >
                            <RefreshCw
                                class="w-4 h-4 mr-2"
                                :class="{ 'animate-spin': isTestingConfig }"
                            />
                            Test Connection
                        </Button>
                        <Button
                            v-if="!selectedProvider.supports_oauth"
                            variant="primary"
                            @click="saveAccount"
                            :loading="isSaving"
                        >
                            {{
                                editingAccount
                                    ? "Update Account"
                                    : "Save Account"
                            }}
                        </Button>
                    </div>
                </div>
            </template>
        </Modal>
    </div>
</template>
