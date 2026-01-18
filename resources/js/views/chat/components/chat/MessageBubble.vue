<script setup lang="ts">
import { computed } from "vue";
import type { Message, MessageAttachment } from "@/types/models/chat";
import { Icon } from "@/components/ui";
import LinkPreview from "@/components/LinkPreview.vue";

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
    retry: [message: Message]; // Add retry event
}>();

const formattedTime = computed(() => {
    return new Date(props.message.created_at).toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
    });
});

// Check if message is seen (legacy compatibility)
const isSeen = computed(() => {
    return Boolean(
        props.message.seen_at || props.message.seen || props.message.is_seen
    );
});

const avatarInitial = computed(() => {
    return props.message.user_name?.charAt(0)?.toUpperCase() || "?";
});

const isImage = (mime: string) => mime && mime.startsWith("image/");

const files = computed<MessageAttachment[]>(() => props.message.attachments?.filter(a => !isImage(a.mime_type)) || []);
const images = computed<MessageAttachment[]>(() => props.message.attachments?.filter(a => isImage(a.mime_type)) || []);
const giphy = computed(() => props.message.metadata?.giphy);

const displayImages = computed(() => images.value.slice(0, 4));

const gridClass = computed(() => {
    const count = images.value.length;
    if (count === 1) return 'grid-cols-1 max-w-sm';
    if (count === 2) return 'grid-cols-2 max-w-md';
    if (count >= 3) return 'grid-cols-2 max-w-md'; 
    return '';
});

function getImageClass(index: number, total: number) {
    if (total === 3) {
        if (index === 0) return 'col-span-2 aspect-[2/1]';
        return 'aspect-square';
    }
    return 'aspect-square'; 
}

const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return bytes + " B";
    const k = 1024;
    const sizes = ["KB", "MB", "GB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + " " + sizes[i];
};

function handleImageClick(img: MessageAttachment) {
    const allImages = images.value;
    const mediaForViewer = allImages.map(i => ({
        src: i.url,
        download: i.download_url || i.url,
        id: i.id,
        type: 'image',
        mimeType: i.mime_type
    }));

    const index = mediaForViewer.findIndex(m => m.id === img.id);

    window.dispatchEvent(new CustomEvent('media-viewer:open', {
        detail: {
            media: mediaForViewer,
            index: index >= 0 ? index : 0
        }
    }));
}

const handleJumpToReply = () => {
    if (props.message.reply_to?.id) {
        emit("jumpToReply", String(props.message.reply_to.id));
    }
};

const firstUrl = computed(() => {
    if (!props.message.content) return null;
    const match = props.message.content.match(/(https?:\/\/[^\s]+)/);
    return match ? match[0] : null;
});
</script>

<template>
    <!-- System Message -->
    <div v-if="message.type === 'system'" class="flex justify-center py-2 px-4 my-1">
        <div class="flex items-center gap-2 text-xs text-[var(--text-tertiary)] bg-[var(--surface-tertiary)]/50 border border-[var(--border-default)] px-3 py-1 rounded-full shadow-sm">
            <Icon name="Info" :size="12" class="opacity-70" />
            <span>{{ message.content }}</span>
        </div>
    </div>

    <!-- User Message -->
    <div
        v-else
        class="group flex gap-3 px-2 py-1"
        :class="isMine ? 'flex-row-reverse' : 'flex-row'"
    >
        <!-- Avatar -->
        <div v-if="showAvatar && !isMine" class="shrink-0 self-end">
            <div
                v-if="message.user_avatar"
                class="w-8 h-8 rounded-xl bg-cover bg-center"
                :style="{ backgroundImage: `url(${message.user_avatar})` }"
            />
            <div
                v-else
                class="w-8 h-8 rounded-xl bg-[var(--interactive-primary)] flex items-center justify-center text-white text-sm font-semibold"
            >
                {{ avatarInitial }}
            </div>
        </div>
        <div v-else-if="!isMine" class="w-8 shrink-0" />

        <!-- Bubble -->
        <div
            class="flex flex-col gap-1 max-w-[75%]"
            :class="isMine ? 'items-end' : 'items-start'"
        >
            <!-- Sender Name (if not mine) -->
            <div
                v-if="!isMine && showAvatar"
                class="text-xs font-medium text-[var(--text-secondary)] ml-1"
            >
                {{ message.user_name }}
            </div>

            <!-- Reply Context -->
            <button
                v-if="message.reply_to"
                class="text-xs py-1.5 px-3 rounded-lg bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border-l-2 border-[var(--interactive-primary)] cursor-pointer hover:bg-[var(--surface-secondary)] transition-colors text-left"
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
            <div
                class="relative px-3.5 py-2.5 rounded-2xl shadow-sm"
                :class="[
                    isMine
                        ? 'bg-[var(--interactive-primary)] text-white rounded-br-sm'
                        : 'bg-[var(--surface-elevated)] text-[var(--text-primary)] border border-[var(--border-default)] rounded-bl-sm',
                ]"
            >
                <!-- Attachments -->
                <div
                    v-if="images.length || files.length"
                    class="space-y-2 mb-2"
                >
                     <!-- Image Grid -->
                    <div v-if="images.length" class="grid gap-1 overflow-hidden rounded-xl w-full" :class="gridClass">
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
                                class="absolute inset-0 bg-black/50 hover:bg-black/60 transition-colors flex items-center justify-center cursor-pointer text-white font-bold text-lg backdrop-blur-sm"
                                @click="handleImageClick(img)"
                            >
                                +{{ images.length - 3 }}
                            </div>
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
                                    : 'text-[var(--text-primary)]'
                            "
                        >
                            <div class="p-1.5 rounded bg-white/20 text-current">
                                <Icon name="FileText" :size="16" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <span class="block truncate font-medium text-xs">{{ att.name }}</span>
                                <span class="block text-[10px] opacity-70">{{ formatFileSize(att.size) }}</span>
                            </div>
                            <Icon name="Download" :size="14" class="opacity-0 group-hover/file:opacity-100 transition-opacity" />
                        </a>
                    </div>
                </div>

                <!-- GIF Display -->
                <div v-if="giphy" class="mb-2 overflow-hidden rounded-xl bg-black/5 dark:bg-black/20 flex justify-center gif-wrapper">
                    <img 
                        :src="giphy.url" 
                        :alt="giphy.title"
                        :width="giphy.width"
                        :height="giphy.height"
                        class="max-w-full h-auto object-contain rounded-lg cursor-pointer hover:opacity-90 transition-opacity"
                        style="max-height: 400px;"
                    />
                </div>

                <!-- Text Content -->
                <p
                    v-if="message.content"
                    class="whitespace-pre-wrap break-words break-all text-sm leading-relaxed"
                    :class="isMine ? '!text-white' : '!text-[var(--text-primary)]'"
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
                            : 'justify-end text-[var(--text-tertiary)]',
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
                        <span v-else-if="message.pending" class="opacity-60"
                            >⏳</span
                        >
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

                <!-- Reply Button (shown on hover) -->
                <button
                    class="absolute -top-2 opacity-0 group-hover:opacity-100 transition-opacity bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg px-2 py-1 text-xs text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] shadow-sm"
                    :class="
                        isMine
                            ? 'left-0 -translate-x-1/2'
                            : 'right-0 translate-x-1/2'
                    "
                    @click="emit('reply')"
                    title="Reply"
                >
                    <Icon name="Reply" size="14" />
                </button>
            </div>
        </div>
    </div>
</template>
