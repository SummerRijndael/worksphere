<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import {
    Filter,
    MoreVertical,
    Paperclip,
    Send,
    Smile,
    Image as ImageIcon,
    Bold,
    Underline,
    List,
    ListOrdered,
    Link2,
    Clock,
    CheckCircle2,
    AlertCircle,
    MapPin,
    Globe,
    Monitor,
    MessageSquare,
    Zap,
    ChevronDown,
    ChevronsDown,
    User,
    Hash,
    FileText,
    Check,
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
import { renderSupportRichText } from '@/utils/supportRichText';

const toast = useToast();
const authStore = useAuthStore();
const supportLogger = createSupportLogger('Inbox');
const route = useRoute();

const SUPPORT_INBOX_SCOPE_STORAGE_KEY = 'worksphere.support.inbox.scope';
const SUPPORT_INBOX_LEFT_WIDTH_STORAGE_KEY = 'worksphere.support.inbox.left_sidebar_width';
const SUPPORT_INBOX_RIGHT_WIDTH_STORAGE_KEY = 'worksphere.support.inbox.right_sidebar_width';
const LEFT_SIDEBAR_MIN_WIDTH = 220;
const LEFT_SIDEBAR_MAX_WIDTH = 420;
const RIGHT_SIDEBAR_MIN_WIDTH = 220;
const RIGHT_SIDEBAR_MAX_WIDTH = 420;
const THREAD_MIN_WIDTH = 420;
const DEFAULT_LEFT_SIDEBAR_WIDTH = 288;
const DEFAULT_RIGHT_SIDEBAR_WIDTH = 288;
const DEFAULT_UI_TIMER_SETTINGS = {
    tick_ms: 1000,
    last_response_warn_minutes: 5,
    last_response_alert_minutes: 15,
    last_response_include_bot: true,
};

function normalizeInboxScope(value) {
    const normalized = String(value || '').trim().toLowerCase();
    if (['mine', 'unassigned', 'all'].includes(normalized)) {
        return normalized;
    }

    return 'all';
}

function readStoredInboxScope() {
    if (typeof window === 'undefined') {
        return 'all';
    }

    return normalizeInboxScope(window.localStorage.getItem(SUPPORT_INBOX_SCOPE_STORAGE_KEY));
}

function readRequestedInboxScope() {
    const raw = Array.isArray(route.query.scope) ? route.query.scope[0] : route.query.scope;
    if (typeof raw !== 'string' || raw.trim() === '') {
        return null;
    }

    return normalizeInboxScope(raw);
}

function readRequestedConversationId() {
    const raw = Array.isArray(route.query.conversation) ? route.query.conversation[0] : route.query.conversation;
    if (typeof raw !== 'string') {
        return null;
    }

    const normalized = raw.trim();
    return normalized !== '' ? normalized : null;
}

function readStoredWidth(storageKey, fallback) {
    if (typeof window === 'undefined') {
        return fallback;
    }

    const raw = Number(window.localStorage.getItem(storageKey));
    if (!Number.isFinite(raw) || raw <= 0) {
        return fallback;
    }

    return Math.round(raw);
}

const activeTab = ref(readStoredInboxScope()); // mine | unassigned | all
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
const detailsSidebarTab = ref('overview');
const messagesContainer = ref(null);
const composerFileInput = ref(null);
const composerTextareaRef = ref(null);
const supportLayoutRef = ref(null);
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
const isComposerCollapsed = ref(false);
const sendCooldownSeconds = ref(0);
const liveClockNow = ref(Date.now());
const uiTimerSettings = ref({ ...DEFAULT_UI_TIMER_SETTINGS });

const isLoadingList = ref(false);
const isLoadingMoreConversations = ref(false);
const isRefreshingListSilently = ref(false);
const isLoadingConversation = ref(false);
const isLoadingAgents = ref(false);
const isSending = ref(false);
const isAssigning = ref(false);
const isResolving = ref(false);
const isEnding = ref(false);
const closeResolved = ref(false);
const activeSidebarResize = ref(null);
const leftSidebarWidth = ref(readStoredWidth(SUPPORT_INBOX_LEFT_WIDTH_STORAGE_KEY, DEFAULT_LEFT_SIDEBAR_WIDTH));
const rightSidebarWidth = ref(readStoredWidth(SUPPORT_INBOX_RIGHT_WIDTH_STORAGE_KEY, DEFAULT_RIGHT_SIDEBAR_WIDTH));
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
let liveCounterTicker = null;

async function focusComposerTextarea() {
    await nextTick();
    const input = composerTextareaRef.value;
    if (!input || input.disabled) {
        return;
    }

    input.focus({ preventScroll: true });
    if (typeof input.setSelectionRange === 'function') {
        const len = String(newMessage.value || '').length;
        try {
            input.setSelectionRange(len, len);
        } catch {
            // Ignore selection errors for non-text mode environments.
        }
    }
}

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
            agentName = 'Eden';
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

const conversationAttachmentItems = computed(() => {
    const messages = Array.isArray(conversationMessages.value) ? conversationMessages.value : [];
    const flattened = [];

    messages.forEach((message) => {
        const attachments = Array.isArray(message?.attachments) ? message.attachments : [];
        attachments.forEach((attachment, index) => {
            if (!attachment || (!attachment.url && !attachment.download_url)) {
                return;
            }

            const senderName = message?.sender?.name
                || (message?.sender_type === 'customer'
                    ? (activeConversationDisplay.value?.name || 'Customer')
                    : message?.sender_type === 'bot'
                        ? 'Eden'
                        : 'Support Agent');

            flattened.push({
                ...attachment,
                _key: `${message?.id || 'message'}-${attachment?.id || attachment?.media_id || index}`,
                _messageId: message?.id || null,
                _createdAt: message?.created_at || null,
                _senderName: senderName,
                _senderType: message?.sender_type || 'agent',
            });
        });
    });

    return flattened.sort((a, b) => {
        const timeA = new Date(a?._createdAt || 0).getTime();
        const timeB = new Date(b?._createdAt || 0).getTime();
        return timeB - timeA;
    });
});

const internalNoteItems = computed(() => {
    const messages = Array.isArray(conversationMessages.value) ? conversationMessages.value : [];
    return messages
        .filter((message) => message?.sender_type === 'agent' && Boolean(message?.is_private_note))
        .map((message) => ({
            id: message.id,
            body: message.body || '',
            attachments: Array.isArray(message.attachments) ? message.attachments : [],
            created_at: message.created_at,
            sender_name: message?.sender?.name || 'Support Agent',
            sender_avatar_url: message?.sender?.avatar_url || message?.sender?.avatar || null,
            sender_avatar_thumb_url: message?.sender?.avatar_thumb_url || null,
            sender_avatar_color: message?.sender?.avatar_color || null,
        }))
        .sort((a, b) => new Date(b.created_at || 0).getTime() - new Date(a.created_at || 0).getTime());
});

const detailsMediaCount = computed(() => conversationAttachmentItems.value.length);
const detailsNotesCount = computed(() => internalNoteItems.value.length);
const activeConversationStatus = computed(() => String(
    activeConversationDisplay.value?.status
    || activeConversation.value?.status
    || 'open'
).toLowerCase());
const isConversationResolvedLike = computed(() => {
    const chatState = String(activeConversation.value?.chat_state || '').toLowerCase();
    return chatState === 'chat_ended' || ['wrap_up', 'resolved', 'closed'].includes(activeConversationStatus.value);
});
const isConversationAssigned = computed(() => {
    const assignee = activeConversation.value?.assignee || {};
    return Boolean(assignee.id || assignee.public_id || assignee.name);
});
const isAssignedToCurrentUser = computed(() => {
    const assigneePublicId = activeConversation.value?.assignee?.public_id || null;
    const currentUserPublicId = authStore.user?.public_id || null;
    return Boolean(assigneePublicId && currentUserPublicId && assigneePublicId === currentUserPublicId);
});
const assignmentStateLabel = computed(() => {
    const assigneeName = activeConversation.value?.assignee?.name || '';
    if (isConversationAssigned.value && assigneeName) {
        return `Assigned to ${assigneeName}`;
    }

    if (isConversationAssigned.value) {
        return 'Assigned to agent';
    }

    return 'Awaiting agent';
});
const endActionLabel = computed(() => {
    if (activeConversationStatus.value === 'closed') {
        return 'Already closed';
    }
    if (activeConversationStatus.value === 'wrap_up') {
        return 'Close started';
    }

    return 'End conversation';
});
const canAssignActiveConversation = computed(() => Boolean(activeConversationId.value) && !isConversationResolvedLike.value);
const canEndActiveConversation = computed(() => Boolean(activeConversationId.value) && !isConversationResolvedLike.value);
const conversationStartedSummary = computed(() => {
    const createdAt = activeConversation.value?.created_at || null;
    return {
        relative: formatRelativeTime(createdAt),
        exact: formatDateTime(createdAt),
    };
});
const latestSupportReplyMeta = computed(() => {
    const messages = Array.isArray(conversationMessages.value) ? conversationMessages.value : [];
    const isAssigned = isConversationAssigned.value;
    const includeBot = Boolean(uiTimerSettings.value.last_response_include_bot);

    const pickLatestByTypes = (senderTypes = []) => {
        const normalized = new Set(senderTypes.map((type) => String(type || '').toLowerCase()));
        let latest = null;
        let latestTs = -1;

        messages.forEach((message) => {
            if (!message || message.is_private_note) {
                return;
            }

            const senderType = String(message.sender_type || '').toLowerCase();
            if (!normalized.has(senderType)) {
                return;
            }

            const ts = new Date(message.created_at || message.updated_at || 0).getTime();
            if (!Number.isFinite(ts) || ts < 0) {
                return;
            }

            if (ts >= latestTs) {
                latest = {
                    sender_type: senderType,
                    created_at: message.created_at || message.updated_at || null,
                };
                latestTs = ts;
            }
        });

        return latest;
    };

    const latest = pickLatestByTypes(
        isAssigned
            ? ['agent']
            : (includeBot ? ['bot', 'agent'] : ['agent'])
    );

    if (latest) {
        return latest;
    }

    const fallback = activeConversation.value?.latest_message || null;
    if (!fallback || fallback.is_private_note) {
        return null;
    }

    const fallbackType = String(fallback.sender_type || '').toLowerCase();
    const createdAt = fallback.created_at || fallback.updated_at || activeConversation.value?.last_message_at || null;
    if (!createdAt) {
        return null;
    }

    if (isAssigned && fallbackType !== 'agent') {
        return null;
    }

    if (!isAssigned && !['agent', ...(includeBot ? ['bot'] : [])].includes(fallbackType)) {
        return null;
    }

    return {
        sender_type: fallbackType || 'agent',
        created_at: createdAt,
    };
});
const latestSupportReplySummary = computed(() => {
    const latest = latestSupportReplyMeta.value;
    if (!latest?.created_at) {
        const actor = isConversationAssigned.value ? 'Agent' : 'Bot';
        return {
            actor,
            relative: '',
            text: `No ${actor.toLowerCase()} reply yet`,
            exact: '',
            muted: true,
        };
    }

    const actor = latest.sender_type === 'agent'
        ? 'Agent'
        : latest.sender_type === 'bot'
            ? 'Bot'
            : 'Support';

    return {
        actor,
        relative: formatRelativeTime(latest.created_at),
        text: `${actor}: ${formatRelativeTime(latest.created_at)}`,
        exact: formatDateTime(latest.created_at),
        muted: false,
    };
});
const latestSupportReplyInline = computed(() => (
    latestSupportReplySummary.value.muted
        ? latestSupportReplySummary.value.text
        : `${latestSupportReplySummary.value.actor} ${latestSupportReplySummary.value.relative}`
));
const conversationDurationSeconds = computed(() => {
    const createdAt = activeConversation.value?.created_at || null;
    if (!createdAt) {
        return 0;
    }

    const startedAtMs = new Date(createdAt).getTime();
    if (!Number.isFinite(startedAtMs)) {
        return 0;
    }

    return Math.max(0, Math.floor((liveClockNow.value - startedAtMs) / 1000));
});
const conversationDurationCounter = computed(() => formatDurationCounter(conversationDurationSeconds.value));
const lastSupportReplyElapsedSeconds = computed(() => {
    const createdAt = latestSupportReplyMeta.value?.created_at || null;
    if (!createdAt) {
        return null;
    }

    const repliedAtMs = new Date(createdAt).getTime();
    if (!Number.isFinite(repliedAtMs)) {
        return null;
    }

    return Math.max(0, Math.floor((liveClockNow.value - repliedAtMs) / 1000));
});
const lastSupportReplyCounter = computed(() => {
    const elapsedSeconds = lastSupportReplyElapsedSeconds.value;
    if (elapsedSeconds === null) {
        return 'Awaiting first reply';
    }

    return formatElapsedCounter(elapsedSeconds);
});
const lastSupportReplyCounterToneClass = computed(() => {
    const elapsedSeconds = lastSupportReplyElapsedSeconds.value;
    if (elapsedSeconds === null) {
        return 'text-[var(--text-muted)]';
    }

    const elapsedMinutes = Math.floor(elapsedSeconds / 60);
    const warnAt = Number(uiTimerSettings.value.last_response_warn_minutes || DEFAULT_UI_TIMER_SETTINGS.last_response_warn_minutes);
    const alertAt = Number(uiTimerSettings.value.last_response_alert_minutes || DEFAULT_UI_TIMER_SETTINGS.last_response_alert_minutes);

    if (elapsedMinutes >= alertAt) {
        return 'text-rose-600 dark:text-rose-300';
    }
    if (elapsedMinutes >= warnAt) {
        return 'text-amber-600 dark:text-amber-300';
    }

    return 'text-emerald-600 dark:text-emerald-300';
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
    return !activeConversation.value || isConversationResolvedLike.value;
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
const leftSidebarStyle = computed(() => ({
    width: `${leftSidebarWidth.value}px`,
}));
const rightSidebarStyle = computed(() => ({
    width: `${rightSidebarWidth.value}px`,
}));
const isAnySidebarResizing = computed(() => activeSidebarResize.value !== null);

function getSupportLayoutWidth() {
    const rect = supportLayoutRef.value?.getBoundingClientRect();
    return rect?.width || 0;
}

function clampSidebarWidth(value, min, max) {
    return Math.min(max, Math.max(min, Math.round(value)));
}

function clampLeftSidebarWidth(value) {
    const layoutWidth = getSupportLayoutWidth();
    const maxByLayout = layoutWidth > 0
        ? layoutWidth - rightSidebarWidth.value - THREAD_MIN_WIDTH
        : LEFT_SIDEBAR_MAX_WIDTH;
    const effectiveMax = Math.max(LEFT_SIDEBAR_MIN_WIDTH, Math.min(LEFT_SIDEBAR_MAX_WIDTH, maxByLayout));

    return clampSidebarWidth(value, LEFT_SIDEBAR_MIN_WIDTH, effectiveMax);
}

function clampRightSidebarWidth(value) {
    const layoutWidth = getSupportLayoutWidth();
    const maxByLayout = layoutWidth > 0
        ? layoutWidth - leftSidebarWidth.value - THREAD_MIN_WIDTH
        : RIGHT_SIDEBAR_MAX_WIDTH;
    const effectiveMax = Math.max(RIGHT_SIDEBAR_MIN_WIDTH, Math.min(RIGHT_SIDEBAR_MAX_WIDTH, maxByLayout));

    return clampSidebarWidth(value, RIGHT_SIDEBAR_MIN_WIDTH, effectiveMax);
}

function stopSidebarResize() {
    if (!activeSidebarResize.value) {
        return;
    }

    activeSidebarResize.value = null;
    window.removeEventListener('pointermove', handleSidebarResizeMove);
    window.removeEventListener('pointerup', stopSidebarResize);
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
}

function handleSidebarResizeMove(event) {
    const layoutRect = supportLayoutRef.value?.getBoundingClientRect();
    if (!layoutRect || !activeSidebarResize.value) {
        return;
    }

    if (activeSidebarResize.value === 'left') {
        const nextWidth = event.clientX - layoutRect.left;
        leftSidebarWidth.value = clampLeftSidebarWidth(nextWidth);
        return;
    }

    const nextWidth = layoutRect.right - event.clientX;
    rightSidebarWidth.value = clampRightSidebarWidth(nextWidth);
}

function startSidebarResize(side, event) {
    if (event.button !== 0) {
        return;
    }

    event.preventDefault();
    activeSidebarResize.value = side;
    window.addEventListener('pointermove', handleSidebarResizeMove);
    window.addEventListener('pointerup', stopSidebarResize);
    document.body.style.cursor = 'col-resize';
    document.body.style.userSelect = 'none';
}

function handleSidebarResizeDoubleClick(side) {
    stopSidebarResize();

    if (side === 'left') {
        leftSidebarWidth.value = clampLeftSidebarWidth(DEFAULT_LEFT_SIDEBAR_WIDTH);
        return;
    }

    rightSidebarWidth.value = clampRightSidebarWidth(DEFAULT_RIGHT_SIDEBAR_WIDTH);
}

function handleSupportLayoutResize() {
    leftSidebarWidth.value = clampLeftSidebarWidth(leftSidebarWidth.value);
    rightSidebarWidth.value = clampRightSidebarWidth(rightSidebarWidth.value);
}

function onQuickLinkFacadeClick(label) {
    toast.info('Quick link coming soon', `${label} integration is in facade mode for now.`);
}

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

    const diffSeconds = Math.floor((liveClockNow.value - date.getTime()) / 1000);
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

function formatDurationCounter(totalSeconds) {
    const safeSeconds = Math.max(0, Math.floor(Number(totalSeconds) || 0));
    const hours = Math.floor(safeSeconds / 3600);
    const minutes = Math.floor((safeSeconds % 3600) / 60);
    const seconds = safeSeconds % 60;

    if (hours > 0) {
        return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }

    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

function formatElapsedCounter(totalSeconds) {
    const safeSeconds = Math.max(0, Math.floor(Number(totalSeconds) || 0));
    const hours = Math.floor(safeSeconds / 3600);
    const minutes = Math.floor((safeSeconds % 3600) / 60);
    const seconds = safeSeconds % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m ${seconds}s`;
    }

    return `${Math.floor(safeSeconds / 60)}m ${seconds}s`;
}

function normalizeUiTimerMeta(meta) {
    const incoming = meta?.ui_timers || {};
    const tickMsRaw = Number(incoming.tick_ms);
    const tickMs = Number.isFinite(tickMsRaw) ? Math.max(250, Math.floor(tickMsRaw)) : DEFAULT_UI_TIMER_SETTINGS.tick_ms;
    const warnRaw = Number(incoming.last_response_warn_minutes);
    const warnMinutes = Number.isFinite(warnRaw) ? Math.max(1, Math.floor(warnRaw)) : DEFAULT_UI_TIMER_SETTINGS.last_response_warn_minutes;
    const alertRaw = Number(incoming.last_response_alert_minutes);
    const alertMinutes = Number.isFinite(alertRaw)
        ? Math.max(warnMinutes, Math.floor(alertRaw))
        : Math.max(warnMinutes, DEFAULT_UI_TIMER_SETTINGS.last_response_alert_minutes);

    return {
        tick_ms: tickMs,
        last_response_warn_minutes: warnMinutes,
        last_response_alert_minutes: alertMinutes,
        last_response_include_bot: Boolean(incoming.last_response_include_bot ?? DEFAULT_UI_TIMER_SETTINGS.last_response_include_bot),
    };
}

function startLiveCounterTicker() {
    if (liveCounterTicker) {
        clearInterval(liveCounterTicker);
        liveCounterTicker = null;
    }

    const tickMs = Math.max(250, Number(uiTimerSettings.value.tick_ms || DEFAULT_UI_TIMER_SETTINGS.tick_ms));
    liveCounterTicker = setInterval(() => {
        liveClockNow.value = Date.now();
    }, tickMs);
}

function stopLiveCounterTicker() {
    if (liveCounterTicker) {
        clearInterval(liveCounterTicker);
        liveCounterTicker = null;
    }
}

function applyUiTimerMeta(meta) {
    const next = normalizeUiTimerMeta(meta);
    const didTickMsChange = next.tick_ms !== uiTimerSettings.value.tick_ms;
    uiTimerSettings.value = next;

    if (didTickMsChange) {
        startLiveCounterTicker();
    }
}

function formatDateTime(isoValue) {
    if (!isoValue) {
        return 'N/A';
    }

    const date = new Date(isoValue);
    if (Number.isNaN(date.getTime())) {
        return 'N/A';
    }

    return date.toLocaleString([], {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
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

function noteContentHtml(note) {
    return renderSupportRichText(String(note?.body || ''));
}

function pendingMessageContentHtml(pending) {
    return renderSupportRichText(String(pending?.body || ''));
}

function withComposerSelection(mutator) {
    if (composerDisabled.value || isSending.value || isSendCoolingDown.value) {
        return;
    }

    const input = composerTextareaRef.value;
    if (!input) {
        return;
    }

    const currentValue = String(newMessage.value || '');
    const start = Number.isFinite(input.selectionStart) ? input.selectionStart : currentValue.length;
    const end = Number.isFinite(input.selectionEnd) ? input.selectionEnd : currentValue.length;
    const selected = currentValue.slice(start, end);

    const result = mutator({
        value: currentValue,
        start,
        end,
        selected,
    });

    if (!result || typeof result.value !== 'string') {
        return;
    }

    newMessage.value = result.value;
    handleComposerInput();

    nextTick(() => {
        const textarea = composerTextareaRef.value;
        if (!textarea || textarea.disabled) {
            return;
        }

        textarea.focus({ preventScroll: true });

        if (typeof textarea.setSelectionRange === 'function') {
            const selectionStart = Number.isFinite(result.selectionStart) ? result.selectionStart : result.value.length;
            const selectionEnd = Number.isFinite(result.selectionEnd) ? result.selectionEnd : selectionStart;
            try {
                textarea.setSelectionRange(selectionStart, selectionEnd);
            } catch {
                // Ignore browsers/input modes that block selection APIs.
            }
        }
    });
}

function applyComposerInlineFormat(prefix, suffix = prefix, placeholder = 'text') {
    withComposerSelection(({ value, start, end, selected }) => {
        const base = selected || placeholder;
        const replacement = `${prefix}${base}${suffix}`;
        const nextValue = `${value.slice(0, start)}${replacement}${value.slice(end)}`;
        const selectionStart = start + prefix.length;
        const selectionEnd = selectionStart + base.length;

        return {
            value: nextValue,
            selectionStart,
            selectionEnd,
        };
    });
}

function applyComposerListFormat(type = 'bullet') {
    withComposerSelection(({ value, start, end, selected }) => {
        const raw = selected || 'List item';
        const lines = raw.split('\n');
        const replacement = lines
            .map((line, index) => {
                const marker = type === 'numbered' ? `${index + 1}.` : '-';
                return `${marker} ${line || 'List item'}`;
            })
            .join('\n');

        const nextValue = `${value.slice(0, start)}${replacement}${value.slice(end)}`;
        const selectionStart = start;
        const selectionEnd = start + replacement.length;

        return {
            value: nextValue,
            selectionStart,
            selectionEnd,
        };
    });
}

function insertComposerLink() {
    withComposerSelection(({ value, start, end, selected }) => {
        const trimmed = selected.trim();
        const selectedIsUrl = /^https?:\/\/\S+$/i.test(trimmed);
        const linkText = selectedIsUrl ? 'link text' : (trimmed || 'link text');
        const url = selectedIsUrl ? trimmed : 'https://example.com';
        const replacement = `[${linkText}](${url})`;
        const nextValue = `${value.slice(0, start)}${replacement}${value.slice(end)}`;
        const urlStartOffset = replacement.indexOf(url);
        const selectionStart = start + urlStartOffset;
        const selectionEnd = selectionStart + url.length;

        return {
            value: nextValue,
            selectionStart,
            selectionEnd,
        };
    });
}

function handleComposerKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
        return;
    }

    if (!event.metaKey && !event.ctrlKey) {
        return;
    }

    const key = String(event.key || '').toLowerCase();
    if (key === 'b') {
        event.preventDefault();
        applyComposerInlineFormat('**', '**', 'bold text');
        return;
    }

    if (key === 'u') {
        event.preventDefault();
        applyComposerInlineFormat('<u>', '</u>', 'underlined text');
        return;
    }

    if (key === 'k') {
        event.preventDefault();
        insertComposerLink();
    }
}

function stripConversationMessages(conversation) {
    if (!conversation || typeof conversation !== 'object') {
        return null;
    }

    const normalized = { ...conversation };
    if (Object.prototype.hasOwnProperty.call(normalized, 'messages')) {
        delete normalized.messages;
    }

    return normalized;
}

function upsertConversationInList(conversation, options = {}) {
    const normalized = stripConversationMessages(conversation);
    if (!normalized?.id) {
        return;
    }

    const moveToTop = options.moveToTop === true;
    const index = conversations.value.findIndex((chat) => chat.id === normalized.id);

    if (index === -1) {
        conversations.value = [normalized, ...conversations.value];
        return;
    }

    const merged = {
        ...conversations.value[index],
        ...normalized,
    };

    const next = [...conversations.value];
    if (moveToTop && index > 0) {
        next.splice(index, 1);
        next.unshift(merged);
    } else {
        next[index] = merged;
    }

    conversations.value = next;
}

function applyConversationSnapshot(conversation, options = {}) {
    const normalized = stripConversationMessages(conversation);
    if (!normalized?.id) {
        return;
    }

    if (activeConversationId.value === normalized.id) {
        activeConversation.value = {
            ...(activeConversation.value || {}),
            ...normalized,
        };
        selectedAgentId.value = activeConversation.value?.assignee?.id || selectedAgentId.value;
    }

    upsertConversationInList(normalized, options);
}

function patchConversationFromMessage(conversationId, message, options = {}) {
    if (!conversationId || !message) {
        return;
    }

    if (message.is_private_note) {
        return;
    }

    applyConversationSnapshot({
        id: conversationId,
        latest_message: message,
        last_message_at: message.created_at || null,
        updated_at: message.created_at || null,
    }, {
        moveToTop: options.moveToTop !== false,
    });
}

function extractMessagePayload(responseData) {
    const direct = responseData?.data;
    if (direct && typeof direct === 'object' && direct.id) {
        return direct;
    }

    const nested = responseData?.data?.data;
    if (nested && typeof nested === 'object' && nested.id) {
        return nested;
    }

    return null;
}

function extractConversationPayload(responseData) {
    const direct = responseData?.conversation;
    if (direct && typeof direct === 'object' && direct.id) {
        return direct;
    }

    const nested = responseData?.conversation?.data;
    if (nested && typeof nested === 'object' && nested.id) {
        return nested;
    }

    return null;
}

function getAttachmentOpenUrl(attachment) {
    return attachment?.download_url || attachment?.url || '';
}

function isImageAttachment(attachment) {
    if (attachment?.media_kind) {
        return attachment.media_kind === 'image';
    }

    if (attachment?.is_image === true) {
        return true;
    }

    return String(attachment?.mime_type || '').toLowerCase().startsWith('image/');
}

function isAudioAttachment(attachment) {
    if (attachment?.media_kind) {
        return attachment.media_kind === 'audio';
    }

    if (attachment?.is_audio === true || attachment?.is_voice_clip === true) {
        return true;
    }

    const mime = String(attachment?.mime_type || '').toLowerCase();
    if (!mime) {
        return false;
    }

    if (mime.startsWith('audio/')) {
        return true;
    }

    if (mime.startsWith('video/')) {
        const name = String(attachment?.name || '').trim().toLowerCase();
        return name.startsWith('voice-') || name.startsWith('audio-') || name.startsWith('recording-');
    }

    return false;
}

function isVideoAttachment(attachment) {
    if (attachment?.media_kind) {
        return attachment.media_kind === 'video';
    }

    if (attachment?.is_video === true) {
        return true;
    }

    const mime = String(attachment?.mime_type || '').toLowerCase();
    return mime.startsWith('video/') && !isAudioAttachment(attachment);
}

function getAttachmentTypeLabel(attachment) {
    if (isImageAttachment(attachment)) {
        return 'Image';
    }

    if (isVideoAttachment(attachment)) {
        return 'Video';
    }

    if (isAudioAttachment(attachment)) {
        return 'Audio';
    }

    return 'File';
}

function formatAttachmentSize(bytes) {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) {
        return '0 B';
    }
    if (value < 1024) {
        return `${value} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    const index = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length);
    return `${parseFloat((value / Math.pow(1024, index)).toFixed(1))} ${units[index - 1]}`;
}

function openAttachmentFromDetails(attachment) {
    const targetUrl = getAttachmentOpenUrl(attachment);
    if (!targetUrl) {
        return;
    }

    if (isImageAttachment(attachment) || isVideoAttachment(attachment)) {
        const media = conversationAttachmentItems.value
            .filter((item) => isImageAttachment(item) || isVideoAttachment(item))
            .map((item) => ({
                src: item.url || item.download_url,
                download: item.download_url || item.url,
                id: item.id || item._key,
                type: isVideoAttachment(item) ? 'video' : 'image',
                mimeType: item.mime_type,
            }));

        const targetId = attachment.id || attachment._key;
        const index = media.findIndex((item) => item.id === targetId);
        window.dispatchEvent(new CustomEvent('media-viewer:open', {
            detail: {
                media,
                index: index >= 0 ? index : 0,
            },
        }));
        return;
    }

    window.open(targetUrl, '_blank', 'noopener,noreferrer');
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

    if (isComposerCollapsed.value) {
        isComposerCollapsed.value = false;
    }
    showEmojiPicker.value = !showEmojiPicker.value;
}

function toggleComposerCollapsed() {
    isComposerCollapsed.value = !isComposerCollapsed.value;
    if (isComposerCollapsed.value) {
        showEmojiPicker.value = false;
        return;
    }

    void focusComposerTextarea();
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
    if (status === 'wrap_up') {
        return 'close';
    }

    return (status || 'open').replace(/_/g, ' ');
}

function getStatusClasses(status) {
    switch (status) {
        case 'waiting_human':
            return 'text-amber-500';
        case 'wrap_up':
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
        case 'wrap_up':
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
    if (status === 'wrap_up') {
        return 'warning';
    }
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
    applyUiTimerMeta(meta);
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

    patchConversationFromMessage(event?.conversation_id, incoming, { moveToTop: true });

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
                    await loadConversations({ silent: true, syncActiveConversation: false });
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
    const silent = options.silent === true;
    const syncActiveConversation = options.syncActiveConversation !== false;
    if (append && !canLoadMoreConversations.value) {
        return;
    }

    if (append) {
        if (isLoadingMoreConversations.value || isLoadingList.value || isRefreshingListSilently.value) {
            return;
        }
        isLoadingMoreConversations.value = true;
    } else {
        if (isLoadingList.value || isRefreshingListSilently.value) {
            return;
        }
        if (silent) {
            isRefreshingListSilently.value = true;
        } else {
            isLoadingList.value = true;
        }
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

        const requestedConversationHandled = !append
            ? await syncConversationFromRoute({
                loadMessages: true,
                withLoading: !silent,
            })
            : false;

        if (!append) {
            if (!requestedConversationHandled) {
                const existing = conversations.value.find((chat) => chat.id === activeConversationId.value);
                if (!existing) {
                    activeConversationId.value = conversations.value[0]?.id || null;
                }

                if (activeConversationId.value) {
                    const shouldLoadMessages = !activeConversation.value
                        || activeConversation.value.id !== activeConversationId.value
                        || conversationMessages.value.length === 0;
                    const shouldSyncSelectedConversation = syncActiveConversation
                        || shouldLoadMessages
                        || !activeConversation.value
                        || activeConversation.value.id !== activeConversationId.value;

                    if (shouldSyncSelectedConversation) {
                        await fetchConversation(activeConversationId.value, {
                            loadMessages: shouldLoadMessages,
                            withLoading: !silent && shouldLoadMessages,
                        });
                    } else {
                        await subscribeSupportRealtime();
                    }
                } else {
                    activeConversation.value = null;
                    conversationMessages.value = [];
                    clearCustomerTypingIndicator();
                    clearUnreadMarker();
                    await subscribeSupportRealtime();
                }
            } else {
                await subscribeSupportRealtime();
            }
        } else {
            await subscribeSupportRealtime();
        }
    } catch (error) {
        supportLogger.error('inbox.fetch.failure', 'Failed to load support inbox conversations.', summarizeError(error));
        console.error('Failed to load support inbox:', error);
        if (!silent) {
            toast.error('Error', error.response?.data?.message || 'Failed to load support inbox.');
        }
    } finally {
        if (append) {
            isLoadingMoreConversations.value = false;
        } else {
            if (silent) {
                isRefreshingListSilently.value = false;
            } else {
                isLoadingList.value = false;
            }
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

async function syncConversationFromRoute(options = {}) {
    const requestedConversationId = readRequestedConversationId();
    if (!requestedConversationId) {
        return false;
    }

    if (
        requestedConversationId === activeConversationId.value
        && activeConversation.value?.id === requestedConversationId
    ) {
        return true;
    }

    activeConversationId.value = requestedConversationId;

    try {
        await fetchConversation(requestedConversationId, {
            loadMessages: options.loadMessages !== false,
            withLoading: options.withLoading !== false,
        });
    } catch {
        return false;
    }

    return activeConversation.value?.id === requestedConversationId;
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

    const shouldLoadMessages = options.loadMessages !== false;
    const withLoading = options.withLoading !== false && shouldLoadMessages;

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
        if (activeConversation.value?.id) {
            applyConversationSnapshot(activeConversation.value);
        }
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

async function handleJumpToLatestClick() {
    if (unreadMessageCount.value > 0) {
        await jumpToFirstUnread();
        return;
    }

    jumpToLatest();
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
        const responseData = response.data || {};
        applyRealtimeMeta(responseData?.meta);
        const confirmedMessage = extractMessagePayload(responseData);
        const conversationPayload = extractConversationPayload(responseData);

        if (conversationPayload) {
            applyConversationSnapshot(conversationPayload, { moveToTop: true });
        }

        removePendingMessage(messageId);

        if (activeConversationId.value === pending.conversationId) {
            const wasNearBottom = isNearBottom();

            if (confirmedMessage?.id) {
                const existingIndex = conversationMessages.value.findIndex((message) => message.id === confirmedMessage.id);
                if (existingIndex === -1) {
                    conversationMessages.value = [...conversationMessages.value, confirmedMessage]
                        .sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime());
                } else {
                    conversationMessages.value[existingIndex] = {
                        ...conversationMessages.value[existingIndex],
                        ...confirmedMessage,
                    };
                }

                patchConversationFromMessage(pending.conversationId, confirmedMessage, { moveToTop: true });
            } else {
                await loadConversationMessages(pending.conversationId, { mergeLatest: true, wasNearBottom, trackUnread: false });
            }

            if (wasNearBottom) {
                await scrollToLatest(false);
            }
        }

        if (!conversationPayload && !confirmedMessage) {
            await loadConversations({ silent: true, syncActiveConversation: false });
        }
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

    if (isComposerCollapsed.value) {
        isComposerCollapsed.value = false;
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
        await focusComposerTextarea();

        if (shouldStickToBottom) {
            await scrollToLatest(false);
        }

        await transmitPendingMessage(pending.id, { showErrorToast: true });
    } catch {
        toast.error('Error', 'Failed to queue message.');
    } finally {
        isSending.value = false;
        await focusComposerTextarea();
    }
}

async function assignConversation(agentPublicId = selectedAgentId.value) {
    const resolvedAgentId = typeof agentPublicId === 'string'
        ? agentPublicId
        : selectedAgentId.value;
    const normalizedAgentId = String(resolvedAgentId || '').trim();

    if (!activeConversationId.value || !normalizedAgentId || isAssigning.value || !canAssignActiveConversation.value) {
        supportLogger.debug('conversation.assign.skip', 'Skipping assignment due to invalid state.', {
            conversation_id: activeConversationId.value,
            agent_public_id: normalizedAgentId || null,
            is_assigning: isAssigning.value,
            can_assign: canAssignActiveConversation.value,
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

    if (!canAssignActiveConversation.value) {
        toast.error('Unavailable', 'Closed or close-out conversations cannot be reassigned.');
        return;
    }

    selectedAgentId.value = authStore.user.public_id;
    await assignConversation(authStore.user.public_id);
}

async function endConversation() {
    if (!activeConversationId.value || isEnding.value || !canEndActiveConversation.value) {
        return;
    }

    isEnding.value = true;

    try {
        supportLogger.info('conversation.end.start', 'Ending support conversation from inbox.', {
            conversation_id: activeConversationId.value,
        });
        const response = await api.post(`/api/support/chats/agent/${activeConversationId.value}/end`);
        applyRealtimeMeta(response.data?.meta);
        toast.success('Close started', 'Conversation moved to close-out.');
        await fetchConversation(activeConversationId.value);
        await loadConversations();
        supportLogger.info('conversation.end.success', 'Support conversation ended from inbox.', {
            conversation_id: activeConversationId.value,
        });
    } catch (error) {
        supportLogger.error('conversation.end.failure', 'Failed to end support conversation from inbox.', summarizeError(error));
        console.error('Failed to end support conversation:', error);
        toast.error('Error', error.response?.data?.message || 'Failed to end conversation.');
    } finally {
        isEnding.value = false;
    }
}

async function completeCloseConversation() {
    if (!activeConversationId.value || isResolving.value || activeConversationStatus.value !== 'wrap_up') {
        return;
    }

    isResolving.value = true;

    try {
        supportLogger.info('conversation.close.start', 'Finalizing support close-out.', {
            conversation_id: activeConversationId.value,
            resolved: closeResolved.value,
        });
        const response = await api.post(`/api/support/chats/${activeConversationId.value}/wrap-up/complete`, {
            resolved: closeResolved.value,
        });
        applyRealtimeMeta(response.data?.meta);
        toast.success('Closed', closeResolved.value ? 'Conversation closed as resolved.' : 'Conversation closed as unresolved.');
        await fetchConversation(activeConversationId.value);
        await loadConversations();
        supportLogger.info('conversation.close.success', 'Support close-out finalized.', {
            conversation_id: activeConversationId.value,
            resolved: closeResolved.value,
        });
    } catch (error) {
        supportLogger.error('conversation.close.failure', 'Failed to finalize support close-out.', summarizeError(error));
        console.error('Failed to finalize support close-out:', error);
        toast.error('Error', error.response?.data?.message || 'Failed to close conversation.');
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
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(SUPPORT_INBOX_SCOPE_STORAGE_KEY, normalizeInboxScope(activeTab.value));
    }
    await loadConversations();
});

watch(leftSidebarWidth, (value) => {
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(SUPPORT_INBOX_LEFT_WIDTH_STORAGE_KEY, String(Math.round(value)));
    }
});

watch(rightSidebarWidth, (value) => {
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(SUPPORT_INBOX_RIGHT_WIDTH_STORAGE_KEY, String(Math.round(value)));
    }
});

watch(searchQuery, () => {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }

    searchDebounceTimer = setTimeout(async () => {
        await loadConversations();
    }, 350);
});

watch(activeConversationId, () => {
    detailsSidebarTab.value = 'overview';
});

watch(
    () => [route.query.scope, route.query.conversation],
    async () => {
        const requestedScope = readRequestedInboxScope();
        if (requestedScope && requestedScope !== activeTab.value) {
            activeTab.value = requestedScope;
            return;
        }

        const requestedConversationId = readRequestedConversationId();
        if (!requestedConversationId) {
            return;
        }

        if (
            requestedConversationId === activeConversationId.value
            && activeConversation.value?.id === requestedConversationId
        ) {
            return;
        }

        const existing = conversations.value.find((chat) => chat.id === requestedConversationId);
        if (existing) {
            await selectConversation(requestedConversationId);
            return;
        }

        await syncConversationFromRoute({
            loadMessages: true,
            withLoading: true,
        });
    },
);

watch(
    () => activeConversation.value?.resolution_marker,
    (value) => {
        closeResolved.value = String(value || '').toLowerCase() === 'resolved';
    },
    { immediate: true },
);

onMounted(async () => {
    supportLogger.info('lifecycle.mounted', 'Support inbox view mounted.');
    startLiveCounterTicker();
    leftSidebarWidth.value = clampLeftSidebarWidth(leftSidebarWidth.value);
    rightSidebarWidth.value = clampRightSidebarWidth(rightSidebarWidth.value);
    const requestedScope = readRequestedInboxScope();
    if (requestedScope) {
        activeTab.value = requestedScope;
    }
    await Promise.all([refreshRealtimeToken(), loadAgents(), loadConversations()]);
    window.addEventListener('echo:connected', handleEchoConnected);
    window.addEventListener('pointerdown', handleGlobalPointerDown);
    window.addEventListener('resize', handleSupportLayoutResize);

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

    stopLiveCounterTicker();

    clearCustomerTypingIndicator();
    clearComposerFiles();
    clearRealtimeSubscription();
    stopSidebarResize();
    window.removeEventListener('echo:connected', handleEchoConnected);
    window.removeEventListener('pointerdown', handleGlobalPointerDown);
    window.removeEventListener('resize', handleSupportLayoutResize);
});
</script>

<template>
    <div
        ref="supportLayoutRef"
        class="flex-1 w-full bg-[var(--surface-primary)] flex overflow-hidden border-t border-[var(--border-default)]"
        :class="{ 'support-resize-active': isAnySidebarResizing }"
    >
        <!-- Left Sidebar: Conversations List -->
        <div
            class="support-inbox-sidebar support-inbox-sidebar-left border-r border-[var(--border-default)] bg-[var(--surface-secondary)]/30 flex flex-col"
            :style="leftSidebarStyle"
        >
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
        <div
            class="support-sidebar-resize-handle support-sidebar-resize-handle--left"
            :class="{ 'is-active': activeSidebarResize === 'left' }"
            title="Resize conversation list (double-click to reset)"
            @pointerdown="startSidebarResize('left', $event)"
            @dblclick.prevent="handleSidebarResizeDoubleClick('left')"
        >
            <span class="support-sidebar-grip" aria-hidden="true">
                <span class="support-sidebar-grip-dot"></span>
                <span class="support-sidebar-grip-dot"></span>
                <span class="support-sidebar-grip-dot"></span>
                <span class="support-sidebar-grip-dot"></span>
            </span>
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
                <div class="px-3 py-2 border-b border-[var(--border-default)] bg-[var(--surface-primary)]/90 backdrop-blur-sm z-10 sticky top-0">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-[var(--text-secondary)]">
                                <span>
                                    Duration
                                    <span class="font-semibold text-[var(--text-primary)]">{{ conversationDurationCounter }}</span>
                                </span>
                                <span class="text-[var(--text-muted)]">•</span>
                                <span>
                                    Response gap
                                    <span
                                        class="font-semibold"
                                        :class="lastSupportReplyCounterToneClass"
                                    >
                                        {{ lastSupportReplyCounter }}
                                    </span>
                                </span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[10px] text-[var(--text-muted)]">
                                <span class="truncate">Started: {{ conversationStartedSummary.exact }}</span>
                                <span>•</span>
                                <span class="truncate">Last reply: {{ latestSupportReplySummary.exact || 'Waiting for first support response' }}</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-1.5 shrink-0">
                            <Dropdown>
                                <template #trigger>
                                    <Button variant="outline" size="sm" class="h-8 text-xs px-2.5" :disabled="isResolving || isEnding">
                                        <MoreVertical class="h-3.5 w-3.5" />
                                        Actions
                                    </Button>
                                </template>
                                <DropdownItem @select="refreshCurrentConversation">Refresh conversation</DropdownItem>
                                <DropdownItem v-if="canAssignActiveConversation && !isAssignedToCurrentUser" :disabled="isAssigning" @select="assignToCurrentUser">
                                    {{ isAssigning ? 'Assigning...' : 'Assign to me' }}
                                </DropdownItem>
                                <DropdownItem v-else-if="isAssignedToCurrentUser" disabled>Already assigned to you</DropdownItem>
                                <DropdownItem v-else disabled>Assignment unavailable in read-only state</DropdownItem>
                                <DropdownSeparator />
                                <DropdownItem v-if="canEndActiveConversation" :disabled="isEnding" destructive @select="endConversation">
                                    {{ isEnding ? 'Ending...' : 'End conversation' }}
                                </DropdownItem>
                                <DropdownItem v-else disabled>{{ endActionLabel }}</DropdownItem>
                            </Dropdown>
                        </div>
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
                                <div
                                    v-if="messageContentForDisplay(message)"
                                    class="support-rich-content text-sm break-words"
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
                                    />
                                </div>
                                <span class="text-[10px] text-[var(--text-muted)] mt-1 ml-1">{{ message.time }}</span>
                            </div>
                        </div>

                        <div v-else class="flex flex-col items-end mb-2">
                            <div class="max-w-[75%] flex flex-col items-end">
                                <div class="bg-[var(--interactive-primary)] text-white px-4 py-2.5 rounded-2xl rounded-br-sm shadow-md border border-black/5 dark:border-white/5 backdrop-blur-sm text-sm">
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
                                    />
                                </div>
                                <span class="text-[10px] text-[var(--text-muted)] mt-1 mr-1 text-right">{{ message.time }} • {{ message.agentName }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-if="showJumpToLatest" class="sticky bottom-3 z-20 flex justify-center pointer-events-none">
                        <button
                            type="button"
                            class="pointer-events-auto relative inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-900 bg-slate-900 text-white shadow-sm transition-colors hover:bg-slate-800 dark:border-slate-200 dark:bg-slate-200 dark:text-slate-900 dark:hover:bg-slate-300"
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

                    <div
                        v-for="pending in pendingMessagesForActiveConversation"
                        :key="pending.id"
                        class="flex flex-col items-end mb-2"
                    >
                        <div
                            v-if="pending.isPrivateNote"
                            class="max-w-[75%] bg-amber-500/10 border border-amber-500/25 text-amber-700 dark:text-amber-400 px-4 py-2.5 rounded-2xl rounded-br-sm text-sm"
                        >
                            <div
                                v-if="pending.body"
                                class="support-rich-content break-words"
                                v-html="pendingMessageContentHtml(pending)"
                            ></div>
                            <p v-if="pending.fileNames?.length" class="mt-1 text-[11px]">
                                {{ pending.fileNames.length }} attachment<span v-if="pending.fileNames.length > 1">s</span>
                            </p>
                        </div>
                        <div
                            v-else
                            class="max-w-[75%] bg-[var(--interactive-primary)] text-white px-4 py-2.5 rounded-2xl rounded-br-sm shadow-md border border-black/5 dark:border-white/5 text-sm"
                        >
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
                        <button
                            type="button"
                            class="ml-auto inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)] hover:text-[var(--text-primary)]"
                            :disabled="isSending"
                            @click="toggleComposerCollapsed"
                        >
                            <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="isComposerCollapsed ? '-rotate-90' : 'rotate-0'" />
                            {{ isComposerCollapsed ? 'Expand composer' : 'Collapse composer' }}
                        </button>
                    </div>

                    <div
                        v-if="isComposerCollapsed"
                        class="rounded-xl border border-dashed border-[var(--border-default)] bg-[var(--surface-primary)]/80 px-3 py-2 text-xs text-[var(--text-secondary)]"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span class="truncate">
                                {{ newMessage.trim() ? `Draft: ${newMessage.trim().slice(0, 96)}` : 'Composer collapsed' }}
                            </span>
                            <span v-if="selectedComposerFiles.length > 0" class="shrink-0 text-[var(--text-muted)]">
                                {{ selectedComposerFiles.length }} file<span v-if="selectedComposerFiles.length !== 1">s</span>
                            </span>
                        </div>
                    </div>

                    <div
                        v-else
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
                        <div class="flex items-center gap-2 border-b border-[var(--border-default)]/50 px-2.5 py-2">
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-9 w-9 p-0 border border-[var(--border-default)]/70 bg-[var(--surface-primary)] text-[var(--text-primary)] hover:bg-black/5 dark:hover:bg-white/10"
                                title="Bold (Ctrl/Cmd+B)"
                                :disabled="composerDisabled || isSending || isSendCoolingDown"
                                @mousedown.prevent
                                @click="applyComposerInlineFormat('**', '**', 'bold text')"
                            >
                                <Bold class="h-[18px] w-[18px]" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-9 w-9 p-0 border border-[var(--border-default)]/70 bg-[var(--surface-primary)] text-[var(--text-primary)] hover:bg-black/5 dark:hover:bg-white/10"
                                title="Underline (Ctrl/Cmd+U)"
                                :disabled="composerDisabled || isSending || isSendCoolingDown"
                                @mousedown.prevent
                                @click="applyComposerInlineFormat('<u>', '</u>', 'underlined text')"
                            >
                                <Underline class="h-[18px] w-[18px]" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-9 w-9 p-0 border border-[var(--border-default)]/70 bg-[var(--surface-primary)] text-[var(--text-primary)] hover:bg-black/5 dark:hover:bg-white/10"
                                title="Bulleted list"
                                :disabled="composerDisabled || isSending || isSendCoolingDown"
                                @mousedown.prevent
                                @click="applyComposerListFormat('bullet')"
                            >
                                <List class="h-[18px] w-[18px]" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-9 w-9 p-0 border border-[var(--border-default)]/70 bg-[var(--surface-primary)] text-[var(--text-primary)] hover:bg-black/5 dark:hover:bg-white/10"
                                title="Numbered list"
                                :disabled="composerDisabled || isSending || isSendCoolingDown"
                                @mousedown.prevent
                                @click="applyComposerListFormat('numbered')"
                            >
                                <ListOrdered class="h-[18px] w-[18px]" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-9 w-9 p-0 border border-[var(--border-default)]/70 bg-[var(--surface-primary)] text-[var(--text-primary)] hover:bg-black/5 dark:hover:bg-white/10"
                                title="Insert link (Ctrl/Cmd+K)"
                                :disabled="composerDisabled || isSending || isSendCoolingDown"
                                @mousedown.prevent
                                @click="insertComposerLink"
                            >
                                <Link2 class="h-[18px] w-[18px]" />
                            </Button>
                        </div>
                        <textarea
                            ref="composerTextareaRef"
                            v-model="newMessage"
                            :placeholder="isNoteMode ? 'Type an internal note (visitors cannot see this)...' : 'Type your message... (Press Shift+Enter for new line)'"
                            class="w-full bg-transparent border-none p-3 text-sm focus:outline-none focus:ring-0 focus:ring-offset-0 focus-visible:ring-0 focus-visible:outline-none resize-none min-h-[80px] max-h-[200px] text-[var(--text-primary)]"
                            :disabled="composerDisabled || isSendCoolingDown"
                            @keydown="handleComposerKeydown"
                            @input="handleComposerInput"
                            @blur="emitTyping(false)"
                        ></textarea>

                        <div class="flex items-center justify-between p-2 border-t border-[var(--border-default)]/50">
                            <div class="flex items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="h-9 w-9 p-0 border border-[var(--border-default)] text-[var(--text-primary)] hover:bg-black/5 dark:hover:bg-white/10"
                                    title="Attach file"
                                    :disabled="composerDisabled || isSending || isSendCoolingDown"
                                    @click="openComposerFilePicker"
                                >
                                    <Paperclip class="h-[17px] w-[17px]" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="h-9 w-9 p-0 border border-[var(--border-default)] text-[var(--text-primary)] hover:bg-black/5 dark:hover:bg-white/10"
                                    title="Insert image"
                                    :disabled="composerDisabled || isSending || isSendCoolingDown"
                                >
                                    <ImageIcon class="h-[17px] w-[17px]" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="h-9 w-9 p-0 border border-[var(--border-default)] text-[var(--text-primary)] hover:bg-black/5 dark:hover:bg-white/10"
                                    title="Insert emoji"
                                    :disabled="composerDisabled || isSending || isSendCoolingDown"
                                    @click="toggleEmojiPicker"
                                >
                                    <Smile class="h-[17px] w-[17px]" />
                                </Button>
                                <div class="h-4 w-px bg-[var(--border-default)] mx-1"></div>
                                <Button variant="ghost" size="sm" class="h-9 px-2.5 text-[12px] font-semibold text-[var(--text-primary)] hover:bg-black/5 dark:hover:bg-white/10" title="Use a saved macro" :disabled="composerDisabled">
                                    <Zap class="h-4 w-4 mr-1.5" /> Macros
                                </Button>
                            </div>
                            <Button
                                :variant="isNoteMode ? 'outline' : 'primary'"
                                size="sm"
                                @click="sendMessage"
                                :disabled="composerDisabled || isSending || isSendCoolingDown || (!newMessage.trim() && selectedComposerFiles.length === 0)"
                                :class="[
                                    'h-9 px-3 text-[13px] font-semibold',
                                    isNoteMode ? 'bg-amber-100 dark:bg-amber-900 border-amber-500/30 text-amber-700 dark:text-amber-300 hover:bg-amber-200 dark:hover:bg-amber-800' : ''
                                ]"
                            >
                                <Loader2 v-if="isSending" class="h-4 w-4 mr-1.5 animate-spin" />
                                <span>{{ isNoteMode ? 'Add Note' : 'Send' }}</span>
                                <Send class="h-4 w-4 ml-1.5" />
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
                    <div
                        v-if="activeConversationStatus === 'wrap_up'"
                        class="mt-2 flex items-center justify-end gap-3 rounded-xl border border-[var(--border-default)]/50 bg-[var(--surface-primary)]/70 px-2 py-2"
                    >
                        <label class="inline-flex items-center gap-2 text-[12px] font-medium text-[var(--text-secondary)]">
                            <input
                                v-model="closeResolved"
                                type="checkbox"
                                class="h-3.5 w-3.5 rounded border-[var(--border-default)] text-emerald-600 focus:ring-emerald-500"
                                :disabled="isResolving"
                            />
                            Resolved
                        </label>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-9 px-3 text-[12px] font-semibold border-emerald-500/40 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-emerald-950/40"
                            :disabled="isResolving"
                            @click="completeCloseConversation"
                        >
                            <Loader2 v-if="isResolving" class="h-4 w-4 mr-1.5 animate-spin" />
                            <Check v-else class="h-4 w-4 mr-1.5" />
                            Close Chat
                        </Button>
                    </div>
            </template>
        </div>

        <!-- Right Sidebar: Visitor Info & Context -->
        <div
            class="support-sidebar-resize-handle support-sidebar-resize-handle--right"
            :class="{ 'is-active': activeSidebarResize === 'right' }"
            title="Resize chat details (double-click to reset)"
            @pointerdown="startSidebarResize('right', $event)"
            @dblclick.prevent="handleSidebarResizeDoubleClick('right')"
        >
            <span class="support-sidebar-grip" aria-hidden="true">
                <span class="support-sidebar-grip-dot"></span>
                <span class="support-sidebar-grip-dot"></span>
                <span class="support-sidebar-grip-dot"></span>
                <span class="support-sidebar-grip-dot"></span>
            </span>
        </div>
        <div
            class="support-inbox-sidebar support-inbox-sidebar-right bg-[var(--surface-secondary)]/30 overflow-y-auto"
            :style="rightSidebarStyle"
        >
            <div v-if="!activeConversationDisplay" class="h-full flex items-center justify-center text-[var(--text-secondary)] text-sm p-4 text-center">
                Customer context will appear here when you select a conversation.
            </div>

            <template v-else>
                <div class="p-3 space-y-3">
                    <section class="rounded-xl border border-[var(--border-default)] bg-[var(--surface-primary)]/60 p-3 space-y-2.5">
                        <div class="rounded-lg border border-[var(--border-default)]/70 bg-[var(--surface-secondary)]/20 px-2.5 py-2.5">
                            <div class="flex items-start gap-2.5">
                                <Avatar
                                    :src="activeConversationDisplay.avatarUrl"
                                    :thumb-url="activeConversationDisplay.avatarThumbUrl"
                                    :alt="activeConversationDisplay.name"
                                    :fallback="getConversationInitials(activeConversation || {})"
                                    :color="activeConversationDisplay.avatarColor || 'var(--surface-tertiary)'"
                                    size="md"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="text-[10px] uppercase tracking-wider text-[var(--text-muted)]">Customer</p>
                                    <h3 class="truncate text-[13px] font-semibold text-[var(--text-primary)] leading-tight mt-0.5">
                                        {{ activeConversationDisplay.name }}
                                    </h3>
                                    <p class="truncate text-[11px] text-[var(--text-secondary)] mt-0.5">{{ activeConversationDisplay.email }}</p>
                                </div>
                            </div>

                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <Badge :variant="statusBadgeVariant(activeConversationDisplay.status)" size="sm" class="capitalize h-5 px-1.5 text-[10px]">
                                    {{ getStatusLabel(activeConversationDisplay.status) }}
                                </Badge>
                                <span class="inline-flex items-center rounded-md border border-[var(--border-default)] bg-[var(--surface-primary)] px-1.5 py-0.5 text-[10px] font-medium text-[var(--text-secondary)]">
                                    {{ assignmentStateLabel }}
                                </span>
                                <span
                                    class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-medium"
                                    :class="isConversationResolvedLike
                                        ? 'border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--text-muted)]'
                                        : 'border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-300'"
                                >
                                    {{ isConversationResolvedLike ? 'Read-only' : 'Active thread' }}
                                </span>
                            </div>
                        </div>

                        <div class="rounded-lg border border-[var(--border-default)]/70 bg-[var(--surface-primary)] px-2.5 py-2">
                            <p class="mb-1.5 text-[10px] uppercase tracking-wider text-[var(--text-muted)]">Assignment</p>
                            <div class="space-y-1.5">
                                <select
                                    v-model="selectedAgentId"
                                    class="h-8 w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] px-2.5 text-[11px] text-[var(--text-primary)]"
                                    :disabled="isLoadingAgents || isAssigning || !canAssignActiveConversation"
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
                                    :disabled="!selectedAgentId || isAssigning || !canAssignActiveConversation"
                                    @click="assignConversation()"
                                >
                                    <Loader2 v-if="isAssigning" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                    Assign Conversation
                                </Button>
                                <p v-if="!canAssignActiveConversation" class="text-[10px] text-[var(--text-muted)]">
                                    Assignment is disabled for closed or close-out conversations.
                                </p>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl border border-[var(--border-default)] bg-[var(--surface-primary)]/50 p-3 text-sm">
                        <div class="rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]/30 p-1">
                            <div class="grid grid-cols-3 gap-1">
                                <button
                                    type="button"
                                    class="h-7 rounded-md text-[10px] font-semibold transition-colors"
                                    :class="detailsSidebarTab === 'overview'
                                        ? 'bg-[var(--surface-primary)] text-[var(--text-primary)] shadow-sm'
                                        : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]'"
                                    @click="detailsSidebarTab = 'overview'"
                                >
                                    Overview
                                </button>
                                <button
                                    type="button"
                                    class="h-7 rounded-md text-[10px] font-semibold transition-colors"
                                    :class="detailsSidebarTab === 'media'
                                        ? 'bg-[var(--surface-primary)] text-[var(--text-primary)] shadow-sm'
                                        : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]'"
                                    @click="detailsSidebarTab = 'media'"
                                >
                                    Media ({{ detailsMediaCount }})
                                </button>
                                <button
                                    type="button"
                                    class="h-7 rounded-md text-[10px] font-semibold transition-colors"
                                    :class="detailsSidebarTab === 'notes'
                                        ? 'bg-[var(--surface-primary)] text-[var(--text-primary)] shadow-sm'
                                        : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]'"
                                    @click="detailsSidebarTab = 'notes'"
                                >
                                    Notes ({{ detailsNotesCount }})
                                </button>
                            </div>
                        </div>

                        <div v-if="detailsSidebarTab === 'overview'" class="mt-3 space-y-1.5">
                            <div class="flex items-start gap-2 rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-1.5">
                                <MapPin class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]" />
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[var(--text-secondary)]">Location</p>
                                    <p class="truncate text-xs font-medium text-[var(--text-primary)]">{{ activeConversationDisplay.location }}</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-2 rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-1.5">
                                <Globe class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]" />
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[var(--text-secondary)]">Browser & OS</p>
                                    <p class="line-clamp-2 text-xs font-medium text-[var(--text-primary)]">{{ activeConversationDisplay.browser }}</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-2 rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-1.5">
                                <Hash class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]" />
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[var(--text-secondary)]">IP Address</p>
                                    <p class="truncate text-xs font-medium text-[var(--text-primary)]">{{ activeConversationDisplay.ip }}</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-2 rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-1.5">
                                <Monitor class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]" />
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[var(--text-secondary)]">Source URL</p>
                                    <p class="line-clamp-2 break-all text-xs font-medium text-[var(--text-primary)]">{{ activeConversationDisplay.pageView }}</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-2 rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-1.5">
                                <Clock class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]" />
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[var(--text-secondary)]">Opened</p>
                                    <p class="truncate text-xs font-medium text-[var(--text-primary)]">{{ formatRelativeTime(activeConversation?.created_at) }}</p>
                                </div>
                            </div>
                            <div class="rounded-lg border border-[var(--border-default)]/50 bg-[var(--surface-secondary)]/20 px-2 py-2">
                                <p class="mb-1.5 text-[10px] uppercase tracking-wider text-[var(--text-secondary)]">Quick Links</p>
                                <div class="space-y-1.5">
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 rounded-md border border-[var(--border-default)] bg-[var(--surface-primary)] px-2 py-1.5 text-left text-[11px] font-semibold text-[var(--text-primary)] transition-colors hover:bg-[var(--surface-tertiary)]"
                                        @click="onQuickLinkFacadeClick('Knowledge base')"
                                    >
                                        <FileText class="h-3.5 w-3.5 text-[var(--interactive-primary)]" />
                                        Knowledge base
                                    </button>
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 rounded-md border border-[var(--border-default)] bg-[var(--surface-primary)] px-2 py-1.5 text-left text-[11px] font-semibold text-[var(--text-primary)] transition-colors hover:bg-[var(--surface-tertiary)]"
                                        @click="onQuickLinkFacadeClick('Ticket dashboard')"
                                    >
                                        <Hash class="h-3.5 w-3.5 text-[var(--interactive-primary)]" />
                                        Ticket dashboard
                                    </button>
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 rounded-md border border-[var(--border-default)] bg-[var(--surface-primary)] px-2 py-1.5 text-left text-[11px] font-semibold text-[var(--text-primary)] transition-colors hover:bg-[var(--surface-tertiary)]"
                                        @click="onQuickLinkFacadeClick('Customer profile')"
                                    >
                                        <User class="h-3.5 w-3.5 text-[var(--interactive-primary)]" />
                                        Customer profile
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="detailsSidebarTab === 'media'" class="mt-3 space-y-2">
                            <div
                                v-if="conversationAttachmentItems.length === 0"
                                class="rounded-lg border border-dashed border-[var(--border-default)] px-3 py-4 text-center"
                            >
                                <ImageIcon class="mx-auto mb-1 h-4 w-4 text-[var(--text-muted)]" />
                                <p class="text-[11px] text-[var(--text-secondary)]">No media attachments yet.</p>
                            </div>
                            <button
                                v-for="attachment in conversationAttachmentItems"
                                :key="attachment._key"
                                type="button"
                                class="flex w-full items-center gap-2 rounded-lg border border-[var(--border-default)]/60 bg-[var(--surface-secondary)]/20 p-2 text-left transition-colors hover:bg-[var(--surface-secondary)]/45"
                                @click="openAttachmentFromDetails(attachment)"
                            >
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-md border border-[var(--border-default)] bg-[var(--surface-primary)]">
                                    <img
                                        v-if="isImageAttachment(attachment) || isVideoAttachment(attachment)"
                                        :src="attachment.thumb_url || attachment.url || attachment.download_url"
                                        :alt="attachment.name || 'Attachment preview'"
                                        class="h-full w-full object-cover"
                                        loading="lazy"
                                        decoding="async"
                                    />
                                    <span
                                        v-else-if="isAudioAttachment(attachment)"
                                        class="text-[10px] font-semibold text-[var(--text-secondary)]"
                                    >
                                        Audio
                                    </span>
                                    <FileText v-else class="h-4 w-4 text-[var(--text-muted)]" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[11px] font-semibold text-[var(--text-primary)]">
                                        {{ attachment.name || `${getAttachmentTypeLabel(attachment)} attachment` }}
                                    </p>
                                    <p class="truncate text-[10px] text-[var(--text-secondary)]">
                                        {{ getAttachmentTypeLabel(attachment) }} • {{ formatAttachmentSize(attachment.size) }}
                                    </p>
                                    <p class="truncate text-[10px] text-[var(--text-muted)]">
                                        {{ attachment._senderName }} • {{ formatRelativeTime(attachment._createdAt) }}
                                    </p>
                                </div>
                            </button>
                        </div>

                        <div v-else class="mt-3 space-y-2">
                            <div
                                v-if="internalNoteItems.length === 0"
                                class="rounded-lg border border-dashed border-[var(--border-default)] px-3 py-4 text-center"
                            >
                                <AlertCircle class="mx-auto mb-1 h-4 w-4 text-[var(--text-muted)]" />
                                <p class="text-[11px] text-[var(--text-secondary)]">No internal notes yet.</p>
                            </div>
                            <article
                                v-for="note in internalNoteItems"
                                :key="note.id"
                                class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-2.5 py-2"
                            >
                                <div class="flex items-center gap-2">
                                    <Avatar
                                        :src="note.sender_avatar_url"
                                        :thumb-url="note.sender_avatar_thumb_url"
                                        :alt="note.sender_name"
                                        :fallback="note.sender_name?.charAt(0) || 'A'"
                                        :color="note.sender_avatar_color || 'var(--surface-tertiary)'"
                                        size="xs"
                                    />
                                    <div class="min-w-0">
                                        <p class="truncate text-[11px] font-semibold text-[var(--text-primary)]">{{ note.sender_name }}</p>
                                        <p class="text-[10px] text-[var(--text-muted)]">{{ formatRelativeTime(note.created_at) }}</p>
                                    </div>
                                </div>
                                <div class="mt-2 break-words text-[11px] text-[var(--text-primary)]">
                                    <div v-if="note.body" class="support-rich-content" v-html="noteContentHtml(note)"></div>
                                    <span v-else>Internal note (no text)</span>
                                </div>
                                <SupportMessageAttachments
                                    v-if="Array.isArray(note.attachments) && note.attachments.length > 0"
                                    class="mt-2"
                                    :attachments="note.attachments"
                                    tone="note"
                                    compact
                                />
                            </article>
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

.support-inbox-sidebar {
    flex-shrink: 0;
    min-width: 0;
}

.support-resize-active {
    user-select: none;
}

.support-sidebar-resize-handle {
    position: relative;
    width: 0;
    flex: 0 0 0;
    overflow: visible;
    cursor: col-resize;
    touch-action: none;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    z-index: 20;
}

.support-sidebar-resize-handle::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 2px;
    background: color-mix(in srgb, var(--border-default) 55%, transparent);
    transition: background-color 120ms ease, opacity 120ms ease;
    opacity: 0.6;
}

.support-sidebar-resize-handle::after {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    left: -6px;
    width: 12px;
}

.support-sidebar-resize-handle:hover::before,
.support-sidebar-resize-handle.is-active::before {
    background: color-mix(in srgb, var(--interactive-primary) 75%, transparent);
    opacity: 1;
}

.support-sidebar-grip {
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    z-index: 1;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 2px;
    padding: 3px 2px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--surface-secondary) 88%, transparent);
    border: 1px solid color-mix(in srgb, var(--border-default) 80%, transparent);
    transition: border-color 120ms ease, background-color 120ms ease;
}

.support-sidebar-grip-dot {
    width: 2px;
    height: 2px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--text-muted) 82%, transparent);
}

.support-sidebar-resize-handle:hover .support-sidebar-grip,
.support-sidebar-resize-handle.is-active .support-sidebar-grip {
    border-color: color-mix(in srgb, var(--interactive-primary) 75%, transparent);
    background: color-mix(in srgb, var(--surface-secondary) 95%, var(--interactive-primary) 5%);
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
    margin: 0.35rem 0;
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

:deep(.support-rich-content pre),
:deep(.support-rich-content code) {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
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
