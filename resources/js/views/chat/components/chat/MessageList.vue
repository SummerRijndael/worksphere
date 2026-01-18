<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from "vue";
import type { Chat, Message } from "@/types/models/chat";
import MessageBubble from "./MessageBubble.vue";
import { Icon } from "@/components/ui";

interface Props {
    messages: Message[];
    activeChat: Chat | null;
    currentUserPublicId: string;
    currentUserName: string;
    typingIndicator: string | null;
    isLoading: boolean;
    shouldShowDateDivider: (messages: Message[], index: number) => boolean;
    formatMessageDate: (date: string) => string;
}

const props = defineProps<Props>();
const emit = defineEmits<{
    scroll: [event: Event];
    reply: [message: Message];
    jumpToMessage: [messageId: string];
    scrollToBottom: [];
    retry: [message: Message];
}>();

const containerRef = ref<HTMLElement | null>(null);
const showScrollButton = ref(false);
const isCloaked = ref(true);
const typingRef = ref<HTMLElement | null>(null);

// Expose public methods/properties for parent
defineExpose({
    scrollTo: (options: ScrollToOptions) =>
        containerRef.value?.scrollTo(options),
    get scrollTop() {
        return containerRef.value?.scrollTop ?? 0;
    },
    set scrollTop(val: number) {
        if (containerRef.value) containerRef.value.scrollTop = val;
    },
    get scrollHeight() {
        return containerRef.value?.scrollHeight ?? 0;
    },
    get clientHeight() {
        return containerRef.value?.clientHeight ?? 0;
    },
});

// Watch active chat to cloak and reset scroll
let resizeObserver: ResizeObserver | null = null;

onMounted(() => {
    if (containerRef.value) {
        resizeObserver = new ResizeObserver(() => {
            // If we are currently cloaked (initializing), strongly enforce bottom position
            // This handles images loading or layout shifts causing the scroll to "drift" up
            if (isCloaked.value && containerRef.value) {
                containerRef.value.scrollTop = containerRef.value.scrollHeight;
            }
        });
        resizeObserver.observe(containerRef.value);
    }
});

onUnmounted(() => {
    if (resizeObserver) {
        resizeObserver.disconnect();
    }
});

watch(
    () => props.activeChat?.public_id,
    async () => {
        isCloaked.value = true;

        // Allow Vue to render the new list
        await nextTick();
        await nextTick();

        if (containerRef.value) {
            // Initial jump
            containerRef.value.scrollTop = containerRef.value.scrollHeight;

            // Wait a bit for any immediate layout shifts (like date dividers)
            setTimeout(() => {
                if (containerRef.value) {
                    containerRef.value.scrollTop =
                        containerRef.value.scrollHeight;
                }
                isCloaked.value = false;
            }, 100); // Slightly longer delay to be safe
        } else {
            isCloaked.value = false;
        }
    },
    { immediate: true }
);

// Watch messages length to scroll to bottom if we are already near bottom
watch(
    () => props.messages.length,
    async (newLen, oldLen) => {
        if (!containerRef.value) return;

        // Is this a "Load More" (pagination) event?
        // We detect this if newLen > oldLen BUT we were at the top, or if isLoading was true.
        // However, the parent handles the `isLoading` prop.
        // If we are loading older messages (pagination), we DO NOT want to scroll to bottom.
        // We want to maintain relative position.

        if (props.isLoading) {
            // This is likely pagination (or initial load, but initial load is handled by cloak)
            // For pagination, we want to hold position.
            // The parent (ChatPage) handles the data update.
            // We need to capture position BEFORE the update usually, but here we are reacting post-update.
            // It's better to rely on `isCloaked` for initial load.
            return;
        }

        // Check if we were near bottom (within 150px) before update
        const { scrollTop, scrollHeight, clientHeight } = containerRef.value;
        const wasNearBottom = scrollHeight - scrollTop - clientHeight < 200;

        await nextTick();

        // Use smooth scroll for new messages arriving (real-time or user sent)
        if (wasNearBottom || (newLen > oldLen && oldLen === 0)) {
            containerRef.value.scrollTo({
                top: containerRef.value.scrollHeight,
                behavior: "smooth",
            });
        }
    }
);

// Watch typing indicator
watch(
    () => props.typingIndicator,
    async (val) => {
        if (val && containerRef.value) {
            await nextTick();
            const { scrollTop, scrollHeight, clientHeight } =
                containerRef.value;
            const isNearBottom = scrollHeight - scrollTop - clientHeight < 200;

            if (isNearBottom) {
                containerRef.value.scrollTo({
                    top: containerRef.value.scrollHeight,
                    behavior: "smooth",
                });
            }
        }
    }
);
// ... (keep rest)

const handleReply = (message: Message) => {
    emit("reply", message);
};

const handleJumpToMessage = (messageId: string) => {
    emit("jumpToMessage", messageId);
};

const handleScroll = (event: Event) => {
    // Prevent firing scroll events (which trigger loadMore) while we are positioning the chat
    if (isCloaked.value) return;

    const target = event.target as HTMLElement;
    const distanceFromBottom =
        target.scrollHeight - target.scrollTop - target.clientHeight;
    const distanceFromTop = target.scrollTop;

    // Show button if we are more than 500px away from bottom
    showScrollButton.value = distanceFromBottom > 500;

    if (import.meta.env.DEV) {
        // Throttle logs slightly to avoid freezing console
        if (Math.random() < 0.1 || distanceFromTop < 100) {
            console.log(
                `[Scroll] Top: ${Math.round(distanceFromTop)}, Height: ${
                    target.scrollHeight
                }, Client: ${target.clientHeight}, Trigger limit: 20`
            );
        }
    }

    emit("scroll", event);
};

const jumpToLatest = () => {
    emit("scrollToBottom");
};
</script>

<template>
    <div class="relative flex-1 flex flex-col min-h-0 overflow-hidden">
        <div
            ref="containerRef"
            class="flex-1 overflow-y-auto px-4 py-6 space-y-1 flex flex-col"
            :class="{ 'opacity-0': isCloaked, 'opacity-100': !isCloaked }"
            @scroll="handleScroll"
        >
            <!-- Loading indicator (Infinite Scroll) -->
            <div v-if="isLoading" class="flex justify-center py-2">
                <div
                    class="w-6 h-6 border-2 border-[var(--interactive-primary)] border-t-transparent rounded-full animate-spin"
                />
            </div>

            <div
                v-if="activeChat && messages.length"
                :key="activeChat.public_id || 'empty'"
                class="space-y-1 mt-auto"
            >
                <template
                    v-for="(msg, index) in messages"
                    :key="msg.tempId || msg.id"
                >
                    <!-- Date Divider -->
                    <div
                        v-if="shouldShowDateDivider(messages, index)"
                        class="flex items-center justify-center gap-3 py-6"
                    >
                        <div class="h-px w-16 bg-[var(--border-default)]" />
                        <span
                            class="text-xs text-[var(--text-tertiary)] font-medium uppercase tracking-wider"
                        >
                            {{ formatMessageDate(msg.created_at) }}
                        </span>
                        <div class="h-px w-16 bg-[var(--border-default)]" />
                    </div>

                    <!-- Message Bubble -->
                    <MessageBubble
                        :data-message-id="msg.id"
                        :message="msg"
                        :is-mine="
                            !!(
                                msg.user_public_id === currentUserPublicId ||
                                msg.pending
                            )
                        "
                        :show-avatar="true"
                        @reply="handleReply(msg)"
                        @jump-to-reply="handleJumpToMessage"
                        @retry="emit('retry', msg)"
                    />
                </template>
            </div>

            <!-- Empty State: Has chat but no messages -->
            <div
                v-else-if="activeChat && !isLoading"
                class="flex-1 flex items-center justify-center h-full min-h-[50vh]"
            >
                <div class="text-center text-[var(--text-secondary)]">
                    <div class="text-3xl mb-3 opacity-50">
                        <Icon name="MessageSquare" size="48" />
                    </div>
                    <div>No messages yet. Say hello!</div>
                </div>
            </div>

            <!-- Empty State: No chat selected -->
            <div
                v-else-if="!activeChat"
                class="flex-1 flex items-center justify-center h-full min-h-[50vh]"
            >
                <div
                    class="text-center p-8 rounded-2xl bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-lg max-w-sm"
                >
                    <div class="text-5xl mb-4">💬</div>
                    <div
                        class="text-lg font-semibold text-[var(--text-primary)] mb-2"
                    >
                        Welcome back!
                    </div>
                    <div class="text-[var(--text-secondary)]">
                        Select a chat from the sidebar to start messaging.
                    </div>
                </div>
            </div>
        </div>

        <!-- Jump to Bottom FAB -->
        <transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 translate-y-4"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-4"
        >
            <button
                v-if="showScrollButton"
                @click="jumpToLatest"
                class="absolute bottom-6 right-6 p-3 rounded-full bg-[var(--surface-elevated)] text-[var(--interactive-primary)] shadow-lg border border-[var(--border-default)] hover:bg-[var(--surface-tertiary)] transition-all z-10"
                title="Jump to latest"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="lucide lucide-arrow-down"
                >
                    <path d="M12 5v14" />
                    <path d="m19 12-7 7-7-7" />
                </svg>
            </button>
        </transition>
    </div>
</template>
