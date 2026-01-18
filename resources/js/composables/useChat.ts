import { ref, computed, onMounted, onUnmounted, watch, nextTick, reactive } from 'vue';

import { useChatStore } from '@/stores/chat';
import { useChatRealtime } from './useChatRealtime';
import { useAvatar } from './useAvatar';
import { useToast } from './useToast';
import { chatService } from '@/services/chat.service';
import type { Chat, Message, MediaItem } from '@/types/models/chat';

interface UseChatOptions {
  /**
   * Auto-fetch chats on mount.
   */
  autoFetch?: boolean;
  /**
   * Initial chat ID to select (Public ID).
   */
  initialChatId?: string | null;
}

/**
 * Main orchestration composable for chat functionality.
 */
export function useChat(options: UseChatOptions = {}) {
  const { autoFetch = true, initialChatId = null } = options;

  const store = useChatStore();
  const realtime = useChatRealtime();
  const toast = useToast();
  
  // Media State
  const mediaItems = ref<MediaItem[]>([]);
  const mediaLoading = ref(false);
  const mediaPage = ref(1);
  const hasMoreMedia = ref(false);

  // Storage Stats
  const storageStats = ref<{ file_count: number; usage_mb: number; limit_mb: number; percentage_used: number } | null>(null);

  // Local UI state
  const messageInput = ref('');
  const messagesContainerRef = ref<HTMLElement | null>(null);
  const isLoadingMore = ref(false);
  const searchQuery = ref('');
  const peopleSearchQuery = ref('');
  
  // Heartbeat
  let heartbeatInterval: ReturnType<typeof setTimeout> | null = null;
  
  // New UI State
  const drawerOpen = ref(false);
  const activeTab = ref<'chats' | 'people' | 'invites'>('chats');
  const showEmoji = ref(false);
  const isMobile = ref(window.innerWidth < 1024);
  const showSidebar = ref(!isMobile.value);

  // Responsive handler
  const handleResize = () => {
    const mobile = window.innerWidth < 1024;
    if (mobile !== isMobile.value) {
        isMobile.value = mobile;
        if (mobile) {
            showSidebar.value = false;
        } else {
            showSidebar.value = true;
        }
    }
  };

  onMounted(() => {
    window.addEventListener('resize', handleResize);
  });

  onUnmounted(() => {
    window.removeEventListener('resize', handleResize);
  });

  // Typing indicator debounce
  let typingTimeout: ReturnType<typeof setTimeout> | null = null;
  let lastTypingSent = 0;

  // AbortController for race condition prevention on chat switch
  let chatSelectController: AbortController | null = null;

  // ============================================================================
  // Computed
  // ============================================================================

  const activeChat = computed(() => store.activeChat);
  const activeMessages = computed(() => store.activeMessages);
  const chats = computed(() => store.sortedChats);
  const dmChats = computed(() => store.dmChats);
  const groupChats = computed(() => store.groupChats);
  const people = computed(() => store.people);
  const invites = computed(() => store.invites);
  const totalUnreadCount = computed(() => store.totalUnreadCount);
  const isLoading = computed(() => store.chatsLoading);
  const isMessagesLoading = computed(() => store.messagesLoading);
  const replyingTo = computed(() => store.replyingToMessage);
  const pendingFiles = computed(() => store.pendingFiles);

  const canLoadMore = computed(() => {
    if (!store.activeChatPublicId) return false;
    return store.hasMoreMessages.get(store.activeChatPublicId) ?? false;
  });

  const typingIndicator = computed(() => {
    if (!store.activeChatPublicId) return null;
    const users = store.getTypingUsers(store.activeChatPublicId);
    if (users.length === 0) return null;

    const chat = store.activeChat;
    if (!chat) return null;

    // Find participant names
    const names = users
      .map((publicId) => chat.participants.find((p) => p.public_id === publicId)?.name)
      .filter(Boolean);

    if (names.length === 0) return null;
    if (names.length === 1) return `${names[0]} is typing...`;
    if (names.length === 2) return `${names[0]} and ${names[1]} are typing...`;
    return `${names[0]} and ${names.length - 1} others are typing...`;
  });

  const filteredChats = computed(() => {
    if (!searchQuery.value) return chats.value;
    const q = searchQuery.value.toLowerCase();
    return chats.value.filter((chat) => {
      if (chat.name?.toLowerCase().includes(q)) return true;
      return chat.participants.some((p) => p.name.toLowerCase().includes(q));
    });
  });

  // ============================================================================
  // Chat Selection
  // ============================================================================

  async function selectChat(chatId: string | null) {
    // Cancel any in-flight requests from previous chat selection
    if (chatSelectController) {
      chatSelectController.abort();
    }
    chatSelectController = new AbortController();
    chatSelectController = new AbortController();

    store.selectChat(chatId);

    if (chatId) {
      // On mobile, close sidebar when chat is selected
      if (isMobile.value) {
          showSidebar.value = false;
      }
      
      try {
        await store.fetchMessages(chatId);
        
        // ... rest of logic
        
        await store.markAsRead(chatId);
        scrollToBottom();
        startHeartbeat(chatId);
      } catch (error) {
        if ((error as Error).name === 'AbortError') {
          console.debug('[useChat] Request aborted due to chat switch');
          return;
        }
        throw error;
      }
    } else {
      stopHeartbeat();
    }
  }

  // ============================================================================
  // Messages
  // ============================================================================

  async function sendMessage(contentArg?: string, replyToArg?: string | number) {
    if (!store.activeChatPublicId) return;

    const content = contentArg ?? messageInput.value.trim();
    const pendingFiles = store.pendingFiles;
    const replyTo = replyToArg ?? store.replyingToMessage?.id;

    console.log('[useChat] sendMessage called', { content, pendingFilesLen: pendingFiles.length });

    if (!content && pendingFiles.length === 0) {
        console.log('[useChat] sendMessage aborted: no content or files');
        return;
    }

    // Only clear input if we are sending from state (not retry)
    if (!contentArg) {
        messageInput.value = '';
    }

    try {
      if (pendingFiles.length > 0 && !contentArg) {
        // Only attach pending files if sending from composer (not retry)
        console.log('[useChat] Uploading files...');
        const files = pendingFiles.map(p => p.file);
        await store.uploadMessage(store.activeChatPublicId, files, content, replyTo);
      } else {
        console.log('[useChat] Sending text...');
        await store.sendMessage(store.activeChatPublicId, content, replyTo);
      }

      scrollToBottom();
    } catch (error: any) {
      console.error('Failed to send message:', error);
      toast.error('Send Failed', error.message || 'Failed to send message');
      // Restore input on failure if it was from input
      if (!contentArg) {
          messageInput.value = content;
      }
    }
  }

  async function sendGif(gif: any) {
    if (!store.activeChatPublicId) return;
    
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
    await store.sendMessage(store.activeChatPublicId, '', store.replyingToMessage?.id, metadata);
    scrollToBottom();
  }

  async function loadMoreMessages() {
    if (!store.activeChatPublicId || isLoadingMore.value || !canLoadMore.value) return;

    const messages = store.activeMessages;
    if (messages.length === 0) return;

    const oldestId = messages[0].id;
    isLoadingMore.value = true;
    
    // Capture scroll details before load
    const container = messagesContainerRef.value;
    const oldScrollHeight = container?.scrollHeight || 0;
    const oldScrollTop = container?.scrollTop || 0;

    try {
      await store.fetchMessages(store.activeChatPublicId, { before: oldestId });
      
      // Restore position logic
      nextTick(() => {
        if (container) {
          const newScrollHeight = container.scrollHeight;
          const heightDifference = newScrollHeight - oldScrollHeight;
          container.scrollTop = heightDifference + oldScrollTop;
        }
      });
    } finally {
      isLoadingMore.value = false;
    }
  }

  function scrollToBottom(smooth = true) {
    nextTick(() => {
      if (messagesContainerRef.value) {
        messagesContainerRef.value.scrollTo({
            top: messagesContainerRef.value.scrollHeight,
            behavior: smooth ? 'smooth' : 'auto'
        });
      }
    });
  }

  // ============================================================================
  // Typing Indicator
  // ============================================================================

  function handleInputChange() {
    if (!store.activeChatPublicId) return;

    const now = Date.now();
    // Only send typing every 2 seconds
    if (now - lastTypingSent < 2000) return;

    lastTypingSent = now;
    chatService.sendTyping(store.activeChatPublicId);

    // Reset typing timeout
    if (typingTimeout) {
      clearTimeout(typingTimeout);
    }

    typingTimeout = setTimeout(() => {
      // Typing stopped (could send stop event if needed)
    }, 3000);
  }

  // ============================================================================
  // Reply
  // ============================================================================

  function setReplyTo(message: Message | null) {
    store.setReplyingTo(message);
  }

  function cancelReply() {
    store.setReplyingTo(null);
  }

  // ============================================================================
  // Files
  // ============================================================================

  const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
  const MAX_TOTAL_SIZE = 10 * 1024 * 1024; // 10MB
  const MAX_FILES = 10;

  function addFiles(files: FileList | File[]) {
    const currentCount = store.pendingFiles.length;
    const currentTotalSize = store.pendingFiles.reduce((acc, f) => acc + f.size, 0);
    const newFiles = Array.from(files);

    if (currentCount + newFiles.length > MAX_FILES) {
        toast.error('Limit Exceeded', `You can only upload up to ${MAX_FILES} files at a time.`);
        return;
    }

    let newBatchSize = 0;
    for (const file of newFiles) {
        newBatchSize += file.size;
    }

    if (currentTotalSize + newBatchSize > MAX_TOTAL_SIZE) {
        toast.error('Limit Exceeded', 'Total upload size cannot exceed 10MB.');
        return;
    }

    for (const file of newFiles) {
      if (file.size > MAX_FILE_SIZE) {
          toast.error('File Too Large', `${file.name} exceeds the 5MB limit.`);
          continue;
      }
      store.addPendingFile(file);
    }
  }

  function removeFile(index: number) {
    store.removePendingFile(index);
  }

  function clearFiles() {
    store.clearPendingFiles();
  }

  // ============================================================================
  // People & DM
  // ============================================================================

  async function searchPeople(query?: string) {
    peopleSearchQuery.value = query ?? '';
    await store.searchPeople(query);
  }

  async function startDm(userId: number | string): Promise<string | null> {
    const result = await store.ensureDm(String(userId));

    if (result.status === 'chat_exists' && result.chat_public_id) {
      await store.fetchChats();
      await selectChat(result.chat_public_id);
      return result.chat_public_id;
    }

    if (result.status === 'invite_required') {
      await store.sendInvite(String(userId));
      return null;
    }

    return null;
  }

  // ============================================================================
  // Invites
  // ============================================================================

  async function acceptInvite(inviteId: number) {
    const chatId = await store.acceptInvite(String(inviteId));
    if (chatId) {
      await selectChat(chatId);
    }
    return chatId;
  }

  async function declineInvite(inviteId: number) {
    await store.declineInvite(String(inviteId));
  }

  // ============================================================================
  // Groups
  // ============================================================================

  async function createGroup(name?: string) {
    const chat = await store.createGroup(name);
    await selectChat(chat.public_id);
    return chat;
  }

  async function renameGroup(name: string) {
    if (!store.activeChatPublicId) return;
    await store.renameGroup(store.activeChatPublicId, name);
  }

  // ============================================================================
  // Media State (moved to top-level scope)
  // ============================================================================

  async function fetchMedia(chatId?: string, reset = true) {
      const id = chatId || store.activeChatPublicId;
      if (!id) return;
      
      if (reset) {
          mediaPage.value = 1;
          mediaItems.value = [];
      } else {
          mediaPage.value++;
      }
      
      mediaLoading.value = true;
      try {
          const res = await chatService.getChatMedia(id, 'all', 24, mediaPage.value);
          if (reset) {
              mediaItems.value = res.items || [];
          } else {
              mediaItems.value = [...mediaItems.value, ...(res.items || [])];
          }
          hasMoreMedia.value = res.hasMore;
      } catch (e) {
          console.error(e);
      } finally {
          mediaLoading.value = false;
      }
  }

  async function loadMoreMedia() {
      if (mediaLoading.value || !hasMoreMedia.value) return;
      await fetchMedia(undefined, false);
  }
  
  async function deleteMediaItem(mediaId: number) {
      if (!store.activeChatPublicId) return;
      try {
          await chatService.deleteMedia(store.activeChatPublicId, mediaId);
          mediaItems.value = mediaItems.value.filter(m => m.id !== mediaId);
      } catch (e) {
          console.error(e);
      }
  }

  async function fetchStorageStats(chatId?: string) {
      const id = chatId || store.activeChatPublicId;
      if (!id) return;
      try {
          storageStats.value = await chatService.getChatStorageStats(id);
      } catch (e) {
          console.error('Failed to fetch storage stats:', e);
      }
  }

  // ...



  // ============================================================================
  // Helpers
  // ============================================================================

  function getChatTitle(chat: Chat): string {
    if (chat.name) return chat.name;
    if (chat.type === 'dm') {
      // Get other participant's name
      const other = chat.participants[0]; // Assuming first participant is the other person
      return other?.name ?? 'Unknown';
    }
    return 'Group Chat';
  }

  /**
   * Get chat avatar URL using unified useAvatar composable.
   * @deprecated Use useAvatar().resolveChatAvatar() directly for full avatar data
   */
  function getChatAvatar(chat: Chat): string | null {
    const avatar = useAvatar();
    return avatar.resolveChatAvatar(chat).url;
  }

  function getOtherParticipant(chat: Chat) {
    if (chat.type !== 'dm') return null;
    return chat.participants[0] ?? null;
  }

  function formatMessageTime(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  function formatMessageDate(dateString: string): string {
    const date = new Date(dateString);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);

    if (date.toDateString() === today.toDateString()) {
      return 'Today';
    }
    if (date.toDateString() === yesterday.toDateString()) {
      return 'Yesterday';
    }
    return date.toLocaleDateString([], { weekday: 'long', month: 'short', day: 'numeric' });
  }

  function shouldShowDateDivider(messages: Message[], index: number): boolean {
    if (index === 0) return true;
    const current = new Date(messages[index].created_at).toDateString();
    const previous = new Date(messages[index - 1].created_at).toDateString();
    return current !== previous;
  }

  // ============================================================================
  // Lifecycle
  // ============================================================================

  onMounted(async () => {
    if (autoFetch) {
      await store.fetchChats();
      await store.fetchInvites();
    }

    realtime.initialize();

    if (initialChatId) {
      await selectChat(initialChatId);
    }
  });

  onUnmounted(() => {
    stopHeartbeat();
    if (typingTimeout) {
      clearTimeout(typingTimeout);
    }
  });

  function startHeartbeat(chatId: string) {
      stopHeartbeat();
      // Initial beat
      chatService.sendHeartbeat(chatId).catch(() => {});
      
      heartbeatInterval = setInterval(() => {
          chatService.sendHeartbeat(chatId).catch(() => {});
      }, 15000);
  }

  function stopHeartbeat() {
      if (heartbeatInterval) {
          clearInterval(heartbeatInterval);
          heartbeatInterval = null;
      }
  }

  // Watch for active messages and scroll to bottom on new messages
  watch(
    () => activeMessages.value.length,
    (newLen, oldLen) => {
      if (newLen > oldLen) {
        scrollToBottom();
      }
    },
  );

  return {
    // State
    messageInput,
    messagesContainerRef,
    searchQuery,
    peopleSearchQuery,
    isLoadingMore,

    // Computed
    activeChat,
    activeMessages,
    chats,
    dmChats,
    groupChats,
    filteredChats,
    people,
    invites,
    totalUnreadCount,
    isLoading,
    isMessagesLoading,
    replyingTo,
    pendingFiles,
    canLoadMore,
    typingIndicator,

    // Chat selection
    selectChat,

    // Messages
    sendMessage,
    sendGif,
    loadMoreMessages,
    scrollToBottom,
    handleInputChange,

    // Reply
    setReplyTo,
    cancelReply,

    // Files
    addFiles,
    removeFile,
    clearFiles,

    // People & DM
    searchPeople,
    startDm,

    // Invites
    acceptInvite,
    declineInvite,

    // Groups
    createGroup,
    renameGroup,

    // Helpers
    getChatTitle,
    getChatAvatar,
    getOtherParticipant,
    formatMessageTime,
    formatMessageDate,
    shouldShowDateDivider,

    // Media
    mediaItems,
    mediaLoading,
    hasMoreMedia,
    fetchMedia,
    loadMoreMedia,
    deleteMediaItem,
    storageStats,
    fetchStorageStats,

    // UI State
    drawerOpen,
    activeTab,
    showEmoji,
    isMobile,
    showSidebar,

    // Store access
    store,

    // Realtime state (for debugging)
    realtimeState: reactive({
      isConnected: realtime.isConnected,
      connectionState: realtime.connectionState,
      subscribedChannels: realtime.subscribedChannels,
    }),
  };
}
