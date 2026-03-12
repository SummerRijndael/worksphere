<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { Icon } from "@/components/ui";

interface Props {
    src: string;
    isMine?: boolean;
    compact?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    isMine: false,
    compact: false,
});

const audioRef = ref<HTMLAudioElement | null>(null);
const isPlaying = ref(false);
const currentTime = ref(0);
const duration = ref(0);
const hasError = ref(false);
let rafId: number | null = null;

function stopProgressSync() {
    if (rafId !== null) {
        window.cancelAnimationFrame(rafId);
        rafId = null;
    }
}

function syncFromElement() {
    const audio = audioRef.value;
    if (!audio) return;

    currentTime.value = Number.isFinite(audio.currentTime) ? audio.currentTime : 0;

    const nextDuration = Number.isFinite(audio.duration) ? audio.duration : 0;
    if (nextDuration > 0) {
        duration.value = nextDuration;
    }
}

function startProgressSync() {
    stopProgressSync();
    const tick = () => {
        syncFromElement();
        if (isPlaying.value) {
            rafId = window.requestAnimationFrame(tick);
        } else {
            rafId = null;
        }
    };
    rafId = window.requestAnimationFrame(tick);
}

const progressPercent = computed(() => {
    if (!duration.value || duration.value <= 0) return 0;
    return Math.max(0, Math.min(100, (currentTime.value / duration.value) * 100));
});

const isIndeterminate = computed(
    () => isPlaying.value && (!duration.value || duration.value <= 0),
);

const formattedCurrent = computed(() => formatTime(currentTime.value));
const formattedDuration = computed(() => formatTime(duration.value));

function formatTime(value: number) {
    if (!Number.isFinite(value) || value < 0) return "0:00";
    const secs = Math.floor(value);
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return `${m}:${String(s).padStart(2, "0")}`;
}

async function togglePlayback() {
    const audio = audioRef.value;
    if (!audio) return;

    if (isPlaying.value) {
        audio.pause();
        return;
    }

    hasError.value = false;
    try {
        if (
            duration.value > 0 &&
            audio.currentTime >= Math.max(duration.value - 0.05, 0)
        ) {
            audio.currentTime = 0;
            currentTime.value = 0;
        }
        await audio.play();
    } catch {
        hasError.value = true;
    }
}

function handleLoadedMetadata() {
    syncFromElement();
    hasError.value = false;
}

function handleTimeUpdate() {
    syncFromElement();
}

function handleEnded() {
    isPlaying.value = false;
    stopProgressSync();
    syncFromElement();
}

function handlePlay() {
    isPlaying.value = true;
    startProgressSync();
}

function handlePause() {
    isPlaying.value = false;
    stopProgressSync();
    syncFromElement();
}

function handleError() {
    hasError.value = true;
    isPlaying.value = false;
    stopProgressSync();
}

function seek(event: Event) {
    const audio = audioRef.value;
    const target = event.target as HTMLInputElement | null;
    if (!audio || !target) return;

    const next = Number(target.value);
    if (!Number.isFinite(next)) return;
    audio.currentTime = next;
    currentTime.value = next;
}

watch(
    () => props.src,
    () => {
        stopProgressSync();
        isPlaying.value = false;
        hasError.value = false;
        currentTime.value = 0;
        duration.value = 0;
    },
);

onBeforeUnmount(() => {
    stopProgressSync();
});
</script>

<template>
    <div
        class="chat-audio-player"
        :class="{
            'is-own': isMine,
            'is-compact': compact,
            'has-error': hasError,
        }"
    >
        <audio
            ref="audioRef"
            preload="metadata"
            :src="src"
            class="hidden"
            @loadedmetadata="handleLoadedMetadata"
            @timeupdate="handleTimeUpdate"
            @play="handlePlay"
            @pause="handlePause"
            @ended="handleEnded"
            @error="handleError"
        />

        <button
            type="button"
            class="chat-audio-play"
            :title="isPlaying ? 'Pause' : 'Play'"
            @click="togglePlayback"
        >
            <Icon :name="isPlaying ? 'Pause' : 'Play'" :size="compact ? 12 : 14" />
        </button>

        <div class="chat-audio-progress-wrap">
            <div class="chat-audio-progress-track">
                <div
                    class="chat-audio-progress-fill"
                    :class="{ 'is-indeterminate': isIndeterminate }"
                    :style="{ width: `${progressPercent}%` }"
                />
            </div>
            <input
                class="chat-audio-progress-input"
                type="range"
                min="0"
                :max="duration || 0"
                step="0.01"
                :value="currentTime"
                :disabled="!duration || hasError"
                @input="seek"
            />
        </div>

        <span class="chat-audio-time">
            {{ formattedCurrent }} / {{ formattedDuration }}
        </span>
    </div>
</template>

<style scoped>
.chat-audio-player {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 9px;
    border-radius: 12px;
    border: 1px solid color-mix(in srgb, var(--border-default) 88%, transparent);
    background: color-mix(in srgb, var(--surface-elevated) 92%, transparent);
}

.chat-audio-player.is-own {
    border-color: rgba(255, 255, 255, 0.25);
    background: rgba(0, 0, 0, 0.18);
}

.chat-audio-player.is-compact {
    gap: 6px;
    padding: 6px 7px;
    border-radius: 10px;
}

.chat-audio-play {
    width: 28px;
    height: 28px;
    border-radius: 999px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: white;
    background: linear-gradient(
        135deg,
        color-mix(in srgb, var(--interactive-primary) 90%, white 10%),
        color-mix(in srgb, var(--interactive-primary) 68%, black 32%)
    );
    flex-shrink: 0;
}

.chat-audio-player.is-own .chat-audio-play {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.34), rgba(255, 255, 255, 0.18));
}

.chat-audio-player.is-compact .chat-audio-play {
    width: 24px;
    height: 24px;
}

.chat-audio-progress-wrap {
    position: relative;
    flex: 1;
    min-width: 84px;
    height: 20px;
    display: flex;
    align-items: center;
}

.chat-audio-progress-track {
    width: 100%;
    height: 5px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--text-secondary) 30%, transparent);
    overflow: hidden;
}

.chat-audio-player.is-own .chat-audio-progress-track {
    background: rgba(255, 255, 255, 0.28);
}

.chat-audio-progress-fill {
    height: 100%;
    border-radius: inherit;
    min-width: 0;
    background: linear-gradient(
        90deg,
        color-mix(in srgb, var(--interactive-primary) 95%, white 5%),
        color-mix(in srgb, var(--interactive-primary) 70%, white 30%)
    );
    box-shadow: 0 0 8px color-mix(in srgb, var(--interactive-primary) 55%, transparent);
}

.chat-audio-progress-fill.is-indeterminate {
    width: 34% !important;
    animation: audioIndeterminate 1.1s ease-in-out infinite;
}

@keyframes audioIndeterminate {
    0% {
        transform: translateX(-110%);
    }
    100% {
        transform: translateX(305%);
    }
}

.chat-audio-player.is-own .chat-audio-progress-fill {
    background: linear-gradient(90deg, #fff, rgba(255, 255, 255, 0.72));
}

.chat-audio-progress-input {
    position: absolute;
    inset: 0;
    width: 100%;
    margin: 0;
    opacity: 0;
    cursor: pointer;
}

.chat-audio-time {
    min-width: 64px;
    text-align: right;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
    color: var(--text-secondary);
}

.chat-audio-player.is-own .chat-audio-time {
    color: rgba(255, 255, 255, 0.85);
}

.chat-audio-player.is-compact .chat-audio-time {
    min-width: 56px;
    font-size: 10px;
}

.chat-audio-player.has-error {
    border-color: color-mix(in srgb, var(--color-error) 55%, transparent);
}
</style>
