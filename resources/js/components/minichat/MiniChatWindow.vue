<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from "vue";
import { useRouter } from "vue-router";
import { useMiniChatStore, type MiniChatWindow } from "@/stores/minichat";
import { useChatStore } from "@/stores/chat";
import { useAuthStore } from "@/stores/auth";
import { useThemeStore } from "@/stores/theme";
import { useChatRealtime } from "@/composables/useChatRealtime";
import { usePresence } from "@/composables/usePresence.ts";
import { useAvatar } from "@/composables/useAvatar";
import { useToast } from "@/composables/useToast";
import { Icon, Avatar } from "@/components/ui";
import type { Message, PendingFile } from "@/types/models/chat";
import { chatService } from "@/services/chat.service";
import data from "@emoji-mart/data";
import { Picker } from "emoji-mart";
import MiniChatMessageBubble from "./MiniChatMessageBubble.vue";
import GiphyPicker from "@/views/chat/components/chat/GiphyPicker.vue";
import { useMention } from "@/composables/useMention";

const props = defineProps<{
    window: MiniChatWindow;
}>();

const router = useRouter();
const miniChatStore = useMiniChatStore();
const chatStore = useChatStore();
const authStore = useAuthStore();
const themeStore = useThemeStore();
const { presenceUsers } = usePresence({ manageLifecycle: false });
const chatRealtime = useChatRealtime();
const avatar = useAvatar();
const toast = useToast();



// Instance Identity for Debugging
const instanceId = Math.random().toString(36).substring(7);

// Debug
const showDebug = ref(false);
const debugLogs = ref<{ time: string; type: string; message: string }[]>([]);

function addDebugLog(type: string, message: string) {
    const time = new Date().toLocaleTimeString();
    debugLogs.value.unshift({ time, type, message });
    if (debugLogs.value.length > 50) debugLogs.value.pop();
}

// Watchers moved to bottom to avoid initialization errors

// State
const messageInput = ref("");

const messagesRef = ref<HTMLElement | null>(null);
const textareaRef = ref<HTMLTextAreaElement | null>(null);
const fileInputRef = ref<HTMLInputElement | null>(null);
const emojiMountRef = ref<HTMLElement | null>(null);

const { attach: attachMention } = useMention(textareaRef, computed(() => props.window.chat.public_id), {
    onSelect: (item: any) => {
        nextTick(() => {
            if (textareaRef.value) {
                messageInput.value = textareaRef.value.value;
                textareaRef.value.focus();
            }
        });
    }
});
const isSending = ref(false);
const isLoadingMore = ref(false);
const showScrollButton = ref(false);
const isJumping = ref(false);
const isDragging = ref(false);
const showEmoji = ref(false);
const showGiphy = ref(false);
const replyingTo = ref<Message | null>(null);
const pendingFiles = ref<PendingFile[]>([]);
let pickerInstance: any = null;
// typingUser ref removed
// Removed local typing/channel state in favor of useChatRealtime
// typingTimeout removed (unused)
let lastTypingSent = 0;

const currentUserPublicId = computed(() => authStore.user?.public_id || "");

const messages = computed(() => {
    return chatStore.messagesByChat.get(props.window.chatId) || [];
});

const chatTitle = computed(() => {
    const chat = props.window.chat;
    if (chat.name) return chat.name;
    if (chat.type === "dm" && chat.participants.length) {
        const currentId = currentUserPublicId.value;
        // Try to find OTHER user
        const other = chat.participants.find((p) => p.public_id !== currentId);
        // If other found, return name.
        // If not found (e.g. self chat, or currentId mismatch), fallback to first participant or 'Chat'
        // Also handle case where currentId is empty string (might match no one or everyone depending on logic)
        return other?.name || chat.participants[0]?.name || "Chat";
    }
    return "Group Chat";
});

const chatAvatarData = computed(() => {
    const chat = props.window.chat;
    return avatar.resolveChatAvatar(chat, currentUserPublicId.value);
});

// Get other participant's online status for DM chats
const otherParticipantStatus = computed(() => {
    const chat = props.window.chat;
    if (chat.type !== "dm") return null;

    const other = chat.participants.find(
        (p) => p.public_id !== currentUserPublicId.value
    );
    if (!other) return null;

    // Check presence map
    const presence = presenceUsers.value.get(other.public_id);
    return presence?.status || "offline";
});

const canSend = computed(() => {
    return (
        (messageInput.value.trim().length > 0 ||
            pendingFiles.value.length > 0) &&
        !isSending.value
    );
});

// Local typing indicator (V1 style isolation)
// Local typing indicator (aligned with Full Chat)
const typingIndicator = computed(() => {
    const typerIds = chatStore.getTypingUsers(props.window.chatId);
    if (!typerIds || typerIds.length === 0) return null;

    const names = typerIds.map(id => {
        const p = props.window.chat.participants.find(user => user.public_id === id);
        return p ? (p.name.split(' ')[0]) : 'Someone'; 
    });

    if (names.length === 1) return `${names[0]} is typing...`;
    if (names.length === 2) return `${names[0]} and ${names[1]} are typing...`;
    return `${names.length} people are typing...`;
});

// Watchers for Debugging (Defined here to ensure dependencies are initialized)
watch(
    () => messages.value.length,
    (newLen, oldLen) => {
        if (newLen > oldLen) {
            const lastMsg = messages.value[messages.value.length - 1];
            addDebugLog(
                "MESSAGE",
                `Received: ${lastMsg.id} from ${lastMsg.user_name}`
            );
        }
    }
);

watch(typingIndicator, (newVal) => {
    if (newVal) {
        addDebugLog("TYPING", `Typing started: ${newVal}`);
        console.log(`[MiniChat:${instanceId}] Typing started, scrolling to bottom`);
        nextTick(() => scrollToBottom());
    } else {
        addDebugLog("TYPING", "Typing stopped");
    }
});

// Fetch messages on mount
onMounted(async () => {
    if (messages.value.length === 0) {
        await chatStore.fetchMessages(props.window.chatId);
    }
    await nextTick();
    scrollToBottom();
    chatStore.markAsRead(props.window.chatId);

    document.addEventListener("click", handleClickOutside);
    document.addEventListener("keydown", handleEsc);
    attachMention();
});

onUnmounted(() => {
    document.removeEventListener("click", handleClickOutside);
    document.removeEventListener("keydown", handleEsc);
    // Cleanup pending files
    pendingFiles.value.forEach((f) => {
        if (f.url) URL.revokeObjectURL(f.url);
    });
});

// Watch for new messages
watch(
    messages,
    async () => {
        await nextTick();
        scrollToBottom();
    },
    { deep: true }
);

function scrollToBottom() {
    if (messagesRef.value) {
        messagesRef.value.scrollTop = messagesRef.value.scrollHeight;
    }
}

async function handleSend() {
    if (!canSend.value) return;

    const content = messageInput.value.trim();
    messageInput.value = "";
    isSending.value = true;

    try {
        if (pendingFiles.value.length > 0) {
            // Send with files
            const files = pendingFiles.value.map((pf) => pf.file);
            await chatStore.uploadMessage(
                props.window.chatId,
                files,
                content,
                replyingTo.value?.id
            );
            pendingFiles.value.forEach((f) => {
                if (f.url) URL.revokeObjectURL(f.url);
            });
            pendingFiles.value = [];
        } else {
            // Send text only
            await chatStore.sendMessage(
                props.window.chatId,
                content,
                replyingTo.value?.id
            );
        }

        replyingTo.value = null;
    } catch (error: any) {
        console.error('Failed to send message:', error);
        toast.error('Send Failed', error.message || 'Failed to send message');
    } finally {
        isSending.value = false;
    }
}

async function sendGif(gif: any) {
    if (!props.window.chatId) return;
    
    const metadata = {
        giphy: {
            id: gif.id,
            url: gif.url,
            title: gif.title,
            width: gif.width,
            height: gif.height,
            preview: gif.preview
        }
    };
    
    // Send empty content but with metadata
    // Close picker first
    showGiphy.value = false;
    
    try {
        await chatStore.sendMessage(props.window.chatId, '', replyingTo.value?.id, metadata);
        scrollToBottom();
    } catch (error: any) {
        console.error('Failed to send GIF:', error);
        toast.error('Send Failed', error.message || 'Failed to send GIF');
    }
}

function handleKeydown(e: KeyboardEvent) {
    if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        handleSend();
    } else if (e.key === "Escape") {
        if (showEmoji.value) {
            showEmoji.value = false;
        } else {
            miniChatStore.closeChatWindow(props.window.chatId);
        }
    }
}

// Send typing indicator with debounce
function handleInputChange() {
    const now = Date.now();
    // Only send typing indicator every 2 seconds
    // Typing throttle logic
    if (now - lastTypingSent > 2000) {
        lastTypingSent = now;
        chatService.sendTyping(props.window.chatId);
    }
}

// Reply handling
function handleReply(msg: Message) {
    replyingTo.value = msg;
    textareaRef.value?.focus();
}

function cancelReply() {
    replyingTo.value = null;
}

// ============================================================================
// Scroll & Message Handling
// ============================================================================

const handleScroll = async (event: Event) => {
    const target = event.target as HTMLElement;
    const distanceFromBottom =
        target.scrollHeight - target.scrollTop - target.clientHeight;

    // Toggle Scroll Button
    showScrollButton.value = distanceFromBottom > 200;

    // Infinite Scroll: If scrolled to top and not already loading
    if (target.scrollTop < 50 && !isLoadingMore.value) {
        // Check if we have more messages to load
        const hasMore = chatStore.hasMoreMessages.get(props.window.chatId);
        if (!hasMore && messages.value.length > 0) return;

        isLoadingMore.value = true;

        // Capture current scroll height to maintain position
        const oldScrollHeight = target.scrollHeight;
        const oldScrollTop = target.scrollTop;

        try {
            const oldestMessage = messages.value[0];
            const beforeId = oldestMessage ? oldestMessage.id : undefined;

            if (beforeId) {
                await chatStore.fetchMessages(props.window.chatId, {
                    before: beforeId,
                });

                // Restore scroll position
                await nextTick();
                const newScrollHeight = target.scrollHeight;
                target.scrollTop =
                    newScrollHeight - oldScrollHeight + oldScrollTop;
            }
        } catch (error) {
            console.error("Failed to load older messages:", error);
        } finally {
            isLoadingMore.value = false;
        }
    }
};

const jumpToMessage = async (messageId: string) => {
    let el = messagesRef.value?.querySelector(
        `[data-message-id="${messageId}"]`
    );

    if (el) {
        highlightElement(el);
        return;
    }

    if (isJumping.value) return;

    isJumping.value = true;
    try {
        const result = await chatService.messagesAround(
            props.window.chatId,
            messageId
        );

        // Replace current messages with fetched context
        chatStore.setMessagesForChat(props.window.chatId, result.messages);

        await nextTick();

        // Now find and scroll to target
        el = messagesRef.value?.querySelector(
            `[data-message-id="${messageId}"]`
        );
        if (el) {
            highlightElement(el);
        }
    } catch (error) {
        console.error("Failed to jump to message:", error);
    } finally {
        isJumping.value = false;
    }
};

const highlightElement = (el: Element) => {
    el.scrollIntoView({ behavior: "smooth", block: "center" });
    el.classList.add("highlight-message");
    setTimeout(() => el.classList.remove("highlight-message"), 2000);
};

const handleRetry = async (messageId: string) => {
    try {
        await chatStore.retryMessage(props.window.chatId, messageId);
        scrollToBottom();
    } catch (error) {
        console.error("Failed to retry message:", error);
        toast.error("Retry Failed", "Could not resend message");
    }
};

// ============================================================================
// File Handling
// ============================================================================
const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
const MAX_TOTAL_SIZE = 10 * 1024 * 1024; // 10MB
const MAX_FILES = 10;

function openFilePicker() {
    fileInputRef.value?.click();
}

function handleFileSelect(e: Event) {
    const input = e.target as HTMLInputElement;
    if (input.files) {
        const newFiles = Array.from(input.files);
        const currentTotalSize = pendingFiles.value.reduce((acc, f) => acc + f.size, 0);
        
        if (pendingFiles.value.length + newFiles.length > MAX_FILES) {
             toast.error('Limit Exceeded', `You can only upload up to ${MAX_FILES} files at a time.`);
             input.value = "";
             return;
        }

        let newBatchSize = 0;
        newFiles.forEach(f => newBatchSize += f.size);

        if (currentTotalSize + newBatchSize > MAX_TOTAL_SIZE) {
            toast.error('Limit Exceeded', 'Total upload size cannot exceed 10MB.');
            input.value = "";
            return;
        }

        newFiles.forEach((file) => {
            if (file.size > MAX_FILE_SIZE) {
                toast.error('File Too Large', `${file.name} exceeds the 5MB limit.`);
                return;
            }
            const isImage = file.type.startsWith("image/");
            pendingFiles.value.push({
                file,
                name: file.name,
                size: file.size,
                isImage,
                url: isImage ? URL.createObjectURL(file) : undefined,
            });
        });
        input.value = "";
    }
}

function handlePaste(e: ClipboardEvent) {
    if (e.clipboardData && e.clipboardData.files.length > 0) {
        e.preventDefault(); 
        const newFiles = Array.from(e.clipboardData.files);
        const currentTotalSize = pendingFiles.value.reduce((acc, f) => acc + f.size, 0);

        if (pendingFiles.value.length + newFiles.length > MAX_FILES) {
             toast.error('Limit Exceeded', `You can only upload up to ${MAX_FILES} files at a time.`);
             return;
        }

        let newBatchSize = 0;
        newFiles.forEach(f => newBatchSize += f.size);

        if (currentTotalSize + newBatchSize > MAX_TOTAL_SIZE) {
            toast.error('Limit Exceeded', 'Total upload size cannot exceed 10MB.');
            return;
        }

        newFiles.forEach((file) => {
            if (file.size > MAX_FILE_SIZE) {
                toast.error('File Too Large', `${file.name} exceeds the 5MB limit.`);
                return;
            }
            const isImage = file.type.startsWith("image/");
            pendingFiles.value.push({
                file,
                name: file.name,
                size: file.size,
                isImage,
                url: isImage ? URL.createObjectURL(file) : undefined,
            });
        });
    }
}

function removeFile(index: number) {
    const file = pendingFiles.value[index];
    if (file.url) URL.revokeObjectURL(file.url);
    pendingFiles.value.splice(index, 1);
}

// Emoji handling
async function toggleGiphy() {
    showGiphy.value = !showGiphy.value;
    if (showGiphy.value) {
        showEmoji.value = false;
    }
}

async function toggleEmoji() {
    showEmoji.value = !showEmoji.value;
    if (showEmoji.value) {
        showGiphy.value = false;
    }
    await nextTick();

    if (showEmoji.value && !pickerInstance && emojiMountRef.value) {
        pickerInstance = new Picker({
            data,
            onEmojiSelect: (emoji: any) => {
                insertEmoji(emoji.native);
            },
            previewPosition: "none",
            theme: themeStore.isDark ? "dark" : "light",
            perLine: 8,
            maxHeight: 250,
            searchPosition: "static",
        });
        emojiMountRef.value.appendChild(pickerInstance);
    }
}

function insertEmoji(emoji: string) {
    messageInput.value += emoji;
    showEmoji.value = false;
}

function handleClickOutside(e: MouseEvent) {
    const target = e.target as HTMLElement;
    if (
        showEmoji.value &&
        emojiMountRef.value &&
        !emojiMountRef.value.contains(target)
    ) {
        const emojiBtn = target.closest(".minichat-emoji-btn");
        if (!emojiBtn) showEmoji.value = false;
    }
}

function handleEsc(e: KeyboardEvent) {
    if (e.key === "Escape" && showEmoji.value) {
        showEmoji.value = false;
    }
}

// Subscribe to channel locally (V1 style)
onMounted(async () => {
    addDebugLog("SYSTEM", `Mounting chat: ${props.window.chatId}`);

    // Subscribe using shared realtime service
    chatRealtime.subscribeToChat(props.window.chatId, props.window.chat.type);

    scrollToBottom();

    // Mark as read if window is active
    if (!props.window.isMinimized) {
        await chatService.markAsRead(props.window.chatId);
    }
});

onUnmounted(() => {
    addDebugLog("SYSTEM", "Unmounting chat");
    // Unsubscribe using shared realtime service
    chatRealtime.unsubscribeFromChat(
        props.window.chatId,
        props.window.chat.type
    );
});

// Window controls
function handleMinimize() {
    miniChatStore.minimizeChatWindow(props.window.chatId);
}

function handleClose() {
    miniChatStore.closeChatWindow(props.window.chatId);
}

function handleOpenFull() {
    router.push(`/chat/${props.window.chatId}`);
    miniChatStore.closeAllWindows();
}

function handleFocus() {
    miniChatStore.bringToFront(props.window.chatId);
}

// Drag handling
const startPos = ref({ right: 0, bottom: 0 });
const startMouse = ref({ x: 0, y: 0 });

function handleDragStart(e: MouseEvent) {
    if (miniChatStore.isDocked) return;

    isDragging.value = true;
    startPos.value = {
        right: props.window.position.right,
        bottom: props.window.position.bottom,
    };
    startMouse.value = { x: e.clientX, y: e.clientY };

    document.addEventListener("mousemove", handleDragMove);
    document.addEventListener("mouseup", handleDragEnd);
}

function handleDragMove(e: MouseEvent) {
    if (!isDragging.value) return;

    const deltaX = startMouse.value.x - e.clientX;
    const deltaY = startMouse.value.y - e.clientY;

    const newRight = Math.max(
        20,
        Math.min(window.innerWidth - 360, startPos.value.right + deltaX)
    );
    const newBottom = Math.max(
        20,
        Math.min(window.innerHeight - 480, startPos.value.bottom + deltaY)
    );

    miniChatStore.updateWindowPosition(props.window.chatId, {
        right: newRight,
        bottom: newBottom,
    });
}

function handleDragEnd() {
    isDragging.value = false;
    document.removeEventListener("mousemove", handleDragMove);
    document.removeEventListener("mouseup", handleDragEnd);
}
function isOwnMessage(msg: Message): boolean {
    return msg.user_public_id === currentUserPublicId.value;
}

</script>

<template>
    <div
        class="minichat-window"
        :class="[
            {
                'is-minimized': window.isMinimized,
                'is-dragging': isDragging,
            },
            `chat-theme-${themeStore.chatTheme}`,
        ]"
        :style="{
            right: `${window.position.right}px`,
            bottom: `${window.position.bottom}px`,
            zIndex: window.zIndex,
        }"
        @mousedown="handleFocus"
    >
        <!-- Debug Console -->
        <div
            v-if="showDebug"
            class="bg-black/90 text-[10px] text-green-400 p-2 h-32 overflow-y-auto font-mono border-b border-gray-700 flex flex-col gap-1 tracking-tight leading-3"
        >
            <div
                class="flex justify-between items-center text-gray-500 border-b border-gray-800 pb-1 mb-1"
            >
                <span>DEBUG LOG</span>
                <button @click="showDebug = false" class="hover:text-white">
                    x
                </button>
            </div>

            <!-- Connection Status -->
            <div class="border-b border-gray-800 pb-2 mb-1">
                <div class="flex justify-between mb-1">
                    <span>STATUS: <span :class="chatRealtime.isConnected.value ? 'text-green-400' : 'text-red-400'">{{ chatRealtime.connectionState.value }}</span></span>
                    <span>UID: {{ currentUserPublicId.substring(0, 8) }}</span>
                </div>
                <div class="flex justify-between mb-1 text-[9px] text-gray-500">
                     <span>INST: {{ instanceId }}</span>
                     <span>VIS: {{ !!typingIndicator }}</span>
                </div>
                <div class="text-gray-500">
                    <div class="mb-1">Subscriptions ({{ chatRealtime.subscribedChannels.value.size }}):</div>
                    <div class="break-all text-[9px] leading-tight text-gray-600">
                        {{ Array.from(chatRealtime.subscribedChannels.value).join(', ') }}
                    </div>
                </div>
            </div>

            <div v-for="(log, i) in debugLogs" :key="i" class="break-all border-b border-gray-900/50 pb-0.5 mb-0.5">
                <span class="text-gray-500">[{{ log.time }}]</span>
                <span
                    class="font-bold"
                    :class="
                        log.type === 'TYPING'
                            ? 'text-yellow-400'
                            : 'text-blue-400'
                    "
                    >[{{ log.type }}]</span
                >
                {{ log.message }}
            </div>
        </div>

        <!-- Header -->
        <div
            class="minichat-window-header"
            :class="{ '!cursor-default': miniChatStore.isDocked }"
            @mousedown.prevent="handleDragStart"
        >
            <div class="relative shrink-0">
                <Avatar
                    :src="chatAvatarData.url"
                    :alt="chatTitle"
                    :fallback="chatAvatarData.initials"
                    size="sm"
                    :status="otherParticipantStatus"
                    variant="ring"
                />
            </div>
            <div class="minichat-window-info">
                <span class="minichat-window-title">{{ chatTitle }}</span>
            </div>
            <div class="minichat-window-actions">
                <button
                    class="minichat-window-action"
                    title="Open full page"
                    @click.stop="handleOpenFull"
                >
                    <Icon name="Maximize2" :size="14" />
                </button>
                <button
                    class="minichat-window-action"
                    title="Minimize"
                    @click.stop="handleMinimize"
                >
                    <Icon name="Minus" :size="14" />
                </button>
                <button
                    class="minichat-window-action minichat-window-close"
                    title="Close"
                    @click.stop="handleClose"
                >
                    <Icon name="X" :size="14" />
                </button>
            </div>
        </div>

        <!-- Messages -->
        <div
            ref="messagesRef"
            class="minichat-window-messages chat-main-area"
            @scroll="handleScroll"
        >
            <!-- Loading Indicator -->
            <div v-if="isLoadingMore" class="flex justify-center py-2 shrink-0">
                <div
                    class="w-5 h-5 border-2 border-[var(--interactive-primary)] border-t-transparent rounded-full animate-spin"
                />
            </div>

            <TransitionGroup name="message-fade">
                <div
                    v-for="msg in messages"
                    :key="msg.tempId || msg.id"
                    class="minichat-message-container"
                    :class="{ 'is-own': isOwnMessage(msg) }"
                    :data-message-id="msg.id"
                >
                    <!-- System Message -->
                    <div v-if="msg.type === 'system'" class="flex justify-center my-2 px-4">
                         <span class="text-xs text-[var(--text-tertiary)] text-center italic leading-tight">
                             {{ msg.content }}
                         </span>
                    </div>

                    <!-- User Message -->
                    <MiniChatMessageBubble 
                        v-else 
                        :message="msg" 
                        :is-mine="isOwnMessage(msg)"
                        @reply="handleReply"
                        @jump="jumpToMessage" 
                        @retry="handleRetry"
                    />
                </div>
            </TransitionGroup>



            <div v-if="messages.length === 0" class="minichat-window-empty">
                <Icon name="MessageSquare" :size="24" />
                <p>No messages yet</p>
            </div>

            <!-- Jump to Bottom FAB -->
            <Transition name="fade">
                <button
                    v-if="showScrollButton"
                    @click="scrollToBottom"
                    class="minichat-scroll-btn"
                    title="Jump to latest"
                >
                    <Icon name="ArrowDown" :size="16" />
                </button>
            </Transition>
        </div>

        <!-- Typing Indicator (Fixed above composer) -->
        <div
            v-if="typingIndicator"
            class="flex items-center gap-2 px-3 text-xs text-[var(--text-muted)] transition-all duration-300 w-full"
            style="background: transparent !important;"
        >
            <div
                class="flex space-x-1 p-2 bg-[var(--surface-elevated)] rounded-2xl shadow-sm border border-[var(--border-default)]"
            >
                <div
                    class="w-1 h-1 bg-[var(--text-tertiary)] rounded-full animate-bounce [animation-delay:-0.3s]"
                ></div>
                <div
                    class="w-1 h-1 bg-[var(--text-tertiary)] rounded-full animate-bounce [animation-delay:-0.15s]"
                ></div>
                <div
                    class="w-1 h-1 bg-[var(--text-tertiary)] rounded-full animate-bounce"
                ></div>
            </div>
            <span class="animate-pulse">{{ typingIndicator }}</span>
        </div>

        <!-- Reply Preview -->
        <div v-if="replyingTo" class="minichat-reply-preview">
            <div class="minichat-reply-content">
                <span class="minichat-reply-label"
                    >Replying to {{ replyingTo.user_name }}</span
                >
                <span class="minichat-reply-text">{{
                    replyingTo.content?.slice(0, 50)
                }}</span>
            </div>
            <button class="minichat-reply-cancel" @click="cancelReply">
                <Icon name="X" :size="14" />
            </button>
        </div>

        <!-- Pending Files -->
        <div v-if="pendingFiles.length" class="minichat-pending-files">
            <div
                v-for="(file, idx) in pendingFiles"
                :key="idx"
                class="minichat-pending-file"
            >
                <img
                    v-if="file.isImage && file.url"
                    :src="file.url"
                    class="minichat-pending-thumb"
                />
                <Icon v-else name="FileText" :size="16" />
                <span class="minichat-pending-name">{{ file.name }}</span>
                <button
                    class="minichat-pending-remove"
                    @click="removeFile(idx)"
                >
                    <Icon name="X" :size="12" />
                </button>
            </div>
        </div>

        <!-- Composer -->
        <div class="minichat-window-composer">
            <!-- Attach button -->
            <button
                class="minichat-composer-btn"
                title="Attach file"
                @click="openFilePicker"
            >
                <Icon name="Paperclip" :size="18" />
            </button>
            <input
                ref="fileInputRef"
                type="file"
                multiple
                accept="image/*,.pdf,.doc,.docx,.txt,.zip"
                class="hidden"
                @change="handleFileSelect"
            />

            <!-- Emoji button -->
            <button
                class="minichat-composer-btn minichat-emoji-btn"
                :class="{ 'is-active': showEmoji }"
                title="Emoji"
                @click.stop="toggleEmoji"
            >
                <Icon name="Smile" :size="18" />
            </button>

            <!-- GIF button -->
            <button
                class="minichat-composer-btn"
                :class="{ 'is-active': showGiphy }"
                title="GIF"
                @click.stop="toggleGiphy"
            >
                <div class="font-bold text-[8px] leading-none border border-current rounded px-0.5 py-0.5">GIF</div>
            </button>

            <!-- Emoji Picker -->
            <div
                v-show="showEmoji"
                ref="emojiMountRef"
                class="minichat-emoji-picker"
            />

            <!-- Giphy Picker -->
            <div
                v-if="showGiphy"
                class="minichat-emoji-picker"
            >
                <GiphyPicker compact @select="sendGif" />
            </div>

            <!-- Input -->
            <textarea
                ref="textareaRef"
                v-model="messageInput"
                rows="1"
                placeholder="Type a message..."
                class="minichat-input"
                @keydown="handleKeydown"
                @input="handleInputChange"
                @paste="handlePaste"
            />

            <!-- Send button -->
            <button
                class="minichat-send-btn"
                :disabled="!canSend"
                @click="handleSend"
            >
                <Icon
                    v-if="isSending"
                    name="Loader2"
                    :size="16"
                    class="animate-spin"
                />
                <Icon v-else name="Send" :size="16" />
            </button>
        </div>
    </div>
</template>

<style scoped>
.minichat-window {
    position: fixed;
    width: 340px;
    height: 480px;
    background: var(--surface-elevated);
    border: 1px solid var(--border-default);
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: slideUp 0.25s ease-out;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.minichat-window-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    background: var(--surface-secondary);
    border-bottom: 1px solid var(--border-default);
    cursor: move;
    user-select: none;
}

.minichat-window-info {
    flex: 1;
    min-width: 0;
}

.minichat-window-title {
    font-weight: 600;
    font-size: 14px;
    color: var(--text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: block;
}

.minichat-window-typing {
    font-size: 11px;
    color: var(--interactive-primary);
    font-weight: 500;
    animation: typingPulse 1.5s ease-in-out infinite;
}

@keyframes typingPulse {
    0%,
    100% {
        opacity: 0.7;
    }
    50% {
        opacity: 1;
    }
}

.minichat-window-actions {
    display: flex;
    gap: 2px;
}

.minichat-window-action {
    width: 26px;
    height: 26px;
    border-radius: 6px;
    border: none;
    background: transparent;
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}

.minichat-window-action:hover {
    background: var(--surface-tertiary);
    color: var(--text-primary);
}

.minichat-window-close:hover {
    background: var(--color-error);
    color: white;
}

.minichat-window-messages {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.minichat-window-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: var(--text-muted);
}

.minichat-window-empty p {
    font-size: 13px;
}

/* Messages */
.minichat-message-container {
    display: flex;
    flex-direction: column;
    width: 100%;
}

.minichat-message-container.is-own {
    align-items: flex-end;
}

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
    word-break: break-word;
    margin: 0;
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

/* Attachments */
.minichat-attachments {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 4px;
}

.minichat-attachment-img {
    max-width: 100%;
    max-height: 150px;
    border-radius: 8px;
    cursor: pointer;
    transition: transform 0.15s ease;
}

.minichat-attachment-img:hover {
    transform: scale(1.02);
}

.minichat-attachment-file {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    background: var(--surface-secondary);
    border-radius: 8px;
    font-size: 11px;
    color: var(--text-secondary);
    text-decoration: none;
}

.minichat-attachment-file:hover {
    background: var(--surface-tertiary);
}

.minichat-file-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 120px;
}

.minichat-file-size {
    font-size: 9px;
    opacity: 0.7;
    flex-shrink: 0;
}

/* Reply Preview */
.minichat-reply-preview {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: var(--surface-tertiary);
    border-left: 2px solid var(--interactive-primary);
}

.minichat-reply-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.minichat-reply-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--interactive-primary);
}

.minichat-reply-text {
    font-size: 12px;
    color: var(--text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.minichat-reply-cancel {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.minichat-reply-cancel:hover {
    background: var(--surface-secondary);
}

/* Pending Files */
.minichat-pending-files {
    display: flex;
    gap: 8px;
    padding: 8px 12px;
    overflow-x: auto;
    background: var(--surface-secondary);
}

.minichat-pending-file {
    position: relative;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 8px;
    background: var(--surface-tertiary);
    border-radius: 8px;
    font-size: 11px;
    flex-shrink: 0;
}

.minichat-pending-thumb {
    width: 32px;
    height: 32px;
    object-fit: cover;
    border-radius: 4px;
}

.minichat-pending-name {
    max-width: 80px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--text-secondary);
}

.minichat-pending-remove {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: none;
    background: var(--color-error);
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
}

/* Composer */
.minichat-window-composer {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 12px;
    border-top: 1px solid var(--border-default);
    background: var(--surface-secondary);
    position: relative;
}

.minichat-composer-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
    flex-shrink: 0;
}

.minichat-composer-btn:hover,
.minichat-composer-btn.is-active {
    background: var(--surface-tertiary);
    color: var(--text-primary);
}

.minichat-emoji-picker {
    position: absolute;
    bottom: 100%;
    left: 8px;
    margin-bottom: 4px;
    z-index: 100;
    /* Removed max-height/overflow to prevent double scrollbars */
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

:deep(em-emoji-picker) {
    height: 350px;
    --em-height: 250px;
}

.minichat-input {
    flex: 1;
    padding: 8px 12px;
    border-radius: 18px;
    border: 1px solid var(--border-default);
    background: var(--surface-elevated);
    color: var(--text-primary);
    font-size: 13px;
    outline: none;
    resize: none;
    min-height: 36px;
    max-height: 80px;
    transition: all 0.15s ease;
}

.minichat-input:focus {
    border-color: var(--interactive-primary);
}

.minichat-input::placeholder {
    color: var(--text-muted);
}

.minichat-send-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: var(--interactive-primary);
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
    flex-shrink: 0;
}

.minichat-send-btn:hover:not(:disabled) {
    opacity: 0.9;
    transform: scale(1.05);
}

.minichat-send-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.hidden {
    display: none;
}

/* Message animations */
.message-fade-enter-active {
    transition: all 0.3s ease;
}

.message-fade-enter-from {
    opacity: 0;
    transform: translateY(10px);
}
.minichat-scroll-btn {
    /* Centered at bottom of scrollable area */
    position: sticky;
    bottom: 1px;
    align-self: center; /* If parent is flex col */
    margin: 0 auto; /* If parent is block */

    width: 32px;
    height: 32px;
    min-width: 32px; /* Prevent squishing */
    min-height: 32px;
    flex-shrink: 0;

    border-radius: 50%;
    background: var(--surface-elevated);
    border: 1px solid var(--border-default);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    color: var(--interactive-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 10;
    transition: all 0.2s ease;
}

.minichat-scroll-btn:hover {
    background: var(--surface-tertiary);
}

/* Message highlight animation */
:deep(.highlight-message) {
    animation: highlight-pulse 2s ease-out;
}

@keyframes highlight-pulse {
    0%,
    100% {
        background-color: transparent;
    }
    50% {
        background-color: var(--interactive-primary);
        opacity: 0.2;
    }
}

/* Fade transition */
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
