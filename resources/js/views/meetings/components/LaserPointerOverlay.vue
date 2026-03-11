<template>
    <!-- Full-screen transparent overlay, pointer-events: none so clicks pass through -->
    <div class="laser-overlay" ref="overlayEl">
        <TransitionGroup name="laser-dot">
            <div
                v-for="pointer in visiblePointers"
                :key="pointer.participantId"
                class="laser-dot"
                :style="{
                    left: pointer.x + '%',
                    top: pointer.y + '%',
                    '--dot-color': participantColor(pointer.participantId),
                }"
            >
                <div class="laser-dot-core" />
                <div class="laser-dot-ring" />
                <div class="laser-dot-label">{{ participantName(pointer.participantId) }}</div>
            </div>
        </TransitionGroup>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useMeetingStore } from '@/stores/meeting';

const props = defineProps<{
    targetParticipantId: string;
    isScreenShare?: boolean;
}>();

const meetingStore = useMeetingStore();
const overlayEl = ref<HTMLDivElement | null>(null);

// ─── Visible pointers (auto-filtered to < 3s stale) ──────────────────────────
const visiblePointers = computed(() => {
    const now = Date.now();
    // Only render dots on screenshare tiles
    if (!props.isScreenShare) return [];
    
    return [...meetingStore.remotePointers.values()]
        .filter(p => p.targetParticipantId === props.targetParticipantId && now - p.lastSeen < 3000);
});

// ─── Send via MeetingSignal (private channel, no HTTP, no Pusher client events needed) ────
let lastSent = 0;
const THROTTLE_MS = 50; // 20fps

function onMouseMove(e: MouseEvent) {
    if (!overlayEl.value) return;
    if (meetingStore.laserPointerMode === 'off') return;
    
    // Only track moves if this tile represents a SCREENS-SHARE
    // AND only if the local participant is the one sharing (source of the move)
    if (!props.isScreenShare) return;
    if (props.targetParticipantId !== meetingStore.localParticipant?.public_id) return;

    const now = Date.now();
    if (now - lastSent < THROTTLE_MS) return;
    lastSent = now;

    const rect = overlayEl.value.getBoundingClientRect();
    if (!rect.width || !rect.height) return;

    const x = Math.min(100, Math.max(0, ((e.clientX - rect.left) / rect.width) * 100));
    const y = Math.min(100, Math.max(0, ((e.clientY - rect.top) / rect.height) * 100));

    console.log('[LASER] Sending move:', { x, y, target: props.targetParticipantId });
    meetingStore.sendSignal?.('laser-move', { x, y, target_participant_id: props.targetParticipantId });
}

// ─── Stale pointer cleanup ────────────────────────────────────────────────────
let cleanupInterval: ReturnType<typeof setInterval>;

onMounted(() => {
    // Only add mouse listener if we are the one sharing on this tile
    if (props.isScreenShare && props.targetParticipantId === meetingStore.localParticipant?.public_id) {
        window.addEventListener('mousemove', onMouseMove, { passive: true });
    }
    
    cleanupInterval = setInterval(() => {
        const now = Date.now();
        for (const [id, p] of meetingStore.remotePointers) {
            if (now - p.lastSeen > 3000) meetingStore.remotePointers.delete(id);
        }
    }, 1000);
});

onUnmounted(() => {
    window.removeEventListener('mousemove', onMouseMove);
    clearInterval(cleanupInterval);
});

// ─── Color + name helpers ─────────────────────────────────────────────────────
const POINTER_COLORS = [
    '#f28b82', '#fbbc04', '#34a853', '#8ab4f8', '#f6aea3',
    '#a8dab5', '#cbf0f8', '#e6c9a8', '#d7aefb', '#fdcfe8',
];
function participantColor(id: string): string {
    let hash = 0;
    for (const c of id) hash = (hash * 31 + c.charCodeAt(0)) & 0xffffffff;
    return POINTER_COLORS[Math.abs(hash) % POINTER_COLORS.length];
}
function participantName(id: string): string {
    const p = (meetingStore.allParticipants as any[])?.find(x => x.public_id === id);
    return p?.name ?? p?.user?.name ?? 'Participant';
}
</script>

<style scoped>
.laser-overlay {
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 50;
    overflow: hidden;
    user-select: none;
}

.laser-dot {
    position: absolute;
    transform: translate(-50%, -50%);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}

.laser-dot-core {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--dot-color);
    box-shadow: 0 0 8px 3px var(--dot-color);
    animation: pulse-core 1s ease-in-out infinite;
}

.laser-dot-ring {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 2px solid var(--dot-color);
    opacity: 0.4;
    animation: pulse-ring 1s ease-out infinite;
}

.laser-dot-label {
    font-size: 10px;
    font-weight: 600;
    color: #fff;
    background: rgba(0, 0, 0, 0.6);
    padding: 1px 6px;
    border-radius: 8px;
    white-space: nowrap;
    margin-top: 18px;
    backdrop-filter: blur(4px);
}

@keyframes pulse-core {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.15); }
}

@keyframes pulse-ring {
    0% { transform: translate(-50%, -50%) scale(1); opacity: 0.4; }
    100% { transform: translate(-50%, -50%) scale(2.2); opacity: 0; }
}

.laser-dot-enter-active { transition: opacity 0.2s ease; }
.laser-dot-leave-active { transition: opacity 0.3s ease; }
.laser-dot-enter-from, .laser-dot-leave-to { opacity: 0; }
</style>
