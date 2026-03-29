<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import data from "@emoji-mart/data";
import { Picker } from "emoji-mart";
import { Avatar, Badge, Button, Card, Dropdown, DropdownItem, DropdownSeparator } from "@/components/ui";
import {
    AlertCircle,
    Bold,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock,
    Globe,
    Hash,
    ImageIcon,
    Link2,
    List,
    ListOrdered,
    Loader2,
    MapPin,
    MessageSquare,
    Monitor,
    MoreVertical,
    Paperclip,
    Send,
    Smile,
    Underline,
    User,
    X,
    Zap,
} from "lucide-vue-next";
import api from "@/lib/api";
import { useToast } from "@/composables/useToast.ts";
import { hasSupportRealtimeToken, setSupportRealtimeToken, startEcho } from "@/echo";
import { createSupportLogger, summarizeError } from "@/utils/supportDebug";
import { playSupportMessageSound, startSupportOfferLoop, stopSupportOfferLoop } from "@/utils/supportSound";
import { renderSupportRichText } from "@/utils/supportRichText";
import SupportMessageAttachments from "@/components/support/SupportMessageAttachments.vue";
import { useAuthStore } from "@/stores/auth";
import SupportTransferModal from "@/components/support/SupportTransferModal.vue";
import SupportStatusSelector from "@/components/support/SupportStatusSelector.vue";

interface SupportUserSummary {
    id: string;
    name: string;
    email: string;
    avatar_url?: string | null;
    avatar_thumb_url?: string | null;
    avatar_color?: string | null;
}

interface SupportMessage {
    id: string;
    sender_type: string;
    is_private_note?: boolean;
    body?: string | null;
    attachments?: SupportAttachment[];
    sender?: SupportUserSummary | null;
    created_at?: string | null;
    updated_at?: string | null;
}

interface SupportConversationListItem {
    id: string;
    status: string;
    chat_state?: string | null;
    resolution_marker?: string | null;
    priority: string;
    created_at: string | null;
    updated_at: string | null;
    ended_at?: string | null;
    closed_at?: string | null;
    assigned_at?: string | null;
    last_message_at?: string | null;
    first_response_at?: string | null;
    guest_name?: string | null;
    guest_email?: string | null;
    requester?: SupportUserSummary | null;
    assignee?: SupportUserSummary | null;
    support_skill?: {
        id: string;
        name: string;
        slug: string;
        department: string | null;
    } | null;
    latest_message?: SupportMessage | null;
    metadata?: Record<string, string | null> | null;
    source_url?: string | null;
}

type WorkbenchDetailTab = "overview" | "media" | "links" | "notes";

interface SupportAttachment {
    id?: string | number | null;
    media_id?: string | number | null;
    name?: string | null;
    size?: number | null;
    mime_type?: string | null;
    media_kind?: string | null;
    url?: string | null;
    download_url?: string | null;
    thumb_url?: string | null;
    is_image?: boolean;
    is_video?: boolean;
    is_audio?: boolean;
    is_voice_clip?: boolean;
}

interface SidebarAttachmentItem extends SupportAttachment {
    _key: string;
    _messageId: string | null;
    _createdAt: string | null;
    _senderName: string;
    _senderType: string;
}

const toast = useToast();
const authStore = useAuthStore();
const supportLogger = createSupportLogger("Workbench");

const REFRESH_INTERVAL_MS = 20_000;
const TOKEN_REFRESH_INTERVAL_MS = 240_000;
const DETAILS_FOCUS_THROTTLE_MS = 220;
const DETAILS_SKELETON_MS = 180;
const MAX_ATTACHABLE_FILES = 10;
const WORKBENCH_HARD_MAX_PANELS = 5;
const isDevMode = import.meta.env.DEV;
const DEFAULT_UI_TIMER_SETTINGS = {
    tick_ms: 1000,
    last_response_warn_minutes: 5,
    last_response_alert_minutes: 15,
    last_response_include_bot: true,
};

const conversations = ref<SupportConversationListItem[]>([]);
const openConversationIds = ref<string[]>([]);
const focusedConversationId = ref<string | null>(null);
const workbenchMaxPanels = ref<number>(WORKBENCH_HARD_MAX_PANELS);
const agentPanelCapacity = ref<number>(WORKBENCH_HARD_MAX_PANELS);
const detailsTab = ref<WorkbenchDetailTab>("overview");
const leftSidebarCollapsed = ref(false);
const isLeftSidebarHovered = ref(false);
let leftSidebarHoverTimeout: ReturnType<typeof setTimeout> | null = null;

const handleLeftSidebarMouseEnter = () => {
    if (!leftSidebarCollapsed.value) return; // Already expanded
    leftSidebarHoverTimeout = setTimeout(() => {
        isLeftSidebarHovered.value = true;
    }, 150);
};

const handleLeftSidebarMouseLeave = () => {
    if (leftSidebarHoverTimeout) {
        clearTimeout(leftSidebarHoverTimeout);
        leftSidebarHoverTimeout = null;
    }
    isLeftSidebarHovered.value = false;
};

const isLeftSidebarExpanded = computed(() => {
    return !leftSidebarCollapsed.value || isLeftSidebarHovered.value;
});
const detailsHydratingConversationId = ref<string | null>(null);
const isLoadingList = ref(false);
const isRefreshingList = ref(false);
const liveClockNow = ref<number>(Date.now());
const uiTimerSettings = ref({ ...DEFAULT_UI_TIMER_SETTINGS });

const messagesByConversation = ref<Record<string, SupportMessage[]>>({});
const loadingMessagesByConversation = ref<Record<string, boolean>>({});
const sendingByConversation = ref<Record<string, boolean>>({});
const acceptingByConversation = ref<Record<string, boolean>>({});
const rejectingByConversation = ref<Record<string, boolean>>({});
const assigningByConversation = ref<Record<string, boolean>>({});
const completingWrapUpByConversation = ref<Record<string, boolean>>({});
const resolvingByConversation = ref<Record<string, boolean>>({});
const endingByConversation = ref<Record<string, boolean>>({});
const closeResolvedByConversation = ref<Record<string, boolean>>({});
const composerByConversation = ref<Record<string, string>>({});
const composerCollapsedByConversation = ref<Record<string, boolean>>({});
const noteModeByConversation = ref<Record<string, boolean>>({});
const unreadByConversation = ref<Record<string, number>>({});
const hasMoreBeforeByConversation = ref<Record<string, boolean>>({});
const oldestMessageIdByConversation = ref<Record<string, string | null>>({});
const loadingOlderMessagesByConversation = ref<Record<string, boolean>>({});
const showJumpToLatestByConversation = ref<Record<string, boolean>>({});
const firstUnreadMessageIdByConversation = ref<Record<string, string | null>>({});
const selectedFilesByConversation = ref<Record<string, File[]>>({});
const openEmojiPickerConversationId = ref<string | null>(null);
const panelMessageContainers: Record<string, HTMLElement | null> = {};
const panelComposerRefs: Record<string, HTMLTextAreaElement | null> = {};
const panelFileInputRefs: Record<string, HTMLInputElement | null> = {};
const panelEmojiMountRefs: Record<string, HTMLElement | null> = {};
const panelEmojiPickerInstances = new Map<string, HTMLElement>();
const pendingAssignments = ref<SupportConversationListItem[]>([]);
const isTransferModalOpen = ref(false);
const transferConversationId = ref<string | null>(null);

let refreshInterval: ReturnType<typeof setInterval> | null = null;
let realtimeTokenRefreshTimer: ReturnType<typeof setInterval> | null = null;
let realtimeSubscriptionRetryTimer: ReturnType<typeof setTimeout> | null = null;
let inboxReloadDebounceTimer: ReturnType<typeof setTimeout> | null = null;
let detailsFocusThrottleTimer: ReturnType<typeof setTimeout> | null = null;
let detailsSkeletonTimer: ReturnType<typeof setTimeout> | null = null;
let pendingFocusedConversationId: string | null = null;
let lastDetailsFocusAt = 0;
let supportRealtimeRetryAttempts = 0;
let supportRealtimeInboxChannelName = "";
const supportRealtimeConversationChannels = new Set<string>();
let supportRealtimeSubscribeInFlight = false;
let supportRealtimeSubscribePending = false;
let supportRealtimeTokenHydratePromise: Promise<boolean> | null = null;
let liveCounterTicker: ReturnType<typeof setInterval> | null = null;

const maxOpenPanels = computed(() =>
    Math.max(
        1,
        Math.min(
            WORKBENCH_HARD_MAX_PANELS,
            Math.min(workbenchMaxPanels.value, agentPanelCapacity.value),
        ),
    ),
);
const isDenseGrid = computed(() => maxOpenPanels.value >= WORKBENCH_HARD_MAX_PANELS);
const detailsSidebarWidthClass = computed(() => (isDenseGrid.value ? "w-[236px]" : "w-[278px]"));
const panelGridScrollerClass = computed(() => "h-full min-w-0 overflow-hidden");

const panelGridClass = computed(() =>
    isDenseGrid.value
        ? "flex h-full min-w-0 gap-2"
        : "flex h-full min-w-0 gap-2",
);

const conversationMap = computed(() => {
    const map = new Map<string, SupportConversationListItem>();
    for (const conversation of conversations.value) {
        if (conversation?.id) {
            map.set(conversation.id, conversation);
        }
    }
    return map;
});

const openConversations = computed(() =>
    openConversationIds.value
        .map((id) => conversationMap.value.get(id) || null)
        .filter((conversation): conversation is SupportConversationListItem => Boolean(conversation)),
);
const activeWorkingConversations = computed(() =>
    conversations.value.filter((conversation) =>
        !["wrap_up", "resolved", "closed", "pending_acceptance"].includes(String(conversation.status || "").toLowerCase()),
    ),
);
const activeWorkingChatsCount = computed(() => activeWorkingConversations.value.length);
const oldestWorkingChatStartedAt = computed(() => {
    const startedAt = activeWorkingConversations.value
        .map((conversation) => conversation.first_response_at || conversation.created_at)
        .filter(Boolean)
        .map((value) => new Date(String(value)))
        .filter((value) => !Number.isNaN(value.getTime()))
        .sort((a, b) => a.getTime() - b.getTime());

    return startedAt[0]?.toISOString() || null;
});

const focusedConversation = computed<SupportConversationListItem | null>(() => {
    if (focusedConversationId.value) {
        const hit = conversationMap.value.get(focusedConversationId.value);
        if (hit) {
            return hit;
        }
    }

    return openConversations.value[0] || null;
});

const focusedConversationMessages = computed<SupportMessage[]>(() => {
    const id = focusedConversation.value?.id;
    return id ? panelMessages(id) : [];
});

const focusedConversationNoteItems = computed<SupportMessage[]>(() =>
    [...focusedConversationMessages.value]
        .filter((message) => String(message?.sender_type || "").toLowerCase() === "agent" && Boolean(message?.is_private_note))
        .sort((a, b) => new Date(b?.created_at || 0).getTime() - new Date(a?.created_at || 0).getTime()),
);

const focusedConversationAttachmentItems = computed<SidebarAttachmentItem[]>(() => {
    const flattened: SidebarAttachmentItem[] = [];
    for (const message of focusedConversationMessages.value) {
        const attachments = Array.isArray(message?.attachments) ? message.attachments : [];
        for (const [index, attachment] of attachments.entries()) {
            if (!attachment || (!attachment.url && !attachment.download_url)) {
                continue;
            }

            flattened.push({
                ...attachment,
                _key: `${message?.id || "message"}-${attachment?.id || attachment?.media_id || index}`,
                _messageId: message?.id || null,
                _createdAt: message?.created_at || null,
                _senderName: senderLabel(message),
                _senderType: message?.sender_type || "agent",
            });
        }
    }

    return flattened.sort((a, b) => {
        const timeA = new Date(a?._createdAt || 0).getTime();
        const timeB = new Date(b?._createdAt || 0).getTime();
        return timeB - timeA;
    });
});

const focusedConversationDisplay = computed(() => {
    const conversation = focusedConversation.value;
    if (!conversation) {
        return null;
    }

    const metadata = conversation.metadata || {};
    return {
        id: conversation.id,
        name: customerName(conversation),
        email: conversation.requester?.email || conversation.guest_email || "No email provided",
        avatar_url: conversation.requester?.avatar_url || null,
        avatar_thumb_url: conversation.requester?.avatar_thumb_url || null,
        avatar_color: conversation.requester?.avatar_color || null,
        location: String(metadata.location || "Unknown"),
        browser: String(metadata.browser || metadata.user_agent || "Unknown"),
        ip: String(metadata.ip || "Unknown"),
        page: conversation.source_url || String(metadata.page || "Unknown"),
        status: String(conversation.status || "open"),
        skill: conversation.support_skill?.name || "General",
        created_at: conversation.created_at || null,
    };
});

const showDetailsSkeleton = computed(() => {
    const id = focusedConversationId.value;
    if (!id) {
        return false;
    }

    return (
        detailsHydratingConversationId.value === id ||
        Boolean(loadingMessagesByConversation.value[id])
    );
});

function formatTime(value: string | null | undefined): string {
    if (!value) {
        return "Unknown";
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return "Unknown";
    }

    return new Intl.DateTimeFormat(undefined, {
        hour: "numeric",
        minute: "2-digit",
    }).format(date);
}

function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return "Unknown time";
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return "Unknown time";
    }

    return new Intl.DateTimeFormat(undefined, {
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
    }).format(date);
}

function customerName(conversation: SupportConversationListItem): string {
    return (
        conversation.requester?.name ||
        conversation.guest_name ||
        conversation.guest_email ||
        `Guest #${conversation.id.slice(-6)}`
    );
}

function setFocusedConversation(conversationId: string | null): void {
    if (!conversationId) {
        focusedConversationId.value = null;
        return;
    }

    focusedConversationId.value = conversationId;
}

function markDetailsHydrating(conversationId: string): void {
    if (detailsSkeletonTimer) {
        clearTimeout(detailsSkeletonTimer);
    }

    detailsHydratingConversationId.value = conversationId;
    detailsSkeletonTimer = setTimeout(() => {
        if (detailsHydratingConversationId.value === conversationId) {
            detailsHydratingConversationId.value = null;
        }
        detailsSkeletonTimer = null;
    }, DETAILS_SKELETON_MS);
}

function applyFocusedConversation(conversationId: string): void {
    setFocusedConversation(conversationId);
    markDetailsHydrating(conversationId);
}

function focusDetailsFromComposer(conversationId: string): void {
    if (!conversationId) {
        return;
    }

    pendingFocusedConversationId = conversationId;
    const elapsed = Date.now() - lastDetailsFocusAt;
    if (!detailsFocusThrottleTimer && elapsed >= DETAILS_FOCUS_THROTTLE_MS) {
        lastDetailsFocusAt = Date.now();
        applyFocusedConversation(conversationId);
        pendingFocusedConversationId = null;
        return;
    }

    if (detailsFocusThrottleTimer) {
        return;
    }

    const waitMs = Math.max(DETAILS_FOCUS_THROTTLE_MS - elapsed, 60);
    detailsFocusThrottleTimer = setTimeout(() => {
        detailsFocusThrottleTimer = null;
        const nextConversationId = pendingFocusedConversationId;
        pendingFocusedConversationId = null;
        if (!nextConversationId) {
            return;
        }

        lastDetailsFocusAt = Date.now();
        applyFocusedConversation(nextConversationId);
    }, waitMs);
}

function isFocusedConversation(conversationId: string): boolean {
    return focusedConversation.value?.id === conversationId;
}

function formatRelativeTime(value: string | null | undefined): string {
    if (!value) {
        return "Now";
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return "Now";
    }

    const diffSeconds = Math.floor((liveClockNow.value - date.getTime()) / 1000);
    if (diffSeconds < 60) {
        return "Just now";
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

    return new Intl.DateTimeFormat(undefined, {
        month: "short",
        day: "numeric",
    }).format(date);
}

function formatDurationCounter(totalSeconds: number): string {
    const safeSeconds = Math.max(0, Math.floor(Number(totalSeconds) || 0));
    const hours = Math.floor(safeSeconds / 3600);
    const minutes = Math.floor((safeSeconds % 3600) / 60);
    const seconds = safeSeconds % 60;

    if (hours > 0) {
        return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
    }

    return `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
}

function formatElapsedCounter(totalSeconds: number): string {
    const safeSeconds = Math.max(0, Math.floor(Number(totalSeconds) || 0));
    const hours = Math.floor(safeSeconds / 3600);
    const minutes = Math.floor((safeSeconds % 3600) / 60);
    const seconds = safeSeconds % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m ${seconds}s`;
    }

    return `${Math.floor(safeSeconds / 60)}m ${seconds}s`;
}

function normalizeUiTimerMeta(meta: any): {
    tick_ms: number;
    last_response_warn_minutes: number;
    last_response_alert_minutes: number;
    last_response_include_bot: boolean;
} {
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

function startLiveCounterTicker(): void {
    if (liveCounterTicker) {
        clearInterval(liveCounterTicker);
        liveCounterTicker = null;
    }

    const tickMs = Math.max(250, Number(uiTimerSettings.value.tick_ms || DEFAULT_UI_TIMER_SETTINGS.tick_ms));
    liveCounterTicker = setInterval(() => {
        liveClockNow.value = Date.now();
    }, tickMs);
}

function stopLiveCounterTicker(): void {
    if (liveCounterTicker) {
        clearInterval(liveCounterTicker);
        liveCounterTicker = null;
    }
}

function applyUiTimerMeta(meta: any): void {
    const next = normalizeUiTimerMeta(meta);
    const didTickMsChange = next.tick_ms !== uiTimerSettings.value.tick_ms;
    uiTimerSettings.value = next;

    if (didTickMsChange) {
        startLiveCounterTicker();
    }
}

function normalizeWorkbenchPanelLimit(value: unknown, fallback = WORKBENCH_HARD_MAX_PANELS): number {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) {
        return Math.max(1, Math.min(WORKBENCH_HARD_MAX_PANELS, fallback));
    }

    return Math.max(1, Math.min(WORKBENCH_HARD_MAX_PANELS, Math.floor(parsed)));
}

function applyWorkbenchMeta(meta: any): void {
    const workbenchMeta = meta?.workbench || {};
    const nextMaxPanels = normalizeWorkbenchPanelLimit(workbenchMeta.max_panels, workbenchMaxPanels.value);
    workbenchMaxPanels.value = nextMaxPanels;

    const nextAgentCapacity = normalizeWorkbenchPanelLimit(
        workbenchMeta.agent_capacity ?? workbenchMeta.effective_panel_limit,
        agentPanelCapacity.value,
    );
    agentPanelCapacity.value = Math.min(nextMaxPanels, nextAgentCapacity);
}

function messageHtml(message: SupportMessage): string {
    return renderSupportRichText(message?.body || "");
}

function messageHasBody(message: SupportMessage): boolean {
    return String(message?.body || "").trim().length > 0;
}

function panelMessages(conversationId: string): SupportMessage[] {
    return messagesByConversation.value[conversationId] || [];
}

function panelVisibleMessages(conversationId: string): SupportMessage[] {
    return panelMessages(conversationId).filter((message) => !message?.is_private_note);
}

function panelUnreadCount(conversationId: string): number {
    return Number(unreadByConversation.value[conversationId] || 0);
}

function hasMoreMessagesBefore(conversationId: string): boolean {
    return Boolean(hasMoreBeforeByConversation.value[conversationId]);
}

function oldestMessageId(conversationId: string): string | null {
    const value = oldestMessageIdByConversation.value[conversationId];
    if (typeof value === "string" && value.trim() !== "") {
        return value;
    }

    return null;
}

function isLoadingOlderMessages(conversationId: string): boolean {
    return Boolean(loadingOlderMessagesByConversation.value[conversationId]);
}

function shouldShowPanelJumpToLatest(conversationId: string): boolean {
    return Boolean(showJumpToLatestByConversation.value[conversationId]);
}

function setPanelMessagesRef(conversationId: string, element: Element | null): void {
    const nextElement = (element as HTMLElement | null) || null;
    if (panelMessageContainers[conversationId] === nextElement) {
        return;
    }

    panelMessageContainers[conversationId] = nextElement;
}

function setLoadingMessages(conversationId: string, value: boolean): void {
    loadingMessagesByConversation.value = {
        ...loadingMessagesByConversation.value,
        [conversationId]: value,
    };
}

function setLoadingOlderMessages(conversationId: string, value: boolean): void {
    loadingOlderMessagesByConversation.value = {
        ...loadingOlderMessagesByConversation.value,
        [conversationId]: value,
    };
}

function setHasMoreBefore(conversationId: string, value: boolean): void {
    hasMoreBeforeByConversation.value = {
        ...hasMoreBeforeByConversation.value,
        [conversationId]: value,
    };
}

function setOldestMessageId(conversationId: string, value: string | null): void {
    oldestMessageIdByConversation.value = {
        ...oldestMessageIdByConversation.value,
        [conversationId]: value,
    };
}

function setPanelJumpToLatestVisible(conversationId: string, value: boolean): void {
    showJumpToLatestByConversation.value = {
        ...showJumpToLatestByConversation.value,
        [conversationId]: value,
    };
}

function setFirstUnreadMessageId(conversationId: string, value: string | null): void {
    firstUnreadMessageIdByConversation.value = {
        ...firstUnreadMessageIdByConversation.value,
        [conversationId]: value,
    };
}

function setSending(conversationId: string, value: boolean): void {
    sendingByConversation.value = {
        ...sendingByConversation.value,
        [conversationId]: value,
    };
}

function setAssigning(conversationId: string, value: boolean): void {
    assigningByConversation.value = {
        ...assigningByConversation.value,
        [conversationId]: value,
    };
}

function setResolving(conversationId: string, value: boolean): void {
    resolvingByConversation.value = {
        ...resolvingByConversation.value,
        [conversationId]: value,
    };
}

function setEnding(conversationId: string, value: boolean): void {
    endingByConversation.value = {
        ...endingByConversation.value,
        [conversationId]: value,
    };
}

function setComposerValue(conversationId: string, value: string): void {
    composerByConversation.value = {
        ...composerByConversation.value,
        [conversationId]: value,
    };
}

function isComposerCollapsed(conversationId: string): boolean {
    return Boolean(composerCollapsedByConversation.value[conversationId]);
}

function setComposerCollapsed(conversationId: string, value: boolean): void {
    composerCollapsedByConversation.value = {
        ...composerCollapsedByConversation.value,
        [conversationId]: value,
    };

    if (value && openEmojiPickerConversationId.value === conversationId) {
        openEmojiPickerConversationId.value = null;
        return;
    }

    if (!value) {
        void focusPanelComposer(conversationId);
    }
}

function toggleComposerCollapsed(conversationId: string): void {
    setComposerCollapsed(conversationId, !isComposerCollapsed(conversationId));
}

async function setNoteMode(conversationId: string, enabled: boolean): Promise<void> {
    noteModeByConversation.value = {
        ...noteModeByConversation.value,
        [conversationId]: enabled,
    };

    if (isComposerCollapsed(conversationId)) {
        setComposerCollapsed(conversationId, false);
        return;
    }

    await focusPanelComposer(conversationId);
}

function isNoteMode(conversationId: string): boolean {
    return Boolean(noteModeByConversation.value[conversationId]);
}

function setPanelComposerRef(conversationId: string, element: Element | null): void {
    const nextElement = (element as HTMLTextAreaElement | null) || null;
    if (panelComposerRefs[conversationId] === nextElement) {
        return;
    }

    panelComposerRefs[conversationId] = nextElement;
}

function setPanelFileInputRef(conversationId: string, element: Element | null): void {
    const nextElement = (element as HTMLInputElement | null) || null;
    if (panelFileInputRefs[conversationId] === nextElement) {
        return;
    }

    panelFileInputRefs[conversationId] = nextElement;
}

function setPanelEmojiMountRef(conversationId: string, element: Element | null): void {
    const nextElement = (element as HTMLElement | null) || null;
    if (panelEmojiMountRefs[conversationId] === nextElement) {
        return;
    }

    panelEmojiMountRefs[conversationId] = nextElement;
    if (nextElement && openEmojiPickerConversationId.value === conversationId) {
        mountPanelEmojiPicker(conversationId);
    }
}

async function focusPanelComposer(conversationId: string): Promise<void> {
    await nextTick();
    const input = panelComposerRefs[conversationId];
    if (!input || input.disabled) {
        return;
    }

    input.focus({ preventScroll: true });
    if (typeof input.setSelectionRange === "function") {
        const len = String(composerByConversation.value[conversationId] || "").length;
        try {
            input.setSelectionRange(len, len);
        } catch {
            // Ignore selection API failures for non-text mode environments.
        }
    }
}

function panelSelectedFiles(conversationId: string): File[] {
    const files = selectedFilesByConversation.value[conversationId];
    return Array.isArray(files) ? files : [];
}

function setPanelSelectedFiles(conversationId: string, files: File[]): void {
    selectedFilesByConversation.value = {
        ...selectedFilesByConversation.value,
        [conversationId]: files,
    };
}

function clearPanelSelectedFiles(conversationId: string): void {
    if (!selectedFilesByConversation.value[conversationId]) {
        return;
    }

    const nextFiles = { ...selectedFilesByConversation.value };
    delete nextFiles[conversationId];
    selectedFilesByConversation.value = nextFiles;

    const input = panelFileInputRefs[conversationId];
    if (input) {
        input.value = "";
    }
}

function isConversationClosedLike(conversation: SupportConversationListItem): boolean {
    const normalized = String(conversation?.status || "").toLowerCase();
    const chatState = String(conversation?.chat_state || "").toLowerCase();

    return chatState === "chat_ended" || normalized === "wrap_up" || normalized === "resolved" || normalized === "closed" || normalized === "ended";
}

function shouldShowConversationInWorkbench(conversation: Partial<SupportConversationListItem> | null | undefined): boolean {
    const normalized = String(conversation?.status || "").toLowerCase();

    return normalized !== "resolved" && normalized !== "closed" && normalized !== "ended" && normalized !== "pending_acceptance";
}

function statusToneClass(status: string): string {
    const normalized = String(status || "").toLowerCase();
    if (normalized === "assigned") {
        return "text-emerald-400 border-emerald-500/30 bg-emerald-500/10";
    }

    if (normalized === "waiting_human") {
        return "text-amber-300 border-amber-500/30 bg-amber-500/10";
    }

    if (normalized === "wrap_up") {
        return "text-amber-300 border-amber-500/30 bg-amber-500/10";
    }

    if (normalized === "resolved" || normalized === "closed" || normalized === "ended") {
        return "text-slate-300 border-slate-500/30 bg-slate-500/10";
    }

    return "text-sky-300 border-sky-500/30 bg-sky-500/10";
}

function statusDisplayLabel(status: string): string {
    const normalized = String(status || "open").toLowerCase();
    if (normalized === "wrap_up") {
        return "wrap up";
    }
    if (normalized === "closed") {
        return "closed";
    }

    return normalized.replace(/_/g, " ");
}

function closedConversationNoticeLabel(status: string): string {
    const normalized = String(status || "").toLowerCase();

    if (normalized === "wrap_up") {
        return "This conversation is in wrap-up. Internal notes only.";
    }

    if (normalized === "resolved") {
        return "This conversation is resolved. Internal notes only.";
    }

    if (normalized === "closed" || normalized === "ended") {
        return "This conversation is closed. Internal notes only.";
    }

    return `This conversation is ${statusDisplayLabel(status)}. Internal notes only.`;
}

function statusDotClass(status: string): string {
    const normalized = String(status || "").toLowerCase();
    if (normalized === "waiting_human" || normalized === "pending_acceptance" || normalized === "wrap_up") {
        return "bg-amber-400";
    }

    if (normalized === "bot_active") {
        return "bg-sky-400";
    }

    if (normalized === "resolved" || normalized === "closed" || normalized === "ended") {
        return "bg-slate-400";
    }

    return "bg-emerald-400";
}

function conversationHeaderGradientClass(conversation: SupportConversationListItem): string {
    const normalized = String(conversation?.status || "").toLowerCase();

    if (normalized === "waiting_human" || normalized === "pending_acceptance") {
        return "from-amber-500/16 via-[var(--surface-primary)] to-[var(--surface-primary)]";
    }

    if (normalized === "bot_active") {
        return "from-sky-500/16 via-[var(--surface-primary)] to-[var(--surface-primary)]";
    }

    if (normalized === "wrap_up") {
        return "from-orange-500/16 via-[var(--surface-primary)] to-[var(--surface-primary)]";
    }

    if (normalized === "resolved" || normalized === "closed" || normalized === "ended") {
        return "from-slate-500/16 via-[var(--surface-primary)] to-[var(--surface-primary)]";
    }

    return "from-emerald-500/16 via-[var(--surface-primary)] to-[var(--surface-primary)]";
}

function responseGapBadgeClass(conversationId: string): string {
    if (acwElapsedSeconds(conversationId) !== null) {
        return "border-amber-500/30 bg-amber-500/10 text-amber-500";
    }

    const elapsedSeconds = lastSupportReplyElapsedSeconds(conversationId);
    if (elapsedSeconds === null) {
        return "border-[var(--border-muted)] bg-[var(--surface-secondary)]/80 text-[var(--text-secondary)]";
    }

    const elapsedMinutes = Math.floor(elapsedSeconds / 60);
    const warnAt = Number(uiTimerSettings.value.last_response_warn_minutes || DEFAULT_UI_TIMER_SETTINGS.last_response_warn_minutes);
    const alertAt = Number(uiTimerSettings.value.last_response_alert_minutes || DEFAULT_UI_TIMER_SETTINGS.last_response_alert_minutes);

    if (elapsedMinutes >= alertAt) {
        return "border-rose-500/30 bg-rose-500/10 text-rose-500";
    }

    if (elapsedMinutes >= warnAt) {
        return "border-amber-500/30 bg-amber-500/10 text-amber-500";
    }

    return "border-emerald-500/30 bg-emerald-500/10 text-emerald-500";
}

function shouldShowResolutionBadge(status: string): boolean {
    const normalized = String(status || "").toLowerCase();
    return normalized === "wrap_up" || normalized === "resolved" || normalized === "closed" || normalized === "ended";
}

function isCloseResolved(conversation: SupportConversationListItem): boolean {
    if (typeof closeResolvedByConversation.value[conversation.id] === "boolean") {
        return closeResolvedByConversation.value[conversation.id];
    }

    return String(conversation.resolution_marker || "").toLowerCase() === "resolved";
}

function setCloseResolved(conversationId: string, value: boolean): void {
    closeResolvedByConversation.value = {
        ...closeResolvedByConversation.value,
        [conversationId]: value,
    };
}

function messageRowClass(message: SupportMessage): string {
    const senderType = String(message?.sender_type || "").toLowerCase();

    if (senderType === "system") {
        return "justify-center";
    }

    if (senderType === "customer") {
        return "justify-start";
    }

    return "justify-end";
}

function messageBubbleClass(message: SupportMessage): string {
    const senderType = String(message?.sender_type || "").toLowerCase();

    if (senderType === "system") {
        return "inline-flex w-fit items-center border border-[var(--border-default)]/45 bg-[var(--surface-secondary)]/35 text-[var(--text-muted)] text-[9px] px-2 py-0.5 rounded-full";
    }

    if (senderType === "customer") {
        return "inline-block w-auto max-w-full bg-[var(--surface-elevated)] border border-black/5 dark:border-white/10 text-[var(--text-primary)] text-left rounded-2xl rounded-bl-sm px-2.5 py-1.5 text-[13px] leading-[1.35] shadow-sm";
    }

    if (senderType === "bot") {
        return "inline-block w-auto max-w-full bg-cyan-700/90 text-white text-left rounded-2xl rounded-br-sm px-2.5 py-1.5 text-[13px] leading-[1.35] shadow-sm";
    }

    if (senderType === "agent" && message?.is_private_note) {
        return "inline-block w-auto max-w-full bg-amber-500/10 border border-amber-500/30 text-[var(--text-primary)] text-left rounded-2xl rounded-br-sm px-2.5 py-1.5 text-[13px] leading-[1.35]";
    }

    return "inline-block w-auto max-w-full bg-[var(--interactive-primary)] text-white text-left rounded-2xl rounded-br-sm px-2.5 py-1.5 text-[13px] leading-[1.35] border border-black/5 dark:border-white/10 shadow-sm";
}

function messageMetaClass(message: SupportMessage): string {
    const senderType = String(message?.sender_type || "").toLowerCase();
    if (senderType === "customer") {
        return "text-left";
    }
    if (senderType === "system") {
        return "text-center opacity-60";
    }

    return "text-right";
}

function messageBlockClass(message: SupportMessage): string {
    const senderType = String(message?.sender_type || "").toLowerCase();
    if (senderType === "system") {
        return "flex flex-col items-center";
    }
    if (senderType === "customer") {
        return "flex flex-col items-start";
    }

    return "flex flex-col items-end";
}

function senderLabel(message: SupportMessage): string {
    const senderType = String(message?.sender_type || "").toLowerCase();
    if (senderType === "customer") {
        return customerNameFromMessage(message);
    }
    if (senderType === "bot") {
        return "Eden";
    }
    if (senderType === "system") {
        return "System";
    }

    if (senderType === "agent" && message?.is_private_note) {
        return "Internal Note";
    }

    return message?.sender?.name || "Agent";
}

function customerNameFromMessage(message: SupportMessage): string {
    return message?.sender?.name || "Customer";
}

function parseIsoTimestamp(value: string | null | undefined): number | null {
    if (!value) {
        return null;
    }

    const ts = new Date(value).getTime();
    return Number.isFinite(ts) ? ts : null;
}

function conversationEndedAtMs(conversation: SupportConversationListItem | null | undefined): number | null {
    if (!conversation) {
        return null;
    }

    return parseIsoTimestamp(conversation.ended_at || conversation.closed_at || null);
}

function conversationLiveTimerReferenceMs(conversation: SupportConversationListItem | null | undefined): number {
    const endedAtMs = conversationEndedAtMs(conversation);
    if (endedAtMs !== null) {
        return Math.min(liveClockNow.value, endedAtMs);
    }

    return liveClockNow.value;
}

function conversationDurationSeconds(conversation: SupportConversationListItem | null | undefined): number {
    const createdAt = conversation?.created_at || null;
    if (!createdAt) {
        return 0;
    }

    const startedAtMs = new Date(createdAt).getTime();
    if (!Number.isFinite(startedAtMs)) {
        return 0;
    }

    return Math.max(0, Math.floor((conversationLiveTimerReferenceMs(conversation) - startedAtMs) / 1000));
}

function conversationDurationLabel(conversation: SupportConversationListItem | null | undefined): string {
    return formatDurationCounter(conversationDurationSeconds(conversation));
}

function latestSupportReplyAtForConversation(conversationId: string): string | null {
    const conversation = conversationMap.value.get(conversationId);
    if (!conversation) {
        return null;
    }

    const messages = panelMessages(conversationId);
    const includeBot = Boolean(uiTimerSettings.value.last_response_include_bot);
    const isAssigned = Boolean(conversation.assignee?.id || conversation.assignee?.email || conversation.assignee?.name);
    const allowedSenderTypes = new Set(
        isAssigned
            ? ["agent"]
            : (includeBot ? ["agent", "bot"] : ["agent"]),
    );

    let latestTimestamp: string | null = null;
    let latestMs = -1;
    for (const message of messages) {
        if (!message || message.is_private_note) {
            continue;
        }

        const senderType = String(message.sender_type || "").toLowerCase();
        if (!allowedSenderTypes.has(senderType)) {
            continue;
        }

        const createdAt = message.created_at || message.updated_at || null;
        const ts = new Date(createdAt || 0).getTime();
        if (!Number.isFinite(ts) || ts < 0) {
            continue;
        }

        if (ts >= latestMs) {
            latestMs = ts;
            latestTimestamp = createdAt;
        }
    }

    if (latestTimestamp) {
        return latestTimestamp;
    }

    const fallback = conversation.latest_message;
    if (!fallback || fallback.is_private_note) {
        return null;
    }

    const fallbackType = String(fallback.sender_type || "").toLowerCase();
    if (!allowedSenderTypes.has(fallbackType)) {
        return null;
    }

    return fallback.created_at || fallback.updated_at || conversation.last_message_at || null;
}

function lastSupportReplyElapsedSeconds(conversationId: string): number | null {
    const conversation = conversationMap.value.get(conversationId);
    const lastSupportReplyAt = latestSupportReplyAtForConversation(conversationId);
    if (!lastSupportReplyAt) {
        return null;
    }

    const repliedAtMs = new Date(lastSupportReplyAt).getTime();
    if (!Number.isFinite(repliedAtMs)) {
        return null;
    }

    return Math.max(0, Math.floor((conversationLiveTimerReferenceMs(conversation) - repliedAtMs) / 1000));
}

function acwElapsedSeconds(conversationId: string): number | null {
    const conversation = conversationMap.value.get(conversationId);
    if (!conversation || String(conversation.status || "").toLowerCase() !== "wrap_up") {
        return null;
    }

    const endedAtMs = conversationEndedAtMs(conversation);
    if (endedAtMs === null) {
        return null;
    }

    return Math.max(0, Math.floor((liveClockNow.value - endedAtMs) / 1000));
}

function secondaryTimerPrefix(conversationId: string): string {
    return acwElapsedSeconds(conversationId) !== null ? "ACW" : "Gap";
}

function responseGapLabel(conversationId: string): string {
    const acwSeconds = acwElapsedSeconds(conversationId);
    if (acwSeconds !== null) {
        return formatElapsedCounter(acwSeconds);
    }

    const elapsedSeconds = lastSupportReplyElapsedSeconds(conversationId);
    if (elapsedSeconds === null) {
        return "Awaiting first reply";
    }

    return formatElapsedCounter(elapsedSeconds);
}

function responseGapToneClass(conversationId: string): string {
    if (acwElapsedSeconds(conversationId) !== null) {
        return "text-amber-500";
    }

    const elapsedSeconds = lastSupportReplyElapsedSeconds(conversationId);
    if (elapsedSeconds === null) {
        return "text-[var(--text-muted)]";
    }

    const elapsedMinutes = Math.floor(elapsedSeconds / 60);
    const warnAt = Number(uiTimerSettings.value.last_response_warn_minutes || DEFAULT_UI_TIMER_SETTINGS.last_response_warn_minutes);
    const alertAt = Number(uiTimerSettings.value.last_response_alert_minutes || DEFAULT_UI_TIMER_SETTINGS.last_response_alert_minutes);

    if (elapsedMinutes >= alertAt) {
        return "text-rose-500";
    }
    if (elapsedMinutes >= warnAt) {
        return "text-amber-400";
    }

    return "text-emerald-400";
}

function updateMessagesForConversation(
    conversationId: string,
    updater: (current: SupportMessage[]) => SupportMessage[],
): void {
    const current = messagesByConversation.value[conversationId] || [];
    messagesByConversation.value = {
        ...messagesByConversation.value,
        [conversationId]: updater(current),
    };
}

function markConversationRead(conversationId: string): void {
    const hasUnread = panelUnreadCount(conversationId) > 0;
    const hasJump = shouldShowPanelJumpToLatest(conversationId);
    const hasFirstUnread = Boolean(firstUnreadMessageIdByConversation.value[conversationId]);
    if (!hasUnread && !hasJump && !hasFirstUnread) {
        return;
    }

    unreadByConversation.value = {
        ...unreadByConversation.value,
        [conversationId]: 0,
    };
    setPanelJumpToLatestVisible(conversationId, false);
    setFirstUnreadMessageId(conversationId, null);
}

function incrementConversationUnread(conversationId: string): void {
    unreadByConversation.value = {
        ...unreadByConversation.value,
        [conversationId]: panelUnreadCount(conversationId) + 1,
    };
}

function isPanelNearBottom(conversationId: string): boolean {
    const container = panelMessageContainers[conversationId];
    if (!container) {
        return true;
    }

    const threshold = 96;
    return (
        container.scrollHeight - (container.scrollTop + container.clientHeight) <= threshold
    );
}

async function scrollPanelToBottom(conversationId: string, smooth = false): Promise<void> {
    await nextTick();

    const container = panelMessageContainers[conversationId];
    if (!container) {
        return;
    }

    container.scrollTo({
        top: container.scrollHeight,
        behavior: smooth ? "smooth" : "auto",
    });
    markConversationRead(conversationId);
}

function handlePanelScroll(conversationId: string): void {
    const container = panelMessageContainers[conversationId];
    if (!container) {
        return;
    }

    const nearBottom = isPanelNearBottom(conversationId);
    setPanelJumpToLatestVisible(conversationId, !nearBottom);
    if (nearBottom) {
        markConversationRead(conversationId);
    }

    if (container.scrollTop <= 72) {
        void loadOlderPanelMessages(conversationId);
    }
}

async function jumpPanelToLatest(conversationId: string): Promise<void> {
    if (!conversationId) {
        return;
    }

    await scrollPanelToBottom(conversationId, true);
}

async function jumpPanelToFirstUnread(conversationId: string): Promise<void> {
    const unreadMessageId = firstUnreadMessageIdByConversation.value[conversationId];
    if (!unreadMessageId) {
        await jumpPanelToLatest(conversationId);
        return;
    }

    await nextTick();
    const selectorId = `support-workbench-message-${conversationId}-${unreadMessageId}`;
    const element = document.getElementById(selectorId);
    if (!element) {
        await jumpPanelToLatest(conversationId);
        return;
    }

    element.scrollIntoView({
        behavior: "smooth",
        block: "center",
    });
}

async function handlePanelJumpToLatest(conversationId: string): Promise<void> {
    if (!conversationId) {
        return;
    }

    if (panelUnreadCount(conversationId) > 0) {
        await jumpPanelToFirstUnread(conversationId);
        return;
    }

    await jumpPanelToLatest(conversationId);
}

async function loadOlderPanelMessages(conversationId: string): Promise<void> {
    if (!conversationId || isLoadingOlderMessages(conversationId)) {
        return;
    }

    if (!hasMoreMessagesBefore(conversationId)) {
        return;
    }

    const before = oldestMessageId(conversationId);
    if (!before) {
        return;
    }

    const container = panelMessageContainers[conversationId];
    const oldScrollHeight = container?.scrollHeight || 0;
    const oldScrollTop = container?.scrollTop || 0;

    setLoadingOlderMessages(conversationId, true);
    try {
        await loadConversationMessages(conversationId, {
            before,
            appendOlder: true,
            withLoading: false,
            autoScroll: false,
        });

        await nextTick();
        const activeContainer = panelMessageContainers[conversationId];
        if (activeContainer) {
            const newScrollHeight = activeContainer.scrollHeight;
            activeContainer.scrollTop = oldScrollTop + (newScrollHeight - oldScrollHeight);
        }
    } catch (error) {
        supportLogger.error("messages.fetch.older.failure", "Failed to load older workbench messages.", {
            conversation_id: conversationId,
            ...summarizeError(error),
        });
    } finally {
        setLoadingOlderMessages(conversationId, false);
    }
}

function ensureOpenConversations(seedRows: SupportConversationListItem[] = conversations.value): void {
    const maxPanels = maxOpenPanels.value;
    const rank = (status: string): number => {
        const normalized = String(status || "").toLowerCase();
        if (normalized === "assigned") return 0;
        if (normalized === "waiting_human") return 1;
        if (normalized === "open" || normalized === "bot_active") return 2;
        if (normalized === "resolved") return 3;
        if (normalized === "closed") return 4;
        return 5;
    };

    const availableIds = [...seedRows]
        .sort((a, b) => {
            const rankDelta = rank(a.status) - rank(b.status);
            if (rankDelta !== 0) {
                return rankDelta;
            }

            const aTime = new Date(a.last_message_at || a.updated_at || 0).getTime();
            const bTime = new Date(b.last_message_at || b.updated_at || 0).getTime();
            return bTime - aTime;
        })
        .map((conversation) => conversation.id);
    const existing = openConversationIds.value
        .filter((id) => availableIds.includes(id))
        .slice(0, maxPanels);

    const next = [...existing];
    for (const id of availableIds) {
        if (next.length >= maxPanels) {
            break;
        }

        if (!next.includes(id)) {
            next.push(id);
        }
    }

    openConversationIds.value = next;
    if (!focusedConversationId.value || !next.includes(focusedConversationId.value)) {
        focusedConversationId.value = next[0] || null;
    }
}

function removeConversationFromWorkbench(conversationId: string): void {
    if (!conversationId) {
        return;
    }

    conversations.value = conversations.value.filter((conversation) => conversation.id !== conversationId);
    openConversationIds.value = openConversationIds.value.filter((id) => id !== conversationId);

    if (messagesByConversation.value[conversationId]) {
        const nextMessages = { ...messagesByConversation.value };
        delete nextMessages[conversationId];
        messagesByConversation.value = nextMessages;
    }

    if (unreadByConversation.value[conversationId]) {
        const nextUnread = { ...unreadByConversation.value };
        delete nextUnread[conversationId];
        unreadByConversation.value = nextUnread;
    }

    if (hasMoreBeforeByConversation.value[conversationId] !== undefined) {
        const nextHasMore = { ...hasMoreBeforeByConversation.value };
        delete nextHasMore[conversationId];
        hasMoreBeforeByConversation.value = nextHasMore;
    }

    if (oldestMessageIdByConversation.value[conversationId] !== undefined) {
        const nextOldest = { ...oldestMessageIdByConversation.value };
        delete nextOldest[conversationId];
        oldestMessageIdByConversation.value = nextOldest;
    }

    if (loadingOlderMessagesByConversation.value[conversationId] !== undefined) {
        const nextLoadingOlder = { ...loadingOlderMessagesByConversation.value };
        delete nextLoadingOlder[conversationId];
        loadingOlderMessagesByConversation.value = nextLoadingOlder;
    }

    if (showJumpToLatestByConversation.value[conversationId] !== undefined) {
        const nextJump = { ...showJumpToLatestByConversation.value };
        delete nextJump[conversationId];
        showJumpToLatestByConversation.value = nextJump;
    }

    if (firstUnreadMessageIdByConversation.value[conversationId] !== undefined) {
        const nextFirstUnread = { ...firstUnreadMessageIdByConversation.value };
        delete nextFirstUnread[conversationId];
        firstUnreadMessageIdByConversation.value = nextFirstUnread;
    }

    if (composerByConversation.value[conversationId]) {
        const nextComposer = { ...composerByConversation.value };
        delete nextComposer[conversationId];
        composerByConversation.value = nextComposer;
    }

    if (composerCollapsedByConversation.value[conversationId] !== undefined) {
        const nextComposerCollapsed = { ...composerCollapsedByConversation.value };
        delete nextComposerCollapsed[conversationId];
        composerCollapsedByConversation.value = nextComposerCollapsed;
    }

    if (noteModeByConversation.value[conversationId]) {
        const nextNoteMode = { ...noteModeByConversation.value };
        delete nextNoteMode[conversationId];
        noteModeByConversation.value = nextNoteMode;
    }

    if (panelComposerRefs[conversationId]) {
        delete panelComposerRefs[conversationId];
    }

    if (panelMessageContainers[conversationId]) {
        delete panelMessageContainers[conversationId];
    }

    if (panelFileInputRefs[conversationId]) {
        delete panelFileInputRefs[conversationId];
    }

    if (panelEmojiMountRefs[conversationId]) {
        delete panelEmojiMountRefs[conversationId];
    }

    const mountedPicker = panelEmojiPickerInstances.get(conversationId);
    if (mountedPicker?.parentNode) {
        mountedPicker.parentNode.removeChild(mountedPicker);
    }
    panelEmojiPickerInstances.delete(conversationId);

    clearPanelSelectedFiles(conversationId);

    if (openEmojiPickerConversationId.value === conversationId) {
        openEmojiPickerConversationId.value = null;
    }

    if (focusedConversationId.value === conversationId) {
        focusedConversationId.value = openConversationIds.value[0] || conversations.value[0]?.id || null;
    }
}

function upsertConversationInList(
    patch: Partial<SupportConversationListItem> & { id: string },
    options: { moveToTop?: boolean } = {},
): void {
    if (!shouldShowConversationInWorkbench(patch)) {
        removeConversationFromWorkbench(patch.id);
        return;
    }

    const index = conversations.value.findIndex((conversation) => conversation.id === patch.id);
    const moveToTop = options.moveToTop !== false;

    if (index === -1) {
        conversations.value = [patch as SupportConversationListItem, ...conversations.value];
        return;
    }

    const merged = {
        ...conversations.value[index],
        ...patch,
    } as SupportConversationListItem;

    const next = [...conversations.value];
    if (moveToTop && index > 0) {
        next.splice(index, 1);
        next.unshift(merged);
    } else {
        next[index] = merged;
    }

    conversations.value = next;
}

function patchConversationFromMessage(conversationId: string, message: SupportMessage): void {
    if (!conversationId || !message?.id) {
        return;
    }

    if (message.is_private_note) {
        return;
    }

    if (!conversationMap.value.has(conversationId)) {
        scheduleSilentInboxRefresh();
        return;
    }

    upsertConversationInList(
        {
            id: conversationId,
            latest_message: message,
            last_message_at: message.created_at || null,
            updated_at: message.created_at || null,
        },
        { moveToTop: true },
    );
}

function extractMessagePayload(responseData: any): SupportMessage | null {
    const direct = responseData?.data;
    if (direct && typeof direct === "object" && direct.id) {
        return direct as SupportMessage;
    }

    const nested = responseData?.data?.data;
    if (nested && typeof nested === "object" && nested.id) {
        return nested as SupportMessage;
    }

    return null;
}

function extractConversationPayload(responseData: any): SupportConversationListItem | null {
    const direct = responseData?.conversation;
    if (direct && typeof direct === "object" && direct.id) {
        return direct as SupportConversationListItem;
    }

    const nested = responseData?.conversation?.data;
    if (nested && typeof nested === "object" && nested.id) {
        return nested as SupportConversationListItem;
    }

    return null;
}

function applyRealtimeMeta(meta: any): void {
    applyUiTimerMeta(meta);
    applyWorkbenchMeta(meta);
    const token = meta?.realtime?.token;
    if (typeof token === "string" && token.trim() !== "") {
        setSupportRealtimeToken(token.trim(), "agent");
        supportLogger.debug("realtime.token.updated", "Applied agent realtime token from API meta.");
    }
}

function withPanelComposerSelection(
    conversationId: string,
    mutator: (context: {
        value: string;
        start: number;
        end: number;
        selected: string;
    }) => { value: string; selectionStart?: number; selectionEnd?: number } | null,
): void {
    if (!conversationId || sendingByConversation.value[conversationId]) {
        return;
    }

    const input = panelComposerRefs[conversationId];
    if (!input) {
        return;
    }

    const currentValue = String(composerByConversation.value[conversationId] || "");
    const start = Number.isFinite(input.selectionStart) ? input.selectionStart : currentValue.length;
    const end = Number.isFinite(input.selectionEnd) ? input.selectionEnd : currentValue.length;
    const selected = currentValue.slice(start, end);
    const result = mutator({ value: currentValue, start, end, selected });

    if (!result || typeof result.value !== "string") {
        return;
    }

    setComposerValue(conversationId, result.value);
    nextTick(() => {
        const textarea = panelComposerRefs[conversationId];
        if (!textarea || textarea.disabled) {
            return;
        }

        textarea.focus({ preventScroll: true });
        if (typeof textarea.setSelectionRange === "function") {
            const selectionStart = Number.isFinite(result.selectionStart)
                ? result.selectionStart
                : result.value.length;
            const selectionEnd = Number.isFinite(result.selectionEnd)
                ? result.selectionEnd
                : selectionStart;
            try {
                textarea.setSelectionRange(selectionStart, selectionEnd);
            } catch {
                // Ignore selection API failures for non-text mode environments.
            }
        }
    });
}

function applyPanelInlineFormat(
    conversationId: string,
    prefix: string,
    suffix = prefix,
    placeholder = "text",
): void {
    withPanelComposerSelection(conversationId, ({ value, start, end, selected }) => {
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

function applyPanelListFormat(conversationId: string, type: "bullet" | "numbered" = "bullet"): void {
    withPanelComposerSelection(conversationId, ({ value, start, end, selected }) => {
        const raw = selected || "List item";
        const lines = raw.split("\n");
        const replacement = lines
            .map((line, index) => {
                const marker = type === "numbered" ? `${index + 1}.` : "-";
                return `${marker} ${line || "List item"}`;
            })
            .join("\n");

        const nextValue = `${value.slice(0, start)}${replacement}${value.slice(end)}`;
        return {
            value: nextValue,
            selectionStart: start,
            selectionEnd: start + replacement.length,
        };
    });
}

function insertPanelLink(conversationId: string): void {
    withPanelComposerSelection(conversationId, ({ value, start, end, selected }) => {
        const trimmed = selected.trim();
        const selectedIsUrl = /^https?:\/\/\S+$/i.test(trimmed);
        const linkText = selectedIsUrl ? "link text" : (trimmed || "link text");
        const url = selectedIsUrl ? trimmed : "https://example.com";
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

function onQuickLinkFacadeClick(label: string): void {
    toast.info("Quick link coming soon", `${label} integration is in facade mode for now.`);
}

function openPanelFilePicker(conversationId: string): void {
    if (!conversationId || sendingByConversation.value[conversationId]) {
        return;
    }

    openEmojiPickerConversationId.value = null;
    if (isComposerCollapsed(conversationId)) {
        setComposerCollapsed(conversationId, false);
    }
    panelFileInputRefs[conversationId]?.click();
}

function handlePanelFileSelection(conversationId: string, event: Event): void {
    const incoming = Array.from((event.target as HTMLInputElement | null)?.files || []);
    if (!incoming.length) {
        return;
    }

    const merged = [...panelSelectedFiles(conversationId), ...incoming];
    if (merged.length > MAX_ATTACHABLE_FILES) {
        toast.error("Limit reached", `You can attach up to ${MAX_ATTACHABLE_FILES} files per message.`);
    }

    setPanelSelectedFiles(conversationId, merged.slice(0, MAX_ATTACHABLE_FILES));
    const input = panelFileInputRefs[conversationId];
    if (input) {
        input.value = "";
    }
}

function removePanelFile(conversationId: string, index: number): void {
    const current = panelSelectedFiles(conversationId);
    if (!current.length || index < 0 || index >= current.length) {
        return;
    }

    const next = [...current];
    next.splice(index, 1);
    if (next.length === 0) {
        clearPanelSelectedFiles(conversationId);
        return;
    }

    setPanelSelectedFiles(conversationId, next);
}

function appendPanelEmoji(conversationId: string, emoji: string): void {
    if (!conversationId || !emoji || sendingByConversation.value[conversationId]) {
        return;
    }

    withPanelComposerSelection(conversationId, ({ value, start, end }) => {
        const nextValue = `${value.slice(0, start)}${emoji}${value.slice(end)}`;
        const nextCursor = start + emoji.length;
        return {
            value: nextValue,
            selectionStart: nextCursor,
            selectionEnd: nextCursor,
        };
    });
}

function mountPanelEmojiPicker(conversationId: string): void {
    const mount = panelEmojiMountRefs[conversationId];
    if (!mount) {
        return;
    }

    const previousPicker = panelEmojiPickerInstances.get(conversationId);
    if (previousPicker?.parentNode === mount) {
        return;
    }

    mount.textContent = "";

    const pickerElement = new Picker({
        data,
        previewPosition: "none",
        skinTonePosition: "none",
        theme: document.documentElement.classList.contains("dark") ? "dark" : "light",
        set: "native",
        perLine: 8,
        maxFrequentRows: 1,
        onEmojiSelect: (emoji: any) => {
            const native = typeof emoji?.native === "string" ? emoji.native : "";
            appendPanelEmoji(conversationId, native);
        },
    }) as unknown as HTMLElement;

    mount.appendChild(pickerElement);
    panelEmojiPickerInstances.set(conversationId, pickerElement);
}

function togglePanelEmojiPicker(conversationId: string): void {
    if (!conversationId || sendingByConversation.value[conversationId]) {
        return;
    }

    if (isComposerCollapsed(conversationId)) {
        setComposerCollapsed(conversationId, false);
    }

    if (openEmojiPickerConversationId.value === conversationId) {
        openEmojiPickerConversationId.value = null;
        return;
    }

    openEmojiPickerConversationId.value = conversationId;
    nextTick(() => {
        mountPanelEmojiPicker(conversationId);
    });
}

function handleWorkbenchGlobalPointerDown(event: PointerEvent): void {
    const openConversationId = openEmojiPickerConversationId.value;
    if (!openConversationId) {
        return;
    }

    const target = event.target as HTMLElement | null;
    if (!target) {
        openEmojiPickerConversationId.value = null;
        return;
    }

    const openMount = panelEmojiMountRefs[openConversationId];
    if (openMount?.contains(target)) {
        return;
    }

    if (target.closest(`[data-workbench-emoji-toggle="${openConversationId}"]`)) {
        return;
    }

    openEmojiPickerConversationId.value = null;
}

function onMacroFacadeClick(): void {
    toast.info("Macros", "Macro actions are in facade mode in workbench.");
}

function isAssignedToCurrentUser(conversationId: string): boolean {
    const conversation = conversationMap.value.get(conversationId);
    const currentUserId = String(authStore.user?.public_id || "").trim();
    const assigneeId = String(conversation?.assignee?.id || "").trim();

    return Boolean(currentUserId && assigneeId && currentUserId === assigneeId);
}

function canAssignConversation(conversationId: string): boolean {
    const conversation = conversationMap.value.get(conversationId);
    return Boolean(
        conversation &&
        !isConversationClosedLike(conversation) &&
        !assigningByConversation.value[conversationId],
    );
}

function canResolveConversation(conversationId: string): boolean {
    const conversation = conversationMap.value.get(conversationId);
    return Boolean(
        conversation &&
        !isConversationClosedLike(conversation) &&
        !resolvingByConversation.value[conversationId],
    );
}

function canEndConversation(conversationId: string): boolean {
    const conversation = conversationMap.value.get(conversationId);
    return Boolean(
        conversation &&
        !isConversationClosedLike(conversation) &&
        !endingByConversation.value[conversationId],
    );
}

async function refreshConversationPanel(conversationId: string): Promise<void> {
    if (!conversationId) {
        return;
    }

    await Promise.all([
        loadConversationMessages(conversationId, { withLoading: true, autoScroll: false }),
        loadConversations({ silent: true, refreshPanelMessages: false }),
    ]);
}

async function assignConversationToCurrentUser(conversationId: string): Promise<void> {
    const currentUserId = String(authStore.user?.public_id || "").trim();
    if (!conversationId || !currentUserId || !canAssignConversation(conversationId)) {
        return;
    }

    if (isAssignedToCurrentUser(conversationId)) {
        toast.info("Already assigned", "This conversation is already assigned to you.");
        return;
    }

    setAssigning(conversationId, true);
    try {
        const response = await api.post(`/api/support/chats/${conversationId}/assign`, {
            agent_public_id: currentUserId,
        });
        applyRealtimeMeta(response?.data?.meta);

        const payload = extractConversationPayload(response?.data);
        if (payload) {
            upsertConversationInList(payload, { moveToTop: false });
        }
        toast.success("Assigned", "Conversation assigned to you.");
    } catch (error: any) {
        toast.error(error?.response?.data?.message || "Failed to assign conversation.");
    } finally {
        setAssigning(conversationId, false);
    }
}

async function resolveConversation(conversationId: string): Promise<void> {
    if (!conversationId || !canResolveConversation(conversationId)) {
        return;
    }

    setResolving(conversationId, true);
    try {
        const response = await api.post(`/api/support/chats/${conversationId}/resolve`);
        applyRealtimeMeta(response?.data?.meta);

        const payload = extractConversationPayload(response?.data);
        if (payload) {
            upsertConversationInList(payload, { moveToTop: true });
            setCloseResolved(conversationId, true);
        }
        toast.success("Close started", "Conversation moved to close-out as resolved.");
    } catch (error: any) {
        toast.error(error?.response?.data?.message || "Failed to start close-out.");
    } finally {
        setResolving(conversationId, false);
    }
}

async function endConversation(conversationId: string): Promise<void> {
    if (!conversationId || !canEndConversation(conversationId)) {
        return;
    }

    setEnding(conversationId, true);
    try {
        const response = await api.post(`/api/support/chats/agent/${conversationId}/end`);
        applyRealtimeMeta(response?.data?.meta);

        const payload = extractConversationPayload(response?.data);
        if (payload) {
            upsertConversationInList(payload, { moveToTop: true });
        }
        toast.success("Close started", "Conversation moved to close-out.");
    } catch (error: any) {
        toast.error(error?.response?.data?.message || "Failed to end conversation.");
    } finally {
        setEnding(conversationId, false);
    }
}

function getAttachmentOpenUrl(attachment: SupportAttachment): string {
    return String(attachment?.download_url || attachment?.url || "");
}

function isImageAttachment(attachment: SupportAttachment): boolean {
    if (attachment?.media_kind) {
        return String(attachment.media_kind).toLowerCase() === "image";
    }

    if (attachment?.is_image === true) {
        return true;
    }

    return String(attachment?.mime_type || "").toLowerCase().startsWith("image/");
}

function isAudioAttachment(attachment: SupportAttachment): boolean {
    if (attachment?.media_kind) {
        return String(attachment.media_kind).toLowerCase() === "audio";
    }

    if (attachment?.is_audio === true || attachment?.is_voice_clip === true) {
        return true;
    }

    const mime = String(attachment?.mime_type || "").toLowerCase();
    if (mime.startsWith("audio/")) {
        return true;
    }

    if (mime.startsWith("video/")) {
        const name = String(attachment?.name || "").trim().toLowerCase();
        return name.startsWith("voice-") || name.startsWith("audio-") || name.startsWith("recording-");
    }

    return false;
}

function isVideoAttachment(attachment: SupportAttachment): boolean {
    if (attachment?.media_kind) {
        return String(attachment.media_kind).toLowerCase() === "video";
    }

    if (attachment?.is_video === true) {
        return true;
    }

    const mime = String(attachment?.mime_type || "").toLowerCase();
    return mime.startsWith("video/") && !isAudioAttachment(attachment);
}

function getAttachmentTypeLabel(attachment: SupportAttachment): string {
    if (isImageAttachment(attachment)) {
        return "Image";
    }
    if (isVideoAttachment(attachment)) {
        return "Video";
    }
    if (isAudioAttachment(attachment)) {
        return "Audio";
    }
    return "File";
}

function formatAttachmentSize(bytes: number | null | undefined): string {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) {
        return "0 B";
    }
    if (value < 1024) {
        return `${value} B`;
    }

    const units = ["KB", "MB", "GB", "TB"];
    const index = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length);
    return `${parseFloat((value / Math.pow(1024, index)).toFixed(1))} ${units[index - 1]}`;
}

function openAttachmentFromSidebar(attachment: SidebarAttachmentItem): void {
    const targetUrl = getAttachmentOpenUrl(attachment);
    if (!targetUrl) {
        return;
    }

    if (isImageAttachment(attachment) || isVideoAttachment(attachment)) {
        const media = focusedConversationAttachmentItems.value
            .filter((item) => isImageAttachment(item) || isVideoAttachment(item))
            .map((item) => ({
                src: item.url || item.download_url,
                download: item.download_url || item.url,
                id: item.id || item._key,
                type: isVideoAttachment(item) ? "video" : "image",
                mimeType: item.mime_type,
            }));

        const targetId = attachment.id || attachment._key;
        const index = media.findIndex((item) => item.id === targetId);
        window.dispatchEvent(new CustomEvent("media-viewer:open", {
            detail: {
                media,
                index: index >= 0 ? index : 0,
            },
        }));
        return;
    }

    window.open(targetUrl, "_blank", "noopener,noreferrer");
}

function scheduleSupportRealtimeRetry(): void {
    if (realtimeSubscriptionRetryTimer) {
        return;
    }

    const delayMs = Math.min(1000 * 2 ** supportRealtimeRetryAttempts, 8000);
    supportRealtimeRetryAttempts = Math.min(supportRealtimeRetryAttempts + 1, 6);

    supportLogger.warn("realtime.retry.schedule", "Scheduling workbench realtime retry.", {
        attempt: supportRealtimeRetryAttempts,
        delay_ms: delayMs,
    });

    realtimeSubscriptionRetryTimer = setTimeout(async () => {
        realtimeSubscriptionRetryTimer = null;
        await refreshRealtimeToken();
        await subscribeSupportRealtime();
    }, delayMs);
}

function clearRealtimeSubscriptions({ clearToken = true } = {}): void {
    const echo = (window as any).Echo;
    if (echo && supportRealtimeInboxChannelName) {
        echo.leave(supportRealtimeInboxChannelName);
    }

    if (echo) {
        for (const channelName of supportRealtimeConversationChannels) {
            echo.leave(channelName);
        }
    }

    supportRealtimeInboxChannelName = "";
    supportRealtimeConversationChannels.clear();

    if (clearToken) {
        setSupportRealtimeToken(null, "agent");
    }
}

function scheduleSilentInboxRefresh(): void {
    if (inboxReloadDebounceTimer) {
        clearTimeout(inboxReloadDebounceTimer);
    }

    inboxReloadDebounceTimer = setTimeout(async () => {
        inboxReloadDebounceTimer = null;
        await loadConversations({ silent: true, refreshPanelMessages: false });
    }, 220);
}

async function refreshRealtimeToken(): Promise<boolean> {
    try {
        const response = await api.get("/api/support/chats/realtime-token");
        const token = response?.data?.data?.token;
        if (typeof token === "string" && token.trim() !== "") {
            setSupportRealtimeToken(token.trim(), "agent");
            supportLogger.info("realtime.token.refresh.success", "Refreshed support realtime token for workbench.");
            return true;
        }
    } catch (error) {
        supportLogger.error(
            "realtime.token.refresh.failure",
            "Failed to refresh support realtime token for workbench.",
            summarizeError(error),
        );
    }

    return hasSupportRealtimeToken("agent");
}

async function ensureRealtimeToken(): Promise<boolean> {
    if (hasSupportRealtimeToken("agent")) {
        return true;
    }

    if (supportRealtimeTokenHydratePromise) {
        return supportRealtimeTokenHydratePromise;
    }

    supportRealtimeTokenHydratePromise = (async () => {
        const hydrated = await refreshRealtimeToken();
        return hydrated || hasSupportRealtimeToken("agent");
    })().finally(() => {
        supportRealtimeTokenHydratePromise = null;
    });

    return supportRealtimeTokenHydratePromise;
}

function upsertIncomingMessage(conversationId: string, message: SupportMessage): boolean {
    let added = false;

    updateMessagesForConversation(conversationId, (current) => {
        const existingIndex = current.findIndex((entry) => entry.id === message.id);
        let next = [...current];

        if (existingIndex === -1) {
            next.push(message);
            added = true;
        } else {
            next[existingIndex] = {
                ...next[existingIndex],
                ...message,
            };
        }

        next.sort((a, b) => {
            const aValue = new Date(a.created_at || 0).getTime();
            const bValue = new Date(b.created_at || 0).getTime();
            return aValue - bValue;
        });

        return next;
    });

    return added;
}

async function handleRealtimeMessage(event: any): Promise<void> {
    const conversationId = String(event?.conversation_id || "");
    const incoming = event?.message as SupportMessage | undefined;

    if (!conversationId || !incoming?.id) {
        supportLogger.warn("realtime.message.skip", "Workbench received malformed SupportMessageCreated event.", {
            event,
        });
        return;
    }

    const wasNearBottom = isPanelNearBottom(conversationId);
    const added = upsertIncomingMessage(conversationId, incoming);
    patchConversationFromMessage(conversationId, incoming);

    if (added && String(incoming.sender_type || "").toLowerCase() === "customer") {
        playSupportMessageSound();
    }

    if (!added) {
        if (wasNearBottom) {
            await scrollPanelToBottom(conversationId, false);
        }
        return;
    }

    if (wasNearBottom) {
        await scrollPanelToBottom(conversationId, false);
    } else {
        incrementConversationUnread(conversationId);
        if (!firstUnreadMessageIdByConversation.value[conversationId]) {
            setFirstUnreadMessageId(conversationId, incoming.id);
        }
        setPanelJumpToLatestVisible(conversationId, true);
    }
}

function handleRealtimeConversationChanged(event: any): void {
    const conversationId = String(event?.conversation_id || "");
    if (!conversationId) {
        return;
    }

    const currentUserId = String(authStore.user?.public_id || "");
    const assignedTo = String(event?.assigned_to || "");
    const nextStatus = String(event?.status || "").toLowerCase();
    if (nextStatus === "resolved" || nextStatus === "closed") {
        removeConversationFromWorkbench(conversationId);
        pendingAssignments.value = pendingAssignments.value.filter((conversation) => conversation.id !== conversationId);
        return;
    }

    if (!conversationMap.value.has(conversationId)) {
        // Only refetch if this event may affect the current agent's workbench.
        if (currentUserId && assignedTo === currentUserId) {
            scheduleSilentInboxRefresh();
        }
        return;
    }

    if (currentUserId && assignedTo && assignedTo !== currentUserId) {
        removeConversationFromWorkbench(conversationId);
        pendingAssignments.value = pendingAssignments.value.filter((conversation) => conversation.id !== conversationId);
        return;
    }

    // Apply lightweight local patch — no full refetch needed
    upsertConversationInList(
        {
            id: conversationId,
            status: String(event?.status || "open"),
            updated_at: event?.updated_at || null,
            last_message_at: event?.last_message_at || null,
            assignee: event?.assigned_to
                ? {
                      ...(conversationMap.value.get(conversationId)?.assignee || {}),
                      id: String(event.assigned_to),
                  }
                : conversationMap.value.get(conversationId)?.assignee || null,
        },
        { moveToTop: false },
    );

    if (nextStatus === "pending_acceptance" && assignedTo === currentUserId) {
        const existing = pendingAssignments.value.find(c => c.id === conversationId);
        if (!existing) {
            const conversation = conversationMap.value.get(conversationId) || {
                id: conversationId,
                status: nextStatus,
                guest_name: event.guest_name || "New Customer",
                support_skill: event.support_skill || { name: "General" }
            } as any;
            conversation._receivedAt = Date.now();
            pendingAssignments.value.push(conversation);
        }
    } else {
        pendingAssignments.value = pendingAssignments.value.filter(c => c.id !== conversationId);
    }
}

async function subscribeSupportRealtime(): Promise<void> {
    if (supportRealtimeSubscribeInFlight) {
        supportRealtimeSubscribePending = true;
        return;
    }

    supportRealtimeSubscribeInFlight = true;

    const echo = startEcho() || (window as any).Echo;
    if (!echo) {
        supportLogger.warn("realtime.subscribe.skip", "Echo instance not ready for workbench.");
        supportRealtimeSubscribeInFlight = false;
        return;
    }

    try {
        if (!hasSupportRealtimeToken("agent")) {
            const hydrated = await ensureRealtimeToken();
            if (!hydrated) {
                supportLogger.warn("realtime.subscribe.no_token", "Missing support agent token for workbench channel subscription.");
                scheduleSupportRealtimeRetry();
                return;
            }
        }

        if (supportRealtimeInboxChannelName !== "support.agent.inbox") {
            if (supportRealtimeInboxChannelName) {
                echo.leave(supportRealtimeInboxChannelName);
            }

            const inboxChannel = echo.private("support.agent.inbox").listen(".SupportConversationChanged", (event: any) => {
                handleRealtimeConversationChanged(event);
            });

            if ((inboxChannel as any).subscription) {
                (inboxChannel as any).subscription.bind("pusher:subscription_succeeded", () => {
                    supportRealtimeRetryAttempts = 0;
                    supportLogger.info("realtime.subscribe.success", "Subscribed to support.agent.inbox (workbench).");
                });

                (inboxChannel as any).subscription.bind("pusher:subscription_error", (error: unknown) => {
                    supportLogger.error("realtime.subscribe.failure", "Failed to subscribe workbench inbox channel.", summarizeError(error));
                    if (supportRealtimeInboxChannelName === "support.agent.inbox") {
                        supportRealtimeInboxChannelName = "";
                    }
                    scheduleSupportRealtimeRetry();
                });
            }

            supportRealtimeInboxChannelName = "support.agent.inbox";
        }

        const desiredChannels = new Set(
            openConversationIds.value.map((conversationId) => `support.agent.${conversationId}`),
        );

        for (const existingChannel of supportRealtimeConversationChannels) {
            if (!desiredChannels.has(existingChannel)) {
                echo.leave(existingChannel);
                supportRealtimeConversationChannels.delete(existingChannel);
            }
        }

        for (const channelName of desiredChannels) {
            if (supportRealtimeConversationChannels.has(channelName)) {
                continue;
            }

            const conversationChannel = echo
                .private(channelName)
                .listen(".SupportMessageCreated", async (event: any) => {
                    await handleRealtimeMessage(event);
                })
                .listen(".SupportConversationChanged", (event: any) => {
                    handleRealtimeConversationChanged(event);
                });

            if ((conversationChannel as any).subscription) {
                (conversationChannel as any).subscription.bind("pusher:subscription_succeeded", () => {
                    supportRealtimeRetryAttempts = 0;
                    supportLogger.info("realtime.subscribe.success", "Subscribed to support workbench conversation channel.", {
                        channel: channelName,
                    });
                });

                (conversationChannel as any).subscription.bind("pusher:subscription_error", (error: unknown) => {
                    supportLogger.error("realtime.subscribe.failure", "Failed to subscribe support workbench conversation channel.", {
                        channel: channelName,
                        ...summarizeError(error),
                    });
                    supportRealtimeConversationChannels.delete(channelName);
                    scheduleSupportRealtimeRetry();
                });
            }

            supportRealtimeConversationChannels.add(channelName);
        }
    } finally {
        supportRealtimeSubscribeInFlight = false;
        if (supportRealtimeSubscribePending) {
            supportRealtimeSubscribePending = false;
            void subscribeSupportRealtime();
        }
    }
}

async function loadConversationMessages(
    conversationId: string,
    options: {
        withLoading?: boolean;
        autoScroll?: boolean;
        before?: string | null;
        appendOlder?: boolean;
        mergeLatest?: boolean;
        trackUnread?: boolean;
        wasNearBottom?: boolean;
        limit?: number;
    } = {},
): Promise<void> {
    if (!conversationId) {
        return;
    }

    const withLoading = options.withLoading !== false;
    const before = typeof options.before === "string" && options.before.trim() !== ""
        ? options.before.trim()
        : null;
    const appendOlder = options.appendOlder === true;
    const mergeLatest = options.mergeLatest === true;
    const trackUnread = options.trackUnread !== false;
    const wasNearBottom = typeof options.wasNearBottom === "boolean"
        ? options.wasNearBottom
        : isPanelNearBottom(conversationId);
    const previousMessages = panelMessages(conversationId);
    if (withLoading) {
        setLoadingMessages(conversationId, true);
    }

    try {
        const response = await api.get(`/api/support/chats/agent/${conversationId}/messages`, {
            params: {
                limit: options.limit || 30,
                ...(before ? { before } : {}),
            },
        });

        const incoming = Array.isArray(response?.data?.data)
            ? (response.data.data as SupportMessage[])
            : [];
        const meta = response?.data?.meta || {};

        if (appendOlder) {
            const existingIds = new Set(previousMessages.map((entry) => entry.id));
            const olderRows = incoming.filter((entry) => !existingIds.has(entry.id));
            updateMessagesForConversation(conversationId, (current) => [...olderRows, ...current]);
        } else if (mergeLatest) {
            const merged = new Map<string, SupportMessage>();
            for (const message of previousMessages) {
                if (message?.id) {
                    merged.set(message.id, message);
                }
            }
            for (const message of incoming) {
                if (message?.id) {
                    merged.set(message.id, message);
                }
            }

            const sorted = Array.from(merged.values()).sort((a, b) => {
                const aValue = new Date(a?.created_at || 0).getTime();
                const bValue = new Date(b?.created_at || 0).getTime();
                return aValue - bValue;
            });
            updateMessagesForConversation(conversationId, () => sorted);
        } else {
            updateMessagesForConversation(conversationId, () => incoming);
            markConversationRead(conversationId);
        }

        const latestRows = panelMessages(conversationId);
        setHasMoreBefore(conversationId, Boolean(meta.has_more_before));
        setOldestMessageId(conversationId, meta.oldest_id || latestRows[0]?.id || null);

        if (mergeLatest && trackUnread) {
            const previousIds = new Set(previousMessages.map((message) => message.id));
            const appended = latestRows.filter((message) => !previousIds.has(message.id));
            if (appended.length > 0) {
                if (!wasNearBottom) {
                    unreadByConversation.value = {
                        ...unreadByConversation.value,
                        [conversationId]: panelUnreadCount(conversationId) + appended.length,
                    };
                    if (!firstUnreadMessageIdByConversation.value[conversationId]) {
                        setFirstUnreadMessageId(conversationId, appended[0].id);
                    }
                    setPanelJumpToLatestVisible(conversationId, true);
                } else {
                    markConversationRead(conversationId);
                }
            }
        }

        if (options.autoScroll !== false) {
            await scrollPanelToBottom(conversationId, false);
        }
    } catch (error) {
        supportLogger.error("messages.fetch.failure", "Failed to load workbench conversation messages.", {
            conversation_id: conversationId,
            ...summarizeError(error),
        });
    } finally {
        if (withLoading) {
            setLoadingMessages(conversationId, false);
        }
    }
}

async function ensureConversationMessages(conversationId: string): Promise<void> {
    if (!conversationId) {
        return;
    }

    const existing = panelMessages(conversationId);
    if (existing.length > 0 || loadingMessagesByConversation.value[conversationId]) {
        return;
    }

    await loadConversationMessages(conversationId, { withLoading: true, autoScroll: true });
}

async function loadConversations(
    options: { silent?: boolean; refreshPanelMessages?: boolean } = {},
): Promise<void> {
    const silent = options.silent === true;
    const refreshPanelMessages = options.refreshPanelMessages !== false;

    if (silent) {
        isRefreshingList.value = true;
    } else {
        isLoadingList.value = true;
    }

    try {
        const params: Record<string, unknown> = {
            scope: "mine",
            per_page: 50,
        };

        const response = await api.get("/api/support/chats/inbox", { params });
        applyRealtimeMeta(response?.data?.meta);

        const rows = Array.isArray(response?.data?.data)
            ? (response.data.data as SupportConversationListItem[])
            : [];
        const visibleRows = rows.filter((conversation) => shouldShowConversationInWorkbench(conversation));
        conversations.value = visibleRows;
        pendingAssignments.value = rows.filter((conversation) => conversation.status === 'pending_acceptance')
            .map(c => ({ ...c, _receivedAt: Date.now() }));
        ensureOpenConversations(visibleRows);

        if (refreshPanelMessages) {
            await Promise.all(
                openConversationIds.value.map((conversationId) =>
                    ensureConversationMessages(conversationId),
                ),
            );
        }

        await subscribeSupportRealtime();
        supportLogger.info("inbox.fetch.success", "Loaded support workbench conversations.", {
            count: conversations.value.length,
            open_panels: openConversationIds.value.length,
            total: Number(response?.data?.meta?.total || conversations.value.length),
        });
    } catch (error) {
        supportLogger.error("inbox.fetch.failure", "Failed to load support workbench conversations.", summarizeError(error));
        if (!silent) {
            toast.error("Unable to load agent workbench conversations.");
        }
    } finally {
        isLoadingList.value = false;
        isRefreshingList.value = false;
    }
}

async function sendPanelMessage(conversationId: string): Promise<void> {
    const body = String(composerByConversation.value[conversationId] || "").trim();
    const files = panelSelectedFiles(conversationId).slice(0, MAX_ATTACHABLE_FILES);
    const hasFiles = files.length > 0;
    if (!conversationId || (!body && !hasFiles) || sendingByConversation.value[conversationId]) {
        return;
    }

    const conversation = conversationMap.value.get(conversationId);
    setSending(conversationId, true);
    const sendAsInternalNote = isNoteMode(conversationId) || (!!conversation && isConversationClosedLike(conversation));
    const previousBody = String(composerByConversation.value[conversationId] || "");
    const previousFiles = [...files];

    try {
        setComposerValue(conversationId, "");
        clearPanelSelectedFiles(conversationId);

        let response;
        if (hasFiles) {
            const formData = new FormData();
            formData.append("body", body);
            formData.append("is_private_note", sendAsInternalNote ? "1" : "0");
            for (const file of files) {
                formData.append("files[]", file);
            }

            response = await api.post(`/api/support/chats/${conversationId}/agent-messages`, formData, {
                headers: {
                    "Content-Type": "multipart/form-data",
                },
            });
        } else {
            response = await api.post(`/api/support/chats/${conversationId}/agent-messages`, {
                body,
                is_private_note: sendAsInternalNote,
            });
        }

        applyRealtimeMeta(response?.data?.meta);

        const confirmedMessage = extractMessagePayload(response.data);
        const conversationPayload = extractConversationPayload(response.data);

        if (conversationPayload) {
            upsertConversationInList(conversationPayload, { moveToTop: true });
        }

        if (confirmedMessage?.id) {
            upsertIncomingMessage(conversationId, confirmedMessage);
            patchConversationFromMessage(conversationId, confirmedMessage);
            await scrollPanelToBottom(conversationId, false);
        } else {
            await loadConversationMessages(conversationId, {
                withLoading: false,
                autoScroll: true,
                mergeLatest: true,
                trackUnread: false,
            });
        }
    } catch (error: any) {
        const message = error?.response?.data?.message || "Failed to send message.";
        toast.error(message);
        setComposerValue(conversationId, previousBody);
        if (previousFiles.length > 0) {
            setPanelSelectedFiles(conversationId, previousFiles);
        }
    } finally {
        setSending(conversationId, false);
        await focusPanelComposer(conversationId);
    }
}

async function acceptAssignment(conversationId: string): Promise<void> {
    if (!conversationId || acceptingByConversation.value[conversationId]) return;

    acceptingByConversation.value[conversationId] = true;
    try {
        const response = await api.post(`/api/support/chats/${conversationId}/accept`);
        const conversationPayload = extractConversationPayload(response.data);
        if (conversationPayload) {
            upsertConversationInList(conversationPayload, { moveToTop: true });
        }
        pendingAssignments.value = pendingAssignments.value.filter(c => c.id !== conversationId);
        
        // Open the conversation if not already open
        if (!openConversationIds.value.includes(conversationId)) {
            openConversationIds.value = [conversationId, ...openConversationIds.value.slice(0, maxOpenPanels.value - 1)];
        }
        setFocusedConversation(conversationId);
        toast.success("Assignment accepted.");
    } catch (error: any) {
        toast.error(error?.response?.data?.message || "Failed to accept assignment.");
    } finally {
        acceptingByConversation.value[conversationId] = false;
    }
}

async function rejectAssignment(conversationId: string): Promise<void> {
    if (!conversationId || rejectingByConversation.value[conversationId]) return;

    rejectingByConversation.value[conversationId] = true;
    try {
        await api.post(`/api/support/chats/${conversationId}/reject`, {
            reason: "Agent declined via UI"
        });
        pendingAssignments.value = pendingAssignments.value.filter(c => c.id !== conversationId);
        toast.info("Assignment declined.");
    } catch (error: any) {
        toast.error(error?.response?.data?.message || "Failed to decline assignment.");
    } finally {
        rejectingByConversation.value[conversationId] = false;
    }
}

async function handleAutoRejectAssignment(conversationId: string): Promise<void> {
    if (!conversationId) return;
    
    try {
        await api.post(`/api/support/chats/${conversationId}/reject`, {
            reason: "Auto-rejected due to inactivity (60s timeout)"
        });
    } catch (e) {
        // Ignore errors for auto-rejection (might have been accepted/rejected elsewhere)
    }
    
    pendingAssignments.value = pendingAssignments.value.filter(c => c.id !== conversationId);
    
    // Change agent status to unavailable as requested
    if (authStore.user?.support_status !== 'unavailable') {
        await authStore.updateSupportPresence('unavailable');
        toast.warning("Chat offer expired. Your status has been set to Unavailable.");
    }
}

function openTransferModal(conversationId: string): void {
    if (!conversationId) return;
    const conversation = conversationMap.value.get(conversationId);
    if (!conversation || String(conversation.status || "").toLowerCase() === "wrap_up") {
        toast.info("Transfer unavailable", "Wrap-up conversations cannot be transferred.");
        return;
    }
    transferConversationId.value = conversationId;
    isTransferModalOpen.value = true;
}

function handleTransferSuccess(conversationId: string): void {
    // Close the panel as it's no longer assigned to this agent
    openConversationIds.value = openConversationIds.value.filter(id => id !== conversationId);
    if (focusedConversationId.value === conversationId) {
        focusedConversationId.value = openConversationIds.value[0] || null;
    }
    
    // Also remove from inbox list if it was there (silent refresh will handle it but this is faster)
    conversations.value = conversations.value.filter(c => c.id !== conversationId);
}

async function completeWrapUp(conversationId: string): Promise<void> {
    if (!conversationId || completingWrapUpByConversation.value[conversationId]) return;

    completingWrapUpByConversation.value[conversationId] = true;
    try {
        const conversation = conversationMap.value.get(conversationId);
        const resolved = conversation ? isCloseResolved(conversation) : false;
        const response = await api.post(`/api/support/chats/${conversationId}/wrap-up/complete`, {
            resolved,
        });
        applyRealtimeMeta(response?.data?.meta);
        const conversationPayload = extractConversationPayload(response.data);
        if (conversationPayload) {
            upsertConversationInList(conversationPayload, { moveToTop: true });
        }
        
        // Remove from open panels
        openConversationIds.value = openConversationIds.value.filter(id => id !== conversationId);
        if (focusedConversationId.value === conversationId) {
            focusedConversationId.value = openConversationIds.value[0] || null;
        }

        const nextCloseResolved = { ...closeResolvedByConversation.value };
        delete nextCloseResolved[conversationId];
        closeResolvedByConversation.value = nextCloseResolved;

        toast.success("Closed", resolved ? "Conversation closed as resolved." : "Conversation closed as unresolved.");
    } catch (error: any) {
        toast.error(error?.response?.data?.message || "Failed to close conversation.");
    } finally {
        completingWrapUpByConversation.value[conversationId] = false;
    }
}

async function handleComposerKeydown(event: KeyboardEvent, conversationId: string): Promise<void> {
    if (event.key === "Enter" && !event.shiftKey) {
        event.preventDefault();
        await sendPanelMessage(conversationId);
        return;
    }

    if (!event.metaKey && !event.ctrlKey) {
        return;
    }

    const key = String(event.key || "").toLowerCase();
    if (key === "b") {
        event.preventDefault();
        applyPanelInlineFormat(conversationId, "**", "**", "bold text");
        return;
    }
    if (key === "u") {
        event.preventDefault();
        applyPanelInlineFormat(conversationId, "<u>", "</u>", "underlined text");
        return;
    }
    if (key === "k") {
        event.preventDefault();
        insertPanelLink(conversationId);
    }
}

function handleEchoConnected(): void {
    supportLogger.info("realtime.echo.connected", "Received echo:connected for support workbench.");
    void subscribeSupportRealtime();
}

watch(maxOpenPanels, () => {
    ensureOpenConversations();
    void subscribeSupportRealtime();
});

watch(
    openConversationIds,
    async (ids) => {
        if (!focusedConversationId.value || !ids.includes(focusedConversationId.value)) {
            if (ids[0]) {
                applyFocusedConversation(ids[0]);
            } else {
                setFocusedConversation(null);
            }
        }
        await Promise.all(ids.map((id) => ensureConversationMessages(id)));
        await subscribeSupportRealtime();
    },
    { deep: false },
);

watch(conversations, (rows) => {
    if (!focusedConversationId.value) {
        const nextId = openConversationIds.value[0] || rows[0]?.id || null;
        if (nextId) {
            applyFocusedConversation(nextId);
        }
        return;
    }

    if (!rows.some((conversation) => conversation.id === focusedConversationId.value)) {
        const nextId = openConversationIds.value[0] || rows[0]?.id || null;
        if (nextId) {
            applyFocusedConversation(nextId);
        } else {
            setFocusedConversation(null);
        }
    }
});

watch(liveClockNow, (nowMs) => {
    if (pendingAssignments.value.length === 0) return;
    
    const now = nowMs;
    for (const assignment of [...pendingAssignments.value]) {
        const receivedAt = assignment._receivedAt || now;
        const elapsed = (now - receivedAt) / 1000;
        
        if (elapsed >= 60) {
            void handleAutoRejectAssignment(assignment.id);
        }
    }
});

watch(
    () => pendingAssignments.value.length,
    (count) => {
        if (count > 0) {
            startSupportOfferLoop();
            return;
        }

        stopSupportOfferLoop();
    },
    { immediate: true },
);

onMounted(async () => {
    startLiveCounterTicker();
    await loadConversations();

    document.addEventListener("pointerdown", handleWorkbenchGlobalPointerDown);
    window.addEventListener("echo:connected", handleEchoConnected);
});

onBeforeUnmount(() => {
    if (realtimeSubscriptionRetryTimer) {
        clearTimeout(realtimeSubscriptionRetryTimer);
        realtimeSubscriptionRetryTimer = null;
    }

    if (inboxReloadDebounceTimer) {
        clearTimeout(inboxReloadDebounceTimer);
        inboxReloadDebounceTimer = null;
    }

    if (detailsFocusThrottleTimer) {
        clearTimeout(detailsFocusThrottleTimer);
        detailsFocusThrottleTimer = null;
    }

    if (detailsSkeletonTimer) {
        clearTimeout(detailsSkeletonTimer);
        detailsSkeletonTimer = null;
    }

    stopLiveCounterTicker();
    stopSupportOfferLoop();

    for (const picker of panelEmojiPickerInstances.values()) {
        if (picker?.parentNode) {
            picker.parentNode.removeChild(picker);
        }
    }
    panelEmojiPickerInstances.clear();
    openEmojiPickerConversationId.value = null;

    document.removeEventListener("pointerdown", handleWorkbenchGlobalPointerDown);
    window.removeEventListener("echo:connected", handleEchoConnected);
    clearRealtimeSubscriptions();
});
</script>

<template>
    <div class="flex h-screen min-h-0 bg-[radial-gradient(circle_at_top_left,rgba(56,189,248,0.08),transparent_32%),radial-gradient(circle_at_bottom_right,rgba(16,185,129,0.08),transparent_26%),var(--surface-primary)]">
        <!-- Persistent Left Sidebar -->
        <aside
            class="hidden min-h-0 shrink-0 flex-col border-r border-(--border-muted) bg-(--surface-secondary)/15 transition-all duration-300 ease-in-out lg:flex"
            :class="isLeftSidebarExpanded ? 'w-[18.4rem]' : 'w-16'"
            @mouseenter="handleLeftSidebarMouseEnter"
            @mouseleave="handleLeftSidebarMouseLeave"
        >
            <!-- Sidebar Top: Presence & Identity -->
            <div class="flex shrink-0 items-center border-b border-(--border-muted)/50" :class="!isLeftSidebarExpanded ? 'p-2 justify-center' : 'p-2'">
                <SupportStatusSelector
                    :collapsed="!isLeftSidebarExpanded"
                    :active-chats-count="activeWorkingChatsCount"
                    :working-since-at="oldestWorkingChatStartedAt"
                />
            </div>

            <!-- Sidebar Middle: Core Actions & Sync Status -->
            <div class="flex min-h-0 flex-1 flex-col items-center gap-0 overflow-y-auto py-0">
                <!-- Syncing Indicator -->
                <div 
                    v-if="isLoadingList" 
                    class="flex items-center justify-center rounded-lg border border-(--border-default) bg-(--surface-primary) transition-all px-3 py-2"
                    :class="!isLeftSidebarExpanded ? 'w-10 h-10 p-0' : 'w-full mx-3 gap-2 h-10'"
                    title="Syncing..."
                >
                    <Loader2 class="h-4 w-4 animate-spin text-(--text-secondary)" />
                    <span v-if="isLeftSidebarExpanded" class="text-xs font-semibold text-(--text-secondary)">Loading</span>
                </div>


                <!-- Details Content Section (only when expanded) -->
                <div v-if="isLeftSidebarExpanded" class="flex w-full flex-1 flex-col px-2.5 pt-1.5 overflow-y-auto">
                    <div v-if="!focusedConversationDisplay" class="rounded-xl border border-dashed border-(--border-muted) p-6 text-center">
                        <MessageSquare class="mx-auto h-8 w-8 text-(--text-muted)/50" />
                        <p class="mt-2 text-xs font-medium text-(--text-muted)">Select a conversation to view detailed insights.</p>
                    </div>

                    <div v-else-if="showDetailsSkeleton" class="space-y-4">
                        <section class="rounded-lg border border-(--border-default) bg-(--surface-primary)/70 p-3">
                            <div class="flex items-start gap-2.5 animate-pulse">
                                <div class="h-10 w-10 rounded-full bg-(--surface-tertiary)"></div>
                                <div class="flex-1 space-y-2">
                                    <div class="h-3 w-2/3 rounded bg-(--surface-tertiary)"></div>
                                    <div class="h-2.5 w-4/5 rounded bg-(--surface-tertiary)"></div>
                                </div>
                            </div>
                        </section>
                    </div>
                    
                    <div v-else class="space-y-4">
                        <section class="relative overflow-hidden rounded-xl border border-(--border-default) bg-(--surface-primary) p-3.5 shadow-sm ring-1 ring-black/5">
                            <!-- Subtle Background Glow -->
                            <div class="absolute -right-8 -top-8 h-24 w-24 rounded-full bg-(--brand)/5 blur-3xl"></div>
                            
                            <div class="relative flex items-center gap-3">
                                <Avatar
                                    :src="focusedConversationDisplay.avatar_url"
                                    :thumb-url="focusedConversationDisplay.avatar_thumb_url"
                                    :alt="focusedConversationDisplay.name"
                                    :fallback="focusedConversationDisplay.name.slice(0, 1).toUpperCase()"
                                    :color="focusedConversationDisplay.avatar_color || 'var(--surface-tertiary)'"
                                    size="lg"
                                    class="h-10 w-10 rounded-lg ring-2 ring-(--surface-primary) shadow-sm"
                                />
                                <div class="min-w-0 flex-1">
                                    <h3 class="truncate text-sm font-bold tracking-tight text-(--text-primary)">
                                        {{ focusedConversationDisplay.name }}
                                    </h3>
                                    <p class="truncate text-xs font-medium text-(--text-secondary) opacity-80">
                                        {{ focusedConversationDisplay.email }}
                                    </p>
                                </div>
                            </div>
                            
                            <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                                <Badge
                                    v-if="shouldShowResolutionBadge(focusedConversationDisplay.status)"
                                    variant="outline"
                                    size="sm"
                                    :class="[statusToneClass(focusedConversationDisplay.status), 'px-1.5 py-0.5 font-bold uppercase tracking-wider text-[9px]']"
                                >
                                    {{ statusDisplayLabel(focusedConversationDisplay.status) }}
                                </Badge>
                                <span class="inline-flex items-center gap-1 rounded-md border border-(--border-muted)/30 bg-(--surface-secondary)/40 px-1.5 py-0.5 text-[9px] font-bold text-(--text-secondary)">
                                    <Hash class="h-2.5 w-2.5 opacity-50" />
                                    {{ focusedConversationDisplay.id.slice(-6) }}
                                </span>
                            </div>
                        </section>

                        <!-- Tabs & Detailed Info -->
                        <section class="flex min-h-0 flex-1 flex-col gap-3">
                            <div class="grid grid-cols-4 gap-1 rounded-lg border border-(--border-default) bg-(--surface-secondary)/20 p-1">
                                <button
                                    v-for="tab in (['overview', 'media', 'links', 'notes'] as WorkbenchDetailTab[])"
                                    :key="tab"
                                    type="button"
                                    class="h-8 rounded-md text-[10px] font-bold capitalize transition-all"
                                    :class="detailsTab === tab 
                                        ? 'bg-(--surface-primary) text-(--text-primary) shadow-sm' 
                                        : 'text-(--text-secondary) hover:bg-(--surface-secondary)'"
                                    @click="detailsTab = tab"
                                >
                                    {{ tab }}
                                    <span v-if="tab === 'media' && focusedConversationAttachmentItems.length > 0" class="ml-1 opacity-60">
                                        {{ focusedConversationAttachmentItems.length }}
                                    </span>
                                    <span v-else-if="tab === 'notes' && focusedConversationNoteItems.length > 0" class="ml-1 opacity-60">
                                        {{ focusedConversationNoteItems.length }}
                                    </span>
                                </button>
                            </div>

                            <div class="flex-1 overflow-y-auto pr-1">
                                <div v-if="detailsTab === 'overview'" class="space-y-4">
                                    <!-- Metadata Grid -->
                                    <div class="grid grid-cols-1 gap-2">
                                        <div v-for="item in [
                                            { icon: MapPin, label: 'Location', value: focusedConversationDisplay.location, color: 'text-blue-500' },
                                            { icon: Globe, label: 'Browser', value: focusedConversationDisplay.browser, color: 'text-emerald-500' },
                                            { icon: Hash, label: 'IP Address', value: focusedConversationDisplay.ip, color: 'text-purple-500' },
                                            { icon: Monitor, label: 'Source URL', value: focusedConversationDisplay.page, color: 'text-orange-500' },
                                            { icon: User, label: 'Skill', value: focusedConversationDisplay.skill, color: 'text-pink-500' }
                                        ]" :key="item.label" class="group flex items-center gap-3 rounded-xl border border-(--border-muted)/30 bg-(--surface-secondary)/10 p-2.5 transition-all hover:border-(--border-default) hover:bg-(--surface-secondary)/20">
                                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-(--surface-primary) shadow-sm ring-1 ring-(--border-muted)/20 transition-transform group-hover:scale-105" :class="item.color">
                                                <component :is="item.icon" class="h-4 w-4" />
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-[10px] font-bold uppercase tracking-wider text-(--text-muted)/80">{{ item.label }}</p>
                                                <p class="truncate text-xs font-semibold text-(--text-primary)">{{ item.value }}</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Quick Links Section -->
                                    <div class="mt-2 space-y-2 border-t border-(--border-muted)/30 pt-4">
                                        <h4 class="px-1 text-[10px] font-bold uppercase tracking-widest text-(--text-muted)">Quick Links</h4>
                                        <div class="grid grid-cols-1 gap-1.5">
                                            <a 
                                                v-for="link in [
                                                    { label: 'Knowledge Base', icon: Link2, url: '#' },
                                                    { label: 'Customer History', icon: User, url: '#' },
                                                    { label: 'Internal Wiki', icon: Globe, url: '#' }
                                                ]"
                                                :key="link.label"
                                                href="#"
                                                class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-xs font-medium text-(--text-secondary) transition-all hover:bg-(--surface-secondary)/50 hover:text-(--text-primary)"
                                            >
                                                <component :is="link.icon" class="h-3.5 w-3.5 text-(--text-muted)" />
                                                {{ link.label }}
                                            </a>
                                        </div>
                                    </div>

                                    <!-- Duration Meta -->
                                    <div class="rounded-xl bg-(--surface-secondary)/10 p-3 text-[10px] text-(--text-muted)">
                                        <div class="flex items-center justify-between">
                                            <span>Current Duration</span>
                                            <span class="font-bold text-(--text-primary)">{{ conversationDurationLabel(focusedConversation || null) }}</span>
                                        </div>
                                        <div class="mt-1.5 flex items-center justify-between opacity-80">
                                            <span>Communication Started</span>
                                            <span>{{ formatRelativeTime(focusedConversationDisplay.created_at) }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div v-else-if="detailsTab === 'media'" class="space-y-2">
                                    <div v-if="focusedConversationAttachmentItems.length === 0" class="rounded-xl border border-dashed border-(--border-muted) p-6 text-center">
                                         <ImageIcon class="mx-auto h-6 w-6 text-(--text-muted)/40" />
                                         <p class="mt-2 text-[11px] text-(--text-muted)">No media found.</p>
                                    </div>
                                    <button
                                        v-for="attachment in focusedConversationAttachmentItems"
                                        :key="attachment._key"
                                        type="button"
                                        class="group flex w-full items-center gap-3 rounded-lg border border-(--border-default)/70 bg-(--surface-secondary)/20 p-2 text-left transition-all hover:bg-(--surface-secondary)/50"
                                        @click="openAttachmentFromSidebar(attachment)"
                                    >
                                        <div class="h-10 w-10 shrink-0 overflow-hidden rounded-md border border-(--border-default) bg-(--surface-primary)">
                                            <img v-if="isImageAttachment(attachment) || isVideoAttachment(attachment)" 
                                                 :src="attachment.thumb_url || attachment.url" 
                                                 class="h-full w-full object-cover" />
                                            <div v-else class="flex h-full w-full items-center justify-center bg-(--surface-tertiary) text-[10px] font-bold text-(--text-muted)">
                                                {{ getAttachmentTypeLabel(attachment) }}
                                            </div>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-[11px] font-bold text-(--text-primary)">{{ attachment.name }}</p>
                                            <p class="text-[10px] text-(--text-secondary)">{{ formatAttachmentSize(attachment.size) }}</p>
                                        </div>
                                    </button>
                                </div>

                                <div v-else-if="detailsTab === 'notes'" class="space-y-2">
                                    <div
                                        v-if="focusedConversationNoteItems.length === 0"
                                        class="rounded-xl border border-dashed border-(--border-muted) p-6 text-center"
                                    >
                                        <AlertCircle class="mx-auto h-6 w-6 text-(--text-muted)/40" />
                                        <p class="mt-2 text-[11px] text-(--text-muted)">No internal notes yet.</p>
                                    </div>
                                    <article
                                        v-for="note in focusedConversationNoteItems"
                                        :key="note.id"
                                        class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2.5"
                                    >
                                        <div class="flex items-center gap-2">
                                            <Avatar
                                                :src="note.sender?.avatar_url"
                                                :thumb-url="note.sender?.avatar_thumb_url"
                                                :alt="note.sender?.name || 'Agent'"
                                                :fallback="(note.sender?.name || 'A').charAt(0)"
                                                :color="note.sender?.avatar_color || 'var(--surface-tertiary)'"
                                                size="xs"
                                            />
                                            <div class="min-w-0">
                                                <p class="truncate text-[11px] font-semibold text-(--text-primary)">{{ note.sender?.name || 'Agent' }}</p>
                                                <p class="text-[10px] text-(--text-muted)">{{ formatRelativeTime(note.created_at) }}</p>
                                            </div>
                                        </div>
                                        <div class="mt-2 break-words text-[11px] text-(--text-primary)">
                                            <div v-if="messageHasBody(note)" class="support-rich-content" v-html="messageHtml(note)"></div>
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
                            </div>
                        </section>
                    </div>
                </div>
            </div>

            <!-- Sidebar Footer: Collapse Toggle -->
            <div class="border-t border-(--border-muted)/50 p-2">
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-10 w-full justify-center text-(--text-muted) hover:bg-(--surface-tertiary) hover:text-(--text-primary)"
                    @click="leftSidebarCollapsed = !leftSidebarCollapsed"
                >
                    <ChevronLeft v-if="!leftSidebarCollapsed" class="h-5 w-5" />
                    <ChevronRight v-else class="h-5 w-5" />
                    <span v-if="isLeftSidebarExpanded" class="ml-2 text-xs font-bold">
                        {{ leftSidebarCollapsed ? 'Expand Sidebar' : 'Collapse Sidebar' }}
                    </span>
                </Button>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="flex min-w-0 flex-1 flex-col overflow-hidden p-3 pt-3">
                <section class="min-h-0 flex-1 overflow-hidden">
                    <div v-if="openConversations.length === 0" class="flex h-full items-center justify-center">
                        <Card class="w-full max-w-md border border-[var(--border-muted)] bg-[var(--surface-primary)]/88 p-8 text-center shadow-sm backdrop-blur">
                            <MessageSquare class="mx-auto h-10 w-10 text-[var(--text-muted)]" />
                            <p class="mt-3 text-base font-medium text-[var(--text-primary)]">
                                No live panels yet
                            </p>
                            <p class="mt-1 text-sm text-[var(--text-secondary)]">
                                Assigned conversations will appear here automatically.
                            </p>
                        </Card>
                    </div>

                    <div v-else :class="panelGridScrollerClass">
                        <div :class="panelGridClass">
                            <Card
                                v-for="conversation in openConversations"
                                :key="conversation.id"
                                padding="none"
                                class="flex h-full min-h-0 shrink-0 flex-1 flex-col overflow-hidden border bg-[var(--surface-secondary)]/92 backdrop-blur"
                                :class="[
                                    isFocusedConversation(conversation.id)
                                        ? 'border-[var(--border-default)] shadow-[0_16px_32px_-24px_rgba(15,23,42,0.6),inset_0_0_0_1px_var(--border-default)]'
                                        : 'border-[var(--border-muted)]',
                                    'min-w-0 rounded-[1.35rem]'
                                ]"
                            >
                            <header
                                class="relative overflow-hidden border-b border-[var(--border-muted)]"
                                :class="isDenseGrid ? 'px-1.5 py-1' : 'px-2 py-1.5'"
                            >
                                <div class="absolute inset-0 bg-gradient-to-br" :class="conversationHeaderGradientClass(conversation)"></div>
                                <div class="absolute -right-6 top-0 h-16 w-16 rounded-full bg-white/30 blur-3xl"></div>

                                <div class="relative flex items-start justify-between gap-1.5">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <Avatar
                                            :src="conversation.requester?.avatar_url || undefined"
                                            :thumb-url="conversation.requester?.avatar_thumb_url || undefined"
                                            :alt="customerName(conversation)"
                                            :fallback="customerName(conversation).slice(0, 1).toUpperCase()"
                                            :color="conversation.requester?.avatar_color || 'var(--surface-tertiary)'"
                                            size="sm"
                                        />
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold text-[var(--text-primary)]" :class="isDenseGrid ? 'text-[13px]' : 'text-[13px]'">
                                                {{ customerName(conversation) }}
                                            </p>
                                            <p class="truncate text-[var(--text-secondary)]/90" :class="isDenseGrid ? 'text-[10px]' : 'text-[10px]'">
                                                {{ conversation.requester?.email || conversation.guest_email || "No email" }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-1.5">
                                        <Badge
                                            v-if="shouldShowResolutionBadge(conversation.status)"
                                            variant="outline"
                                            size="sm"
                                            :class="[statusToneClass(conversation.status), 'px-1.5 py-0 text-[10px]']"
                                        >
                                            {{ statusDisplayLabel(conversation.status) }}
                                        </Badge>

                                        <Dropdown>
                                            <template #trigger>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    class="h-7 gap-1.5 px-2 text-[10px]"
                                                    :disabled="
                                                        sendingByConversation[conversation.id] ||
                                                        assigningByConversation[conversation.id] ||
                                                        endingByConversation[conversation.id]
                                                    "
                                                >
                                                    <MoreVertical class="h-3.5 w-3.5" />
                                                    <span v-if="!isDenseGrid">Actions</span>
                                                </Button>
                                            </template>

                                            <DropdownItem @select="refreshConversationPanel(conversation.id)">
                                                Refresh conversation
                                            </DropdownItem>
                                            <DropdownItem
                                                v-if="canAssignConversation(conversation.id) && !isAssignedToCurrentUser(conversation.id)"
                                                :disabled="assigningByConversation[conversation.id]"
                                                @select="assignConversationToCurrentUser(conversation.id)"
                                            >
                                                {{ assigningByConversation[conversation.id] ? "Assigning..." : "Assign to me" }}
                                            </DropdownItem>
                                            <DropdownItem v-else-if="isAssignedToCurrentUser(conversation.id)" disabled>
                                                Already assigned to you
                                            </DropdownItem>
                                            <DropdownItem v-else disabled>
                                                Assignment unavailable
                                            </DropdownItem>
                                            <DropdownSeparator />
                                            <DropdownItem
                                                v-if="canEndConversation(conversation.id)"
                                                :disabled="endingByConversation[conversation.id]"
                                                destructive
                                                @select="endConversation(conversation.id)"
                                            >
                                                {{ endingByConversation[conversation.id] ? "Ending..." : "End conversation" }}
                                            </DropdownItem>
                                            <DropdownItem v-else disabled>
                                                End unavailable
                                            </DropdownItem>
                                            <DropdownSeparator v-if="isAssignedToCurrentUser(conversation.id)" />
                                            <DropdownItem
                                                v-if="isAssignedToCurrentUser(conversation.id) && conversation.status !== 'wrap_up'"
                                                @select="openTransferModal(conversation.id)"
                                            >
                                                Transfer chat
                                            </DropdownItem>
                                        </Dropdown>
                                    </div>
                                </div>

                                <div class="relative mt-1.5 space-y-1.5">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span
                                            v-if="String(conversation.status || '').toLowerCase() !== 'assigned'"
                                            class="inline-flex items-center gap-1.5 rounded-full border border-[var(--border-default)] bg-[var(--surface-primary)]/80 px-1.5 py-0.5 text-[9px] font-medium text-[var(--text-primary)] backdrop-blur"
                                        >
                                            <span class="h-2 w-2 rounded-full" :class="statusDotClass(conversation.status)"></span>
                                            {{ statusDisplayLabel(conversation.status) }}
                                        </span>
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-[var(--border-default)] bg-[var(--surface-primary)]/80 px-1.5 py-0.5 text-[9px] font-medium text-[var(--text-primary)] backdrop-blur">
                                            <Clock class="h-3 w-3 text-[var(--text-muted)]" />
                                            Dur {{ conversationDurationLabel(conversation) }}
                                        </span>
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full border px-1.5 py-0.5 text-[9px] font-medium backdrop-blur"
                                            :class="responseGapBadgeClass(conversation.id)"
                                        >
                                            {{ secondaryTimerPrefix(conversation.id) }} {{ responseGapLabel(conversation.id) }}
                                        </span>
                                    </div>
                                </div>
                            </header>

                            <div
                                class="min-h-0 flex-1 overflow-y-auto bg-[var(--surface-primary)]"
                                :class="isDenseGrid ? 'px-1.5 py-1.5' : 'px-2 py-2'"
                                :ref="(el) => setPanelMessagesRef(conversation.id, el)"
                                @scroll="handlePanelScroll(conversation.id)"
                            >
                                <div
                                    v-if="isLoadingOlderMessages(conversation.id)"
                                    class="flex items-center justify-center py-2 text-[10px] text-[var(--text-secondary)]"
                                >
                                    <Loader2 class="mr-1.5 h-3 w-3 animate-spin" />
                                    Loading older messages...
                                </div>

                                <div
                                    v-if="loadingMessagesByConversation[conversation.id]"
                                    class="flex items-center justify-center py-8 text-xs text-[var(--text-secondary)]"
                                >
                                    <Loader2 class="mr-2 h-3.5 w-3.5 animate-spin" />
                                    Loading messages...
                                </div>

                                <div
                                    v-else-if="panelVisibleMessages(conversation.id).length === 0"
                                    class="flex items-center justify-center py-8 text-xs text-[var(--text-secondary)]"
                                >
                                    No customer-visible messages yet.
                                </div>

                                <div v-else class="flex min-h-full flex-col justify-end">
                                    <div :class="isDenseGrid ? 'space-y-1' : 'space-y-1.5'">
                                        <div
                                            v-for="message in panelVisibleMessages(conversation.id)"
                                            :key="message.id"
                                            :id="`support-workbench-message-${conversation.id}-${message.id}`"
                                            class="flex"
                                            :class="messageRowClass(message)"
                                        >
                                            <div :class="[isDenseGrid ? 'w-fit max-w-[80%]' : 'w-fit max-w-[72%]', messageBlockClass(message)]">
                                                <div :class="messageBubbleClass(message)">
                                                    <div
                                                        v-if="messageHasBody(message)"
                                                        class="support-rich-content break-words text-left"
                                                        v-html="messageHtml(message)"
                                                    ></div>
                                                    <SupportMessageAttachments
                                                        v-if="Array.isArray(message.attachments) && message.attachments.length > 0"
                                                        class="mt-2"
                                                        :attachments="message.attachments"
                                                        :tone="String(message.sender_type || '').toLowerCase() === 'customer'
                                                            ? 'theirs'
                                                            : message.is_private_note
                                                                ? 'note'
                                                                : 'mine'"
                                                    />
                                                </div>

                                                <p class="mt-1 text-[10px] text-[var(--text-muted)] leading-tight" :class="messageMetaClass(message)">
                                                    {{ formatTime(message.created_at) }} · {{ senderLabel(message) }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    v-if="shouldShowPanelJumpToLatest(conversation.id)"
                                    class="sticky bottom-3 z-20 flex justify-center pointer-events-none"
                                >
                                    <button
                                        type="button"
                                        class="pointer-events-auto relative inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-900 bg-slate-900 text-white shadow-sm transition-colors hover:bg-slate-800 dark:border-slate-200 dark:bg-slate-200 dark:text-slate-900 dark:hover:bg-slate-300"
                                        :title="panelUnreadCount(conversation.id) > 0 ? 'Jump to first unread message' : 'Jump to latest message'"
                                        @click="handlePanelJumpToLatest(conversation.id)"
                                    >
                                        <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4" aria-hidden="true">
                                            <path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                        <span
                                            v-if="panelUnreadCount(conversation.id) > 0"
                                            class="absolute -right-1 -top-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-[var(--interactive-primary)] px-1 text-[9px] font-semibold leading-none text-white"
                                        >
                                            {{ panelUnreadCount(conversation.id) > 99 ? "99+" : panelUnreadCount(conversation.id) }}
                                        </span>
                                    </button>
                                </div>
                            </div>

                            <footer
                                class="border-t border-[var(--border-muted)] bg-[var(--surface-secondary)]/60"
                                :class="isDenseGrid ? 'p-1' : 'p-1.5'"
                            >
                                <div class="space-y-1.5" @click.stop>
                                    <div v-if="isConversationClosedLike(conversation)" class="px-1 py-0.5 text-xs text-[var(--text-secondary)] italic">
                                        {{ closedConversationNoticeLabel(conversation.status) }}
                                    </div>
                                    <div class="flex items-center justify-between gap-2 px-0.5" :class="isDenseGrid ? 'pb-0.5' : ''">
                                        <div class="flex items-center gap-1">
                                            <template v-if="!isConversationClosedLike(conversation)">
                                                <button
                                                    type="button"
                                                    class="rounded-full font-semibold transition-colors"
                                                    :class="[
                                                        isDenseGrid ? 'px-1.5 py-0.5 text-[9px]' : 'px-2 py-0.5 text-[9px]',
                                                        !isNoteMode(conversation.id)
                                                            ? 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]'
                                                            : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]'
                                                    ]"
                                                    :disabled="sendingByConversation[conversation.id]"
                                                    @click="setNoteMode(conversation.id, false)"
                                                >
                                                    Reply
                                                </button>
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 rounded-full font-semibold transition-colors"
                                                    :class="[
                                                        isDenseGrid ? 'px-1.5 py-0.5 text-[9px]' : 'px-2 py-0.5 text-[9px]',
                                                        isNoteMode(conversation.id)
                                                            ? 'bg-amber-500/10 text-amber-600 dark:text-amber-300'
                                                            : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]'
                                                    ]"
                                                    :disabled="sendingByConversation[conversation.id]"
                                                    @click="setNoteMode(conversation.id, true)"
                                                >
                                                    <AlertCircle class="h-3 w-3" />
                                                    {{ isDenseGrid ? "Note" : "Internal Note" }}
                                                </button>
                                            </template>
                                            <span
                                                v-else
                                                class="inline-flex items-center gap-1 rounded-full border border-amber-500/25 bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-300"
                                            >
                                                <AlertCircle class="h-3 w-3" />
                                                Notes only
                                            </span>
                                        </div>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)] hover:text-[var(--text-primary)]"
                                            :disabled="sendingByConversation[conversation.id]"
                                            @click="toggleComposerCollapsed(conversation.id)"
                                        >
                                            <ChevronRight class="h-3 w-3 transition-transform" :class="isComposerCollapsed(conversation.id) ? '' : 'rotate-90'" />
                                            {{ isComposerCollapsed(conversation.id) ? "Expand composer" : "Collapse composer" }}
                                        </button>
                                    </div>

                                    <div
                                        v-if="isComposerCollapsed(conversation.id)"
                                        class="rounded-lg border border-dashed border-[var(--border-default)] bg-[var(--surface-primary)]/70 px-3 py-2 text-xs text-[var(--text-secondary)]"
                                    >
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="truncate">
                                                {{
                                                    (composerByConversation[conversation.id] || '').trim()
                                                        ? `Draft: ${(composerByConversation[conversation.id] || '').trim().slice(0, 72)}`
                                                        : 'Composer collapsed'
                                                }}
                                            </span>
                                            <span v-if="panelSelectedFiles(conversation.id).length > 0" class="shrink-0 text-[var(--text-muted)]">
                                                {{ panelSelectedFiles(conversation.id).length }} file<span v-if="panelSelectedFiles(conversation.id).length !== 1">s</span>
                                            </span>
                                        </div>
                                    </div>

                                    <div
                                        v-else
                                        class="rounded-lg border"
                                        :class="(isNoteMode(conversation.id) || isConversationClosedLike(conversation))
                                            ? 'border-amber-500/30 bg-amber-500/5'
                                            : 'border-[var(--border-default)] bg-[var(--surface-primary)]'"
                                    >
                                        <input
                                            :ref="(el) => setPanelFileInputRef(conversation.id, el)"
                                            type="file"
                                            class="hidden"
                                            multiple
                                            @change="handlePanelFileSelection(conversation.id, $event)"
                                        />

                                        <div
                                            v-if="isNoteMode(conversation.id) || isConversationClosedLike(conversation)"
                                            class="flex items-center justify-between gap-2 border-b border-amber-500/20 bg-amber-500/8 px-2 py-1 text-[10px] font-medium text-amber-700 dark:text-amber-300"
                                        >
                                            <span class="inline-flex items-center gap-1">
                                                <AlertCircle class="h-3 w-3" />
                                                Internal note. Only teammates can see this.
                                            </span>
                                        </div>

                                        <div
                                            v-if="panelSelectedFiles(conversation.id).length > 0"
                                            class="border-b border-[var(--border-default)]/60"
                                            :class="isDenseGrid ? 'px-1 py-1' : 'px-1.5 py-1.5'"
                                        >
                                            <div class="flex flex-wrap gap-1">
                                                <span
                                                    v-for="(file, fileIndex) in panelSelectedFiles(conversation.id)"
                                                    :key="`${conversation.id}-file-${file.name}-${file.lastModified}-${fileIndex}`"
                                                    class="inline-flex max-w-full items-center gap-1 rounded-md border border-[var(--border-default)] bg-[var(--surface-secondary)]/60 px-1.5 py-0.5 text-[10px] text-[var(--text-secondary)]"
                                                >
                                                    <span class="truncate max-w-[120px]">{{ file.name }}</span>
                                                    <button
                                                        type="button"
                                                        class="rounded p-0.5 text-[var(--text-muted)] transition-colors hover:text-[var(--text-primary)]"
                                                        :disabled="sendingByConversation[conversation.id]"
                                                        @click="removePanelFile(conversation.id, fileIndex)"
                                                    >
                                                        <X class="h-3 w-3" />
                                                    </button>
                                                </span>
                                            </div>
                                        </div>

                                        <div
                                            class="flex items-center gap-1 border-b border-[var(--border-default)]/60"
                                            :class="isDenseGrid ? 'px-1 py-1' : 'px-1.5 py-1.5'"
                                        >
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                class="h-8 w-8 p-0 text-(--text-primary) hover:bg-(--surface-secondary)"
                                                :disabled="sendingByConversation[conversation.id]"
                                                title="Bold"
                                                @mousedown.prevent
                                                @click="applyPanelInlineFormat(conversation.id, '**', '**', 'bold text')"
                                            >
                                                <Bold class="h-4 w-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                class="h-8 w-8 p-0 text-(--text-primary) hover:bg-(--surface-secondary)"
                                                :disabled="sendingByConversation[conversation.id]"
                                                title="Underline"
                                                @mousedown.prevent
                                                @click="applyPanelInlineFormat(conversation.id, '<u>', '</u>', 'underlined text')"
                                            >
                                                <Underline class="h-4 w-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                class="h-8 w-8 p-0 text-(--text-primary) hover:bg-(--surface-secondary)"
                                                :disabled="sendingByConversation[conversation.id]"
                                                title="Bulleted list"
                                                @mousedown.prevent
                                                @click="applyPanelListFormat(conversation.id, 'bullet')"
                                            >
                                                <List class="h-4 w-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                class="h-8 w-8 p-0 text-(--text-primary) hover:bg-(--surface-secondary)"
                                                :disabled="sendingByConversation[conversation.id]"
                                                title="Numbered list"
                                                @mousedown.prevent
                                                @click="applyPanelListFormat(conversation.id, 'numbered')"
                                            >
                                                <ListOrdered class="h-4 w-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                class="h-8 w-8 p-0 text-(--text-primary) hover:bg-(--surface-secondary)"
                                                :disabled="sendingByConversation[conversation.id]"
                                                title="Insert link"
                                                @mousedown.prevent
                                                @click="insertPanelLink(conversation.id)"
                                            >
                                                <Link2 class="h-4 w-4" />
                                            </Button>
                                        </div>

                                        <textarea
                                            :ref="(el) => setPanelComposerRef(conversation.id, el)"
                                            :value="composerByConversation[conversation.id] || ''"
                                            rows="2"
                                            class="w-full resize-none border-none bg-transparent text-[var(--text-primary)] outline-none focus:outline-none focus:ring-0 focus-visible:outline-none focus-visible:ring-0"
                                            :class="isDenseGrid ? 'min-h-[52px] px-2 py-1.5 text-[13px]' : 'min-h-[58px] px-2.5 py-1.5 text-[13px]'"
                                            :placeholder="(isNoteMode(conversation.id) || isConversationClosedLike(conversation)) ? 'Internal note (customer cannot see this)...' : 'Type your message...'"
                                            :disabled="sendingByConversation[conversation.id]"
                                            @focus="focusDetailsFromComposer(conversation.id)"
                                            @input="
                                                setComposerValue(
                                                    conversation.id,
                                                    ($event.target as HTMLTextAreaElement).value,
                                                )
                                            "
                                            @keydown="handleComposerKeydown($event, conversation.id)"
                                        ></textarea>

                                        <div
                                            class="flex items-center justify-between border-t border-[var(--border-default)]/60"
                                            :class="isDenseGrid ? 'px-1.5 py-1' : 'px-2 py-1'"
                                        >
                                            <div class="flex items-center gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-8 w-8 p-0 text-(--text-primary) hover:bg-(--surface-secondary)"
                                                    :disabled="sendingByConversation[conversation.id]"
                                                    title="Attach file"
                                                    @click="openPanelFilePicker(conversation.id)"
                                                >
                                                    <Paperclip class="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-8 w-8 p-0 text-(--text-primary) hover:bg-(--surface-secondary)"
                                                    :disabled="sendingByConversation[conversation.id]"
                                                    title="Insert image"
                                                    @click="openPanelFilePicker(conversation.id)"
                                                >
                                                    <ImageIcon class="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-8 w-8 p-0 text-(--text-primary) hover:bg-(--surface-secondary)"
                                                    :disabled="sendingByConversation[conversation.id]"
                                                    title="Insert emoji"
                                                    :data-workbench-emoji-toggle="conversation.id"
                                                    @click.stop="togglePanelEmojiPicker(conversation.id)"
                                                >
                                                    <Smile class="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    class="font-semibold text-(--text-primary)"
                                                    :class="isDenseGrid ? 'h-8 px-2.5 text-[12px]' : 'h-8 px-2.5 text-[12px]'"
                                                    :disabled="sendingByConversation[conversation.id]"
                                                    title="Macros"
                                                    @click="onMacroFacadeClick()"
                                                >
                                                    <Zap class="mr-1 h-4 w-4" />
                                                    <span>Macros</span>
                                                </Button>
                                            </div>

                                            <Button
                                                size="sm"
                                                class="text-xs"
                                                :class="isDenseGrid ? 'h-9 px-3 text-[13px]' : 'h-9 px-3 text-[13px]'"
                                                :variant="(isNoteMode(conversation.id) || isConversationClosedLike(conversation)) ? 'outline' : 'primary'"
                                                :disabled="
                                                    sendingByConversation[conversation.id] ||
                                                    (
                                                        !(composerByConversation[conversation.id] || '').trim() &&
                                                        panelSelectedFiles(conversation.id).length === 0
                                                    )
                                                "
                                                @click="sendPanelMessage(conversation.id)"
                                            >
                                                <Loader2
                                                    v-if="sendingByConversation[conversation.id]"
                                                    class="mr-1 h-4 w-4 animate-spin"
                                                />
                                                <Send v-else class="mr-1 h-4 w-4" />
                                                <span>{{ (isNoteMode(conversation.id) || isConversationClosedLike(conversation)) ? "Add Note" : "Send" }}</span>
                                            </Button>
                                        </div>

                                        <div
                                            v-if="openEmojiPickerConversationId === conversation.id"
                                            class="relative border-t border-[var(--border-default)]/60 px-1.5 py-1.5"
                                        >
                                            <div
                                                :ref="(el) => setPanelEmojiMountRef(conversation.id, el)"
                                                class="support-workbench-emoji-mount"
                                            ></div>
                                        </div>
                                    </div>
                                    <div
                                        v-if="conversation.status === 'wrap_up'"
                                        class="flex items-center justify-end gap-2 pt-1"
                                    >
                                        <label class="inline-flex items-center gap-1.5 text-[11px] font-medium text-[var(--text-secondary)]">
                                            <input
                                                type="checkbox"
                                                class="h-3.5 w-3.5 rounded border-[var(--border-default)] text-emerald-600 focus:ring-emerald-500"
                                                :checked="isCloseResolved(conversation)"
                                                :disabled="completingWrapUpByConversation[conversation.id]"
                                                @change="setCloseResolved(conversation.id, ($event.target as HTMLInputElement).checked)"
                                            />
                                            Resolved
                                        </label>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            class="h-9 px-3 text-xs font-bold border-emerald-500/50 text-emerald-600 hover:bg-emerald-50"
                                            :disabled="completingWrapUpByConversation[conversation.id]"
                                            @click="completeWrapUp(conversation.id)"
                                        >
                                            <Loader2 v-if="completingWrapUpByConversation[conversation.id]" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                            <Check v-else class="mr-1.5 h-3.5 w-3.5" />
                                            Close Chat
                                        </Button>
                                    </div>
                                </div>
                            </footer>
                        </Card>
                    </div>
                    </div>
                </section>

        </main>
        
        <!-- Assignment Notification Overlay -->
        <TransitionGroup
            tag="div"
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="translate-y-4 opacity-0 scale-95"
            enter-to-class="translate-y-0 opacity-100 scale-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="translate-y-0 opacity-100 scale-100"
            leave-to-class="translate-y-4 opacity-0 scale-95"
            class="fixed bottom-6 left-1/2 z-100 flex -translate-x-1/2 flex-col gap-3 pointer-events-none"
        >
            <div
                v-for="assignment in pendingAssignments"
                :key="assignment.id"
                class="pointer-events-auto flex w-[420px] items-center gap-4 rounded-2xl border border-(--border-strong) bg-(--surface-elevated) p-4 shadow-2xl ring-1 ring-black/10"
            >
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-(--interactive-primary)/10 text-(--interactive-primary)">
                    <MessageSquare class="h-6 w-6" />
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="font-bold text-(--text-primary)">New Chat Assignment</h3>
                    <p class="truncate text-sm text-(--text-secondary)">
                        {{ customerName(assignment) }} is waiting
                        <span v-if="assignment.support_skill" class="text-(--text-muted)">· {{ assignment.support_skill.name }}</span>
                    </p>
                    <div class="mt-2 flex items-center gap-2">
                        <div class="h-1 flex-1 overflow-hidden rounded-full bg-(--surface-tertiary)">
                            <div 
                                class="h-full bg-(--interactive-primary) transition-all duration-300"
                                :style="{ width: `${Math.max(0, Math.min(100, (60 - (liveClockNow - (assignment._receivedAt || Date.now())) / 1000) / 60 * 100))}%` }"
                            />
                        </div>
                        <span class="text-[10px] font-mono font-medium text-(--text-muted) tabular-nums">
                            {{ Math.max(0, Math.ceil(60 - (liveClockNow - (assignment._receivedAt || Date.now())) / 1000)) }}s
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-9 px-3 text-rose-500 hover:bg-rose-500/10 hover:text-rose-600"
                        :disabled="rejectingByConversation[assignment.id]"
                        @click="rejectAssignment(assignment.id)"
                    >
                        <Loader2 v-if="rejectingByConversation[assignment.id]" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                        Decline
                    </Button>
                    <Button
                        variant="primary"
                        size="sm"
                        class="h-9 px-4 font-bold shadow-lg shadow-(--interactive-primary)/20"
                        :disabled="acceptingByConversation[assignment.id]"
                        @click="acceptAssignment(assignment.id)"
                    >
                        <Loader2 v-if="acceptingByConversation[assignment.id]" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                        Accept
                    </Button>
                </div>
            </div>
        </TransitionGroup>

        <!-- Transfer Modal -->
        <SupportTransferModal
            v-model:open="isTransferModalOpen"
            :conversation-id="transferConversationId"
            @success="handleTransferSuccess"
        />
    </div>
</template>

<style scoped>
.support-workbench-emoji-mount {
    display: inline-flex;
    max-width: 100%;
    overflow: hidden;
    border-radius: 12px;
    border: 1px solid color-mix(in srgb, var(--border-default) 88%, transparent);
}

:deep(.support-workbench-emoji-mount em-emoji-picker) {
    width: min(320px, calc(100vw - 48px)) !important;
    height: 300px !important;
    --em-height: 300px;
    --border-radius: 12px;
    --shadow: none !important;
}

:deep(.support-rich-content) {
    display: block;
    width: auto;
    max-width: 100%;
    line-height: 1.45;
    white-space: normal;
    text-align: left !important;
}

:deep(.support-rich-content p) {
    margin: 0.35rem 0;
    white-space: pre-wrap;
    text-align: left !important;
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
    text-align: left;
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
</style>
