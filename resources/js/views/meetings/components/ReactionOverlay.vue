<template>
    <div class="reactions-overlay">
        <!-- Floating Emojis -->
        <TransitionGroup name="float" tag="div">
            <div
                v-for="reaction in meetingStore.activeReactions"
                :key="reaction.id"
                class="floating-emoji"
                :style="getStyle(reaction.id)"
            >
                <div class="emoji-wrapper">
                    <span class="emoji-char">{{ reaction.emoji }}</span>
                    <span v-if="showNames" class="emoji-name">{{ getParticipantName(reaction.publicId) }}</span>
                </div>
            </div>
        </TransitionGroup>

        <!-- Reaction Picker Button (Bottom Bar integration or Floating) -->
        <div class="reaction-picker-wrapper" v-if="showPicker">
            <div class="reaction-menu">
                <button v-for="emoji in popularEmojis" 
                        :key="emoji"
                        @click="sendReaction(emoji)"
                        class="emoji-btn"
                        :title="'Send ' + emoji"
                >
                    {{ emoji }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useMeetingStore } from '@/stores/meeting';

const props = defineProps({
    showPicker: {
        type: Boolean,
        default: false
    },
    showNames: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['reaction-sent']);

const meetingStore = useMeetingStore();
const popularEmojis = ['👍', '👎', '👏', '😂', '😮', '🎉', '❤️', '🤔', '😢', '💯', '😱'];

// Store random offsets for each reaction so they float differently
const animationCache = new Map<string, any>();

function getStyle(id: string) {
    if (!animationCache.has(id)) {
        // Random horizontal starting position between 20% and 80% to avoid screen edges
        const leftParams = Math.random() * 60 + 20;
        
        // Random scale for variety
        const scale = 0.8 + Math.random() * 0.4;
        
        // Random rotation tilt
        const rotate = -15 + Math.random() * 30;
        
        animationCache.set(id, {
            left: `${leftParams}%`,
            '--emoji-scale': scale,
            '--emoji-rotate': `${rotate}deg`,
        });
    }
    return animationCache.get(id);
}

function getParticipantName(publicId: string) {
    if (publicId === meetingStore.localParticipant?.public_id) return 'You';
    const p = meetingStore.allParticipants.find(x => x.public_id === publicId);
    if (!p) return '';
    const base = (p.user?.name || p.metadata?.guest_name || 'Guest').split(' ')[0]; // Just first name
    const isGuest = !p.user?.public_id && !p.user?.id;
    if (isGuest && !/\(guest\)$/i.test(base)) {
        return `${base} (Guest)`;
    }
    return base;
}

function sendReaction(emoji: string) {
    meetingStore.sendReaction(emoji);
    emit('reaction-sent', emoji);
}
</script>

<style scoped>
.reactions-overlay {
    position: absolute;
    bottom: 90px; /* Above the control bar */
    left: 0;
    right: 0;
    height: 60vh;
    pointer-events: none;
    z-index: 40;
    overflow: hidden;
}

.reaction-picker-wrapper {
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    pointer-events: auto;
    background: #3c4043;
    border-radius: 24px;
    padding: 8px 16px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
    margin-bottom: 20px;
    max-width: 90vw;
    width: fit-content;
}

.reaction-menu {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: center;
}

.emoji-btn {
    background: transparent;
    border: none;
    font-size: 24px;
    cursor: pointer;
    transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    user-select: none;
    padding: 4px;
    display: flex;
    justify-content: center;
    align-items: center;
    border-radius: 50%;
}

.emoji-btn:hover {
    transform: scale(1.3);
    background: rgba(255, 255, 255, 0.1);
}

.emoji-btn:active {
    transform: scale(0.9);
}

/* Floating Animation Styles */
.floating-emoji {
    position: absolute;
    bottom: 0px;
    will-change: transform, opacity;
}

.emoji-wrapper {
    display: flex;
    flex-direction: column;
    align-items: center;
    transform: scale(var(--emoji-scale)) rotate(var(--emoji-rotate));
    background: rgba(0,0,0,0.4);
    backdrop-filter: blur(4px);
    border-radius: 20px;
    padding: 6px 12px;
}

.emoji-char {
    font-size: 32px;
    line-height: 1;
}

.emoji-name {
    font-size: 11px;
    color: white;
    font-weight: 500;
    margin-top: 4px;
    white-space: nowrap;
}

/* Vue Transition Group Classes */
.float-enter-active {
    animation: floatUp 3s cubic-bezier(0.25, 0.1, 0.25, 1) forwards;
}

.float-leave-active {
    transition: opacity 0.5s ease;
}

.float-enter-from {
    opacity: 0;
    transform: translateY(20px);
}

.float-leave-to {
    opacity: 0;
}

@keyframes floatUp {
    0% {
        transform: translateY(20px) scale(0.5);
        opacity: 0;
    }
    10% {
        opacity: 1;
        transform: translateY(0) scale(1.1);
    }
    15% {
        transform: translateY(-20px) scale(1);
    }
    90% {
        opacity: 1;
    }
    100% {
        transform: translateY(-300px);
        opacity: 0;
    }
}
</style>
