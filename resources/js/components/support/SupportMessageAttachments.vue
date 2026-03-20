<script setup>
import { computed } from 'vue';
import { FileText, Play } from 'lucide-vue-next';
import AudioMessagePlayer from '@/components/chat/AudioMessagePlayer.vue';

const props = defineProps({
    attachments: {
        type: Array,
        default: () => [],
    },
    tone: {
        type: String,
        default: 'theirs', // mine | theirs | note
        validator: (value) => ['mine', 'theirs', 'note'].includes(value),
    },
    compact: {
        type: Boolean,
        default: false,
    },
});

const allAttachments = computed(() => (
    Array.isArray(props.attachments)
        ? props.attachments.filter((attachment) => attachment && (attachment.url || attachment.download_url))
        : []
));

const isImage = (attachment) => {
    if (attachment?.media_kind) {
        return attachment.media_kind === 'image';
    }

    if (attachment?.is_image === true) {
        return true;
    }

    return String(attachment?.mime_type || '').toLowerCase().startsWith('image/');
};

const isAudio = (attachment) => {
    if (attachment?.media_kind) {
        return attachment.media_kind === 'audio';
    }

    if (attachment?.is_audio === true || attachment?.is_voice_clip === true) {
        return true;
    }

    const mime = String(attachment?.mime_type || '').toLowerCase();
    if (!mime) {
        return false;
    }

    if (mime.startsWith('audio/')) {
        return true;
    }

    if (mime.startsWith('video/')) {
        const name = String(attachment?.name || '').trim().toLowerCase();
        return (
            name.startsWith('voice-')
            || name.startsWith('audio-')
            || name.startsWith('recording-')
        );
    }

    return false;
};

const isVideo = (attachment) => {
    if (attachment?.media_kind) {
        return attachment.media_kind === 'video';
    }

    if (attachment?.is_video === true) {
        return true;
    }

    const mime = String(attachment?.mime_type || '').toLowerCase();
    return mime.startsWith('video/');
};

const images = computed(() => allAttachments.value.filter((attachment) => isImage(attachment)));
const audioFiles = computed(() => allAttachments.value.filter((attachment) => isAudio(attachment)));
const videos = computed(() => allAttachments.value.filter((attachment) => isVideo(attachment) && !isAudio(attachment)));
const files = computed(() => allAttachments.value.filter((attachment) => !isImage(attachment) && !isAudio(attachment) && !isVideo(attachment)));

const displayImages = computed(() => images.value.slice(0, 4));

const imageGridClass = computed(() => {
    const count = images.value.length;
    if (count === 1) {
        return props.compact ? 'grid-cols-1 max-w-[180px]' : 'grid-cols-1 max-w-sm';
    }
    if (count === 2) {
        return props.compact ? 'grid-cols-2 max-w-[220px]' : 'grid-cols-2 max-w-md';
    }

    return props.compact ? 'grid-cols-2 max-w-[220px]' : 'grid-cols-2 max-w-md';
});

const imageCellClass = (index, total) => {
    if (total === 3 && index === 0) {
        return 'col-span-2 aspect-[2/1]';
    }

    return 'aspect-square';
};

const formatFileSize = (bytes) => {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) {
        return '0 B';
    }
    if (value < 1024) {
        return `${value} B`;
    }
    const unit = ['KB', 'MB', 'GB', 'TB'];
    const index = Math.min(Math.floor(Math.log(value) / Math.log(1024)), unit.length);

    return `${parseFloat((value / Math.pow(1024, index)).toFixed(1))} ${unit[index - 1]}`;
};

const getAudioLabel = (attachment) => {
    const name = String(attachment?.name || '').trim().toLowerCase();
    if (name.startsWith('voice-')) {
        return 'Voice message';
    }

    return 'Audio clip';
};

const toneClasses = computed(() => {
    if (props.tone === 'mine') {
        return {
            fileCard: 'bg-white/10 border-white/20 hover:bg-white/15 text-white/95',
            fileMeta: 'text-white/75',
            iconWrap: 'bg-white/20',
            imageRing: 'ring-white/15',
            videoCard: 'border-white/25 bg-white/10 hover:border-white/45',
            videoMeta: 'text-white/85',
            audioCard: 'bg-white/10 border-white/20',
        };
    }

    if (props.tone === 'note') {
        return {
            fileCard: 'bg-amber-500/10 border-amber-500/30 hover:bg-amber-500/15 text-amber-800 dark:text-amber-200',
            fileMeta: 'text-amber-700/80 dark:text-amber-200/80',
            iconWrap: 'bg-amber-500/20',
            imageRing: 'ring-amber-500/25',
            videoCard: 'border-amber-500/30 bg-amber-500/8 hover:border-amber-500/45',
            videoMeta: 'text-amber-700/90 dark:text-amber-200/90',
            audioCard: 'bg-amber-500/10 border-amber-500/30',
        };
    }

    return {
        fileCard: 'bg-[var(--surface-secondary)] border-[var(--border-default)] hover:bg-[var(--surface-tertiary)] text-[var(--text-primary)]',
        fileMeta: 'text-[var(--text-secondary)]',
        iconWrap: 'bg-black/5 dark:bg-white/10',
        imageRing: 'ring-black/5 dark:ring-white/10',
        videoCard: 'border-[var(--border-default)] bg-[var(--surface-secondary)] hover:border-[var(--interactive-primary)]/45',
        videoMeta: 'text-[var(--text-secondary)]',
        audioCard: 'bg-[var(--surface-secondary)] border-[var(--border-default)]',
    };
});

const openImageViewer = (attachment) => {
    const media = images.value.map((item) => ({
        src: item.url || item.download_url,
        download: item.download_url || item.url,
        id: item.id,
        type: 'image',
        mimeType: item.mime_type,
    }));

    const index = media.findIndex((item) => item.id === attachment.id);
    window.dispatchEvent(new CustomEvent('media-viewer:open', {
        detail: {
            media,
            index: index >= 0 ? index : 0,
        },
    }));
};

const openVideoViewer = (attachment) => {
    const media = videos.value.map((item) => ({
        src: item.url || item.download_url,
        download: item.download_url || item.url,
        id: item.id,
        type: 'video',
        mimeType: item.mime_type,
    }));

    const index = media.findIndex((item) => item.id === attachment.id);
    window.dispatchEvent(new CustomEvent('media-viewer:open', {
        detail: {
            media,
            index: index >= 0 ? index : 0,
        },
    }));
};

const openUrl = (attachment) => attachment?.download_url || attachment?.url || '#';
</script>

<template>
    <div v-if="allAttachments.length > 0" class="space-y-2">
        <div
            v-if="images.length > 0"
            class="grid gap-1 overflow-hidden rounded-xl w-full"
            :class="imageGridClass"
        >
            <button
                v-for="(image, index) in displayImages"
                :key="image.id || `${image.name}-${index}`"
                type="button"
                class="relative overflow-hidden group/img ring-1 transition"
                :class="[imageCellClass(index, images.length), toneClasses.imageRing]"
                @click="openImageViewer(image)"
            >
                <img
                    :src="image.thumb_url || image.url || image.download_url"
                    :alt="image.name || 'Attachment image'"
                    loading="lazy"
                    decoding="async"
                    class="w-full h-full object-cover transition-transform duration-300 group-hover/img:scale-105"
                />
                <div
                    v-if="index === 3 && images.length > 4"
                    class="absolute inset-0 bg-black/55 text-white font-semibold text-sm flex items-center justify-center"
                >
                    +{{ images.length - 4 }}
                </div>
            </button>
        </div>

        <div v-if="videos.length > 0" class="space-y-2">
            <button
                v-for="(video, index) in videos"
                :key="video.id || `${video.name}-${index}`"
                type="button"
                class="group/video w-full rounded-xl border text-left transition"
                :class="toneClasses.videoCard"
                @click="openVideoViewer(video)"
            >
                <div v-if="video.thumb_url" class="relative aspect-video overflow-hidden rounded-xl">
                    <img
                        :src="video.thumb_url"
                        :alt="video.name || 'Video attachment'"
                        loading="lazy"
                        decoding="async"
                        class="w-full h-full object-cover transition-transform duration-300 group-hover/video:scale-105"
                    />
                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-black/10"></div>
                    <span class="absolute inset-0 flex items-center justify-center">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-black/55 text-white ring-1 ring-white/30">
                            <Play class="h-4 w-4 fill-current" />
                        </span>
                    </span>
                    <div class="absolute inset-x-2 bottom-2">
                        <div class="rounded-md bg-black/45 px-2 py-1 ring-1 ring-white/15 backdrop-blur-[1px]">
                            <span class="block truncate text-xs font-semibold text-white">{{ video.name || 'Video' }}</span>
                            <span class="block text-[10px] text-white/80">{{ formatFileSize(video.size) }}</span>
                        </div>
                    </div>
                </div>
                <div v-else class="flex items-center gap-2 p-2.5">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full" :class="toneClasses.iconWrap">
                        <Play class="h-4 w-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-semibold">{{ video.name || 'Video' }}</span>
                        <span class="block text-[10px]" :class="toneClasses.videoMeta">{{ formatFileSize(video.size) }}</span>
                    </span>
                </div>
            </button>
        </div>

        <div v-if="audioFiles.length > 0" class="space-y-2">
            <div
                v-for="(audio, index) in audioFiles"
                :key="audio.id || `${audio.name}-${index}`"
                class="rounded-lg border p-2"
                :class="toneClasses.audioCard"
            >
                <div class="mb-1 flex items-center justify-between text-[11px]">
                    <span class="font-medium truncate">{{ getAudioLabel(audio) }}</span>
                    <span :class="toneClasses.fileMeta">{{ formatFileSize(audio.size) }}</span>
                </div>
                <AudioMessagePlayer
                    :src="openUrl(audio)"
                    :is-mine="tone === 'mine'"
                    :duration-seconds="audio.duration_seconds"
                    compact
                />
            </div>
        </div>

        <div v-if="files.length > 0" class="space-y-1.5">
            <a
                v-for="(file, index) in files"
                :key="file.id || `${file.name}-${index}`"
                :href="file.download_url || file.url"
                target="_blank"
                rel="noopener noreferrer"
                class="flex items-center gap-2 rounded-lg border p-2 transition group/file"
                :class="toneClasses.fileCard"
            >
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-full" :class="toneClasses.iconWrap">
                    <FileText class="h-4 w-4" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-xs font-semibold">{{ file.name || 'Attachment' }}</span>
                    <span class="block text-[10px]" :class="toneClasses.fileMeta">{{ formatFileSize(file.size) }}</span>
                </span>
            </a>
        </div>
    </div>
</template>
