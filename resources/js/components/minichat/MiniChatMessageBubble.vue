<script setup lang="ts">
import { computed, ref } from "vue";
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
    callback: [data: { chatId: string; callType: "video" | "audio" }];
    "join-call": [
        data: { chatId: string; callId: string; callType: "video" | "audio" },
    ];
}>();

const authStore = useAuthStore();
const showReactionMenu = ref(false);

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

const canUseVideoThumb = (attachment: MessageAttachment) =>
    Boolean(attachment.thumb_url) &&
    !failedVideoThumbs.value.has(String(attachment.id));

const markVideoThumbFailed = (attachment: MessageAttachment) => {
    failedVideoThumbs.value.add(String(attachment.id));
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

const firstUrl = computed(() => {
    if (!props.message.content) return null;
    const match = props.message.content.match(/(https?:\/\/[^\s]+)/);
    return match ? match[0] : null;
});

const myPublicId = computed(() =>
    String(authStore.user?.public_id || "").trim().toLowerCase(),
);

const isPinned = computed(
    () => !!(props.message.is_pinned || props.message.metadata?.is_pinned),
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

const visibleReactions = computed(() =>
    REACTION_OPTIONS.map((option) => {
        const bucket = reactionBuckets.value[option.key] || [];
        return {
            ...option,
            count: bucket.length,
            active:
                myPublicId.value !== "" &&
                bucket.includes(myPublicId.value),
        };
    }).filter((item) => item.count > 0),
);

function toggleReactionMenu() {
    showReactionMenu.value = !showReactionMenu.value;
}

function selectReaction(reaction: string) {
    emit("react", reaction);
    showReactionMenu.value = false;
}
</script>

<template>
    <!-- System Message -->
    <div v-if="message.type === 'system'" class="flex justify-center my-2 px-4">
        <div
            class="flex items-center gap-1.5 text-[10px] text-(--text-tertiary) bg-(--surface-tertiary)/50 border border-(--border-default) px-2 py-1 rounded-full shadow-sm italic"
            :class="{
                'text-red-500! border-red-200! bg-red-50!':
                    message.metadata?.event === 'missed',
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
                    Missed {{ message.metadata.type }} call
                    <span v-if="message.metadata.caller_name">
                        from {{ message.metadata.caller_name }}</span
                    >
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
                        message.metadata.event === 'missed' ||
                        message.metadata.event === 'no_answer'
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
                    v-if="images.length || videos.length || audioFiles.length || files.length"
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
                            <img
                                :src="img.thumb_url || img.url"
                                class="w-full h-full object-cover cursor-pointer transition-transform duration-500 hover:scale-105"
                                @click="handleImageClick(img)"
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
                                class="group/video relative w-full overflow-hidden rounded-[10px] border text-left transition-all duration-200"
                                :class="
                                    isMine
                                        ? 'border-white/24 bg-black/16 hover:border-white/40'
                                        : 'border-(--border-default) bg-(--surface-secondary) hover:border-(--interactive-primary)/40'
                                "
                                @click="handleVideoClick(video)"
                            >
                                <div class="relative aspect-video w-full overflow-hidden">
                                    <img
                                        :src="video.thumb_url"
                                        :alt="video.name"
                                        loading="lazy"
                                        class="h-full w-full object-cover transition-transform duration-500 group-hover/video:scale-[1.04]"
                                        @error="markVideoThumbFailed(video)"
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
                                class="minichat-attachment-file group/file"
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
                    v-if="giphy"
                    class="mb-1.5 overflow-hidden rounded-lg bg-black/5 dark:bg-black/20 flex justify-center gif-wrapper"
                >
                    <img
                        :src="giphy.url"
                        :alt="giphy.title"
                        :width="giphy.width"
                        :height="giphy.height"
                        class="max-w-full h-auto object-contain rounded-lg cursor-pointer hover:opacity-90 transition-opacity"
                        style="max-height: 250px"
                    />
                </div>

                <!-- Text Content -->
                <p v-if="message.content" class="minichat-message-content">
                    {{ message.content }}
                </p>

                <!-- Link Preview -->
                <div v-if="firstUrl" class="mt-2">
                    <LinkPreview :url="firstUrl" />
                </div>

                <div class="flex items-center justify-end gap-1 mt-1">
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
                class="minichat-message-actions"
                :class="{ 'is-visible': showReactionMenu, 'is-own': isMine }"
            >
                <button
                    v-if="!message.failed"
                    type="button"
                    class="minichat-inline-action"
                    @click="emit('reply', message)"
                >
                    Reply
                </button>
                <button
                    v-if="!message.failed"
                    type="button"
                    class="minichat-inline-action"
                    @click.stop="toggleReactionMenu"
                >
                    React
                </button>
                <button
                    v-if="!message.failed"
                    type="button"
                    class="minichat-inline-action"
                    @click="emit('togglePin')"
                >
                    {{ isPinned ? "Unpin" : "Pin" }}
                </button>
            </div>

            <div
                v-if="showReactionMenu"
                class="minichat-reaction-menu"
                :class="{ 'is-own': isMine }"
            >
                <button
                    v-for="option in REACTION_OPTIONS"
                    :key="`mini-reaction-option-${message.id}-${option.key}`"
                    type="button"
                    class="minichat-reaction-option"
                    @click="selectReaction(option.key)"
                >
                    {{ option.emoji }} {{ option.label }}
                </button>
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
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    background: var(--surface-secondary);
    border: 1px solid var(--border-default);
    border-radius: 10px;
    padding: 8px 10px;
}

.minichat-message.is-own .minichat-audio-file {
    background: rgba(0, 0, 0, 0.12);
    border-color: rgba(255, 255, 255, 0.2);
}

.minichat-audio-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    font-size: 10px;
    margin-bottom: 7px;
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

</style>
