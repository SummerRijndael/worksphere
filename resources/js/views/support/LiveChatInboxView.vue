<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Filter,
    MoreVertical,
    Paperclip,
    Send,
    Smile,
    Image as ImageIcon,
    Clock,
    CheckCircle2,
    AlertCircle,
    MapPin,
    Globe,
    Monitor,
    MessageSquare,
    Zap,
    Book,
    Code,
    UserPlus,
    ChevronDown,
    ChevronsDown,
    User,
    Hash,
    Loader2,
} from 'lucide-vue-next';
import {
    Avatar,
    Button,
    SearchInput,
    Badge,
    Dropdown,
    DropdownItem,
    DropdownSeparator,
} from '@/components/ui';
import LinkPreview from '@/components/LinkPreview.vue';
import SupportMessageAttachments from '@/components/support/SupportMessageAttachments.vue';
import api from '@/lib/api';
import { useToast } from '@/composables/useToast.ts';
import { useAuthStore } from '@/stores/auth';
import { hasSupportRealtimeToken, setSupportRealtimeToken, startEcho } from '@/echo';
import { createSupportLogger, summarizeError } from '@/utils/supportDebug';
import { playSupportMessageSound } from '@/utils/supportSound';
import { PROFESSIONAL_SUPPORT_EMOJIS } from '@/constants/supportEmojis';

const toast = useToast();
const authStore = useAuthStore();
const supportLogger = createSupportLogger('Inbox');

const activeTab = ref('mine'); // mine | unassigned | all
const searchQuery = ref('');
const conversations = ref([]);
const conversationPagination = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 20,
    total: 0,
});
const activeConversationId = ref(null);
const activeConversation = ref(null);
const conversationMessages = ref([]);
const agents = ref([]);
const selectedAgentId = ref('');
const messagesContainer = ref(null);
const composerFileInput = ref(null);
const selectedComposerFiles = ref([]);
const emojiPickerRef = ref(null);

const newMessage = ref('');
const isNoteMode = ref(false);
const isLoadingOlderMessages = ref(false);
const hasMoreMessagesBefore = ref(false);
const oldestMessageId = ref(null);
const showJumpToLatest = ref(false);
const customerTypingIndicator = ref('');
const firstUnreadMessageId = ref(null);
const unreadMessageCount = ref(0);
const pendingOutgoingMessages = ref([]);
const unsafeMessageIds = ref(new Set());
const showEmojiPicker = ref(false);
const sendCooldownSeconds = ref(0);

const isLoadingList = ref(false);
const isLoadingMoreConversations = ref(false);
const isLoadingConversation = ref(false);
const isLoadingAgents = ref(false);
const isSending = ref(false);
const isAssigning = ref(false);
const isResolving = ref(false);
const MAX_ATTACHABLE_FILES = 10;

let searchDebounceTimer = null;
let realtimeTokenRefreshTimer = null;
let realtimeInboxRefreshTimer = null;
let realtimeSubscriptionRetryTimer = null;
let supportRealtimeInboxChannelName = '';
let supportRealtimeConversationChannelName = '';
let customerTypingTimer = null;
let typingDebounceTimer = null;
let lastTypingSentAt = 0;
let supportRealtimeRetryAttempts = 0;
let sendCooldownTimer = null;

function scheduleSupportRealtimeRetry() {
    if (realtimeSubscriptionRetryTimer) {
        clearTimeout(realtimeSubscriptionRetryTimer);
    }

    const delayMs = Math.min(1000 * (2 ** supportRealtimeRetryAttempts), 8000);
    supportRealtimeRetryAttempts = Math.min(supportRealtimeRetryAttempts + 1, 6);
    supportLogger.warn('realtime.retry.schedule', 'Scheduling inbox realtime retry.', {
        attempt: supportRealtimeRetryAttempts,
        delay_ms: delayMs,
        conversation_id: activeConversationId.value,
    });

    realtimeSubscriptionRetryTimer = setTimeout(async () => {
        realtimeSubscriptionRetryTimer = null;
        await refreshRealtimeToken();
        await subscribeSupportRealtime();
    }, delayMs);
}

function handleEchoConnected() {
    supportLogger.info('realtime.echo.connected', 'Received echo:connected for support inbox.');
    subscribeSupportRealtime();
}

const activeConversationDisplay = computed(() => {
    const conversation = activeConversation.value;
    if (!conversation) {
        return null;
    }

    const metadata = conversation.metadata || {};
    const requesterName = conversation.requester?.name
        || conversation.guest_name
        || conversation.guest_email
        || `Guest ${conversation.id?.slice(-6) || ''}`;

    return {
        id: conversation.id,
        name: requesterName,
        email: conversation.requester?.email || conversation.guest_email || 'No email provided',
        avatarUrl: conversation.requester?.avatar_url || null,
        avatarThumbUrl: conversation.requester?.avatar_thumb_url || null,
        avatarColor: conversation.requester?.avatar_color || null,
        pageView: conversation.source_url || metadata.page || 'N/A',
        location: metadata.location || 'Unknown',
        browser: metadata.browser || metadata.user_agent || 'Unknown',
        ip: metadata.ip || 'Unknown',
        status: conversation.status || 'open',
    };
});

const activeConversationMessages = computed(() => {
    const messages = conversationMessages.value || [];

    const mapped = messages.map((message) => {
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
        } else if (message.sender_type === 'agent' && message.is_private_note) {
            type = 'note';
        }

        return {
            id: message.id,
            type,
            content: message.body || '',
            firstUrl: extractFirstUrl(message.body || ''),
            senderAvatarUrl: message.sender?.avatar_url || message.sender?.avatar || null,
            senderAvatarThumbUrl: message.sender?.avatar_thumb_url || null,
            senderAvatarColor: message.sender?.avatar_color || null,
            attachments: Array.isArray(message.attachments) ? message.attachments : [],
            time: formatTimestamp(message.created_at),
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
    const conversationId = activeConversationId.value;
    if (!conversationId) {
        return [];
    }

    return pendingOutgoingMessages.value
        .filter((message) => message.conversationId === conversationId)
        .sort((a, b) => new Date(a.createdAt).getTime() - new Date(b.createdAt).getTime())
        .map((message) => ({
            ...message,
            time: formatTimestamp(message.createdAt),
        }));
});

const composerDisabled = computed(() => {
    const status = activeConversation.value?.status;
    return !activeConversation.value || status === 'resolved' || status === 'closed';
});
const isSendCoolingDown = computed(() => sendCooldownSeconds.value > 0);

const activeScopeTitle = computed(() => {
    if (activeTab.value === 'unassigned') {
        return 'Unassigned';
    }
    if (activeTab.value === 'all') {
        return 'All Activity';
    }

    return 'My Inbox';
});
const activeScopeShortLabel = computed(() => {
    if (activeTab.value === 'unassigned') {
        return 'Unassigned';
    }
    if (activeTab.value === 'all') {
        return 'All';
    }

    return 'Mine';
});
const canLoadMoreConversations = computed(
    () => conversationPagination.value.currentPage < conversationPagination.value.lastPage,
);

function formatTimestamp(isoValue) {
    if (!isoValue) {
        return 'Now';
    }

    const date = new Date(isoValue);
    if (Number.isNaN(date.getTime())) {
        return 'Now';
    }

    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatRelativeTime(isoValue) {
    if (!isoValue) {
        return 'Now';
    }

    const date = new Date(isoValue);
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
    if (!emoji || composerDisabled.value || isSending.value || isSendCoolingDown.value) {
        return;
    }

    newMessage.value = `${newMessage.value || ''}${emoji}`;
    showEmojiPicker.value = false;
    handleComposerInput();
}

function toggleEmojiPicker() {
    if (composerDisabled.value || isSending.value || isSendCoolingDown.value) {
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

function getConversationDisplayName(chat) {
    return chat?.requester?.name
        || chat?.guest_name
        || chat?.guest_email
        || `Guest ${chat?.id?.slice(-6) || ''}`;
}

function getConversationInitials(chat) {
    const name = getConversationDisplayName(chat).trim();
    if (!name) {
        return 'G';
    }

    const parts = name.split(/\s+/).filter(Boolean);
    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return `${parts[0][0]}${parts[1][0]}`.toUpperCase();
}

function getStatusLabel(status) {
    return (status || 'open').replace(/_/g, ' ');
}

function getStatusClasses(status) {
    switch (status) {
        case 'waiting_human':
            return 'text-amber-500';
        case 'assigned':
            return 'text-blue-500';
        case 'resolved':
        case 'closed':
            return 'text-[var(--text-muted)]';
        case 'bot_active':
            return 'text-purple-500';
        case 'open':
        default:
            return 'text-emerald-500';
    }
}

function getStatusIcon(status) {
    switch (status) {
        case 'waiting_human':
            return Clock;
        case 'resolved':
        case 'closed':
            return CheckCircle2;
        case 'assigned':
            return User;
        case 'bot_active':
            return Zap;
        case 'open':
        default:
            return MessageSquare;
    }
}

function statusBadgeVariant(status) {
    if (status === 'resolved' || status === 'closed') {
        return 'secondary';
    }
    if (status === 'waiting_human') {
        return 'warning';
    }
    if (status === 'assigned') {
        return 'primary';
    }

    return 'success';
}

function applyRealtimeMeta(meta) {
    const token = meta?.realtime?.token;
    if (typeof token === 'string' && token.trim() !== '') {
        setSupportRealtimeToken(token.trim(), 'agent');
        supportLogger.debug('realtime.token.updated', 'Applied agent realtime token from API meta.');
    }
}

function clearCustomerTypingIndicator() {
    customerTypingIndicator.value = '';
    if (customerTypingTimer) {
        clearTimeout(customerTypingTimer);
        customerTypingTimer = null;
    }
}

function clearUnreadMarker() {
    firstUnreadMessageId.value = null;
    unreadMessageCount.value = 0;
}

function setCustomerTyping(actorName, isTyping) {
    if (!isTyping) {
        clearCustomerTypingIndicator();
        return;
    }

    customerTypingIndicator.value = `${actorName || 'Customer'} is typing...`;
    if (customerTypingTimer) {
        clearTimeout(customerTypingTimer);
    }
    customerTypingTimer = setTimeout(() => {
        customerTypingIndicator.value = '';
        customerTypingTimer = null;
    }, 3500);
}

function isNearBottom() {
    const container = messagesContainer.value;
    if (!container) {
        return true;
    }

    const threshold = 120;
    return (container.scrollHeight - (container.scrollTop + container.clientHeight)) <= threshold;
}

async function scrollToLatest(smooth = false) {
    await nextTick();
    const container = messagesContainer.value;
    if (!container) {
        return;
    }

    container.scrollTo({
        top: container.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto',
    });
    showJumpToLatest.value = false;
    clearUnreadMarker();
}

async function loadConversationMessages(conversationId, options = {}) {
    if (!conversationId) {
        conversationMessages.value = [];
        hasMoreMessagesBefore.value = false;
        oldestMessageId.value = null;
        clearUnreadMarker();
        return;
    }
    supportLogger.debug('messages.fetch.start', 'Loading inbox conversation messages.', {
        conversation_id: conversationId,
        before: options.before || null,
        append_older: options.appendOlder === true,
        merge_latest: options.mergeLatest === true,
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

    const response = await api.get(`/api/support/chats/agent/${conversationId}/messages`, { params });
    const incoming = Array.isArray(response.data?.data) ? response.data.data : [];
    const meta = response.data?.meta || {};

    if (appendOlder) {
        const existingIds = new Set(conversationMessages.value.map((message) => message.id));
        const olderMessages = incoming.filter((message) => !existingIds.has(message.id));
        conversationMessages.value = [...olderMessages, ...conversationMessages.value];
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
    supportLogger.debug('messages.fetch.success', 'Loaded inbox conversation messages.', {
        conversation_id: conversationId,
        incoming_count: incoming.length,
        total_local_count: conversationMessages.value.length,
        has_more_before: hasMoreMessagesBefore.value,
        oldest_message_id: oldestMessageId.value,
    });
}

async function handleRealtimeMessage(event) {
    if (event?.conversation_id !== activeConversationId.value) {
        return;
    }

    const incoming = event?.message;
    if (!incoming?.id) {
        supportLogger.warn('realtime.message.skip', 'Received SupportMessageCreated without message payload.', {
            event,
        });
        return;
    }
    supportLogger.debug('realtime.message.received', 'Received support realtime message event.', {
        conversation_id: event?.conversation_id,
        message_id: incoming.id,
        sender_type: incoming.sender_type,
    });

    const wasNearBottom = isNearBottom();
    const existingIndex = conversationMessages.value.findIndex((message) => message.id === incoming.id);
    const shouldPlayIncomingSound = existingIndex === -1 && incoming.sender_type === 'customer';
    if (shouldPlayIncomingSound) {
        const played = playSupportMessageSound();
        supportLogger.debug('realtime.sound.triggered', 'Processed inbox sound trigger for incoming customer message.', {
            conversation_id: event?.conversation_id,
            message_id: incoming.id,
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
    }

    if (realtimeInboxRefreshTimer) {
        clearTimeout(realtimeInboxRefreshTimer);
    }
    realtimeInboxRefreshTimer = setTimeout(async () => {
        await loadConversations();
    }, 250);

    if (existingIndex === -1) {
        if (!wasNearBottom) {
            unreadMessageCount.value += 1;
            if (!firstUnreadMessageId.value) {
                firstUnreadMessageId.value = incoming.id;
            }
            showJumpToLatest.value = true;
        } else {
            clearUnreadMarker();
            await scrollToLatest(false);
        }
    } else if (wasNearBottom) {
        await scrollToLatest(false);
    }
}

async function loadOlderMessages() {
    if (!activeConversationId.value || isLoadingOlderMessages.value || !hasMoreMessagesBefore.value || !oldestMessageId.value) {
        return;
    }

    isLoadingOlderMessages.value = true;
    const container = messagesContainer.value;
    const oldScrollHeight = container?.scrollHeight || 0;
    const oldScrollTop = container?.scrollTop || 0;

    try {
        await loadConversationMessages(activeConversationId.value, {
            before: oldestMessageId.value,
            appendOlder: true,
        });

        await nextTick();
        if (container) {
            const newScrollHeight = container.scrollHeight;
            container.scrollTop = oldScrollTop + (newScrollHeight - oldScrollHeight);
        }
    } catch (error) {
        console.error('Failed to load older support messages:', error);
    } finally {
        isLoadingOlderMessages.value = false;
    }
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

function clearRealtimeSubscription({ clearToken = true } = {}) {
    const echo = (window).Echo;
    if (echo && supportRealtimeInboxChannelName) {
        echo.leave(supportRealtimeInboxChannelName);
    }
    if (echo && supportRealtimeConversationChannelName) {
        echo.leave(supportRealtimeConversationChannelName);
    }

    supportRealtimeInboxChannelName = '';
    supportRealtimeConversationChannelName = '';

    if (clearToken) {
        setSupportRealtimeToken(null, 'agent');
    }
    supportLogger.debug('realtime.unsubscribe', 'Cleared support inbox realtime subscriptions.', {
        clear_token: clearToken,
    });
}

async function subscribeSupportRealtime() {
    const echo = startEcho() || (window).Echo;
    if (!echo) {
        supportLogger.warn('realtime.subscribe.skip', 'Echo instance not ready for support inbox subscriptions.');
        return;
    }

    if (!hasSupportRealtimeToken('agent')) {
        supportLogger.warn('realtime.subscribe.no_token', 'Missing agent realtime token; delaying support inbox subscription.');
        scheduleSupportRealtimeRetry();
        return;
    }

    if (supportRealtimeInboxChannelName !== 'support.agent.inbox') {
        if (supportRealtimeInboxChannelName) {
            echo.leave(supportRealtimeInboxChannelName);
        }

        const inboxChannel = echo.private('support.agent.inbox')
            .listen('.SupportConversationChanged', () => {
                if (realtimeInboxRefreshTimer) {
                    clearTimeout(realtimeInboxRefreshTimer);
                }

                realtimeInboxRefreshTimer = setTimeout(async () => {
                    await loadConversations();
                }, 250);
            });

        if ((inboxChannel).subscription) {
            (inboxChannel).subscription.bind('pusher:subscription_succeeded', () => {
                supportRealtimeInboxChannelName = 'support.agent.inbox';
                supportRealtimeRetryAttempts = 0;
                supportLogger.info('realtime.subscribe.success', 'Subscribed to support.agent.inbox.');
            });

            (inboxChannel).subscription.bind('pusher:subscription_error', (error) => {
                console.error('[SupportRealtime] Failed to subscribe support.agent.inbox', error);
                if (supportRealtimeInboxChannelName === 'support.agent.inbox') {
                    supportRealtimeInboxChannelName = '';
                }
                supportLogger.error('realtime.subscribe.failure', 'Failed to subscribe support.agent.inbox.', summarizeError(error));
                scheduleSupportRealtimeRetry();
            });
        }

        supportRealtimeInboxChannelName = 'support.agent.inbox';
    }

    const conversationId = activeConversationId.value;
    if (!conversationId) {
        if (supportRealtimeConversationChannelName) {
            echo.leave(supportRealtimeConversationChannelName);
            supportRealtimeConversationChannelName = '';
        }
        return;
    }

    const targetConversationChannel = `support.agent.${conversationId}`;
    if (supportRealtimeConversationChannelName === targetConversationChannel) {
        supportLogger.debug('realtime.subscribe.skip', 'Already subscribed to active support conversation channel.', {
            channel: targetConversationChannel,
        });
        return;
    }

    if (supportRealtimeConversationChannelName) {
        echo.leave(supportRealtimeConversationChannelName);
    }

    const conversationChannel = echo.private(targetConversationChannel)
        .listen('.SupportMessageCreated', async (event) => {
            await handleRealtimeMessage(event);
        })
        .listen('.SupportConversationChanged', async (event) => {
            if (event?.conversation_id !== activeConversationId.value) {
                return;
            }

            if (!activeConversation.value) {
                return;
            }

            activeConversation.value = {
                ...activeConversation.value,
                status: event?.status || activeConversation.value.status,
                ai_handoff_required: Boolean(event?.ai_handoff_required),
                last_message_at: event?.last_message_at || activeConversation.value.last_message_at,
                updated_at: event?.updated_at || activeConversation.value.updated_at,
                assignee: event?.assigned_to
                    ? { ...(activeConversation.value.assignee || {}), id: event.assigned_to }
                    : activeConversation.value.assignee,
            };
        })
        .listen('.SupportTypingUpdated', (event) => {
            if (event?.conversation_id !== activeConversationId.value) {
                return;
            }

            if (event?.actor_type !== 'customer') {
                return;
            }

            setCustomerTyping(event?.actor_name || 'Customer', Boolean(event?.is_typing));
        });

    if ((conversationChannel).subscription) {
        (conversationChannel).subscription.bind('pusher:subscription_succeeded', () => {
            supportRealtimeConversationChannelName = targetConversationChannel;
            supportRealtimeRetryAttempts = 0;
            supportLogger.info('realtime.subscribe.success', 'Subscribed to support conversation channel.', {
                channel: targetConversationChannel,
            });
        });

        (conversationChannel).subscription.bind('pusher:subscription_error', (error) => {
            console.error(`[SupportRealtime] Failed to subscribe ${targetConversationChannel}`, error);
            if (supportRealtimeConversationChannelName === targetConversationChannel) {
                supportRealtimeConversationChannelName = '';
            }
            supportLogger.error('realtime.subscribe.failure', 'Failed to subscribe support conversation channel.', {
                channel: targetConversationChannel,
                ...summarizeError(error),
            });
            scheduleSupportRealtimeRetry();
        });
    }

    supportRealtimeConversationChannelName = targetConversationChannel;
}

async function refreshRealtimeToken() {
    try {
        supportLogger.debug('realtime.token.refresh.start', 'Refreshing support agent realtime token.');
        const response = await api.get('/api/support/chats/realtime-token');
        const token = response.data?.data?.token;
        if (typeof token === 'string' && token.trim() !== '') {
            setSupportRealtimeToken(token.trim(), 'agent');
            await subscribeSupportRealtime();
            supportLogger.info('realtime.token.refresh.success', 'Support agent realtime token refreshed.');
        }
    } catch (error) {
        supportLogger.error('realtime.token.refresh.failure', 'Failed to refresh support agent realtime token.', summarizeError(error));
        console.error('Failed to refresh support realtime token:', error);
    }
}

async function loadAgents() {
    isLoadingAgents.value = true;

    try {
        const response = await api.get('/api/support/chats/agents');
        agents.value = response.data?.data || [];

        if (!selectedAgentId.value && authStore.user?.public_id) {
            selectedAgentId.value = authStore.user.public_id;
        }
    } catch (error) {
        console.error('Failed to load support agents:', error);
        toast.error('Error', error.response?.data?.message || 'Failed to load support agents.');
    } finally {
        isLoadingAgents.value = false;
    }
}

async function loadConversations(options = {}) {
    const append = options.append === true;
    if (append && !canLoadMoreConversations.value) {
        return;
    }

    if (append) {
        if (isLoadingMoreConversations.value || isLoadingList.value) {
            return;
        }
        isLoadingMoreConversations.value = true;
    } else {
        isLoadingList.value = true;
    }

    supportLogger.debug('inbox.fetch.start', 'Loading support inbox conversations.', {
        scope: activeTab.value,
        search: searchQuery.value.trim() || null,
        append,
        page: append ? conversationPagination.value.currentPage + 1 : 1,
    });

    try {
        const params = {
            scope: activeTab.value,
            per_page: 20,
            page: append ? conversationPagination.value.currentPage + 1 : 1,
        };

        if (searchQuery.value.trim()) {
            params.q = searchQuery.value.trim();
        }

        const response = await api.get('/api/support/chats/inbox', { params });
        applyRealtimeMeta(response.data?.meta);
        await subscribeSupportRealtime();
        const incoming = response.data?.data || [];
        conversations.value = append
            ? mergeConversations(conversations.value, incoming)
            : incoming;
        conversationPagination.value = {
            currentPage: Number(response.data?.meta?.current_page || 1),
            lastPage: Number(response.data?.meta?.last_page || 1),
            perPage: Number(response.data?.meta?.per_page || params.per_page),
            total: Number(response.data?.meta?.total || conversations.value.length),
        };
        supportLogger.info('inbox.fetch.success', 'Loaded support inbox conversations.', {
            count: conversations.value.length,
            scope: activeTab.value,
            append,
            current_page: conversationPagination.value.currentPage,
            last_page: conversationPagination.value.lastPage,
        });

        const existing = conversations.value.find((chat) => chat.id === activeConversationId.value);
        if (!existing) {
            activeConversationId.value = conversations.value[0]?.id || null;
        }

        if (!append && activeConversationId.value) {
            const shouldLoadMessages = !activeConversation.value
                || activeConversation.value.id !== activeConversationId.value
                || conversationMessages.value.length === 0;

            await fetchConversation(activeConversationId.value, { loadMessages: shouldLoadMessages });
        } else {
            activeConversation.value = null;
            conversationMessages.value = [];
            clearCustomerTypingIndicator();
            clearUnreadMarker();
            await subscribeSupportRealtime();
        }
    } catch (error) {
        supportLogger.error('inbox.fetch.failure', 'Failed to load support inbox conversations.', summarizeError(error));
        console.error('Failed to load support inbox:', error);
        toast.error('Error', error.response?.data?.message || 'Failed to load support inbox.');
    } finally {
        if (append) {
            isLoadingMoreConversations.value = false;
        } else {
            isLoadingList.value = false;
        }
    }
}

function mergeConversations(existing, incoming) {
    const merged = [...existing];
    const seen = new Set(existing.map((chat) => chat.id));
    incoming.forEach((chat) => {
        if (!seen.has(chat.id)) {
            merged.push(chat);
            seen.add(chat.id);
        }
    });

    return merged;
}

async function loadMoreConversations() {
    await loadConversations({ append: true });
}

async function fetchConversation(conversationId, options = {}) {
    if (!conversationId) {
        activeConversation.value = null;
        conversationMessages.value = [];
        clearCustomerTypingIndicator();
        clearUnreadMarker();
        return;
    }

    const withLoading = options.withLoading !== false;
    const shouldLoadMessages = options.loadMessages !== false;

    if (withLoading) {
        isLoadingConversation.value = true;
    }
    supportLogger.debug('conversation.fetch.start', 'Loading support inbox conversation.', {
        conversation_id: conversationId,
        with_loading: withLoading,
        load_messages: shouldLoadMessages,
    });

    try {
        const response = await api.get(`/api/support/chats/agent/${conversationId}`);
        applyRealtimeMeta(response.data?.meta);
        activeConversation.value = response.data?.data || null;
        selectedAgentId.value = activeConversation.value?.assignee?.id || selectedAgentId.value;

        if (shouldLoadMessages) {
            await loadConversationMessages(conversationId);
            await nextTick();
            await scrollToLatest(false);
        }

        await subscribeSupportRealtime();
        supportLogger.info('conversation.fetch.success', 'Support inbox conversation loaded.', {
            conversation_id: conversationId,
            status: activeConversation.value?.status || null,
            messages_count: conversationMessages.value.length,
        });
    } catch (error) {
        supportLogger.error('conversation.fetch.failure', 'Failed to load support inbox conversation.', {
            conversation_id: conversationId,
            ...summarizeError(error),
        });
        console.error('Failed to load support conversation:', error);
        toast.error('Error', error.response?.data?.message || 'Failed to load support conversation.');
    } finally {
        if (withLoading) {
            isLoadingConversation.value = false;
        }
    }
}

async function selectConversation(conversationId) {
    activeConversationId.value = conversationId;
    clearCustomerTypingIndicator();
    clearUnreadMarker();
    clearComposerFiles();
    await fetchConversation(conversationId);
}

async function emitTyping(isTyping) {
    if (!activeConversationId.value) {
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
        await api.post(`/api/support/chats/agent/${activeConversationId.value}/typing`, {
            is_typing: Boolean(isTyping),
        });
    } catch {
        // Typing events are best-effort only.
    }
}

function handleComposerInput() {
    if (composerDisabled.value || !activeConversationId.value) {
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

function jumpToLatest() {
    scrollToLatest(true);
}

async function jumpToFirstUnread() {
    if (!firstUnreadMessageId.value) {
        await jumpToLatest();
        return;
    }

    await nextTick();
    const element = document.getElementById(`support-inbox-message-${firstUnreadMessageId.value}`);
    if (!element) {
        await jumpToLatest();
        return;
    }

    element.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
    });
}

function queuePendingMessage(body, isPrivateNote = false) {
    const conversationId = activeConversationId.value;
    if (!conversationId) {
        return null;
    }

    const files = selectedComposerFiles.value.slice(0, MAX_ATTACHABLE_FILES);
    const pending = {
        id: `pending-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
        conversationId,
        body,
        isPrivateNote: Boolean(isPrivateNote),
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
    supportLogger.info('message.send.start', 'Sending agent support message.', {
        pending_id: messageId,
        conversation_id: pending.conversationId,
        is_private_note: pending.isPrivateNote,
        files_count: Array.isArray(pending.files) ? pending.files.length : 0,
    });

    try {
        const hasFiles = Array.isArray(pending.files) && pending.files.length > 0;
        let response;

        if (hasFiles) {
            const formData = new FormData();
            formData.append('body', pending.body || '');
            formData.append('is_private_note', pending.isPrivateNote ? '1' : '0');
            pending.files.forEach((file) => {
                formData.append('files[]', file);
            });

            response = await api.post(`/api/support/chats/${pending.conversationId}/agent-messages`, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });
        } else {
            response = await api.post(`/api/support/chats/${pending.conversationId}/agent-messages`, {
                body: pending.body,
                is_private_note: pending.isPrivateNote,
            });
        }
        applyRealtimeMeta(response.data?.meta);

        removePendingMessage(messageId);

        if (activeConversationId.value === pending.conversationId) {
            const wasNearBottom = isNearBottom();
            await fetchConversation(pending.conversationId, { withLoading: false, loadMessages: false });
            await loadConversationMessages(pending.conversationId, { mergeLatest: true, wasNearBottom, trackUnread: false });
            if (wasNearBottom) {
                await scrollToLatest(false);
            }
        }

        await loadConversations();
        supportLogger.info('message.send.success', 'Agent support message sent.', {
            pending_id: messageId,
            conversation_id: pending.conversationId,
        });
    } catch (error) {
        setPendingMessageStatus(messageId, 'failed');
        const status = Number(error?.response?.status || 0);
        if (status === 429) {
            startSendCooldown(extractRetryAfterSeconds(error, 30));
        }
        supportLogger.error('message.send.failure', 'Failed to send agent support message.', {
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

function openComposerFilePicker() {
    if (composerDisabled.value || isSending.value || isSendCoolingDown.value) {
        return;
    }

    showEmojiPicker.value = false;
    composerFileInput.value?.click();
}

function handleComposerFileSelection(event) {
    const incoming = Array.from(event?.target?.files || []);
    if (!incoming.length) {
        return;
    }

    const merged = [...selectedComposerFiles.value, ...incoming];
    if (merged.length > MAX_ATTACHABLE_FILES) {
        toast.error('Limit reached', `You can attach up to ${MAX_ATTACHABLE_FILES} files per message.`);
    }
    selectedComposerFiles.value = merged.slice(0, MAX_ATTACHABLE_FILES);

    if (composerFileInput.value) {
        composerFileInput.value.value = '';
    }
}

function removeComposerFile(index) {
    selectedComposerFiles.value.splice(index, 1);
}

function clearComposerFiles() {
    selectedComposerFiles.value = [];
    if (composerFileInput.value) {
        composerFileInput.value.value = '';
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

async function sendMessage() {
    const body = newMessage.value.trim();
    const hasFiles = selectedComposerFiles.value.length > 0;
    if (!activeConversationId.value || (!body && !hasFiles) || isSending.value || isSendCoolingDown.value) {
        return;
    }

    showEmojiPicker.value = false;
    isSending.value = true;

    try {
        if (typingDebounceTimer) {
            clearTimeout(typingDebounceTimer);
            typingDebounceTimer = null;
        }
        await emitTyping(false);

        const shouldStickToBottom = isNearBottom();
        const pending = queuePendingMessage(body, isNoteMode.value);
        if (!pending) {
            return;
        }

        newMessage.value = '';
        clearComposerFiles();

        if (shouldStickToBottom) {
            await scrollToLatest(false);
        }

        await transmitPendingMessage(pending.id, { showErrorToast: true });
    } catch {
        toast.error('Error', 'Failed to queue message.');
    } finally {
        isSending.value = false;
    }
}

async function assignConversation(agentPublicId = selectedAgentId.value) {
    const resolvedAgentId = typeof agentPublicId === 'string'
        ? agentPublicId
        : selectedAgentId.value;
    const normalizedAgentId = String(resolvedAgentId || '').trim();

    if (!activeConversationId.value || !normalizedAgentId || isAssigning.value) {
        supportLogger.debug('conversation.assign.skip', 'Skipping assignment due to invalid state.', {
            conversation_id: activeConversationId.value,
            agent_public_id: normalizedAgentId || null,
            is_assigning: isAssigning.value,
        });
        return;
    }

    isAssigning.value = true;

    try {
        supportLogger.info('conversation.assign.start', 'Assigning support conversation.', {
            conversation_id: activeConversationId.value,
            agent_public_id: normalizedAgentId,
        });
        const response = await api.post(`/api/support/chats/${activeConversationId.value}/assign`, {
            agent_public_id: normalizedAgentId,
        });
        applyRealtimeMeta(response.data?.meta);

        toast.success('Assigned', 'Conversation assigned successfully.');
        await fetchConversation(activeConversationId.value);
        await loadConversations();
        supportLogger.info('conversation.assign.success', 'Support conversation assigned.', {
            conversation_id: activeConversationId.value,
            agent_public_id: normalizedAgentId,
        });
    } catch (error) {
        supportLogger.error('conversation.assign.failure', 'Failed to assign support conversation.', summarizeError(error));
        console.error('Failed to assign support conversation:', error);
        toast.error('Error', error.response?.data?.message || 'Failed to assign conversation.');
    } finally {
        isAssigning.value = false;
    }
}

async function assignToCurrentUser() {
    if (!authStore.user?.public_id) {
        return;
    }

    selectedAgentId.value = authStore.user.public_id;
    await assignConversation(authStore.user.public_id);
}

async function resolveConversation() {
    if (!activeConversationId.value || isResolving.value) {
        return;
    }

    isResolving.value = true;

    try {
        supportLogger.info('conversation.resolve.start', 'Resolving support conversation.', {
            conversation_id: activeConversationId.value,
        });
        const response = await api.post(`/api/support/chats/${activeConversationId.value}/resolve`);
        applyRealtimeMeta(response.data?.meta);
        toast.success('Resolved', 'Conversation marked as resolved.');
        await fetchConversation(activeConversationId.value);
        await loadConversations();
        supportLogger.info('conversation.resolve.success', 'Support conversation resolved.', {
            conversation_id: activeConversationId.value,
        });
    } catch (error) {
        supportLogger.error('conversation.resolve.failure', 'Failed to resolve support conversation.', summarizeError(error));
        console.error('Failed to resolve support conversation:', error);
        toast.error('Error', error.response?.data?.message || 'Failed to resolve conversation.');
    } finally {
        isResolving.value = false;
    }
}

async function refreshCurrentConversation() {
    if (!activeConversationId.value) {
        await loadConversations();
        return;
    }

    await fetchConversation(activeConversationId.value);
    await loadConversations();
}

watch(activeTab, async () => {
    await loadConversations();
});

watch(searchQuery, () => {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }

    searchDebounceTimer = setTimeout(async () => {
        await loadConversations();
    }, 350);
});

onMounted(async () => {
    supportLogger.info('lifecycle.mounted', 'Support inbox view mounted.');
    await Promise.all([refreshRealtimeToken(), loadAgents(), loadConversations()]);
    window.addEventListener('echo:connected', handleEchoConnected);
    window.addEventListener('pointerdown', handleGlobalPointerDown);

    realtimeTokenRefreshTimer = setInterval(() => {
        refreshRealtimeToken();
    }, 240000);
});

onBeforeUnmount(() => {
    supportLogger.info('lifecycle.unmount', 'Support inbox view unmounting; cleaning realtime resources.');
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = null;
    }

    if (realtimeTokenRefreshTimer) {
        clearInterval(realtimeTokenRefreshTimer);
        realtimeTokenRefreshTimer = null;
    }

    if (realtimeInboxRefreshTimer) {
        clearTimeout(realtimeInboxRefreshTimer);
        realtimeInboxRefreshTimer = null;
    }

    if (realtimeSubscriptionRetryTimer) {
        clearTimeout(realtimeSubscriptionRetryTimer);
        realtimeSubscriptionRetryTimer = null;
    }

    if (customerTypingTimer) {
        clearTimeout(customerTypingTimer);
        customerTypingTimer = null;
    }

    if (typingDebounceTimer) {
        clearTimeout(typingDebounceTimer);
        typingDebounceTimer = null;
    }

    if (sendCooldownTimer) {
        clearInterval(sendCooldownTimer);
        sendCooldownTimer = null;
    }

    clearCustomerTypingIndicator();
    clearComposerFiles();
    clearRealtimeSubscription();
    window.removeEventListener('echo:connected', handleEchoConnected);
    window.removeEventListener('pointerdown', handleGlobalPointerDown);
});
</script>

<template>
    <div class="flex-1 w-full bg-[var(--surface-primary)] flex overflow-hidden border-t border-[var(--border-default)]">
        <!-- Left Sidebar: Conversations List -->
        <div class="w-72 flex-shrink-0 border-r border-[var(--border-default)] bg-[var(--surface-secondary)]/30 flex flex-col">
            <div class="p-2.5 border-b border-[var(--border-default)]">
                <div class="flex items-center gap-1.5">
                    <Dropdown>
                        <template #trigger>
                            <button class="h-8 px-2.5 inline-flex items-center gap-1.5 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] text-[11px] font-semibold text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] transition-colors outline-none">
                                <span class="leading-none">{{ activeScopeShortLabel }}</span>
                                <ChevronDown class="h-3 w-3 text-[var(--text-muted)]" />
                            </button>
                        </template>
                        <DropdownItem @click="activeTab = 'mine'" :class="{ 'bg-[var(--surface-tertiary)]': activeTab === 'mine' }">
                            <User class="mr-2 h-4 w-4" /> My Conversations
                        </DropdownItem>
                        <DropdownItem @click="activeTab = 'unassigned'" :class="{ 'bg-[var(--surface-tertiary)]': activeTab === 'unassigned' }">
                            <Zap class="mr-2 h-4 w-4" /> New Conversations
                        </DropdownItem>
                        <DropdownItem @click="activeTab = 'all'" :class="{ 'bg-[var(--surface-tertiary)]': activeTab === 'all' }">
                            <Hash class="mr-2 h-4 w-4" /> All Activity
                        </DropdownItem>
                    </Dropdown>

                    <SearchInput
                        v-model="searchQuery"
                        size="sm"
                        placeholder="Search conversations..."
                        class="min-w-0 flex-1"
                    />

                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-8 w-8 p-0 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] hover:bg-[var(--surface-tertiary)] transition-colors"
                        @click="loadConversations"
                    >
                        <Filter class="h-3.5 w-3.5 text-[var(--text-secondary)]" />
                    </Button>

                    <Badge
                        variant="secondary"
                        size="sm"
                        class="h-6 min-w-[28px] px-1.5 justify-center text-[10px] font-semibold tabular-nums"
                        :title="`${conversations.length} conversations in ${activeScopeTitle}`"
                    >
                        {{ conversations.length }}
                    </Badge>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto">
                <div v-if="isLoadingList" class="h-full flex items-center justify-center text-[var(--text-secondary)] text-sm">
                    <Loader2 class="h-4 w-4 mr-2 animate-spin" />
                    Loading conversations...
                </div>

                <div v-else-if="conversations.length === 0" class="h-full flex items-center justify-center text-[var(--text-secondary)] text-sm px-4 text-center">
                    No conversations found for this inbox scope.
                </div>

                <div
                    v-else
                    v-for="chat in conversations"
                    :key="chat.id"
                    @click="selectConversation(chat.id)"
                    class="px-3 py-2.5 border-b border-[var(--border-default)]/50 cursor-pointer transition-colors relative"
                    :class="chat.id === activeConversationId ? 'bg-[var(--surface-primary)] border-l-4 border-l-[var(--interactive-primary)]' : 'hover:bg-[var(--surface-primary)] border-l-4 border-l-transparent'"
                >
                    <div class="flex items-start gap-2.5">
                        <div class="relative">
                            <Avatar
                                :src="chat.requester?.avatar_url"
                                :thumb-url="chat.requester?.avatar_thumb_url"
                                :alt="getConversationDisplayName(chat)"
                                :fallback="getConversationInitials(chat)"
                                :color="chat.requester?.avatar_color || 'var(--surface-tertiary)'"
                                size="xs"
                                class="font-medium"
                            />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-0.5">
                                <span class="font-medium text-[var(--text-primary)] truncate pr-2 text-[13px] leading-tight">{{ getConversationDisplayName(chat) }}</span>
                                <span class="text-[11px] text-[var(--text-muted)] whitespace-nowrap">{{ formatRelativeTime(chat.last_message_at || chat.updated_at || chat.created_at) }}</span>
                            </div>
                            <p class="text-[12px] leading-snug text-[var(--text-secondary)] truncate">{{ chat.latest_message?.body || 'No messages yet.' }}</p>

                            <div class="flex items-center justify-between mt-1.5">
                                <div class="flex items-center gap-1" :class="getStatusClasses(chat.status)">
                                    <component :is="getStatusIcon(chat.status)" class="w-3 h-3" />
                                    <span class="text-[9px] font-medium uppercase tracking-wider">{{ getStatusLabel(chat.status) }}</span>
                                </div>
                                <Badge v-if="chat.ai_handoff_required" variant="warning" size="sm" class="h-4 px-1 text-[9px]">
                                    Escalated
                                </Badge>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="conversations.length > 0" class="px-3 py-2 border-t border-[var(--border-default)]/50">
                    <Button
                        v-if="canLoadMoreConversations"
                        variant="ghost"
                        size="sm"
                        class="w-full h-8 text-xs"
                        :disabled="isLoadingMoreConversations || isLoadingList"
                        @click="loadMoreConversations"
                    >
                        <Loader2 v-if="isLoadingMoreConversations" class="h-3.5 w-3.5 mr-1.5 animate-spin" />
                        <span>{{ isLoadingMoreConversations ? 'Loading...' : 'Load more' }}</span>
                    </Button>
                    <p v-else class="text-center text-[10px] text-[var(--text-muted)] py-1">
                        All conversations loaded
                    </p>
                </div>
            </div>
        </div>

        <!-- Middle Column: Active Chat Thread -->
        <div class="flex-1 flex flex-col bg-[var(--surface-primary)] relative border-r border-[var(--border-default)]">
            <div class="absolute inset-0 pointer-events-none opacity-[0.03] dark:opacity-[0.05]"
                 style="background-image: radial-gradient(circle at 50% 50%, var(--text-primary) 1px, transparent 1px); background-size: 24px 24px;">
            </div>

            <div v-if="!activeConversationDisplay" class="flex-1 flex items-center justify-center text-[var(--text-secondary)] text-sm z-10">
                Select a conversation to begin.
            </div>

            <template v-else>
                <div class="h-14 px-4 border-b border-[var(--border-default)] flex items-center justify-between bg-[var(--surface-primary)]/90 backdrop-blur-sm z-10 sticky top-0">
                    <div class="flex items-center gap-2.5">
                        <Avatar
                            :src="activeConversationDisplay.avatarUrl"
                            :thumb-url="activeConversationDisplay.avatarThumbUrl"
                            alt="Conversation requester"
                            :fallback="getConversationInitials(activeConversation || {})"
                            :color="activeConversationDisplay.avatarColor || 'var(--surface-tertiary)'"
                            size="sm"
                        />
                        <Badge :variant="statusBadgeVariant(activeConversationDisplay.status)" size="sm" class="capitalize h-6 px-2 text-[10px]">
                            {{ getStatusLabel(activeConversationDisplay.status) }}
                        </Badge>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <Button
                            variant="outline"
                            size="sm"
                            class="hidden sm:flex h-8 text-xs px-2.5"
                            :disabled="isResolving || composerDisabled"
                            @click="resolveConversation"
                        >
                            <Loader2 v-if="isResolving" class="w-3.5 h-3.5 mr-1.5 animate-spin" />
                            <CheckCircle2 v-else class="w-3.5 h-3.5 mr-1.5" />
                            Mark Resolved
                        </Button>
                        <Dropdown>
                            <template #trigger>
                                <Button variant="ghost" size="sm" class="h-8 w-8 p-0">
                                    <MoreVertical class="h-3.5 w-3.5 text-[var(--text-secondary)]" />
                                </Button>
                            </template>
                            <DropdownItem @click="assignToCurrentUser">Assign to me</DropdownItem>
                            <DropdownItem @click="refreshCurrentConversation">Refresh conversation</DropdownItem>
                            <DropdownSeparator />
                            <DropdownItem class="text-red-500" @click="resolveConversation">Resolve conversation</DropdownItem>
                        </Dropdown>
                    </div>
                </div>

                <div
                    ref="messagesContainer"
                    class="flex-1 overflow-y-auto p-6 space-y-6 relative z-0"
                    @scroll="onMessagesScroll"
                >
                    <div v-if="isLoadingConversation" class="h-full flex items-center justify-center text-[var(--text-secondary)] text-sm">
                        <Loader2 class="h-4 w-4 mr-2 animate-spin" />
                        Loading conversation...
                    </div>

                    <div v-if="isLoadingOlderMessages" class="flex items-center justify-center text-[var(--text-secondary)] text-xs">
                        <Loader2 class="h-3.5 w-3.5 mr-2 animate-spin" />
                        Loading older messages...
                    </div>

                    <div
                        v-else-if="activeConversationMessages.length === 0 && pendingMessagesForActiveConversation.length === 0"
                        class="h-full flex items-center justify-center text-[var(--text-secondary)] text-sm"
                    >
                        No messages yet.
                    </div>

                    <div
                        v-else
                        v-for="message in activeConversationMessages"
                        :key="message.id"
                        :id="message.type === 'divider' ? undefined : `support-inbox-message-${message.id}`"
                        class="flex flex-col"
                    >
                        <div v-if="message.type === 'divider'" class="flex items-center gap-3 my-3">
                            <div class="h-px flex-1 bg-[var(--border-default)]/70"></div>
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-[var(--interactive-primary)]">
                                {{ message.content }}
                                <span class="ml-1 normal-case text-[var(--text-muted)]">({{ message.unreadCount }})</span>
                            </span>
                            <div class="h-px flex-1 bg-[var(--border-default)]/70"></div>
                        </div>

                        <div v-else-if="message.type === 'system'" class="flex justify-center my-2">
                            <div class="bg-[var(--surface-secondary)] px-3 py-1.5 rounded-full text-xs text-[var(--text-secondary)] border border-[var(--border-default)]/50 shadow-sm backdrop-blur-sm">
                                {{ message.content }} • {{ message.time }}
                            </div>
                        </div>

                        <div v-else-if="message.type === 'note'" class="flex flex-col items-center my-4">
                            <div class="bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-400 w-full max-w-lg p-4 rounded-xl shadow-sm">
                                <div class="flex items-center gap-2 mb-2">
                                    <AlertCircle class="w-4 h-4" />
                                    <span class="text-xs font-bold uppercase tracking-wider">Internal Note</span>
                                    <span class="text-xs opacity-70 ml-auto">{{ message.time }} by {{ message.agentName }}</span>
                                </div>
                                <p v-if="messageContentForDisplay(message)" class="text-sm whitespace-pre-wrap break-words">{{ messageContentForDisplay(message) }}</p>
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
                                    tone="note"
                                />
                            </div>
                        </div>

                        <div v-else-if="message.type === 'visitor'" class="flex items-end gap-2 mb-2">
                            <Avatar
                                :src="message.senderAvatarUrl || activeConversationDisplay.avatarUrl"
                                :thumb-url="message.senderAvatarThumbUrl || activeConversationDisplay.avatarThumbUrl"
                                :alt="activeConversationDisplay.name"
                                :fallback="getConversationInitials(activeConversation || {})"
                                :color="message.senderAvatarColor || activeConversationDisplay.avatarColor || 'var(--surface-tertiary)'"
                                size="xs"
                                class="mb-1"
                            />
                            <div class="max-w-[75%] flex flex-col items-start">
                                <div class="bg-[var(--surface-elevated)] border border-black/5 dark:border-white/5 text-[var(--text-primary)] px-4 py-2.5 rounded-2xl rounded-bl-sm shadow-md backdrop-blur-sm text-sm">
                                    <p v-if="messageContentForDisplay(message)" class="whitespace-pre-wrap break-words">{{ messageContentForDisplay(message) }}</p>
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
                                    />
                                </div>
                                <span class="text-[10px] text-[var(--text-muted)] mt-1 ml-1">{{ message.time }}</span>
                            </div>
                        </div>

                        <div v-else class="flex flex-col items-end mb-2">
                            <div class="max-w-[75%] flex flex-col items-end">
                                <div class="bg-[var(--interactive-primary)] text-white px-4 py-2.5 rounded-2xl rounded-br-sm shadow-md border border-black/5 dark:border-white/5 backdrop-blur-sm text-sm">
                                    <p v-if="messageContentForDisplay(message)" class="whitespace-pre-wrap break-words">{{ messageContentForDisplay(message) }}</p>
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
                                    />
                                </div>
                                <span class="text-[10px] text-[var(--text-muted)] mt-1 mr-1 text-right">{{ message.time }} • {{ message.agentName }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-if="showJumpToLatest" class="sticky bottom-2 z-20 flex justify-center gap-2 pointer-events-none">
                        <Button
                            v-if="unreadMessageCount > 0"
                            size="sm"
                            variant="outline"
                            class="pointer-events-auto bg-[var(--surface-primary)]/95 backdrop-blur border-[var(--border-default)] shadow"
                            @click="jumpToFirstUnread"
                        >
                            First unread
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            class="pointer-events-auto bg-[var(--surface-primary)]/95 backdrop-blur border-[var(--border-default)] shadow"
                            @click="jumpToLatest"
                        >
                            <ChevronsDown class="h-3.5 w-3.5 mr-1.5" />
                            Jump to latest
                            <span
                                v-if="unreadMessageCount > 0"
                                class="ml-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-[var(--interactive-primary)] px-1.5 text-[10px] font-semibold text-white"
                            >
                                {{ unreadMessageCount > 99 ? '99+' : unreadMessageCount }}
                            </span>
                        </Button>
                    </div>

                    <div
                        v-for="pending in pendingMessagesForActiveConversation"
                        :key="pending.id"
                        class="flex flex-col items-end mb-2"
                    >
                        <div
                            v-if="pending.isPrivateNote"
                            class="max-w-[75%] bg-amber-500/10 border border-amber-500/25 text-amber-700 dark:text-amber-400 px-4 py-2.5 rounded-2xl rounded-br-sm text-sm"
                        >
                            {{ pending.body }}
                            <p v-if="pending.fileNames?.length" class="mt-1 text-[11px]">
                                {{ pending.fileNames.length }} attachment<span v-if="pending.fileNames.length > 1">s</span>
                            </p>
                        </div>
                        <div
                            v-else
                            class="max-w-[75%] bg-[var(--interactive-primary)] text-white px-4 py-2.5 rounded-2xl rounded-br-sm shadow-md border border-black/5 dark:border-white/5 text-sm"
                        >
                            {{ pending.body }}
                            <p v-if="pending.fileNames?.length" class="mt-1 text-[11px] text-white/90">
                                {{ pending.fileNames.length }} attachment<span v-if="pending.fileNames.length > 1">s</span>
                            </p>
                        </div>

                        <div class="mt-1 mr-1 flex items-center gap-2 text-[10px] text-[var(--text-muted)]">
                            <template v-if="pending.status === 'sending'">
                                <Loader2 class="h-3 w-3 animate-spin" />
                                <span>{{ pending.isPrivateNote ? 'Sending note...' : 'Sending...' }}</span>
                            </template>
                            <template v-else>
                                <span class="text-red-500 dark:text-red-400">
                                    {{ pending.isPrivateNote ? 'Failed to send note.' : 'Failed to send.' }}
                                </span>
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
                </div>

                <div class="p-4 bg-[var(--surface-primary)] border-t border-[var(--border-default)] z-10 relative">
                    <div
                        v-if="customerTypingIndicator"
                        class="px-2 pb-2"
                    >
                        <div class="support-typing-pill">
                            <div class="support-typing-dots">
                                <span class="support-typing-dot support-typing-delay-1"></span>
                                <span class="support-typing-dot support-typing-delay-2"></span>
                                <span class="support-typing-dot"></span>
                            </div>
                            <span class="text-xs text-[var(--text-secondary)]">{{ customerTypingIndicator }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-1 mb-2 px-2">
                        <button
                            @click="isNoteMode = false"
                            class="text-xs font-semibold px-3 py-1 rounded-full transition-colors"
                            :class="!isNoteMode ? 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]' : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]'"
                        >
                            Reply
                        </button>
                        <button
                            @click="isNoteMode = true"
                            class="text-xs font-semibold px-3 py-1 rounded-full transition-colors flex items-center gap-1"
                            :class="isNoteMode ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]'"
                        >
                            <AlertCircle class="w-3 h-3" /> Internal Note
                        </button>
                    </div>

                    <div
                        class="rounded-xl border shadow-sm transition-all"
                        :class="[
                            isNoteMode ? 'bg-amber-500/5 border-amber-500/30' : 'bg-[var(--surface-secondary)] border-[var(--border-default)]',
                            'hover:border-black/10 dark:hover:border-white/15'
                        ]"
                    >
                        <input
                            ref="composerFileInput"
                            type="file"
                            multiple
                            class="hidden"
                            @change="handleComposerFileSelection"
                        />
                        <textarea
                            v-model="newMessage"
                            :placeholder="isNoteMode ? 'Type an internal note (visitors cannot see this)...' : 'Type your message... (Press Shift+Enter for new line)'"
                            class="w-full bg-transparent border-none p-3 text-sm focus:outline-none focus:ring-0 focus:ring-offset-0 focus-visible:ring-0 focus-visible:outline-none resize-none min-h-[80px] max-h-[200px] text-[var(--text-primary)]"
                            :disabled="composerDisabled || isSending || isSendCoolingDown"
                            @keydown.enter.exact.prevent="sendMessage"
                            @input="handleComposerInput"
                            @blur="emitTyping(false)"
                        ></textarea>

                        <div class="flex items-center justify-between p-2 border-t border-[var(--border-default)]/50">
                            <div class="flex items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="h-8 w-8 p-0 hover:bg-black/5 dark:hover:bg-white/5"
                                    title="Attach file"
                                    :disabled="composerDisabled || isSending || isSendCoolingDown"
                                    @click="openComposerFilePicker"
                                >
                                    <Paperclip class="h-4 w-4 text-[var(--text-secondary)]" />
                                </Button>
                                <Button variant="ghost" size="sm" class="h-8 w-8 p-0 hover:bg-black/5 dark:hover:bg-white/5" title="Insert image" :disabled="composerDisabled">
                                    <ImageIcon class="h-4 w-4 text-[var(--text-secondary)]" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="h-8 w-8 p-0 hover:bg-black/5 dark:hover:bg-white/5"
                                    title="Insert emoji"
                                    :disabled="composerDisabled || isSending || isSendCoolingDown"
                                    @click="toggleEmojiPicker"
                                >
                                    <Smile class="h-4 w-4 text-[var(--text-secondary)]" />
                                </Button>
                                <div class="h-4 w-px bg-[var(--border-default)] mx-1"></div>
                                <Button variant="ghost" size="sm" class="h-8 px-2 text-xs font-medium text-[var(--text-secondary)] hover:bg-black/5 dark:hover:bg-white/5" title="Use a saved macro" :disabled="composerDisabled">
                                    <Zap class="h-3.5 w-3.5 mr-1" /> Macros
                                </Button>
                            </div>
                            <Button
                                :variant="isNoteMode ? 'outline' : 'primary'"
                                size="sm"
                                @click="sendMessage"
                                :disabled="composerDisabled || isSending || isSendCoolingDown || (!newMessage.trim() && selectedComposerFiles.length === 0)"
                                :class="[
                                    isNoteMode ? 'bg-amber-100 dark:bg-amber-900 border-amber-500/30 text-amber-700 dark:text-amber-300 hover:bg-amber-200 dark:hover:bg-amber-800' : ''
                                ]"
                            >
                                <Loader2 v-if="isSending" class="h-3.5 w-3.5 mr-1.5 animate-spin" />
                                <span>{{ isNoteMode ? 'Add Note' : 'Send' }}</span>
                                <Send class="h-3.5 w-3.5 ml-1.5" />
                            </Button>
                        </div>
                        <div
                            v-if="showEmojiPicker"
                            ref="emojiPickerRef"
                            class="mx-2 mb-2 rounded-xl border border-[var(--border-default)] bg-[var(--surface-primary)] p-2 shadow-lg"
                        >
                            <div class="grid grid-cols-10 gap-1">
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
                        <div
                            v-if="isSendCoolingDown"
                            class="mx-2 mb-2 rounded-lg border border-amber-400/40 bg-amber-500/10 px-2.5 py-1.5 text-[11px] text-amber-600 dark:text-amber-300"
                        >
                            You’re sending messages too quickly. Please wait {{ sendCooldownSeconds }}s.
                        </div>
                        <div v-if="selectedComposerFiles.length > 0" class="px-3 pb-2 flex flex-wrap gap-1.5">
                            <span
                                v-for="(file, index) in selectedComposerFiles"
                                :key="`${file.name}-${index}`"
                                class="inline-flex items-center gap-1 rounded-full bg-[var(--surface-primary)] border border-[var(--border-default)] px-2 py-0.5 text-[11px] text-[var(--text-secondary)]"
                            >
                                <Paperclip class="h-3 w-3" />
                                <span class="max-w-[180px] truncate">{{ file.name }}</span>
                                <button
                                    type="button"
                                    class="text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                                    @click="removeComposerFile(index)"
                                >
                                    ×
                                </button>
                            </span>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Right Sidebar: Visitor Info & Context -->
        <div class="w-72 flex-shrink-0 bg-[var(--surface-secondary)]/30 overflow-y-auto">
            <div v-if="!activeConversationDisplay" class="h-full flex items-center justify-center text-[var(--text-secondary)] text-sm p-4 text-center">
                Customer context will appear here when you select a conversation.
            </div>

            <template v-else>
                <div class="p-3 space-y-3">
                    <section class="rounded-xl border border-[var(--border-default)] bg-[var(--surface-primary)]/60 p-3">
                        <div class="flex items-center gap-2.5">
                            <Avatar
                                :src="activeConversationDisplay.avatarUrl"
                                :thumb-url="activeConversationDisplay.avatarThumbUrl"
                                :alt="activeConversationDisplay.name"
                                :fallback="getConversationInitials(activeConversation || {})"
                                :color="activeConversationDisplay.avatarColor || 'var(--surface-tertiary)'"
                                size="lg"
                                class="shadow-sm"
                            />
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-sm font-semibold text-[var(--text-primary)]">{{ activeConversationDisplay.name }}</h3>
                                <p class="truncate text-[11px] text-[var(--text-secondary)]">{{ activeConversationDisplay.email }}</p>
                                <p class="mt-1 text-[10px] uppercase tracking-wide text-[var(--text-muted)]">
                                    {{ getStatusLabel(activeConversationDisplay.status) }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 space-y-2">
                            <select
                                v-model="selectedAgentId"
                                class="h-8 w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] px-2.5 text-[11px] text-[var(--text-primary)]"
                                :disabled="isLoadingAgents || isAssigning"
                            >
                                <option value="" disabled>Select an agent</option>
                                <option v-for="agent in agents" :key="agent.id" :value="agent.id">
                                    {{ agent.name }} ({{ agent.email }})
                                </option>
                            </select>
                            <Button
                                variant="outline"
                                size="sm"
                                class="h-8 w-full text-[11px]"
                                :disabled="!selectedAgentId || isAssigning"
                                @click="assignConversation()"
                            >
                                <Loader2 v-if="isAssigning" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                Assign Conversation
                            </Button>
                        </div>
                    </section>

                    <section class="rounded-xl border border-[var(--border-default)] bg-[var(--surface-primary)]/50 p-3 text-sm">
                        <h4 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-[var(--text-secondary)]">Current Session</h4>
                        <ul class="space-y-1.5">
                            <li class="flex items-start gap-2 rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-1.5">
                                <MapPin class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]" />
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[var(--text-secondary)]">Location</p>
                                    <p class="truncate text-xs font-medium text-[var(--text-primary)]">{{ activeConversationDisplay.location }}</p>
                                </div>
                            </li>
                            <li class="flex items-start gap-2 rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-1.5">
                                <Globe class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]" />
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[var(--text-secondary)]">Browser & OS</p>
                                    <p class="line-clamp-2 text-xs font-medium text-[var(--text-primary)]">{{ activeConversationDisplay.browser }}</p>
                                </div>
                            </li>
                            <li class="flex items-start gap-2 rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-1.5">
                                <Hash class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]" />
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[var(--text-secondary)]">IP Address</p>
                                    <p class="truncate text-xs font-medium text-[var(--text-primary)]">{{ activeConversationDisplay.ip }}</p>
                                </div>
                            </li>
                            <li class="flex items-start gap-2 rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-1.5">
                                <Monitor class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]" />
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[var(--text-secondary)]">Source URL</p>
                                    <p class="line-clamp-2 break-all text-xs font-medium text-[var(--text-primary)]">{{ activeConversationDisplay.pageView }}</p>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <section class="rounded-xl border border-[var(--border-default)] bg-[var(--surface-primary)]/50 p-3">
                        <h4 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-[var(--text-secondary)]">Quick Links</h4>
                        <div class="grid grid-cols-1 gap-1.5">
                            <button class="group flex w-full items-center gap-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]/30 px-2 py-1.5 text-left transition-colors hover:bg-[var(--surface-secondary)]/60">
                                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-blue-500/10">
                                    <Book class="h-3.5 w-3.5 text-blue-500" />
                                </div>
                                <span class="text-[11px] font-semibold text-[var(--text-primary)]">Knowledge Base</span>
                            </button>
                            <button class="group flex w-full items-center gap-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]/30 px-2 py-1.5 text-left transition-colors hover:bg-[var(--surface-secondary)]/60">
                                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-emerald-500/10">
                                    <Code class="h-3.5 w-3.5 text-emerald-500" />
                                </div>
                                <span class="text-[11px] font-semibold text-[var(--text-primary)]">API Documentation</span>
                            </button>
                        </div>
                    </section>

                    <section class="rounded-xl border border-[var(--border-default)] bg-[var(--surface-primary)]/50 p-3 text-sm">
                        <div class="mb-2 flex items-center justify-between">
                            <h4 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-secondary)]">Recent Activity</h4>
                            <button class="text-[10px] font-semibold text-[var(--interactive-primary)] hover:underline">View All</button>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-center gap-2 rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-1.5">
                                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-500/10">
                                    <MessageSquare class="h-3.5 w-3.5 text-blue-500" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-[11px] font-semibold text-[var(--text-primary)]">Conversation Opened</p>
                                    <p class="text-[10px] text-[var(--text-muted)]">{{ formatRelativeTime(activeConversation.created_at) }}</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-1.5">
                                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-500/10">
                                    <Book class="h-3.5 w-3.5 text-slate-500" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-[11px] font-semibold text-[var(--text-primary)]">Latest Status</p>
                                    <p class="text-[10px] text-[var(--text-muted)]">{{ getStatusLabel(activeConversation.status) }}</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-1.5">
                                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-500/10">
                                    <UserPlus class="h-3.5 w-3.5 text-emerald-500" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-[11px] font-semibold text-[var(--text-primary)]">Assigned To</p>
                                    <p class="truncate text-[10px] text-[var(--text-muted)]">{{ activeConversation.assignee?.name || 'Unassigned' }}</p>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
textarea {
    outline: none !important;
    box-shadow: none !important;
    border: none !important;
}
textarea:focus {
    outline: none !important;
    box-shadow: none !important;
    border: none !important;
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
