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
import { useAudioClipRecorder } from "@/composables/useAudioClipRecorder";
import { buildUploadMediaMetadata } from "@/utils/mediaDuration";

const props = defineProps<{
    window: MiniChatWindow;
}>();

interface RecordedAudioDraft {
    file: File;
    durationSeconds: number;
    mimeType: string;
    rawMimeType?: string;
    url: string;
    fallbackUrl?: string;
}

const router = useRouter();
const miniChatStore = useMiniChatStore();
const chatStore = useChatStore();
const authStore = useAuthStore();
const themeStore = useThemeStore();
const { presenceUsers } = usePresence({ manageLifecycle: false });
const chatRealtime = useChatRealtime();
const avatar = useAvatar();
const toast = useToast();

// Video call
import { useVideoCall } from "@/composables/useVideoCall";
import { useVideoCallStore } from "@/stores/videocall";
const videoCall = useVideoCall();
const videoCallStore = useVideoCallStore();

function handleStartCall(callType: "video" | "audio") {
    const chat = props.window.chat;
    const other =
        chat.type === "dm"
            ? chat.participants.find(
                  (p: any) => p.public_id !== currentUserPublicId.value,
              )
            : {
                  public_id: "group",
                  name: chat.name || "Group Chat",
                  avatar: null,
              };

    if (!other && chat.type === "dm") return;

    videoCall.startCall(chat.public_id, callType, {
        publicId: (other as any).public_id || "group",
        name: (other as any).name || "Group",
        avatar: (other as any).avatar || null,
    });
}

function handleCallback(data: { chatId: string; callType: "video" | "audio" }) {
    const chat = props.window.chat;
    const other =
        chat.type === "dm"
            ? chat.participants.find(
                  (p: any) => p.public_id !== currentUserPublicId.value,
              )
            : {
                  public_id: "group",
                  name: chat.name || "Group Chat",
                  avatar: null,
              };

    videoCall.startCall(data.chatId, data.callType, {
        publicId: (other as any).public_id || "group",
        name: (other as any).name || "Group",
        avatar: (other as any).avatar || null,
    });
}

const activeCall = computed(() => {
    return videoCallStore.activeCalls.get(props.window.chatId);
});

// const isInCurrentCall = computed(() => {
//     return videoCallStore.currentCall?.chatId === props.window.chatId;
// });

function joinCall() {
    if (activeCall.value) {
        videoCall.joinActiveCall(
            props.window.chatId,
            activeCall.value.callId,
            activeCall.value.callType,
        );
    }
}

const activeCallInvite = computed(() => {
    const chat = props.window.chat;
    if (!chat || chat.type !== "group") return null;

    // Check if there is an active call for this chat
    const activeCall = videoCallStore.activeCalls.get(props.window.chatId);
    if (!activeCall) return null;

    // Check if we are already in it
    const isInCall = videoCallStore.currentCall?.chatId === props.window.chatId;
    if (isInCall) return null;

    return {
        callId: activeCall.callId,
        callType: activeCall.callType,
        chatId: props.window.chatId,
        callerName: "Group",
    };
});

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
const emojiButtonRef = ref<HTMLButtonElement | null>(null);
const giphyButtonRef = ref<HTMLButtonElement | null>(null);
const giphyPopoverRef = ref<HTMLElement | null>(null);
const minichatWindowRef = ref<HTMLElement | null>(null);
const emojiPickerStyle = ref<{ left: string; top: string }>({
    left: "0px",
    top: "0px",
});
const giphyPickerStyle = ref<{ left: string; top: string }>({
    left: "0px",
    top: "0px",
});

const { attach: attachMention } = useMention(
    textareaRef,
    computed(() => props.window.chat.public_id),
    {
        menuContainer: () => minichatWindowRef.value,
        onMenuPosition: (menu, textarea) => {
            const windowEl = minichatWindowRef.value;
            if (!windowEl) return;

            const windowRect = windowEl.getBoundingClientRect();
            const textareaRect = textarea.getBoundingClientRect();
            const spacing = 8;

            const menuWidth = menu.offsetWidth || 280;
            const menuHeight = menu.offsetHeight || 140;

            const desiredLeft = textareaRect.left - windowRect.left;
            const minLeft = 8;
            const maxLeft = Math.max(
                minLeft,
                windowRect.width - menuWidth - 8,
            );
            const left = Math.min(maxLeft, Math.max(minLeft, desiredLeft));

            const desiredTop =
                textareaRect.top - windowRect.top - menuHeight - spacing;
            const minTop = 8;
            const maxTop = Math.max(
                minTop,
                windowRect.height - menuHeight - 8,
            );
            const top = Math.min(maxTop, Math.max(minTop, desiredTop));

            menu.style.position = "absolute";
            menu.style.left = `${left}px`;
            menu.style.top = `${top}px`;
        },
        onSelect: (_item: any) => {
            nextTick(() => {
                if (textareaRef.value) {
                    messageInput.value = textareaRef.value.value;
                    textareaRef.value.focus();
                }
            });
        },
    },
);
const isSending = ref(false);
const isLoadingMore = ref(false);
const isInitialLoading = ref(false);
const showScrollButton = ref(false);
const isJumping = ref(false);
const isDragging = ref(false);
const showEmoji = ref(false);
const showGiphy = ref(false);
const replyingTo = ref<Message | null>(null);
const editingMessage = ref<Message | null>(null);
const pendingFiles = ref<PendingFile[]>([]);
const recordedAudioDraft = ref<RecordedAudioDraft | null>(null);
const draftAudioRef = ref<HTMLAudioElement | null>(null);
const isDraftPlaying = ref(false);
let pickerInstance: any = null;
const EMOJI_PICKER_FALLBACK_WIDTH = 352;
const EMOJI_PICKER_FALLBACK_HEIGHT = 380;
const GIPHY_PICKER_FALLBACK_WIDTH = 320;
const GIPHY_PICKER_FALLBACK_HEIGHT = 336;

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
    onError: (message) => {
        toast.error("Recorder", message);
    },
});
// typingUser ref removed
// Removed local typing/channel state in favor of useChatRealtime
// typingTimeout removed (unused)
let lastTypingSent = 0;

const currentUserPublicId = computed(() => authStore.user?.public_id || "");

const messages = computed(() => {
    return chatStore.messagesByChat.get(props.window.chatId) || [];
});

const pinnedMessages = computed(() =>
    messages.value.filter(
        (msg) =>
            msg.type !== "system" &&
            Boolean(msg.is_pinned || msg.metadata?.is_pinned),
    ),
);

const activePinnedMessage = computed(() => {
    if (!pinnedMessages.value.length) return null;
    const sorted = [...pinnedMessages.value].sort((a, b) => {
        const aPin = new Date(
            String(a.pinned_at || a.metadata?.pinned_at || a.created_at),
        ).getTime();
        const bPin = new Date(
            String(b.pinned_at || b.metadata?.pinned_at || b.created_at),
        ).getTime();
        return bPin - aPin;
    });
    return sorted[0] ?? null;
});

const pinnedPreview = computed(() => {
    const msg = activePinnedMessage.value;
    if (!msg) return "";
    if (msg.content?.trim()) return msg.content.trim();
    if (msg.attachments?.length) {
        return `${msg.attachments.length} attachment${msg.attachments.length > 1 ? "s" : ""}`;
    }
    if (msg.metadata?.giphy?.title) return `GIF: ${msg.metadata.giphy.title}`;
    return "Pinned message";
});

const pinnedPreviewShort = computed(() => {
    const text = pinnedPreview.value.trim();
    if (!text) return "";
    const max = 72;
    return text.length > max ? `${text.slice(0, max - 1)}...` : text;
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
        (p) => p.public_id !== currentUserPublicId.value,
    );
    if (!other) return null;

    // Check presence map
    const presence = presenceUsers.value.get(other.public_id);
    return presence?.status || "offline";
});

const canSend = computed(() => {
    return (
        (messageInput.value.trim().length > 0 ||
            pendingFiles.value.length > 0 ||
            !!recordedAudioDraft.value) &&
        !isSending.value &&
        !isRecorderActive.value
    );
});

const isComposerLockedForDraft = computed(
    () =>
        !!recordedAudioDraft.value ||
        isRecorderActive.value ||
        isRecorderBusy.value,
);
const isRecordButtonBlocked = computed(
    () =>
        !!recordedAudioDraft.value ||
        isSending.value ||
        messageInput.value.trim().length > 0 ||
        pendingFiles.value.length > 0,
);

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

    messageInput.value = "";
    showEmoji.value = false;
    showGiphy.value = false;
    isDraftPlaying.value = false;
}

function clearRecordedAudioDraft() {
    const player = draftAudioRef.value;
    if (player) {
        player.pause();
        player.currentTime = 0;
    }

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
    } catch (error) {
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
                } catch (retryError) {
                    console.warn("Retry preview failed:", retryError);
                }
            }
        }

        console.warn("Unable to preview recorded audio:", error);
        toast.error("Playback failed", "Could not play this recording.");
        isDraftPlaying.value = false;
    }
}

function handleDraftEnded() {
    isDraftPlaying.value = false;
}

function handleDraftPaused() {
    isDraftPlaying.value = false;
}

async function handleAudioDraftCancel() {
    if (isRecorderActive.value || isRecorderBusy.value) {
        await cancelRecording();
    }
    clearRecordedAudioDraft();
}

// Local typing indicator (V1 style isolation)
// Local typing indicator (aligned with Full Chat)
const typingIndicator = computed(() => {
    const typerIds = chatStore.getTypingUsers(props.window.chatId);
    if (!typerIds || typerIds.length === 0) return null;

    const names = typerIds.map((id) => {
        const p = props.window.chat.participants.find(
            (user) => user.public_id === id,
        );
        return p ? p.name.split(" ")[0] : "Someone";
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
                `Received: ${lastMsg.id} from ${lastMsg.user_name}`,
            );
        }
    },
);

watch(typingIndicator, (newVal) => {
    if (newVal) {
        addDebugLog("TYPING", `Typing started: ${newVal}`);
        console.log(
            `[MiniChat:${instanceId}] Typing started, scrolling to bottom`,
        );
        nextTick(() => scrollToBottom());
    } else {
        addDebugLog("TYPING", "Typing stopped");
    }
});

// Fetch messages on mount
onMounted(async () => {
    if (messages.value.length === 0) {
        isInitialLoading.value = true;
        try {
            await chatStore.fetchMessages(props.window.chatId);
        } finally {
            isInitialLoading.value = false;
        }
    }
    await nextTick();
    scrollToBottom();
    chatStore.markAsRead(props.window.chatId);

    document.addEventListener("click", handleClickOutside);
    document.addEventListener("keydown", handleEsc);
    window.addEventListener("resize", handleEmojiPickerViewportChange);
    window.addEventListener("scroll", handleEmojiPickerViewportChange, true);
    attachMention();
});

onUnmounted(() => {
    document.removeEventListener("click", handleClickOutside);
    document.removeEventListener("keydown", handleEsc);
    window.removeEventListener("resize", handleEmojiPickerViewportChange);
    window.removeEventListener("scroll", handleEmojiPickerViewportChange, true);
    // Cleanup pending files
    pendingFiles.value.forEach((f) => {
        if (f.url) URL.revokeObjectURL(f.url);
    });
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

// Watch for new messages
watch(
    messages,
    async () => {
        await nextTick();
        scrollToBottom();
    },
    { deep: true },
);

watch(
    showEmoji,
    async (isOpen) => {
        if (!isOpen) {
            return;
        }

        await nextTick();
        updateEmojiPickerPosition();
    },
);

watch(
    showGiphy,
    async (isOpen) => {
        if (!isOpen) {
            return;
        }

        await nextTick();
        updateGiphyPickerPosition();
    },
);

watch(
    () => [props.window.position.right, props.window.position.bottom],
    () => {
        handleEmojiPickerViewportChange();
    },
);

function scrollToBottom() {
    if (messagesRef.value) {
        messagesRef.value.scrollTop = messagesRef.value.scrollHeight;
    }
}

async function handleSend() {
    if (!canSend.value) return;

    if (editingMessage.value) {
        const next = messageInput.value.trim();
        const current = String(editingMessage.value.content || "").trim();
        if (!next) {
            toast.error("Edit failed", "Message cannot be empty.");
            return;
        }
        if (next === current) {
            cancelEdit();
            return;
        }

        isSending.value = true;
        try {
            await chatStore.editMessage(
                props.window.chatId,
                String(editingMessage.value.id),
                next,
            );
            messageInput.value = "";
            cancelEdit();
        } catch (error: any) {
            toast.error(
                "Edit failed",
                error?.message || "Could not edit this message.",
            );
        } finally {
            isSending.value = false;
        }
        return;
    }

    if (recordedAudioDraft.value) {
        isSending.value = true;
        try {
            await sendRecordedAudio(
                recordedAudioDraft.value.file,
                recordedAudioDraft.value.durationSeconds,
            );
            clearRecordedAudioDraft();
            replyingTo.value = null;
            await nextTick();
            scrollToBottom();
        } catch (error: any) {
            console.error("Failed to send recorded audio:", error);
            toast.error(
                "Send Failed",
                error?.message || "Failed to send recorded audio",
            );
        } finally {
            isSending.value = false;
        }
        return;
    }

    const content = messageInput.value.trim();
    messageInput.value = "";
    isSending.value = true;

    try {
        if (pendingFiles.value.length > 0) {
            // Send with files
            const files = pendingFiles.value.map((pf) => pf.file);
            const mediaMetadata = await buildUploadMediaMetadata(files);
            await chatStore.uploadMessage(
                props.window.chatId,
                files,
                content,
                replyingTo.value?.id,
                mediaMetadata,
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
                replyingTo.value?.id,
            );
        }

        replyingTo.value = null;
    } catch (error: any) {
        console.error("Failed to send message:", error);
        toast.error("Send Failed", error.message || "Failed to send message");
    } finally {
        isSending.value = false;
    }
}

async function sendRecordedAudio(file: File, durationSeconds?: number | null) {
    if (!props.window.chatId) return;
    await chatStore.uploadMessage(
        props.window.chatId,
        [file],
        "",
        replyingTo.value?.id,
        [
            {
                duration_seconds:
                    typeof durationSeconds === "number" ? durationSeconds : null,
            },
        ],
    );
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
            preview: gif.preview,
        },
    };

    // Send empty content but with metadata
    // Close picker first
    showGiphy.value = false;

    try {
        await chatStore.sendMessage(
            props.window.chatId,
            "",
            replyingTo.value?.id,
            metadata,
        );
        scrollToBottom();
    } catch (error: any) {
        console.error("Failed to send GIF:", error);
        toast.error("Send Failed", error.message || "Failed to send GIF");
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
    if (editingMessage.value) {
        messageInput.value = "";
    }
    editingMessage.value = null;
    replyingTo.value = msg;
    textareaRef.value?.focus();
}

function cancelReply() {
    replyingTo.value = null;
}

function handleStartEditMessage(message: Message) {
    if (!message?.id) return;
    if (isRecorderActive.value || isRecorderBusy.value) {
        void cancelRecording();
    }
    if (recordedAudioDraft.value) {
        clearRecordedAudioDraft();
    }
    editingMessage.value = message;
    replyingTo.value = null;
    clearPendingFiles();
    messageInput.value = String(message.content || "");
    nextTick(() => textareaRef.value?.focus());
}

function cancelEdit() {
    editingMessage.value = null;
    messageInput.value = "";
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
        `[data-message-id="${messageId}"]`,
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
            messageId,
        );

        // Replace current messages with fetched context
        chatStore.setMessagesForChat(props.window.chatId, result.messages);

        await nextTick();

        // Now find and scroll to target
        el = messagesRef.value?.querySelector(
            `[data-message-id="${messageId}"]`,
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

const handleReactMessage = async (message: Message, reaction: string) => {
    if (!message?.id) return;
    try {
        await chatStore.toggleMessageReaction(
            props.window.chatId,
            String(message.id),
            reaction,
        );
    } catch (error: any) {
        toast.error(
            "Reaction failed",
            error?.message || "Could not update reaction.",
        );
    }
};

const handleTogglePinMessage = async (message: Message) => {
    if (!message?.id) return;
    const isPinned = Boolean(message.is_pinned || message.metadata?.is_pinned);
    try {
        if (isPinned) {
            await chatStore.unpinMessage(
                props.window.chatId,
                String(message.id),
            );
        } else {
            await chatStore.pinMessage(props.window.chatId, String(message.id));
        }
    } catch (error: any) {
        toast.error(
            "Pin update failed",
            error?.message || "Could not update pin state.",
        );
    }
};

const handleDeleteMessage = async (message: Message, scope: "me" | "all") => {
    if (!message?.id) return;

    if (scope === "all") {
        const confirmed = window.confirm("Delete this message for everyone?");
        if (!confirmed) return;
    }

    try {
        await chatStore.deleteMessage(
            props.window.chatId,
            String(message.id),
            scope,
        );
    } catch (error: any) {
        toast.error(
            "Delete failed",
            error?.message || "Could not update this message.",
        );
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

function appendPendingFiles(newFiles: File[]) {
    if (!newFiles.length) return;
    if (editingMessage.value) {
        toast.error("Edit mode", "Finish editing before attaching files.");
        return;
    }

    const currentTotalSize = pendingFiles.value.reduce(
        (acc, f) => acc + f.size,
        0,
    );

    if (pendingFiles.value.length + newFiles.length > MAX_FILES) {
        toast.error(
            "Limit Exceeded",
            `You can only upload up to ${MAX_FILES} files at a time.`,
        );
        return;
    }

    const newBatchSize = newFiles.reduce((sum, file) => sum + file.size, 0);
    if (currentTotalSize + newBatchSize > MAX_TOTAL_SIZE) {
        toast.error("Limit Exceeded", "Total upload size cannot exceed 10MB.");
        return;
    }

    newFiles.forEach((file) => {
        if (file.size > MAX_FILE_SIZE) {
            toast.error("File Too Large", `${file.name} exceeds the 5MB limit.`);
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

function handleFileSelect(e: Event) {
    const input = e.target as HTMLInputElement;
    if (input.files) {
        appendPendingFiles(Array.from(input.files));
        input.value = "";
    }
}

function handlePaste(e: ClipboardEvent) {
    if (e.clipboardData && e.clipboardData.files.length > 0) {
        e.preventDefault();
        appendPendingFiles(Array.from(e.clipboardData.files));
    }
}

function removeFile(index: number) {
    const file = pendingFiles.value[index];
    if (file.url) URL.revokeObjectURL(file.url);
    pendingFiles.value.splice(index, 1);
}

function clearPendingFiles() {
    pendingFiles.value.forEach((file) => {
        if (file.url) URL.revokeObjectURL(file.url);
    });
    pendingFiles.value = [];
}

// Emoji handling
function resolvePopoverPosition(
    anchorEl: HTMLElement,
    popoverWidth: number,
    popoverHeight: number,
) {
    const margin = 8;
    const spacing = 8;
    const bottomPadding = 14;
    const anchorRect = anchorEl.getBoundingClientRect();
    const maxLeft = Math.max(margin, window.innerWidth - popoverWidth - margin);
    const left = Math.min(maxLeft, Math.max(margin, anchorRect.left));

    const aboveTop = anchorRect.top - popoverHeight - spacing;
    if (aboveTop >= margin) {
        return {
            left: `${Math.round(left)}px`,
            top: `${Math.round(aboveTop)}px`,
        };
    }

    const maxTop = Math.max(
        margin,
        window.innerHeight - popoverHeight - bottomPadding,
    );
    const belowTop = Math.max(margin, anchorRect.bottom + spacing);

    return {
        left: `${Math.round(left)}px`,
        top: `${Math.round(Math.min(maxTop, belowTop))}px`,
    };
}

function updateEmojiPickerPosition() {
    if (!showEmoji.value || !emojiButtonRef.value) {
        return;
    }

    const pickerElement = emojiMountRef.value?.firstElementChild as
        | HTMLElement
        | undefined;
    const pickerWidth =
        pickerElement?.offsetWidth || EMOJI_PICKER_FALLBACK_WIDTH;
    const pickerHeight =
        pickerElement?.offsetHeight || EMOJI_PICKER_FALLBACK_HEIGHT;
    emojiPickerStyle.value = resolvePopoverPosition(
        emojiButtonRef.value,
        pickerWidth,
        pickerHeight,
    );
}

function updateGiphyPickerPosition() {
    if (!showGiphy.value || !giphyButtonRef.value) {
        return;
    }

    const pickerWidth =
        giphyPopoverRef.value?.offsetWidth || GIPHY_PICKER_FALLBACK_WIDTH;
    const pickerHeight =
        giphyPopoverRef.value?.offsetHeight || GIPHY_PICKER_FALLBACK_HEIGHT;
    giphyPickerStyle.value = resolvePopoverPosition(
        giphyButtonRef.value,
        pickerWidth,
        pickerHeight,
    );
}

function handleEmojiPickerViewportChange() {
    if (!showEmoji.value && !showGiphy.value) {
        return;
    }

    window.requestAnimationFrame(() => {
        if (showEmoji.value) {
            updateEmojiPickerPosition();
        }
        if (showGiphy.value) {
            updateGiphyPickerPosition();
        }
    });
}

async function toggleGiphy() {
    showGiphy.value = !showGiphy.value;
    if (showGiphy.value) {
        showEmoji.value = false;
        await nextTick();
        updateGiphyPickerPosition();
    }
}

async function toggleEmoji() {
    showEmoji.value = !showEmoji.value;
    if (showEmoji.value) {
        showGiphy.value = false;
    }
    await nextTick();
    updateEmojiPickerPosition();

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
        window.requestAnimationFrame(() => {
            updateEmojiPickerPosition();
        });
    }
}

function insertEmoji(emoji: string) {
    messageInput.value += emoji;
    nextTick(() => {
        textareaRef.value?.focus();
    });
}

function handleClickOutside(e: MouseEvent) {
    const target = e.target as HTMLElement;
    if (emojiButtonRef.value?.contains(target)) {
        return;
    }
    if (giphyButtonRef.value?.contains(target)) {
        return;
    }
    if (
        showEmoji.value &&
        emojiMountRef.value &&
        !emojiMountRef.value.contains(target)
    ) {
        showEmoji.value = false;
    }
    if (
        showGiphy.value &&
        giphyPopoverRef.value &&
        !giphyPopoverRef.value.contains(target)
    ) {
        showGiphy.value = false;
    }
}

function handleEsc(e: KeyboardEvent) {
    if (e.key === "Escape") {
        showEmoji.value = false;
        showGiphy.value = false;
    }
}

// Subscribe to channel locally (V1 style)
onMounted(async () => {
    addDebugLog("SYSTEM", `Mounting chat: ${props.window.chatId}`);

    // Subscribe using shared realtime service
    chatRealtime.subscribeToChat(props.window.chatId, props.window.chat.type);

    // active call check
    videoCallStore.checkActiveCall(props.window.chatId);

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
        props.window.chat.type,
    );
});

// Window controls
function handleMinimize() {
    showEmoji.value = false;
    showGiphy.value = false;
    miniChatStore.minimizeChatWindow(props.window.chatId);
}

function handleClose() {
    showEmoji.value = false;
    showGiphy.value = false;
    miniChatStore.closeChatWindow(props.window.chatId);
}

function handleOpenFull() {
    showEmoji.value = false;
    showGiphy.value = false;
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
        Math.min(window.innerWidth - 360, startPos.value.right + deltaX),
    );
    const newBottom = Math.max(
        20,
        Math.min(window.innerHeight - 500, startPos.value.bottom + deltaY),
    );

    miniChatStore.updateWindowPosition(props.window.chatId, {
        right: newRight,
        bottom: newBottom,
    });

    handleEmojiPickerViewportChange();
}

function handleDragEnd() {
    isDragging.value = false;
    document.removeEventListener("mousemove", handleDragMove);
    document.removeEventListener("mouseup", handleDragEnd);
}
function isOwnMessage(msg: Message): boolean {
    if (msg.type === "system") return false;
    return msg.user_public_id === currentUserPublicId.value;
}
</script>

<template>
    <div
        ref="minichatWindowRef"
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
                    <span
                        >STATUS:
                        <span
                            :class="
                                chatRealtime.isConnected.value
                                    ? 'text-green-400'
                                    : 'text-red-400'
                            "
                            >{{ chatRealtime.connectionState.value }}</span
                        ></span
                    >
                    <span>UID: {{ currentUserPublicId.substring(0, 8) }}</span>
                </div>
                <div class="flex justify-between mb-1 text-[9px] text-gray-500">
                    <span>INST: {{ instanceId }}</span>
                    <span>VIS: {{ !!typingIndicator }}</span>
                </div>
                <div class="text-gray-500">
                    <div class="mb-1">
                        Subscriptions ({{
                            chatRealtime.subscribedChannels.value.size
                        }}):
                    </div>
                    <div
                        class="break-all text-[9px] leading-tight text-gray-600"
                    >
                        {{
                            Array.from(
                                chatRealtime.subscribedChannels.value,
                            ).join(", ")
                        }}
                    </div>
                </div>
            </div>

            <div
                v-for="(log, i) in debugLogs"
                :key="i"
                class="break-all border-b border-gray-900/50 pb-0.5 mb-0.5"
            >
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
            class="minichat-window-header relative"
            :class="{ 'cursor-default!': miniChatStore.isDocked }"
            @mousedown.prevent="handleDragStart"
        >
            <div
                v-if="!miniChatStore.isDocked"
                class="absolute left-1.5 top-1/2 -translate-y-1/2 text-(--text-secondary) pointer-events-none opacity-60 group-hover:opacity-100 transition-opacity"
            >
                <Icon name="GripVertical" :size="16" />
            </div>
            <div
                class="relative shrink-0"
                :class="{ 'ml-4': !miniChatStore.isDocked }"
            >
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
                <!-- Call buttons -->
                <template v-if="true">
                    <button
                        class="minichat-window-action"
                        title="Voice Call"
                        @click.stop="handleStartCall('audio')"
                    >
                        <Icon name="Phone" :size="14" />
                    </button>
                    <button
                        class="minichat-window-action"
                        title="Video Call"
                        @click.stop="handleStartCall('video')"
                    >
                        <Icon name="Video" :size="14" />
                    </button>
                </template>
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

        <!-- Fixed Pinned Message Strip -->
        <div
            v-if="activePinnedMessage"
            class="minichat-pinned-strip"
            :title="pinnedPreview"
            @click="jumpToMessage(String(activePinnedMessage.id))"
        >
            <Icon name="Pin" :size="11" class="minichat-pinned-strip-icon" />
            <div class="minichat-pinned-strip-content">
                {{ pinnedPreviewShort }}
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
                    class="w-5 h-5 border-2 border-(--interactive-primary) border-t-transparent rounded-full animate-spin"
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
                    <!-- Message Bubble (Handles both User and System messages) -->
                    <MiniChatMessageBubble
                        :message="msg"
                        :is-mine="isOwnMessage(msg)"
                        @reply="handleReply"
                        @jump="jumpToMessage"
                        @retry="handleRetry"
                        @react="(reaction) => handleReactMessage(msg, reaction)"
                        @toggle-pin="handleTogglePinMessage(msg)"
                        @start-edit="() => handleStartEditMessage(msg)"
                        @delete="(scope) => handleDeleteMessage(msg, scope)"
                        @callback="handleCallback"
                        @join-call="
                            (data) =>
                                videoCall.joinActiveCall(
                                    data.chatId,
                                    data.callId,
                                    data.callType,
                                )
                        "
                        :participants="window.chat.participants"
                    />
                </div>
            </TransitionGroup>

            <div
                v-if="isInitialLoading && messages.length === 0"
                class="minichat-skeleton-wrap"
                aria-hidden="true"
            >
                <div class="minichat-skeleton-row">
                    <div class="minichat-skeleton-avatar"></div>
                    <div class="minichat-skeleton-bubble shimmer" style="width: 48%"></div>
                </div>
                <div class="minichat-skeleton-row is-own">
                    <div class="minichat-skeleton-bubble shimmer" style="width: 36%"></div>
                </div>
                <div class="minichat-skeleton-row">
                    <div class="minichat-skeleton-avatar"></div>
                    <div class="minichat-skeleton-bubble shimmer" style="width: 62%"></div>
                </div>
                <div class="minichat-skeleton-row is-own">
                    <div class="minichat-skeleton-bubble shimmer" style="width: 40%"></div>
                </div>
            </div>

            <!-- System Message: Active Call Invite -->
            <div
                v-if="activeCallInvite"
                class="flex justify-center py-4 animate-in fade-in slide-in-from-bottom-4 duration-500"
            >
                <div
                    class="p-8 rounded-2xl bg-(--surface-elevated) border-(--border-default) shadow-lg max-w-sm mx-auto flex items-center gap-3"
                >
                    <div
                        class="p-2 bg-green-500/10 text-green-500 rounded-full shrink-0"
                    >
                        <Icon name="Video" size="20" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-medium text-xs text-(--text-primary)">
                            Video Call Started
                        </div>
                        <div
                            class="text-[10px] text-(--text-secondary) truncate"
                        >
                            Group Call
                        </div>
                    </div>
                    <button
                        @click.stop="joinCall"
                        class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-medium rounded-lg transition-colors shadow-sm whitespace-nowrap"
                    >
                        Join
                    </button>
                </div>
            </div>

            <div
                v-else-if="!isInitialLoading && messages.length === 0"
                class="minichat-window-empty"
            >
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
            class="minichat-typing-float"
        >
            <div class="minichat-typing-pill">
                <div class="minichat-typing-dots">
                    <div
                        class="minichat-typing-dot animate-bounce [animation-delay:-0.3s]"
                    ></div>
                    <div
                        class="minichat-typing-dot animate-bounce [animation-delay:-0.15s]"
                    ></div>
                    <div class="minichat-typing-dot animate-bounce"></div>
                </div>
                <span class="minichat-typing-text">{{ typingIndicator }}</span>
            </div>
        </div>

        <!-- Reply/Edit Preview -->
        <div v-if="editingMessage || replyingTo" class="minichat-reply-preview">
            <div class="minichat-reply-content">
                <span class="minichat-reply-label"
                    >{{
                        editingMessage
                            ? "Editing message"
                            : `Replying to ${replyingTo?.user_name || "Unknown"}`
                    }}</span
                >
                <span class="minichat-reply-text">{{
                    editingMessage
                        ? editingMessage.content?.slice(0, 50)
                        : replyingTo?.content?.slice(0, 50)
                }}</span>
            </div>
            <button
                class="minichat-reply-cancel"
                @click="editingMessage ? cancelEdit() : cancelReply()"
            >
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
            <input
                ref="fileInputRef"
                type="file"
                multiple
                accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip,.webm,.mp4,.mov,.m4v,.avi,.mkv,.mp3,.wav,.ogg,.m4a,.aac,.flac"
                class="hidden"
                @change="handleFileSelect"
            />

            <template v-if="!isComposerLockedForDraft">
                <!-- Attach button -->
                <button
                    class="minichat-composer-btn"
                    title="Attach file"
                    @click="openFilePicker"
                >
                    <Icon name="Paperclip" :size="16" />
                </button>

                <button
                    v-if="isRecorderSupported"
                    class="minichat-composer-btn"
                    :class="{
                        'is-active': isRecorderActive,
                        'is-disabled': isRecordButtonBlocked,
                    }"
                    :title="
                        isRecorderActive
                            ? 'Stop recording'
                            : 'Record audio (click or hold)'
                    "
                    :disabled="isRecordButtonBlocked"
                    @click.stop="onRecordButtonClick"
                    @pointerdown.stop.prevent="onRecordButtonPointerDown"
                    @pointerup.stop.prevent="onRecordButtonPointerUp"
                    @pointercancel.stop.prevent="onRecordButtonPointerUp"
                    @pointerleave.stop.prevent="onRecordButtonPointerUp"
                >
                    <Icon :name="isRecorderActive ? 'Square' : 'Mic'" :size="16" />
                </button>

                <!-- Emoji button -->
                <button
                    ref="emojiButtonRef"
                    class="minichat-composer-btn minichat-emoji-btn"
                    :class="{ 'is-active': showEmoji }"
                    title="Emoji"
                    @click.stop="toggleEmoji"
                >
                    <Icon name="Smile" :size="16" />
                </button>

                <!-- GIF button -->
                <button
                    ref="giphyButtonRef"
                    class="minichat-composer-btn"
                    :class="{ 'is-active': showGiphy }"
                    title="GIF"
                    @click.stop="toggleGiphy"
                >
                    <div
                        class="font-bold text-[8px] leading-none border border-current rounded px-0.5 py-0.5"
                    >
                        GIF
                    </div>
                </button>
            </template>

            <Teleport to="body">
                <div
                    v-show="showEmoji"
                    class="minichat-emoji-popover"
                    :style="emojiPickerStyle"
                >
                    <div ref="emojiMountRef" class="minichat-emoji-picker-shell" />
                </div>
            </Teleport>

            <Teleport to="body">
                <div
                    v-show="showGiphy"
                    ref="giphyPopoverRef"
                    class="minichat-giphy-popover"
                    :style="giphyPickerStyle"
                >
                    <GiphyPicker compact @select="sendGif" />
                </div>
            </Teleport>

            <div
                v-if="isRecorderBusy || isRecorderActive"
                class="minichat-audio-draft"
            >
                <button
                    type="button"
                    class="minichat-audio-draft-play"
                    :title="isRecorderPaused ? 'Resume recording' : 'Pause recording'"
                    :disabled="isRecorderBusy && !isRecorderActive"
                    @click="toggleRecordingPause"
                >
                    <Icon :name="isRecorderPaused ? 'Play' : 'Pause'" :size="12" />
                </button>
                <button
                    type="button"
                    class="minichat-audio-draft-play"
                    title="Stop recording"
                    :disabled="isRecorderBusy && !isRecorderActive"
                    @click="stopAndPrepareRecording"
                >
                    <Icon name="Square" :size="12" />
                </button>
                <div class="minichat-audio-draft-wave">
                    <span
                        v-for="(barHeight, idx) in liveWaveformBars"
                        :key="`recording-wave-${idx}`"
                        class="minichat-audio-draft-bar is-recording"
                        :style="{ height: `${barHeight}px` }"
                    ></span>
                </div>
                <span class="minichat-audio-draft-time">
                    {{
                        isRecorderBusy && !isRecorderActive
                            ? "..."
                            : isRecorderPaused
                              ? `${recorderElapsed}P`
                              : recorderElapsed
                    }}
                </span>
            </div>

            <div
                v-else-if="recordedAudioDraft"
                class="minichat-audio-draft"
            >
                <button
                    type="button"
                    class="minichat-audio-draft-play"
                    :title="isDraftPlaying ? 'Pause preview' : 'Play / resume preview'"
                    @click="toggleDraftPlayback"
                >
                    <Icon :name="isDraftPlaying ? 'Pause' : 'Play'" :size="12" />
                </button>
                <div class="minichat-audio-draft-wave">
                    <span
                        v-for="(barHeight, idx) in draftWaveformBars"
                        :key="`draft-wave-${idx}`"
                        class="minichat-audio-draft-bar"
                        :style="{ height: `${barHeight}px` }"
                    ></span>
                </div>
                <span class="minichat-audio-draft-time">{{
                    formatDuration(recordedAudioDraft.durationSeconds)
                }}</span>
                <audio
                    ref="draftAudioRef"
                    :src="recordedAudioDraft.url"
                    class="hidden"
                    @ended="handleDraftEnded"
                    @pause="handleDraftPaused"
                />
            </div>

            <!-- Input -->
            <textarea
                v-else
                ref="textareaRef"
                v-model="messageInput"
                rows="1"
                placeholder="Type a message..."
                class="minichat-input"
                @keydown="handleKeydown"
                @input="handleInputChange"
                @paste="handlePaste"
            />

            <button
                v-if="isComposerLockedForDraft || recordedAudioDraft"
                type="button"
                class="minichat-draft-cancel-btn"
                @click="handleAudioDraftCancel"
            >
                Cancel
            </button>

            <!-- Send button -->
            <button
                class="minichat-send-btn"
                :disabled="!canSend || isRecorderBusy"
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

        <div
            v-if="recorderError && !isRecorderActive && !isRecorderBusy"
            class="minichat-recorder-error"
        >
            {{ recorderError }}
        </div>
    </div>
</template>

<style scoped>
.minichat-window {
    position: fixed;
    width: 335px;
    height: 500px;
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
    position: relative;
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
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
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

.minichat-skeleton-wrap {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding-top: 2px;
}

.minichat-skeleton-row {
    display: flex;
    align-items: flex-end;
    gap: 8px;
}

.minichat-skeleton-row.is-own {
    justify-content: flex-end;
}

.minichat-skeleton-avatar {
    width: 22px;
    height: 22px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--surface-tertiary) 90%, transparent);
    flex: 0 0 22px;
}

.minichat-skeleton-bubble {
    height: 30px;
    border-radius: 12px;
    min-width: 92px;
    max-width: 76%;
    background: color-mix(in srgb, var(--surface-tertiary) 88%, transparent);
}

.shimmer {
    position: relative;
    overflow: hidden;
}

.shimmer::after {
    content: "";
    position: absolute;
    inset: 0;
    transform: translateX(-100%);
    background: linear-gradient(
        90deg,
        transparent,
        color-mix(in srgb, var(--surface-elevated) 45%, transparent),
        transparent
    );
    animation: chatShimmer 1.25s infinite;
}

@keyframes chatShimmer {
    100% {
        transform: translateX(100%);
    }
}

/* Messages */
.minichat-message-container {
    display: flex;
    flex-direction: column;
    width: 100%;
    margin-bottom: 0;
}

.minichat-pinned-strip {
    margin: 0;
    padding: 8px 12px;
    border-bottom: 1px solid var(--border-default);
    background: color-mix(
        in srgb,
        var(--surface-secondary) 88%,
        transparent
    );
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 34px;
}

.minichat-pinned-strip:hover {
    background: color-mix(
        in srgb,
        var(--surface-tertiary) 90%,
        transparent
    );
}

.minichat-pinned-strip-icon {
    color: var(--text-tertiary);
    flex: 0 0 auto;
}

.minichat-pinned-strip-content {
    font-size: 12px;
    color: var(--text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
    min-width: 0;
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

.minichat-recorder-indicator {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 6px 12px;
    background: var(--surface-secondary);
    border-top: 1px solid var(--border-default);
    font-size: 11px;
    color: var(--text-secondary);
}

.minichat-recorder-indicator.is-error {
    color: var(--color-error);
}

.minichat-recorder-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
}

.minichat-recorder-dot {
    width: 7px;
    height: 7px;
    border-radius: 999px;
    background: #ef4444;
    animation: minichatRecorderPulse 1s ease-in-out infinite;
}

@keyframes minichatRecorderPulse {
    0%,
    100% {
        transform: scale(0.95);
        opacity: 0.8;
    }
    50% {
        transform: scale(1.2);
        opacity: 1;
    }
}

.minichat-recorder-cancel {
    border: 1px solid var(--border-default);
    border-radius: 6px;
    background: var(--surface-tertiary);
    color: var(--text-secondary);
    padding: 2px 8px;
    font-size: 11px;
    cursor: pointer;
}

.minichat-recorder-cancel:hover {
    color: var(--text-primary);
}

/* Composer */
.minichat-window-composer {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 8px 10px;
    border-top: 1px solid var(--border-default);
    background: var(--surface-secondary);
    position: relative;
}

.minichat-typing-float {
    position: absolute;
    left: 12px;
    bottom: 64px;
    z-index: 24;
    pointer-events: none;
    max-width: calc(100% - 92px);
}

.minichat-typing-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    border-radius: 999px;
    background: color-mix(
        in srgb,
        var(--surface-elevated) 78%,
        transparent
    );
    border: 1px solid color-mix(in srgb, var(--border-default) 70%, transparent);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
}

.minichat-typing-dots {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    flex-shrink: 0;
}

.minichat-typing-dot {
    width: 4px;
    height: 4px;
    border-radius: 999px;
    background: var(--text-tertiary);
}

.minichat-typing-text {
    font-size: 12px;
    color: var(--text-tertiary);
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.minichat-composer-btn {
    width: 28px;
    height: 28px;
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

.minichat-composer-btn.is-disabled {
    opacity: 0.45;
    pointer-events: none;
}

.minichat-emoji-popover {
    position: fixed;
    z-index: 12050;
    padding-bottom: 6px;
}

.minichat-emoji-picker-shell {
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.28);
}

.minichat-giphy-popover {
    position: fixed;
    z-index: 12050;
    padding-bottom: 6px;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.28);
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
    min-width: 0;
    padding: 7px 10px;
    border-radius: 18px;
    border: 1px solid var(--border-default);
    background: var(--surface-elevated);
    color: var(--text-primary);
    font-size: 12.5px;
    outline: none;
    resize: none;
    min-height: 34px;
    max-height: 80px;
    transition: all 0.15s ease;
}

.minichat-input:focus {
    border-color: var(--interactive-primary);
}

.minichat-input::placeholder {
    color: var(--text-muted);
}

.minichat-audio-draft {
    flex: 1;
    min-width: 0;
    min-height: 34px;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 3px 6px;
    border-radius: 18px;
    border: 1px solid var(--border-default);
    background: var(--surface-elevated);
}

.minichat-audio-draft-play,
.minichat-audio-draft-clear {
    width: 22px;
    height: 22px;
    border-radius: 999px;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
}

.minichat-audio-draft-play {
    background: var(--interactive-primary);
    color: #fff;
}

.minichat-audio-draft-clear {
    background: transparent;
    color: var(--text-secondary);
}

.minichat-audio-draft-clear:hover {
    background: var(--surface-tertiary);
    color: var(--text-primary);
}

.minichat-audio-draft-wave {
    flex: 1;
    min-width: 0;
    height: 22px;
    display: flex;
    align-items: center;
    gap: 1px;
    padding: 0;
}

.minichat-audio-draft-bar {
    flex: 1 1 0;
    min-width: 1px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--interactive-primary) 60%, transparent);
    opacity: 0.85;
}

.minichat-audio-draft-bar.is-recording {
    background: #ef4444;
    animation: minichatRecorderPulse 1s ease-in-out infinite;
}

.minichat-audio-draft-time {
    width: 34px;
    font-size: 10px;
    color: var(--text-secondary);
    text-align: right;
    font-weight: 600;
    flex-shrink: 0;
}

.minichat-draft-cancel-btn {
    height: 26px;
    border-radius: 999px;
    border: 1px solid color-mix(in srgb, var(--color-error) 45%, transparent);
    background: color-mix(in srgb, var(--color-error) 12%, transparent);
    color: var(--color-error);
    font-size: 11px;
    font-weight: 600;
    padding: 0 9px;
    cursor: pointer;
    flex-shrink: 0;
    transition: all 0.15s ease;
}

.minichat-draft-cancel-btn:hover {
    background: color-mix(in srgb, var(--color-error) 18%, transparent);
}

.minichat-recorder-error {
    padding: 0 12px 8px;
    font-size: 11px;
    color: var(--color-error);
}

.minichat-send-btn {
    width: 32px;
    height: 32px;
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
    position: relative;
    z-index: 1;
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
