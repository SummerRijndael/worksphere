<script setup lang="ts">
import { computed, ref } from "vue";
import { useRouter } from "vue-router";
import type { Chat, ChatInvite, DiscoverablePerson } from "@/types/models/chat";
import StatusSelector from "@/components/ui/StatusSelector.vue";
import { Avatar, Icon } from "@/components/ui";
import { usePresence, getStatusLabel } from "@/composables/usePresence.ts";

interface User {
    id: number;
    public_id: string;
    name: string;
    avatar?: string | null;
}

interface Props {
    chats: Chat[];
    dmChats: Chat[];
    groupChats: Chat[];
    peopleResults: DiscoverablePerson[];
    invites: ChatInvite[];
    activeTab: "chats" | "people" | "invites";
    searchQuery: string;
    peopleSearchQuery: string;
    activeChatId: string | null;
    isLoading: boolean;
    currentUser: User | null;
    isMobile: boolean;
    showSidebar: boolean;
}

const props = defineProps<Props>();
const emit = defineEmits<{
    "update:activeTab": [value: "chats" | "people" | "invites"];
    "update:searchQuery": [value: string];
    "update:peopleSearchQuery": [value: string];
    selectChat: [chatId: string];
    createGroup: [];
    searchPeople: [];
    startDm: [personOrId: DiscoverablePerson | string];
    acceptInvite: [inviteId: string];
    declineInvite: [inviteId: string];
    close: [];
}>();

const router = useRouter();

const tabs = [
    { id: "chats", label: "Chats", icon: "MessageSquare" },
    { id: "people", label: "People", icon: "Users" },
    { id: "invites", label: "Invites", icon: "Mail" },
] as const;

const inviteCount = computed(() => props.invites.length);

const formatLastMessage = (chat: Chat) => {
    if (!chat.last_message) return "";
    const content = chat.last_message.content;
    return content && content.length > 40
        ? content.substring(0, 40) + "..."
        : content || "📎 Attachment";
};

const formatTime = (date: string) => {
    const d = new Date(date);
    const now = new Date();
    const diffDays = Math.floor(
        (now.getTime() - d.getTime()) / (1000 * 60 * 60 * 24)
    );

    if (diffDays === 0)
        return d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    if (diffDays === 1) return "Yesterday";
    if (diffDays < 7) return d.toLocaleDateString([], { weekday: "short" });
    return d.toLocaleDateString([], { month: "short", day: "numeric" });
};

const getOtherParticipant = (chat: Chat) => {
    if (chat.type !== "dm" || !props.currentUser) return null;
    return chat.participants.find(
        (p) => p.public_id !== props.currentUser!.public_id
    );
};

// Presence Integration
const { presenceUsers } = usePresence({ manageLifecycle: false });

// Collapsible Sections
const isDmsOpen = ref(true);
const isGroupsOpen = ref(true);

const onlineUsers = computed(() => {
    return Array.from(presenceUsers.value.values())
        .filter(
            (u) =>
                u.status !== "offline" &&
                u.public_id !== props.currentUser?.public_id
        )
        .sort((a, b) => {
            // Sort by status priority (online > busy > away) then name
            const statusPriority = {
                online: 0,
                busy: 1,
                away: 2,
                invisible: 3,
                offline: 4,
            };
            const statusDiff =
                (statusPriority[a.status] || 4) -
                (statusPriority[b.status] || 4);
            if (statusDiff !== 0) return statusDiff;
            return (a.name || "").localeCompare(b.name || "");
        });
});
// Helper to determine chat presence status
const getChatPresenceStatus = (chat: Chat) => {
    if (chat.type === 'dm') {
        const other = getOtherParticipant(chat);
        if (!other) return 'offline';
        const user = presenceUsers.value.get(other.public_id);
        return user?.status || other.presence_status || 'offline';
    }
    
    // For groups: Online if ANY other participant is online
    if (chat.type === 'group' || chat.type === 'team') {
        const hasOnline = chat.participants.some(p => {
             if (p.public_id === props.currentUser?.public_id) return false;
             // Check real-time status first, then static
             const realtime = presenceUsers.value.get(p.public_id);
             return (realtime?.status === 'online') || (p.presence_status === 'online');
        });
        return hasOnline ? 'online' : 'offline';
    }
    
    return 'offline';
};

</script>

<template>
    <aside
        class="flex flex-col min-h-0 border-r border-[var(--border-default)] bg-[var(--surface-secondary)] transition-transform"
        :class="[
            isMobile
                ? 'fixed inset-0 z-50 w-full max-w-sm h-screen' +
                  (showSidebar ? ' translate-x-0' : ' -translate-x-full')
                : 'w-80 shrink-0 h-full',
        ]"
    >
        <!-- Header -->
        <div class="p-4 border-b border-[var(--border-default)]">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-[var(--text-primary)]">
                    Messages
                </h2>
                <div class="flex items-center gap-2">
                    <button
                        class="p-2 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)]"
                        title="Back to Dashboard"
                        @click="router.push('/dashboard')"
                    >
                        <Icon name="Home" size="18" />
                    </button>
                    <button
                        class="p-2 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)]"
                        title="New group"
                        @click="emit('createGroup')"
                    >
                        <Icon name="Plus" size="18" />
                    </button>
                    <button
                        v-if="isMobile"
                        class="p-2 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)]"
                        @click="emit('close')"
                    >
                        <Icon name="X" size="18" />
                    </button>
                </div>
            </div>

            <!-- Search -->
            <div class="relative">
                <input
                    :value="
                        activeTab === 'people' ? peopleSearchQuery : searchQuery
                    "
                    type="text"
                    :placeholder="
                        activeTab === 'people'
                            ? 'Search people...'
                            : 'Search chats...'
                    "
                    class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-[var(--surface-elevated)] border border-[var(--border-default)] text-[var(--text-primary)] placeholder-[var(--text-muted)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)] focus:border-transparent"
                    @input="e => emit(activeTab === 'people' ? 'update:peopleSearchQuery' : 'update:searchQuery', (e.target as HTMLInputElement).value)"
                    @keydown.enter="
                        activeTab === 'people' && emit('searchPeople')
                    "
                />
                <div
                    class="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)]"
                >
                    <Icon name="Search" size="14" />
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-2 mt-4">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    class="flex-1 px-3 py-2 rounded-xl text-sm font-medium transition-all"
                    :class="
                        activeTab === tab.id
                            ? 'bg-[var(--interactive-primary)] text-white shadow-sm'
                            : 'bg-[var(--surface-tertiary)] text-[var(--text-secondary)] hover:bg-[var(--surface-primary)] border border-[var(--border-default)]'
                    "
                    @click="emit('update:activeTab', tab.id)"
                >
                    <Icon
                        :name="tab.icon"
                        size="16"
                        class="mr-1.5 inline-block"
                    />
                    {{ tab.label }}
                    <span
                        v-if="tab.id === 'invites' && inviteCount > 0"
                        class="ml-1 px-1.5 py-0.5 text-xs rounded-full bg-red-500 text-white"
                    >
                        {{ inviteCount }}
                    </span>
                </button>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-3 space-y-2">
            <!-- Chats Tab -->
            <template v-if="activeTab === 'chats'">
                <div v-if="isLoading" class="flex justify-center py-8">
                    <div
                        class="w-6 h-6 border-2 border-[var(--interactive-primary)] border-t-transparent rounded-full animate-spin"
                    />
                </div>

                <template v-else-if="chats.length">
                    <!-- DM Section -->
                    <div v-if="dmChats.length" class="mb-4">
                        <button
                            class="flex items-center gap-1 w-full text-xs font-semibold uppercase tracking-wider text-[var(--text-tertiary)] px-2 mb-2 hover:text-[var(--text-primary)] transition-colors"
                            @click="isDmsOpen = !isDmsOpen"
                        >
                            <Icon
                                name="ChevronRight"
                                size="12"
                                class="transition-transform duration-200"
                                :class="isDmsOpen ? 'rotate-90' : ''"
                            />
                            Direct Messages
                        </button>
                        <div v-show="isDmsOpen" class="space-y-1">
                            <button
                                v-for="chat in dmChats"
                                :key="chat.public_id"
                                class="w-full flex items-center gap-3 p-3 rounded-2xl transition-all text-left"
                                :class="
                                    activeChatId === chat.public_id
                                        ? 'bg-[var(--surface-tertiary)] border border-[var(--interactive-primary)]'
                                        : 'hover:bg-[var(--surface-tertiary)] border border-transparent'
                                "
                                @click="emit('selectChat', chat.public_id)"
                            >
                                <div class="relative shrink-0">
                                    <Avatar
                                        :src="getOtherParticipant(chat)?.avatar"
                                        :fallback="getOtherParticipant(chat)?.name?.charAt(0) || '?'"
                                        size="md"
                                        :status="getChatPresenceStatus(chat)"
                                        variant="ring"
                                    />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <span
                                            class="font-semibold text-[var(--text-primary)] truncate"
                                            >{{
                                                getOtherParticipant(chat)
                                                    ?.name || "Unknown"
                                            }}</span
                                        >
                                        <span
                                            v-if="chat.last_message"
                                            class="text-xs text-[var(--text-tertiary)]"
                                            >{{
                                                formatTime(
                                                    chat.last_message.created_at
                                                )
                                            }}</span
                                        >
                                    </div>
                                    <div
                                        class="text-sm text-[var(--text-secondary)] truncate"
                                    >
                                        {{ formatLastMessage(chat) }}
                                    </div>
                                </div>
                                <div
                                    v-if="chat.unread_count"
                                    class="shrink-0 w-5 h-5 rounded-full bg-[var(--interactive-primary)] text-white text-xs flex items-center justify-center"
                                >
                                    {{
                                        chat.unread_count > 9
                                            ? "9+"
                                            : chat.unread_count
                                    }}
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Groups Section -->
                    <div v-if="groupChats.length">
                        <button
                            class="flex items-center gap-1 w-full text-xs font-semibold uppercase tracking-wider text-[var(--text-tertiary)] px-2 mb-2 hover:text-[var(--text-primary)] transition-colors"
                            @click="isGroupsOpen = !isGroupsOpen"
                        >
                            <Icon
                                name="ChevronRight"
                                size="12"
                                class="transition-transform duration-200"
                                :class="isGroupsOpen ? 'rotate-90' : ''"
                            />
                            Groups
                        </button>
                        <div v-show="isGroupsOpen" class="space-y-1">
                            <button
                                v-for="chat in groupChats"
                                :key="chat.public_id"
                                class="w-full flex items-center gap-3 p-3 rounded-2xl transition-all text-left"
                                :class="
                                    activeChatId === chat.public_id
                                        ? 'bg-[var(--surface-tertiary)] border border-[var(--interactive-primary)]'
                                        : 'hover:bg-[var(--surface-tertiary)] border border-transparent'
                                "
                                @click="emit('selectChat', chat.public_id)"
                            >
                                <div class="relative shrink-0">
                                    <Avatar
                                        :fallback="chat.name?.charAt(0) || 'G'"
                                        size="md"
                                        :status="getChatPresenceStatus(chat)"
                                        variant="ring"
                                    />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <span
                                            class="font-semibold text-[var(--text-primary)] truncate"
                                            >{{ chat.name || "Group" }}</span
                                        >
                                        <span
                                            v-if="chat.last_message"
                                            class="text-xs text-[var(--text-tertiary)]"
                                            >{{
                                                formatTime(
                                                    chat.last_message.created_at
                                                )
                                            }}</span
                                        >
                                    </div>
                                    <div
                                        class="text-sm text-[var(--text-secondary)] truncate"
                                    >
                                        {{ formatLastMessage(chat) }}
                                    </div>
                                </div>
                                <div
                                    v-if="chat.unread_count"
                                    class="shrink-0 w-5 h-5 rounded-full bg-[var(--interactive-primary)] text-white text-xs flex items-center justify-center"
                                >
                                    {{
                                        chat.unread_count > 9
                                            ? "9+"
                                            : chat.unread_count
                                    }}
                                </div>
                            </button>
                        </div>
                    </div>
                </template>

                <div
                    v-else
                    class="text-center py-8 text-[var(--text-secondary)]"
                >
                    <div class="mb-2 text-[var(--text-tertiary)]">
                        <Icon name="MessageSquare" size="32" />
                    </div>
                    <div>No chats yet</div>
                    <div class="text-sm text-[var(--text-tertiary)]">
                        Start a conversation from the People tab
                    </div>
                </div>
            </template>

            <!-- People Tab -->
            <template v-else-if="activeTab === 'people'">
                <!-- Search Results (High Priority) -->
                <div v-if="peopleSearchQuery" class="space-y-2">
                    <template v-if="peopleResults.length">
                        <button
                            v-for="person in peopleResults"
                            :key="person.id"
                            class="w-full flex items-center gap-3 p-3 rounded-2xl hover:bg-[var(--surface-tertiary)] transition-all text-left border border-transparent"
                            @click="emit('startDm', person.public_id)"
                        >
                            <Avatar
                                :src="person.avatar"
                                :fallback="person.name?.charAt(0) || '?'"
                                :status="
                                    person.is_online ? 'online' : 'offline'
                                "
                                size="md"
                                variant="ring"
                            />
                            <div class="flex-1 min-w-0">
                                <div
                                    class="font-semibold text-[var(--text-primary)] truncate"
                                >
                                    {{ person.name }}
                                </div>
                                <div class="flex items-center gap-1.5 text-sm">
                                    <span
                                        class="text-[var(--text-secondary)]"
                                        >{{
                                            person.is_online
                                                ? "Online"
                                                : "Offline"
                                        }}</span
                                    >
                                </div>
                            </div>
                            <span class="text-[var(--text-tertiary)]">→</span>
                        </button>
                    </template>
                    <div
                        v-else
                        class="text-center py-8 text-[var(--text-secondary)]"
                    >
                        <div class="mb-2 text-[var(--text-tertiary)]">
                            <Icon name="Search" size="32" />
                        </div>
                        <div>No people found</div>
                    </div>
                </div>

                <!-- Online People List (Default View) -->
                <div v-else>
                    <div v-if="onlineUsers.length" class="space-y-2">
                        <div
                            class="text-xs font-semibold uppercase tracking-wider text-[var(--text-tertiary)] px-2 mb-2"
                        >
                            Online Now ({{ onlineUsers.length }})
                        </div>
                        <button
                            v-for="user in onlineUsers"
                            :key="user.public_id"
                            class="w-full flex items-center gap-3 p-3 rounded-2xl hover:bg-[var(--surface-tertiary)] transition-all text-left border border-transparent"
                            @click="emit('startDm', user.public_id)"
                        >
                            <Avatar
                                :src="user.avatar"
                                :fallback="user.name?.charAt(0) || '?'"
                                :status="user.status"
                                size="md"
                                variant="ring"
                            />
                            <div class="flex-1 min-w-0">
                                <div
                                    class="font-semibold text-[var(--text-primary)] truncate"
                                >
                                    {{ user.name }}
                                </div>
                                <div
                                    class="flex items-center gap-1.5 text-sm text-[var(--text-secondary)]"
                                >
                                    {{ getStatusLabel(user.status) }}
                                </div>
                            </div>
                            <span class="text-[var(--text-tertiary)]">→</span>
                        </button>
                    </div>

                    <div
                        v-else
                        class="text-center py-8 text-[var(--text-secondary)]"
                    >
                        <div class="mb-2 text-[var(--text-tertiary)]">
                            <Icon name="Users" size="32" />
                        </div>
                        <div>No one is online right now</div>
                        <div class="text-sm text-[var(--text-tertiary)] mt-1">
                            Search to find people
                        </div>
                    </div>
                </div>
            </template>

            <!-- Invites Tab -->
            <template v-else-if="activeTab === 'invites'">
                <div v-if="invites.length" class="space-y-2">
                    <div
                        v-for="invite in invites"
                        :key="invite.id"
                        class="p-3 rounded-2xl bg-[var(--surface-elevated)] border border-[var(--border-default)]"
                    >
                        <div class="flex items-center gap-3 mb-3">
                            <div
                                class="w-10 h-10 rounded-xl bg-[var(--interactive-primary)] flex items-center justify-center text-white font-semibold"
                            >
                                {{ invite.inviter_name?.charAt(0) || "?" }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div
                                    class="font-semibold text-[var(--text-primary)] truncate"
                                >
                                    {{ invite.inviter_name }}
                                </div>
                                <div
                                    class="text-sm text-[var(--text-secondary)]"
                                >
                                    wants to chat
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button
                                class="flex-1 py-2 rounded-xl bg-[var(--interactive-primary)] text-white font-medium hover:bg-[var(--interactive-primary-hover)]"
                                @click="emit('acceptInvite', invite.id)"
                            >
                                Accept
                            </button>
                            <button
                                class="flex-1 py-2 rounded-xl bg-[var(--surface-tertiary)] text-[var(--text-secondary)] font-medium hover:bg-[var(--surface-primary)]"
                                @click="emit('declineInvite', invite.id)"
                            >
                                Decline
                            </button>
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="text-center py-8 text-[var(--text-secondary)]"
                >
                    <div class="mb-2 text-[var(--text-tertiary)]">
                        <Icon name="Mail" size="32" />
                    </div>
                    <div>No pending invites</div>
                </div>
            </template>
        </div>

        <!-- User Footer -->
        <div
            class="p-4 border-t border-[var(--border-default)] bg-[var(--surface-tertiary)]"
        >
            <div class="flex items-center gap-3">
                <div
                    class="shrink-0 w-10 h-10 rounded-xl bg-[var(--interactive-primary)] bg-cover bg-center"
                    :style="
                        currentUser?.avatar
                            ? { backgroundImage: `url(${currentUser.avatar})` }
                            : {}
                    "
                >
                    <span
                        v-if="!currentUser?.avatar"
                        class="flex items-center justify-center h-full text-white font-semibold"
                    >
                        {{ currentUser?.name?.charAt(0) || "?" }}
                    </span>
                </div>
                <div class="flex-1 min-w-0">
                    <div
                        class="font-semibold text-[var(--text-primary)] truncate"
                    >
                        {{ currentUser?.name || "User" }}
                    </div>
                    <StatusSelector size="sm" />
                </div>
            </div>
        </div>
    </aside>
</template>
