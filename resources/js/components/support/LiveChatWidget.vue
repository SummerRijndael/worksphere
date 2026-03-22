<script setup>
import { ref, nextTick, onMounted, onBeforeUnmount, computed, watch } from 'vue';
import {
    MessageSquare,
    Send,
    Paperclip,
    Smile,
    Minus,
    ChevronLeft,
    User,
    Mail,
    Clock,
    CheckCircle2,
    PlusCircle,
    History,
    Loader2,
    ChevronsDown,
    Headset,
} from 'lucide-vue-next';
import { Avatar, Button } from '@/components/ui';
import LinkPreview from '@/components/LinkPreview.vue';
import SupportMessageAttachments from '@/components/support/SupportMessageAttachments.vue';
import RecaptchaChallengeModal from '@/components/common/RecaptchaChallengeModal.vue';
import { useAuthStore } from '@/stores/auth';
import { useSupportChatStore } from '@/stores/supportChat';
import { useToast } from '@/composables/useToast.ts';
import useRecaptcha from '@/composables/useRecaptcha';
import api from '@/lib/api';
import { hasSupportRealtimeToken, setSupportRealtimeToken, startEcho } from '@/echo';
import { createSupportLogger, maskToken, summarizeError } from '@/utils/supportDebug';
import { playSupportMessageSound } from '@/utils/supportSound';
import { PROFESSIONAL_SUPPORT_EMOJIS } from '@/constants/supportEmojis';
import { renderSupportRichText } from '@/utils/supportRichText';

const props = defineProps({
    hideLauncher: {
        type: Boolean,
        default: false,
    },
});

const authStore = useAuthStore();
const chatStore = useSupportChatStore();
const toast = useToast();
const supportLogger = createSupportLogger('Widget');
const { executeRecaptcha, isEnabled: isRecaptchaEnabled } = useRecaptcha();

const isAuthenticated = computed(() => authStore.isAuthenticated);
const currentUser = computed(() => authStore.user);

const isOpen = computed(() => chatStore.isOpen);
const viewState = computed({
    get: () => chatStore.viewState,
    set: (val) => {
        chatStore.viewState = val;
    },
});

const newMessage = ref('');
const fileInput = ref(null);
const composerInputRef = ref(null);
const selectedFiles = ref([]);
const messagesContainer = ref(null);
const honeypotWebsiteUrl = ref('');
const activeConversation = ref(null);
const conversationMessages = ref([]);
const activeGuestToken = ref('');
const historyItems = ref([]);
const availability = ref({
    available: false,
    available_agents: 0,
    message: 'Checking support availability...',
});
const isLoadingAvailability = ref(false);
const isLoadingHistory = ref(false);
const isLoadingConversation = ref(false);
const isSending = ref(false);
const isStartingConversation = ref(false);
const isResumingConversation = ref(false);
const isLoadingOlderMessages = ref(false);
const hasMoreMessagesBefore = ref(false);
const oldestMessageId = ref(null);
const showJumpToLatest = ref(false);
const agentTypingIndicator = ref('');
const firstUnreadMessageId = ref(null);
const unreadMessageCount = ref(0);
const pendingOutgoingMessages = ref([]);
const unsafeMessageIds = ref(new Set());
const showEmojiPicker = ref(false);
const emojiPickerRef = ref(null);
const sendCooldownSeconds = ref(0);
const MAX_ATTACHABLE_FILES = 10;
const isLoadingSurvey = ref(false);
const isSubmittingSurvey = ref(false);
const isUpdatingSurveyPreference = ref(false);
const isEndingConversation = ref(false);
const showRecaptchaChallenge = ref(false);
const pendingConversationStartMessage = ref('');
const activeSurvey = ref({
    state: 'none',
    invite: null,
    response: null,
    bundle: null,
});
const surveyDraft = ref({
    csat_score: null,
    nps_score: null,
    comment: '',
});

const leadForm = ref({
    name: '',
    email: '',
});

let availabilityTimer = null;
let realtimeTokenRefreshTimer = null;
let realtimeSubscriptionRetryTimer = null;
let supportRealtimeChannelName = '';
let typingResetTimer = null;
let typingDebounceTimer = null;
let lastTypingSentAt = 0;
let supportRealtimeRetryAttempts = 0;
let sendCooldownTimer = null;

async function focusComposerInput() {
    if (!chatStore.isOpen || viewState.value !== 'chat') {
        return;
    }

    await nextTick();
    const input = composerInputRef.value;
    if (!input || input.disabled) {
        return;
    }

    input.focus({ preventScroll: true });
    if (typeof input.setSelectionRange === 'function') {
        const len = String(newMessage.value || '').length;
        try {
            input.setSelectionRange(len, len);
        } catch {
            // Ignore browsers/input modes that block selection APIs.
        }
    }
}

function scheduleSupportRealtimeRetry() {
    if (realtimeSubscriptionRetryTimer) {
        clearTimeout(realtimeSubscriptionRetryTimer);
    }

    const delayMs = Math.min(1000 * (2 ** supportRealtimeRetryAttempts), 8000);
    supportRealtimeRetryAttempts = Math.min(supportRealtimeRetryAttempts + 1, 6);
    supportLogger.warn('realtime.retry.schedule', 'Scheduling realtime resubscribe retry.', {
        attempt: supportRealtimeRetryAttempts,
        delay_ms: delayMs,
        conversation_id: activeConversation.value?.id || null,
    });

    realtimeSubscriptionRetryTimer = setTimeout(async () => {
        realtimeSubscriptionRetryTimer = null;
        if (!activeConversation.value?.id) {
            return;
        }

        const refreshed = await fetchConversation(activeConversation.value.id, activeGuestToken.value, {
            withLoading: false,
            applyRealtimeMeta: true,
            loadMessages: false,
        });

        if (refreshed) {
            activeConversation.value = refreshed;
        }

        await subscribeSupportRealtime();
    }, delayMs);
}

function handleEchoConnected() {
    supportLogger.info('realtime.echo.connected', 'Received echo:connected; re-subscribing support channel.');
    subscribeSupportRealtime();
}

const storageKey = computed(() => {
    return `worksphere_support_widget_history_${currentUser.value?.public_id || 'guest'}`;
});

// Event types that should only be shown to agents, not customers
const AGENT_ONLY_EVENT_TYPES = new Set([
    'resolution_started',
    'wrap_up_completed',
    'assignment',
    'assignment_accepted',
    'assignment_rejected',
    'transfer',
]);

const mappedMessages = computed(() => {
    const messages = conversationMessages.value || [];

    const mapped = messages
        .filter((message) => {
            // In the customer-facing widget, hide agent-only system events
            if (message.sender_type === 'system') {
                const eventType = message.metadata?.type;
                if (eventType && AGENT_ONLY_EVENT_TYPES.has(eventType)) {
                    return false;
                }
            }
            return true;
        })
        .map((message) => {
        let type = 'agent';
        let agentName = message.sender?.name || 'Support Team';

        if (message.sender_type === 'customer') {
            type = 'visitor';
        } else if (message.sender_type === 'system') {
            type = 'system';
            agentName = 'System';
        } else if (message.sender_type === 'bot') {
            type = 'agent';
            agentName = 'AI Support Bot';
        }

        return {
            id: message.id,
            type,
            content: message.body || '',
            firstUrl: extractFirstUrl(message.body || ''),
            avatarUrl: message.sender?.avatar_url || message.sender?.avatar || null,
            avatarThumbUrl: message.sender?.avatar_thumb_url || null,
            avatarColor: message.sender?.avatar_color || null,
            attachments: Array.isArray(message.attachments) ? message.attachments : [],
            time: formatTime(message.created_at),
            agentName,
        };
    });

    if (!firstUnreadMessageId.value || unreadMessageCount.value <= 0) {
        return mapped;
    }

    const dividerIndex = mapped.findIndex((message) => message.id === firstUnreadMessageId.value);
    if (dividerIndex < 0) {
        return mapped;
    }

    return [
        ...mapped.slice(0, dividerIndex),
        {
            id: `unread-divider-${firstUnreadMessageId.value}`,
            type: 'divider',
            content: 'New messages',
            firstUrl: null,
            unreadCount: unreadMessageCount.value,
            time: '',
            agentName: '',
        },
        ...mapped.slice(dividerIndex),
    ];
});

const pendingMessagesForActiveConversation = computed(() => {
    const conversationId = activeConversation.value?.id;
    if (!conversationId) {
        return [];
    }

    return pendingOutgoingMessages.value
        .filter((message) => message.conversationId === conversationId)
        .sort((a, b) => new Date(a.createdAt).getTime() - new Date(b.createdAt).getTime())
        .map((message) => ({
            ...message,
            time: formatTime(message.createdAt),
        }));
});

const conversationStatus = computed(() => activeConversation.value?.status || null);
const isConversationClosed = computed(() => ['resolved', 'closed'].includes(conversationStatus.value));
const showConversationHeaderMeta = computed(() => {
    return viewState.value === 'chat' && Boolean(activeConversation.value?.id);
});

const activeAssigneeName = computed(() => {
    const value = activeConversation.value?.assignee?.name;
    if (typeof value !== 'string') {
        return '';
    }

    const normalized = value.trim();
    return normalized !== '' ? normalized : '';
});

const talkingToLabel = computed(() => {
    if (activeAssigneeName.value) {
        return `Talking to: ${activeAssigneeName.value} (Agent)`;
    }

    const status = String(activeConversation.value?.status || '');
    const aiEnabled = Boolean(activeConversation.value?.ai_enabled ?? true);

    if (status === 'waiting_human') {
        return 'Talking to: Support Bot (connecting you to an agent)';
    }

    if (!aiEnabled) {
        return 'Talking to: Support Team';
    }

    return 'Talking to: Support Bot';
});

const starterHint = computed(() => {
    if (isConversationClosed.value) {
        return 'This conversation is resolved. Start a new conversation to continue.';
    }

    if (!availability.value.available) {
        return 'No support agent is available right now, but you can leave a message.';
    }

    return 'We are here to help. Send a message to start.';
});
const isSendCoolingDown = computed(() => sendCooldownSeconds.value > 0);
const surveyBundle = computed(() => activeSurvey.value?.bundle || {});
const csatSurveyState = computed(() => surveyBundle.value?.csat || null);
const npsSurveyState = computed(() => surveyBundle.value?.nps || null);
const pendingCsatInvite = computed(() => csatSurveyState.value?.state === 'pending' ? csatSurveyState.value?.invite : null);
const pendingNpsInvite = computed(() => npsSurveyState.value?.state === 'pending' ? npsSurveyState.value?.invite : null);
const hasPendingSurvey = computed(() => Boolean(pendingCsatInvite.value || pendingNpsInvite.value));
const hasSubmittedSurvey = computed(() => {
    if (hasPendingSurvey.value) {
        return false;
    }

    return [csatSurveyState.value, npsSurveyState.value].some((entry) => {
        return entry?.state === 'responded' && !!entry?.response;
    });
});
const showSurveyPanel = computed(() => {
    return isLoadingSurvey.value
        || hasPendingSurvey.value
        || hasSubmittedSurvey.value;
});
const isSurveyOptedOut = computed(() => Boolean(activeConversation.value?.survey_opt_out));
const csatScaleValues = computed(() => {
    const definition = pendingCsatInvite.value?.definition || {};
    const min = Number(definition.scale_min ?? 1);
    const max = Number(definition.scale_max ?? 5);
    const values = [];
    for (let value = min; value <= max; value += 1) {
        values.push(value);
    }

    return values;
});
const npsScaleValues = computed(() => {
    const definition = pendingNpsInvite.value?.definition || {};
    const min = Number(definition.scale_min ?? 0);
    const max = Number(definition.scale_max ?? 10);
    const values = [];
    for (let value = min; value <= max; value += 1) {
        values.push(value);
    }

    return values;
});
const surveyCanSubmit = computed(() => {
    if (!hasPendingSurvey.value || isSubmittingSurvey.value || isLoadingSurvey.value) {
        return false;
    }

    const needsCsat = Boolean(pendingCsatInvite.value);
    const needsNps = Boolean(pendingNpsInvite.value);
    if (!needsCsat && !needsNps) {
        return false;
    }

    if (needsCsat) {
        const csat = Number(surveyDraft.value.csat_score);
        if (!Number.isFinite(csat) || csat < 1 || csat > 5) {
            return false;
        }
    }

    if (needsNps) {
        const nps = Number(surveyDraft.value.nps_score);
        if (!Number.isFinite(nps) || nps < 0 || nps > 10) {
            return false;
        }
    }

    return true;
});

const toggleChat = () => {
    chatStore.toggleChat();
};

const scrollToBottom = async (smooth = false) => {
    await nextTick();
    if (messagesContainer.value) {
        messagesContainer.value.scrollTo({
            top: messagesContainer.value.scrollHeight,
            behavior: smooth ? 'smooth' : 'auto',
        });
    }
    showJumpToLatest.value = false;
    clearUnreadMarker();
};

function isNearBottom() {
    const container = messagesContainer.value;
    if (!container) {
        return true;
    }

    return (container.scrollHeight - (container.scrollTop + container.clientHeight)) <= 120;
}

function readHistoryRecords() {
    try {
        const raw = localStorage.getItem(storageKey.value);
        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return [];
        }

        return parsed;
    } catch {
        return [];
    }
}

function writeHistoryRecords(records) {
    localStorage.setItem(storageKey.value, JSON.stringify(records.slice(0, 30)));
}

function upsertHistoryRecord(record) {
    const records = readHistoryRecords();
    const filtered = records.filter((item) => item.id !== record.id);
    const persistedToken = isAuthenticated.value ? (record.token || '') : '';

    filtered.unshift({
        id: record.id,
        token: persistedToken,
        status: record.status || 'open',
        last_message: record.last_message || '',
        updated_at: record.updated_at || new Date().toISOString(),
        guest_name: record.guest_name || '',
        guest_email: record.guest_email || '',
    });

    writeHistoryRecords(filtered);
}

function removeHistoryRecord(conversationId) {
    const records = readHistoryRecords();
    writeHistoryRecords(records.filter((item) => item.id !== conversationId));
}

function formatTime(value) {
    if (!value) {
        return 'Now';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return 'Now';
    }

    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatRelativeTime(value) {
    if (!value) {
        return 'Now';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return 'Now';
    }

    const diffSeconds = Math.floor((Date.now() - date.getTime()) / 1000);
    if (diffSeconds < 60) {
        return 'Just now';
    }

    const diffMinutes = Math.floor(diffSeconds / 60);
    if (diffMinutes < 60) {
        return `${diffMinutes}m ago`;
    }

    const diffHours = Math.floor(diffMinutes / 60);
    if (diffHours < 24) {
        return `${diffHours}h ago`;
    }

    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) {
        return `${diffDays}d ago`;
    }

    return date.toLocaleDateString();
}

function extractFirstUrl(content) {
    if (typeof content !== 'string' || content.trim() === '') {
        return null;
    }

    const match = content.match(/(https?:\/\/[^\s]+)/);
    return match ? match[0] : null;
}

function markMessageUnsafe(messageId) {
    if (!messageId) {
        return;
    }

    unsafeMessageIds.value = new Set([...unsafeMessageIds.value, String(messageId)]);
}

function messageContentForDisplay(message) {
    const content = String(message?.content || '');
    if (content === '') {
        return '';
    }

    const messageId = String(message?.id || '');
    const firstUrl = String(message?.firstUrl || '');
    if (firstUrl === '') {
        return content;
    }

    const isOnlyUrl = content.trim() === firstUrl;
    if (!isOnlyUrl && !unsafeMessageIds.value.has(messageId)) {
        return content;
    }

    return content.replace(firstUrl, '').trim();
}

function messageContentHtml(message) {
    return renderSupportRichText(messageContentForDisplay(message));
}

function pendingMessageContentHtml(pending) {
    return renderSupportRichText(String(pending?.body || ''));
}

function extractRetryAfterSeconds(error, fallbackSeconds = 30) {
    const fromMeta = Number(error?.response?.data?.meta?.retry_after);
    if (Number.isFinite(fromMeta) && fromMeta > 0) {
        return Math.ceil(fromMeta);
    }

    const fromBody = Number(error?.response?.data?.retry_after);
    if (Number.isFinite(fromBody) && fromBody > 0) {
        return Math.ceil(fromBody);
    }

    const fromHeader = Number(error?.response?.headers?.['retry-after']);
    if (Number.isFinite(fromHeader) && fromHeader > 0) {
        return Math.ceil(fromHeader);
    }

    return Math.ceil(fallbackSeconds);
}

function startSendCooldown(seconds = 30) {
    const normalized = Math.max(1, Math.ceil(Number(seconds) || 30));
    sendCooldownSeconds.value = normalized;
    showEmojiPicker.value = false;

    if (sendCooldownTimer) {
        clearInterval(sendCooldownTimer);
    }

    sendCooldownTimer = setInterval(() => {
        sendCooldownSeconds.value = Math.max(0, sendCooldownSeconds.value - 1);
        if (sendCooldownSeconds.value <= 0) {
            clearInterval(sendCooldownTimer);
            sendCooldownTimer = null;
        }
    }, 1000);
}

function appendProfessionalEmoji(emoji) {
    if (!emoji || isSendCoolingDown.value || isConversationClosed.value || isSending.value || isStartingConversation.value) {
        return;
    }

    newMessage.value = `${newMessage.value || ''}${emoji}`;
    showEmojiPicker.value = false;
    handleComposerInput();
}

function toggleEmojiPicker() {
    if (isSendCoolingDown.value || isConversationClosed.value || isSending.value || isStartingConversation.value) {
        return;
    }

    showEmojiPicker.value = !showEmojiPicker.value;
}

function handleGlobalPointerDown(event) {
    if (!showEmojiPicker.value) {
        return;
    }

    if (emojiPickerRef.value?.contains(event.target)) {
        return;
    }

    showEmojiPicker.value = false;
}

function statusLabel(status) {
    return (status || 'open').replace(/_/g, ' ');
}

function userDisplayName() {
    return currentUser.value?.name || leadForm.value.name || 'Guest User';
}

function userEmail() {
    return currentUser.value?.email || leadForm.value.email || '';
}

function applyRealtimeMeta(meta) {
    const token = meta?.realtime?.token;
    if (typeof token === 'string' && token.trim() !== '') {
        setSupportRealtimeToken(token.trim(), 'customer');
        supportLogger.debug('realtime.token.updated', 'Applied customer realtime token from API meta.', {
            token_preview: maskToken(token.trim()),
            conversation_id: activeConversation.value?.id || null,
        });
    }
}

function clearAgentTypingIndicator() {
    agentTypingIndicator.value = '';
    if (typingResetTimer) {
        clearTimeout(typingResetTimer);
        typingResetTimer = null;
    }
}

function clearUnreadMarker() {
    firstUnreadMessageId.value = null;
    unreadMessageCount.value = 0;
}

function setAgentTyping(actorName, isTyping) {
    if (!isTyping) {
        clearAgentTypingIndicator();
        return;
    }

    agentTypingIndicator.value = `${actorName || 'Support'} is typing...`;
    if (typingResetTimer) {
        clearTimeout(typingResetTimer);
    }
    typingResetTimer = setTimeout(() => {
        agentTypingIndicator.value = '';
        typingResetTimer = null;
    }, 3500);
}

async function emitTyping(isTyping) {
    const conversationId = activeConversation.value?.id;
    if (!conversationId) {
        return;
    }

    if (isTyping) {
        const now = Date.now();
        if (now - lastTypingSentAt < 3500) {
            return;
        }
        lastTypingSentAt = now;
    }

    try {
        const payload = {
            is_typing: Boolean(isTyping),
        };
        if (activeGuestToken.value) {
            payload.guest_token = activeGuestToken.value;
        }

        await api.post(`/api/support/chats/${conversationId}/typing`, payload);
    } catch {
        // Best-effort only.
    }
}

function handleComposerInput() {
    if (isConversationClosed.value) {
        return;
    }

    if (!activeConversation.value?.id) {
        return;
    }

    const hasValue = newMessage.value.trim().length > 0;
    if (!hasValue) {
        if (typingDebounceTimer) {
            clearTimeout(typingDebounceTimer);
            typingDebounceTimer = null;
        }
        emitTyping(false);
        return;
    }

    emitTyping(true);
    if (typingDebounceTimer) {
        clearTimeout(typingDebounceTimer);
    }
    typingDebounceTimer = setTimeout(() => {
        emitTyping(false);
        typingDebounceTimer = null;
    }, 3000);
}

function onMessagesScroll() {
    const container = messagesContainer.value;
    if (!container) {
        return;
    }

    const nearBottom = isNearBottom();
    showJumpToLatest.value = !nearBottom;
    if (nearBottom) {
        clearUnreadMarker();
    }

    if (container.scrollTop <= 72) {
        loadOlderMessages();
    }
}

function jumpToLatest() {
    scrollToBottom(true);
}

async function jumpToFirstUnread() {
    if (!firstUnreadMessageId.value) {
        await jumpToLatest();
        return;
    }

    await nextTick();
    const element = document.getElementById(`support-widget-message-${firstUnreadMessageId.value}`);
    if (!element) {
        await jumpToLatest();
        return;
    }

    element.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
    });
}

async function handleJumpToLatestClick() {
    if (unreadMessageCount.value > 0) {
        await jumpToFirstUnread();
        return;
    }

    jumpToLatest();
}

function queuePendingMessage(body) {
    const conversationId = activeConversation.value?.id;
    if (!conversationId) {
        return null;
    }

    const files = selectedFiles.value.slice(0, MAX_ATTACHABLE_FILES);
    const pending = {
        id: `pending-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
        conversationId,
        guestToken: activeGuestToken.value,
        body,
        files,
        fileNames: files.map((file) => file.name),
        status: 'sending',
        createdAt: new Date().toISOString(),
    };

    pendingOutgoingMessages.value.push(pending);
    return pending;
}

function setPendingMessageStatus(messageId, status) {
    const message = pendingOutgoingMessages.value.find((item) => item.id === messageId);
    if (!message) {
        return;
    }

    message.status = status;
}

function removePendingMessage(messageId) {
    pendingOutgoingMessages.value = pendingOutgoingMessages.value.filter((item) => item.id !== messageId);
}

async function transmitPendingMessage(messageId, options = {}) {
    const pending = pendingOutgoingMessages.value.find((item) => item.id === messageId);
    if (!pending || !pending.conversationId) {
        return;
    }

    const showErrorToast = options.showErrorToast !== false;
    setPendingMessageStatus(messageId, 'sending');
    supportLogger.info('message.send.start', 'Sending pending support message.', {
        pending_id: messageId,
        conversation_id: pending.conversationId,
        body_length: pending.body?.length || 0,
        has_guest_token: Boolean(pending.guestToken),
        files_count: Array.isArray(pending.files) ? pending.files.length : 0,
    });

    try {
        const hasFiles = Array.isArray(pending.files) && pending.files.length > 0;
        let response;

        if (hasFiles) {
            const formData = new FormData();
            formData.append('body', pending.body || '');
            if (pending.guestToken) {
                formData.append('guest_token', pending.guestToken);
            }
            pending.files.forEach((file) => {
                formData.append('files[]', file);
            });

            response = await api.post(`/api/support/chats/${pending.conversationId}/messages`, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });
        } else {
            const payload = { body: pending.body };
            if (pending.guestToken) {
                payload.guest_token = pending.guestToken;
            }
            response = await api.post(`/api/support/chats/${pending.conversationId}/messages`, payload);
        }
        applyRealtimeMeta(response.data?.meta);
        removePendingMessage(messageId);

        if (activeConversation.value?.id === pending.conversationId) {
            const wasNearBottom = isNearBottom();

            if (response.data?.conversation) {
                activeConversation.value = response.data.conversation;
            } else {
                const refreshed = await fetchConversation(pending.conversationId, pending.guestToken, {
                    withLoading: false,
                    applyRealtimeMeta: true,
                    loadMessages: false,
                });
                if (refreshed) {
                    activeConversation.value = refreshed;
                }
            }

            await loadConversationMessages(pending.conversationId, pending.guestToken, {
                mergeLatest: true,
                wasNearBottom,
                trackUnread: false,
            });

            if (activeConversation.value) {
                syncConversationToHistory(activeConversation.value);
            }

            if (wasNearBottom) {
                await scrollToBottom(false);
            }
        }

        await loadConversationHistory();
        supportLogger.info('message.send.success', 'Pending support message sent successfully.', {
            pending_id: messageId,
            conversation_id: pending.conversationId,
        });
    } catch (error) {
        setPendingMessageStatus(messageId, 'failed');
        const status = Number(error?.response?.status || 0);
        if (status === 429) {
            startSendCooldown(extractRetryAfterSeconds(error, 30));
        }
        supportLogger.error('message.send.failure', 'Failed to send pending support message.', {
            pending_id: messageId,
            conversation_id: pending.conversationId,
            ...summarizeError(error),
        });

        if (showErrorToast) {
            if (status === 429) {
                toast.error('Please slow down', `You are sending too quickly. Try again in ${sendCooldownSeconds.value}s.`);
            } else {
                toast.error('Error', error.response?.data?.message || 'Message failed to send. You can retry.');
            }
        }
    }
}

function openFilePicker() {
    if (isConversationClosed.value || isSending.value || isStartingConversation.value) {
        return;
    }

    showEmojiPicker.value = false;
    fileInput.value?.click();
}

function handleFileSelection(event) {
    const incoming = Array.from(event?.target?.files || []);
    if (!incoming.length) {
        return;
    }

    const merged = [...selectedFiles.value, ...incoming];
    if (merged.length > MAX_ATTACHABLE_FILES) {
        toast.error('Limit reached', `You can attach up to ${MAX_ATTACHABLE_FILES} files per message.`);
    }
    selectedFiles.value = merged.slice(0, MAX_ATTACHABLE_FILES);

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function removeSelectedFile(index) {
    selectedFiles.value.splice(index, 1);
}

function clearSelectedFiles() {
    selectedFiles.value = [];
    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function resetSurveyState() {
    activeSurvey.value = {
        state: 'none',
        invite: null,
        response: null,
        bundle: null,
    };
    surveyDraft.value = {
        csat_score: null,
        nps_score: null,
        comment: '',
    };
}

async function loadConversationSurvey(conversationId, token = '') {
    if (!conversationId) {
        resetSurveyState();
        return;
    }

    isLoadingSurvey.value = true;
    try {
        const params = {};
        if (token) {
            params.guest_token = token;
        }

        const response = await api.get(`/api/support/chats/${conversationId}/survey`, { params });
        const payload = response.data?.data || {};

        activeSurvey.value = {
            state: payload.state || 'none',
            invite: payload.invite || null,
            response: payload.response || null,
            bundle: payload.bundle || null,
        };

        if (activeSurvey.value.state !== 'pending') {
            surveyDraft.value = {
                csat_score: null,
                nps_score: null,
                comment: '',
            };
        }
    } catch (error) {
        const status = Number(error?.response?.status || 0);
        if (status !== 404) {
            supportLogger.warn('survey.fetch.failure', 'Failed to load customer survey state.', summarizeError(error));
        }
        resetSurveyState();
    } finally {
        isLoadingSurvey.value = false;
    }
}

async function submitConversationSurvey() {
    const conversationId = activeConversation.value?.id;
    if (!conversationId || !surveyCanSubmit.value) {
        return;
    }

    isSubmittingSurvey.value = true;
    try {
        const payload = {
            comment: (surveyDraft.value.comment || '').trim() || null,
        };
        if (pendingCsatInvite.value) {
            payload.csat_score = Number(surveyDraft.value.csat_score);
        }
        if (pendingNpsInvite.value) {
            payload.nps_score = Number(surveyDraft.value.nps_score);
        }
        if (activeGuestToken.value) {
            payload.guest_token = activeGuestToken.value;
        }

        await api.post(`/api/support/chats/${conversationId}/survey`, payload);
        await loadConversationSurvey(conversationId, activeGuestToken.value);
        surveyDraft.value = {
            csat_score: null,
            nps_score: null,
            comment: '',
        };

        toast.success('Thanks for your feedback', 'Your survey response was recorded.');
    } catch (error) {
        const status = Number(error?.response?.status || 0);
        if (status === 409) {
            await loadConversationSurvey(conversationId, activeGuestToken.value);
            toast.error('Survey unavailable', error?.response?.data?.message || 'This survey is no longer available.');
        } else if (status === 429) {
            startSendCooldown(extractRetryAfterSeconds(error, 30));
            toast.error('Please slow down', `You are sending too quickly. Try again in ${sendCooldownSeconds.value}s.`);
        } else {
            toast.error('Error', error?.response?.data?.message || 'Unable to submit survey.');
        }
        supportLogger.warn('survey.submit.failure', 'Failed to submit customer survey.', summarizeError(error));
    } finally {
        isSubmittingSurvey.value = false;
    }
}

async function updateSurveyPreference(optOut) {
    const conversationId = activeConversation.value?.id;
    if (!conversationId || isUpdatingSurveyPreference.value) {
        return;
    }

    isUpdatingSurveyPreference.value = true;
    try {
        const payload = {
            opt_out: Boolean(optOut),
        };
        if (activeGuestToken.value) {
            payload.guest_token = activeGuestToken.value;
        }

        const response = await api.post(`/api/support/chats/${conversationId}/survey-preference`, payload);
        if (response.data?.data?.conversation) {
            activeConversation.value = response.data.data.conversation;
            syncConversationToHistory(activeConversation.value);
        } else if (activeConversation.value) {
            activeConversation.value = {
                ...activeConversation.value,
                survey_opt_out: Boolean(optOut),
                survey_opt_out_at: Boolean(optOut) ? new Date().toISOString() : null,
            };
        }

        await loadConversationSurvey(conversationId, activeGuestToken.value);
        toast.success('Survey preference updated', Boolean(optOut) ? 'We will not ask for surveys in this chat.' : 'Surveys are enabled for this chat.');
    } catch (error) {
        toast.error('Error', error?.response?.data?.message || 'Unable to update survey preference.');
    } finally {
        isUpdatingSurveyPreference.value = false;
    }
}

async function endConversation() {
    const conversationId = activeConversation.value?.id;
    if (!conversationId || isEndingConversation.value || isConversationClosed.value) {
        return;
    }

    isEndingConversation.value = true;
    showEmojiPicker.value = false;
    try {
        const payload = {};
        if (activeGuestToken.value) {
            payload.guest_token = activeGuestToken.value;
        }
        if (!isAuthenticated.value) {
            payload.ended_by_name = userDisplayName();
        }

        const response = await api.post(`/api/support/chats/${conversationId}/end`, payload);
        applyRealtimeMeta(response.data?.meta);
        if (response.data?.data) {
            activeConversation.value = response.data.data;
            syncConversationToHistory(activeConversation.value);
        }

        await loadConversationMessages(conversationId, activeGuestToken.value, { mergeLatest: true, wasNearBottom: true, trackUnread: false });
        await loadConversationSurvey(conversationId, activeGuestToken.value);
        await loadConversationHistory();
        toast.success('Chat ended', 'This support conversation is now closed.');
    } catch (error) {
        toast.error('Error', error?.response?.data?.message || 'Unable to end this support conversation.');
    } finally {
        isEndingConversation.value = false;
    }
}

async function retryPendingMessage(messageId) {
    if (isSending.value) {
        return;
    }

    isSending.value = true;
    try {
        await transmitPendingMessage(messageId, { showErrorToast: true });
    } finally {
        isSending.value = false;
    }
}

function clearRealtimeSubscription({ clearToken = true } = {}) {
    const echo = (window).Echo;
    if (echo && supportRealtimeChannelName) {
        echo.leave(supportRealtimeChannelName);
    }
    supportRealtimeChannelName = '';
    if (clearToken) {
        setSupportRealtimeToken(null, 'customer');
    }
    supportLogger.debug('realtime.unsubscribe', 'Cleared support realtime subscription.', {
        cleared_token: clearToken,
    });
}

function syncConversationToHistory(conversation) {
    if (!conversation?.id) {
        return;
    }

    const record = {
        id: conversation.id,
        token: activeGuestToken.value,
        status: conversation.status,
        last_message: conversation.latest_message?.body || '',
        updated_at: conversation.last_message_at || conversation.updated_at || new Date().toISOString(),
        guest_name: conversation.guest_name || userDisplayName(),
        guest_email: conversation.guest_email || userEmail(),
    };

    upsertHistoryRecord(record);

    const index = historyItems.value.findIndex((item) => item.id === conversation.id);
    if (index !== -1) {
        historyItems.value[index] = {
            ...historyItems.value[index],
            ...record,
        };
    }
}

async function handleRealtimeMessage(event) {
    if (!event?.conversation_id || event.conversation_id !== activeConversation.value?.id) {
        return;
    }

    const incoming = event?.message;
    if (!incoming?.id) {
        supportLogger.warn('realtime.message.skip', 'Received SupportMessageCreated without message payload.', {
            event,
        });
        return;
    }
    supportLogger.debug('realtime.message.received', 'Received SupportMessageCreated event.', {
        conversation_id: event?.conversation_id,
        message_id: incoming.id,
        sender_type: incoming.sender_type,
    });

    const wasNearBottom = isNearBottom();
    const existingIndex = conversationMessages.value.findIndex((message) => message.id === incoming.id);
    const shouldPlayIncomingSound = existingIndex === -1 && ['agent', 'bot'].includes(String(incoming.sender_type || ''));
    if (shouldPlayIncomingSound) {
        const played = playSupportMessageSound();
        supportLogger.debug('realtime.sound.triggered', 'Processed widget sound trigger for incoming support message.', {
            conversation_id: event?.conversation_id,
            message_id: incoming.id,
            sender_type: incoming.sender_type,
            played,
        });
    }

    if (existingIndex === -1) {
        conversationMessages.value = [...conversationMessages.value, incoming]
            .sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime());
    } else {
        conversationMessages.value[existingIndex] = {
            ...conversationMessages.value[existingIndex],
            ...incoming,
        };
    }

    if (activeConversation.value) {
        activeConversation.value = {
            ...activeConversation.value,
            latest_message: incoming,
            last_message_at: incoming.created_at || activeConversation.value.last_message_at,
        };
        syncConversationToHistory(activeConversation.value);
    }

    if (existingIndex === -1) {
        if (!wasNearBottom) {
            unreadMessageCount.value += 1;
            if (!firstUnreadMessageId.value) {
                firstUnreadMessageId.value = incoming.id;
            }
            showJumpToLatest.value = true;
        } else {
            clearUnreadMarker();
            await scrollToBottom(false);
        }
    } else if (wasNearBottom) {
        await scrollToBottom(false);
    }
}

async function subscribeSupportRealtime() {
    const conversationId = activeConversation.value?.id;
    if (!conversationId) {
        clearRealtimeSubscription();
        return;
    }

    const channelName = `support.customer.${conversationId}`;
    if (supportRealtimeChannelName === channelName) {
        supportLogger.debug('realtime.subscribe.skip', 'Already subscribed to support channel.', {
            channel: channelName,
        });
        return;
    }

    clearRealtimeSubscription({ clearToken: false });

    const echo = startEcho() || (window).Echo;
    if (!echo) {
        supportLogger.warn('realtime.subscribe.skip', 'Echo instance not ready for support subscription.', {
            channel: channelName,
        });
        return;
    }

    if (!hasSupportRealtimeToken('customer')) {
        supportLogger.warn('realtime.subscribe.no_token', 'Skipping support subscription because customer token is missing.', {
            channel: channelName,
            conversation_id: conversationId,
        });
        scheduleSupportRealtimeRetry();
        return;
    }
    supportLogger.info('realtime.subscribe.start', 'Subscribing to support customer channel.', {
        channel: channelName,
        conversation_id: conversationId,
    });

    const conversationChannel = echo.private(channelName)
        .listen('.SupportMessageCreated', async (event) => {
            await handleRealtimeMessage(event);
        })
        .listen('.SupportConversationChanged', async (event) => {
            if (!event?.conversation_id || event.conversation_id !== activeConversation.value?.id) {
                return;
            }

            if (!activeConversation.value) {
                return;
            }

            const previousStatus = String(activeConversation.value.status || '');
            const previousAssigneeId = activeConversation.value?.assignee?.id || null;
            const incomingAssigneeId = event?.assigned_to || null;

            activeConversation.value = {
                ...activeConversation.value,
                status: event?.status || activeConversation.value.status,
                ai_handoff_required: Boolean(event?.ai_handoff_required),
                last_message_at: event?.last_message_at || activeConversation.value.last_message_at,
                updated_at: event?.updated_at || activeConversation.value.updated_at,
                assignee: incomingAssigneeId
                    ? { ...(activeConversation.value.assignee || {}), id: incomingAssigneeId }
                    : activeConversation.value.assignee,
            };
            syncConversationToHistory(activeConversation.value);

            const statusChanged = previousStatus !== String(activeConversation.value.status || '');
            const assigneeChanged = previousAssigneeId !== incomingAssigneeId;

            if (statusChanged || assigneeChanged) {
                const refreshed = await fetchConversation(activeConversation.value.id, activeGuestToken.value, {
                    withLoading: false,
                    applyRealtimeMeta: false,
                    loadMessages: false,
                });

                if (refreshed) {
                    activeConversation.value = {
                        ...activeConversation.value,
                        ...refreshed,
                    };
                    syncConversationToHistory(activeConversation.value);
                }
            }

            if (['resolved', 'closed'].includes(String(activeConversation.value?.status || ''))) {
                await loadConversationSurvey(activeConversation.value.id, activeGuestToken.value);
            }
        })
        .listen('.SupportTypingUpdated', (event) => {
            if (!event?.conversation_id || event.conversation_id !== activeConversation.value?.id) {
                return;
            }

            if (event?.actor_type !== 'agent') {
                return;
            }

            setAgentTyping(event?.actor_name || 'Support', Boolean(event?.is_typing));
        });

    if ((conversationChannel).subscription) {
        (conversationChannel).subscription.bind('pusher:subscription_succeeded', () => {
            supportRealtimeChannelName = channelName;
            supportRealtimeRetryAttempts = 0;
            supportLogger.info('realtime.subscribe.success', 'Support customer channel subscription succeeded.', {
                channel: channelName,
            });
        });

        (conversationChannel).subscription.bind('pusher:subscription_error', (error) => {
            console.error(`[SupportRealtime] Failed to subscribe ${channelName}`, error);
            if (supportRealtimeChannelName === channelName) {
                supportRealtimeChannelName = '';
            }
            supportLogger.error('realtime.subscribe.failure', 'Support customer channel subscription failed.', {
                channel: channelName,
                ...summarizeError(error),
            });
            scheduleSupportRealtimeRetry();
        });
    }

    supportRealtimeChannelName = channelName;
}

async function loadAvailability() {
    isLoadingAvailability.value = true;
    try {
        const response = await api.get('/api/support/chats/availability');
        availability.value = response.data?.data || availability.value;
    } catch {
        availability.value = {
            available: false,
            available_agents: 0,
            message: 'Support availability is temporarily unavailable.',
        };
    } finally {
        isLoadingAvailability.value = false;
    }
}

async function fetchConversation(conversationId, token = '', options = {}) {
    if (!conversationId) {
        return null;
    }

    const withLoading = options.withLoading !== false;
    const applyMeta = options.applyRealtimeMeta !== false;
    const shouldLoadMessages = options.loadMessages !== false;

    if (withLoading) {
        isLoadingConversation.value = true;
    }
    supportLogger.debug('conversation.fetch.start', 'Fetching support conversation.', {
        conversation_id: conversationId,
        with_loading: withLoading,
        apply_meta: applyMeta,
        load_messages: shouldLoadMessages,
        has_guest_token: Boolean(token),
    });

    try {
        const params = {};
        if (token) {
            params.guest_token = token;
        }

        const response = await api.get(`/api/support/chats/${conversationId}`, { params });
        if (applyMeta) {
            applyRealtimeMeta(response.data?.meta);
        }
        const conversation = response.data?.data || null;
        if (conversation && shouldLoadMessages) {
            await loadConversationMessages(conversationId, token);
        }
        supportLogger.info('conversation.fetch.success', 'Fetched support conversation.', {
            conversation_id: conversation?.id || conversationId,
            status: conversation?.status || null,
            messages_loaded: shouldLoadMessages,
        });
        return conversation;
    } catch (error) {
        supportLogger.error('conversation.fetch.failure', 'Failed to fetch support conversation.', {
            conversation_id: conversationId,
            ...summarizeError(error),
        });
        return null;
    } finally {
        if (withLoading) {
            isLoadingConversation.value = false;
        }
    }
}

async function loadConversationMessages(conversationId, token = '', options = {}) {
    if (!conversationId) {
        conversationMessages.value = [];
        hasMoreMessagesBefore.value = false;
        oldestMessageId.value = null;
        clearUnreadMarker();
        return;
    }
    supportLogger.debug('messages.fetch.start', 'Loading support conversation messages.', {
        conversation_id: conversationId,
        before: options.before || null,
        append_older: options.appendOlder === true,
        merge_latest: options.mergeLatest === true,
        has_guest_token: Boolean(token),
        limit: options.limit || 30,
    });

    const before = options.before || null;
    const appendOlder = options.appendOlder === true;
    const mergeLatest = options.mergeLatest === true;
    const trackUnread = options.trackUnread !== false;
    const wasNearBottom = typeof options.wasNearBottom === 'boolean'
        ? options.wasNearBottom
        : isNearBottom();
    const previousMessages = conversationMessages.value || [];
    const params = {
        limit: options.limit || 30,
    };

    if (before) {
        params.before = before;
    }
    if (token) {
        params.guest_token = token;
    }

    const response = await api.get(`/api/support/chats/${conversationId}/messages`, { params });
    const incoming = Array.isArray(response.data?.data) ? response.data.data : [];
    const meta = response.data?.meta || {};

    if (appendOlder) {
        const existingIds = new Set(conversationMessages.value.map((message) => message.id));
        const older = incoming.filter((message) => !existingIds.has(message.id));
        conversationMessages.value = [...older, ...conversationMessages.value];
    } else if (mergeLatest) {
        const combined = new Map();
        for (const message of conversationMessages.value) {
            combined.set(message.id, message);
        }
        for (const message of incoming) {
            combined.set(message.id, message);
        }
        conversationMessages.value = Array.from(combined.values())
            .sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime());
    } else {
        conversationMessages.value = incoming;
        clearUnreadMarker();
    }

    if (mergeLatest && trackUnread) {
        const previousIds = new Set(previousMessages.map((message) => message.id));
        const appendedMessages = conversationMessages.value.filter((message) => !previousIds.has(message.id));

        if (appendedMessages.length > 0) {
            if (!wasNearBottom) {
                unreadMessageCount.value += appendedMessages.length;
                if (!firstUnreadMessageId.value) {
                    firstUnreadMessageId.value = appendedMessages[0].id;
                }
                showJumpToLatest.value = true;
            } else {
                clearUnreadMarker();
            }
        }
    }

    hasMoreMessagesBefore.value = Boolean(meta.has_more_before);
    oldestMessageId.value = meta.oldest_id || conversationMessages.value[0]?.id || null;
    supportLogger.debug('messages.fetch.success', 'Support messages loaded.', {
        conversation_id: conversationId,
        incoming_count: incoming.length,
        total_local_count: conversationMessages.value.length,
        has_more_before: hasMoreMessagesBefore.value,
        oldest_message_id: oldestMessageId.value,
    });
}

async function loadOlderMessages() {
    const conversationId = activeConversation.value?.id;
    if (!conversationId || isLoadingOlderMessages.value || !hasMoreMessagesBefore.value || !oldestMessageId.value) {
        return;
    }

    isLoadingOlderMessages.value = true;
    const container = messagesContainer.value;
    const oldScrollHeight = container?.scrollHeight || 0;
    const oldScrollTop = container?.scrollTop || 0;

    try {
        await loadConversationMessages(conversationId, activeGuestToken.value, {
            before: oldestMessageId.value,
            appendOlder: true,
        });

        await nextTick();
        if (container) {
            container.scrollTop = oldScrollTop + (container.scrollHeight - oldScrollHeight);
        }
    } catch {
        // Ignore pagination failures and keep current messages.
    } finally {
        isLoadingOlderMessages.value = false;
    }
}

async function loadConversationHistory() {
    isLoadingHistory.value = true;

    const records = readHistoryRecords();
    if (records.length === 0) {
        historyItems.value = [];
        isLoadingHistory.value = false;
        return;
    }

    const invalidRecordIds = [];
    const items = (await Promise.all(records.map(async (record) => {
        const conversation = await fetchConversation(record.id, record.token || '', {
            withLoading: false,
            applyRealtimeMeta: false,
            loadMessages: false,
        });
        if (!conversation) {
            invalidRecordIds.push(record.id);
            return null;
        }

        const item = {
            id: conversation.id,
            token: record.token || '',
            last_message: conversation.latest_message?.body || 'No messages yet',
            updated_at: conversation.last_message_at || conversation.updated_at || conversation.created_at,
            status: conversation.status || 'open',
            guest_name: conversation.guest_name || record.guest_name || '',
            guest_email: conversation.guest_email || record.guest_email || '',
        };

        upsertHistoryRecord(item);
        return item;
    }))).filter(Boolean);

    if (invalidRecordIds.length > 0) {
        const invalidSet = new Set(invalidRecordIds);
        writeHistoryRecords(records.filter((record) => !invalidSet.has(record.id)));
    }

    historyItems.value = items;
    isLoadingHistory.value = false;
}

function goToForm() {
    if (isAuthenticated.value) {
        startNewChat();
    } else {
        viewState.value = 'form';
    }
}

async function goToHistory() {
    viewState.value = 'history';
    await loadConversationHistory();
}

function goBack() {
    if (viewState.value === 'chat') {
        if (historyItems.value.length > 0) {
            viewState.value = 'history';
        } else {
            viewState.value = 'intro';
        }
        return;
    }

    viewState.value = 'intro';
}

async function startChatFromForm() {
    if (!leadForm.value.name || !leadForm.value.email) {
        return;
    }

    await startNewChat();
}

async function startNewChat() {
    if (!isAuthenticated.value) {
        await clearGuestResumeSession();
    }

    pendingConversationStartMessage.value = '';
    showRecaptchaChallenge.value = false;
    activeConversation.value = null;
    resetSurveyState();
    conversationMessages.value = [];
    hasMoreMessagesBefore.value = false;
    oldestMessageId.value = null;
    clearAgentTypingIndicator();
    clearUnreadMarker();
    pendingOutgoingMessages.value = [];
    activeGuestToken.value = '';
    newMessage.value = '';
    viewState.value = 'chat';
    await scrollToBottom(false);
    await focusComposerInput();
}

async function selectConversation(item) {
    const token = item?.token || '';
    const conversation = await fetchConversation(item.id, token);
    if (!conversation) {
        removeHistoryRecord(item.id);
        toast.error('Error', 'Unable to load this conversation.');
        await loadConversationHistory();
        return;
    }

    activeConversation.value = conversation;
    activeGuestToken.value = token;
    clearAgentTypingIndicator();
    clearUnreadMarker();
    await subscribeSupportRealtime();
    await loadConversationSurvey(conversation.id, token);
    viewState.value = 'chat';
    await scrollToBottom(false);
    await focusComposerInput();
}

function buildOpenPayload(initialMessage, security = {}) {
    return {
        initial_message: initialMessage,
        subject: 'Live chat support request',
        channel: 'widget',
        source_url: window.location.href,
        guest_name: userDisplayName(),
        guest_email: userEmail(),
        website_url: honeypotWebsiteUrl.value || '',
        metadata: {
            page: window.location.pathname,
            user_agent: navigator.userAgent,
        },
        ...security,
    };
}

async function openConversationWithMessage(initialMessage, options = {}) {
    isStartingConversation.value = true;
    supportLogger.info('conversation.open.start', 'Starting new support conversation.', {
        initial_message_length: initialMessage?.length || 0,
        authenticated: isAuthenticated.value,
    });

    try {
        const securityPayload = {};
        if (!isAuthenticated.value) {
            if (options.v2Token) {
                securityPayload.recaptcha_token = 'fallback-initiated';
                securityPayload.recaptcha_v2_token = options.v2Token;
            } else if (isRecaptchaEnabled.value) {
                const recaptchaToken = await executeRecaptcha('support_chat_open');
                if (!recaptchaToken) {
                    toast.error('Security check failed', 'Please refresh and try again.');
                    return false;
                }
                securityPayload.recaptcha_token = recaptchaToken;
            }
        }

        const payload = buildOpenPayload(initialMessage, securityPayload);
        const response = await api.post('/api/support/chats', payload);
        applyRealtimeMeta(response.data?.meta);
        const conversation = response.data?.data || null;
        if (!conversation) {
            throw new Error('Conversation creation failed.');
        }

        const token = response.data?.data?.guest_token || '';
        activeConversation.value = conversation;
        activeGuestToken.value = token;
        resetSurveyState();
        await loadConversationMessages(conversation.id, token);
        clearAgentTypingIndicator();
        await subscribeSupportRealtime();
        await loadConversationSurvey(conversation.id, token);

        syncConversationToHistory({
            ...conversation,
            latest_message: conversation.latest_message || { body: initialMessage },
        });

        await loadConversationHistory();
        viewState.value = 'chat';
        await scrollToBottom(false);
        await focusComposerInput();
        supportLogger.info('conversation.open.success', 'Support conversation started successfully.', {
            conversation_id: conversation.id,
            status: conversation.status,
            has_guest_token: Boolean(token),
        });
        return true;
    } catch (error) {
        supportLogger.error('conversation.open.failure', 'Failed to start support conversation.', summarizeError(error));
        const status = Number(error?.response?.status || 0);
        if (status === 422 && Boolean(error?.response?.data?.requires_challenge) && !options.v2Token) {
            pendingConversationStartMessage.value = initialMessage;
            showRecaptchaChallenge.value = true;
            return false;
        }

        if (status === 429) {
            startSendCooldown(extractRetryAfterSeconds(error, 30));
            toast.error('Please slow down', `You are sending too quickly. Try again in ${sendCooldownSeconds.value}s.`);
        } else {
            toast.error('Error', error.response?.data?.message || 'Unable to start support conversation.');
        }
        return false;
    } finally {
        isStartingConversation.value = false;
    }
}

async function handleRecaptchaChallengeVerified(v2Token) {
    showRecaptchaChallenge.value = false;
    const message = String(pendingConversationStartMessage.value || '').trim();
    if (message === '') {
        return;
    }

    const started = await openConversationWithMessage(message, { v2Token });
    if (started) {
        newMessage.value = '';
        pendingConversationStartMessage.value = '';
    }
}

async function clearGuestResumeSession() {
    try {
        await api.post('/api/support/chats/resume/clear');
    } catch {
        // Ignore cleanup failures; user can still start a new conversation.
    }
}

async function claimGuestConversationForAuthenticatedUser() {
    if (!isAuthenticated.value) {
        return null;
    }

    try {
        supportLogger.info('conversation.claim.start', 'Attempting to claim guest conversation for authenticated user.');
        const response = await api.post('/api/support/chats/claim-guest');
        applyRealtimeMeta(response.data?.meta);
        supportLogger.info('conversation.claim.success', 'Guest conversation claim completed.', {
            conversation_id: response.data?.data?.id || null,
        });
        return response.data?.data || null;
    } catch (error) {
        supportLogger.warn('conversation.claim.skip', 'Guest conversation claim failed or no claimable session.', summarizeError(error));
        // Optional claim flow; failures should not block user messaging.
        return null;
    }
}

async function resumeGuestConversation() {
    if (isAuthenticated.value || isResumingConversation.value || activeConversation.value) {
        return;
    }

    isResumingConversation.value = true;
    supportLogger.info('conversation.resume.start', 'Attempting to resume guest support conversation.');

    try {
        const response = await api.get('/api/support/chats/resume');
        applyRealtimeMeta(response.data?.meta);

        const conversation = response.data?.data || null;
        if (!conversation?.id) {
            supportLogger.debug('conversation.resume.empty', 'No resumable guest conversation found.');
            return;
        }

        activeConversation.value = conversation;
        activeGuestToken.value = conversation.guest_token || '';
        resetSurveyState();
        await loadConversationMessages(conversation.id, activeGuestToken.value);
        clearAgentTypingIndicator();
        syncConversationToHistory(conversation);
        await subscribeSupportRealtime();
        await loadConversationSurvey(conversation.id, activeGuestToken.value);

        if (chatStore.isOpen) {
            viewState.value = 'chat';
            await scrollToBottom(false);
            await focusComposerInput();
        }
        supportLogger.info('conversation.resume.success', 'Resumed guest support conversation.', {
            conversation_id: conversation.id,
            status: conversation.status,
        });
    } catch (error) {
        supportLogger.warn('conversation.resume.failure', 'Failed to resume guest support conversation.', summarizeError(error));
        // Silent fallback to regular guest start flow.
    } finally {
        isResumingConversation.value = false;
    }
}

async function sendMessage() {
    const body = newMessage.value.trim();
    const hasFiles = selectedFiles.value.length > 0;
    if ((!body && !hasFiles) || isSending.value || isStartingConversation.value || isConversationClosed.value || isSendCoolingDown.value) {
        supportLogger.debug('message.send.skip', 'Skipping send message due to invalid state.', {
            has_body: Boolean(body),
            has_files: hasFiles,
            is_sending: isSending.value,
            is_starting: isStartingConversation.value,
            is_closed: isConversationClosed.value,
            is_cooling_down: isSendCoolingDown.value,
            has_active_conversation: Boolean(activeConversation.value?.id),
        });
        return;
    }

    showEmojiPicker.value = false;

    if (!activeConversation.value) {
        if (!body) {
            toast.error('Message required', 'Please add a first message before sending attachments.');
            return;
        }

        if (!isAuthenticated.value && (!leadForm.value.name || !leadForm.value.email)) {
            viewState.value = 'form';
            toast.error('Details required', 'Please provide your name and email before starting chat.');
            return;
        }

        const started = await openConversationWithMessage(body);
        if (started) {
            newMessage.value = '';
            await focusComposerInput();
        }
        return;
    }

    isSending.value = true;
    try {
        if (typingDebounceTimer) {
            clearTimeout(typingDebounceTimer);
            typingDebounceTimer = null;
        }
        await emitTyping(false);

        const shouldStickToBottom = isNearBottom();
        const pending = queuePendingMessage(body);
        if (!pending) {
            return;
        }

        newMessage.value = '';
        clearSelectedFiles();
        await focusComposerInput();

        if (shouldStickToBottom) {
            await scrollToBottom(false);
        }

        await transmitPendingMessage(pending.id, { showErrorToast: true });
    } catch (error) {
        supportLogger.error('message.queue.failure', 'Failed to queue/send support message.', summarizeError(error));
        toast.error('Error', 'Unable to queue message.');
    } finally {
        isSending.value = false;
        await focusComposerInput();
    }
}

watch(
    () => chatStore.isOpen,
    async (newValue) => {
        if (!newValue) {
            return;
        }

        await loadAvailability();
        await resumeGuestConversation();
        await loadConversationHistory();
        if (!isAuthenticated.value && activeConversation.value?.id) {
            viewState.value = 'chat';
        }
        await scrollToBottom(false);
    },
);

watch(
    () => currentUser.value?.public_id,
    async (newPublicId, oldPublicId) => {
        const shouldAttemptClaim = Boolean(newPublicId && !oldPublicId);
        const claimedConversation = shouldAttemptClaim
            ? await claimGuestConversationForAuthenticatedUser()
            : null;

        clearRealtimeSubscription({ clearToken: !claimedConversation?.id });
        clearAgentTypingIndicator();
        activeConversation.value = null;
        resetSurveyState();
        conversationMessages.value = [];
        hasMoreMessagesBefore.value = false;
        oldestMessageId.value = null;
        clearUnreadMarker();
        pendingOutgoingMessages.value = [];
        activeGuestToken.value = '';
        leadForm.value = {
            name: currentUser.value?.name || '',
            email: currentUser.value?.email || '',
        };

        await loadConversationHistory();

        if (claimedConversation?.id) {
            activeConversation.value = claimedConversation;
            activeGuestToken.value = '';
            clearUnreadMarker();
            await loadConversationMessages(claimedConversation.id, '', { trackUnread: false });
            syncConversationToHistory(claimedConversation);
            await subscribeSupportRealtime();
            await loadConversationSurvey(claimedConversation.id, '');

            if (chatStore.isOpen) {
                viewState.value = 'chat';
                await scrollToBottom(false);
            }
        }
    },
    { immediate: true },
);

watch(
    () => activeConversation.value?.id,
    async (conversationId) => {
        await subscribeSupportRealtime();

        if (conversationId) {
            await loadConversationSurvey(conversationId, activeGuestToken.value);
        } else {
            resetSurveyState();
        }
    },
);

watch(
    () => mappedMessages.value.length,
    async (nextLength, previousLength) => {
        if (viewState.value !== 'chat' || nextLength <= previousLength) {
            return;
        }

        if (!showJumpToLatest.value && !isLoadingOlderMessages.value) {
            await scrollToBottom(false);
        }
    },
);

onMounted(async () => {
    supportLogger.info('lifecycle.mounted', 'Support live chat widget mounted.');
    await loadAvailability();
    await resumeGuestConversation();
    await loadConversationHistory();
    window.addEventListener('echo:connected', handleEchoConnected);
    window.addEventListener('pointerdown', handleGlobalPointerDown);

    availabilityTimer = setInterval(() => {
        loadAvailability();
    }, 60000);

    realtimeTokenRefreshTimer = setInterval(async () => {
        if (!activeConversation.value?.id) {
            return;
        }

        const refreshed = await fetchConversation(activeConversation.value.id, activeGuestToken.value, {
            withLoading: false,
            applyRealtimeMeta: true,
            loadMessages: false,
        });

        if (refreshed) {
            const wasNearBottom = isNearBottom();
            activeConversation.value = refreshed;
            await loadConversationMessages(refreshed.id, activeGuestToken.value, { mergeLatest: true, wasNearBottom });
            syncConversationToHistory(refreshed);
            if (wasNearBottom) {
                await scrollToBottom(false);
            }
        }
    }, 240000);
});

onBeforeUnmount(() => {
    supportLogger.info('lifecycle.unmount', 'Support live chat widget unmounting; clearing timers/subscriptions.');
    if (availabilityTimer) {
        clearInterval(availabilityTimer);
        availabilityTimer = null;
    }

    if (realtimeTokenRefreshTimer) {
        clearInterval(realtimeTokenRefreshTimer);
        realtimeTokenRefreshTimer = null;
    }

    if (realtimeSubscriptionRetryTimer) {
        clearTimeout(realtimeSubscriptionRetryTimer);
        realtimeSubscriptionRetryTimer = null;
    }

    if (typingResetTimer) {
        clearTimeout(typingResetTimer);
        typingResetTimer = null;
    }

    if (typingDebounceTimer) {
        clearTimeout(typingDebounceTimer);
        typingDebounceTimer = null;
    }

    if (sendCooldownTimer) {
        clearInterval(sendCooldownTimer);
        sendCooldownTimer = null;
    }

    clearAgentTypingIndicator();
    clearSelectedFiles();
    clearRealtimeSubscription();
    window.removeEventListener('echo:connected', handleEchoConnected);
    window.removeEventListener('pointerdown', handleGlobalPointerDown);
});
</script>

<template>
    <div class="fixed bottom-6 right-6 z-[10001]">
        
        <!-- Chat Window -->
        <Transition
            enter-active-class="transition duration-300 ease-out origin-bottom-right"
            enter-from-class="transform scale-90 opacity-0"
            enter-to-class="transform scale-100 opacity-100"
            leave-active-class="transition duration-200 ease-in origin-bottom-right"
            leave-from-class="transform scale-100 opacity-100"
            leave-to-class="transform scale-90 opacity-0 pointer-events-none"
        >
            <div 
                v-if="isOpen" 
                class="absolute bottom-0 right-0 w-[min(320px,calc(100vw-24px))] h-[520px] max-h-[calc(100vh-96px)] bg-[var(--surface-primary)] rounded-xl shadow-2xl flex flex-col overflow-hidden border border-[var(--border-default)] flex-shrink-0"
            >
                <!-- Support Desk Header -->
                <div class="shrink-0 border-b border-[var(--border-default)] bg-[var(--surface-secondary)] px-2.5 py-2">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-1.5">
                            <button
                                v-if="viewState !== 'intro'"
                                @click="goBack"
                                class="rounded-md p-1 text-[var(--text-muted)] transition-colors hover:bg-[var(--surface-tertiary)] hover:text-[var(--text-primary)]"
                            >
                                <ChevronLeft class="h-3.5 w-3.5" />
                            </button>
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-[var(--interactive-primary)]/25 bg-[var(--interactive-primary)]/12">
                                <svg
                                    class="h-3.5 w-3.5 text-[var(--interactive-primary)]"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="2.5"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M13 10V3L4 14h7v7l9-11h-7z"
                                    />
                                </svg>
                            </div>
                            <div
                                v-if="showConversationHeaderMeta"
                                class="min-w-0"
                            >
                                <p class="truncate text-[9.5px] leading-[1.2] text-[var(--text-secondary)]">
                                    {{ talkingToLabel }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button
                                v-if="viewState === 'chat' && activeConversation?.id && !isConversationClosed"
                                type="button"
                                class="rounded-md border border-[var(--border-default)] bg-[var(--surface-primary)] px-2 py-1 text-[10px] font-semibold text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-tertiary)] hover:text-[var(--text-primary)] disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="isEndingConversation"
                                @click="endConversation"
                            >
                                {{ isEndingConversation ? 'Ending...' : 'End' }}
                            </button>
                            <button
                                @click="toggleChat"
                                class="rounded-md p-1 text-[var(--text-muted)] transition-colors hover:bg-[var(--surface-tertiary)] hover:text-[var(--text-primary)]"
                            >
                                <Minus class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Content Area -->
                <div class="flex-1 overflow-hidden flex flex-col bg-[var(--surface-primary)] relative">
                    
                    <!-- Intro View -->
                    <div v-if="viewState === 'intro'" class="flex-1 flex flex-col p-4 items-center justify-center text-center space-y-4 bg-[var(--surface-primary)]">
                        <div class="w-16 h-16 bg-blue-50 dark:bg-blue-900/20 rounded-full flex items-center justify-center mb-1">
                             <MessageSquare class="w-8 h-8 text-[var(--interactive-primary)]" />
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-[var(--text-primary)]">Welcome!</h2>
                            <p class="text-[var(--text-secondary)] text-xs mt-1.5 px-2">{{ availability.message }}</p>
                        </div>
                        
                        <div class="w-full space-y-2.5 pt-2">
                            <Button @click="goToForm" class="w-full h-10 rounded-lg text-sm font-semibold shadow-md">
                                Start new conversation
                            </Button>
                            <Button v-if="isAuthenticated" variant="outline" @click="goToHistory" class="w-full h-10 rounded-lg text-sm font-semibold border-[var(--border-default)]">
                                <History class="w-4 h-4 mr-2" />
                                View previous chats
                            </Button>
                        </div>
                    </div>

                    <!-- Lead Form View (For Guests) -->
                    <div v-else-if="viewState === 'form'" class="flex-1 flex flex-col p-4 space-y-4">
                        <div class="space-y-2">
                            <h3 class="text-base font-bold text-[var(--text-primary)]">Give us a few details</h3>
                            <p class="text-xs text-[var(--text-secondary)]">We'll use this to get back to you if we're offline.</p>
                        </div>
                        
                        <div class="space-y-3">
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold text-[var(--text-muted)] uppercase ml-1">Your Name</label>
                                <div class="relative flex items-center bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-default)] focus-within:border-[var(--interactive-primary)] transition-all px-3 py-2.5">
                                    <User class="w-4 h-4 text-[var(--text-muted)] mr-2.5" />
                                    <input v-model="leadForm.name" type="text" placeholder="John Doe" class="bg-transparent border-none text-sm w-full focus:outline-none" />
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold text-[var(--text-muted)] uppercase ml-1">Email Address</label>
                                <div class="relative flex items-center bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-default)] focus-within:border-[var(--interactive-primary)] transition-all px-3 py-2.5">
                                    <Mail class="w-4 h-4 text-[var(--text-muted)] mr-2.5" />
                                    <input v-model="leadForm.email" type="email" placeholder="john@example.com" class="bg-transparent border-none text-sm w-full focus:outline-none" />
                                </div>
                            </div>
                            <div style="display: none" aria-hidden="true">
                                <input
                                    v-model="honeypotWebsiteUrl"
                                    type="text"
                                    name="website_url"
                                    tabindex="-1"
                                    autocomplete="off"
                                />
                            </div>
                        </div>
                        
                        <Button @click="startChatFromForm" :disabled="!leadForm.name || !leadForm.email" class="w-full h-10 rounded-lg text-sm font-semibold shadow-md mt-auto">
                            Start Chatting
                        </Button>
                        <p class="text-[10px] text-[var(--text-muted)] leading-relaxed">
                            Protected by reCAPTCHA. Google
                            <a href="https://policies.google.com/privacy" class="underline" target="_blank" rel="noopener noreferrer">Privacy Policy</a>
                            and
                            <a href="https://policies.google.com/terms" class="underline" target="_blank" rel="noopener noreferrer">Terms</a>
                            apply.
                        </p>
                    </div>

                    <!-- History View (For Auth Users) -->
                    <div v-else-if="viewState === 'history'" class="flex-1 flex flex-col p-3 bg-[var(--surface-secondary)]/30">
                        <div class="flex justify-between items-center mb-3 px-1.5">
                            <h3 class="font-bold text-[var(--text-primary)]">Previous Chats</h3>
                            <Button variant="ghost" size="sm" @click="startNewChat" class="h-8 text-[var(--interactive-primary)] hover:text-[var(--interactive-hover)]">
                                <PlusCircle class="w-4 h-4 mr-1.5" />
                                New
                            </Button>
                        </div>
                        
                        <div v-if="isLoadingHistory" class="flex-1 flex items-center justify-center text-[var(--text-secondary)] text-sm">
                            <Loader2 class="w-4 h-4 mr-2 animate-spin" />
                            Loading conversations...
                        </div>

                        <div v-else class="space-y-2 overflow-y-auto pr-1">
                            <div 
                                v-for="item in historyItems" 
                                :key="item.id"
                                @click="selectConversation(item)"
                                class="p-3 bg-[var(--surface-primary)] rounded-lg border border-[var(--border-default)] hover:border-[var(--interactive-primary)] cursor-pointer transition-all shadow-sm"
                            >
                                <div class="flex justify-between items-start mb-1">
                                    <span class="text-xs font-bold text-[var(--text-primary)]">Support #{{ item.id?.slice(-6) }}</span>
                                    <span class="text-[10px] text-[var(--text-muted)]">{{ formatRelativeTime(item.updated_at) }}</span>
                                </div>
                                <p class="text-xs text-[var(--text-secondary)] line-clamp-1 truncate">{{ item.last_message }}</p>
                                <div class="flex items-center mt-2">
                                    <div class="w-1.5 h-1.5 rounded-full mr-2" :class="item.status === 'resolved' ? 'bg-emerald-500' : 'bg-amber-500'"></div>
                                    <span class="text-[10px] uppercase font-bold text-[var(--text-muted)]">{{ statusLabel(item.status) }}</span>
                                </div>
                            </div>
                        </div>

                        <div v-if="!isLoadingHistory && historyItems.length === 0" class="flex-1 flex flex-col items-center justify-center text-center opacity-50 space-y-3">
                             <History class="w-10 h-10 text-[var(--text-muted)]" />
                             <p class="text-sm font-medium">No previous conversations</p>
                        </div>
                    </div>

                    <!-- Active Chat View -->
                    <div v-else-if="viewState === 'chat'" class="flex-1 flex flex-col overflow-hidden">
                        <!-- Messages -->
                        <div 
                            ref="messagesContainer"
                            class="flex-1 overflow-y-auto p-4 pt-5 space-y-4 bg-[var(--surface-primary)] relative"
                            @scroll="onMessagesScroll"
                        >
                            <!-- Background Pattern -->
                            <div class="absolute inset-0 pointer-events-none opacity-[0.02]" 
                                 style="background-image: radial-gradient(circle at 50% 50%, var(--text-primary) 1px, transparent 1px); background-size: 20px 20px;">
                            </div>

                            <div v-if="isLoadingConversation" class="h-full flex items-center justify-center text-sm text-[var(--text-secondary)]">
                                <Loader2 class="w-4 h-4 mr-2 animate-spin" />
                                Loading conversation...
                            </div>

                            <div v-else-if="isResumingConversation" class="h-full flex items-center justify-center text-sm text-[var(--text-secondary)]">
                                <Loader2 class="w-4 h-4 mr-2 animate-spin" />
                                Resuming your previous support chat...
                            </div>

                            <div v-else-if="!activeConversation && !isStartingConversation" class="h-full flex flex-col items-center justify-center text-center px-6 text-[var(--text-secondary)]">
                                <MessageSquare class="w-8 h-8 mb-2 opacity-60" />
                                <p class="text-sm">{{ starterHint }}</p>
                            </div>

                            <div v-if="isLoadingOlderMessages" class="flex items-center justify-center text-[var(--text-secondary)] text-xs relative z-10">
                                <Loader2 class="w-3.5 h-3.5 mr-2 animate-spin" />
                                Loading older messages...
                            </div>

                            <div
                                v-for="message in mappedMessages"
                                :key="message.id"
                                :id="message.type === 'divider' ? undefined : `support-widget-message-${message.id}`"
                                class="flex flex-col relative z-10"
                                :class="message.type === 'visitor' ? 'items-end' : (message.type === 'system' ? 'items-center' : 'items-start')"
                            >
                                <div v-if="message.type === 'divider'" class="w-full flex items-center gap-2.5 my-2">
                                    <div class="h-px flex-1 bg-[var(--border-default)]/70"></div>
                                    <span class="text-[10px] uppercase tracking-wider font-semibold text-[var(--interactive-primary)]">
                                        {{ message.content }}
                                        <span class="ml-1 normal-case text-[var(--text-muted)]">({{ message.unreadCount }})</span>
                                    </span>
                                    <div class="h-px flex-1 bg-[var(--border-default)]/70"></div>
                                </div>

                                <div v-else-if="message.type === 'system'" class="flex max-w-[92%] flex-col items-center">
                                    <div class="rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]/80 px-3 py-2 text-xs leading-5 text-[var(--text-secondary)] shadow-sm">
                                        <div
                                            v-if="messageContentForDisplay(message)"
                                            class="support-rich-content break-words text-center"
                                            v-html="messageContentHtml(message)"
                                        ></div>
                                    </div>
                                    <span class="mt-1 text-[10px] text-[var(--text-muted)]">{{ message.time }}</span>
                                </div>

                                <div v-else-if="message.type === 'agent'" class="flex items-end gap-2 max-w-[85%]">
                                    <Avatar
                                        :src="message.avatarUrl"
                                        :thumb-url="message.avatarThumbUrl"
                                        :alt="message.agentName"
                                        :fallback="message.agentName?.charAt(0) || 'S'"
                                        :color="message.avatarColor || 'var(--surface-tertiary)'"
                                        size="xs"
                                        class="mb-1 flex-shrink-0"
                                    />
                                    <div class="flex flex-col">
                                        <span class="text-[10px] text-[var(--text-muted)] ml-1 mb-0.5">{{ message.agentName }}</span>
                                        <div class="bg-[var(--surface-secondary)] text-[var(--text-primary)] px-3.5 py-2.5 rounded-2xl rounded-bl-sm text-sm border border-[var(--border-default)] shadow-sm">
                                            <div
                                                v-if="messageContentForDisplay(message)"
                                                class="support-rich-content break-words"
                                                v-html="messageContentHtml(message)"
                                            ></div>
                                            <div v-if="message.firstUrl" class="mt-2">
                                                <LinkPreview
                                                    :url="message.firstUrl"
                                                    api-url="/api/support/chats/link/unfurl"
                                                    @unsafe="markMessageUnsafe(message.id)"
                                                />
                                            </div>
                                            <SupportMessageAttachments
                                                v-if="Array.isArray(message.attachments) && message.attachments.length > 0"
                                                class="mt-2"
                                                :attachments="message.attachments"
                                                tone="theirs"
                                                compact
                                            />
                                        </div>
                                        <span class="text-[10px] text-[var(--text-muted)] mt-1 ml-1">{{ message.time }}</span>
                                    </div>
                                </div>

                                <div v-else class="flex flex-col items-end max-w-[88%]">
                                    <div class="bg-[var(--interactive-primary)] text-white px-3.5 py-2.5 rounded-2xl rounded-br-sm text-sm shadow-sm border border-black/5 dark:border-white/5">
                                        <div
                                            v-if="messageContentForDisplay(message)"
                                            class="support-rich-content break-words"
                                            v-html="messageContentHtml(message)"
                                        ></div>
                                        <div v-if="message.firstUrl" class="mt-2">
                                            <LinkPreview
                                                :url="message.firstUrl"
                                                api-url="/api/support/chats/link/unfurl"
                                                @unsafe="markMessageUnsafe(message.id)"
                                            />
                                        </div>
                                        <SupportMessageAttachments
                                            v-if="Array.isArray(message.attachments) && message.attachments.length > 0"
                                            class="mt-2"
                                            :attachments="message.attachments"
                                            tone="mine"
                                            compact
                                        />
                                    </div>
                                    <span class="text-[10px] text-[var(--text-muted)] mt-1 mr-1">{{ message.time }}</span>
                                </div>
                            </div>

                            <div
                                v-for="pending in pendingMessagesForActiveConversation"
                                :key="pending.id"
                                class="flex flex-col items-end relative z-10"
                            >
                                <div class="bg-[var(--interactive-primary)] text-white px-3.5 py-2.5 rounded-2xl rounded-br-sm text-sm shadow-sm border border-black/5 dark:border-white/5 max-w-[85%]">
                                    <div
                                        v-if="pending.body"
                                        class="support-rich-content break-words"
                                        v-html="pendingMessageContentHtml(pending)"
                                    ></div>
                                    <p v-if="pending.fileNames?.length" class="mt-1 text-[11px] text-white/90">
                                        {{ pending.fileNames.length }} attachment<span v-if="pending.fileNames.length > 1">s</span>
                                    </p>
                                </div>
                                <div class="mt-1 mr-1 flex items-center gap-2 text-[10px] text-[var(--text-muted)]">
                                    <template v-if="pending.status === 'sending'">
                                        <Loader2 class="w-3 h-3 animate-spin" />
                                        <span>Sending...</span>
                                    </template>
                                    <template v-else>
                                        <span class="text-red-500 dark:text-red-400">Failed to send.</span>
                                        <button
                                            type="button"
                                            class="underline underline-offset-2 hover:opacity-80 disabled:cursor-not-allowed disabled:opacity-50"
                                            :disabled="isSendCoolingDown"
                                            @click="retryPendingMessage(pending.id)"
                                        >
                                            Retry
                                        </button>
                                    </template>
                                </div>
                            </div>

                            <div v-if="showJumpToLatest" class="sticky bottom-2 z-20 flex justify-center pointer-events-none">
                                <button
                                    type="button"
                                    class="pointer-events-auto relative inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-900 bg-slate-900 text-white shadow-sm transition-colors hover:bg-slate-800 dark:border-slate-200 dark:bg-slate-200 dark:text-slate-900 dark:hover:bg-slate-300"
                                    :title="unreadMessageCount > 0 ? 'Jump to first unread message' : 'Jump to latest message'"
                                    @click="handleJumpToLatestClick"
                                >
                                    <ChevronsDown class="h-4 w-4" />
                                    <span
                                        v-if="unreadMessageCount > 0"
                                        class="absolute -right-1 -top-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-[var(--interactive-primary)] px-1 text-[9px] font-semibold leading-none text-white"
                                    >
                                        {{ unreadMessageCount > 99 ? '99+' : unreadMessageCount }}
                                    </span>
                                </button>
                            </div>
                        </div>

                        <!-- Input Area -->
                        <div class="p-2.5 bg-[var(--surface-primary)] border-t border-[var(--border-default)] shrink-0">
                            <div v-if="agentTypingIndicator" class="px-2 pb-2">
                                <div class="support-typing-pill">
                                    <div class="support-typing-dots">
                                        <span class="support-typing-dot support-typing-delay-1"></span>
                                        <span class="support-typing-dot support-typing-delay-2"></span>
                                        <span class="support-typing-dot"></span>
                                    </div>
                                    <span class="text-xs text-[var(--text-secondary)]">{{ agentTypingIndicator }}</span>
                                </div>
                            </div>

                            <div
                                v-if="showSurveyPanel"
                                class="mb-3 rounded-xl border border-slate-300/85 bg-white/92 p-3 shadow-[0_1px_0_rgba(15,23,42,0.05)] ring-1 ring-slate-200/70 dark:border-[var(--border-default)] dark:bg-[var(--surface-secondary)]/60 dark:shadow-none dark:ring-transparent"
                            >
                                <div v-if="isLoadingSurvey" class="flex items-center text-xs text-[var(--text-secondary)]">
                                    <Loader2 class="mr-2 h-3.5 w-3.5 animate-spin" />
                                    Loading survey...
                                </div>

                                <template v-else-if="hasPendingSurvey">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--interactive-primary)]">Support Survey</p>
                                    <div v-if="pendingCsatInvite" class="mt-2">
                                        <p class="text-[11px] font-medium text-[var(--text-primary)]">{{ pendingCsatInvite.definition?.question || 'How satisfied are you with this support conversation?' }}</p>
                                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                                            <button
                                                v-for="value in csatScaleValues"
                                                :key="`survey-csat-score-${value}`"
                                                type="button"
                                                class="min-w-8 rounded-md border px-2 py-1 text-xs font-semibold transition-colors"
                                                :class="Number(surveyDraft.csat_score) === Number(value)
                                                    ? 'border-[var(--interactive-primary)] bg-[var(--interactive-primary)] text-white'
                                                    : 'border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--text-secondary)] hover:border-[var(--interactive-primary)]'"
                                                @click="surveyDraft.csat_score = Number(value)"
                                            >
                                                {{ value }}
                                            </button>
                                        </div>
                                    </div>
                                    <div v-if="pendingNpsInvite" class="mt-3">
                                        <p class="text-[11px] font-medium text-[var(--text-primary)]">{{ pendingNpsInvite.definition?.question || 'How likely are you to recommend our support team?' }}</p>
                                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                                            <button
                                                v-for="value in npsScaleValues"
                                                :key="`survey-nps-score-${value}`"
                                                type="button"
                                                class="min-w-8 rounded-md border px-2 py-1 text-xs font-semibold transition-colors"
                                                :class="Number(surveyDraft.nps_score) === Number(value)
                                                    ? 'border-[var(--interactive-primary)] bg-[var(--interactive-primary)] text-white'
                                                    : 'border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--text-secondary)] hover:border-[var(--interactive-primary)]'"
                                                @click="surveyDraft.nps_score = Number(value)"
                                            >
                                                {{ value }}
                                            </button>
                                        </div>
                                    </div>
                                    <textarea
                                        v-model="surveyDraft.comment"
                                        rows="2"
                                        maxlength="1000"
                                        class="mt-2 w-full resize-none rounded-md border border-[var(--border-default)] bg-[var(--surface-primary)] px-2.5 py-2 text-xs text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:outline-none"
                                        placeholder="Optional comment..."
                                    />
                                    <div class="mt-2 flex items-center justify-between">
                                        <button
                                            type="button"
                                            class="text-[10px] text-[var(--text-muted)] underline underline-offset-2 disabled:opacity-50"
                                            :disabled="isUpdatingSurveyPreference"
                                            @click="updateSurveyPreference(true)"
                                        >
                                            {{ isUpdatingSurveyPreference ? 'Updating...' : "Don't ask again in this chat" }}
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center rounded-md bg-[var(--interactive-primary)] px-2.5 py-1.5 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                                            :disabled="!surveyCanSubmit"
                                            @click="submitConversationSurvey"
                                        >
                                            <Loader2 v-if="isSubmittingSurvey" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                            Submit feedback
                                        </button>
                                    </div>
                                </template>

                                <template v-else-if="hasSubmittedSurvey">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-500">Survey Received</p>
                                    <p class="mt-1 text-sm text-[var(--text-primary)]">Thank you for your feedback.</p>
                                    <p v-if="csatSurveyState?.response?.score !== undefined" class="mt-1 text-xs text-[var(--text-secondary)]">
                                        CSAT: <span class="font-semibold">{{ csatSurveyState.response.score }}</span>
                                    </p>
                                    <p v-if="npsSurveyState?.response?.score !== undefined" class="mt-1 text-xs text-[var(--text-secondary)]">
                                        NPS: <span class="font-semibold">{{ npsSurveyState.response.score }}</span>
                                    </p>
                                    <p v-if="csatSurveyState?.response?.comment || npsSurveyState?.response?.comment" class="mt-1 text-xs text-[var(--text-secondary)]">
                                        "{{ csatSurveyState?.response?.comment || npsSurveyState?.response?.comment }}"
                                    </p>
                                    <button
                                        type="button"
                                        class="mt-2 text-[10px] text-[var(--text-muted)] underline underline-offset-2 disabled:opacity-50"
                                        :disabled="isUpdatingSurveyPreference"
                                        @click="updateSurveyPreference(true)"
                                    >
                                        {{ isUpdatingSurveyPreference ? 'Updating...' : "Don't ask again in this chat" }}
                                    </button>
                                </template>

                                <template v-else>
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-xs text-[var(--text-secondary)]">
                                            {{ isSurveyOptedOut ? 'Surveys are disabled for this conversation.' : 'No survey is currently pending.' }}
                                        </p>
                                        <button
                                            type="button"
                                            class="text-[10px] text-[var(--text-muted)] underline underline-offset-2 disabled:opacity-50"
                                            :disabled="isUpdatingSurveyPreference"
                                            @click="updateSurveyPreference(!isSurveyOptedOut)"
                                        >
                                            {{ isUpdatingSurveyPreference ? 'Updating...' : (isSurveyOptedOut ? 'Enable survey' : 'Disable survey') }}
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div class="relative rounded-xl border border-slate-300/90 bg-white/95 shadow-[0_2px_12px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 dark:border-[var(--border-default)] dark:bg-[var(--surface-secondary)]/85 dark:shadow-sm dark:ring-transparent">
                                <input
                                    ref="fileInput"
                                    type="file"
                                    multiple
                                    class="hidden"
                                    @change="handleFileSelection"
                                />
                                <div class="px-3.5 pt-2 pb-1.5">
                                    <input 
                                        ref="composerInputRef"
                                        v-model="newMessage"
                                        type="text" 
                                        :placeholder="isConversationClosed ? 'Conversation resolved. Start a new chat.' : 'Write a message...'"
                                        :disabled="isConversationClosed || isSendCoolingDown"
                                        class="w-full bg-transparent border-none text-sm leading-5 focus:outline-none focus:ring-0 focus:ring-offset-0 focus-visible:ring-0 focus-visible:outline-none text-[var(--text-primary)] placeholder:text-slate-400 dark:placeholder:text-[var(--text-muted)] h-8"
                                        @keydown.enter="sendMessage"
                                        @input="handleComposerInput"
                                        @blur="emitTyping(false)"
                                    />
                                </div>
                                <div class="flex items-center justify-between border-t border-slate-200/90 px-2 py-1.5 dark:border-[var(--border-default)]/70">
                                    <div class="flex items-center gap-1">
                                    <button
                                        class="p-1.5 text-[var(--text-muted)] hover:text-[var(--text-secondary)] transition-colors rounded-md hover:bg-[var(--surface-tertiary)]"
                                        title="Attach file"
                                        :disabled="isConversationClosed || isSending || isStartingConversation || isSendCoolingDown"
                                        @click="openFilePicker"
                                    >
                                        <Paperclip class="w-4 h-4" />
                                    </button>
                                    <button
                                        class="p-1.5 text-[var(--text-muted)] hover:text-[var(--text-secondary)] transition-colors rounded-md hover:bg-[var(--surface-tertiary)] disabled:opacity-50"
                                        title="Insert emoji"
                                        :disabled="isConversationClosed || isSending || isStartingConversation || isSendCoolingDown"
                                        @click="toggleEmojiPicker"
                                    >
                                        <Smile class="w-4 h-4" />
                                    </button>
                                    <div
                                        v-if="showEmojiPicker"
                                        ref="emojiPickerRef"
                                        class="absolute bottom-12 right-12 z-30 w-60 rounded-xl border border-[var(--border-default)] bg-[var(--surface-primary)] p-2 shadow-2xl"
                                    >
                                        <div class="grid grid-cols-8 gap-1">
                                            <button
                                                v-for="emoji in PROFESSIONAL_SUPPORT_EMOJIS"
                                                :key="emoji"
                                                type="button"
                                                class="flex h-7 w-7 items-center justify-center rounded-md text-base hover:bg-[var(--surface-secondary)]"
                                                @click="appendProfessionalEmoji(emoji)"
                                            >
                                                {{ emoji }}
                                            </button>
                                        </div>
                                    </div>
                                    </div>
                                    <button 
                                        @click="sendMessage"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-[var(--interactive-primary)] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[var(--interactive-hover)] transition-colors shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                                        :disabled="(!newMessage.trim() && selectedFiles.length === 0) || isSending || isStartingConversation || isConversationClosed || isSendCoolingDown"
                                    >
                                        <Loader2 v-if="isSending || isStartingConversation" class="w-3.5 h-3.5 animate-spin" />
                                        <Send v-else class="w-3.5 h-3.5" />
                                        <span>Send</span>
                                    </button>
                                </div>
                            </div>
                            <div
                                v-if="isSendCoolingDown"
                                class="mt-2 rounded-lg border border-amber-400/40 bg-amber-500/10 px-2.5 py-1.5 text-[11px] text-amber-600 dark:text-amber-300"
                            >
                                You’re sending messages too quickly. Please wait {{ sendCooldownSeconds }}s.
                            </div>
                            <div v-if="selectedFiles.length > 0" class="mt-2 flex flex-wrap gap-1.5">
                                <span
                                    v-for="(file, index) in selectedFiles"
                                    :key="`${file.name}-${index}`"
                                    class="inline-flex items-center gap-1 rounded-full bg-[var(--surface-secondary)] border border-[var(--border-default)] px-2 py-0.5 text-[11px] text-[var(--text-secondary)]"
                                >
                                    <Paperclip class="h-3 w-3" />
                                    <span class="max-w-[170px] truncate">{{ file.name }}</span>
                                    <button
                                        type="button"
                                        class="text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                                        @click="removeSelectedFile(index)"
                                    >
                                        ×
                                    </button>
                                </span>
                            </div>
                            <div class="text-center mt-2">
                                <span class="text-[10px] flex items-center justify-center gap-1 text-[var(--text-muted)]">
                                    Powered by <span class="font-bold">WorkSphere Chat</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- FAB Button -->
        <button 
            v-if="!hideLauncher"
            @click="toggleChat"
            class="flex items-center justify-center w-14 h-14 rounded-2xl bg-[var(--interactive-primary)] text-white shadow-[0_12px_30px_rgba(2,132,199,0.35)] hover:bg-[var(--interactive-primary-hover)] hover:shadow-[0_16px_36px_rgba(2,132,199,0.45)] hover:-translate-y-1 transition-all duration-300 relative group border border-black/10 dark:border-white/20 overflow-hidden"
            :class="isOpen ? 'scale-0 opacity-0' : 'scale-100 opacity-100'"
        >
            <Headset class="w-6 h-6 transition-transform duration-300 group-hover:scale-110 relative z-10" />
            
            <!-- Tooltip -->
            <div class="absolute right-full mr-4 bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-sm font-medium px-4 py-2 rounded-xl opacity-0 pointer-events-none group-hover:opacity-100 transition-all duration-300 translate-x-4 group-hover:translate-x-0 whitespace-nowrap shadow-xl border border-white/10 dark:border-slate-200">
                Live support
                <div class="absolute right-0 top-1/2 -translate-y-1/2 translate-x-1/2 rotate-45 w-2 h-2 bg-slate-900 dark:bg-white"></div>
            </div>
        </button>

        <RecaptchaChallengeModal
            :show="showRecaptchaChallenge"
            @close="showRecaptchaChallenge = false"
            @verified="handleRecaptchaChallengeVerified"
        />
    </div>
</template>

<style scoped>
input, textarea {
    outline: none !important;
    box-shadow: none !important;
    border: none !important;
}
input:focus, textarea:focus {
    outline: none !important;
    box-shadow: none !important;
    border: none !important;
}

/* Custom scrollbar for message list */
::-webkit-scrollbar {
    width: 4px;
}
::-webkit-scrollbar-track {
    background: transparent;
}
::-webkit-scrollbar-thumb {
    background: var(--border-default);
    border-radius: 10px;
}
::-webkit-scrollbar-thumb:hover {
    background: var(--text-muted);
}

.support-typing-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    border-radius: 999px;
    border: 1px solid color-mix(in srgb, var(--border-default) 90%, transparent);
    background: color-mix(in srgb, var(--surface-secondary) 94%, transparent);
    padding: 0.25rem 0.625rem;
}

.support-typing-dots {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
}

.support-typing-dot {
    width: 0.35rem;
    height: 0.35rem;
    border-radius: 999px;
    background: var(--interactive-primary);
    animation: supportTypingBounce 1s infinite ease-in-out;
}

.support-typing-delay-1 {
    animation-delay: -0.3s;
}

.support-typing-delay-2 {
    animation-delay: -0.15s;
}

:deep(.support-rich-content) {
    line-height: 1.45;
    white-space: normal;
}

:deep(.support-rich-content p) {
    margin: 0.3rem 0;
    white-space: pre-wrap;
}

:deep(.support-rich-content p:first-child) {
    margin-top: 0;
}

:deep(.support-rich-content p:last-child) {
    margin-bottom: 0;
}

:deep(.support-rich-content ul),
:deep(.support-rich-content ol) {
    margin: 0.35rem 0;
    padding-left: 1.1rem;
}

:deep(.support-rich-content li) {
    margin: 0.15rem 0;
}

:deep(.support-rich-content a) {
    color: inherit !important;
    text-decoration: underline;
    text-decoration-color: currentColor;
    text-underline-offset: 2px;
    word-break: break-all;
}

:deep(.support-rich-content a:visited),
:deep(.support-rich-content a:hover),
:deep(.support-rich-content a:active) {
    color: inherit !important;
}

@keyframes supportTypingBounce {
    0%, 80%, 100% {
        transform: translateY(0);
        opacity: 0.45;
    }
    40% {
        transform: translateY(-2px);
        opacity: 1;
    }
}
</style>
