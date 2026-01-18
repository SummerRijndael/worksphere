<script setup lang="ts">
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useChatStore } from '@/stores/chat';
import { useChat } from '@/composables/useChat';
import { useThemeStore } from '@/stores/theme';
import { useToast } from '@/composables/useToast';
import { chatService } from '@/services/chat.service';

// Components
import ChatSidebar from './components/sidebar/ChatSidebar.vue';
import ChatHeader from './components/chat/ChatHeader.vue';
import MessageList from './components/chat/MessageList.vue';
import ChatComposer from './components/chat/ChatComposer.vue';
import ChatInfoDrawer from './components/drawer/ChatInfoDrawer.vue';
import ChatSearchModal from './components/chat/ChatSearchModal.vue';

const route = useRoute();
const authStore = useAuthStore();
const themeStore = useThemeStore();
const store = useChatStore();
const toast = useToast();

const {
    // State
    messageInput,
    // @ts-ignore
    messagesContainerRef,
    searchQuery,
    peopleSearchQuery,
    isLoadingMore,
    
    // Computed
    // Computed
    activeChat,
    activeMessages,
    dmChats,
    groupChats,
    filteredChats,
    people,
    invites,
    isLoading,
    replyingTo,
    pendingFiles,
    canLoadMore,
    typingIndicator,
    
    // UI State
    drawerOpen,
    activeTab,
    isMobile,
    showSidebar,

    // Actions
    selectChat,
    sendMessage,
    sendGif,
    loadMoreMessages,
    scrollToBottom,
    handleInputChange,
    
    setReplyTo,
    cancelReply,
    addFiles,
    removeFile,
    
    searchPeople,
    startDm,
    createGroup,
    renameGroup,
    
    acceptInvite,
    declineInvite,
    
    shouldShowDateDivider,
    formatMessageDate,
    
    // Media
    mediaItems,
    mediaLoading,
    fetchMedia,
    deleteMediaItem,
    hasMoreMedia,
    loadMoreMedia,
    storageStats,
    fetchStorageStats,
    
    // Realtime debug
    realtimeState,
} = useChat({
    initialChatId: route.params.chatId ? String(route.params.chatId) : null,
});


// Derived State
const currentUser = computed(() => authStore.user as any);
const currentUserId = computed(() => authStore.user?.id || 0);
const currentUserPublicId = computed(() => authStore.user?.public_id || '');

const activeHeader = computed(() => {
    if (!activeChat.value) return 'Select a chat';
    if (activeChat.value.name) return activeChat.value.name;
    if (activeChat.value.type === 'dm') {
        const other = activeChat.value.participants.find(p => p.public_id !== currentUserPublicId.value);
        return other?.name || 'Unknown';
    }
    return 'Group Chat';
});

const handleScroll = async (e: Event) => {
    const target = e.target as HTMLElement;
    if (target.scrollTop < 50 && canLoadMore.value && !isLoadingMore.value) {
        await loadMoreMessages();
    }
};

// Search modal state
const showSearchModal = ref(false);
const isJumping = ref(false);

// Jump to replied message with context loading
const jumpToMessage = async (messageId: string) => {
    let el = document.querySelector(`[data-message-id="${messageId}"]`);
    
    if (el) {
        // Message is already in view
        highlightElement(el);
        return;
    }
    
    // Message not in view - fetch context
    if (!activeChat.value || isJumping.value) return;
    
    isJumping.value = true;
    try {
        const result = await chatService.messagesAround(activeChat.value.public_id, messageId);
        
        // Replace current messages with fetched context
        store.setMessagesForChat(activeChat.value.public_id, result.messages);
        
        await nextTick();
        
        // Now find and scroll to target
        el = document.querySelector(`[data-message-id="${messageId}"]`);
        if (el) {
            highlightElement(el);
        }
    } catch (error) {
        console.error('Failed to jump to message:', error);
    } finally {
        isJumping.value = false;
    }
};

const highlightElement = (el: Element) => {
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('highlight-message');
    setTimeout(() => el.classList.remove('highlight-message'), 2000);
};

const toggleSearch = () => {
    showSearchModal.value = !showSearchModal.value;
};

const handleRetryMessage = async (message: any) => {
    // Determine context (new message or reply)
    if (message.reply_to) {
        // It's a reply
        await sendMessage(message.content, message.reply_to);
    } else {
        // Normal message
        await sendMessage(message.content);
    }
    // Remove the failed optimistic message - store handles this via setMessages usually, 
    // but we should ideally remove the specific failed ID.
    // For now, sendMessage adds a new one. We can leave the failed one or filter it out.
    // Let's filter it out strictly speaking, but for MVP re-sending is key.
};

// Lifecycle
onMounted(() => {
    if (route.params.chatId) {
        selectChat(String(route.params.chatId));
    }
});

// Watch drawer to fetch media and storage stats
watch(drawerOpen, (val) => {
    if (val && activeChat.value) {
        fetchMedia();
        fetchStorageStats();
    }
});

const handleStartDm = async (personOrId: any) => {
    // Determine ID - UI passes object or string
    const id = typeof personOrId === 'object' ? String(personOrId.public_id) : String(personOrId);
    
    // Call useChat's startDm but intercept invite_required case from store manually if useChat definition returns null
    // Actually useChat startDm returns null if invite sent, public_id if chat opens
    
    const result = await startDm(id);
    
    // If result is null, it means invite was sent (based on useChat implementation)
    // However, let's verify if we need to show toast here. 
    // useChat's startDm calls store.sendInvite if invite_required.
    // We can just show toast here if result is null? 
    // Wait, let's look at useChat implementation:
    // if (result.status === 'invite_required') { await store.sendInvite(userId); return null; }
    
    if (result === null) {
        toast.success('Invite sent', 'Invitation sent successfully');
        activeTab.value = 'invites';
    }
};

const handleAcceptInvite = (id: any) => acceptInvite(Number(id));
const handleDeclineInvite = (id: any) => declineInvite(Number(id));
</script>

<template>
  <div 
    class="flex h-screen max-h-screen overflow-hidden"
    :class="[
      { 'dark': themeStore.isDark },
      `chat-theme-${themeStore.chatTheme}`
    ]"
  >
    <!-- Sidebar (Left Column) -->
    <ChatSidebar
      :chats="filteredChats"
      :dm-chats="dmChats"
      :group-chats="groupChats"
      :people-results="people"
      :invites="invites"
      :active-tab="activeTab"
      :search-query="searchQuery"
      :people-search-query="peopleSearchQuery"
      :active-chat-id="activeChat?.public_id || null"
      :is-loading="isLoading"
      :current-user="currentUser"
      :is-mobile="isMobile"
      :show-sidebar="showSidebar"
      
      @update:active-tab="val => activeTab = val"
      @update:search-query="val => searchQuery = val"
      @update:people-search-query="val => peopleSearchQuery = val"
      @select-chat="selectChat"
      @create-group="createGroup"
      @search-people="searchPeople"
      @start-dm="handleStartDm"
      @accept-invite="handleAcceptInvite"
      @decline-invite="handleDeclineInvite"
      @close="showSidebar = false"
    />

    <!-- Chat Area (Center Column) -->
    <main class="chat-main-area flex-1 flex flex-col min-w-0 bg-[var(--surface-primary)]">
      <!-- Header -->
      <ChatHeader
        v-if="activeChat"
        :chat="activeChat"
        :header-title="activeHeader"
        :typing-indicator="typingIndicator"
        :connection-state="realtimeState?.connectionState ?? 'disconnected'"
        :subscribed-count="realtimeState?.subscribedChannels?.size ?? 0"
        :is-mobile="isMobile"
        @toggle-drawer="drawerOpen = !drawerOpen"
        @toggle-sidebar="showSidebar = !showSidebar"
        @toggle-search="toggleSearch"
      />
      
      <!-- Empty Header -->
      <div 
        v-else 
        class="flex items-center gap-3 p-4 border-b border-[var(--border-default)] bg-[var(--surface-elevated)]"
      >
        <button 
          v-if="isMobile"
          class="p-2 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-primary)]"
          @click="showSidebar = !showSidebar"
        >
          ☰
        </button>
        <span class="text-[var(--text-secondary)]">Select a chat to start messaging</span>
      </div>

      <MessageList
        ref="messagesContainerRef"
        :messages="activeMessages"
        :active-chat="activeChat"
        :current-user-public-id="currentUserPublicId"
        :current-user-name="currentUser?.name || ''"
        :typing-indicator="typingIndicator"
        :is-loading="isLoadingMore"
        :should-show-date-divider="shouldShowDateDivider"
        :format-message-date="formatMessageDate"
        @scroll="handleScroll"
        @reply="setReplyTo"
        @jump-to-message="jumpToMessage"
        @scroll-to-bottom="scrollToBottom"
        @retry="handleRetryMessage"
      />

        <!-- Typing Indicator (Fixed above composer) -->
        <div 
          v-if="typingIndicator" 
          class="flex items-center gap-2 px-6 py-2 text-sm text-[var(--text-secondary)] transition-all duration-300"
        >
            <div class="flex space-x-1 p-2 bg-[var(--surface-elevated)] rounded-2xl shadow-sm border border-[var(--border-default)]">
                <div class="w-2 h-2 bg-[var(--text-tertiary)] rounded-full animate-bounce [animation-delay:-0.3s]"></div>
                <div class="w-2 h-2 bg-[var(--text-tertiary)] rounded-full animate-bounce [animation-delay:-0.15s]"></div>
                <div class="w-2 h-2 bg-[var(--text-tertiary)] rounded-full animate-bounce"></div>
            </div>
          <span class="font-medium text-xs animate-pulse">{{ typingIndicator }}</span>
        </div>

      <!-- Composer -->
      <ChatComposer
        v-if="activeChat"
        v-model="messageInput"
        :sending="false"
        :reply-to="replyingTo"
        :pending-files="pendingFiles"
        :is-mobile="isMobile"
        :chat-id="activeChat.public_id"
        @send="sendMessage"
        @cancel-reply="cancelReply"
        @add-files="addFiles"
        @remove-file="removeFile"
        @typing="handleInputChange"
        @send-gif="sendGif"
      />
    </main>

    <!-- Mobile Overlay -->
    <Transition name="fade">
      <div 
        v-if="isMobile && showSidebar" 
        class="fixed inset-0 bg-black/50 z-40"
        @click="showSidebar = false"
      />
    </Transition>

    <!-- Info Drawer (Right Column) -->
    <ChatInfoDrawer
      v-if="activeChat"
      :open="drawerOpen"
      :chat="activeChat"
      :media-items="mediaItems"
      :media-loading="mediaLoading"
      :current-user-id="currentUserId"
      :current-user-public-id="currentUserPublicId"
      :has-more-media="hasMoreMedia"
      :load-more-media="loadMoreMedia"
      :storage-stats="storageStats"
      @close="drawerOpen = false"
      @fetch-media="fetchMedia"
      @delete-media="deleteMediaItem"
      @rename-group="renameGroup"
    />

    <ChatSearchModal
        v-if="activeChat"
        v-model:is-open="showSearchModal"
        :chat-id="activeChat.public_id"
        @jump="jumpToMessage"
    />
  </div>
</template>

<style scoped>
/* Message highlight animation */
:deep(.highlight-message) {
  animation: highlight-pulse 2s ease-out;
}

@keyframes highlight-pulse {
  0%, 100% { background-color: transparent; }
  50% { background-color: var(--interactive-primary); opacity: 0.2; }
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
