<template>
    <div
        class="flex-1 flex flex-col h-full bg-(--surface-primary) overflow-hidden min-h-0"
    >
        <!-- Mobile Header (Navigation) -->
        <div
            class="lg:hidden shrink-0 h-14 flex items-center gap-1.5 px-3 border-b border-(--border-default) bg-(--surface-secondary) z-10"
        >
            <button
                @click="emit('toggle-sidebar')"
                class="p-2 text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) rounded-full transition-colors"
                title="Toggle sidebar"
            >
                <MenuIcon class="w-5 h-5" />
            </button>
            <button
                @click="emit('back')"
                class="p-2 text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) rounded-full transition-colors"
                title="Back to list"
            >
                <ArrowLeftIcon class="w-5 h-5" />
            </button>
            <div class="flex-1 min-w-0 ml-1">
                <h2
                    class="text-sm font-semibold text-(--text-primary) truncate"
                >
                    {{ props.email?.subject || activeTabLabel }}
                </h2>
            </div>
        </div>

        <!-- Email Content Area -->
        <div class="flex-1 overflow-hidden min-h-0 relative flex flex-col">
            <!-- Loading State -->
            <div v-if="loadingThread" class="p-8 flex justify-center">
                <LoaderIcon class="w-6 h-6 animate-spin text-(--text-muted)" />
            </div>

            <!-- Thread View -->
            <div
                v-show="activeTab === 'read'"
                class="flex-1 overflow-y-auto min-h-0 flex flex-col"
            >
                <div
                    v-if="threadMessages.length > 0"
                    class="flex-1 flex flex-col"
                >
                    <!-- Thread Header (only for multi-message threads) -->
                    <div
                        v-if="threadMessages.length > 1"
                        class="sticky top-0 z-10 px-5 py-2.5 bg-(--surface-primary)/95 backdrop-blur-sm border-b border-(--border-default) flex items-center justify-between gap-3 shrink-0"
                    >
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span
                                class="shrink-0 inline-flex items-center justify-center w-5 h-5 rounded-full text-[10px] font-bold bg-(--interactive-primary)/10 text-(--interactive-primary)"
                            >
                                {{ threadMessages.length }}
                            </span>
                            <h2
                                class="text-sm font-medium text-(--text-primary) truncate"
                            >
                                {{ props.email?.subject || "(No Subject)" }}
                            </h2>
                        </div>
                        <button
                            @click="toggleAllExpanded"
                            class="shrink-0 flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-medium text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-secondary) transition-colors"
                            :title="allExpanded ? 'Collapse All' : 'Expand All'"
                        >
                            <ChevronsUpDownIcon class="w-3.5 h-3.5" />
                            {{ allExpanded ? "Collapse" : "Expand" }}
                        </button>
                    </div>

                    <!-- Thread Messages -->
                    <div class="flex-1 flex flex-col">
                        <div
                            v-for="(msg, index) in threadMessages"
                            :key="msg.id"
                            class="flex flex-col"
                            :class="[msg.isExpanded ? 'flex-1' : '']"
                        >
                            <!-- Collapsed Message Row -->
                            <div
                                v-if="!msg.isExpanded"
                                @click="toggleExpand(index)"
                                class="group cursor-pointer flex items-center gap-3 px-5 py-2.5 transition-colors duration-100 border-b border-(--border-default)/50 hover:bg-(--surface-secondary)/60"
                                :class="[
                                    !msg.is_read
                                        ? 'bg-(--surface-primary)'
                                        : '',
                                ]"
                            >
                                <!-- Avatar -->
                                <div
                                    class="shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-xs font-semibold text-white shadow-sm"
                                    :style="{
                                        backgroundColor: getAvatarColor(
                                            msg.from_name || msg.from_email,
                                        ),
                                    }"
                                >
                                    {{
                                        getInitials(
                                            msg.from_name || msg.from_email,
                                        )
                                    }}
                                </div>

                                <!-- Main Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <span
                                            v-if="!msg.is_read"
                                            class="shrink-0 w-1.5 h-1.5 rounded-full bg-(--interactive-primary)"
                                        ></span>
                                        <span
                                            class="text-[13px] truncate"
                                            :class="
                                                msg.is_read
                                                    ? 'font-medium text-(--text-primary)'
                                                    : 'font-semibold text-(--text-primary)'
                                            "
                                        >
                                            {{
                                                msg.from_name || msg.from_email
                                            }}
                                        </span>
                                        <PaperclipIcon
                                            v-if="msg.has_attachments"
                                            class="shrink-0 w-3 h-3 text-(--text-muted)"
                                        />
                                        <StarIcon
                                            v-if="msg.is_starred"
                                            class="shrink-0 w-3 h-3 text-amber-400 fill-current"
                                        />
                                    </div>
                                    <p
                                        class="text-xs text-(--text-muted) truncate mt-0.5 leading-normal"
                                    >
                                        {{ msg.preview || "(No preview)" }}
                                    </p>
                                </div>

                                <div
                                    class="shrink-0 flex flex-col items-end gap-0.5"
                                >
                                    <span
                                        class="text-[11px] text-(--text-muted) whitespace-nowrap"
                                    >
                                        {{ formatDate(msg.date, "smart") }}
                                    </span>
                                    <span
                                        v-if="
                                            index ===
                                                threadMessages.length - 1 &&
                                            threadMessages.length > 1
                                        "
                                        class="px-1.5 py-px rounded text-[9px] font-semibold bg-(--interactive-primary)/10 text-(--interactive-primary)"
                                    >
                                        Latest
                                    </span>
                                </div>
                            </div>

                            <!-- Expanded Content -->
                            <div v-else class="flex flex-col flex-1 min-h-0">
                                <div
                                    v-if="threadMessages.length > 1"
                                    @click="toggleExpand(index)"
                                    class="group cursor-pointer flex items-center gap-3 px-5 py-2 border-b border-(--border-default)/50 bg-(--surface-secondary)/40 hover:bg-(--surface-secondary)/70 transition-colors"
                                >
                                    <div
                                        class="shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-xs font-semibold text-white shadow-sm ring-2 ring-(--interactive-primary)/20"
                                        :style="{
                                            backgroundColor: getAvatarColor(
                                                msg.from_name || msg.from_email,
                                            ),
                                        }"
                                    >
                                        {{
                                            getInitials(
                                                msg.from_name || msg.from_email,
                                            )
                                        }}
                                    </div>
                                    <div
                                        class="flex-1 min-w-0 flex items-center gap-2"
                                    >
                                        <span
                                            class="text-[13px] font-semibold text-(--text-primary) truncate"
                                        >
                                            {{
                                                msg.from_name || msg.from_email
                                            }}
                                        </span>
                                        <ChevronDownIcon
                                            class="shrink-0 w-3.5 h-3.5 text-(--text-muted)"
                                        />
                                    </div>
                                    <span
                                        class="text-[11px] text-(--text-muted) whitespace-nowrap"
                                    >
                                        {{ formatDate(msg.date, "smart") }}
                                    </span>
                                </div>
                                <EmailPreviewContent
                                    :email="msg"
                                    :embedded="true"
                                    @reply="openTab('reply', msg)"
                                    @reply-all="openTab('reply-all', msg)"
                                    @forward="openTab('forward', msg)"
                                    @forward-as-attachment="
                                        openTab('forward-as-attachment', msg)
                                    "
                                    @edit="openTab('edit', msg)"
                                />
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Empty State -->
                <div
                    v-else
                    class="flex-1 flex flex-col items-center justify-center text-(--text-muted) h-full"
                >
                    <MailIcon class="w-16 h-16 mb-4 text-(--text-tertiary)" />
                    <p>Select an email to read</p>
                </div>
            </div>

            <!-- Inline Composers (maintained per tab) -->
            <div
                v-for="tab in composerTabs"
                :key="tab.id"
                v-show="activeTab === tab.id"
                class="flex-1 flex flex-col min-h-0"
            >
                <EmailInlineComposer
                    :mode="tab.type"
                    :reply-to="tab.replyTo"
                    @close="closeTab(tab.id)"
                    @send="handleSend(tab.id)"
                />
            </div>
        </div>

        <!-- Anchored Attachments Bar (Clamped above Action Bar) -->
        <div
            v-if="
                props.email &&
                activeTab === 'read' &&
                visibleAttachments.length > 0
            "
            class="border-t border-(--border-default) bg-(--surface-secondary) backdrop-blur-sm"
        >
            <div
                class="px-4 py-2 flex items-center justify-between border-b border-(--border-default)/50"
            >
                <button
                    @click="isAttachmentsExpanded = !isAttachmentsExpanded"
                    class="text-[11px] font-bold tracking-tight text-(--text-primary) flex items-center gap-1.5 hover:text-(--interactive-primary) transition-colors select-none"
                >
                    <ChevronRightIcon
                        class="w-3.5 h-3.5 transition-transform duration-200"
                        :class="{ 'rotate-90': isAttachmentsExpanded }"
                    />
                    ATTACHMENTS
                    <span
                        class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-[10px] bg-red-500 text-white"
                    >
                        {{ visibleAttachments.length }}
                    </span>
                </button>

                <div class="flex items-center gap-3">
                    <button
                        v-if="selectedAttachments.size > 0"
                        @click="downloadSelected"
                        class="text-[10px] font-semibold text-(--interactive-primary) hover:underline"
                    >
                        {{
                            isDownloadingCloud
                                ? `Downloading ${downloadProgress.current}/${downloadProgress.total}...`
                                : hasCloudSelected
                                  ? "Download from Cloud"
                                  : "Download Selected"
                        }}
                        ({{ selectedAttachments.size }})
                    </button>
                    <button
                        @click="downloadAll"
                        class="text-[10px] font-semibold text-(--text-secondary) hover:text-(--text-primary) hover:underline"
                    >
                        Download All
                    </button>
                </div>
            </div>

            <div
                v-if="isAttachmentsExpanded"
                class="px-4 py-3 grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-[180px] overflow-y-auto scrollbar-thin"
            >
                <div
                    v-for="att in visibleAttachments"
                    :key="att.id"
                    class="group relative flex items-center p-2 border border-(--border-default) rounded-lg bg-(--surface-primary) hover:bg-(--surface-tertiary) transition-all cursor-pointer select-none"
                    :class="{
                        'ring-1 ring-inset ring-(--interactive-primary) bg-(--interactive-primary)/5':
                            selectedAttachments.has(att.id),
                    }"
                    @click="handleAttachmentClick(att)"
                >
                    <div class="p-1.5 rounded-md bg-(--interactive-primary)/10">
                        <component
                            :is="getAttachmentIcon(att.type)"
                            class="w-4 h-4 text-(--interactive-primary)"
                        />
                    </div>
                    <div class="ml-2.5 flex-1 min-w-0 pr-6">
                        <p
                            class="text-[11px] font-medium text-(--text-primary) truncate"
                            :title="att.name"
                        >
                            {{ att.name }}
                        </p>
                        <p class="text-[10px] text-(--text-muted)">
                            {{ formatSize(att.size) }}
                            <span
                                v-if="att.is_downloaded === false"
                                class="ml-1 text-amber-500 opacity-80"
                                >(Cloud)</span
                            >
                        </p>
                    </div>

                    <!-- Individual Download/Select Checkbox -->
                    <div class="absolute right-2 flex items-center gap-1.5">
                        <button
                            v-if="att.is_downloaded === false"
                            @click.stop="downloadOnDemand(att)"
                            class="p-1 text-(--interactive-primary) hover:bg-(--interactive-primary)/10 rounded transition-colors"
                        >
                            <LoaderIcon
                                v-if="isDownloading[att.id]"
                                class="w-3 h-3 animate-spin"
                            />
                            <DownloadIcon v-else class="w-3 h-3" />
                        </button>
                        <input
                            type="checkbox"
                            :checked="selectedAttachments.has(att.id)"
                            @change.stop="toggleAttachment(att.id)"
                            @click.stop
                            class="rounded border-(--border-default) text-(--interactive-primary) focus:ring-(--interactive-primary)/50 h-3.5 w-3.5"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Docked Action Bar (Clamped on top of Tab Bar) -->
        <div
            v-if="props.email && activeTab === 'read'"
            class="px-4 py-2.5 border-t border-(--border-default) bg-(--surface-primary) flex items-center gap-3 overflow-x-auto"
        >
            <div class="flex items-center gap-1.5 shrink-0">
                <!-- Main Replies or Edit Draft -->
                <div
                    class="flex items-center gap-1 p-0.5 bg-(--surface-secondary) rounded-lg border border-(--border-default)"
                >
                    <button
                        v-if="props.email.is_draft"
                        @click="openTab('edit', props.email)"
                        :disabled="!store.selectedAccount"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[11px] font-bold tracking-tight text-white bg-(--interactive-primary) hover:bg-(--interactive-primary-hover) transition-all shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <PencilIcon class="w-3.5 h-3.5" />
                        EDIT DRAFT
                    </button>
                    <template v-else>
                        <button
                            @click="
                                openTab(
                                    'reply',
                                    replyTargetEmail || props.email,
                                )
                            "
                            :disabled="!store.selectedAccount"
                            class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[11px] font-bold tracking-tight text-white bg-(--interactive-primary) hover:bg-(--interactive-primary-hover) transition-all shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <ReplyIcon class="w-3.5 h-3.5" />
                            REPLY
                        </button>
                        <button
                            @click="
                                openTab(
                                    'reply-all',
                                    replyTargetEmail || props.email,
                                )
                            "
                            :disabled="!store.selectedAccount"
                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-[11px] font-semibold text-(--text-primary) hover:bg-(--surface-tertiary) transition-all shadow-sm border border-transparent hover:border-(--border-default) disabled:opacity-50 disabled:cursor-not-allowed"
                            title="Reply All"
                        >
                            <ReplyAllIcon class="w-3.5 h-3.5" />
                            Reply All
                        </button>
                        <button
                            @click="
                                openTab(
                                    'forward',
                                    replyTargetEmail || props.email,
                                )
                            "
                            :disabled="!store.selectedAccount"
                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-[11px] font-semibold text-(--text-primary) hover:bg-(--surface-tertiary) transition-all shadow-sm border border-transparent hover:border-(--border-default) disabled:opacity-50 disabled:cursor-not-allowed"
                            title="Forward"
                        >
                            <ForwardIcon class="w-3.5 h-3.5" />
                            Forward
                        </button>
                    </template>
                </div>

                <!-- Secondary Actions -->
                <div
                    class="flex items-center gap-1 px-1 border-l border-(--border-default) ml-1"
                >
                    <button
                        @click="
                            openTab(
                                'forward-as-attachment',
                                replyTargetEmail || props.email,
                            )
                        "
                        class="p-2 text-(--text-secondary) hover:bg-(--surface-secondary) hover:text-(--text-primary) rounded-md transition-colors"
                        title="Forward as attachment"
                    >
                        <PaperclipIcon class="w-3.5 h-3.5" />
                    </button>
                    <button
                        @click="exportAsEml"
                        class="p-2 text-(--text-secondary) hover:bg-(--surface-secondary) hover:text-(--text-primary) rounded-md transition-colors"
                        title="Download as .eml"
                    >
                        <DownloadIcon class="w-3.5 h-3.5" />
                    </button>
                </div>
            </div>

            <!-- Toggles (Moved from Right) -->
            <div class="flex items-center gap-1 shrink-0">
                <!-- Star Action -->
                <button
                    @click="store.toggleStar(props.email.id)"
                    class="p-2 rounded-md transition-colors"
                    :class="
                        props.email.is_starred
                            ? 'text-amber-400 hover:bg-amber-400/10'
                            : 'text-(--text-secondary) hover:bg-(--surface-secondary) hover:text-(--text-primary)'
                    "
                    :title="props.email.is_starred ? 'Unstar' : 'Star'"
                >
                    <StarIcon
                        class="w-3.5 h-3.5"
                        :class="{ 'fill-current': props.email.is_starred }"
                    />
                </button>

                <!-- Read/Unread Action -->
                <button
                    @click="
                        store.markAsRead(props.email.id, !props.email.is_read)
                    "
                    class="p-2 text-(--text-secondary) hover:bg-(--surface-secondary) hover:text-(--text-primary) rounded-md transition-colors"
                    :title="
                        props.email.is_read ? 'Mark as Unread' : 'Mark as Read'
                    "
                >
                    <MailIcon v-if="props.email.is_read" class="w-3.5 h-3.5" />
                    <MailOpenIcon v-else class="w-3.5 h-3.5" />
                </button>

                <div class="w-px h-4 bg-(--border-default) mx-1"></div>

                <button
                    @click="deleteEmail"
                    class="p-2 text-(--text-secondary) hover:bg-error/10 hover:text-error rounded-md transition-colors"
                    title="Delete"
                >
                    <TrashIcon class="w-3.5 h-3.5" />
                </button>
            </div>
        </div>

        <!-- Tab Bar -->
        <div
            v-if="tabs.length > 0"
            class="border-t border-(--border-default) bg-(--surface-secondary)"
        >
            <div class="flex items-center gap-1 px-2 py-1.5 overflow-x-auto">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="group flex items-center gap-2 px-3 py-1.5 text-sm rounded-md transition-all duration-150"
                    :class="[
                        activeTab === tab.id
                            ? 'bg-(--interactive-primary) text-white shadow-sm'
                            : 'text-(--text-secondary) hover:bg-(--surface-tertiary) hover:text-(--text-primary)',
                    ]"
                >
                    <component :is="tab.icon" class="w-3.5 h-3.5" />
                    <span class="truncate max-w-[120px]">{{ tab.label }}</span>
                    <button
                        v-if="tab.closable"
                        @click.stop="closeTab(tab.id)"
                        class="ml-1 p-0.5 rounded hover:bg-white/20 transition-colors"
                        :class="
                            activeTab === tab.id
                                ? 'text-white/70 hover:text-white'
                                : 'text-(--text-muted) hover:text-(--text-primary)'
                        "
                    >
                        <XIcon class="w-3 h-3" />
                    </button>
                </button>

                <!-- New Compose Button -->
                <button
                    @click="openTab('compose')"
                    :disabled="!store.selectedAccount"
                    class="flex items-center gap-1.5 px-3 py-1.5 text-sm text-(--interactive-primary) hover:bg-(--surface-tertiary) rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed disabled:grayscale"
                    title="New Email"
                >
                    <PlusIcon class="w-3.5 h-3.5" />
                    <span class="hidden sm:inline">New</span>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, watch, computed, markRaw, onMounted, onUnmounted } from "vue";
import {
    MailIcon,
    MailOpenIcon,
    XIcon,
    PlusIcon,
    ReplyIcon,
    ReplyAllIcon,
    ForwardIcon,
    PaperclipIcon,
    PencilIcon,
    InboxIcon,
    LoaderIcon,
    TrashIcon,
    DownloadIcon,
    FileIcon,
    ImageIcon,
    ChevronRightIcon,
    ChevronDownIcon,
    ChevronsUpDownIcon,
    StarIcon,
    ArrowLeft as ArrowLeftIcon,
    Menu as MenuIcon,
} from "lucide-vue-next";
import axios from "axios";
import EmailPreviewContent from "./EmailPreviewContent.vue";
import EmailInlineComposer from "./EmailInlineComposer.vue";
// import Dropdown from "@/components/ui/Dropdown.vue";
import type { Email } from "@/types/models/email";
import { useEmailStore } from "@/stores/emailStore";
import { useDate } from "@/composables/useDate";

const { formatDate } = useDate();
interface Tab {
    id: string;
    label: string;
    icon: any;
    closable: boolean;
    type?: any;
    replyTo?: Email | null;
}

const props = defineProps<{
    email: Email | null;
}>();

onMounted(() => {
    console.log("[PreviewPane] Mounted. Email prop:", props.email?.id);
});

onUnmounted(() => {
    console.log("[PreviewPane] Unmounted!");
});

watch(
    () => props.email,
    (newVal, oldVal) => {
        console.log(
            `[PreviewPane] Email prop changed from ${oldVal?.id} to ${newVal?.id}`,
        );
    },
);

const emit = defineEmits<{
    (e: "back"): void;
    (e: "toggle-sidebar"): void;
    (e: "tab-closed", id: string): void;
}>();

const composerTabs = computed(() => tabs.value.filter((t) => t.id !== "read"));

const store = useEmailStore();
const activeTab = ref<string>("read");
const tabs = ref<Tab[]>([
    { id: "read", label: "Read", icon: markRaw(InboxIcon), closable: false },
]);

const activeTabLabel = computed(() => {
    const t = tabs.value.find((t) => t.id === activeTab.value);
    return t ? t.label : "Email";
});
// --- Export as EML ---
function exportAsEml() {
    if (!props.email) return;
    window.open(`/api/emails/${props.email.id}/export`, "_blank");
}

const visibleAttachments = computed(() => {
    if (!props.email?.attachments) return [];
    return props.email.attachments.filter((att: any) => !att.is_inline);
});

function getAttachmentIcon(type: string) {
    if (type?.startsWith("image/")) return ImageIcon;
    return FileIcon;
}

function formatSize(bytes: any) {
    const b = parseInt(String(bytes), 10);
    if (isNaN(b) || b <= 0) return "";

    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(b) / Math.log(k));

    // Safety check for index
    if (i < 0) return b + " B";
    if (i >= sizes.length)
        return (
            (b / Math.pow(k, sizes.length - 1)).toFixed(1) +
            " " +
            sizes[sizes.length - 1]
        );

    return parseFloat((b / Math.pow(k, i)).toFixed(1)) + " " + sizes[i];
}

// ... (existing code) ...

const threadMessages = ref<any[]>([]);
const loadingThread = ref(false);
const replyTargetEmail = ref<Email | null>(null); // Specific email being replied to in thread

// Read Receipt Logic removed (moved to EmailPreviewContent)

// ... (existing code) ...

// Attachment State
const isAttachmentsExpanded = ref(
    localStorage.getItem("email_attachments_expanded") !== "false",
); // Default true
const selectedAttachments = ref(new Set<string>());
const isDownloading = ref<Record<string, boolean>>({});
const isDownloadingCloud = ref(false);
const downloadProgress = ref({ current: 0, total: 0 });

watch(isAttachmentsExpanded, (val) => {
    localStorage.setItem("email_attachments_expanded", String(val));
});

function toggleAttachment(id: string) {
    if (selectedAttachments.value.has(id)) {
        selectedAttachments.value.delete(id);
    } else {
        selectedAttachments.value.add(id);
    }
}

const hasCloudSelected = computed(() => {
    return Array.from(selectedAttachments.value).some((id) => {
        const att = visibleAttachments.value.find((a: any) => a.id === id);
        return att?.is_downloaded === false;
    });
});

function handleAttachmentClick(att: any) {
    const isImage =
        att.type?.startsWith("image/") ||
        /\.(jpg|jpeg|png|gif|webp)$/i.test(att.name);
    const isVideo =
        att.type?.startsWith("video/") || /\.(mp4|webm|ogg)$/i.test(att.name);

    if ((isImage || isVideo) && att.url) {
        // Collect all navigable media
        const mediaList = visibleAttachments.value.filter(
            (a: any) =>
                (a.type?.startsWith("image/") ||
                    /\.(jpg|jpeg|png|gif|webp)$/i.test(a.name) ||
                    a.type?.startsWith("video/") ||
                    /\.(mp4|webm|ogg)$/i.test(a.name)) &&
                a.url,
        );

        const index = mediaList.findIndex((a: any) => a.id === att.id);

        const sources = mediaList.map((a: any) => ({
            src: a.url,
            download: `/api/emails/attachments/${a.id}/download`,
            type:
                a.type?.startsWith("video/") ||
                /\.(mp4|webm|ogg)$/i.test(a.name)
                    ? "video"
                    : "image",
            name: a.name,
            size: a.size,
            id: a.id,
            canDelete: false, // Hide delete button for email attachments
        }));

        window.dispatchEvent(
            new CustomEvent("media-viewer:open", {
                detail: { media: sources, index },
            }),
        );
    } else {
        toggleAttachment(att.id);
    }
}

function deleteEmail() {
    if (!props.email) return;
    if (confirm("Are you sure you want to delete this email?")) {
        store.deleteEmails([props.email.id]);
        // Ideally navigate away or clear selection, handled by store/parent usually
    }
}

async function downloadOnDemand(att: any) {
    if (isDownloading.value[att.id]) return;
    isDownloading.value[att.id] = true;
    try {
        const res = await axios.post(
            `/api/emails/${props.email?.id}/attachments/${att.placeholder_index}/download`,
        );
        if (res.data.attachment) {
            // Force cache bust to ensure image loads if previously 404
            if (res.data.attachment.url) {
                res.data.attachment.url += `?t=${new Date().getTime()}`;
            }
            Object.assign(att, res.data.attachment);
        }
    } catch (e) {
        console.error("Download failed", e);
    } finally {
        isDownloading.value[att.id] = false;
    }
}

async function downloadSelected() {
    // Separate selected into cloud and local
    const selected = Array.from(selectedAttachments.value)
        .map((id) => visibleAttachments.value.find((a: any) => a.id === id))
        .filter(Boolean);

    const cloudItems = selected.filter((a: any) => a.is_downloaded === false);
    const localItems = selected.filter((a: any) => a.is_downloaded !== false);

    // Priority 1: Download from Cloud
    if (cloudItems.length > 0) {
        isDownloadingCloud.value = true;
        downloadProgress.value = { current: 0, total: cloudItems.length };

        // Trigger downloadOnDemand for each
        for (const att of cloudItems) {
            await downloadOnDemand(att);
            downloadProgress.value.current++;
        }

        isDownloadingCloud.value = false;
        return;
    }

    // Priority 2: Download Local (Zip or Single)
    if (localItems.length === 0) return;

    if (localItems.length === 1) {
        // Single file - direct download
        const att = localItems[0];
        if (att && att.url) {
            // Use API download endpoint to ensure headers force download/save dialog
            window.open(`/api/emails/attachments/${att.id}/download`, "_blank");
        }
    } else {
        // Multiple files - batch download as ZIP
        const ids = localItems.map((a: any) => a.id);
        const form = document.createElement("form");
        form.method = "POST";
        form.action = "/api/emails/attachments/download-batch";
        form.target = "_blank";

        const csrfToken = document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute("content");
        if (csrfToken) {
            const csrfInput = document.createElement("input");
            csrfInput.type = "hidden";
            csrfInput.name = "_token";
            csrfInput.value = csrfToken;
            form.appendChild(csrfInput);
        }

        ids.forEach((id: string) => {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = "ids[]";
            input.value = id;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
}

function downloadAll() {
    visibleAttachments.value.forEach((att) => {
        if (att.url) window.open(att.url, "_blank");
    });
}

// Watch for email changes to fetch thread
watch(
    () => props.email,
    async (newEmail) => {
        if (newEmail) {
            activeTab.value = "read";

            loadingThread.value = true;
            const threadId = newEmail.thread_id || newEmail.id;

            try {
                const messages: any = await store.fetchThread(threadId);

                const messageList = Array.isArray(messages)
                    ? messages
                    : messages?.data || [];

                threadMessages.value = messageList.map(
                    (msg: any, index: number) => ({
                        ...msg,
                        isExpanded: index === messageList.length - 1,
                    }),
                );
            } catch (e) {
                // Fallback to single email if fetch fails
                threadMessages.value = [{ ...newEmail, isExpanded: true }];
            } finally {
                loadingThread.value = false;
            }
        } else {
            threadMessages.value = [];
        }
    },
    { immediate: true },
);

function toggleExpand(index: number) {
    if (threadMessages.value[index]) {
        threadMessages.value[index].isExpanded =
            !threadMessages.value[index].isExpanded;
    }
}

const allExpanded = computed(
    () =>
        threadMessages.value.length > 0 &&
        threadMessages.value.every((m: any) => m.isExpanded),
);

function toggleAllExpanded() {
    const shouldExpand = !allExpanded.value;
    threadMessages.value.forEach((msg: any) => {
        msg.isExpanded = shouldExpand;
    });
}

function getInitials(name: string): string {
    if (!name) return "?";
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) {
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }
    return name.substring(0, 2).toUpperCase();
}

function getAvatarColor(name: string): string {
    const colors = [
        "#6366f1",
        "#8b5cf6",
        "#a855f7",
        "#d946ef",
        "#ec4899",
        "#f43f5e",
        "#ef4444",
        "#f97316",
        "#f59e0b",
        "#84cc16",
        "#22c55e",
        "#14b8a6",
        "#06b6d4",
        "#0ea5e9",
        "#3b82f6",
        "#6366f1",
    ];
    let hash = 0;
    for (let i = 0; i < (name || "").length; i++) {
        hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }
    return colors[Math.abs(hash) % colors.length];
}

// Local formatDate removed in favor of useDate composable with 'smart' formatting

// Listen for postMessage from popup windows
onMounted(() => {
    window.addEventListener("message", handlePopupMessage);
});

onUnmounted(() => {
    window.removeEventListener("message", handlePopupMessage);
});

function handlePopupMessage(event: MessageEvent) {
    if (
        event.data?.type &&
        ["reply", "reply-all", "forward", "forward-as-attachment"].includes(
            event.data.type,
        )
    ) {
        // Default to latest message if not specified?
        openTab(event.data.type as any, props.email);
    }
}

function openTab(
    type:
        | "reply"
        | "reply-all"
        | "forward"
        | "compose"
        | "forward-as-attachment"
        | "edit",
    targetEmail: Email | null = null,
) {
    const emailToUse = targetEmail || props.email;
    const id = `${type}-${Date.now()}`;
    const labels = {
        reply: `Re: ${emailToUse?.subject || "Reply"}`,
        "reply-all": `Re All: ${emailToUse?.subject || "Reply All"}`,
        forward: `Fwd: ${emailToUse?.subject || "Forward"}`,
        "forward-as-attachment": `Fwd(Att): ${emailToUse?.subject || "Forward"}`,
        compose: "New Email",
        edit: `Edit: ${emailToUse?.subject || "Draft"}`,
    };
    const icons = {
        reply: markRaw(ReplyIcon),
        "reply-all": markRaw(ReplyAllIcon),
        forward: markRaw(ForwardIcon),
        "forward-as-attachment": markRaw(PaperclipIcon),
        compose: markRaw(PlusIcon),
        edit: markRaw(PencilIcon),
    };

    tabs.value.push({
        id,
        label: labels[type],
        icon: icons[type],
        closable: true,
        type,
        replyTo: emailToUse,
    });

    activeTab.value = id;
}

function closeTab(id: string) {
    const index = tabs.value.findIndex((t) => t.id === id);
    if (index > -1) {
        tabs.value.splice(index, 1);
        // If closing active tab, switch to read
        if (activeTab.value === id) {
            activeTab.value = "read";
            replyTargetEmail.value = null;
        }
        // Notify parent that a tab was closed
        emit("tab-closed", id);

        // Refresh?
    }
}

function closeActiveTab() {
    if (activeTab.value !== "read") {
        closeTab(activeTab.value);
    }
}

function handleSend(tabId: string) {
    closeTab(tabId);
}

// Expose for parent component
defineExpose({
    openTab,
});
</script>
