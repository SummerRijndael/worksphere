<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useMiniChatStore } from '@/stores/minichat';
import { useChatStore } from '@/stores/chat';
import { useAuthStore } from '@/stores/auth';
import { useThemeStore } from '@/stores/theme';
import { usePresence } from '@/composables/usePresence'; // Import usePresence
import { useToast } from '@/composables/useToast';
import { Icon, Avatar } from '@/components/ui';
import type { Chat, DiscoverablePerson, ChatInvite } from '@/types/models/chat';

const emit = defineEmits<{
  close: [];
}>();

const router = useRouter();
const miniChatStore = useMiniChatStore();
const chatStore = useChatStore();
const authStore = useAuthStore();
const themeStore = useThemeStore();
const toast = useToast();

// Use shared presence state (lifecycle managed by App/Layout)
const { presenceUsers } = usePresence({ manageLifecycle: false });

const searchQuery = ref('');
const peopleSearchQuery = ref('');

// ... (existing code) ...

// Helper to get real-time status
function getPresenceStatus(person: DiscoverablePerson): string {
  const onlineUser = presenceUsers.value.get(person.public_id);
  // Fallback to static status if not found in map (e.g. initially)
  return onlineUser?.status || person.presence_status || 'offline';
}

// ... (rest of existing helpers) ...
// Fetch data on mount
// Click outside handling
const panelRef = ref<HTMLElement | null>(null);

onMounted(async () => {
  if (chatStore.chats.length === 0) {
    await chatStore.fetchChats();
  }
  await chatStore.fetchInvites();

  setTimeout(() => {
    document.addEventListener('click', handleClickOutside);
    document.addEventListener('keydown', handleEsc);
  }, 100);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
  document.removeEventListener('keydown', handleEsc);
});

function handleClickOutside(e: MouseEvent) {
  if (panelRef.value && panelRef.value.contains(e.target as Node)) {
    return;
  }
  emit('close');
}

function handleEsc(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    emit('close');
  }
}

// Watch people tab to search
watch(() => miniChatStore.activeTab, async (tab) => {
  if (tab === 'people' && chatStore.people.length === 0) {
    await chatStore.searchPeople();
  }
});

// Computed
const tabs = [
  { id: 'chats' as const, label: 'Chats', icon: 'MessageSquare' },
  { id: 'groups' as const, label: 'Groups', icon: 'Users' },
  { id: 'people' as const, label: 'People', icon: 'UserPlus' },
  { id: 'invites' as const, label: 'Invites', icon: 'Mail' },
];

const dmChats = computed(() => 
  chatStore.chats.filter(c => c.type === 'dm')
);

const groupChats = computed(() => 
  chatStore.chats.filter(c => c.type === 'group' || c.type === 'team')
);

const filteredDmChats = computed(() => {
  if (!searchQuery.value) return dmChats.value;
  return dmChats.value.filter(c => 
    getChatName(c).toLowerCase().includes(searchQuery.value.toLowerCase())
  );
});

const filteredGroupChats = computed(() => {
  if (!searchQuery.value) return groupChats.value;
  return groupChats.value.filter(c => 
    getChatName(c).toLowerCase().includes(searchQuery.value.toLowerCase())
  );
});

const filteredPeople = computed(() => {
  // If searching, use API results
  if (peopleSearchQuery.value) {
    return chatStore.people.filter(p =>
      p.name.toLowerCase().includes(peopleSearchQuery.value.toLowerCase()) ||
      p.email.toLowerCase().includes(peopleSearchQuery.value.toLowerCase())
    );
  }
  
  // Otherwise, show Online/Away users from Presence (Real-time)
  const users = Array.from(presenceUsers.value.values())
    .filter(u => u.status !== 'offline' && u.status !== 'invisible' && u.public_id !== authStore.user?.public_id)
    .map(u => ({
      public_id: u.public_id,
      name: u.name,
      email: '', // Presence doesn't carry email usually, but we can try to find it or leave blank
      avatar: u.avatar,
      presence_status: u.status, // Use real status
      // Add other DiscoverablePerson fields if needed
    })) as DiscoverablePerson[];

  // Sort by status priority then name
  return users.sort((a, b) => {
    const statusPriority: Record<string, number> = { online: 0, busy: 1, away: 2 };
    const pA = statusPriority[a.presence_status || 'offline'] ?? 3;
    const pB = statusPriority[b.presence_status || 'offline'] ?? 3;
    if (pA !== pB) return pA - pB;
    return a.name.localeCompare(b.name);
  });
});

const inviteCount = computed(() => chatStore.invites.length);

// Helpers
function getChatName(chat: Chat): string {
  if (chat.name) return chat.name;
  if (chat.type === 'dm' && chat.participants.length) {
    // Get other participant
    const currentUserId = authStore.user?.public_id;
    const other = chat.participants.find(p => p.public_id !== currentUserId);
    // If we can't find other (e.g. self chat or error), fallback to first or 'Chat'
    return other?.name || chat.participants[0]?.name || 'Chat';
  }
  return 'Chat';
}

function getChatAvatar(chat: Chat): string | null {
  if (chat.avatar_url) return chat.avatar_url;
  if (chat.type === 'dm' && chat.participants.length) {
    const currentUserId = authStore.user?.public_id;
    const other = chat.participants.find(p => p.public_id !== currentUserId);
    return other?.avatar || chat.participants[0]?.avatar || null;
  }
  return null;
}

function getLastMessagePreview(chat: Chat): string {
  if (!chat.last_message) return 'No messages yet';
  if (chat.last_message.has_media) return '📎 Attachment';
  return chat.last_message.content || '';
}

function formatTime(dateStr: string): string {
  const date = new Date(dateStr);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMins = Math.floor(diffMs / 60000);
  
  if (diffMins < 1) return 'now';
  if (diffMins < 60) return `${diffMins}m`;
  
  const diffHours = Math.floor(diffMins / 60);
  if (diffHours < 24) return `${diffHours}h`;
  
  const diffDays = Math.floor(diffHours / 24);
  if (diffDays < 7) return `${diffDays}d`;
  
  return date.toLocaleDateString();
}

function handleChatClick(chat: Chat) {
  miniChatStore.openChatWindow(chat);
}

function handleOpenFullChat() {
  emit('close');
  router.push('/chat');
}

async function handlePersonClick(person: DiscoverablePerson) {
  const result = await chatStore.ensureDm(person.public_id);
  
  if (result?.status === 'chat_exists' && result.data) {
    miniChatStore.openChatWindow(result.data);
  } else if (result?.status === 'invite_required') {
    // Send invite
    await chatStore.sendInvite(person.public_id);
    toast.success('Invite sent', `Invitation sent to ${person.name}`);
    miniChatStore.setActiveTab('invites');
  }
}

async function handleAcceptInvite(invite: ChatInvite) {
  await chatStore.acceptInvite(String(invite.id));
}

async function handleDeclineInvite(invite: ChatInvite) {
  await chatStore.declineInvite(String(invite.id));
}


async function handlePeopleSearch() {
  await chatStore.searchPeople(peopleSearchQuery.value);
}

// Helper to determine chat presence status
function getChatPresenceStatus(chat: Chat): string {
    if (chat.type === 'dm') {
        const currentUserId = authStore.user?.public_id;
        const other = chat.participants.find(p => p.public_id !== currentUserId);
        if (!other) return 'offline'; // Self chat or error
        
        // Check real-time first
        const user = presenceUsers.value.get(other.public_id);
        return user?.status || other.presence_status || 'offline';
    }
    
    // For groups: Online if ANY other participant is online
    if (chat.type === 'group' || chat.type === 'team') {
        const currentUserId = authStore.user?.public_id;
        const hasOnline = chat.participants.some(p => {
             if (p.public_id === currentUserId) return false;
             const realtime = presenceUsers.value.get(p.public_id);
             return (realtime?.status === 'online') || (p.presence_status === 'online');
        });
        return hasOnline ? 'online' : 'offline';
    }
    
    return 'offline';
}
</script>

<template>
  <div ref="panelRef" class="minichat-panel" :class="`chat-theme-${themeStore.chatTheme}`">
    <!-- Header -->
    <div class="minichat-panel-header">
      <div class="minichat-panel-title">
        <Icon name="MessageCircle" :size="20" />
        <span>Messages</span>
      </div>
      <div class="minichat-panel-actions">
        <!-- Anchoring Toggle -->
        <button 
          class="minichat-panel-action"
          :title="miniChatStore.anchoringMode === 'docked' ? 'Switch to Free Mode' : 'Dock Windows'"
          @click="miniChatStore.setAnchoringMode(miniChatStore.anchoringMode === 'docked' ? 'free' : 'docked')"
        >
          <Icon :name="miniChatStore.anchoringMode === 'docked' ? 'LayoutTemplate' : 'Layers'" :size="16" />
        </button>
        <div class="minichat-panel-divider" />
        <button 
          class="minichat-panel-action"
          title="Open full chat"
          @click="handleOpenFullChat"
        >
          <Icon name="Maximize2" :size="16" />
        </button>
        <button 
          class="minichat-panel-action"
          @click="emit('close')"
        >
          <Icon name="X" :size="16" />
        </button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="minichat-panel-tabs">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        class="minichat-panel-tab"
        :class="{ 'is-active': miniChatStore.activeTab === tab.id }"
        @click="miniChatStore.setActiveTab(tab.id)"
      >
        <Icon :name="tab.icon" :size="14" />
        <span>{{ tab.label }}</span>
        <span 
          v-if="tab.id === 'invites' && inviteCount > 0"
          class="minichat-tab-badge"
        >
          {{ inviteCount }}
        </span>
      </button>
    </div>

    <!-- Search -->
    <div class="minichat-panel-search">
      <Icon name="Search" :size="14" class="minichat-search-icon" />
      <input
        v-if="miniChatStore.activeTab === 'people'"
        v-model="peopleSearchQuery"
        type="text"
        placeholder="Search people..."
        @keyup.enter="handlePeopleSearch"
      />
      <input
        v-else
        v-model="searchQuery"
        type="text"
        :placeholder="miniChatStore.activeTab === 'invites' ? 'Search invites...' : 'Search chats...'"
      />
    </div>

    <!-- Content -->
    <div class="minichat-panel-content">
      <!-- Chats Tab -->
      <template v-if="miniChatStore.activeTab === 'chats'">
        <div v-if="chatStore.chatsLoading" class="minichat-loading">
          <div class="minichat-spinner" />
        </div>
        <div v-else-if="filteredDmChats.length === 0" class="minichat-empty">
          <Icon name="MessageSquare" :size="32" />
          <p>No direct messages</p>
        </div>
        <button
          v-else
          v-for="chat in filteredDmChats"
          :key="chat.public_id"
          class="minichat-item"
          @click="handleChatClick(chat)"
        >
          <Avatar
            :src="getChatAvatar(chat)"
            :fallback="getChatName(chat)?.charAt(0) || '?'"
            size="md"
            :status="getChatPresenceStatus(chat)"
            variant="ring"
          />
          <div class="minichat-item-content">
            <div class="minichat-item-header">
              <span class="minichat-item-name">{{ getChatName(chat) }}</span>
              <span class="minichat-item-time">
                {{ chat.last_message ? formatTime(chat.last_message.created_at) : '' }}
              </span>
            </div>
            <p class="minichat-item-preview">{{ getLastMessagePreview(chat) }}</p>
          </div>
          <span v-if="chat.unread_count" class="minichat-item-badge">
            {{ chat.unread_count > 9 ? '9+' : chat.unread_count }}
          </span>
        </button>
      </template>

      <!-- Groups Tab -->
      <template v-else-if="miniChatStore.activeTab === 'groups'">
        <div v-if="chatStore.chatsLoading" class="minichat-loading">
          <div class="minichat-spinner" />
        </div>
        <div v-else-if="filteredGroupChats.length === 0" class="minichat-empty">
          <Icon name="Users" :size="32" />
          <p>No group chats</p>
        </div>
        <button
          v-else
          v-for="chat in filteredGroupChats"
          :key="chat.public_id"
          class="minichat-item"
          @click="handleChatClick(chat)"
        >
          <Avatar
            :src="getChatAvatar(chat)"
            :fallback="getChatName(chat)?.charAt(0) || '?'"
            size="md"
            :status="getChatPresenceStatus(chat)"
            variant="ring"
          />
          <div class="minichat-item-content">
            <div class="minichat-item-header">
              <span class="minichat-item-name">{{ getChatName(chat) }}</span>
              <span class="minichat-item-time">
                {{ chat.last_message ? formatTime(chat.last_message.created_at) : '' }}
              </span>
            </div>
            <p class="minichat-item-preview">{{ getLastMessagePreview(chat) }}</p>
          </div>
          <span v-if="chat.unread_count" class="minichat-item-badge">
            {{ chat.unread_count > 9 ? '9+' : chat.unread_count }}
          </span>
        </button>
      </template>

      <!-- People Tab -->
      <template v-else-if="miniChatStore.activeTab === 'people'">
        <div v-if="chatStore.peopleLoading" class="minichat-loading">
          <div class="minichat-spinner" />
        </div>
        <div v-else-if="filteredPeople.length === 0" class="minichat-empty">
          <Icon name="UserPlus" :size="32" />
          <p>No people found</p>
        </div>
        <button
          v-else
          v-for="person in filteredPeople"
          :key="person.public_id"
          class="minichat-item"
          @click="handlePersonClick(person)"
        >
            <Avatar
              :src="person.avatar"
              :fallback="person.name?.charAt(0) || '?'"
              :alt="person.name"
              size="md"
              :status="getPresenceStatus(person)"
              variant="ring"
            />
          <div class="minichat-item-content">
            <span class="minichat-item-name">{{ person.name }}</span>
            <p class="minichat-item-preview">{{ person.email }}</p>
          </div>
        </button>
      </template>

      <!-- Invites Tab -->
      <template v-else-if="miniChatStore.activeTab === 'invites'">
        <div v-if="chatStore.invites.length === 0" class="minichat-empty">
          <Icon name="Mail" :size="32" />
          <p>No pending invites</p>
        </div>
        <div
          v-else
          v-for="invite in chatStore.invites"
          :key="invite.id"
          class="minichat-invite"
        >
          <Avatar
            :src="invite.avatar_url"
            :name="invite.inviter_name"
            size="md"
          />
          <div class="minichat-invite-content">
            <span class="minichat-item-name">{{ invite.inviter_name }}</span>
            <p class="minichat-item-preview">
              {{ invite.type === 'group' ? `Invited to ${invite.chat_name}` : 'Wants to chat' }}
            </p>
          </div>
          <div class="minichat-invite-actions">
            <button 
              class="minichat-invite-accept"
              @click="handleAcceptInvite(invite)"
            >
              <Icon name="Check" :size="14" />
            </button>
            <button 
              class="minichat-invite-decline"
              @click="handleDeclineInvite(invite)"
            >
              <Icon name="X" :size="14" />
            </button>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.minichat-panel {
  position: absolute;
  bottom: 72px;
  right: 0;
  width: 360px;
  min-height: 520px;
  max-height: 520px;
  background: var(--surface-elevated);
  border: 1px solid var(--border-default);
  border-radius: 16px;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  z-index: 10000;
}

.minichat-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  border-bottom: 1px solid var(--border-default);
  background: var(--surface-secondary);
}

.minichat-panel-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 700;
  color: var(--text-primary);
}

.minichat-panel-actions {
  display: flex;
  gap: 4px;
}

.minichat-panel-action {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  border: none;
  background: transparent;
  color: var(--text-secondary);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
}

.minichat-panel-action:hover {
  background: var(--surface-tertiary);
  color: var(--text-primary);
}

.minichat-panel-divider {
  width: 1px;
  height: 16px;
  background: var(--border-default);
  margin: 0 4px;
  align-self: center;
}

.minichat-panel-tabs {
  display: flex;
  padding: 8px;
  gap: 4px;
  border-bottom: 1px solid var(--border-subtle);
}

.minichat-panel-tab {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 8px 4px;
  border-radius: 8px;
  border: none;
  background: transparent;
  color: var(--text-secondary);
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s ease;
  position: relative;
}

.minichat-panel-tab:hover {
  background: var(--surface-tertiary);
  color: var(--text-primary);
}

.minichat-panel-tab.is-active {
  background: var(--interactive-primary);
  color: white;
}

.minichat-tab-badge {
  position: absolute;
  top: 2px;
  right: 2px;
  min-width: 14px;
  height: 14px;
  padding: 0 4px;
  border-radius: 7px;
  background: var(--color-error);
  color: white;
  font-size: 10px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
}

.minichat-panel-search {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  margin: 8px 12px;
  background: var(--surface-tertiary);
  border-radius: 10px;
  border: 1px solid transparent;
  transition: all 0.15s ease;
}

.minichat-panel-search:focus-within {
  border-color: var(--interactive-primary);
  background: var(--surface-elevated);
}

.minichat-search-icon {
  color: var(--text-muted);
  flex-shrink: 0;
}

.minichat-panel-search input {
  flex: 1;
  border: none;
  background: transparent;
  color: var(--text-primary);
  font-size: 13px;
  outline: none;
}

.minichat-panel-search input::placeholder {
  color: var(--text-muted);
}

.minichat-panel-content {
  flex: 1;
  overflow-y: auto;
  padding: 8px;
}

.minichat-loading {
  display: flex;
  justify-content: center;
  align-items: center;
  padding: 40px;
}

.minichat-spinner {
  width: 24px;
  height: 24px;
  border: 2px solid var(--border-default);
  border-top-color: var(--interactive-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.minichat-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
  color: var(--text-muted);
  gap: 8px;
}

.minichat-empty p {
  font-size: 13px;
}

.minichat-item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 10px 12px;
  border-radius: 12px;
  border: none;
  background: transparent;
  cursor: pointer;
  text-align: left;
  transition: all 0.15s ease;
}

.minichat-item:hover {
  background: var(--surface-tertiary);
}

.minichat-avatar-wrapper {
  position: relative;
  flex-shrink: 0;
}

.minichat-status-dot {
  position: absolute;
  bottom: 0;
  right: 0;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  border: 2px solid var(--surface-elevated);
  background: var(--text-muted);
}

.minichat-status-dot.online {
  background: var(--color-success);
}

.minichat-status-dot.away {
  background: var(--color-warning);
}

.minichat-status-dot.busy {
  background: var(--color-error);
}

.minichat-item-content {
  flex: 1;
  min-width: 0;
}

.minichat-item-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.minichat-item-name {
  font-weight: 600;
  font-size: 13px;
  color: var(--text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.minichat-item-time {
  font-size: 11px;
  color: var(--text-muted);
  flex-shrink: 0;
}

.minichat-item-preview {
  font-size: 12px;
  color: var(--text-secondary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-top: 2px;
}

.minichat-item-badge {
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 9px;
  background: var(--interactive-primary);
  color: white;
  font-size: 10px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.minichat-invite {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 12px;
  background: var(--surface-tertiary);
  margin-bottom: 8px;
}

.minichat-invite-content {
  flex: 1;
  min-width: 0;
}

.minichat-invite-actions {
  display: flex;
  gap: 4px;
}

.minichat-invite-accept,
.minichat-invite-decline {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
}

.minichat-invite-accept {
  background: var(--color-success);
  color: white;
}

.minichat-invite-accept:hover {
  opacity: 0.9;
}

.minichat-invite-decline {
  background: var(--surface-secondary);
  color: var(--text-secondary);
}

.minichat-invite-decline:hover {
  background: var(--color-error);
  color: white;
}
</style>
