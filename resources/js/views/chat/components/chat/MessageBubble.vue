<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import type { Message, MessageAttachment } from "@/types/models/chat";
import { Icon } from "@/components/ui";
import LinkPreview from "@/components/LinkPreview.vue";
import AudioMessagePlayer from "@/components/chat/AudioMessagePlayer.vue";
import { useVideoCallStore } from "@/stores/videocall";
import { useAuthStore } from "@/stores/auth";
import { useChatStore } from "@/stores/chat";

interface Props {
    message: Message;
    isMine: boolean;
    showAvatar?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showAvatar: true,
});

const emit = defineEmits<{
    reply: [];
    jumpToReply: [messageId: string];
    retry: [message: Message];
    react: [reaction: string];
    togglePin: [];
    startEdit: [];
    delete: [scope: "me" | "all"];
    'join-call': [payload: { chatId: string; callId: string; callType: 'video' | 'audio' }];
    callback: [payload: { chatId: string; callType: 'video' | 'audio' }];
}>();

const videoCallStore = useVideoCallStore();
const authStore = useAuthStore();
const chatStore = useChatStore();
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

const uploadProgress = computed(() => {
    if (!props.message.tempId) return 0;
    return chatStore.uploadingProgress.get(props.message.tempId) || 0;
});

const formattedTime = computed(() => {
    return new Date(props.message.created_at).toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
    });
});

// Check if message is seen (legacy compatibility)
const isSeen = computed(() => {
    return Boolean(
        props.message.seen_at || props.message.seen || props.message.is_seen,
    );
});

const avatarInitial = computed(() => {
    return props.message.user_name?.charAt(0)?.toUpperCase() || "?";
});

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

const displayImages = computed(() => images.value.slice(0, 4));

const gridClass = computed(() => {
    const count = images.value.length;
    if (count === 1) return "grid-cols-1 max-w-sm";
    if (count === 2) return "grid-cols-2 max-w-md";
    if (count >= 3) return "grid-cols-2 max-w-md";
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

const handleJumpToReply = () => {
    if (props.message.reply_to?.id) {
        emit("jumpToReply", String(props.message.reply_to.id));
    }
};

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

    if (!source || typeof source !== "object") {
        return {};
    }

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
    <div
        v-if="message.type === 'system'"
        class="flex justify-center py-2 px-4 my-1"
    >
        <div
            class="flex items-center gap-2 text-xs text-(--text-tertiary) bg-(--surface-tertiary)/50 border border-(--border-default) px-3 py-1 rounded-full shadow-sm"
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
                    :size="12"
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
                        message.metadata.event === 'started' &&
                        message.chat_id &&
                        videoCallStore.activeCalls.has(message.chat_id)
                    "
                    class="ml-2 px-2 py-0.5 rounded-full bg-green-500 hover:bg-green-600 text-white text-[10px] font-medium transition-colors flex items-center gap-1 cursor-pointer"
                    @click="
                        emit('join-call', {
                            chatId: message.chat_id!,
                            callId: message.metadata.call_id,
                            callType: message.metadata.type,
                        })
                    "
                >
                    <Icon name="PhoneIncoming" :size="10" />
                    Join Call
                </button>

                <span v-else>
                    {{ message.content }}
                </span>
                <!-- Call Back button for missed calls -->
                <button
                    v-if="
                        !isCallerSideMissedEvent &&
                        (message.metadata.event === 'missed' ||
                            message.metadata.event === 'no_answer')
                    "
                    class="ml-2 px-2 py-0.5 rounded-full bg-green-500 hover:bg-green-600 text-white text-[10px] font-medium transition-colors flex items-center gap-1 cursor-pointer"
                    @click="
                        emit('callback', {
                            chatId: message.metadata.chat_id,
                            callType: message.metadata.type,
                        })
                    "
                >
                    <Icon name="PhoneOutgoing" :size="10" />
                    Call Back
                </button>
            </template>
            <template v-else>
                <Icon name="Info" :size="12" class="opacity-70" />
                <span>{{ message.content }}</span>
            </template>
        </div>
    </div>

    <!-- User Message -->
    <div
        v-else
        class="group flex gap-2 px-2 py-0.5"
        :class="isMine ? 'flex-row-reverse' : 'flex-row'"
        @mouseenter="keepActionsVisible"
        @mouseleave="scheduleHideActions"
    >
        <!-- Avatar -->
        <div v-if="showAvatar && !isMine" class="shrink-0 self-start mt-0.5">
            <div
                v-if="message.user_avatar"
                class="w-8 h-8 rounded-xl bg-cover bg-center"
                :style="{ backgroundImage: `url(${message.user_avatar})` }"
            />
            <div
                v-else
                class="w-8 h-8 rounded-xl bg-(--interactive-primary) flex items-center justify-center text-white text-sm font-semibold"
            >
                {{ avatarInitial }}
            </div>
        </div>
        <div v-else-if="!isMine" class="w-8 shrink-0" />

        <!-- Bubble -->
        <div
            class="relative flex flex-col gap-0.5 max-w-[75%]"
            :class="isMine ? 'items-end' : 'items-start'"
        >
            <!-- Sender Name (if not mine) -->
            <div
                v-if="!isMine && showAvatar"
                class="text-xs font-medium text-(--text-secondary) ml-1"
            >
                {{ message.user_name }}
            </div>

            <!-- Reply Context -->
            <button
                v-if="message.reply_to"
                class="text-xs py-1.5 px-3 rounded-lg bg-(--surface-tertiary) text-(--text-secondary) border-l-2 border-(--interactive-primary) cursor-pointer hover:bg-(--surface-secondary) transition-colors text-left"
                @click="handleJumpToReply"
            >
                <span class="font-medium">{{
                    message.reply_to.user_name
                }}</span>
                <span class="opacity-70 ml-1 line-clamp-1">
                    {{
                        message.reply_to.has_media && !message.reply_to.content
                            ? "📎 Attachment"
                            : message.reply_to.content
                    }}
                </span>
            </button>

            <!-- Message Body -->
            <button
                v-if="isEdited && !isDeleted"
                type="button"
                class="px-1 text-[11px] font-medium text-(--text-tertiary) hover:text-(--text-secondary) transition-colors"
                :class="{ 'underline': showHistory }"
                :title="editHistory.length ? 'Show edit history' : undefined"
                @click.stop="toggleHistory"
            >
                Edited
            </button>
            <div
                class="relative px-3.5 py-2.5 rounded-2xl shadow-sm"
                :class="[
                    isMine
                        ? 'bg-(--interactive-primary) text-white rounded-br-sm'
                        : 'bg-(--surface-elevated) text-(--text-primary) border border-(--border-default) rounded-bl-sm',
                ]"
            >
                <!-- Attachments -->
                <div
                    v-if="!isDeleted && (images.length || videos.length || audioFiles.length || files.length)"
                    class="space-y-2 mb-2"
                >
                    <!-- Image Grid -->
                    <div
                        v-if="images.length"
                        class="grid gap-1 overflow-hidden rounded-xl w-full"
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
                                class="attachment-thumb-skeleton attachment-thumb-shimmer"
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
                                class="absolute inset-0 bg-black/50 hover:bg-black/60 transition-colors flex items-center justify-center cursor-pointer text-white font-bold text-lg backdrop-blur-sm"
                                @click="handleImageClick(img)"
                            >
                                +{{ images.length - 3 }}
                            </div>
                        </div>
                    </div>

                    <div v-if="videos.length" class="space-y-2">
                        <template v-for="video in videos" :key="video.id">
                            <button
                                v-if="canUseVideoThumb(video)"
                                type="button"
                                class="group/video relative w-full overflow-hidden rounded-xl border text-left transition-all duration-200"
                                :class="
                                    isMine
                                        ? 'border-white/25 bg-white/10 hover:border-white/45'
                                        : 'border-(--border-default) bg-(--surface-secondary) hover:border-(--interactive-primary)/45'
                                "
                                @click="handleVideoClick(video)"
                            >
                                <div class="relative aspect-video w-full overflow-hidden">
                                    <div
                                        v-if="!isVideoThumbLoaded(video)"
                                        class="attachment-thumb-skeleton attachment-thumb-shimmer"
                                    />
                                    <img
                                        :src="video.thumb_url || undefined"
                                        :alt="video.name"
                                        loading="lazy"
                                        decoding="async"
                                        class="h-full w-full object-cover transition-[transform,opacity] duration-500 group-hover/video:scale-[1.03]"
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
                                        class="absolute inset-0 bg-gradient-to-t from-black/72 via-black/25 to-black/10"
                                    />
                                    <span
                                        class="absolute inset-0 flex items-center justify-center pointer-events-none"
                                    >
                                        <span
                                            class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-black/55 text-white ring-1 ring-white/30 shadow-lg"
                                        >
                                            <Icon name="Play" :size="16" />
                                        </span>
                                    </span>
                                    <span
                                        class="absolute left-2 top-2 inline-flex items-center rounded-full bg-black/45 px-2 py-0.5 text-[10px] font-medium tracking-wide text-white ring-1 ring-white/25"
                                    >
                                        VIDEO
                                    </span>
                                    <div
                                        class="absolute inset-x-2 bottom-2 flex items-center gap-2"
                                    >
                                        <div
                                            class="min-w-0 flex-1 rounded-md bg-black/45 px-2 py-1 ring-1 ring-white/15 backdrop-blur-[1px]"
                                        >
                                            <span
                                                class="block truncate text-xs font-semibold text-white drop-shadow"
                                            >
                                                {{ video.name }}
                                            </span>
                                            <span
                                                class="block text-[10px] text-white/85 drop-shadow"
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
                                class="flex w-full items-center gap-2 rounded-lg border p-2 text-left transition-colors"
                                :class="
                                    isMine
                                        ? 'bg-white/10 border-white/20 hover:bg-white/15'
                                        : 'bg-(--surface-secondary) border-(--border-default) hover:bg-(--surface-tertiary)'
                                "
                                @click="handleVideoClick(video)"
                            >
                                <div class="p-1.5 rounded bg-white/15 text-current">
                                    <Icon name="Play" :size="14" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="block truncate text-xs font-medium">
                                        {{ video.name }}
                                    </span>
                                    <span class="block text-[10px] opacity-70">
                                        {{ formatFileSize(video.size) }}
                                    </span>
                                </div>
                                <Icon
                                    name="ExternalLink"
                                    :size="13"
                                    class="opacity-70"
                                />
                            </button>
                        </template>
                    </div>

                    <!-- Audio Clips -->
                    <div v-if="audioFiles.length" class="space-y-2">
                        <div
                            v-for="att in audioFiles"
                            :key="att.id"
                            class="rounded-lg border p-2"
                            :class="
                                isMine
                                    ? 'bg-white/10 border-white/20'
                                    : 'bg-(--surface-secondary) border-(--border-default)'
                            "
                        >
                            <div
                                class="mb-1 flex items-center justify-between text-[11px]"
                            >
                                <span class="font-medium truncate">{{
                                    getAudioLabel(att)
                                }}</span>
                                <span class="opacity-70">{{
                                    formatFileSize(att.size)
                                }}</span>
                            </div>
                            <AudioMessagePlayer
                                :src="att.url"
                                :is-mine="isMine"
                                :duration-seconds="att.duration_seconds"
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
                            class="flex items-center gap-2 p-2 rounded-lg bg-black/10 hover:bg-black/20 transition-colors group/file"
                            :class="
                                isMine
                                    ? 'text-white/90'
                                    : 'text-(--text-primary)'
                            "
                        >
                            <div class="p-1.5 rounded bg-white/20 text-current">
                                <Icon name="FileText" :size="16" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <span
                                    class="block truncate font-medium text-xs"
                                    >{{ att.name }}</span
                                >
                                <span class="block text-[10px] opacity-70">{{
                                    formatFileSize(att.size)
                                }}</span>
                            </div>
                            <Icon
                                name="Download"
                                :size="14"
                                class="opacity-0 group-hover/file:opacity-100 transition-opacity"
                            />
                        </a>
                    </div>
                </div>

                <!-- GIF Display -->
                <div
                    v-if="!isDeleted && giphy"
                    class="relative mb-2 overflow-hidden rounded-xl bg-black/5 dark:bg-black/20 flex justify-center gif-wrapper"
                >
                    <div
                        v-if="!isGiphyLoaded"
                        class="attachment-thumb-skeleton attachment-thumb-shimmer"
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
                        style="max-height: 400px"
                        @load="isGiphyLoaded = true"
                        @error="isGiphyLoaded = true"
                    />
                </div>

                <p
                    v-if="isDeleted"
                    class="text-sm leading-relaxed italic opacity-85"
                    :class="isMine ? 'text-white/90' : 'text-(--text-tertiary)'"
                >
                    Message deleted
                </p>
                <p
                    v-else-if="message.content"
                    class="whitespace-pre-wrap wrap-break-word break-all text-sm leading-relaxed"
                    :class="isMine ? 'text-white!' : 'text-(--text-primary)!'"
                >
                    {{ message.content }}
                </p>

                <!-- Link Preview -->
                <div v-if="firstUrl" class="mt-2 text-left">
                    <LinkPreview :url="firstUrl" />
                </div>

                <!-- Footer: Time + Status -->
                <div
                    class="flex items-center gap-1.5 mt-1 text-[11px]"
                    :class="[
                        message.failed
                            ? 'text-red-500 font-medium'
                            : isMine
                              ? 'justify-end text-blue-100'
                              : 'justify-end text-(--text-tertiary)',
                    ]"
                    :title="new Date(message.created_at).toLocaleString()"
                >
                    <span>{{ formattedTime }}</span>
                    <template v-if="isMine">
                        <span
                            v-if="message.failed"
                            class="flex items-center gap-1 cursor-pointer hover:underline"
                            @click="emit('retry', message)"
                        >
                            <Icon name="AlertCircle" size="12" /> Failed • Retry
                            <Icon name="RefreshCw" size="10" />
                        </span>
                        <div v-else-if="message.pending" class="flex items-center gap-2">
                            <span v-if="uploadProgress > 0" class="text-[10px] font-medium opacity-80">
                                {{ uploadProgress }}%
                            </span>
                            <span v-else class="opacity-60">⏳</span>
                        </div>
                        <span
                            v-else-if="isSeen"
                            class="flex items-center text-blue-200"
                            title="Seen"
                        >
                            <Icon name="CheckCheck" size="14" />
                        </span>
                        <span
                            v-else
                            class="flex items-center text-blue-200/70"
                            title="Sent"
                        >
                            <Icon name="Check" size="14" />
                        </span>
                    </template>
                </div>
                
                <!-- Progress Bar -->
                <div v-if="message.pending && uploadProgress > 0" class="mt-2 w-full">
                    <div class="h-1 w-full overflow-hidden rounded-full bg-black/10 dark:bg-white/10">
                        <div 
                            class="h-full bg-white transition-all duration-300 ease-out shadow-[0_0_8px_rgba(255,255,255,0.5)]"
                            :style="{ width: `${uploadProgress}%` }"
                        />
                    </div>
                </div>
            </div>

            <div
                v-if="visibleReactions.length"
                class="mt-1 flex flex-wrap gap-1.5"
                :class="isMine ? 'justify-end' : 'justify-start'"
            >
                <button
                    v-for="reaction in visibleReactions"
                    :key="`reaction-${message.id}-${reaction.key}`"
                    type="button"
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] border transition-colors"
                    :class="
                        reaction.active
                            ? 'border-(--interactive-primary) bg-(--interactive-primary)/15 text-(--interactive-primary)'
                            : 'border-(--border-default) bg-(--surface-tertiary) text-(--text-secondary)'
                    "
                    @click="selectReaction(reaction.key)"
                >
                    <span>{{ reaction.emoji }}</span>
                    <span>{{ reaction.count }}</span>
                </button>
            </div>

            <div
                v-if="showHistory && editHistory.length"
                class="mt-1 w-[18rem] max-w-[75vw] rounded-xl border border-(--border-default) bg-(--surface-elevated) p-2 text-xs shadow-lg"
                :class="isMine ? 'self-end' : 'self-start'"
            >
                <div class="mb-1 font-semibold text-(--text-secondary)">
                    Edit history
                </div>
                <div class="space-y-1.5 max-h-44 overflow-y-auto pr-0.5">
                    <div
                        v-for="(entry, index) in editHistory"
                        :key="`history-${message.id}-${index}`"
                        class="rounded-md bg-(--surface-tertiary) px-2 py-1"
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
                :class="isMine ? 'right-full pr-2' : 'left-full pl-2'"
                @mouseenter="keepActionsVisible"
                @mouseleave="scheduleHideActions"
            >
                <div
                    class="flex flex-col items-center gap-1.5 transition-all duration-150"
                    :class="[
                        isMine ? 'items-start' : 'items-end',
                        showReactionMenu || showMoreMenu || isActionHovering
                            ? 'opacity-100 pointer-events-auto'
                            : 'opacity-0 pointer-events-none',
                    ]"
                >
                    <div
                        class="flex items-center gap-0.5 rounded-full border border-(--border-default) bg-(--surface-elevated)/95 px-1 py-1 text-(--text-primary) shadow-xl backdrop-blur-md"
                    >
                        <button
                            type="button"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-full text-(--text-secondary) transition-colors hover:bg-(--surface-tertiary) hover:text-(--text-primary)"
                            title="More"
                            @click.stop="toggleMoreMenu"
                        >
                            <Icon name="MoreVertical" size="13" />
                        </button>
                        <button
                            v-if="!isDeleted"
                            type="button"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-full text-(--text-secondary) transition-colors hover:bg-(--surface-tertiary) hover:text-(--text-primary)"
                            title="Reply"
                            @click="emit('reply')"
                        >
                            <Icon name="CornerUpLeft" size="13" />
                        </button>
                        <button
                            v-if="!isDeleted"
                            type="button"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-full text-(--text-secondary) transition-colors hover:bg-(--surface-tertiary) hover:text-(--text-primary)"
                            title="React"
                            @click.stop="toggleReactionMenu"
                        >
                            <Icon name="Smile" size="13" />
                        </button>
                    </div>
                </div>

                <div
                    v-if="showReactionMenu"
                    class="absolute top-1/2 z-20 flex -translate-y-1/2 items-center gap-0.5 rounded-full border border-(--border-default) bg-(--surface-elevated)/95 px-1 py-1 shadow-xl backdrop-blur-md"
                    :class="
                        isMine
                            ? 'left-full ml-2'
                            : 'right-full mr-2'
                    "
                >
                    <button
                        v-for="option in quickReactionOptions"
                        :key="`reaction-opt-${message.id}-${option.key}`"
                        type="button"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-full text-[20px] leading-none transition-colors hover:bg-(--surface-tertiary)"
                        :title="option.label"
                        @click="selectReaction(option.key)"
                    >
                        {{ option.emoji }}
                    </button>
                </div>

                <div
                    v-if="showMoreMenu"
                    class="absolute top-1/2 z-20 min-w-[11rem] -translate-y-1/2 rounded-2xl border border-(--border-default) bg-(--surface-elevated) p-1.5 shadow-xl"
                    :class="isMine ? 'left-full ml-2' : 'right-full mr-2'"
                >
                    <button
                        v-if="!isDeleted"
                        type="button"
                        class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-xs font-medium text-(--text-primary) hover:bg-(--surface-tertiary)"
                        @click="emit('togglePin'); closeMenus()"
                    >
                        <span>{{ isPinned ? "Unpin" : "Pin" }}</span>
                        <Icon :name="isPinned ? 'PinOff' : 'Pin'" size="13" />
                    </button>
                    <button
                        v-if="canEdit"
                        type="button"
                        class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-xs font-medium text-(--text-primary) hover:bg-(--surface-tertiary)"
                        @click="startEdit"
                    >
                        <span>Edit</span>
                        <Icon name="Pencil" size="13" />
                    </button>
                    <button
                        v-if="canHideForMe"
                        type="button"
                        class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-xs font-medium text-(--text-primary) hover:bg-(--surface-tertiary)"
                        @click="emit('delete', 'me'); closeMenus()"
                    >
                        <span>Unsend for me</span>
                        <Icon name="EyeOff" size="13" />
                    </button>
                    <button
                        v-if="canDeleteForAll"
                        type="button"
                        class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-xs font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                        @click="emit('delete', 'all'); closeMenus()"
                    >
                        <span>Delete</span>
                        <Icon name="Trash2" size="13" />
                    </button>
                </div>

            </div>
        </div>
    </div>
</template>

<style scoped>
.attachment-thumb-skeleton {
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: color-mix(in srgb, var(--surface-tertiary) 90%, transparent);
}

.attachment-thumb-shimmer::after {
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
    animation: mediaThumbShimmer 1.15s linear infinite;
}

@keyframes mediaThumbShimmer {
    100% {
        transform: translateX(100%);
    }
}
</style>
