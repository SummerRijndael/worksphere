<script setup lang="ts">
import { ref, computed, watch, onMounted } from "vue";
import {
    PenToolIcon,
    UsersIcon,
    HardDriveIcon,
    PlusIcon,
    Trash2Icon,
    
    FileTextIcon,
    ImageIcon,
    SearchIcon,
    SettingsIcon,
    CheckIcon,
    AlertCircleIcon,
    ChevronRightIcon,
    Loader2,
    ArrowLeftIcon,
} from "lucide-vue-next";
import { Button, Card, Input } from "@/components/ui";
import { useEmailSignatures } from "./composables/useEmailSignatures";
import { useEmailTemplates } from "./composables/useEmailTemplates";
// import { useEditor, EditorContent } from "@tiptap/vue-3";
import EmailAccountsSection from "@/components/settings/EmailAccountsSection.vue";
import EmailStorageStats from "@/components/settings/EmailStorageStats.vue";
import { emailAccountService } from "@/services/email-account.service";
import { RichTextEditor } from "@/components/ui";
import { useDebounceFn } from "@vueuse/core";
import { toast } from "vue-sonner";
import api from "@/lib/api";

// Auto-save debounced handlers
const debouncedSaveSignature = useDebounceFn(() => {
    handleSaveSignature();
}, 1000);

const debouncedSaveTemplate = useDebounceFn(() => {
    handleSaveTemplate();
}, 1000);

const tabs = [
    {
        id: "signatures",
        label: "Signatures",
        icon: PenToolIcon,
        description: "Manage email signatures",
    },
    {
        id: "templates",
        label: "Templates",
        icon: FileTextIcon,
        description: "Pre-defined email responses",
    },
    {
        id: "accounts",
        label: "Connected Accounts",
        icon: UsersIcon,
        description: "Manage email providers",
    },
    {
        id: "storage",
        label: "Storage Usage",
        icon: HardDriveIcon,
        description: "View storage quotas",
    },
];

const activeTab = ref("signatures");
const searchQuery = ref("");

// --- Signatures Logic ---
const {
    signatures,
    selectedSignatureId,
    fetchSignatures,
    addSignature,
    updateSignature,
    deleteSignature,
} = useEmailSignatures();

const activeSignature = computed(() =>
    signatures.value.find((s) => s.id === selectedSignatureId.value),
);

const filteredSignatures = computed(() => {
    if (!searchQuery.value) return signatures.value;
    return signatures.value.filter((s) =>
        (s.name || '').toLowerCase().includes((searchQuery.value || '').toLowerCase()),
    );
});

const signatureName = ref("");
// Editor ref
const signatureEditorRef = ref<any>(null);
const signatureContent = ref("");

// Save State
const saveStatus = ref<"saved" | "saving" | "error">("saved");

// Watch for selection change to update form
watch(selectedSignatureId, (newId) => {
    if (newId && activeSignature.value) {
        signatureName.value = activeSignature.value.name;
        signatureContent.value = activeSignature.value.content || "";
        saveStatus.value = "saved";
    } else {
        signatureName.value = "";
        signatureContent.value = "";
        saveStatus.value = "saved";
    }
});

async function handleNewSignature() {
    const newSig = await addSignature({ name: "New Signature", content: "" });
    selectedSignatureId.value = newSig.id;
    saveStatus.value = "saved";
}

async function handleSaveSignature() {
    if (!selectedSignatureId.value) return;
    saveStatus.value = "saving";
    try {
        await updateSignature(selectedSignatureId.value, {
            name: signatureName.value,
            content: activeSignature.value?.content || "",
        });
        saveStatus.value = "saved";
    } catch (e) {
        saveStatus.value = "error";
    }
}

function handleDeleteSignature(id: string) {
    if (confirm("Delete this signature?")) {
        deleteSignature(id);
        if (selectedSignatureId.value === id) {
            selectedSignatureId.value =
                signatures.value.length > 0 ? signatures.value[0].id : null;
        }
    }
}

// --- Templates Logic ---
const {
    templates,
    selectedTemplateId,
    fetchTemplates,
    addTemplate,
    updateTemplate,
    deleteTemplate,
} = useEmailTemplates();

const activeTemplate = computed(() =>
    templates.value.find((t) => t.id === selectedTemplateId.value),
);

const filteredTemplates = computed(() => {
    if (!searchQuery.value) return templates.value;
    return templates.value.filter(
        (t) =>
            t.name.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
            t.subject.toLowerCase().includes(searchQuery.value.toLowerCase()),
    );
});

const templateName = ref("");
const templateSubject = ref("");
// Editor ref
const templateEditorRef = ref<any>(null);
const templateContent = ref("");

watch(selectedTemplateId, (newId) => {
    if (newId && activeTemplate.value) {
        templateName.value = activeTemplate.value.name;
        templateSubject.value = activeTemplate.value.subject;
        templateContent.value = activeTemplate.value.body || "";
        saveStatus.value = "saved";
    } else {
        templateName.value = "";
        templateSubject.value = "";
        templateContent.value = "";
        saveStatus.value = "saved";
    }
});

async function handleNewTemplate() {
    const newTpl = await addTemplate({
        name: "New Template",
        subject: "",
        body: "",
    });
    selectedTemplateId.value = newTpl.id;
    saveStatus.value = "saved";
}

async function handleSaveTemplate() {
    if (!selectedTemplateId.value) return;
    saveStatus.value = "saving";
    try {
        await updateTemplate(selectedTemplateId.value, {
            name: templateName.value,
            subject: templateSubject.value,
            body: activeTemplate.value?.body || "",
        });
        saveStatus.value = "saved";
    } catch (e) {
        saveStatus.value = "error";
    }
}

function handleDeleteTemplate(id: string) {
    if (confirm("Delete this template?")) {
        deleteTemplate(id);
        if (selectedTemplateId.value === id) {
            selectedTemplateId.value =
                templates.value.length > 0 ? templates.value[0].id : null;
        }
    }
}

// --- Media Manager Logic ---
const showMediaBar = ref(false); // Collapsed by default in this layout
const mediaFiles = ref<any[]>([]);
const mediaLoading = ref(false);
const mediaUploadQueue = ref<any[]>([]);
const isUploading = ref(false);

const fetchMedia = async () => {
    const isSignature = activeTab.value === "signatures";
    const id = isSignature
        ? selectedSignatureId.value
        : selectedTemplateId.value;

    if (!id) {
        mediaFiles.value = [];
        return;
    }

    mediaLoading.value = true;
    try {
        const endpoint = isSignature
            ? `/api/emails/signatures/${id}/media`
            : `/api/emails/templates/${id}/media`;
        const response = await api.get(endpoint);
        mediaFiles.value = response.data.data;
    } catch (e) {
        console.error("Failed to fetch media", e);
    } finally {
        mediaLoading.value = false;
    }
};

// Refresh media when selection changes
watch(
    [activeTab, selectedSignatureId, selectedTemplateId],
    () => {
        if (
            activeTab.value === "signatures" ||
            activeTab.value === "templates"
        ) {
            fetchMedia();
        }
    },
    { immediate: true },
);

const handleMediaUpload = (files) => {
    files.forEach((file) => {
        mediaUploadQueue.value.push({
            id: Math.random().toString(36).substr(2, 9),
            file,
            progress: 0,
            status: "pending",
        });
    });
    processUploadQueue();
};

// const removeUpload = (index) => {
//     mediaUploadQueue.value.splice(index, 1);
// };

const processUploadQueue = async () => {
    if (isUploading.value) return;

    const isSignature = activeTab.value === "signatures";
    const id = isSignature
        ? selectedSignatureId.value
        : selectedTemplateId.value;

    if (!id) return;

    const pendingItems = mediaUploadQueue.value.filter(
        (i) => i.status === "pending",
    );
    if (pendingItems.length === 0) return;

    isUploading.value = true;
    let successCount = 0;

    for (const item of pendingItems) {
        item.status = "uploading";
        const formData = new FormData();
        formData.append("file", item.file);

        try {
            const endpoint = isSignature
                ? `/api/emails/signatures/${id}/media`
                : `/api/emails/templates/${id}/media`;

            await api.post(endpoint, formData, {
                headers: { "Content-Type": "multipart/form-data" },
                onUploadProgress: (progressEvent) => {
                    const percentCompleted = progressEvent.total ? Math.round(
                        (progressEvent.loaded * 100) / progressEvent.total,
                    ) : 0;
                    item.progress = percentCompleted;
                },
            });
            item.status = "completed";
            item.progress = 100;
            successCount++;
        } catch (error) {
            console.error(`Error uploading ${item.file.name}:`, error);
            item.status = "error";
        }
    }

    if (successCount > 0) {
        fetchMedia();
        // Clear completed
        mediaUploadQueue.value = mediaUploadQueue.value.filter(
            (item) => item.status !== "completed",
        );
    }

    isUploading.value = mediaUploadQueue.value.some(
        (i) => i.status === "uploading",
    );
};

const handleMediaDelete = async (id) => {
    if (!confirm("Delete this image?")) return;

    const isSignature = activeTab.value === "signatures";
    const parentId = isSignature
        ? selectedSignatureId.value
        : selectedTemplateId.value;

    try {
        const endpoint = isSignature
            ? `/api/emails/signatures/${parentId}/media/${id}`
            : `/api/emails/templates/${parentId}/media/${id}`;

        await api.delete(endpoint);
        fetchMedia();
    } catch (e) {
        console.error("Failed to delete media", e);
    }
};

const insertImage = (media) => {
    const isSignature = activeTab.value === "signatures";
    const editor = isSignature
        ? signatureEditorRef.value?.editor
        : templateEditorRef.value?.editor;

    if (editor && media.url) {
        editor.chain().focus().setImage({ src: media.url }).run();
    }
};

// Drag and drop setup for the drop zone
const isDragging = ref(false);
const handleDrop = (e) => {
    isDragging.value = false;
    const files = Array.from(e.dataTransfer.files);
    if (files.length > 0) {
        handleMediaUpload(files);
    }
};

onMounted(async () => {
    await Promise.all([fetchSignatures(), fetchTemplates()]);

    // Check URL for OAuth result
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has("email_connected")) {
        const status = urlParams.get("email_connected");
        if (status === "success") {
            toast.success("Email account connected successfully!");
        } else if (status === "updated") {
            toast.success("Email account tokens updated!");
        }
        // Auto-switch to accounts tab to show the result
        activeTab.value = "accounts";
        // Clean URL
        window.history.replaceState({}, "", window.location.pathname);
    } else if (urlParams.has("error")) {
        toast.error(urlParams.get("error"));
        window.history.replaceState({}, "", window.location.pathname);
    }

    // Select first item by default if available and nothing selected
    if (
        activeTab.value === "signatures" &&
        signatures.value.length > 0 &&
        !selectedSignatureId.value
    ) {
        selectedSignatureId.value = signatures.value[0].id;
    }
    if (
        activeTab.value === "templates" &&
        templates.value.length > 0 &&
        !selectedTemplateId.value
    ) {
        selectedTemplateId.value = templates.value[0].id;
    }
});

// --- Storage Logic ---
const loadingStorage = ref(false);
const storageAccounts = ref<any[]>([]);

async function fetchStorageData() {
    loadingStorage.value = true;
    try {
        const response = await emailAccountService.list();
        storageAccounts.value = response;
    } catch (e) {
        console.error("Failed to fetch storage data", e);
    } finally {
        loadingStorage.value = false;
    }
}

watch(activeTab, (newTab) => {
    if (newTab === "storage") fetchStorageData();
    searchQuery.value = ""; // Reset search on tab change
});

// Format helpers
function formatBytes(bytes: number | null) {
    if (bytes === null || bytes === undefined) return "0 B";
    if (bytes === 0) return "0 B";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
}

function getUsageDetails(account: any) {
    if (!account.storage_limit || account.storage_limit === 0) {
        return { percent: 0, color: "text-gray-500", bg: "bg-gray-200" };
    }
    const percent = Math.min(
        (account.storage_used / account.storage_limit) * 100,
        100,
    );
    let color = "text-[var(--brand-primary)]";
    let bg = "bg-[var(--brand-primary)]";

    if (percent >= 90) {
        color = "text-red-500";
        bg = "bg-red-500";
    } else if (percent >= 75) {
        color = "text-amber-500";
        bg = "bg-amber-500";
    }

    return { percent, color, bg };
}
</script>

<template>
    <div class="p-6 space-y-8 w-full mx-auto animate-in fade-in duration-500">
        <!-- Header -->
        <div class="flex items-center gap-5">
            <Button
                variant="secondary"
                size="icon"
                class="rounded-xl h-12 w-12 bg-(--surface-primary) border-(--border-default) shadow-sm hover:shadow-md transition-all shrink-0"
                @click="$router.push({ name: 'email' })"
            >
                <ArrowLeftIcon class="w-5 h-5 text-(--text-secondary)" />
            </Button>
            <div class="space-y-1">
                <h1
                    class="text-3xl font-bold tracking-tight text-(--text-primary)"
                >
                    Email Settings
                </h1>
                <p
                    class="text-(--text-secondary) text-sm font-medium opacity-80"
                >
                    Configuration and management for your email experience.
                </p>
            </div>
        </div>

        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Sidebar Navigation -->
            <div class="w-full lg:w-72 shrink-0">
                <nav
                    class="space-y-2 p-1 bg-(--surface-secondary)/20 rounded-2xl border border-(--border-subtle) backdrop-blur-sm"
                >
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        @click="activeTab = tab.id"
                        class="w-full flex items-center gap-3.5 px-4 py-3 text-sm font-semibold rounded-xl transition-all duration-200 group relative"
                        :class="[
                            activeTab === tab.id
                                ? 'bg-(--surface-primary) text-(--brand-primary) shadow-sm border-(--border-default) scale-[1.02]'
                                : 'text-(--text-secondary) hover:bg-(--surface-secondary)/50 hover:text-(--text-primary)',
                        ]"
                    >
                        <div
                            class="w-8 h-8 rounded-lg flex items-center justify-center transition-colors shrink-0"
                            :class="
                                activeTab === tab.id
                                    ? 'bg-(--brand-primary)/10'
                                    : 'bg-(--surface-tertiary)/50 group-hover:bg-(--surface-tertiary)'
                            "
                        >
                            <component :is="tab.icon" class="w-4 h-4" />
                        </div>
                        <div class="flex flex-col items-start min-w-0">
                            <span class="truncate">{{ tab.label }}</span>
                            <span
                                v-if="tab.description"
                                class="text-[10px] opacity-60 font-normal truncate max-w-full"
                                :class="
                                    activeTab === tab.id
                                        ? 'text-(--brand-primary)'
                                        : 'text-(--text-muted)'
                                "
                            >
                                {{ tab.description }}
                            </span>
                        </div>
                        <ChevronRightIcon
                            v-if="activeTab === tab.id"
                            class="w-4 h-4 ml-auto"
                        />
                    </button>
                </nav>
            </div>

            <!-- Content Area -->
            <div class="flex-1 min-w-0 space-y-6">
                <!-- Signatures / Templates -->
                <Card
                    v-if="
                        activeTab === 'signatures' || activeTab === 'templates'
                    "
                    class="overflow-hidden min-h-[500px] flex flex-col"
                >
                    <div
                        class="border-b border-(--border-default) p-5 flex items-center justify-between bg-(--surface-primary)"
                    >
                        <div class="flex items-center gap-3">
                            <h2 class="text-lg font-bold text-(--text-primary)">
                                {{
                                    activeTab === "signatures"
                                        ? "Signatures"
                                        : "Templates"
                                }}
                            </h2>
                            <!-- Status Indicator -->
                            <div
                                v-if="saveStatus === 'saved'"
                                class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-[10px] font-bold uppercase tracking-wider border border-emerald-500/20"
                            >
                                <CheckIcon class="w-3 h-3" />
                                Saved
                            </div>
                            <div
                                v-else-if="saveStatus === 'saving'"
                                class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-(--brand-primary)/10 text-(--brand-primary) text-[10px] font-bold uppercase tracking-wider border border-(--brand-primary)/20"
                            >
                                <Loader2 class="w-3 h-3 animate-spin" />
                                Saving
                            </div>
                            <div
                                v-else-if="saveStatus === 'error'"
                                class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-red-500/10 text-red-600 dark:text-red-400 text-[10px] font-bold uppercase tracking-wider border border-red-500/20"
                            >
                                <AlertCircleIcon class="w-3 h-3" />
                                Error
                            </div>
                        </div>
                        <Button
                            size="sm"
                            class="rounded-xl shadow-sm hover:shadow-md transition-all font-semibold px-4"
                            @click="
                                activeTab === 'signatures'
                                    ? handleNewSignature()
                                    : handleNewTemplate()
                            "
                        >
                            <PlusIcon class="w-4 h-4 mr-2" />
                            Create New
                        </Button>
                    </div>

                    <div class="flex flex-1 flex-col md:flex-row h-full">
                        <!-- List Column -->
                        <div
                            class="w-full md:w-72 border-b md:border-b-0 md:border-r border-(--border-default) bg-(--brand-primary)/5 backdrop-blur-sm flex flex-col shrink-0"
                        >
                            <div class="p-4 border-b border-(--border-default)">
                                <div class="relative">
                                    <SearchIcon
                                        class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-(--text-muted)"
                                    />
                                    <input
                                        v-model="searchQuery"
                                        type="text"
                                        placeholder="Search..."
                                        class="w-full pl-9 pr-3 py-2 text-sm bg-(--surface-primary) border border-(--border-default) rounded-xl focus:outline-none focus:ring-2 focus:ring-(--brand-primary)/20 focus:border-(--brand-primary) transition-all"
                                    />
                                </div>
                            </div>
                            <div
                                class="flex-1 overflow-y-auto p-3 space-y-1.5 max-h-[300px] md:max-h-[600px]"
                            >
                                <template v-if="activeTab === 'signatures'">
                                    <button
                                        v-for="sig in filteredSignatures"
                                        :key="sig.id"
                                        @click="selectedSignatureId = sig.id"
                                        class="w-full text-left px-3.5 py-3 rounded-xl text-sm transition-all flex items-center justify-between group relative overflow-hidden"
                                        :class="
                                            selectedSignatureId === sig.id
                                                ? 'bg-(--surface-primary) shadow-md text-(--brand-primary) font-semibold border border-(--brand-primary)/20 scale-[1.02]'
                                                : 'text-(--text-secondary) hover:bg-(--brand-primary)/10 hover:text-(--text-primary)'
                                        "
                                    >
                                        <div
                                            class="flex items-center gap-3 min-w-0"
                                        >
                                            <PenToolIcon
                                                class="w-4 h-4 shrink-0 opacity-50"
                                            />
                                            <span class="truncate">{{
                                                sig.name
                                            }}</span>
                                        </div>
                                        <button
                                            @click.stop="
                                                handleDeleteSignature(sig.id)
                                            "
                                            class="opacity-0 group-hover:opacity-100 p-1.5 text-(--text-muted) hover:text-red-500 hover:bg-red-500/10 rounded-lg transition-all"
                                        >
                                            <Trash2Icon class="w-4 h-4" />
                                        </button>
                                    </button>
                                </template>
                                <template v-else>
                                    <button
                                        v-for="tpl in filteredTemplates"
                                        :key="tpl.id"
                                        @click="selectedTemplateId = tpl.id"
                                        class="w-full text-left px-3.5 py-3 rounded-xl text-sm transition-all flex flex-col gap-1 group relative overflow-hidden"
                                        :class="
                                            selectedTemplateId === tpl.id
                                                ? 'bg-(--surface-primary) shadow-md text-(--brand-primary) font-semibold border border-(--brand-primary)/20 scale-[1.02]'
                                                : 'text-(--text-secondary) hover:bg-(--brand-primary)/10 hover:text-(--text-primary)'
                                        "
                                    >
                                        <div
                                            class="flex items-center justify-between w-full min-w-0 gap-2"
                                        >
                                            <div
                                                class="flex items-center gap-3 min-w-0"
                                            >
                                                <FileTextIcon
                                                    class="w-4 h-4 shrink-0 opacity-50"
                                                />
                                                <span
                                                    class="truncate font-semibold"
                                                    >{{ tpl.name }}</span
                                                >
                                            </div>
                                            <button
                                                @click.stop="
                                                    handleDeleteTemplate(tpl.id)
                                                "
                                                class="opacity-0 group-hover:opacity-100 p-1.5 text-(--text-muted) hover:text-red-500 hover:bg-red-500/10 rounded-lg transition-all shrink-0"
                                            >
                                                <Trash2Icon class="w-4 h-4" />
                                            </button>
                                        </div>
                                        <span
                                            class="text-xs font-normal truncate pl-7"
                                            :class="
                                                selectedTemplateId === tpl.id
                                                    ? 'text-(--brand-primary)/70'
                                                    : 'text-(--text-muted)'
                                            "
                                        >
                                            {{ tpl.subject || "(No Subject)" }}
                                        </span>
                                    </button>
                                </template>
                            </div>
                        </div>

                        <!-- Editor Column -->
                        <div
                            class="flex-1 flex flex-col min-w-0 bg-(--surface-primary) relative"
                        >
                            <!-- Top Accent Strip -->
                            <div
                                class="absolute top-0 left-0 right-0 h-1 bg-linear-to-r from-(--brand-primary)/50 via-(--brand-primary) to-(--brand-primary)/50 z-10"
                            ></div>
                            <template
                                v-if="
                                    (activeTab === 'signatures' &&
                                        selectedSignatureId) ||
                                    (activeTab === 'templates' &&
                                        selectedTemplateId)
                                "
                            >
                                <div
                                    class="p-6 border-b border-(--border-default) bg-(--surface-primary) space-y-4"
                                >
                                    <div
                                        class="grid grid-cols-1 md:grid-cols-2 gap-4"
                                    >
                                        <div class="space-y-1.5">
                                            <label
                                                class="block text-[10px] font-bold uppercase tracking-wider text-(--text-muted) ml-1"
                                                >Configuration Name</label
                                            >
                                            <Input
                                                v-if="
                                                    activeTab === 'signatures'
                                                "
                                                v-model="signatureName"
                                                @blur="handleSaveSignature"
                                                placeholder="e.g. Personal Signature"
                                                class="bg-(--surface-primary) border-(--border-default) focus:border-(--brand-primary) shadow-inner transition-all"
                                            />
                                            <Input
                                                v-else
                                                v-model="templateName"
                                                @blur="handleSaveTemplate"
                                                placeholder="e.g. Support Response"
                                                class="bg-(--surface-primary) border-(--border-default) focus:border-(--brand-primary) shadow-inner transition-all"
                                            />
                                        </div>
                                        <div
                                            v-if="activeTab === 'templates'"
                                            class="space-y-1.5"
                                        >
                                            <label
                                                class="block text-[10px] font-bold uppercase tracking-wider text-(--text-muted) ml-1"
                                                >Email Subject Line</label
                                            >
                                            <Input
                                                v-model="templateSubject"
                                                @blur="handleSaveTemplate"
                                                placeholder="Subject line for this template"
                                                class="bg-(--surface-secondary)/30 border-(--border-default) focus:bg-(--surface-primary) transition-all"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="flex-1 flex flex-col relative min-h-[450px]"
                                >
                                    <!-- Toolbar / Media Toggle -->

                                    <div class="flex-1 flex">
                                        <!-- Editor -->
                                        <div
                                            class="flex-1 flex flex-col min-h-0"
                                        >
                                            <RichTextEditor
                                                v-if="
                                                    activeTab === 'signatures' && activeSignature
                                                "
                                                ref="signatureEditorRef"
                                                v-model="
                                                    activeSignature.content
                                                "
                                                :content="signatureContent"
                                                @update:modelValue="
                                                    debouncedSaveSignature
                                                "
                                                placeholder="Write your signature..."
                                                class="flex-1"
                                            >
                                                <template #toolbar-after>
                                                    <button
                                                        type="button"
                                                        @click="
                                                            showMediaBar =
                                                                !showMediaBar
                                                        "
                                                        class="flex items-center gap-1.5 px-2 py-1.5 rounded-lg transition-all text-(--text-secondary) hover:bg-(--surface-secondary) active:scale-95"
                                                        :class="
                                                            showMediaBar &&
                                                            'bg-(--brand-primary)/10 text-(--brand-primary)'
                                                        "
                                                    >
                                                        <ImageIcon
                                                            class="w-4 h-4"
                                                        />
                                                        <span
                                                            class="text-sm font-medium"
                                                            >{{
                                                                showMediaBar
                                                                    ? "Close Media"
                                                                    : "Insert Media"
                                                            }}</span
                                                        >
                                                    </button>
                                                </template>
                                            </RichTextEditor>
                                            <RichTextEditor
                                                v-else-if="activeTab === 'templates' && activeTemplate"
                                                ref="templateEditorRef"
                                                v-model="activeTemplate.body"
                                                :content="templateContent"
                                                @update:modelValue="
                                                    debouncedSaveTemplate
                                                "
                                                placeholder="Write your template..."
                                                class="flex-1"
                                            >
                                                <template #toolbar-after>
                                                    <button
                                                        type="button"
                                                        @click="
                                                            showMediaBar =
                                                                !showMediaBar
                                                        "
                                                        class="flex items-center gap-1.5 px-2 py-1.5 rounded-lg transition-all text-(--text-secondary) hover:bg-(--surface-secondary) active:scale-95"
                                                        :class="
                                                            showMediaBar &&
                                                            'bg-(--brand-primary)/10 text-(--brand-primary)'
                                                        "
                                                    >
                                                        <ImageIcon
                                                            class="w-4 h-4"
                                                        />
                                                        <span
                                                            class="text-sm font-medium"
                                                            >{{
                                                                showMediaBar
                                                                    ? "Close Media"
                                                                    : "Insert Media"
                                                            }}</span
                                                        >
                                                    </button>
                                                </template>
                                            </RichTextEditor>
                                        </div>

                                        <!-- Media Sidebar (Inline) -->
                                        <div
                                            v-if="showMediaBar"
                                            class="w-72 border-l border-(--border-default) bg-(--surface-secondary)/10 flex flex-col animate-in slide-in-from-right duration-300"
                                        >
                                            <div
                                                class="p-4 border-b border-(--border-default)"
                                            >
                                                <h3
                                                    class="text-[10px] font-bold text-(--text-muted) uppercase tracking-widest"
                                                >
                                                    Media Assets
                                                </h3>
                                            </div>

                                            <div
                                                class="flex-1 p-3 overflow-y-auto"
                                                @dragover.prevent="
                                                    isDragging = true
                                                "
                                                @dragleave.prevent="
                                                    isDragging = false
                                                "
                                                @drop.prevent="handleDrop"
                                            >
                                                <!-- Drop Zone Overlay -->
                                                <div
                                                    v-if="isDragging"
                                                    class="absolute inset-0 bg-(--brand-primary)/10 z-50 flex items-center justify-center border-2 border-dashed border-(--brand-primary) m-2 rounded-lg pointer-events-none"
                                                >
                                                    <span
                                                        class="text-(--brand-primary) font-bold"
                                                        >Drop files here</span
                                                    >
                                                </div>

                                                <!-- Upload Button -->
                                                <label
                                                    class="flex flex-col items-center justify-center w-full h-24 border-2 border-dashed border-(--border-default) rounded-2xl bg-(--surface-primary) hover:border-(--brand-primary) hover:bg-(--brand-primary)/5 cursor-pointer transition-all mb-5 group"
                                                >
                                                    <div
                                                        class="flex flex-col items-center justify-center pt-5 pb-6"
                                                    >
                                                        <PlusIcon
                                                            class="w-7 h-7 text-(--text-muted) group-hover:text-(--brand-primary) mb-1.5 transition-colors"
                                                        />
                                                        <p
                                                            class="text-[10px] font-bold uppercase tracking-wider text-(--text-muted) group-hover:text-(--brand-primary)"
                                                        >
                                                            Upload Asset
                                                        </p>
                                                    </div>
                                                    <input
                                                        type="file"
                                                        class="hidden"
                                                        multiple
                                                        accept="image/*"
                                                        @change="
                                                            (e: any) =>
                                                                handleMediaUpload(
                                                                    Array.from(
                                                                        e.target?.files || [],
                                                                    ),
                                                                )
                                                        "
                                                    />
                                                </label>

                                                <!-- Upload Queue -->
                                                <div
                                                    v-if="
                                                        mediaUploadQueue.length >
                                                        0
                                                    "
                                                    class="space-y-2 mb-5"
                                                >
                                                    <div
                                                        v-for="(
                                                            item, idx
                                                        ) in mediaUploadQueue"
                                                        :key="idx"
                                                        class="text-[10px] bg-(--surface-secondary)/50 p-2.5 rounded-xl border border-(--border-subtle) animate-in slide-in-from-top-2"
                                                    >
                                                        <div
                                                            class="flex justify-between mb-1.5"
                                                        >
                                                            <span
                                                                class="truncate max-w-[120px] font-medium"
                                                                >{{
                                                                    item.file
                                                                        .name
                                                                }}</span
                                                            >
                                                            <span
                                                                class="font-bold uppercase tracking-tighter"
                                                                :class="{
                                                                    'text-(--brand-primary)':
                                                                        item.status ===
                                                                        'uploading',
                                                                    'text-red-500':
                                                                        item.status ===
                                                                        'error',
                                                                }"
                                                            >
                                                                {{
                                                                    item.status
                                                                }}
                                                            </span>
                                                        </div>
                                                        <div
                                                            class="w-full bg-(--surface-tertiary) rounded-full h-1 overflow-hidden"
                                                        >
                                                            <div
                                                                class="bg-(--brand-primary) h-1 rounded-full transition-all duration-300"
                                                                :style="{
                                                                    width:
                                                                        item.progress +
                                                                        '%',
                                                                }"
                                                            ></div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Grid -->
                                                <div
                                                    v-if="mediaLoading"
                                                    class="flex justify-center py-4"
                                                >
                                                    <Loader2
                                                        class="w-5 h-5 animate-spin text-(--text-muted)"
                                                    />
                                                </div>

                                                <div
                                                    v-else
                                                    class="grid grid-cols-2 gap-2"
                                                >
                                                    <div
                                                        v-for="media in mediaFiles"
                                                        :key="media.id"
                                                        class="group relative aspect-square rounded-md border border-(--border-default) bg-(--surface-primary) overflow-hidden cursor-pointer hover:ring-2 hover:ring-(--brand-primary)"
                                                        draggable="true"
                                                        @dragstart="
                                                            (e) => {
                                                                if(e.dataTransfer) {
                                                                    e.dataTransfer.setData(
                                                                        'text/plain',
                                                                        media.url,
                                                                    );
                                                                    e.dataTransfer.setData(
                                                                        'text/html',
                                                                        `<img src='${media.url}' />`,
                                                                    );
                                                                }
                                                            }
                                                        "
                                                        @click="
                                                            insertImage(media)
                                                        "
                                                    >
                                                        <img
                                                            :src="
                                                                media.thumbnail_url ||
                                                                media.url
                                                            "
                                                            class="w-full h-full object-cover"
                                                            loading="lazy"
                                                        />
                                                        <div
                                                            class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-1"
                                                        >
                                                            <button
                                                                @click.stop="
                                                                    handleMediaDelete(
                                                                        media.id,
                                                                    )
                                                                "
                                                                class="p-1 rounded bg-red-500 text-white hover:bg-red-600"
                                                                title="Delete"
                                                            >
                                                                <Trash2Icon
                                                                    class="w-3 h-3"
                                                                />
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div
                                                    v-if="
                                                        !mediaLoading &&
                                                        mediaFiles.length === 0
                                                    "
                                                    class="text-center py-8 text-(--text-muted) text-xs"
                                                >
                                                    No media found.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <div
                                v-else
                                class="flex flex-col items-center justify-center p-12 text-center h-full min-h-[450px] animate-in fade-in zoom-in duration-500"
                            >
                                <div
                                    class="w-16 h-16 rounded-2xl bg-(--surface-secondary) flex items-center justify-center mb-4 shadow-sm"
                                >
                                    <SettingsIcon
                                        class="w-8 h-8 text-(--text-muted) opacity-50"
                                    />
                                </div>
                                <h3
                                    class="text-(--text-primary) text-xl font-bold tracking-tight"
                                >
                                    Select an item to edit
                                </h3>
                                <p
                                    class="text-(--text-secondary) text-sm mt-2 max-w-xs mx-auto leading-relaxed"
                                >
                                    Pick a signature or template from the
                                    sidebar to manage its content and assets.
                                </p>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Accounts Tab -->
                <div v-else-if="activeTab === 'accounts'">
                    <EmailAccountsSection />
                </div>

                <!-- Storage Tab -->
                <Card
                    v-else-if="activeTab === 'storage'"
                    class="overflow-hidden border-(--border-default) shadow-sm"
                >
                    <div class="p-8">
                        <div class="flex items-center gap-3 mb-8">
                            <div
                                class="w-10 h-10 rounded-xl bg-(--brand-primary)/10 flex items-center justify-center"
                            >
                                <HardDriveIcon
                                    class="w-5 h-5 text-(--brand-primary)"
                                />
                            </div>
                            <h2 class="text-xl font-bold text-(--text-primary)">
                                Storage Allocation
                            </h2>
                        </div>

                        <div
                            v-if="loadingStorage"
                            class="flex flex-col items-center justify-center py-24 gap-4"
                        >
                            <Loader2
                                class="w-10 h-10 animate-spin text-(--brand-primary)"
                            />
                            <p
                                class="text-sm font-medium text-(--text-secondary)"
                            >
                                Analyzing storage usage...
                            </p>
                        </div>
                        <div v-else class="space-y-6">
                            <div
                                v-if="storageAccounts.length === 0"
                                class="text-center py-20 bg-(--surface-secondary)/20 rounded-3xl border border-dashed border-(--border-default)"
                            >
                                <div
                                    class="w-20 h-20 mx-auto rounded-3xl bg-(--surface-secondary) flex items-center justify-center mb-6 shadow-sm"
                                >
                                    <HardDriveIcon
                                        class="w-10 h-10 text-(--text-muted) opacity-50"
                                    />
                                </div>
                                <h3
                                    class="text-xl font-bold text-(--text-primary)"
                                >
                                    No Connected Accounts
                                </h3>
                                <p
                                    class="text-(--text-secondary) mt-2 max-w-sm mx-auto leading-relaxed"
                                >
                                    Connect an email account to view storage
                                    usage statistics and quotas.
                                </p>
                                <Button
                                    size="lg"
                                    class="mt-8 px-8"
                                    @click="activeTab = 'accounts'"
                                >
                                    Connect Your First Account
                                </Button>
                            </div>
                            <div
                                v-else
                                v-for="account in storageAccounts"
                                :key="account.id"
                                class="p-6 rounded-2xl border border-(--border-default) bg-(--surface-secondary)/10 hover:bg-(--surface-secondary)/20 transition-all group"
                            >
                                <div
                                    class="flex items-center justify-between mb-6"
                                >
                                    <div class="flex items-center gap-4">
                                        <div
                                            class="w-12 h-12 rounded-xl bg-(--surface-primary) flex items-center justify-center text-xl shadow-sm border border-(--border-subtle) group-hover:scale-110 transition-transform"
                                        >
                                            {{ account.email[0].toUpperCase() }}
                                        </div>
                                        <div>
                                            <div
                                                class="font-bold text-(--text-primary) text-lg"
                                            >
                                                {{ account.email }}
                                            </div>
                                            <div
                                                class="text-xs font-semibold text-(--text-muted) uppercase tracking-wider flex items-center gap-2"
                                            >
                                                <span
                                                    class="w-2 h-2 rounded-full bg-(--brand-primary)"
                                                ></span>
                                                {{ account.provider }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <EmailStorageStats :account-id="account.id" />
                            </div>
                        </div>
                    </div>
                </Card>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Force RichTextEditor to integrate seamlessly */
:deep(.prose) {
    max-width: none;
}
</style>
