<template>
    <aside class="side-panel chat-panel">
        <div class="chat-header">
            <h3>Messages</h3>
            <button @click="$emit('close')" class="chat-header-close">
                <Icon name="x" size="18" />
            </button>
        </div>
        <div class="side-panel-body chat-messages" ref="messagesContainer">
            <div
                v-for="(msg, index) in meetingStore.chatMessages"
                :key="msg.id"
                class="chat-message-item"
                :class="{ 'chat-message-me': isMe(msg.participant_public_id) }"
            >
                <div
                    v-if="shouldShowHeader(msg, index)"
                    class="chat-message-header"
                >
                    <Avatar
                        v-if="msg.participant_public_id !== 'system'"
                        :src="getParticipantAvatar(msg.participant_public_id)"
                        :fallback="
                            getParticipantInitial(msg.participant_public_id)
                        "
                        :color="getParticipantColor(msg.participant_public_id)"
                        size="xs"
                        class="chat-avatar"
                    />
                    <span class="chat-sender-name">{{
                        getParticipantName(msg.participant_public_id)
                    }}</span>
                    <span class="chat-time">{{
                        formatTime(msg.created_at)
                    }}</span>
                </div>
                <div class="chat-bubble">
                    {{ msg.body }}
                </div>
            </div>
            <div
                v-if="meetingStore.chatMessages.length === 0"
                class="panel-empty"
            >
                <Icon name="message-square" size="28" class="text-[#5f6368]" />
                <p>
                    Messages can only be seen by people in the call and are
                    deleted when the call ends.
                </p>
            </div>
        </div>
        <div class="chat-input-area relative">
            <!-- Emoji Picker Popover -->
            <div
                v-show="showEmoji"
                ref="emojiMountRef"
                class="emoji-picker-container"
            ></div>

            <form @submit.prevent="submitMessage" class="chat-form">
                <button
                    type="button"
                    class="chat-action-btn flex items-center justify-center p-2 rounded-full text-[#9aa0a6] hover:bg-[#3c4043] transition-colors"
                    title="Insert emoji"
                    @click.stop="toggleEmoji"
                >
                    <Icon name="Smile" size="18" />
                </button>
                <input
                    ref="chatInputRef"
                    type="text"
                    v-model="newMessage"
                    class="chat-input"
                    placeholder="Send a message to everyone"
                    :disabled="isSending"
                />
                <button
                    type="submit"
                    class="chat-send-btn"
                    :disabled="!newMessage.trim() || isSending"
                    title="Send message"
                >
                    <Icon name="send" size="18" />
                </button>
            </form>
        </div>
    </aside>
</template>

<script setup lang="ts">
import { ref, nextTick, watch, onMounted, onUnmounted } from "vue";
import { useMeetingStore } from "@/stores/meeting";
import { Icon, Avatar } from "@/components/ui";
import data from "@emoji-mart/data";
import { Picker } from "emoji-mart";

defineEmits(["close"]);

const meetingStore = useMeetingStore();
const newMessage = ref("");
const isSending = ref(false);
const messagesContainer = ref<HTMLElement | null>(null);
const chatInputRef = ref<HTMLInputElement | null>(null);

// Emoji State
const showEmoji = ref(false);
const emojiMountRef = ref<HTMLElement | null>(null);
let pickerInstance: any = null;

function getParticipantName(publicId: string) {
    if (publicId === "system") return "System";
    if (isMe(publicId)) return "You";
    const p = meetingStore.allParticipants.find(
        (x) => x.public_id === publicId,
    );
    const name = p?.user?.name || p?.metadata?.guest_name || "Guest";
    const isGuest = !p?.user?.public_id && !p?.user?.id;
    if (isGuest && !/\(guest\)$/i.test(name)) {
        return `${name} (Guest)`;
    }
    return name;
}

function getParticipantAvatar(publicId: string) {
    const p = meetingStore.allParticipants.find(
        (x) => x.public_id === publicId,
    );
    return p?.user?.avatar_url || p?.metadata?.avatar_url;
}

function getParticipantColor(publicId: string) {
    const p = meetingStore.allParticipants.find(
        (x) => x.public_id === publicId,
    );
    return p?.user?.color;
}

function getParticipantInitial(publicId: string) {
    const p = meetingStore.allParticipants.find(
        (x) => x.public_id === publicId,
    );
    const name = p?.user?.name || p?.metadata?.guest_name || "G";
    return name.charAt(0).toUpperCase();
}

function isMe(publicId: string) {
    return publicId === meetingStore.localParticipant?.public_id;
}

function formatTime(isoString: string) {
    if (!isoString) return "";
    const date = new Date(isoString);
    return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

function shouldShowHeader(msg: any, index: number) {
    if (index === 0) return true;
    const prevMsg = meetingStore.chatMessages[index - 1];

    // Show header if sender changed
    if (prevMsg.participant_public_id !== msg.participant_public_id)
        return true;

    // Show header if more than 5 minutes passed
    if (msg.created_at && prevMsg.created_at) {
        const diff =
            new Date(msg.created_at).getTime() -
            new Date(prevMsg.created_at).getTime();
        if (diff > 5 * 60 * 1000) return true;
    }

    return false;
}

async function submitMessage() {
    if (!newMessage.value.trim() || isSending.value) return;

    const body = newMessage.value.trim();
    isSending.value = true;

    // Optimistic UI Append
    const tempId = Date.now();
    meetingStore.chatMessages.push({
        id: tempId,
        participant_public_id:
            meetingStore.localParticipant?.public_id || "system",
        body: body,
        created_at: new Date().toISOString(),
    });

    newMessage.value = "";
    showEmoji.value = false;

    // Scroll to bottom immediately
    await nextTick();
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop =
            messagesContainer.value.scrollHeight;
    }

    try {
        await meetingStore.sendMessage(body);
        // Clean up temp optimistic message if needed, but since it has same structure, it might stay
    } catch (e) {
        console.error("Failed to send message", e);
        // Remove optimistic message if failed
        meetingStore.chatMessages = meetingStore.chatMessages.filter(
            (m) => m.id !== tempId,
        );
    } finally {
        isSending.value = false;
    }
}

// Emoji picker initialization
async function toggleEmoji() {
    showEmoji.value = !showEmoji.value;
    await nextTick();

    if (showEmoji.value && !pickerInstance && emojiMountRef.value) {
        pickerInstance = new Picker({
            data,
            onEmojiSelect: (emoji: any) => {
                insertEmoji(emoji.native);
            },
            previewPosition: "none",
            theme: "dark",
            perLine: 7,
            maxFrequentRows: 2,
            onClickOutside: () => {},
        });
        emojiMountRef.value.appendChild(pickerInstance);
    }
}

function insertEmoji(emoji: string) {
    const el = chatInputRef.value;
    const currentValue = newMessage.value;

    if (!el) {
        newMessage.value = currentValue + emoji;
        return;
    }

    const start = el.selectionStart || 0;
    const end = el.selectionEnd || 0;
    newMessage.value =
        currentValue.substring(0, start) + emoji + currentValue.substring(end);

    nextTick(() => {
        el.focus();
        el.selectionStart = el.selectionEnd = start + emoji.length;
    });
}

function handleClickOutside(e: MouseEvent) {
    const target = e.target as HTMLElement;

    // Check emoji button
    const emojiButton = document.querySelector('button[title="Insert emoji"]');
    if (emojiButton && emojiButton.contains(target)) return;

    if (
        showEmoji.value &&
        emojiMountRef.value &&
        !emojiMountRef.value.contains(target)
    ) {
        showEmoji.value = false;
    }
}

function handleEsc(e: KeyboardEvent) {
    if (e.key === "Escape" && showEmoji.value) {
        showEmoji.value = false;
    }
}

onMounted(() => {
    document.addEventListener("click", handleClickOutside);
    document.addEventListener("keydown", handleEsc);
});

onUnmounted(() => {
    document.removeEventListener("click", handleClickOutside);
    document.removeEventListener("keydown", handleEsc);
});

// Auto-scroll to bottom when new messages arrive
watch(
    () => meetingStore.chatMessages.length,
    async () => {
        await nextTick();
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop =
                messagesContainer.value.scrollHeight;
        }
    },
);
</script>

<style scoped>
.chat-panel {
    display: flex;
    flex-direction: column;
}

.chat-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 20px;
    border-bottom: 1px solid #3c4043;
    flex-shrink: 0;
}
.chat-header h3 {
    font-size: 16px;
    font-weight: 600;
    color: #e8eaed;
    margin: 0;
    letter-spacing: -0.01em;
}
.chat-header-close {
    background: transparent;
    border: none;
    color: #9aa0a6;
    cursor: pointer;
    padding: 6px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.15s;
}
.chat-header-close:hover {
    background: #3c4043;
    color: #e8eaed;
}

.emoji-picker-container {
    position: absolute;
    bottom: 100%;
    left: 0;
    margin-bottom: 8px;
    z-index: 50;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
    border-radius: 12px;
    overflow: hidden;
    background: #202124;
    border: 1px solid #3c4043;
}

:deep(em-emoji-picker) {
    --border-radius: 0;
    --category-icon-size: 20px;
    --font-size: 14px;
    height: 320px !important;
    width: 320px !important;
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.chat-message-item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    margin-bottom: 4px;
}

.chat-message-me {
    align-items: flex-end;
}

.chat-message-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
    margin-top: 14px;
    padding-left: 2px;
}

.chat-avatar {
    margin-right: -2px;
}

.chat-sender-name {
    font-size: 13px;
    font-weight: 600;
    color: #e8eaed;
}

.chat-time {
    font-size: 11px;
    color: #9aa0a6;
    font-weight: 400;
}

.chat-bubble {
    font-size: 14px;
    color: #e8eaed;
    background: #3c4043;
    padding: 8px 12px;
    border-radius: 12px;
    border-top-left-radius: 2px;
    word-break: break-word;
    line-height: 1.4;
    max-width: 90%;
}

.chat-message-me .chat-bubble {
    background: #8ab4f8; /* Google Blue */
    color: #202124;
    border-radius: 12px;
    border-top-right-radius: 2px;
}

.chat-message-me .chat-sender-name {
    display: none; /* Usually don't need 'You' floating above every blue bubble */
}

.chat-input-area {
    padding: 16px;
    border-top: 1px solid #3c4043;
}

.chat-form {
    display: flex;
    align-items: center;
    background: #3c4043;
    border-radius: 24px;
    padding: 4px 16px;
}

.chat-input {
    flex: 1;
    background: transparent;
    border: none;
    color: white;
    font-size: 14px;
    padding: 10px 0;
    outline: none;
}

.chat-input::placeholder {
    color: #9aa0a6;
}

.chat-send-btn {
    color: #8ab4f8;
    background: transparent;
    border: none;
    padding: 8px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s;
}

.chat-send-btn:hover:not(:disabled) {
    background: rgba(138, 180, 248, 0.1);
}

.chat-send-btn:disabled {
    color: #5f6368;
    cursor: default;
}
</style>
