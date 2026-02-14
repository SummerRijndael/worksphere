<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted } from "vue";
import { useChatStore } from "@/stores/chat";
import { useAuthStore } from "@/stores/auth";
import { Icon } from "@/components/ui";
import MiniChatMessageBubble from "@/components/minichat/MiniChatMessageBubble.vue";
import type { Message } from "@/types/models/chat";

const props = defineProps<{
    chatId: string;
    messages?: Message[];
    isLoadingMore?: boolean;
}>();

const emit = defineEmits<{
    (e: 'fetch-older'): void;
    (e: 'jump-to-latest'): void;
    (e: 'reply', message: Message): void;
    (e: 'jump', messageId: string): void;
}>();

const chatStore = useChatStore();
const authStore = useAuthStore();
const messagesContainer = ref<HTMLElement | null>(null);
const showJumpToLatest = ref(false);

const storeMessages = computed(() => {
    if (!chatStore.messagesByChat) return [];
    return chatStore.messagesByChat.get(props.chatId) || [];
});

const displayMessages = computed(() => {
    return props.messages || storeMessages.value;
});

const currentUserPublicId = computed(() => authStore.user?.public_id);

function isOwnMessage(message: Message) {
    if (message.type === 'system') return false; 
    return message.user_public_id === currentUserPublicId.value;
}

// Group messages by date
const groupedMessages = computed(() => {
    const list = displayMessages.value;
    if (!list || !Array.isArray(list)) return [];
    
    const groups: { date: string; messages: Message[] }[] = [];
    let currentDate = "";

    list.forEach((message) => {
        const date = new Date(message.created_at).toLocaleDateString();
        if (date !== currentDate) {
            currentDate = date;
            groups.push({ date, messages: [] });
        }
        groups[groups.length - 1].messages.push(message);
    });

    return groups;
});

function scrollToBottom(smooth = true) {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTo({
                top: messagesContainer.value.scrollHeight,
                behavior: smooth ? "smooth" : "auto",
            });
        }
    });
}

function scrollToMessage(messageId: string) {
    nextTick(() => {
        const el = messagesContainer.value?.querySelector(`[data-message-id="${messageId}"]`);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Add a brief highlight effect
            el.classList.add('highlight-message');
            setTimeout(() => el.classList.remove('highlight-message'), 2000);
        }
    });
}

// Watch for new messages to scroll
watch(
    () => displayMessages.value.length,
    () => scrollToBottom(true)
);

watch(
    () => props.chatId,
    () => scrollToBottom(false)
);

function handleScroll(e: Event) {
    const container = e.target as HTMLElement;
    if (!container) return;

    // Emit fetch-older when near top (50px threshold)
    if (container.scrollTop < 50 && !props.isLoadingMore) {
        // Prevent multiple fetches at the same position
        emit('fetch-older');
    }

    // Show Jump to Latest if not at bottom (100px threshold)
    const isAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
    showJumpToLatest.value = !isAtBottom;
}

onMounted(() => {
    scrollToBottom(false);
    // Extra insurance for heavy initial loads
    setTimeout(() => scrollToBottom(false), 100);
});

// Expose internal elements for parent linkage
defineExpose({ 
    scrollToBottom,
    scrollToMessage,
    container: messagesContainer
});

</script>
<template>
    <div class="relative flex-1 flex flex-col min-h-0 overflow-hidden">
        <div
            ref="messagesContainer"
            class="flex-1 overflow-y-auto custom-chat-scrollbar p-4 space-y-4"
            @scroll="handleScroll"
        >
            <!-- Loading More Spinner -->
            <div v-if="isLoadingMore" class="flex justify-center py-2 shrink-0">
                <Icon name="Loader2" size="20" class="animate-spin text-(--text-muted)" />
            </div>

            <!-- Messages List -->
            <div v-if="displayMessages.length > 0" class="flex flex-col gap-3 pr-1">
            <div
                v-for="(group, index) in groupedMessages"
                :key="group.date || index"
                class="flex flex-col gap-3"
            >
                <!-- Date Separator -->
                <div class="flex items-center justify-center my-2 px-4 overflow-hidden">
                    <div class="h-px flex-1 bg-(--border-muted) opacity-50"></div>
                    <span class="mx-4 text-[10px] font-bold uppercase tracking-widest text-(--text-muted) whitespace-nowrap">
                        {{ group.date }}
                    </span>
                    <div class="h-px flex-1 bg-(--border-muted) opacity-50"></div>
                </div>

                <div
                    v-for="message in group.messages"
                    :key="message.id"
                    :data-message-id="message.id"
                    class="message-container flex flex-col transition-all duration-500 rounded-lg"
                    :class="{
                        'items-end': isOwnMessage(message),
                        'items-start': !isOwnMessage(message) && message.type !== 'system',
                        'items-center': message.type === 'system'
                    }"
                >
                    <!-- Name for Others (Avatar 24px + Gap 8px = 32px / ml-8) -->
                    <div
                        v-if="!isOwnMessage(message) && message.type !== 'system'"
                        class="text-[10px] font-semibold text-(--text-muted) mb-1 ml-8"
                    >
                        {{ message.user_name }}
                    </div>

                    <!-- Name for Me -->
                    <div
                        v-if="isOwnMessage(message) && message.type !== 'system'"
                        class="text-[10px] font-semibold text-(--text-muted) mb-1 mr-1"
                    >
                        You
                    </div>

                    <MiniChatMessageBubble
                        :message="message"
                        :is-mine="isOwnMessage(message)"
                        :class="{ 'max-w-full! w-full': message.type === 'system' }"
                        @reply="(m) => emit('reply', m)"
                        @jump="(id) => emit('jump', id)"
                    />
                </div>
            </div>
        </div>

        <!-- No Messages State -->
        <div
            v-else
            class="flex-1 flex flex-col items-center justify-center p-8 text-center"
        >
            <div class="w-16 h-16 rounded-full bg-(--surface-secondary) flex items-center justify-center mb-4">
                <Icon name="MessageSquare" size="24" class="text-(--text-muted)" />
            </div>
            <h3 class="text-sm font-semibold text-(--text-primary) mb-1">No messages yet</h3>
            <p class="text-xs text-(--text-secondary) max-w-[200px]">
                Start the conversation by sending a message below.
            </p>
        </div>

        </div>

        <!-- Jump to Latest Button -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="transform translate-y-4 opacity-0"
            enter-to-class="transform translate-y-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="transform translate-y-0 opacity-100"
            leave-to-class="transform translate-y-4 opacity-0"
        >
            <button
                v-if="showJumpToLatest"
                class="absolute bottom-6 left-1/2 -translate-x-1/2 bg-(--interactive-primary) text-white px-6 py-2.5 rounded-full shadow-2xl text-[10px] uppercase tracking-widest font-bold z-20 scale-[0.7] hover:scale-[0.8] active:scale-[0.65] transition-all flex items-center gap-2 ring-1 ring-white/20"
                @click="scrollToBottom(true)"
            >
                <Icon name="ArrowDown" size="14" />
                Jump to Latest
            </button>
        </Transition>
    </div>
</template>

<style>
/* Global-level overrides for the call chat scrollbar to bypass scoping issues and specificity battles */
/* Double-class selector to win against Tailwind v4 broad resets */
.custom-chat-scrollbar.custom-chat-scrollbar {
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
    overflow-x: hidden !important;
}

.custom-chat-scrollbar.custom-chat-scrollbar::-webkit-scrollbar {
    width: 6px !important;
}

.custom-chat-scrollbar.custom-chat-scrollbar::-webkit-scrollbar-track {
    background: transparent !important;
}

.custom-chat-scrollbar.custom-chat-scrollbar::-webkit-scrollbar-thumb {
    background: transparent !important;
    border-radius: 12px !important;
}

.custom-chat-scrollbar.custom-chat-scrollbar:hover::-webkit-scrollbar-thumb {
    background: rgba(0, 0, 0, 0.4) !important;
}

.dark .custom-chat-scrollbar.custom-chat-scrollbar:hover::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.2) !important;
}

.custom-chat-scrollbar.custom-chat-scrollbar::-webkit-scrollbar-thumb:hover {
    background: rgba(0, 0, 0, 0.6) !important;
}

.dark .custom-chat-scrollbar.custom-chat-scrollbar::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.3) !important;
}

/* For Firefox hover persistence */
@supports (-moz-appearance: none) {
    .custom-chat-scrollbar.custom-chat-scrollbar:hover {
        scrollbar-width: thin !important;
    }
}

@keyframes highlightFade {
    0% { background-color: color-mix(in srgb, var(--interactive-primary) 25%, transparent); }
    100% { background-color: transparent; }
}

.highlight-message {
    animation: highlightFade 2s ease-out forwards;
}
</style>
