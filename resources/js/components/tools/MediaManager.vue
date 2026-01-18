<script setup>
import { ref, computed } from "vue";
import {
    LayoutGrid,
    List,
    Search,
    Filter,
    Download,
    Trash2,
    FileText,
    Image as ImageIcon,
    Video as VideoIcon,
    MoreVertical,
    UploadCloud,
    File,
    ChevronLeft,
    ChevronRight,
    X,
    ChevronDown,
    Loader2,
    CheckSquare,
    Square,
    Folder,
    Database,
} from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Avatar from "@/components/ui/Avatar.vue";
import { format } from "date-fns";
import {
    Dropdown,
    DropdownItem,
    DropdownSeparator,
    DropdownLabel,
} from "@/components/ui";
import { toast } from "vue-sonner";

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
    total: {
        type: Number,
        default: 0,
    },
    perPage: {
        type: Number,
        default: 10,
    },
    currentPage: {
        type: Number,
        default: 1,
    },
    search: {
        type: String,
        default: "",
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    uploading: {
        type: Boolean,
        default: false,
    },
    uploadQueue: {
        type: Array,
        default: () => [],
    },
    canDelete: {
        type: Boolean,
        default: false,
    },
    canUpload: {
        type: Boolean,
        default: false,
    },
    storageUsed: {
        type: Number,
        default: 0,
    },
    storageLimit: {
        type: Number,
        default: 0,
    },
    embedded: {
        type: Boolean,
        default: false,
    },
    id: {
        type: String,
        default: "media-manager",
    },
    acceptedFileTypes: {
        type: String,
        default: "image/*",
    },
    showStats: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits([
    "update:page",
    "update:perPage",
    "update:search",
    "update:filters",
    "upload",
    "delete",
    "download",
    "view",
    "remove-upload",
    "process-queue",
    "bulk-delete",
    "bulk-download",
]);

const viewMode = ref("grid"); // 'grid' | 'list'
const isDragging = ref(false);
const localSearch = ref(props.search);
const showQueue = ref(true);
const selectedItems = ref([]); // Array of IDs

// Debounced search could be added here, but for now simple v-model update
const updateSearch = () => {
    emit("update:search", localSearch.value);
};

const handleDrop = (event) => {
    isDragging.value = false;
    if (!props.canUpload) return;

    const droppedFiles = Array.from(event.dataTransfer.files);
    if (droppedFiles.length > 0) {
        emit("upload", droppedFiles);
    }
};

const handleFileSelect = (event) => {
    if (!props.canUpload) return;
    const selectedFiles = Array.from(event.target.files);

    // Check limit
    if (selectedFiles.length + props.uploadQueue.length > 10) {
        toast.error("You can only upload up to 10 files at a time.");
        event.target.value = ""; // Reset
        return;
    }

    if (selectedFiles.length > 0) {
        emit("upload", selectedFiles);
    }
    // Reset input
    event.target.value = "";
};

const handleDragStart = (event, item) => {
    // Set data for Tiptap or other drop targets
    // We send HTML to let Tiptap parse it effectively as an image
    const html = `<img src="${item.url}" alt="${item.name}" />`;
    event.dataTransfer.setData("text/html", html);
    event.dataTransfer.setData("text/plain", item.url);
    event.dataTransfer.effectAllowed = "copy";
};

const triggerUpload = () => {
    document.getElementById(`${props.id}-upload-input`)?.click();
};

const formatDate = (dateString) => {
    if (!dateString) return "-";
    return format(new Date(dateString), "MMM d, yyyy");
};

const formatSize = (bytes) => {
    if (bytes === 0 || !bytes) return "0 B";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
};

const getFileIcon = (mimeType) => {
    if (mimeType?.startsWith("image/")) return ImageIcon;
    if (mimeType?.startsWith("video/")) return VideoIcon;
    if (mimeType === "application/pdf") return FileText;
    return File;
};

// Bulk Selection
const toggleSelection = (id) => {
    if (selectedItems.value.includes(id)) {
        selectedItems.value = selectedItems.value.filter(
            (itemId) => itemId !== id
        );
    } else {
        if (selectedItems.value.length >= 10) {
            toast.error("You can select up to 10 files max.");
            return;
        }
        selectedItems.value.push(id);
    }
};

const usagePercentage = computed(() => {
    if (!props.storageLimit) return 0;
    return Math.min(
        Math.round((props.storageUsed / props.storageLimit) * 100),
        100
    );
});

const isStorageNearLimit = computed(() => usagePercentage.value >= 90);
const isStorageFull = computed(() => usagePercentage.value >= 100);

const isSelected = (id) => selectedItems.value.includes(id);

const clearSelection = () => {
    selectedItems.value = [];
};
</script>

<template>
    <div
        class="flex flex-col h-full bg-[var(--surface-primary)] overflow-hidden relative"
        :class="{
            'rounded-xl border border-[var(--border-default)]': !embedded,
        }"
    >
        <!-- Toolbar -->
        <div
            class="p-3 border-b border-[var(--border-default)] flex items-center justify-between shrink-0"
        >
            <h3 v-if="!embedded" class="font-medium text-[var(--text-primary)]">
                Media
            </h3>
            <div
                class="flex items-center gap-2 w-full"
                :class="{
                    'justify-between': embedded,
                    'justify-end': !embedded,
                }"
            >
                <span
                    v-if="embedded"
                    class="text-sm font-medium text-[var(--text-primary)]"
                    >Media</span
                >

                <div class="flex items-center gap-2">
                    <div
                        class="flex items-center gap-1 bg-[var(--surface-tertiary)] p-0.5 rounded-lg border border-[var(--border-subtle)]"
                    >
                        <button
                            @click="viewMode = 'grid'"
                            class="p-1.5 rounded-md transition-all"
                            :class="
                                viewMode === 'grid'
                                    ? 'bg-[var(--surface-elevated)] text-[var(--interactive-primary)] shadow-sm'
                                    : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                            "
                            title="Grid View"
                        >
                            <LayoutGrid class="w-4 h-4" />
                        </button>
                        <button
                            @click="viewMode = 'list'"
                            class="p-1.5 rounded-md transition-all"
                            :class="
                                viewMode === 'list'
                                    ? 'bg-[var(--surface-elevated)] text-[var(--interactive-primary)] shadow-sm'
                                    : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                            "
                            title="List View"
                        >
                            <List class="w-4 h-4" />
                        </button>
                    </div>

                    <button
                        @click="triggerUpload"
                        class="btn btn-sm btn-primary gap-2"
                    >
                        <UploadCloud class="w-4 h-4" />
                        <span v-if="!embedded">Upload</span>
                    </button>
                    <input
                        :id="`${id}-upload-input`"
                        type="file"
                        multiple
                        :accept="acceptedFileTypes"
                        class="hidden"
                        @change="handleFileSelect"
                    />
                </div>
            </div>
        </div>

        <div
            v-if="embedded"
            class="flex-1 overflow-y-auto p-3 min-h-[200px]"
            ref="dropZone"
        >
            <!-- Bulk Action Toolbar (Embedded) -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="transform -translate-y-2 opacity-0"
                enter-to-class="transform translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="transform translate-y-0 opacity-100"
                leave-to-class="transform -translate-y-2 opacity-0"
            >
                <div
                    v-if="selectedItems.length > 0"
                    class="bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg p-2 flex items-center justify-between transition-all shrink-0 mb-3"
                >
                    <div class="flex items-center gap-2 px-1">
                        <span
                            class="text-xs font-medium text-[var(--interactive-primary)] flex items-center gap-1.5"
                        >
                            <CheckSquare class="h-3.5 w-3.5" />
                            Selected ({{ selectedItems.length }})
                        </span>
                        <button
                            @click="clearSelection"
                            class="text-[10px] text-[var(--text-muted)] hover:text-[var(--text-primary)] underline"
                        >
                            Clear
                        </button>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <Button
                            @click="emit('bulk-download', selectedItems)"
                            size="xs"
                            variant="outline"
                            class="h-7 text-[10px]"
                        >
                            Zip
                        </Button>
                        <Button
                            @click="
                                emit('bulk-delete', selectedItems);
                                clearSelection();
                            "
                            size="xs"
                            variant="danger"
                            class="h-7 text-[10px]"
                        >
                            Delete
                        </Button>
                    </div>
                </div>
            </Transition>
            <!-- Upload Queue (Embedded - Moved to Top) -->
            <div
                v-if="uploadQueue.length > 0"
                class="mb-3 border-b border-[var(--border-default)] pb-3"
            >
                <div class="flex items-center justify-between mb-2">
                    <h4
                        class="text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                    >
                        Queue ({{ uploadQueue.length }})
                    </h4>
                    <Button
                        v-if="
                            !uploading &&
                            uploadQueue.some((i) => i.status === 'pending')
                        "
                        @click="emit('process-queue')"
                        size="xs"
                        class="h-6 text-[10px]"
                    >
                        Start Upload
                    </Button>
                </div>

                <div class="space-y-2">
                    <div
                        v-for="(file, index) in uploadQueue"
                        :key="file.id"
                        class="text-xs bg-[var(--surface-secondary)] p-2 rounded flex items-center justify-between group"
                    >
                        <div class="flex items-center gap-2 min-w-0">
                            <!-- Icon -->
                            <div
                                class="h-6 w-6 shrink-0 rounded bg-[var(--surface-tertiary)] flex items-center justify-center overflow-hidden border border-[var(--border-subtle)]"
                            >
                                <component
                                    :is="getFileIcon(file.file.type || '')"
                                    class="h-3 w-3 text-[var(--text-secondary)]"
                                />
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 mb-0.5">
                                    <span class="truncate max-w-[100px]">{{
                                        file.file.name
                                    }}</span>
                                    <span
                                        class="text-[10px] uppercase font-bold"
                                        :class="{
                                            'text-[var(--text-muted)]':
                                                file.status === 'pending',
                                            'text-[var(--interactive-primary)]':
                                                file.status === 'uploading',
                                            'text-green-500':
                                                file.status === 'completed',
                                            'text-red-500':
                                                file.status === 'error',
                                        }"
                                    >
                                        {{
                                            file.status === "uploading"
                                                ? Math.round(file.progress) +
                                                  "%"
                                                : file.status
                                        }}
                                    </span>
                                </div>
                                <div
                                    class="h-1 bg-[var(--surface-tertiary)] rounded-full overflow-hidden w-full"
                                >
                                    <div
                                        class="h-full transition-all duration-300 rounded-full"
                                        :style="{ width: file.progress + '%' }"
                                        :class="{
                                            'bg-red-500':
                                                file.status === 'error',
                                            'bg-green-500':
                                                file.status === 'completed',
                                            'bg-[var(--interactive-primary)]':
                                                file.status === 'uploading' ||
                                                file.status === 'pending',
                                        }"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        <button
                            v-if="
                                file.status === 'pending' ||
                                file.status === 'error'
                            "
                            @click="emit('remove-upload', index)"
                            class="text-[var(--text-muted)] hover:text-red-500 p-1"
                            title="Remove"
                        >
                            <X class="w-3 h-3" />
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-if="items.length === 0"
                class="h-full flex flex-col items-center justify-center text-[var(--text-muted)] text-center p-4"
            >
                <ImageIcon class="w-10 h-10 mb-3 opacity-50" />
                <p class="text-sm">No media yet.</p>
                <p class="text-xs mt-1">
                    Upload images to use them in your article.
                </p>
            </div>

            <div v-else>
                <!-- Grid View (Embedded) -->
                <div v-if="viewMode === 'grid'" class="grid grid-cols-2 gap-3">
                    <div
                        v-for="item in items"
                        :key="item.id"
                        class="group relative aspect-square rounded-lg border border-[var(--border-default)] overflow-hidden cursor-pointer bg-[var(--surface-secondary)] hover:border-[var(--interactive-primary)] transition-colors"
                        :draggable="item.mime_type?.startsWith('image/')"
                        @dragstart="handleDragStart($event, item)"
                        :class="
                            item.mime_type?.startsWith('image/')
                                ? 'cursor-move'
                                : ''
                        "
                        :title="item.name"
                    >
                        <!-- Selection Checkbox -->
                        <div
                            class="absolute top-2 left-2 z-10 transition-opacity"
                            :class="
                                isSelected(item.id)
                                    ? 'opacity-100'
                                    : 'opacity-0 group-hover:opacity-100'
                            "
                            @click.stop="toggleSelection(item.id)"
                        >
                            <div
                                class="bg-[var(--surface-elevated)] rounded shadow-sm"
                            >
                                <component
                                    :is="
                                        isSelected(item.id)
                                            ? CheckSquare
                                            : Square
                                    "
                                    class="h-5 w-5"
                                    :class="
                                        isSelected(item.id)
                                            ? 'text-[var(--interactive-primary)]'
                                            : 'text-[var(--text-muted)] hover:text-[var(--interactive-primary)]'
                                    "
                                />
                            </div>
                        </div>

                        <img
                            v-if="
                                (item.thumbnail_url || item.url) &&
                                !item.thumbnail_url?.includes('null') &&
                                !item.mime_type?.includes('pdf') &&
                                item.mime_type?.startsWith('image/')
                            "
                            :src="item.thumbnail_url || item.url"
                            :alt="item.name"
                            class="w-full h-full object-cover"
                            loading="lazy"
                        />
                        <div
                            v-else
                            class="w-full h-full flex flex-col items-center justify-center bg-[var(--surface-tertiary)] p-2 group-hover:bg-[var(--surface-secondary)] transition-colors"
                        >
                            <div
                                class="h-8 w-8 rounded-lg bg-[var(--surface-elevated)] shadow-sm flex items-center justify-center mb-1"
                            >
                                <component
                                    :is="getFileIcon(item.mime_type)"
                                    class="h-4 w-4 text-[var(--interactive-primary)]"
                                />
                            </div>
                            <span
                                class="text-[10px] font-medium text-[var(--text-secondary)] uppercase tracking-wider truncate max-w-full px-1"
                                >{{ item.extension || "FILE" }}</span
                            >
                        </div>

                        <!-- Overlay Actions -->
                        <div
                            class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2"
                        >
                            <button
                                @click.stop="emit('delete', item.id)"
                                class="p-1.5 rounded-md bg-red-500/80 text-white hover:bg-red-600 transition-colors"
                                title="Delete"
                            >
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- List View (Embedded) -->
                <div v-else class="space-y-2">
                    <div
                        v-for="item in items"
                        :key="item.id"
                        class="group flex items-center gap-3 p-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)] hover:border-[var(--interactive-primary)] transition-colors"
                        :draggable="item.mime_type?.startsWith('image/')"
                        @dragstart="handleDragStart($event, item)"
                        :class="
                            item.mime_type?.startsWith('image/')
                                ? 'cursor-move'
                                : ''
                        "
                        :title="item.name"
                    >
                         <div
                            class="py-0 px-1 cursor-pointer"
                            @click.stop="toggleSelection(item.id)"
                        >
                            <component
                                :is="
                                    isSelected(item.id)
                                        ? CheckSquare
                                        : Square
                                "
                                class="h-4 w-4"
                                :class="
                                    isSelected(item.id)
                                        ? 'text-[var(--interactive-primary)]'
                                        : 'text-[var(--text-muted)] hover:text-[var(--interactive-primary)]'
                                "
                            />
                        </div>
                        <div
                            class="h-10 w-10 shrink-0 rounded overflow-hidden bg-[var(--surface-tertiary)] border border-[var(--border-subtle)] flex items-center justify-center"
                        >
                            <img
                                v-if="
                                    (item.thumbnail_url || item.url) &&
                                    !item.mime_type?.includes('pdf') &&
                                    item.mime_type?.startsWith('image/')
                                "
                                :src="item.thumbnail_url || item.url"
                                class="w-full h-full object-cover"
                            />
                            <!-- File Preview for non-images -->
                            <div
                                v-else
                                class="h-full w-full bg-[var(--surface-tertiary)] flex items-center justify-center relative p-3 text-center"
                            >
                                <component
                                    :is="getFileIcon(item.mime_type)"
                                    class="w-4 h-4 text-[var(--text-muted)]"
                                />
                            </div>
                        </div>

                        <!-- Info -->
                        <div class="flex-1 min-w-0">
                            <p
                                class="text-sm font-medium text-[var(--text-primary)] truncate"
                                :title="item.name"
                            >
                                {{ item.name }}
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                {{ formatSize(item.size) }}
                            </p>
                        </div>

                        <!-- Actions -->
                        <button
                            v-if="canDelete"
                            @click.stop="emit('delete', item.id)"
                            class="p-1.5 rounded-md text-[var(--text-muted)] hover:text-red-500 hover:bg-red-50 opacity-0 group-hover:opacity-100 transition-all"
                            title="Delete"
                        >
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Embedded Storage Usage -->
            <div
                v-if="storageLimit > 0 && showStats"
                class="mt-4 pt-4 border-t border-[var(--border-default)]"
            >
                <div class="flex items-center justify-between text-xs mb-1">
                    <span class="text-[var(--text-secondary)] font-medium"
                        >Storage Usage</span
                    >
                    <span :class="{ 'text-red-500': isStorageNearLimit }"
                        >{{ usagePercentage }}%</span
                    >
                </div>
                <div
                    class="h-1.5 w-full bg-[var(--surface-tertiary)] rounded-full overflow-hidden"
                >
                    <div
                        class="h-full transition-all duration-500 ease-out rounded-full"
                        :class="
                            isStorageNearLimit
                                ? 'bg-red-500'
                                : 'bg-[var(--interactive-primary)]'
                        "
                        :style="{ width: `${usagePercentage}%` }"
                    ></div>
                </div>
                <div
                    class="flex justify-between mt-1 text-[10px] text-[var(--text-muted)]"
                >
                    <span>{{ formatSize(storageUsed) }} used</span>
                    <span>{{ formatSize(storageLimit) }} limit</span>
                </div>
            </div>
        </div>

        <!-- Content Area (Full) -->
        <div v-else class="flex flex-col flex-1 min-h-0 overflow-hidden">
            <!-- Upload Queue (Moved to Top) -->
            <div
                v-if="uploadQueue.length > 0"
                class="bg-[var(--surface-secondary)] border-b border-[var(--border-default)] transition-all shrink-0"
            >
                <div
                    class="flex items-center justify-between p-3 border-b border-[var(--border-default)] bg-[var(--surface-elevated)]"
                >
                    <h3 class="text-sm font-medium flex items-center gap-2">
                        <UploadCloud
                            class="h-4 w-4 text-[var(--interactive-primary)]"
                        />
                        Upload Queue ({{ uploadQueue.length }})
                    </h3>
                    <div class="flex items-center gap-2">
                        <Button
                            v-if="
                                !uploading &&
                                uploadQueue.some((i) => i.status === 'pending')
                            "
                            @click="emit('process-queue')"
                            size="sm"
                            class="h-7 text-xs"
                        >
                            Start Upload
                        </Button>
                        <button
                            @click="showQueue = !showQueue"
                            class="p-1 rounded hover:bg-[var(--surface-tertiary)] text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors"
                        >
                            <component
                                :is="showQueue ? ChevronDown : ChevronRight"
                                class="h-4 w-4"
                            />
                        </button>
                    </div>
                </div>

                <div
                    v-if="showQueue"
                    class="max-h-60 overflow-y-auto p-3 space-y-2 bg-[var(--surface-primary)]"
                >
                    <div
                        v-for="(item, index) in uploadQueue"
                        :key="item.id"
                        class="flex items-center gap-3 p-3 bg-[var(--surface-elevated)] rounded-lg border border-[var(--border-default)] shadow-sm animate-fade-in"
                    >
                        <!-- Icon -->
                        <div
                            class="h-10 w-10 shrink-0 rounded-lg bg-[var(--surface-tertiary)] flex items-center justify-center overflow-hidden border border-[var(--border-subtle)]"
                        >
                            <component
                                :is="getFileIcon(item.file.type || '')"
                                class="h-5 w-5 text-[var(--text-secondary)]"
                            />
                        </div>

                        <div class="flex-1 min-w-0">
                            <div
                                class="flex items-center justify-between mb-1.5"
                            >
                                <p
                                    class="text-xs font-medium truncate text-[var(--text-primary)]"
                                    :title="item.file.name"
                                >
                                    {{ item.file.name }}
                                </p>
                                <span
                                    class="text-[10px] font-medium px-1.5 py-0.5 rounded-full uppercase tracking-wide"
                                    :class="{
                                        'bg-gray-100 text-gray-600':
                                            item.status === 'pending',
                                        'bg-blue-100 text-blue-600':
                                            item.status === 'uploading',
                                        'bg-green-100 text-green-600':
                                            item.status === 'completed',
                                        'bg-red-100 text-red-600':
                                            item.status === 'error',
                                    }"
                                >
                                    {{
                                        item.status === "uploading"
                                            ? Math.round(item.progress) + "%"
                                            : item.status
                                    }}
                                </span>
                            </div>
                            <!-- Progress Bar -->
                            <div
                                class="h-1.5 bg-[var(--surface-tertiary)] rounded-full overflow-hidden"
                            >
                                <div
                                    class="h-full transition-all duration-300 rounded-full"
                                    :style="{ width: item.progress + '%' }"
                                    :class="{
                                        'bg-red-500': item.status === 'error',
                                        'bg-green-500':
                                            item.status === 'completed',
                                        'bg-[var(--interactive-primary)]':
                                            item.status === 'uploading' ||
                                            item.status === 'pending',
                                    }"
                                ></div>
                            </div>
                        </div>

                        <button
                            @click="emit('remove-upload', index)"
                            class="p-1.5 rounded-md text-[var(--text-muted)] hover:text-red-500 hover:bg-red-50 transition-colors"
                            :disabled="item.status === 'uploading'"
                            title="Remove"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Bulk Action Toolbar -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="transform -translate-y-2 opacity-0"
                enter-to-class="transform translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="transform translate-y-0 opacity-100"
                leave-to-class="transform -translate-y-2 opacity-0"
            >
                <div
                    v-if="selectedItems.length > 0"
                    class="bg-[var(--surface-elevated)] border-b border-[var(--border-default)] p-2 flex items-center justify-between transition-all shrink-0"
                >
                    <div class="flex items-center gap-3 px-2">
                        <span
                            class="font-medium text-[var(--interactive-primary)] flex items-center gap-2"
                        >
                            <CheckSquare class="h-4 w-4" />
                            {{ selectedItems.length }} selected
                        </span>
                        <button
                            @click="clearSelection"
                            class="text-xs text-[var(--text-muted)] hover:text-[var(--text-primary)] underline"
                        >
                            Clear
                        </button>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button
                            @click="emit('bulk-download', selectedItems)"
                            size="sm"
                            variant="outline"
                            class="h-8"
                        >
                            <Download class="h-3.5 w-3.5 mr-1.5" />
                            Download Zip
                        </Button>
                        <Button
                            @click="
                                emit('bulk-delete', selectedItems);
                                clearSelection();
                            "
                            size="sm"
                            variant="danger"
                            class="h-8"
                        >
                            <Trash2 class="h-3.5 w-3.5 mr-1.5" />
                            Delete
                        </Button>
                    </div>
                </div>
            </Transition>

            <div class="flex-1 overflow-y-auto p-4 min-h-[400px] relative">
                <!-- Loading State -->
                <Transition
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition duration-200 ease-in"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="loading"
                        class="absolute inset-0 flex items-center justify-center bg-[var(--surface-primary)]/80 z-30 backdrop-blur-[1px]"
                    >
                        <div class="flex flex-col items-center gap-3">
                            <Loader2
                                class="h-8 w-8 animate-spin text-[var(--interactive-primary)]"
                            />
                            <span
                                class="text-sm font-medium text-[var(--text-muted)]"
                                >Loading files...</span
                            >
                        </div>
                    </div>
                </Transition>

                <!-- Empty State -->
                <div
                    v-if="!loading && items.length === 0"
                    class="flex flex-col items-center justify-center h-full text-[var(--text-muted)] py-12"
                >
                    <div
                        class="h-16 w-16 mb-4 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center"
                    >
                        <Folder class="h-8 w-8 opacity-50" />
                    </div>
                    <p class="text-lg font-medium text-[var(--text-primary)]">
                        No files found
                    </p>
                    <p class="text-sm mb-6">
                        Upload files or drag and drop them here.
                    </p>
                    <Button
                        v-if="canUpload"
                        @click="triggerUpload"
                        variant="outline"
                    >
                        Upload File
                    </Button>
                </div>

                <!-- Grid View -->
                <div
                    v-else-if="viewMode === 'grid'"
                    class="mv-gallery grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4"
                >
                    <div
                        v-for="(item, index) in items"
                        :key="item.id"
                        class="group relative aspect-square bg-[var(--surface-secondary)] rounded-xl overflow-hidden border transition-all cursor-pointer shadow-sm hover:shadow-md"
                        :class="
                            isSelected(item.id)
                                ? 'border-[var(--interactive-primary)] ring-1 ring-[var(--interactive-primary)]'
                                : 'border-[var(--border-default)] hover:border-[var(--interactive-primary)]'
                        "
                        :title="item.name"
                    >
                        <!-- Selection Checkbox (Visible on hover or selected) -->
                        <div
                            class="absolute top-2 left-2 z-10 transition-opacity"
                            :class="
                                isSelected(item.id)
                                    ? 'opacity-100'
                                    : 'opacity-0 group-hover:opacity-100'
                            "
                            @click.stop="toggleSelection(item.id)"
                        >
                            <div
                                class="bg-[var(--surface-elevated)] rounded shadow-sm"
                            >
                                <component
                                    :is="
                                        isSelected(item.id)
                                            ? CheckSquare
                                            : Square
                                    "
                                    class="h-5 w-5"
                                    :class="
                                        isSelected(item.id)
                                            ? 'text-[var(--interactive-primary)]'
                                            : 'text-[var(--text-muted)] hover:text-[var(--interactive-primary)]'
                                    "
                                />
                            </div>
                        </div>

                        <!-- Clickable Overlay for View/Insert -->
                        <div
                            class="absolute inset-0 z-0"
                            @click="emit('view', { item, index, items })"
                        ></div>
                        <!-- Preview -->
                        <img
                            v-if="
                                (item.mime_type?.startsWith('image/') ||
                                    item.thumbnail_url) &&
                                !item.mime_type?.includes('pdf')
                            "
                            :src="item.thumbnail_url || item.url"
                            class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                            loading="lazy"
                            :title="item.name"
                        />
                        <div
                            v-else
                            class="w-full h-full flex flex-col items-center justify-center bg-[var(--surface-tertiary)] p-4 group-hover:bg-[var(--surface-secondary)] transition-colors"
                        >
                            <div
                                class="h-12 w-12 rounded-lg bg-[var(--surface-elevated)] shadow-sm flex items-center justify-center mb-2"
                            >
                                <component
                                    :is="getFileIcon(item.mime_type)"
                                    class="h-6 w-6 text-[var(--interactive-primary)]"
                                />
                            </div>
                            <span
                                class="text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                >{{ item.extension || "FILE" }}</span
                            >
                        </div>

                        <!-- Overlay Info -->
                        <div
                            class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 via-black/40 to-transparent p-3 pt-8 opacity-0 group-hover:opacity-100 transition-opacity flex flex-col justify-end"
                        >
                            <p class="text-white text-sm font-medium truncate">
                                {{ item.name }}
                            </p>
                            <p class="text-white/70 text-xs">
                                {{ formatSize(item.size) }}
                            </p>
                        </div>

                        <!-- Actions (Top Right) -->
                        <div
                            class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1"
                        >
                            <button
                                @click.stop="emit('download', item)"
                                class="p-1.5 rounded-full bg-black/50 text-white hover:bg-[var(--interactive-primary)] transition-colors backdrop-blur-sm"
                                title="Download"
                            >
                                <Download class="h-3.5 w-3.5" />
                            </button>
                            <button
                                v-if="canDelete"
                                @click.stop="emit('delete', item.id)"
                                class="p-1.5 rounded-full bg-black/50 text-white hover:bg-red-500 transition-colors backdrop-blur-sm"
                                title="Delete"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- List View -->
                <div
                    v-else
                    class="bg-[var(--surface-elevated)] rounded-lg border border-[var(--border-default)] overflow-hidden"
                >
                    <table class="w-full text-left text-sm">
                        <thead
                            class="bg-[var(--surface-secondary)] border-b border-[var(--border-default)]"
                        >
                            <tr>
                                <th class="py-3 px-4 w-10">
                                    <!-- Checkbox Column -->
                                </th>
                                <th
                                    class="py-3 px-4 font-medium text-[var(--text-secondary)]"
                                >
                                    Name
                                </th>
                                <th
                                    class="py-3 px-4 font-medium text-[var(--text-secondary)] hidden sm:table-cell"
                                >
                                    Type
                                </th>
                                <th
                                    class="py-3 px-4 font-medium text-[var(--text-secondary)] hidden sm:table-cell"
                                >
                                    Size
                                </th>
                                <th
                                    class="py-3 px-4 font-medium text-[var(--text-secondary)] hidden md:table-cell"
                                >
                                    Date
                                </th>
                                <th
                                    class="py-3 px-4 font-medium text-[var(--text-secondary)] text-right"
                                >
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border-default)]">
                            <tr
                                v-for="(item, index) in items"
                                :key="item.id"
                                class="hover:bg-[var(--surface-secondary)]/50 transition-colors group cursor-pointer"
                                :class="
                                    isSelected(item.id)
                                        ? 'bg-[var(--surface-secondary)]/50'
                                        : ''
                                "
                                @click="emit('view', { item, index, items })"
                            >
                                <td
                                    class="py-3 px-4"
                                    @click.stop="toggleSelection(item.id)"
                                >
                                    <component
                                        :is="
                                            isSelected(item.id)
                                                ? CheckSquare
                                                : Square
                                        "
                                        class="h-5 w-5"
                                        :class="
                                            isSelected(item.id)
                                                ? 'text-[var(--interactive-primary)]'
                                                : 'text-[var(--text-muted)] hover:text-[var(--interactive-primary)]'
                                        "
                                    />
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="h-10 w-10 shrink-0 rounded bg-[var(--surface-tertiary)] flex items-center justify-center overflow-hidden border border-[var(--border-subtle)]"
                                        >
                                            <img
                                                v-if="
                                                    item.mime_type?.startsWith(
                                                        'image/'
                                                    ) || item.thumbnail_url
                                                "
                                                :src="
                                                    item.thumbnail_url ||
                                                    item.url
                                                "
                                                class="w-full h-full object-cover"
                                            />
                                            <component
                                                v-else
                                                :is="
                                                    getFileIcon(item.mime_type)
                                                "
                                                class="h-5 w-5 text-[var(--text-secondary)]"
                                            />
                                        </div>
                                        <div class="min-w-0">
                                            <p
                                                class="font-medium text-[var(--text-primary)] truncate"
                                            >
                                                {{ item.name }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td
                                    class="py-3 px-4 text-[var(--text-secondary)] hidden sm:table-cell uppercase text-xs tracking-wider"
                                >
                                    {{ item.extension || "-" }}
                                </td>
                                <td
                                    class="py-3 px-4 text-[var(--text-secondary)] hidden sm:table-cell"
                                >
                                    {{ formatSize(item.size) }}
                                </td>
                                <td
                                    class="py-3 px-4 text-[var(--text-secondary)] hidden md:table-cell"
                                >
                                    {{ formatDate(item.created_at) }}
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <div
                                        class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity"
                                    >
                                        <Button
                                            @click.stop="emit('download', item)"
                                            variant="ghost"
                                            size="icon-sm"
                                        >
                                            <Download class="h-4 w-4" />
                                        </Button>
                                        <Button
                                            v-if="canDelete"
                                            @click.stop="
                                                emit('delete', item.id)
                                            "
                                            variant="ghost"
                                            size="icon-sm"
                                            class="text-red-500 hover:text-red-600 hover:bg-red-50"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <div
            v-if="total > 0"
            class="p-4 border-t border-[var(--border-default)] flex items-center justify-between bg-[var(--surface-secondary)]/30"
        >
            <div class="text-sm text-[var(--text-muted)]">
                Showing {{ (currentPage - 1) * perPage + 1 }} to
                {{ Math.min(currentPage * perPage, total) }} of
                {{ total }} files
            </div>

            <!-- Storage Usage -->
            <div
                v-if="storageLimit > 0 && showStats"
                class="hidden sm:flex items-center gap-3 px-3 py-1.5 bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-default)] transition-colors hover:border-[var(--border-strong)] group"
            >
                <div
                    class="p-1.5 rounded-md bg-[var(--surface-tertiary)] text-[var(--text-secondary)]"
                >
                    <Database class="h-3.5 w-3.5" />
                </div>
                <div class="flex flex-col gap-1 w-40">
                    <div
                        class="flex justify-between text-[10px] uppercase font-semibold tracking-wider text-[var(--text-secondary)]"
                    >
                        <span>Storage</span>
                        <span :class="{ 'text-red-500': isStorageNearLimit }"
                            >{{ usagePercentage }}%</span
                        >
                    </div>
                    <div
                        class="h-1.5 w-full bg-[var(--surface-tertiary)] rounded-full overflow-hidden"
                    >
                        <div
                            class="h-full transition-all duration-500 ease-out rounded-full"
                            :class="
                                isStorageNearLimit
                                    ? 'bg-red-500'
                                    : 'bg-[var(--interactive-primary)]'
                            "
                            :style="{ width: `${usagePercentage}%` }"
                        ></div>
                    </div>
                </div>
                <div
                    class="text-[10px] font-medium text-[var(--text-secondary)] whitespace-nowrap pl-2 border-l border-[var(--border-default)]"
                >
                    {{ formatSize(storageUsed) }}
                    <span class="text-[var(--text-muted)]">/</span>
                    {{ formatSize(storageLimit) }}
                </div>
            </div>

            <div class="flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="currentPage === 1"
                    @click="emit('update:page', currentPage - 1)"
                >
                    <ChevronLeft class="h-4 w-4" />
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="currentPage * perPage >= total"
                    @click="emit('update:page', currentPage + 1)"
                >
                    <ChevronRight class="h-4 w-4" />
                </Button>
            </div>
        </div>
    </div>
</template>
