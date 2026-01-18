<script setup>
import { ref, onMounted, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "@/lib/api"; // Keep for media operations
import { faqService } from "@/services";
import { Button, RichTextEditor, ConfirmPasswordModal, Modal } from "@/components/ui";
import FaqPreviewModal from "@/components/faq/FaqPreviewModal.vue";
import {
    ArrowLeft,
    Save,
    Eye,
    X,
    CheckCircle,
    XCircle,
    AlertCircle,
    LayoutList,
    Image as ImageIcon,
    EyeOff,
    Trash2,
    FileText,
    History,
    Copy,
} from "lucide-vue-next";
import MediaManager from "@/components/tools/MediaManager.vue";
import FaqVersionHistory from "@/components/faq/FaqVersionHistory.vue";
import { toast } from "vue-sonner";

const route = useRoute();
const router = useRouter();

const isLoading = ref(false);

const copyToClipboard = (text) => {
    navigator.clipboard.writeText(text);
    toast.success("ID copied to clipboard");
};
const isSaving = ref(false);
const showPreviewModal = ref(false);

// Storage Stats Logic
const storageLimit = 300 * 1024 * 1024; // 300MB

const formatSize = (bytes) => {
    if (bytes === 0 || !bytes) return "0 B";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
};

const totalStorageUsed = computed(() => {
    return mediaFiles.value.reduce((acc, i) => acc + (i.size || 0), 0) +
           attachmentFiles.value.reduce((acc, i) => acc + (i.size || 0), 0);
});

const usagePercentage = computed(() => {
    if (!storageLimit) return 0;
    return Math.min(Math.round((totalStorageUsed.value / storageLimit) * 100), 100);
});

const isStorageNearLimit = computed(() => usagePercentage.value >= 90);

const mainTab = ref("editor"); // 'editor' | 'history'

const categories = ref([]);
const articleForm = ref({
    id: null,
    public_id: null,
    category_id: "",
    title: "",
    content: "",
    tags: [],
    is_published: false,
});
const tagsInput = ref("");
const articleErrors = ref({});

// ID from route (if editing)
// ID from route (if editing)
const articleId = computed(() => route.params.id);

const isEditing = computed(() => !!articleId.value);
const editorRef = ref(null);
const originalArticle = ref(null);

// Confirmation Modal State
const showConfirmModal = ref(false);
const showPasswordModal = ref(false);
const confirmAction = ref(null);
const confirmPassword = ref("");
const confirmReason = ref("");
const confirmLoading = ref(false);
const confirmError = ref("");
const passwordError = ref("");

// Media Manager State
const showMediaModal = ref(false);
const mediaFiles = ref([]);
const mediaLoading = ref(false);
const mediaUploadQueue = ref([]);
const isUploading = ref(false);

const activeMediaTab = ref("media"); // 'media' or 'attachments'

const attachmentFiles = ref([]);
const attachmentLoading = ref(false);
const attachmentUploadQueue = ref([]);
const isAttachmentUploading = ref(false);

const fetchArticleMedia = async (collection = "images") => {
    if (!articleId.value) return;

    if (collection === "images") mediaLoading.value = true;
    else attachmentLoading.value = true;

    try {
        const response = await api.get(
            `/api/admin/faq/articles/${articleId.value}/media`,
            { params: { collection } }
        );
        if (collection === "images") {
            mediaFiles.value = response.data.data;
        } else {
            attachmentFiles.value = response.data.data;
        }
    } catch (error) {
        console.error(`Failed to fetch ${collection}`, error);
        toast.error(`Failed to load ${collection}`);
    } finally {
        if (collection === "images") mediaLoading.value = false;
        else attachmentLoading.value = false;
    }
};

const handleMediaUpload = (files, collection = "images") => {
    const queue =
        collection === "images" ? mediaUploadQueue : attachmentUploadQueue;

    files.forEach((file) => {
        queue.value.push({
            id: Math.random().toString(36).substr(2, 9),
            file,
            progress: 0,
            status: "pending",
        });
    });
};

const removeUpload = (index, collection = "images") => {
    const queue =
        collection === "images" ? mediaUploadQueue : attachmentUploadQueue;
    queue.value.splice(index, 1);
};

const processUploadQueue = async (collection = "images") => {
    const queue =
        collection === "images" ? mediaUploadQueue : attachmentUploadQueue;
    const isUploadingRef =
        collection === "images" ? isUploading : isAttachmentUploading;

    if (isUploadingRef.value || !articleId.value) return;

    if (!articleId.value) {
        toast.error("Please save the article first before uploading.");
        queue.value = [];
        return;
    }

    const pendingItems = queue.value.filter((i) => i.status === "pending");
    if (pendingItems.length === 0) return;

    isUploadingRef.value = true;
    let successCount = 0;

    for (const item of pendingItems) {
        item.status = "uploading";
        const formData = new FormData();
        formData.append("file", item.file);
        formData.append("collection", collection); // Pass collection to backend

        try {
            await api.post(
                `/api/admin/faq/articles/${articleId.value}/media`,
                formData,
                {
                    headers: { "Content-Type": "multipart/form-data" },
                    onUploadProgress: (progressEvent) => {
                        const percentCompleted = Math.round(
                            (progressEvent.loaded * 100) / progressEvent.total
                        );
                        item.progress = percentCompleted;
                    },
                }
            );
            item.status = "completed";
            item.progress = 100;
            successCount++;
        } catch (error) {
            console.error(`Error uploading ${item.file.name}:`, error);
            item.status = "error";
            const msg =
                error.response?.data?.message ||
                `Failed to upload ${item.file.name}`;
            toast.error(msg);
        }
    }

    if (successCount > 0) {
        toast.success(`Uploaded ${successCount} files to ${collection}`);
        fetchArticleMedia(collection);
        // Clear completed
        queue.value = queue.value.filter((item) => item.status !== "completed");
    }

    isUploadingRef.value = queue.value.some((i) => i.status === "uploading");
};

// ... existing imports

const showDeleteModal = ref(false);
const mediaToDeleteIds = ref([]);
const mediaToDeleteCollection = ref("images");

// Single delete just wraps in array
const handleMediaDelete = (payload, collection = "images") => {
    const id = payload?.id || payload;
    mediaToDeleteIds.value = [id];
    mediaToDeleteCollection.value = collection;
    showDeleteModal.value = true;
};

const handleMediaBulkDelete = (ids, collection = "images") => {
    mediaToDeleteIds.value = ids;
    mediaToDeleteCollection.value = collection;
    showDeleteModal.value = true;
};

const confirmDelete = async () => {
    if (mediaToDeleteIds.value.length === 0) return;

    let successCount = 0;
    let failCount = 0;

    // Use Promise.allSettled for parallel execution
    const promises = mediaToDeleteIds.value.map((id) =>
        api.delete(
            `/api/admin/faq/articles/${articleId.value}/media/${id}`
        )
    );

    const results = await Promise.allSettled(promises);

    results.forEach((result) => {
        if (result.status === "fulfilled") successCount++;
        else failCount++;
    });

    if (successCount > 0) {
        toast.success(
            `Deleted ${successCount} file${successCount !== 1 ? "s" : ""}`
        );
        // Refresh the collection
        fetchArticleMedia(mediaToDeleteCollection.value);
    }

    if (failCount > 0) {
        toast.error(`Failed to delete ${failCount} file${failCount !== 1 ? "s" : ""}`);
    }

    showDeleteModal.value = false;
    mediaToDeleteIds.value = [];
};

// ... existing code

// ... Template section needs to be added carefully.
// I will split this into two replacement chunks: imports/script and template.

const insertImage = (media) => {
    if (!editorRef.value) return;

    // Tiptap image insertion
    // We haven't exposed editor instance yet in RichTextEditor, need to do that or pass via props
    // Actually RichTextEditor likely doesn't expose 'editor' instance to parent by default unless we defineExpose it.
    // For now, let's assume valid URL
    const editor = editorRef.value.editor; // Need to ensure RichTextEditor exposes this
    if (editor) {
        editor.chain().focus().setImage({ src: media.url }).run();
        toast.success("Image inserted");
    } else {
        console.error("Editor instance not found");
    }
};

const fetchCategories = async () => {
    try {
        const response = await faqService.fetchCategories({ per_page: 100 });
        categories.value = response.data;

        // precise default if new
        if (
            !isEditing.value &&
            categories.value.length > 0 &&
            !articleForm.value.category_id
        ) {
            articleForm.value.category_id = categories.value[0].id;
        }
    } catch (error) {
        console.error("Failed to fetch categories", error);
    }
};

const fetchArticle = async () => {
    if (!articleId.value) return;

    isLoading.value = true;
    try {
        const article = await faqService.fetchArticle(articleId.value);

        articleForm.value = {
            id: article.id,
            public_id: article.public_id,
            category_id: article.category?.id || article.category_id || "",
            title: article.title,
            content: article.content,
            tags: article.tags || [],
            is_published: article.is_published,
        };
        originalArticle.value = { ...articleForm.value };
    } catch (error) {
        console.error("Failed to fetch article", error);
        // Redirect if not found?
        // router.push({ name: 'admin.faq' });
    } finally {
        isLoading.value = false;
    }
};

const addTag = () => {
    const rawInput = tagsInput.value;
    if (!rawInput) return;

    // Split by comma and trim
    const newTags = rawInput
        .split(",")
        .map((t) => t.trim())
        .filter((t) => t.length > 0);

    newTags.forEach((tag) => {
        if (!articleForm.value.tags.includes(tag)) {
            articleForm.value.tags.push(tag);
        }
    });

    tagsInput.value = "";
};

const handleTagPaste = (event) => {
    event.preventDefault();
    const pastedText = event.clipboardData.getData("text");
    const newTags = pastedText
        .split(",")
        .map((t) => t.trim())
        .filter((t) => t.length > 0);

    newTags.forEach((tag) => {
        if (!articleForm.value.tags.includes(tag)) {
            articleForm.value.tags.push(tag);
        }
    });
};

const removeTag = (tag) => {
    articleForm.value.tags = articleForm.value.tags.filter((t) => t !== tag);
};

// Handle password confirmation
const handlePasswordConfirm = async (password, reason) => {
    confirmLoading.value = true;
    passwordError.value = "";
    
    // Update reason if provided from modal
    if (reason) {
        confirmReason.value = reason;
    }

    try {
        await api.post("/api/user/confirm-password", { password });
        await performAction();
        showPasswordModal.value = false;
    } catch (error) {
        passwordError.value = error.response?.data?.errors?.password?.[0] || "Password verification failed";
    } finally {
        confirmLoading.value = false;
    }
};

const executeConfirmedAction = async () => {
    if (!confirmReason.value.trim()) {
        confirmError.value = "Please provide a reason";
        return;
    }
    showConfirmModal.value = false;
    showPasswordModal.value = true;
};

const performAction = async () => {
    if (confirmAction.value?.type === 'save_unpublish') {
         isSaving.value = true;
         try {
            const payload = { ...confirmAction.value.data, reason: confirmReason.value };
            await faqService.updateArticle(articleId.value, payload);
            toast.success("Article unpublished and saved.");
            originalArticle.value = { ...payload };
            confirmAction.value = null;
         } catch (error) {
            console.error(error);
            toast.error("Failed to save article.");
         } finally {
            isSaving.value = false;
         }
    }
};

const saveArticle = async () => {
    isSaving.value = true;
    articleErrors.value = {};

    // Ensure any pending tag input is added before saving
    if (tagsInput.value.trim()) {
        addTag();
    }

    // Intercept Unpublish
    if (isEditing.value && originalArticle.value?.is_published && !articleForm.value.is_published) {
        confirmAction.value = {
            type: 'save_unpublish',
            data: { ...articleForm.value }
        };
        confirmReason.value = "";
        passwordError.value = "";
        showPasswordModal.value = true;
        isSaving.value = false;
        return;
    }

    console.log("Saving article payload:", articleForm.value);

    try {
        if (isEditing.value) {
            await faqService.updateArticle(articleId.value, articleForm.value);
            // Stay on page and show success toast
            toast.success("Article saved successfully!");
            originalArticle.value = { ...articleForm.value, tags: [...articleForm.value.tags] };
        } else {
            const article = await faqService.createArticle(articleForm.value);
            // For new articles, redirect to the edit page of the newly created article
            toast.success("Article created successfully!");
            if (article?.id || article?.public_id) {
                router.push({ name: "admin.faq.edit", params: { id: article.public_id || article.id } });
            } else {
                router.push({ name: "admin.faq" });
            }
        }
    } catch (error) {
        if (error.response?.data?.errors) {
            articleErrors.value = error.response.data.errors;
            toast.error("Please fix the errors and try again.");
        } else {
            console.error(error);
            toast.error("Failed to save article.");
        }
    } finally {
        isSaving.value = false;
    }
};

onMounted(async () => {
    await fetchCategories();
    await fetchArticle();
    if (articleId.value) {
        await fetchArticleMedia("images");
        await fetchArticleMedia("attachments");
    }
});

// Mock user for preview
const currentUser = { name: "You (Preview)" };
</script>

<template>
    <div class="h-full bg-[var(--surface-primary)] flex flex-col">
        <!-- Top Bar -->
        <!-- Top Bar & Tabs Combined -->
        <!-- Top Bar with Segmented Control -->
        <header
            class="bg-[var(--surface-elevated)] border-b border-[var(--border-default)] sticky top-0 z-20"
        >
            <div
                class="w-full px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4 relative"
            >
                <!-- Left: Title & Navigation -->
                <div class="flex items-center gap-4">
                    <button
                        @click="router.push({ name: 'admin.faq' })"
                        class="p-2 -ml-2 hover:bg-[var(--surface-secondary)] rounded-full text-[var(--text-secondary)] transition-colors"
                    >
                        <ArrowLeft class="w-5 h-5" />
                    </button>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1
                                class="text-lg font-bold text-[var(--text-primary)] leading-tight"
                            >
                                {{ isEditing ? "Edit Article" : "New Article" }}
                            </h1>
                        </div>
                    </div>
                </div>

                <!-- Center: Segmented Tabs (Desktop) -->
                <div v-if="isEditing" class="hidden md:flex absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2">
                    <div class="bg-[var(--surface-secondary)] p-1 rounded-lg flex items-center gap-1 border border-[var(--border-subtle)]">
                        <button
                            @click="mainTab = 'editor'"
                            class="px-3 py-1.5 text-sm font-medium rounded-md transition-all flex items-center gap-2"
                            :class="[
                                mainTab === 'editor'
                                    ? 'bg-[var(--surface-elevated)] shadow-sm text-[var(--text-primary)]'
                                    : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                            ]"
                        >
                            <FileText class="w-4 h-4" />
                            Content
                        </button>
                        <button
                            @click="mainTab = 'history'"
                            class="px-3 py-1.5 text-sm font-medium rounded-md transition-all flex items-center gap-2"
                            :class="[
                                mainTab === 'history'
                                    ? 'bg-[var(--surface-elevated)] shadow-sm text-[var(--text-primary)]'
                                    : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                            ]"
                        >
                            <History class="w-4 h-4" />
                            History
                        </button>
                    </div>
                </div>

                <!-- Right: Actions -->
                <div class="flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="showPreviewModal = true"
                        class="text-[var(--text-primary)] hidden sm:flex"
                    >
                        <Eye class="w-4 h-4 mr-1.5" />
                        Preview
                    </Button>
                    <Button
                        @click="saveArticle"
                        :disabled="isSaving"
                        class="min-w-[100px]"
                    >
                        <span v-if="isSaving" class="flex items-center">
                            <span
                                class="animate-spin mr-2 h-4 w-4 border-2 border-b-transparent rounded-full border-white"
                            ></span>
                            Saving...
                        </span>
                        <span v-else class="flex items-center">
                            <Save class="w-4 h-4 mr-1.5" />
                            Save
                        </span>
                    </Button>
                </div>
            </div>

            <!-- Mobile Tabs (Below Header) -->
            <div v-if="isEditing" class="md:hidden px-4 sm:px-6 lg:px-8 border-t border-[var(--border-default)]">
                <nav class="-mb-px flex gap-6">
                    <button
                        @click="mainTab = 'editor'"
                        class="flex items-center gap-2 pb-3 pt-3 px-1 border-b-2 font-medium text-sm transition-all"
                        :class="[
                            mainTab === 'editor'
                                ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]'
                                : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-subtle)]'
                        ]"
                    >
                        <FileText class="w-4 h-4" />
                        Content
                    </button>
                    <button
                        @click="mainTab = 'history'"
                        class="flex items-center gap-2 pb-3 pt-3 px-1 border-b-2 font-medium text-sm transition-all"
                        :class="[
                            mainTab === 'history'
                                ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]'
                                : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-subtle)]'
                        ]"
                    >
                        <History class="w-4 h-4" />
                        History
                    </button>
                </nav>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden relative bg-[var(--surface-subtle)]">
            <!-- History Tab -->
            <div v-if="mainTab === 'history'" class="flex-1 h-full overflow-hidden">
                <FaqVersionHistory 
                    :article-id="articleId" 
                    @restore="()=>{ fetchArticle(); mainTab = 'editor'; }" 
                />
            </div>

            <!-- Editor Tab -->
            <div v-show="mainTab === 'editor'" class="w-full h-full overflow-y-auto px-4 sm:px-6 lg:px-8 py-8">
                <div
                    v-if="isLoading"
                    class="flex items-center justify-center py-20"
                >
                    <div
                        class="animate-spin h-8 w-8 border-2 border-[var(--interactive-primary)] border-b-transparent rounded-full"
                    ></div>
                </div>

                <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Left Column: Content -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Title Info -->
                        <div
                            class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4 sm:p-6 shadow-sm"
                        >
                            <div class="space-y-4">
                                <div>
                                    <label
                                        class="block text-sm font-medium mb-1.5 text-[var(--text-primary)]"
                                        >Article Title</label
                                    >
                                    <input
                                        v-model="articleForm.title"
                                        type="text"
                                        class="input text-lg font-medium"
                                        placeholder="e.g. How to configure notifications"
                                        autofocus
                                    />
                                    <p
                                        v-if="articleErrors.title"
                                        class="text-xs text-red-500 mt-1 flex items-center gap-1"
                                    >
                                        <AlertCircle class="w-3 h-3" />
                                        {{ articleErrors.title[0] }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Editor -->
                        <div
                            class="min-h-[calc(100vh-200px)] bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] shadow-sm flex flex-col"
                        >
                            <RichTextEditor
                                ref="editorRef"
                                v-model="articleForm.content"
                                placeholder="Start writing your amazing article..."
                                class="flex-1 border-none rounded-none"
                                min-height="calc(100vh - 200px)"
                            />
                        </div>
                        <p
                            v-if="articleErrors.content"
                            class="text-xs text-red-500 mt-1 px-1 flex items-center gap-1"
                        >
                            <AlertCircle class="w-3 h-3" />
                            {{ articleErrors.content[0] }}
                        </p>
                    </div>

                    <!-- Right Column: Settings -->
                    <div class="space-y-6">
                        <!-- Publishing Status -->
                        <div
                            class="bg-[var(--surface-elevated)] p-5 rounded-xl border border-[var(--border-default)] shadow-sm space-y-5"
                        >
                            <h4
                                class="font-medium text-[var(--text-primary)] border-b border-[var(--border-default)] pb-3 flex items-center gap-2"
                            >
                                <CheckCircle
                                    class="w-4 h-4 text-[var(--text-muted)]"
                                />
                                Publishing
                            </h4>

                            <!-- Category -->
                            <div>
                                <label
                                    class="block text-sm font-medium mb-1.5 text-[var(--text-secondary)]"
                                    >Category</label
                                >
                                <select
                                    v-model="articleForm.category_id"
                                    class="input"
                                >
                                    <option value="" disabled>
                                        Select a category
                                    </option>
                                    <option
                                        v-for="cat in categories"
                                        :key="cat.id"
                                        :value="cat.id"
                                    >
                                        {{ cat.name }}
                                    </option>
                                </select>
                                <p
                                    v-if="articleErrors.category_id"
                                    class="text-xs text-red-500 mt-1 flex items-center gap-1"
                                >
                                    <AlertCircle class="w-3 h-3" />
                                    {{ articleErrors.category_id[0] }}
                                </p>
                            </div>

                            <!-- Published Toggle -->
                            <div
                                class="flex items-center justify-between p-3 bg-[var(--surface-secondary)] rounded-lg"
                            >
                                <div class="flex flex-col">
                                    <label
                                        class="text-sm font-medium text-[var(--text-primary)] cursor-pointer"
                                        for="publish-toggle"
                                    >
                                        {{
                                            articleForm.is_published
                                                ? "Published"
                                                : "Draft"
                                        }}
                                    </label>
                                    <span
                                        class="text-xs text-[var(--text-muted)]"
                                    >
                                        {{
                                            articleForm.is_published
                                                ? "Visible to everyone"
                                                : "Only admins can see"
                                        }}
                                    </span>
                                </div>
                                <input
                                    id="publish-toggle"
                                    v-model="articleForm.is_published"
                                    type="checkbox"
                                    class="toggle rounded-full border-[var(--border-strong)]"
                                />
                            </div>
                        </div>

                        <!-- Media Manager Sidebar -->
                        <div
                            class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] shadow-sm overflow-hidden flex flex-col h-[500px]"
                        >
                            <!-- Tabs -->
                            <div
                                class="flex border-b border-[var(--border-default)]"
                            >
                                <button
                                    @click="activeMediaTab = 'media'"
                                    class="flex-1 py-3 text-sm font-medium text-center transition-colors border-b-2"
                                    :class="
                                        activeMediaTab === 'media'
                                            ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]'
                                            : 'border-transparent text-[var(--text-muted)] hover:text-[var(--text-primary)]'
                                    "
                                >
                                    Images
                                </button>
                                <button
                                    @click="activeMediaTab = 'attachments'"
                                    class="flex-1 py-3 text-sm font-medium text-center transition-colors border-b-2"
                                    :class="
                                        activeMediaTab === 'attachments'
                                            ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]'
                                            : 'border-transparent text-[var(--text-muted)] hover:text-[var(--text-primary)]'
                                    "
                                >
                                    Downloads
                                </button>
                            </div>

                            <div
                                v-if="articleId || isEditing"
                                class="flex flex-col flex-1 overflow-hidden relative"
                            >
                                <!-- Images Manager -->
                                <div
                                    v-show="activeMediaTab === 'media'"
                                    class="flex-1 min-h-0"
                                >
                                    <MediaManager
                                        id="images-manager"
                                        :items="mediaFiles"
                                        :embedded="true"
                                        :show-stats="false"
                                        :can-upload="true"
                                        :can-delete="true"
                                        :uploading="isUploading"
                                        :upload-queue="mediaUploadQueue"
                                        :storage-used="
                                            mediaFiles.reduce(
                                                (acc, i) => acc + (i.size || 0),
                                                0
                                            ) +
                                            attachmentFiles.reduce(
                                                (acc, i) => acc + (i.size || 0),
                                                0
                                            )
                                        "
                                        :storage-limit="300 * 1024 * 1024"
                                        @upload="
                                            (files) =>
                                                handleMediaUpload(
                                                    files,
                                                    'images'
                                                )
                                        "
                                        @delete="
                                            (id) =>
                                                handleMediaDelete(id, 'images')
                                        "
                                        @bulk-delete="
                                            (ids) =>
                                                handleMediaBulkDelete(
                                                    ids,
                                                    'images'
                                                )
                                        "
                                        @process-queue="
                                            () => processUploadQueue('images')
                                        "
                                        @remove-upload="
                                            (idx) => removeUpload(idx, 'images')
                                        "
                                        @view="insertImage"
                                    />
                                </div>

                                <!-- Attachments Manager -->
                                <div
                                    v-show="activeMediaTab === 'attachments'"
                                    class="flex-1 min-h-0"
                                >
                                    <MediaManager
                                        id="attachments-manager"
                                        accepted-file-types=".pdf,.doc,.docx,.xls,.xlsx,.zip,.txt,.csv,.json"
                                        :items="attachmentFiles"
                                        :embedded="true"
                                        :show-stats="false"
                                        :can-upload="true"
                                        :can-delete="true"
                                        :uploading="isAttachmentUploading"
                                        :upload-queue="attachmentUploadQueue"
                                        :storage-used="
                                            mediaFiles.reduce(
                                                (acc, i) => acc + (i.size || 0),
                                                0
                                            ) +
                                            attachmentFiles.reduce(
                                                (acc, i) => acc + (i.size || 0),
                                                0
                                            )
                                        "
                                        :storage-limit="300 * 1024 * 1024"
                                        @upload="
                                            (files) =>
                                                handleMediaUpload(
                                                    files,
                                                    'attachments'
                                                )
                                        "
                                        @delete="
                                            (id) =>
                                                handleMediaDelete(
                                                    id,
                                                    'attachments'
                                                )
                                        "
                                        @bulk-delete="
                                            (ids) =>
                                                handleMediaBulkDelete(
                                                    ids,
                                                    'attachments'
                                                )
                                        "
                                        @process-queue="
                                            () =>
                                                processUploadQueue(
                                                    'attachments'
                                                )
                                        "
                                        @remove-upload="
                                            (idx) =>
                                                removeUpload(idx, 'attachments')
                                        "
                                    />
                                </div>
                                
                                <div class="p-3 border-t border-[var(--border-default)] bg-[var(--surface-subtle)] shrink-0">
                                   <!-- Stats Bar -->
                                   <div class="flex items-center justify-between text-xs mb-1">
                                        <span class="text-[var(--text-secondary)] font-medium">Storage Usage</span>
                                        <span :class="{ 'text-red-500': isStorageNearLimit }">{{ usagePercentage }}%</span>
                                   </div>
                                   <div class="h-1.5 w-full bg-[var(--surface-tertiary)] rounded-full overflow-hidden">
                                        <div class="h-full transition-all duration-500 ease-out rounded-full"
                                             :class="isStorageNearLimit ? 'bg-red-500' : 'bg-[var(--interactive-primary)]'"
                                             :style="{ width: `${usagePercentage}%` }">
                                        </div>
                                   </div>
                                   <div class="flex justify-between mt-1 text-[10px] text-[var(--text-muted)]">
                                        <span>{{ formatSize(totalStorageUsed) }} used</span>
                                        <span>{{ formatSize(storageLimit) }} limit</span>
                                   </div>
                                </div>
                            </div>
                            <div
                                v-else
                                class="flex flex-col items-center justify-center h-full p-4 text-center text-[var(--text-muted)]"
                            >
                                <ImageIcon class="w-8 h-8 mb-2 opacity-50" />
                                <p class="text-sm">
                                    Save article to manage media
                                </p>
                            </div>
                        </div>

                        <!-- Organization / Tags -->
                        <div
                            class="bg-[var(--surface-elevated)] p-5 rounded-xl border border-[var(--border-default)] shadow-sm space-y-4"
                        >
                            <h4
                                class="font-medium text-[var(--text-primary)] border-b border-[var(--border-default)] pb-3 flex items-center gap-2"
                            >
                                <LayoutList
                                    class="w-4 h-4 text-[var(--text-muted)]"
                                />
                                Metadata
                            </h4>

                            <div v-if="isEditing && articleId">
                                <label
                                    class="block text-sm font-medium mb-1.5 text-[var(--text-secondary)]"
                                    >Public ID</label
                                >
                                <div class="flex items-center gap-2">
                                    <code
                                        class="flex-1 bg-[var(--surface-secondary)] px-3 py-2 rounded-lg border border-[var(--border-default)] text-sm font-mono text-[var(--text-primary)] truncate"
                                    >
                                        {{ articleId }}
                                    </code>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="copyToClipboard(articleId)"
                                        title="Copy ID"
                                    >
                                        <Copy class="w-4 h-4" />
                                    </Button>
                                </div>
                            </div>

                            <div>
                                <label
                                    class="block text-sm font-medium mb-1.5 text-[var(--text-secondary)]"
                                    >Tags</label
                                >
                                <div
                                    class="flex flex-wrap gap-2 mb-2 p-2 bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-default)] min-h-[42px] focus-within:ring-1 focus-within:ring-[var(--interactive-primary)] transition-shadow"
                                >
                                    <span
                                        v-for="tag in articleForm.tags"
                                        :key="tag"
                                        class="inline-flex items-center px-2 py-1 rounded bg-[var(--surface-elevated)] border border-[var(--border-subtle)] text-xs font-medium text-[var(--interactive-primary)] animate-scale-fade-in shadow-sm group"
                                    >
                                        {{ tag }}
                                        <button
                                            @click="removeTag(tag)"
                                            class="ml-1 text-[var(--text-muted)] hover:text-red-500 transition-colors"
                                        >
                                            <X class="w-3 h-3" />
                                        </button>
                                    </span>
                                    <input
                                        v-model="tagsInput"
                                        @keydown.enter.prevent="addTag"
                                        @keydown.tab.prevent="addTag"
                                        @keydown.,.prevent="addTag"
                                        @paste="handleTagPaste"
                                        type="text"
                                        class="bg-transparent border-none outline-none text-sm min-w-[80px] flex-1 text-[var(--text-primary)] placeholder-[var(--text-muted)]"
                                        placeholder="Add tag..."
                                    />
                                </div>
                                <p class="text-xs text-[var(--text-muted)]">
                                    Press Enter or comma to add tags
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Preview Modal -->
        <FaqPreviewModal
            :show="showPreviewModal"
            :article="articleForm"
            :category="categories.find((c) => c.id === articleForm.category_id)"
            @close="showPreviewModal = false"
        />

        <!-- Media Delete Confirmation Modal -->
        <Modal
            v-model:open="showDeleteModal"
            :title="`Delete ${
                mediaToDeleteIds.length > 1 ? mediaToDeleteIds.length : ''
            } File${mediaToDeleteIds.length !== 1 ? 's' : ''}`"
            :description="`Are you sure you want to delete ${
                mediaToDeleteIds.length > 1 ? 'these files' : 'this file'
            }? This action cannot be undone.`"
            size="sm"
        >
            <div class="flex justify-end gap-3 mt-4">
                <Button variant="ghost" @click="showDeleteModal = false"
                    >Cancel</Button
                >
                <Button variant="destructive" @click="confirmDelete"
                    >Delete</Button
                >
            </div>
        </Modal>


        <!-- Password Confirmation Modal -->
        <ConfirmPasswordModal
            :open="showPasswordModal"
            @update:open="
                showPasswordModal = $event;
                passwordError = '';
            "
            title="Confirm Unpublish"
            description="Enter your password to unpublish this article."
            :loading="confirmLoading"
            submit-text="Unpublish"
            submit-variant="secondary"
            :external-error="passwordError"
            :show-reason="confirmAction?.type === 'save_unpublish'"
            @confirm="handlePasswordConfirm"
            @cancel="
                showPasswordModal = false;
                passwordError = '';
            "
        >
        </ConfirmPasswordModal>
    </div>
</template>

<style scoped>
/* Custom toggle style override if needed, assuming global 'toggle' class exists or basic checkbox */
</style>
