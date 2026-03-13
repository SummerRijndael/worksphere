<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import type { Message, MessageAttachment } from "@/types/models/chat";
import { Icon, Avatar } from "@/components/ui";
import LinkPreview from "@/components/LinkPreview.vue";
import AudioMessagePlayer from "@/components/chat/AudioMessagePlayer.vue";
import { useVideoCallStore } from "@/stores/videocall";
import { useAuthStore } from "@/stores/auth";

const videoCallStore = useVideoCallStore();

interface Props {
    message: Message;
    isMine: boolean;
    showJoinButton?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showJoinButton: true,
});

const emit = defineEmits<{
    reply: [message: Message];
    jump: [messageId: string];
    retry: [messageId: string];
    react: [reaction: string];
    togglePin: [];
    startEdit: [];
    delete: [scope: "me" | "all"];
    callback: [data: { chatId: string; callType: "video" | "audio" }];
    "join-call": [
        data: { chatId: string; callId: string; callType: "video" | "audio" },
    ];
}>();

const authStore = useAuthStore();
const showReactionMenu = ref(false);
const showMoreMenu = ref(false);
const showHistory = ref(false);
const actionMenuRef = ref<HTMLElement | null>(null);
const isActionHovering = ref(false);
let hideActionsTimeout: ReturnType<typeof setTimeout> | null = null;

const REACTION_OPTIONS = [
    { key: "like", emoji: "👍", label: "Like" },
    { key: "laugh", emoji: "😂", label: "Laugh" },
    { key: "hundred", emoji: "💯", label: "100" },
    { key: "care", emoji: "🤗", label: "Care" },
    { key: "angry", emoji: "😡", label: "Angry" },
    { key: "scared", emoji: "😱", label: "Scared" },
    { key: "sad", emoji: "😢", label: "Sad" },
    { key: "love", emoji: "❤️", label: "Love" },
] as const;
const QUICK_REACTION_KEYS = ["love", "laugh", "scared", "sad", "angry", "like"] as const;

const formatTime = (dateStr: string): string => {
    return new Date(dateStr).toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
    });
};

const isImage = (attachment: MessageAttachment) => {
    if (attachment.media_kind) {
        return attachment.media_kind === "image";
    }
    return String(attachment.mime_type || "")
        .toLowerCase()
        .startsWith("image/");
};

const isAudio = (attachment: MessageAttachment) => {
    if (attachment.media_kind) {
        return attachment.media_kind === "audio";
    }
    if (attachment.is_audio === true || attachment.is_voice_clip === true) {
        return true;
    }

    const value = String(attachment.mime_type || "").toLowerCase();
    if (!value) return false;
    if (value.startsWith("audio/")) return true;
    // Browser audio recorder may produce audio-only clips in video/* containers.
    // Restrict this fallback to recorder-like filenames to avoid rendering real videos as audio.
    if (value.startsWith("video/")) {
        const name = String(attachment.name || "").trim().toLowerCase();
        return (
            name.startsWith("voice-") ||
            name.startsWith("audio-") ||
            name.startsWith("recording-")
        );
    }
    return false;
};

const isVideo = (attachment: MessageAttachment) => {
    if (attachment.media_kind) {
        return attachment.media_kind === "video";
    }
    if (attachment.is_video === true) {
        return true;
    }
    return String(attachment.mime_type || "")
        .toLowerCase()
        .startsWith("video/");
};

const files = computed<MessageAttachment[]>(
    () =>
        props.message.attachments?.filter(
            (a) => !isImage(a) && !isAudio(a) && !isVideo(a),
        ) || [],
);
const images = computed<MessageAttachment[]>(
    () => props.message.attachments?.filter((a) => isImage(a)) || [],
);
const audioFiles = computed<MessageAttachment[]>(
    () => props.message.attachments?.filter((a) => isAudio(a)) || [],
);
const videos = computed<MessageAttachment[]>(
    () =>
        props.message.attachments?.filter(
            (a) => isVideo(a) && !isAudio(a),
        ) || [],
);
const giphy = computed(() => props.message.metadata?.giphy);
const failedVideoThumbs = ref<Set<string>>(new Set());
const loadedImageThumbs = ref<Set<string>>(new Set());
const loadedVideoThumbs = ref<Set<string>>(new Set());
const isGiphyLoaded = ref(false);

const canUseVideoThumb = (attachment: MessageAttachment) =>
    Boolean(attachment.thumb_url) &&
    !failedVideoThumbs.value.has(String(attachment.id));

const markVideoThumbFailed = (attachment: MessageAttachment) => {
    failedVideoThumbs.value.add(String(attachment.id));
};

const attachmentLoadKey = (
    attachment: MessageAttachment,
    kind: "image" | "video",
) => {
    const identity = attachment.id ?? attachment.url ?? attachment.name ?? "unknown";
    return `${kind}:${props.message.id}:${String(identity)}`;
};

const isImageThumbLoaded = (attachment: MessageAttachment) =>
    loadedImageThumbs.value.has(attachmentLoadKey(attachment, "image"));

const markImageThumbLoaded = (attachment: MessageAttachment) => {
    loadedImageThumbs.value.add(attachmentLoadKey(attachment, "image"));
};

const isVideoThumbLoaded = (attachment: MessageAttachment) =>
    loadedVideoThumbs.value.has(attachmentLoadKey(attachment, "video"));

const markVideoThumbLoaded = (attachment: MessageAttachment) => {
    loadedVideoThumbs.value.add(attachmentLoadKey(attachment, "video"));
};

// Limit to 4 images for grid
const displayImages = computed(() => images.value.slice(0, 4));

const gridClass = computed(() => {
    const count = images.value.length;
    if (count === 1) return "grid-cols-1 max-w-[200px]"; // Smaller max-width for mini chat
    if (count === 2) return "grid-cols-2 max-w-[220px]";
    if (count >= 3) return "grid-cols-2 max-w-[220px]";
    return "";
});

function getImageClass(index: number, total: number) {
    if (total === 3) {
        if (index === 0) return "col-span-2 aspect-[2/1]";
        return "aspect-square";
    }
    return "aspect-square";
}

const formatFileSize = (bytes: number) => {
    const value = Number(bytes || 0);
    if (value < 1024) return `${value} B`;
    const k = 1024;
    const sizes = ["KB", "MB", "GB", "TB"];
    const i = Math.min(
        Math.floor(Math.log(value) / Math.log(k)),
        sizes.length,
    );
    return `${parseFloat((value / Math.pow(k, i)).toFixed(1))} ${sizes[i - 1]}`;
};

const formatDuration = (seconds: number) => {
    if (!seconds && seconds !== 0) return "";
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;

    if (h > 0)
        return `${h}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
    return `${m}:${String(s).padStart(2, "0")}`;
};

const getAudioLabel = (attachment: MessageAttachment) => {
    const name = String(attachment.name || "").trim().toLowerCase();
    if (name.startsWith("voice-")) return "Voice message";
    return "Audio clip";
};

function handleImageClick(img: MessageAttachment) {
    const allImages = images.value;
    const mediaForViewer = allImages.map((i) => ({
        src: i.url,
        download: i.download_url || i.url,
        id: i.id,
        type: "image",
        mimeType: i.mime_type,
    }));

    const index = mediaForViewer.findIndex((m) => m.id === img.id);

    window.dispatchEvent(
        new CustomEvent("media-viewer:open", {
            detail: {
                media: mediaForViewer,
                index: index >= 0 ? index : 0,
            },
        }),
    );
}

function handleVideoClick(video: MessageAttachment) {
    const allVideos = videos.value;
    const mediaForViewer = allVideos.map((item) => ({
        src: item.url,
        download: item.download_url || item.url,
        id: item.id,
        type: "video",
        mimeType: item.mime_type,
    }));

    const index = mediaForViewer.findIndex((item) => item.id === video.id);

    window.dispatchEvent(
        new CustomEvent("media-viewer:open", {
            detail: {
                media: mediaForViewer,
                index: index >= 0 ? index : 0,
            },
        }),
    );
}

watch(
    () => props.message.id,
    () => {
        loadedImageThumbs.value = new Set();
        loadedVideoThumbs.value = new Set();
        failedVideoThumbs.value = new Set();
        isGiphyLoaded.value = false;
    },
);

const firstUrl = computed(() => {
    if (isDeleted.value || !props.message.content) return null;
    const match = props.message.content.match(/(https?:\/\/[^\s]+)/);
    return match ? match[0] : null;
});

const myPublicId = computed(() =>
    String(authStore.user?.public_id || "").trim().toLowerCase(),
);
const myName = computed(() =>
    String(authStore.user?.name || "").trim().toLowerCase(),
);
const isCallerSideCallEvent = computed(() => {
    const event = String(props.message.metadata?.event || "");
    if (!["missed", "no_answer", "cancelled"].includes(event)) return false;

    const callerPublicId = String(
        props.message.metadata?.caller_public_id || "",
    )
        .trim()
        .toLowerCase();

    if (callerPublicId && callerPublicId === myPublicId.value) return true;
    const callerName = String(props.message.metadata?.caller_name || "")
        .trim()
        .toLowerCase();
    if (callerName && myName.value && callerName === myName.value) return true;
    return props.isMine;
});
const isCallerSideMissedEvent = computed(
    () =>
        ["missed", "no_answer"].includes(String(props.message.metadata?.event || "")) &&
        isCallerSideCallEvent.value,
);

const isPinned = computed(
    () => !!(props.message.is_pinned || props.message.metadata?.is_pinned),
);
const isDeleted = computed(
    () =>
        Boolean(props.message.is_deleted) ||
        Boolean(props.message.metadata?.is_deleted),
);
const isEdited = computed(
    () =>
        Boolean(props.message.is_edited) ||
        Boolean(props.message.metadata?.is_edited),
);
const editHistory = computed(() => {
    const raw = props.message.metadata?.edit_history;
    return Array.isArray(raw)
        ? raw.filter((entry) => typeof entry === "object" && !!entry)
        : [];
});
const canEdit = computed(
    () =>
        props.isMine &&
        !isDeleted.value &&
        audioFiles.value.length === 0 &&
        videos.value.length === 0 &&
        !props.message.pending &&
        !props.message.failed,
);
const canDeleteForAll = computed(
    () => props.isMine && !props.message.pending && !props.message.failed,
);
const canHideForMe = computed(
    () => !props.message.pending && !props.message.failed,
);

const reactionBuckets = computed<Record<string, string[]>>(() => {
    const source =
        props.message.reactions && typeof props.message.reactions === "object"
            ? props.message.reactions
            : props.message.metadata?.reactions;

    if (!source || typeof source !== "object") return {};

    const normalized: Record<string, string[]> = {};
    Object.entries(source).forEach(([rawKey, ids]) => {
        if (!Array.isArray(ids)) return;
        const key = rawKey === "100" ? "hundred" : String(rawKey).toLowerCase();
        const values = Array.from(
            new Set(
                ids
                    .map((id) => String(id || "").toLowerCase())
                    .filter(Boolean),
            ),
        );
        if (!values.length) return;
        normalized[key] = Array.from(
            new Set([...(normalized[key] || []), ...values]),
        );
    });

    return normalized;
});

const visibleReactions = computed(() => {
    if (isDeleted.value) return [];

    return REACTION_OPTIONS.map((option) => {
        const bucket = reactionBuckets.value[option.key] || [];
        return {
            ...option,
            count: bucket.length,
            active:
                myPublicId.value !== "" &&
                bucket.includes(myPublicId.value),
        };
    }).filter((item) => item.count > 0);
});
const quickReactionOptions = computed(() =>
    QUICK_REACTION_KEYS.map((key) =>
        REACTION_OPTIONS.find((option) => option.key === key),
    ).filter((option): option is (typeof REACTION_OPTIONS)[number] =>
        Boolean(option),
    ),
);

function toggleReactionMenu() {
    if (isDeleted.value) return;
    showMoreMenu.value = false;
    showReactionMenu.value = !showReactionMenu.value;
}

function selectReaction(reaction: string) {
    if (isDeleted.value) return;
    emit("react", reaction);
    showReactionMenu.value = false;
}

function startEdit() {
    showReactionMenu.value = false;
    showMoreMenu.value = false;
    showHistory.value = false;
    emit("startEdit");
}

function toggleMoreMenu() {
    showReactionMenu.value = false;
    showMoreMenu.value = !showMoreMenu.value;
}

function closeMenus() {
    showReactionMenu.value = false;
    showMoreMenu.value = false;
}

function toggleHistory() {
    if (!editHistory.value.length) return;
    showHistory.value = !showHistory.value;
    closeMenus();
}

function keepActionsVisible() {
    if (hideActionsTimeout) {
        clearTimeout(hideActionsTimeout);
        hideActionsTimeout = null;
    }
    isActionHovering.value = true;
}

function scheduleHideActions() {
    if (hideActionsTimeout) {
        clearTimeout(hideActionsTimeout);
    }

    hideActionsTimeout = setTimeout(() => {
        isActionHovering.value = false;
    }, 180);
}

function handleDocumentClick(event: MouseEvent) {
    const target = event.target;
    if (!(target instanceof Node)) return;
    if (actionMenuRef.value?.contains(target)) return;
    closeMenus();
}

onMounted(() => {
    document.addEventListener("click", handleDocumentClick);
});

onUnmounted(() => {
    document.removeEventListener("click", handleDocumentClick);
    if (hideActionsTimeout) {
        clearTimeout(hideActionsTimeout);
    }
});
</script>

<template>
    <!-- System Message -->
    <div v-if="message.type === 'system'" class="flex justify-center my-2 px-4">
        <div
            class="flex items-center gap-1.5 text-[10px] text-(--text-tertiary) bg-(--surface-tertiary)/50 border border-(--border-default) px-2 py-1 rounded-full shadow-sm italic"
            :class="{
                'text-red-500! border-red-200! bg-red-50!':
                    (message.metadata?.event === 'missed' ||
                        message.metadata?.event === 'no_answer') &&
                    !isCallerSideMissedEvent,
            }"
        >
            <template v-if="message.metadata?.system_type === 'call_event'">
                <Icon
                    :name="
                        message.metadata.event === 'missed' ||
                        message.metadata.event === 'no_answer'
                            ? 'PhoneMissed'
                            : message.metadata.type === 'video'
                              ? 'Video'
                              : 'Phone'
                    "
                    :size="10"
                    class="opacity-70"
                />
                <span v-if="message.metadata.event === 'started'">
                    {{ message.metadata.user_name }} started a
                    {{ message.metadata.type }} call
                </span>
                <span v-else-if="message.metadata.event === 'ended'">
                    Call ended
                    <span v-if="message.metadata.duration"
                        >({{ formatDuration(message.metadata.duration) }})</span
                    >
                </span>
                <span
                    v-else-if="
                        message.metadata.event === 'missed' ||
                        message.metadata.event === 'no_answer'
                    "
                >
                    <template v-if="isCallerSideMissedEvent">
                        No answer on your {{ message.metadata.type }} call
                    </template>
                    <template v-else>
                        Missed {{ message.metadata.type }} call
                        <span v-if="message.metadata.caller_name">
                            from {{ message.metadata.caller_name }}</span
                        >
                    </template>
                </span>
                <span v-else-if="message.metadata.event === 'declined'">
                    {{ message.metadata.type }} call declined
                </span>
                <span v-else-if="message.metadata.event === 'cancelled'">
                    <template v-if="isCallerSideCallEvent">
                        You cancelled the {{ message.metadata.type }} call
                    </template>
                    <template v-else>
                        {{ message.metadata.type }} call cancelled
                    </template>
                </span>

                <!-- Active Call Join Button -->
                <button
                    v-if="
                        showJoinButton &&
                        message.metadata.event === 'started' &&
                        message.chat_id &&
                        videoCallStore.activeCalls.has(message.chat_id)
                    "
                    class="ml-2 px-1.5 py-0.5 rounded-full bg-green-500 hover:bg-green-600 text-white text-[9px] font-medium transition-colors flex items-center gap-0.5 cursor-pointer not-italic"
                    @click="
                        message.chat_id &&
                            emit('join-call', {
                                chatId: message.chat_id,
                                callId: message.metadata.call_id || '',
                                callType: message.metadata.type,
                            })
                    "
                >
                    <Icon name="PhoneIncoming" :size="8" />
                    Join Call
                </button>

                <span v-else>
                    {{ message.content }}
                </span>
                <!-- Call Back button -->
                <button
                    v-if="
                        !isCallerSideMissedEvent &&
                        (message.metadata.event === 'missed' ||
                            message.metadata.event === 'no_answer')
                    "
                    class="ml-2 px-1.5 py-0.5 rounded-full bg-green-500 hover:bg-green-600 text-white text-[9px] font-medium transition-colors flex items-center gap-0.5 cursor-pointer not-italic"
                    @click="
                        emit('callback', {
                            chatId: message.metadata.chat_id || message.chat_id || '',
                            callType: message.metadata.type,
                        })
                    "
                >
                    <Icon name="PhoneOutgoing" :size="8" />
                    Call Back
                </button>
            </template>
            <template v-else>
                <span>{{ message.content }}</span>
            </template>
        </div>
    </div>

    <!-- User Message -->
    <div
        v-else
        class="minichat-message"
        :class="{ 'is-own': isMine }"
        @mouseenter="keepActionsVisible"
        @mouseleave="scheduleHideActions"
    >
        <Avatar
            v-if="!isMine"
            :src="message.user_avatar"
            :alt="message.user_name"
            size="xs"
            class="minichat-message-avatar"
        />

        <div class="minichat-message-wrapper">
            <!-- Reply reference -->
            <div
                v-if="message.reply_to"
                class="minichat-reply-ref cursor-pointer hover:opacity-80 transition-opacity"
                @click="emit('jump', String(message.reply_to.id))"
            >
                <Icon name="CornerUpRight" :size="10" />
                <span
                    >{{ message.reply_to.user_name }}:
                    {{ message.reply_to.content?.slice(0, 30) }}...</span
                >
            </div>

            <div class="minichat-message-bubble">
                <!-- Attachments -->
                <div
                    v-if="!isDeleted && (images.length || videos.length || audioFiles.length || files.length)"
                    class="space-y-1.5 mb-1.5 minichat-attachments-block"
                >
                    <!-- Image Grid -->
                    <div
                        v-if="images.length"
                        class="grid gap-0.5 overflow-hidden rounded-lg w-full"
                        :class="gridClass"
                    >
                        <div
                            v-for="(img, index) in displayImages"
                            :key="img.id"
                            class="relative bg-black/5 dark:bg-white/5 overflow-hidden group/img ring-1 ring-black/5 dark:ring-white/10"
                            :class="[getImageClass(index, images.length)]"
                        >
                            <div
                                v-if="!isImageThumbLoaded(img)"
                                class="minichat-thumb-skeleton minichat-thumb-shimmer"
                            />
                            <img
                                :src="img.thumb_url || img.url"
                                loading="lazy"
                                decoding="async"
                                class="w-full h-full object-cover cursor-pointer transition-[transform,opacity] duration-500 hover:scale-105"
                                :class="
                                    isImageThumbLoaded(img)
                                        ? 'opacity-100'
                                        : 'opacity-0'
                                "
                                @click="handleImageClick(img)"
                                @load="markImageThumbLoaded(img)"
                                @error="markImageThumbLoaded(img)"
                            />

                            <!-- +N Overlay -->
                            <div
                                v-if="index === 3 && images.length > 4"
                                class="absolute inset-0 bg-black/50 hover:bg-black/60 transition-colors flex items-center justify-center cursor-pointer text-white font-bold text-sm backdrop-blur-sm"
                                @click="handleImageClick(img)"
                            >
                                +{{ images.length - 3 }}
                            </div>
                        </div>
                    </div>

                    <div v-if="videos.length" class="space-y-1">
                        <template v-for="video in videos" :key="video.id">
                            <button
                                v-if="canUseVideoThumb(video)"
                                type="button"
                                class="group/video minichat-video-card relative overflow-hidden rounded-[10px] border text-left transition-all duration-200"
                                :class="
                                    isMine
                                        ? 'border-white/24 bg-black/16 hover:border-white/40'
                                        : 'border-(--border-default) bg-(--surface-secondary) hover:border-(--interactive-primary)/40'
                                "
                                @click="handleVideoClick(video)"
                            >
                                <div class="relative aspect-video w-full overflow-hidden">
                                    <div
                                        v-if="!isVideoThumbLoaded(video)"
                                        class="minichat-thumb-skeleton minichat-thumb-shimmer"
                                    />
                                    <img
                                        :src="video.thumb_url || undefined"
                                        :alt="video.name"
                                        loading="lazy"
                                        decoding="async"
                                        class="h-full w-full object-cover transition-[transform,opacity] duration-500 group-hover/video:scale-[1.04]"
                                        :class="
                                            isVideoThumbLoaded(video)
                                                ? 'opacity-100'
                                                : 'opacity-0'
                                        "
                                        @load="markVideoThumbLoaded(video)"
                                        @error="
                                            markVideoThumbLoaded(video);
                                            markVideoThumbFailed(video);
                                        "
                                    />
                                    <div
                                        class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/28 to-black/8"
                                    />
                                    <span
                                        class="absolute inset-0 flex items-center justify-center pointer-events-none"
                                    >
                                        <span
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-black/55 text-white ring-1 ring-white/30 shadow"
                                        >
                                            <Icon name="Play" :size="12" />
                                        </span>
                                    </span>
                                    <span
                                        class="absolute left-1.5 top-1.5 inline-flex items-center rounded-full bg-black/45 px-1.5 py-0.5 text-[9px] font-medium tracking-wide text-white ring-1 ring-white/25"
                                    >
                                        VIDEO
                                    </span>
                                    <div
                                        class="absolute inset-x-1.5 bottom-1.5"
                                    >
                                        <div
                                            class="min-w-0 rounded-md bg-black/48 px-1.5 py-1 ring-1 ring-white/15 backdrop-blur-[1px]"
                                        >
                                            <span
                                                class="block truncate text-[10px] leading-tight font-semibold drop-shadow"
                                                :title="video.name"
                                            >
                                                {{ video.name }}
                                            </span>
                                            <span
                                                class="block text-[9px] leading-tight text-white/85 drop-shadow"
                                            >
                                                {{ formatFileSize(video.size) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </button>
                            <button
                                v-else
                                type="button"
                                class="minichat-attachment-file minichat-video-file group/file"
                                @click="handleVideoClick(video)"
                            >
                                <span class="minichat-attachment-icon">
                                    <Icon name="Play" :size="14" />
                                </span>
                                <div class="minichat-file-meta">
                                    <span class="minichat-file-name" :title="video.name">{{
                                        video.name
                                    }}</span>
                                    <span class="minichat-file-size">{{
                                        formatFileSize(video.size)
                                    }}</span>
                                </div>
                                <Icon
                                    name="ExternalLink"
                                    :size="12"
                                    class="minichat-attachment-tail"
                                />
                            </button>
                        </template>
                    </div>

                    <div v-if="audioFiles.length" class="space-y-1">
                        <div
                            v-for="att in audioFiles"
                            :key="att.id"
                            class="minichat-audio-file"
                        >
                            <div class="minichat-audio-meta">
                                <span class="minichat-file-name" :title="getAudioLabel(att)">{{
                                    getAudioLabel(att)
                                }}</span>
                                <span class="minichat-file-size">{{
                                    formatFileSize(att.size)
                                }}</span>
                            </div>
                            <AudioMessagePlayer
                                :src="att.url"
                                :is-mine="isMine"
                                :duration-seconds="att.duration_seconds"
                                compact
                            />
                        </div>
                    </div>

                    <!-- File List -->
                    <div v-if="files.length" class="space-y-1">
                        <a
                            v-for="att in files"
                            :key="att.id"
                            :href="att.url"
                            target="_blank"
                            class="minichat-attachment-file group/file"
                        >
                            <span class="minichat-attachment-icon">
                                <Icon name="FileText" :size="14" />
                            </span>
                            <div class="minichat-file-meta">
                                <span class="minichat-file-name" :title="att.name">{{
                                    att.name
                                }}</span>
                                <span class="minichat-file-size">{{
                                    formatFileSize(att.size)
                                }}</span>
                            </div>
                            <Icon
                                name="Download"
                                :size="12"
                                class="minichat-attachment-tail"
                            />
                        </a>
                    </div>
                </div>

                <!-- GIF Display -->
                <div
                    v-if="!isDeleted && giphy"
                    class="relative mb-1.5 overflow-hidden rounded-lg bg-black/5 dark:bg-black/20 flex justify-center gif-wrapper"
                >
                    <div
                        v-if="!isGiphyLoaded"
                        class="minichat-thumb-skeleton minichat-thumb-shimmer"
                    />
                    <img
                        :src="giphy.url"
                        :alt="giphy.title"
                        :width="giphy.width"
                        :height="giphy.height"
                        loading="lazy"
                        decoding="async"
                        class="max-w-full h-auto object-contain rounded-lg cursor-pointer hover:opacity-90 transition-opacity duration-300"
                        :class="isGiphyLoaded ? 'opacity-100' : 'opacity-0'"
                        style="max-height: 250px"
                        @load="isGiphyLoaded = true"
                        @error="isGiphyLoaded = true"
                    />
                </div>

                <p
                    v-if="isDeleted"
                    class="minichat-message-content italic opacity-85"
                >
                    Message deleted
                </p>
                <p v-else-if="message.content" class="minichat-message-content">
                    {{ message.content }}
                </p>

                <!-- Link Preview -->
                <div v-if="firstUrl" class="mt-2">
                    <LinkPreview :url="firstUrl" />
                </div>

                <div class="flex items-center justify-end gap-1 mt-1">
                    <span
                        v-if="isEdited && !isDeleted"
                        class="minichat-message-time opacity-80 cursor-pointer hover:underline"
                        :class="{ 'underline': showHistory }"
                        :title="editHistory.length ? 'Show edit history' : undefined"
                        @click.stop="toggleHistory"
                    >
                        Edited
                    </span>
                    <span class="minichat-message-time">{{
                        formatTime(message.created_at)
                    }}</span>

                    <!-- Status Indicators (Own Message Only) -->
                    <div v-if="isMine" class="flex items-center">
                        <Icon
                            v-if="message.pending"
                            name="Loader2"
                            :size="10"
                            class="animate-spin text-(--text-muted)"
                        />
                        <Icon
                            v-else-if="message.failed"
                            name="AlertCircle"
                            :size="10"
                            class="text-red-300"
                        />
                        <Icon
                            v-else-if="message.is_seen"
                            name="CheckCheck"
                            :size="10"
                            class="text-white/90"
                        />
                        <Icon
                            v-else
                            name="Check"
                            :size="10"
                            class="text-white/70"
                        />
                    </div>
                </div>
            </div>

            <div
                v-if="visibleReactions.length"
                class="minichat-reactions-row"
                :class="{ 'is-own': isMine }"
            >
                <button
                    v-for="reaction in visibleReactions"
                    :key="`mini-reaction-${message.id}-${reaction.key}`"
                    type="button"
                    class="minichat-reaction-chip"
                    :class="{ 'is-active': reaction.active }"
                    @click="selectReaction(reaction.key)"
                >
                    <span>{{ reaction.emoji }}</span>
                    <span>{{ reaction.count }}</span>
                </button>
            </div>

            <div
                v-if="showHistory && editHistory.length"
                class="mt-1 w-[13.5rem] max-w-[68vw] rounded-lg border border-(--border-default) bg-(--surface-elevated) p-1.5 text-[10px] shadow-lg"
                :class="isMine ? 'self-end' : 'self-start'"
            >
                <div class="mb-1 font-semibold text-(--text-secondary)">
                    Edit history
                </div>
                <div class="space-y-1 max-h-32 overflow-y-auto pr-0.5">
                    <div
                        v-for="(entry, index) in editHistory"
                        :key="`mini-history-${message.id}-${index}`"
                        class="rounded bg-(--surface-tertiary) px-1.5 py-1"
                    >
                        <div class="text-(--text-primary) break-all">
                            {{ entry.previous_content || "(empty)" }}
                        </div>
                        <div class="text-(--text-tertiary) mt-0.5">
                            {{ entry.edited_by_user_name || "Unknown" }}
                            ·
                            {{
                                entry.edited_at
                                    ? new Date(entry.edited_at).toLocaleString()
                                    : ""
                            }}
                        </div>
                    </div>
                </div>
            </div>

            <div
                ref="actionMenuRef"
                class="absolute top-1/2 z-20 -translate-y-1/2"
                :class="isMine ? 'right-full pr-1.5' : 'left-full pl-1.5'"
                @mouseenter="keepActionsVisible"
                @mouseleave="scheduleHideActions"
            >
                <div
                    class="minichat-message-actions"
                    :class="{
                        'is-visible': showReactionMenu || showMoreMenu || isActionHovering,
                        'is-own': isMine,
                    }"
                >
                    <div
                        class="flex items-center gap-0.5 rounded-full border border-(--border-default) bg-(--surface-elevated)/95 px-1 py-1 text-(--text-primary) shadow-xl backdrop-blur-md"
                    >
                        <button
                            type="button"
                            class="inline-flex h-6 w-6 items-center justify-center rounded-full text-(--text-secondary) transition-colors hover:bg-(--surface-tertiary) hover:text-(--text-primary)"
                            title="More"
                            @click.stop="toggleMoreMenu"
                        >
                            <Icon name="MoreVertical" :size="11" />
                        </button>
                        <button
                            v-if="!message.failed && !isDeleted"
                            type="button"
                            class="inline-flex h-6 w-6 items-center justify-center rounded-full text-(--text-secondary) transition-colors hover:bg-(--surface-tertiary) hover:text-(--text-primary)"
                            title="Reply"
                            @click="emit('reply', message)"
                        >
                            <Icon name="CornerUpLeft" :size="11" />
                        </button>
                        <button
                            v-if="!message.failed && !isDeleted"
                            type="button"
                            class="inline-flex h-6 w-6 items-center justify-center rounded-full text-(--text-secondary) transition-colors hover:bg-(--surface-tertiary) hover:text-(--text-primary)"
                            title="React"
                            @click.stop="toggleReactionMenu"
                        >
                            <Icon name="Smile" :size="11" />
                        </button>
                    </div>
                </div>

                <div
                    v-if="showReactionMenu"
                    class="absolute top-1/2 z-20 flex -translate-y-1/2 items-center gap-0.5 rounded-full border border-(--border-default) bg-(--surface-elevated)/95 px-1 py-1 shadow-xl backdrop-blur-md"
                    :class="isMine ? 'left-full ml-1.5' : 'right-full mr-1.5'"
                >
                    <button
                        v-for="option in quickReactionOptions"
                        :key="`mini-reaction-option-${message.id}-${option.key}`"
                        type="button"
                        class="inline-flex h-6 w-6 items-center justify-center rounded-full text-[18px] leading-none transition-colors hover:bg-(--surface-tertiary)"
                        :title="option.label"
                        @click="selectReaction(option.key)"
                    >
                        {{ option.emoji }}
                    </button>
                </div>

                <div
                    v-if="showMoreMenu"
                    class="absolute top-1/2 z-20 min-w-[9.75rem] -translate-y-1/2 rounded-xl border border-(--border-default) bg-(--surface-elevated) p-1 shadow-xl"
                    :class="isMine ? 'left-full ml-1.5' : 'right-full mr-1.5'"
                >
                    <button
                        v-if="!isDeleted"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-2.5 py-1.5 text-left text-[10px] font-medium text-(--text-primary) hover:bg-(--surface-tertiary)"
                        @click="emit('togglePin'); closeMenus()"
                    >
                        <span>{{ isPinned ? "Unpin" : "Pin" }}</span>
                        <Icon :name="isPinned ? 'PinOff' : 'Pin'" :size="11" />
                    </button>
                    <button
                        v-if="canEdit"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-2.5 py-1.5 text-left text-[10px] font-medium text-(--text-primary) hover:bg-(--surface-tertiary)"
                        @click="startEdit"
                    >
                        <span>Edit</span>
                        <Icon name="Pencil" :size="11" />
                    </button>
                    <button
                        v-if="canHideForMe"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-2.5 py-1.5 text-left text-[10px] font-medium text-(--text-primary) hover:bg-(--surface-tertiary)"
                        @click="emit('delete', 'me'); closeMenus()"
                    >
                        <span>Unsend for me</span>
                        <Icon name="EyeOff" :size="11" />
                    </button>
                    <button
                        v-if="canDeleteForAll"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-2.5 py-1.5 text-left text-[10px] font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                        @click="emit('delete', 'all'); closeMenus()"
                    >
                        <span>Delete</span>
                        <Icon name="Trash2" :size="11" />
                    </button>
                </div>

            </div>

            <!-- Retry Button (Failed Only) -->
            <button
                v-if="message.failed && isMine"
                class="absolute -left-8 top-1/2 -translate-y-1/2 p-1.5 rounded-full bg-red-100 hover:bg-red-200 text-red-600 transition-colors shadow-sm"
                title="Retry Send"
                @click.stop="emit('retry', message.id)"
            >
                <Icon name="RefreshCw" :size="12" />
            </button>
        </div>
    </div>
</template>

<style scoped>
/* Copied and adapted styles from MiniChatWindow.vue */
.minichat-message {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    max-width: 88%;
}

.minichat-message.is-own {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.minichat-message-avatar {
    flex-shrink: 0;
    margin-top: 1px;
}

.minichat-message-wrapper {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 2px;
    width: fit-content;
    max-width: calc(100% - 28px);
}

.minichat-message.is-own .minichat-message-wrapper {
    max-width: 100%;
}

.minichat-reply-ref {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    color: var(--text-muted);
    padding: 4px 8px;
    background: var(--surface-tertiary);
    border-radius: 8px 8px 0 0;
    border-left: 2px solid var(--interactive-primary);
}

.minichat-message-bubble {
    padding: 9px 12px;
    border-radius: 14px;
    background: var(--surface-tertiary);
    border: 1px solid var(--border-default);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    position: relative;
    min-width: 0; /* Enable truncation inside flex items */
}

.minichat-message.is-own .minichat-message-bubble {
    background: var(--interactive-primary);
    border-color: color-mix(
        in srgb,
        var(--interactive-primary) 75%,
        white 25%
    );
    color: white !important;
}

.minichat-message.is-own .minichat-message-bubble * {
    color: white !important;
}

.minichat-message-content {
    font-size: 13px;
    line-height: 1.4;
    word-break: break-word; /* legacy */
    overflow-wrap: break-word; /* modern */
    margin: 0;
    white-space: pre-wrap;
}

.minichat-message-time {
    font-size: 10px;
    color: var(--text-muted);
    margin-top: 2px;
    display: block;
}

.minichat-message.is-own .minichat-message-time {
    color: rgba(255, 255, 255, 0.7);
}

.minichat-reactions-row {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 0;
}

.minichat-reactions-row.is-own {
    justify-content: flex-end;
}

.minichat-reaction-chip {
    border: 1px solid var(--border-default);
    background: var(--surface-secondary);
    color: var(--text-secondary);
    border-radius: 999px;
    padding: 2px 7px;
    font-size: 10px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.minichat-reaction-chip:hover {
    border-color: var(--interactive-primary);
}

.minichat-reaction-chip.is-active {
    color: var(--interactive-primary);
    border-color: color-mix(in srgb, var(--interactive-primary) 70%, white 30%);
    background: color-mix(in srgb, var(--interactive-primary) 16%, transparent);
}

.minichat-message-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 0;
    opacity: 0;
    max-height: 0;
    overflow: hidden;
    pointer-events: none;
    transform: translateY(-2px);
    transition:
        opacity 0.15s ease,
        max-height 0.2s ease,
        transform 0.2s ease;
}

.minichat-message-actions.is-own {
    justify-content: flex-end;
}

.minichat-message-actions.is-visible {
    opacity: 1;
    max-height: 48px;
    pointer-events: auto;
    transform: translateY(0);
}

.minichat-inline-action {
    border: none;
    background: transparent;
    color: var(--text-secondary);
    font-size: 11px;
    cursor: pointer;
    padding: 0;
    line-height: 1;
    transition: color 0.15s ease;
}

.minichat-inline-action:hover {
    color: var(--interactive-primary);
}

.minichat-reaction-menu {
    margin-top: 4px;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 7px;
    border-radius: 10px;
    border: 1px solid var(--border-default);
    background: var(--surface-elevated);
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
    max-width: 220px;
}

.minichat-reaction-menu.is-own {
    align-self: flex-end;
    justify-content: flex-end;
}

.minichat-reaction-option {
    border: 1px solid var(--border-default);
    background: var(--surface-tertiary);
    color: var(--text-primary);
    border-radius: 8px;
    padding: 2px 6px;
    font-size: 10px;
    cursor: pointer;
}

.minichat-reaction-option:hover {
    background: var(--surface-secondary);
}

@media (hover: hover) and (pointer: fine) {
    .minichat-message-wrapper:hover .minichat-message-actions {
        opacity: 1;
        max-height: 48px;
        pointer-events: auto;
        transform: translateY(0);
    }
}

@media (hover: none), (pointer: coarse) {
    .minichat-message-actions {
        opacity: 1;
        max-height: 48px;
        pointer-events: auto;
        transform: translateY(0);
    }
}

/* File Styles */
.minichat-attachment-file {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    background: var(--surface-secondary);
    border: 1px solid var(--border-default);
    border-radius: 10px;
    font-size: 11px;
    color: var(--text-primary);
    text-decoration: none;
    transition:
        background-color 0.15s ease,
        border-color 0.15s ease;
}

/* Ensure file text is readable when inside own bubble (which is primary color) */
.minichat-message.is-own .minichat-attachment-file {
    background: rgba(0, 0, 0, 0.18);
    border-color: rgba(255, 255, 255, 0.24);
    color: rgba(255, 255, 255, 0.96);
}

.minichat-attachment-file:hover {
    background: var(--surface-tertiary);
    border-color: color-mix(in srgb, var(--interactive-primary) 45%, var(--border-default));
}
.minichat-message.is-own .minichat-attachment-file:hover {
    background: rgba(0, 0, 0, 0.28);
    border-color: rgba(255, 255, 255, 0.35);
}

.minichat-attachment-icon {
    width: 24px;
    height: 24px;
    border-radius: 7px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: color-mix(in srgb, var(--surface-elevated) 70%, transparent);
}

.minichat-message.is-own .minichat-attachment-icon {
    background: rgba(255, 255, 255, 0.18);
}

.minichat-file-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    gap: 1px;
    flex: 1 1 auto;
    min-width: 0;
}

.minichat-attachment-tail {
    flex-shrink: 0;
    opacity: 0.45;
    transition: opacity 0.15s ease;
}

.group\/file:hover .minichat-attachment-tail {
    opacity: 0.95;
}

.minichat-file-name {
    min-width: 0;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: block;
    font-size: 11px;
    font-weight: 600;
    line-height: 1.25;
}

.minichat-file-size {
    font-size: 10px;
    line-height: 1.2;
    opacity: 0.72;
    margin-left: 0;
    white-space: nowrap;
}

.minichat-audio-file {
    width: min(158px, 100%);
    max-width: 158px;
    box-sizing: border-box;
    background: var(--surface-secondary);
    border: 1px solid var(--border-default);
    border-radius: 9px;
    padding: 5px 7px;
}

.minichat-message.is-own .minichat-audio-file {
    background: rgba(0, 0, 0, 0.12);
    border-color: rgba(255, 255, 255, 0.2);
}

.minichat-audio-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    font-size: 10px;
    margin-bottom: 5px;
}

.minichat-audio-meta .minichat-file-name {
    flex: 1 1 auto;
    min-width: 0;
}

.minichat-audio-meta .minichat-file-size {
    flex-shrink: 0;
}

.minichat-attachments-block {
    width: 100%;
    min-width: 0;
}

.minichat-video-card {
    width: min(158px, 100%);
    max-width: 158px;
}

.minichat-video-file {
    width: min(158px, 100%);
    max-width: 158px;
}

.minichat-thumb-skeleton {
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: color-mix(in srgb, var(--surface-tertiary) 90%, transparent);
}

.minichat-thumb-shimmer::after {
    content: "";
    position: absolute;
    inset: 0;
    transform: translateX(-100%);
    background: linear-gradient(
        90deg,
        transparent,
        color-mix(in srgb, var(--surface-elevated) 45%, transparent),
        transparent
    );
    animation: miniMediaThumbShimmer 1.15s linear infinite;
}

@keyframes miniMediaThumbShimmer {
    100% {
        transform: translateX(100%);
    }
}

</style>
