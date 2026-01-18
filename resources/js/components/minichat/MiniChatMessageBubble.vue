<script setup lang="ts">
import { computed } from "vue";
import type { Message, MessageAttachment } from "@/types/models/chat";
import { Icon, Avatar } from "@/components/ui";
import LinkPreview from "@/components/LinkPreview.vue";

interface Props {
    message: Message;
    isMine: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    reply: [message: Message];
    jump: [messageId: string];
    retry: [messageId: string];
}>();

const formatTime = (dateStr: string): string => {
    return new Date(dateStr).toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
    });
};

const isImage = (mime: string) => mime && mime.startsWith("image/");

const files = computed<MessageAttachment[]>(() => props.message.attachments?.filter(a => !isImage(a.mime_type)) || []);
const images = computed<MessageAttachment[]>(() => props.message.attachments?.filter(a => isImage(a.mime_type)) || []);
const giphy = computed(() => props.message.metadata?.giphy);

// Limit to 4 images for grid
const displayImages = computed(() => images.value.slice(0, 4));

const gridClass = computed(() => {
    const count = images.value.length;
    if (count === 1) return 'grid-cols-1 max-w-[200px]'; // Smaller max-width for mini chat
    if (count === 2) return 'grid-cols-2 max-w-[220px]';
    if (count >= 3) return 'grid-cols-2 max-w-[220px]'; 
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


const firstUrl = computed(() => {
    if (!props.message.content) return null;
    const match = props.message.content.match(/(https?:\/\/[^\s]+)/);
    return match ? match[0] : null;
});
</script>

<template>
    <!-- System Message -->
    <div v-if="message.type === 'system'" class="flex justify-center my-2 px-4">
        <span class="text-xs text-[var(--text-tertiary)] text-center italic leading-tight">
            {{ message.content }}
        </span>
    </div>

    <!-- User Message -->
    <div v-else class="minichat-message" :class="{ 'is-own': isMine }">
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
                <span>{{ message.reply_to.user_name }}: {{ message.reply_to.content?.slice(0, 30) }}...</span>
            </div>

            <div class="minichat-message-bubble">
                <!-- Attachments -->
                <div v-if="images.length || files.length" class="space-y-1.5 mb-1.5">
                    
                    <!-- Image Grid -->
                    <div v-if="images.length" class="grid gap-0.5 overflow-hidden rounded-lg w-full" :class="gridClass">
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

                    <!-- File List -->
                    <div v-if="files.length" class="space-y-1">
                        <a
                            v-for="att in files"
                            :key="att.id"
                            :href="att.url"
                            target="_blank"
                            class="minichat-attachment-file group/file"
                        >
                            <Icon name="FileText" :size="14" />
                            <div class="flex-1 min-w-0">
                                <span class="minichat-file-name">{{ att.name }}</span>
                                <span class="minichat-file-size">({{ formatFileSize(att.size) }})</span>
                            </div>
                            <Icon name="Download" :size="12" class="opacity-0 group-hover/file:opacity-100 transition-opacity" />
                        </a>
                    </div>
                </div>

                <!-- GIF Display -->
                <div v-if="giphy" class="mb-1.5 overflow-hidden rounded-lg bg-black/5 dark:bg-black/20 flex justify-center gif-wrapper">
                    <img 
                        :src="giphy.url" 
                        :alt="giphy.title"
                        :width="giphy.width"
                        :height="giphy.height"
                        class="max-w-full h-auto object-contain rounded-lg cursor-pointer hover:opacity-90 transition-opacity"
                        style="max-height: 250px;"
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
                    <span class="minichat-message-time">{{ formatTime(message.created_at) }}</span>
                    
                    <!-- Status Indicators (Own Message Only) -->
                    <div v-if="isMine" class="flex items-center">
                        <Icon v-if="message.pending" name="Loader2" :size="10" class="animate-spin text-[var(--text-muted)]" />
                        <Icon v-else-if="message.failed" name="AlertCircle" :size="10" class="text-red-300" />
                        <Icon v-else-if="message.is_seen" name="CheckCheck" :size="10" class="text-white/90" />
                        <Icon v-else name="Check" :size="10" class="text-white/70" />
                    </div>
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

            <!-- Reply button -->
            <button
                v-if="!message.failed"
                class="minichat-reply-btn"
                title="Reply"
                @click="emit('reply', message)"
            >
                <Icon name="Reply" :size="12" />
            </button>
        </div>
    </div>
</template>

<style scoped>
/* Copied and adapted styles from MiniChatWindow.vue */
.minichat-message {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    max-width: 85%;
}

.minichat-message.is-own {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.minichat-message-avatar {
    flex-shrink: 0;
}

.minichat-message-wrapper {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.minichat-message-wrapper:hover .minichat-reply-btn {
    opacity: 1;
}

.minichat-reply-btn {
    position: absolute;
    right: -24px;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: none;
    background: var(--surface-tertiary);
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: all 0.15s ease;
}

.minichat-message.is-own .minichat-reply-btn {
    right: auto;
    left: -24px;
}

.minichat-reply-btn:hover {
    background: var(--interactive-primary);
    color: white;
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
    padding: 8px 12px;
    border-radius: 14px;
    background: var(--surface-tertiary);
    position: relative;
    min-width: 0; /* Enable truncation inside flex items */
}

.minichat-message.is-own .minichat-message-bubble {
    background: var(--interactive-primary);
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
    margin-top: 4px;
    display: block;
}

.minichat-message.is-own .minichat-message-time {
    color: rgba(255, 255, 255, 0.7);
}

/* File Styles */
.minichat-attachment-file {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    background: var(--surface-secondary);
    border-radius: 8px;
    font-size: 11px;
    color: var(--text-secondary); /* Default text color */
    text-decoration: none;
    transition: background-color 0.15s ease;
}

/* Ensure file text is readable when inside own bubble (which is primary color) */
.minichat-message.is-own .minichat-attachment-file {
    background: rgba(0, 0, 0, 0.1); /* Darker on primary bg */
    color: rgba(255, 255, 255, 0.9);
}

.minichat-attachment-file:hover {
    background: var(--surface-tertiary);
}
.minichat-message.is-own .minichat-attachment-file:hover {
    background: rgba(0, 0, 0, 0.2);
}

.minichat-file-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: block;
}

.minichat-file-size {
    font-size: 9px;
    opacity: 0.7;
    margin-left: 4px;
}
</style>
