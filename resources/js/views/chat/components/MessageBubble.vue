<script setup lang="ts">
import { computed } from 'vue';
import type { Message, MessageAttachment } from '@/types/models/chat';
import { Icon } from '@/components/ui';

interface Props {
  message: Message;
  isMine: boolean;
  showAvatar: boolean;
  showTime: boolean;
}

const props = defineProps<Props>();

// Helper to check if file is an image
const isImage = (mime: string) => mime && mime.startsWith('image/');

const formattedTime = computed(() => {
  return new Date(props.message.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
});

function prettySize(bytes: number) {
    if (bytes < 1024) return bytes + ' B';
    const k = 1024;
    const sizes = ['KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

// Check if message is seen
function isSeen(msg: Message): boolean {
    return Boolean(msg?.seen_at || msg?.seen || msg?.is_seen);
}

// Layout Logic for Attachments
const images = computed<MessageAttachment[]>(() => props.message.attachments?.filter(a => isImage(a.mime_type)) || []);
const files = computed<MessageAttachment[]>(() => props.message.attachments?.filter(a => !isImage(a.mime_type)) || []);

// Limit displayed images to 4 for the grid (3 + 1 overlay)
const displayImages = computed(() => images.value.slice(0, 4));

const gridClass = computed(() => {
    const count = images.value.length;
    if (count === 1) return 'grid-cols-1 max-w-sm';
    if (count === 2) return 'grid-cols-2 max-w-md';
    if (count >= 3) return 'grid-cols-2 max-w-md'; // 3 and 4+ use 2x2 grid logic (with spans)
    return '';
});

function getImageClass(index: number, total: number) {
    // 3 Images: First one spans 2 rows
    if (total === 3) {
        if (index === 0) return 'col-span-2 aspect-[2/1]';
        return 'aspect-square';
    }
    return 'aspect-square'; 
}

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
</script>

<template>
  <div class="v3-msg" :class="{ mine: isMine }">
    <div class="v3-msg__row">
      <!-- Avatar (only show for others) -->
      <div v-if="!isMine && showAvatar" class="v3-msg__ava">
        <img 
          v-if="message.user_avatar" 
          :src="message.user_avatar" 
          :alt="message.user_name"
          class="v3-msg__ava-img"
          @error="($event.target as HTMLImageElement).style.display = 'none'"
        />
        <span v-else class="v3-msg__ava-fallback">{{ message.user_name?.charAt(0) || '?' }}</span>
      </div>
      <div v-else-if="!isMine" style="width: 32px"></div>

      <div class="v3-bubble">
        <!-- Sender Name (Group Chat) -->
        <div v-if="!isMine && showAvatar" class="v3-bubble__meta">
            <span class="v3-bubble__who">{{ message.user_name }}</span>
        </div>

        <div class="v3-bubble__body">
            <!-- Reply Context -->
            <div v-if="message.reply_to" class="v3-bubble__reply">
                <div class="v3-subtle" style="font-size: 11px; margin-bottom: 4px; border-left: 2px solid var(--text); padding-left: 6px;">
                    Replying to {{ message.reply_to.user_name || 'unknown' }}...
                </div>
            </div>

            <!-- Attachments -->
            <div v-if="images.length || files.length" class="v3-bubble__attachments space-y-2">
                
                <!-- Image Grid -->
                <!-- Use w-full for grid container to respect max-w from class -->
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
                    <div v-for="file in files" :key="file.id" class="v3-bubble__file group flex items-center gap-3 p-3 rounded-lg bg-[var(--surface-tertiary)] hover:bg-[var(--surface-secondary)] border border-[var(--border-default)] transition-colors">
                        <div class="p-2 rounded bg-[var(--surface-elevated)] text-[var(--interactive-primary)]">
                            <Icon name="FileText" :size="20" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <a :href="file.url" target="_blank" class="block font-medium text-sm text-[var(--text-primary)] hover:underline truncate">
                                {{ file.name }}
                            </a>
                            <div class="text-xs text-[var(--text-tertiary)]">{{ prettySize(file.size) }}</div>
                        </div>
                        <a :href="file.url" download class="opacity-0 group-hover:opacity-100 p-2 text-[var(--text-secondary)] hover:text-[var(--interactive-primary)] transition-all">
                            <Icon name="Download" :size="16" />
                        </a>
                    </div>
                </div>
            </div>

            <!-- Text Content -->
            <div v-if="message.content" class="v3-bubble__txt" style="white-space: pre-wrap;">{{ message.content }}</div>

            <!-- Footer -->
             <div class="v3-bubble__footer">
                <span class="v3-bubble__time">{{ formattedTime }}</span>
                <span v-if="isMine && message.pending" class="v3-bubble__status v3-bubble__pending">Sending...</span>
                <span v-else-if="isMine && isSeen(message)" class="v3-bubble__status v3-bubble__seen">Seen</span>
             </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.v3-bubble__reply { opacity: 0.8; margin-bottom: 6px; }

/* Avatar image styles */
.v3-msg__ava-img {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
}
.v3-msg__ava-fallback {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--brand, #3b82f6);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
}

/* Seen indicator styles */
.v3-bubble__status {
    margin-left: 4px;
    font-size: 12px;
}
.v3-bubble__seen {
    color: var(--brand, #3b82f6);
}
.v3-bubble__sent {
    opacity: 0.6;
}
.v3-bubble__pending {
    opacity: 0.4;
}
</style>
