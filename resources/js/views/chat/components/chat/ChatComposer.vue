<script setup lang="ts">
import { ref, computed, nextTick, watch, onMounted, onUnmounted } from "vue";
import type { Message, PendingFile } from "@/types/models/chat";
import { useThemeStore } from "@/stores/theme";
import { Icon } from "@/components/ui";
import data from "@emoji-mart/data";
import { Picker } from "emoji-mart";
import GiphyPicker from "./GiphyPicker.vue";
import { useAudioClipRecorder } from "@/composables/useAudioClipRecorder";

interface Props {
    modelValue: string;
    sending?: boolean;
    replyTo?: Message | null;
    pendingFiles?: PendingFile[];
    isMobile?: boolean;
    chatId?: string;
    compact?: boolean;
}

interface RecordedAudioDraft {
    file: File;
    durationSeconds: number;
    mimeType: string;
    rawMimeType?: string;
    url: string;
    fallbackUrl?: string;
}

const props = withDefaults(defineProps<Props>(), {
    sending: false,
    replyTo: null,
    pendingFiles: () => [],
    isMobile: false,
    chatId: undefined,
    compact: false,
});

const emit = defineEmits<{
    "update:modelValue": [value: string];
    send: [];
    cancelReply: [];
    addFiles: [files: File[]];
    sendRecordedAudio: [file: File];
    removeFile: [index: number];
    typing: [];
    sendGif: [gif: any];
}>();

const themeStore = useThemeStore();
const textareaRef = ref<HTMLTextAreaElement | null>(null);
const isFocused = ref(false);
const showEmoji = ref(false);
const showGiphy = ref(false);
const emojiMountRef = ref<HTMLElement | null>(null);
const giphyMountRef = ref<HTMLElement | null>(null);
const recordedAudioDraft = ref<RecordedAudioDraft | null>(null);
const draftAudioRef = ref<HTMLAudioElement | null>(null);
const isDraftPlaying = ref(false);
let pickerInstance: any = null;

const canSend = computed(() => {
    return (
        (props.modelValue.trim().length > 0 ||
            props.pendingFiles.length > 0 ||
            !!recordedAudioDraft.value) &&
        !props.sending &&
        !isRecorderActive.value
    );
});

const isValidMessage = computed(() => props.modelValue.trim().length > 0);
const hasAudioDraft = computed(() => !!recordedAudioDraft.value);
const isComposerLockedForDraft = computed(
    () => hasAudioDraft.value || isRecorderActive.value || isRecorderBusy.value,
);
const isRecordButtonBlocked = computed(
    () =>
        hasAudioDraft.value ||
        props.sending ||
        props.modelValue.trim().length > 0 ||
        props.pendingFiles.length > 0,
);

const {
    isSupported: isRecorderSupported,
    isRecording: isRecorderActive,
    isBusy: isRecorderBusy,
    isPaused: isRecorderPaused,
    formattedElapsed: recorderElapsed,
    liveWaveformBars,
    draftWaveformBars,
    error: recorderError,
    onRecordButtonClick,
    onRecordButtonPointerDown,
    onRecordButtonPointerUp,
    cancelRecording,
    stopAndPrepareRecording,
    toggleRecordingPause,
} = useAudioClipRecorder({
    maxSeconds: 120,
    onReady: async (file, meta) => {
        setRecordedAudioDraft(
            file,
            meta.durationSeconds,
            meta.mimeType,
            meta.rawMimeType,
            meta.playbackBlob,
        );
    },
});

function formatDuration(totalSeconds: number) {
    const safeSeconds = Math.max(0, Math.floor(totalSeconds || 0));
    const mins = Math.floor(safeSeconds / 60);
    const secs = safeSeconds % 60;
    return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

function setRecordedAudioDraft(
    file: File,
    durationSeconds: number,
    mimeType: string,
    rawMimeType?: string,
    playbackBlob?: Blob,
) {
    if (recordedAudioDraft.value?.url) {
        URL.revokeObjectURL(recordedAudioDraft.value.url);
    }
    if (
        recordedAudioDraft.value?.fallbackUrl &&
        recordedAudioDraft.value.fallbackUrl !== recordedAudioDraft.value.url
    ) {
        URL.revokeObjectURL(recordedAudioDraft.value.fallbackUrl);
    }

    const primaryUrl = URL.createObjectURL(playbackBlob ?? file);
    const fallbackUrl =
        playbackBlob && playbackBlob !== file ? URL.createObjectURL(file) : undefined;

    recordedAudioDraft.value = {
        file,
        durationSeconds,
        mimeType,
        rawMimeType,
        url: primaryUrl,
        fallbackUrl,
    };

    emit("update:modelValue", "");
    showEmoji.value = false;
    showGiphy.value = false;
    isDraftPlaying.value = false;
}

function clearRecordedAudioDraft() {
    if (recordedAudioDraft.value?.url) {
        URL.revokeObjectURL(recordedAudioDraft.value.url);
    }
    if (
        recordedAudioDraft.value?.fallbackUrl &&
        recordedAudioDraft.value.fallbackUrl !== recordedAudioDraft.value.url
    ) {
        URL.revokeObjectURL(recordedAudioDraft.value.fallbackUrl);
    }
    recordedAudioDraft.value = null;
    isDraftPlaying.value = false;
}

async function toggleDraftPlayback() {
    const player = draftAudioRef.value;
    if (!player) return;

    try {
        if (isDraftPlaying.value) {
            player.pause();
            isDraftPlaying.value = false;
            return;
        }

        if (
            Number.isFinite(player.duration) &&
            player.duration > 0 &&
            player.currentTime >= Math.max(player.duration - 0.05, 0)
        ) {
            player.currentTime = 0;
        }

        if (player.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) {
            await new Promise<void>((resolve, reject) => {
                const onCanPlay = () => {
                    cleanup();
                    resolve();
                };
                const onError = () => {
                    cleanup();
                    reject(new Error("audio-not-playable"));
                };
                const cleanup = () => {
                    player.removeEventListener("canplay", onCanPlay);
                    player.removeEventListener("error", onError);
                };

                player.addEventListener("canplay", onCanPlay);
                player.addEventListener("error", onError);
                player.load();
            });
        }

        await player.play();
        isDraftPlaying.value = true;
    } catch {
        const draft = recordedAudioDraft.value;
        if (draft?.fallbackUrl) {
            draft.url = draft.fallbackUrl;
            draft.fallbackUrl = undefined;

            await nextTick();
            const retryPlayer = draftAudioRef.value;
            if (retryPlayer) {
                try {
                    retryPlayer.currentTime = 0;
                    await retryPlayer.play();
                    isDraftPlaying.value = true;
                    return;
                } catch {
                    // final failure path below
                }
            }
        }

        isDraftPlaying.value = false;
    }
}

function handleDraftEnded() {
    isDraftPlaying.value = false;
}

function handleDraftPaused() {
    isDraftPlaying.value = false;
}

async function handleRecordingCancel() {
    await cancelRecording();
}

const handleInput = (e: Event) => {
    const target = e.target as HTMLTextAreaElement;
    emit("update:modelValue", target.value);
    emit("typing");
    autoResize();
};

const autoResize = () => {
    if (textareaRef.value) {
        textareaRef.value.style.height = "auto";
        textareaRef.value.style.height =
            Math.min(textareaRef.value.scrollHeight, 160) + "px";
    }
};

const handleSend = () => {
    if (recordedAudioDraft.value && !props.sending && !isRecorderActive.value) {
        emit("sendRecordedAudio", recordedAudioDraft.value.file);
        clearRecordedAudioDraft();
        return;
    }

    if (canSend.value) {
        emit("send");
        nextTick(() => {
            if (textareaRef.value) {
                textareaRef.value.style.height = "auto";
            }
        });
    }
};

const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        handleSend();
    }
};

const handleFileSelect = (e: Event) => {
    const input = e.target as HTMLInputElement;
    if (input.files) {
        emit("addFiles", Array.from(input.files));
        input.value = "";
    }
};

const handlePaste = (e: ClipboardEvent) => {
    if (e.clipboardData && e.clipboardData.files.length > 0) {
        e.preventDefault();
        emit("addFiles", Array.from(e.clipboardData.files));
    }
};

// ... (imports)

// ...

// Emoji picker
async function toggleEmoji() {
    showEmoji.value = !showEmoji.value;
    await nextTick();

    if (showEmoji.value && !pickerInstance && emojiMountRef.value) {
        pickerInstance = new Picker({
            data,
            onEmojiSelect: (emoji: any) => {
                insertEmoji(emoji.native);
                // Don't close on select
            },
            previewPosition: "none",
            theme: themeStore.isDark ? "dark" : "light",
            onClickOutside: () => {
                // Let our own click handler handle it
            },
        });
        emojiMountRef.value.appendChild(pickerInstance);
    }
}

function insertEmoji(emoji: string) {
    const el = textareaRef.value;
    const currentValue = props.modelValue;

    if (!el) {
        emit("update:modelValue", currentValue + emoji);
        return;
    }

    const start = el.selectionStart;
    const end = el.selectionEnd;
    const newValue =
        currentValue.substring(0, start) + emoji + currentValue.substring(end);
    emit("update:modelValue", newValue);

    nextTick(() => {
        el.focus();
        el.selectionStart = el.selectionEnd = start + emoji.length;
        autoResize();
    });
}

// ...

function handleClickOutside(e: MouseEvent) {
    const target = e.target as HTMLElement;

    // Check emoji
    const emojiButton = document.querySelector('button[title="Emoji"]');
    if (emojiButton && emojiButton.contains(target)) return;

    if (
        showEmoji.value &&
        emojiMountRef.value &&
        !emojiMountRef.value.contains(target)
    ) {
        showEmoji.value = false;
    }

    // Check Giphy
    const giphyButton = document.querySelector('button[title="GIF"]');
    if (giphyButton && giphyButton.contains(target)) return;

    if (
        showGiphy.value &&
        giphyMountRef.value &&
        !giphyMountRef.value.contains(target)
    ) {
        showGiphy.value = false;
    }
}

function handleEsc(e: KeyboardEvent) {
    if (e.key === "Escape") {
        if (showEmoji.value) showEmoji.value = false;
        if (showGiphy.value) showGiphy.value = false;
    }
}

function toggleGiphy() {
    showGiphy.value = !showGiphy.value;
    if (showGiphy.value) {
        showEmoji.value = false;
    }
}

function handleGifSelect(gif: any) {
    emit("sendGif", gif);
    showGiphy.value = false;
}

// ... (imports)
import { useMention } from "@/composables/useMention";

const { attach: attachMention } = useMention(
    textareaRef,
    computed(() => props.chatId),
    {
        onSelect: (_item?: any) => {
            // Tribute modifies the DOM directly, so we need to sync the modelValue
            // However, since handleInput is triggered by 'input' event which Tribute dispatches
            // we might just rely on that, but let's ensure focus is kept
            nextTick(() => {
                if (textareaRef.value) {
                    emit("update:modelValue", textareaRef.value.value);
                    textareaRef.value.focus();
                    autoResize();
                }
            });
        },
    },
);

onMounted(() => {
    document.addEventListener("click", handleClickOutside);
    document.addEventListener("keydown", handleEsc);
    attachMention();
});

onUnmounted(() => {
    document.removeEventListener("click", handleClickOutside);
    document.removeEventListener("keydown", handleEsc);
});

// Focus textarea when replying
watch(
    () => props.replyTo,
    (val) => {
        if (val) {
            nextTick(() => textareaRef.value?.focus());
        }
    },
);

onUnmounted(() => {
    if (recordedAudioDraft.value?.url) {
        URL.revokeObjectURL(recordedAudioDraft.value.url);
    }
    if (
        recordedAudioDraft.value?.fallbackUrl &&
        recordedAudioDraft.value.fallbackUrl !== recordedAudioDraft.value.url
    ) {
        URL.revokeObjectURL(recordedAudioDraft.value.fallbackUrl);
    }
});
</script>

<template>
    <div
        class="relative border-t border-(--border-default) bg-(--surface-elevated) transition-all duration-300"
        :class="compact ? 'px-2 py-2' : 'px-4 py-4 md:px-6 md:py-5'"
    >
        <!-- Reply Preview -->
        <transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-2"
        >
            <div
                v-if="replyTo"
                class="flex items-center gap-3 mb-4 p-3 rounded-xl bg-(--surface-secondary) border border-(--border-subtle) shadow-sm relative overflow-hidden group"
            >
                <div
                    class="absolute left-0 top-0 bottom-0 w-1 bg-(--interactive-primary)"
                ></div>
                <div class="flex-1 min-w-0 pl-2">
                    <div class="flex items-center gap-2 mb-0.5">
                        <Icon
                            name="CornerUpLeft"
                            size="14"
                            class="text-(--interactive-primary)"
                        />
                        <span
                            class="text-xs font-semibold text-(--text-primary)"
                        >
                            Replying to {{ replyTo.user_name }}
                        </span>
                    </div>
                    <div class="text-sm text-(--text-secondary) truncate">
                        {{ replyTo.content || "📎 Attachment" }}
                    </div>
                </div>
                <button
                    class="shrink-0 p-2 rounded-lg hover:bg-(--surface-tertiary) text-(--text-tertiary) hover:text-(--text-primary) transition-colors"
                    @click="emit('cancelReply')"
                >
                    <Icon name="X" size="16" />
                </button>
            </div>
        </transition>

        <!-- Pending Files -->
        <div
            v-if="pendingFiles.length"
            class="flex gap-3 mb-4 overflow-x-auto pb-2 scrollbar-hide"
        >
            <transition-group
                enter-active-class="transition ease-out duration-300"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="transition ease-in duration-200"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95"
            >
                <div
                    v-for="(file, idx) in pendingFiles"
                    :key="file.name + idx"
                    class="relative shrink-0 w-24 group"
                >
                    <div
                        class="w-24 h-24 rounded-xl bg-(--surface-secondary) border border-(--border-subtle) overflow-hidden shadow-sm"
                    >
                        <img
                            v-if="file.isImage && file.url"
                            :src="file.url"
                            class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"
                        />
                        <div
                            v-else
                            class="flex flex-col items-center justify-center h-full text-(--text-tertiary) p-2 text-center"
                        >
                            <Icon name="FileText" size="28" class="mb-1" />
                            <span
                                class="text-[10px] uppercase font-bold tracking-wider opacity-70"
                                >{{ file.name.split(".").pop() }}</span
                            >
                        </div>
                    </div>
                    <button
                        class="absolute -top-2 -right-2 w-6 h-6 rounded-full bg-(--surface-elevated) border border-(--border-subtle) text-(--text-secondary) hover:text-red-500 flex items-center justify-center shadow-md hover:bg-red-50 transition-all z-10 scale-0 group-hover:scale-100"
                        @click="emit('removeFile', idx)"
                    >
                        <Icon name="X" size="14" />
                    </button>
                    <div
                        class="text-[11px] font-medium text-(--text-secondary) truncate mt-1.5 px-0.5"
                    >
                        {{ file.name }}
                    </div>
                </div>
            </transition-group>
        </div>

        <div
            v-if="
                !hasAudioDraft &&
                (isRecorderActive || isRecorderBusy || recorderError)
            "
            class="mb-3 flex items-center justify-between rounded-xl border px-3 py-2 text-xs"
            :class="
                recorderError
                    ? 'border-red-300/60 bg-red-50 text-red-600 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-300'
                    : 'border-(--border-subtle) bg-(--surface-secondary) text-(--text-secondary)'
            "
        >
            <template v-if="isRecorderBusy && !isRecorderActive">
                <div class="flex items-center gap-2 font-medium">
                    <span
                        class="inline-block h-2 w-2 rounded-full bg-red-500 animate-pulse"
                    ></span>
                    <span>Preparing microphone...</span>
                </div>
                <button
                    type="button"
                    class="rounded-md px-2 py-1 text-xs font-medium text-(--text-primary) hover:bg-(--surface-tertiary)"
                    @click="handleRecordingCancel"
                >
                    Cancel
                </button>
            </template>
            <template v-else-if="isRecorderActive">
                <div class="flex items-center gap-2 font-medium">
                    <span
                        class="inline-block h-2 w-2 rounded-full bg-red-500 animate-pulse"
                    ></span>
                    <span>
                        {{ isRecorderPaused ? "Paused" : "Recording" }}
                        {{ recorderElapsed }} / 02:00
                    </span>
                </div>
                <div class="flex items-center gap-1.5">
                    <button
                        type="button"
                        class="rounded-md px-2 py-1 text-xs font-medium text-(--text-primary) hover:bg-(--surface-tertiary)"
                        @click="toggleRecordingPause"
                    >
                        {{ isRecorderPaused ? "Resume" : "Pause" }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-2 py-1 text-xs font-medium text-(--text-primary) hover:bg-(--surface-tertiary)"
                        @click="stopAndPrepareRecording"
                    >
                        Stop
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-2 py-1 text-xs font-medium text-(--text-primary) hover:bg-(--surface-tertiary)"
                        @click="handleRecordingCancel"
                    >
                        Cancel
                    </button>
                </div>
            </template>
            <template v-else>
                <span>{{ recorderError }}</span>
            </template>
        </div>

        <!-- Input Row -->
        <div class="flex gap-3" :class="compact ? 'items-center' : 'items-end'">
            <!-- Left Actions Group -->
            <div
                class="flex items-center gap-1"
                :class="{ 'mb-1.5': !compact }"
            >
                <!-- Attach Button -->
                <label
                    class="p-2.5 rounded-full cursor-pointer text-(--text-secondary) hover:text-(--interactive-primary) hover:bg-(--surface-secondary) transition-all duration-200 group relative"
                    :class="{
                        'opacity-50 pointer-events-none': isComposerLockedForDraft,
                    }"
                    title="Attach File"
                >
                    <input
                        type="file"
                        multiple
                        accept="image/*,audio/*,.pdf,.doc,.docx,.txt,.zip"
                        class="hidden"
                        @change="handleFileSelect"
                    />
                    <Icon
                        name="Paperclip"
                        size="22"
                        :stroke-width="2"
                        class="group-hover:rotate-45 transition-transform"
                    />
                </label>

                <button
                    v-if="isRecorderSupported"
                    type="button"
                    class="p-2.5 rounded-full text-(--text-secondary) hover:text-(--interactive-primary) hover:bg-(--surface-secondary) transition-all duration-200"
                    :class="{
                        'text-red-500 bg-red-50 dark:bg-red-500/10': isRecorderActive,
                        'opacity-50 pointer-events-none': isRecordButtonBlocked,
                    }"
                    :title="
                        isRecorderActive
                            ? 'Stop recording'
                            : 'Record audio (click to start/stop or hold to record)'
                    "
                    :disabled="isRecordButtonBlocked"
                    @click.stop="onRecordButtonClick"
                    @pointerdown.stop.prevent="onRecordButtonPointerDown"
                    @pointerup.stop.prevent="onRecordButtonPointerUp"
                    @pointercancel.stop.prevent="onRecordButtonPointerUp"
                    @pointerleave.stop.prevent="onRecordButtonPointerUp"
                >
                    <Icon
                        :name="isRecorderActive ? 'Square' : 'Mic'"
                        :size="20"
                    />
                </button>

                <div class="relative">
                    <button
                        class="text-(--text-tertiary) hover:text-(--text-primary) transition-colors rounded-full hover:bg-(--surface-tertiary)"
                        :class="[
                            compact ? 'p-1.5' : 'p-2',
                            showEmoji
                                ? 'text-yellow-500 bg-(--surface-secondary)'
                                : '',
                            isComposerLockedForDraft
                                ? 'opacity-50 pointer-events-none'
                                : '',
                        ]"
                        title="Insert emoji"
                        @click.stop="toggleEmoji"
                    >
                        <Icon name="Smile" :size="compact ? 18 : 20" />
                    </button>
                </div>

                <!-- GIF Button (Hidden in compact mode) -->
                <div v-if="!compact" class="relative group">
                    <button
                        class="p-2 text-(--text-tertiary) hover:text-(--text-primary) transition-colors rounded-full hover:bg-(--surface-tertiary)"
                        :class="{
                            'opacity-50 pointer-events-none': isComposerLockedForDraft,
                        }"
                        title="GIF"
                        @click.stop="toggleGiphy"
                    >
                        <div
                            class="font-bold text-[10px] leading-none border-2 border-current rounded px-1 py-0.5"
                        >
                            GIF
                        </div>
                    </button>
                </div>
            </div>

            <!-- Main Input Area -->
            <div
                class="flex-1 min-w-0 relative rounded-[20px] bg-(--surface-secondary) border transition-all duration-200"
                :class="
                    isFocused
                        ? 'border-(--interactive-primary) ring-2 ring-(--interactive-primary)/10 shadow-sm bg-(--surface-primary)'
                        : 'border-transparent hover:border-(--border-subtle)'
                "
            >
                <textarea
                    v-if="!hasAudioDraft && !isRecorderBusy && !isRecorderActive"
                    ref="textareaRef"
                    :value="modelValue"
                    placeholder="Type a message..."
                    rows="1"
                    class="block w-full resize-none border-0 bg-transparent text-(--text-primary) placeholder-(--text-muted) outline-none focus:ring-0 text-[15px] leading-relaxed max-h-40 py-3.5 px-4 rounded-[20px]"
                    @input="handleInput"
                    @keydown="handleKeydown"
                    @paste="handlePaste"
                    @focus="isFocused = true"
                    @blur="isFocused = false"
                />

                <div
                    v-else-if="isRecorderBusy || isRecorderActive"
                    class="flex items-center gap-3 py-3 px-4"
                >
                    <button
                        type="button"
                        class="w-8 h-8 rounded-full bg-red-500 text-white flex items-center justify-center shrink-0"
                        :title="isRecorderPaused ? 'Resume recording' : 'Pause recording'"
                        @click="toggleRecordingPause"
                        :disabled="isRecorderBusy && !isRecorderActive"
                    >
                        <Icon
                            :name="isRecorderPaused ? 'Play' : 'Pause'"
                            size="14"
                        />
                    </button>
                    <button
                        type="button"
                        class="w-8 h-8 rounded-full bg-(--interactive-primary) text-white flex items-center justify-center shrink-0"
                        title="Stop recording"
                        @click="stopAndPrepareRecording"
                        :disabled="isRecorderBusy && !isRecorderActive"
                    >
                        <Icon name="Square" size="14" />
                    </button>
                    <div
                        class="flex-1 h-8 rounded-full border border-(--border-subtle) bg-(--surface-secondary) px-3 flex items-center gap-1 overflow-hidden"
                    >
                        <span
                            v-for="(barHeight, idx) in liveWaveformBars"
                            :key="`recording-wave-${idx}`"
                            class="inline-block w-0.5 rounded-full bg-(--text-tertiary)/70 animate-pulse"
                            :style="{ height: `${barHeight}px` }"
                        ></span>
                    </div>
                    <span
                        class="text-xs font-semibold text-(--text-secondary) w-11 text-right"
                    >
                        {{
                            isRecorderBusy && !isRecorderActive
                                ? "..."
                                : isRecorderPaused
                                  ? `${recorderElapsed}P`
                                  : recorderElapsed
                        }}
                    </span>
                    <button
                        type="button"
                        class="w-7 h-7 rounded-full hover:bg-(--surface-tertiary) text-(--text-secondary) flex items-center justify-center shrink-0"
                        title="Cancel recording"
                        @click="handleRecordingCancel"
                    >
                        <Icon name="X" size="14" />
                    </button>
                </div>

                <div
                    v-else-if="hasAudioDraft && recordedAudioDraft"
                    class="flex items-center gap-3 py-3 px-4"
                >
                    <button
                        type="button"
                        class="w-8 h-8 rounded-full bg-(--interactive-primary) text-white flex items-center justify-center shrink-0"
                        :title="isDraftPlaying ? 'Pause preview' : 'Play / resume preview'"
                        @click="toggleDraftPlayback"
                    >
                        <Icon :name="isDraftPlaying ? 'Pause' : 'Play'" size="14" />
                    </button>
                    <div
                        class="flex-1 h-8 rounded-full border border-(--border-subtle) bg-(--surface-secondary) px-3 flex items-center gap-1 overflow-hidden"
                    >
                        <span
                            v-for="(barHeight, idx) in draftWaveformBars"
                            :key="`draft-wave-${idx}`"
                            class="inline-block w-0.5 rounded-full bg-(--interactive-primary)/55"
                            :style="{ height: `${barHeight}px` }"
                        ></span>
                    </div>
                    <span
                        class="text-xs font-semibold text-(--text-secondary) w-11 text-right"
                    >
                        {{ formatDuration(recordedAudioDraft.durationSeconds) }}
                    </span>
                    <button
                        type="button"
                        class="w-7 h-7 rounded-full hover:bg-(--surface-tertiary) text-(--text-secondary) flex items-center justify-center shrink-0"
                        title="Discard recording"
                        @click="clearRecordedAudioDraft"
                    >
                        <Icon name="X" size="14" />
                    </button>
                    <audio
                        ref="draftAudioRef"
                        :src="recordedAudioDraft.url"
                        class="hidden"
                        @ended="handleDraftEnded"
                        @pause="handleDraftPaused"
                    />
                </div>

                <!-- Character Count -->
                <div
                    v-if="!hasAudioDraft && modelValue.length > 3000"
                    class="absolute bottom-2 right-4 text-[10px] font-medium transition-colors pointer-events-none"
                    :class="
                        modelValue.length >= 4000
                            ? 'text-red-500'
                            : 'text-(--text-tertiary)'
                    "
                >
                    {{ modelValue.length }} / 4000
                </div>
            </div>

            <!-- Send Button -->
            <button
                @click="handleSend"
                :disabled="
                    (!isValidMessage &&
                        pendingFiles.length === 0 &&
                        !hasAudioDraft) ||
                    isRecorderActive ||
                    isRecorderBusy
                "
                class="flex items-center justify-center rounded-full transition-all duration-200 transform hover:scale-105 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:scale-100"
                :class="[
                    isValidMessage || pendingFiles.length > 0 || hasAudioDraft
                        ? 'bg-(--interactive-primary) text-white shadow-md hover:bg-(--interactive-primary-hover)'
                        : 'bg-(--surface-tertiary) text-(--text-tertiary)',
                    compact ? 'w-9 h-9' : 'w-10 h-10',
                    !compact ? 'mb-1.5 pr-0.5' : '',
                ]"
            >
                <Icon name="Send" :size="compact ? 16 : 18" />
            </button>
        </div>

        <!-- Popovers -->
        <!-- Emoji Picker -->
        <div
            v-show="showEmoji"
            ref="emojiMountRef"
            :class="[
                isMobile
                    ? 'fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4'
                    : 'absolute bottom-full left-4 mb-3 z-50 shadow-xl rounded-2xl overflow-hidden',
            ]"
            :style="
                compact
                    ? {
                          transform: 'scale(0.85)',
                          'transform-origin': 'bottom left',
                      }
                    : {}
            "
            @click.self="isMobile ? (showEmoji = false) : null"
        />

        <!-- Giphy Picker -->
        <div
            v-if="showGiphy"
            ref="giphyMountRef"
            :class="[
                isMobile
                    ? 'fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4'
                    : 'absolute bottom-full left-16 mb-3 z-50 shadow-xl rounded-2xl overflow-hidden',
            ]"
            @click.self="isMobile ? (showGiphy = false) : null"
        >
            <div
                class="bg-(--surface-elevated) rounded-2xl border border-(--border-default) overflow-hidden"
            >
                <GiphyPicker @select="handleGifSelect" />
            </div>
        </div>
    </div>
</template>
