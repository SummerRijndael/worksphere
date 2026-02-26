<template>
    <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="opacity-0 translate-y-4 scale-95"
        enter-to-class="opacity-100 translate-y-0 scale-100"
        leave-active-class="transition duration-500 ease-in"
        leave-from-class="opacity-100 translate-y-0 scale-100"
        leave-to-class="opacity-0 translate-y-2 scale-95"
    >
        <div
            v-if="isVisible && Object.keys(reactionCounts).length > 0"
            class="vibe-summary-pill"
        >
            <div class="vibe-content">
                <div
                    v-for="(count, emoji) in reactionCounts"
                    :key="emoji"
                    class="vibe-item"
                >
                    <span class="vibe-emoji">{{ emoji }}</span>
                    <span class="vibe-count">{{ count }}</span>
                </div>
            </div>
            <div class="vibe-label">Meeting Vibe</div>
        </div>
    </Transition>
</template>

<script setup lang="ts">
import { ref, watch, onUnmounted } from "vue";
import { useMeetingStore } from "@/stores/meeting";

const meetingStore = useMeetingStore();
const reactionCounts = ref<Record<string, number>>({});
const isVisible = ref(false);
let hideTimeout: number | null = null;
const DISPLAY_DURATION = 5000; // 5 seconds of inactivity before hiding

watch(
    () => [...meetingStore.activeReactions],
    (newReactions, oldReactions) => {
        // Only proceed if we have more reactions than before (new ones added)
        if (newReactions.length > (oldReactions?.length || 0)) {
            const latest = newReactions[newReactions.length - 1];

            // Update counts
            if (!reactionCounts.value[latest.emoji]) {
                reactionCounts.value[latest.emoji] = 0;
            }
            reactionCounts.value[latest.emoji]++;

            // Show and reset timer
            isVisible.value = true;

            if (hideTimeout) {
                clearTimeout(hideTimeout);
            }

            hideTimeout = window.setTimeout(() => {
                isVisible.value = false;
                // Reset counts after it completely fades out
                setTimeout(() => {
                    reactionCounts.value = {};
                }, 500);
            }, DISPLAY_DURATION);
        }
    },
    { deep: true },
);

onUnmounted(() => {
    if (hideTimeout) clearTimeout(hideTimeout);
});
</script>

<style scoped>
.vibe-summary-pill {
    position: absolute;
    bottom: 100px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(32, 33, 36, 0.8);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    padding: 8px 16px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
    z-index: 50;
    pointer-events: none;
    user-select: none;
}

.vibe-content {
    display: flex;
    align-items: center;
    gap: 12px;
}

.vibe-item {
    display: flex;
    align-items: center;
    gap: 4px;
}

.vibe-emoji {
    font-size: 20px;
}

.vibe-count {
    color: #ffffff;
    font-size: 14px;
    font-weight: 700;
    background: rgba(255, 255, 255, 0.1);
    padding: 2px 6px;
    border-radius: 10px;
    min-width: 20px;
    text-align: center;
}

.vibe-label {
    font-size: 10px;
    color: #9aa0a6;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    font-weight: 600;
}
</style>
