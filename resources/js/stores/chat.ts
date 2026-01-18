import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { chatService } from '@/services/chat.service';
import type {
  Chat,
  Message,
  ChatInvite,
  DiscoverablePerson,
} from '@/types/models/chat';

export const useChatStore = defineStore('chat', () => {
  // ============================================================================
  // State
  // ============================================================================

  // Chat list
  const chats = ref<Chat[]>([]);
  const chatsLoading = ref(false);
  const chatsError = ref<string | null>(null);

  // Active chat (Public ID)
  const activeChatPublicId = ref<string | null>(null);

  // Messages (keyed by chat Public ID)
  const messagesByChat = ref<Map<string, Message[]>>(new Map());
  const messagesLoading = ref(false);
  const hasMoreMessages = ref<Map<string, boolean>>(new Map());

  // People discovery
  const people = ref<DiscoverablePerson[]>([]);
  const peopleLoading = ref(false);

  // Invites
  const invites = ref<ChatInvite[]>([]);
  const invitesLoading = ref(false);
  const seenInviteIds = ref<Set<string>>(new Set());

  // Typing indicators (chatPublicId -> Set of user public_ids)
  const typingUsers = ref<Map<string, Set<string>>>(new Map());

  // UI state
  const replyingToMessage = ref<Message | null>(null);
  const pendingFiles = ref<any[]>([]);

  // ============================================================================
  // Computed
  // ============================================================================

  const activeChat = computed(() => {
    if (!activeChatPublicId.value) return null;
    return chats.value.find((c) => c.public_id === activeChatPublicId.value) ?? null;
  });

  const activeMessages = computed(() => {
    if (!activeChatPublicId.value) return [];
    return messagesByChat.value.get(activeChatPublicId.value) ?? [];
  });

  const totalUnreadCount = computed(() => {
    return chats.value.reduce((sum, chat) => sum + (chat.unread_count ?? 0), 0);
  });

  const sortedChats = computed(() => {
    return [...chats.value].sort((a, b) => {
      const aTime = a.last_message?.created_at ?? a.updated_at;
      const bTime = b.last_message?.created_at ?? b.updated_at;
      return new Date(bTime).getTime() - new Date(aTime).getTime();
    });
  });

  const dmChats = computed(() => sortedChats.value.filter((c) => c.type === 'dm'));
  const groupChats = computed(() => sortedChats.value.filter((c) => c.type === 'group' || c.type === 'team'));

  const unseenInviteCount = computed(() => {
    return invites.value.filter((inv) => !seenInviteIds.value.has(inv.id)).length;
  });

  // ============================================================================
  // Actions - Chat List
  // ============================================================================

  async function fetchChats() {
    chatsLoading.value = true;
    chatsError.value = null;
    try {
      chats.value = await chatService.listChats();
    } catch (error: any) {
      chatsError.value = error.message ?? 'Failed to load chats';
    } finally {
      chatsLoading.value = false;
    }
  }

  async function refreshChat(chatId: string) {
    try {
      const updated = await chatService.getChat(chatId);
      const index = chats.value.findIndex((c) => c.public_id === chatId);
      if (index !== -1) {
        chats.value[index] = updated;
      } else {
        chats.value.unshift(updated);
      }
    } catch {
      // Silently fail - chat may have been deleted
    }
  }

  function selectChat(chatId: string | null) {
    activeChatPublicId.value = chatId;
    replyingToMessage.value = null;
  }

  function updateChatUnreadCount(chatId: string, count: number) {
    const chat = chats.value.find((c) => c.public_id === chatId);
    if (chat) {
      chat.unread_count = count;
    }
  }

  function clearChatUnread(chatId: string) {
    updateChatUnreadCount(chatId, 0);
  }

  // ============================================================================
  // Actions - Messages
  // ============================================================================

  async function fetchMessages(chatId: string, options?: { before?: number | string }) {
    messagesLoading.value = true;
    try {
      const { messages, hasMore } = await chatService.getMessages(chatId, options);

      if (options?.before) {
        // Prepend older messages
        const existing = messagesByChat.value.get(chatId) ?? [];
        messagesByChat.value.set(chatId, [...messages, ...existing]);
      } else {
        // Replace with fresh messages
        messagesByChat.value.set(chatId, messages);
      }

      hasMoreMessages.value.set(chatId, hasMore);
    } catch (error) {
      console.error('Failed to fetch messages:', error);
      throw error;
    } finally {
      messagesLoading.value = false;
    }
  }

  async function sendMessage(chatId: string, content: string, replyTo?: string | number, metadata?: Record<string, any>) {
    // Get current user from auth store for proper message display
    const { useAuthStore } = await import('@/stores/auth');
    const authStore = useAuthStore();
    const currentUserPublicId = authStore.user?.public_id || null;
    
    // Create optimistic message
    const tempId = `temp-${Date.now()}`;
      const optimisticMessage: Message = {
      id: tempId,
      type: 'user',
      user_public_id: currentUserPublicId, // Set to current user so it displays on correct side
      user_name: authStore.user?.name || 'You',
      user_avatar: authStore.avatarUrl || null,
      content,
      created_at: new Date().toISOString(),
      is_seen: false,
      seen: false,
      seen_at: null,
      reply_to: replyTo ? findMessageById(chatId, replyTo) as any : null,
      attachments: [],
      pending: true,
      tempId,
      metadata,
    };

    // Add optimistic message
    addMessage(chatId, optimisticMessage);

    try {
      const message = await chatService.sendMessage(chatId, content, replyTo, tempId, metadata);
      // Replace optimistic message with real one
      replaceMessage(chatId, tempId, message);
      replyingToMessage.value = null;
      return message;
    } catch (error) {
      // Mark as failed
      markMessageFailed(chatId, tempId);
      throw error;
    }
  }

  async function uploadMessage(chatId: string, files: File[], content?: string, replyTo?: string | number) {
    try {
      const message = await chatService.uploadMessage(chatId, files, content, replyTo);
      addMessage(chatId, message);
      replyingToMessage.value = null;
      clearPendingFiles(); // Clear and revoke URLs
      return message;
    } catch (error) {
      throw error;
    }
  }

  async function retryMessage(chatId: string, messageId: string) {
    const messages = messagesByChat.value.get(chatId) ?? [];
    const message = messages.find((m) => m.id === messageId);
    
    if (!message || !message.failed) return;

    // Reset status
    message.failed = false;
    message.pending = true;
    
    // Remove old message (we'll re-add it as new optimistic)
    // Actually, let's just re-use the sendMessage logic but we need to handle content/files
    // For simplicity, for text messages, we can just call sendMessage again.
    // For files, we'd need to re-upload.
    
    // Simplest retry for text:
    if (message.content && message.attachments.length === 0) {
        // Remove the failed message from the list so sendMessage can create a fresh one
        const index = messages.findIndex(m => m.id === messageId);
        if (index !== -1) {
            messages.splice(index, 1);
            messagesByChat.value.set(chatId, [...messages]);
        }
        
        return sendMessage(chatId, message.content, message.reply_to?.id);
    }
    
    // TODO: Handle file retry if needed (requires storing file blob or re-selecting)
  }

  function addMessage(chatId: string, message: Message) {
    console.log('[ChatStore] addMessage called', {
      chatId,
      activeChatPublicId: activeChatPublicId.value,
      isActiveChat: chatId === activeChatPublicId.value,
      messageId: message.id,
    });

    const messages = messagesByChat.value.get(chatId) ?? [];
    
    // Avoid duplicates - compare by string ID
    const isDuplicate = messages.some((m) => 
      String(m.id) === String(message.id) || 
      (m.tempId && m.tempId === message.tempId)
    );
    
    if (isDuplicate) {
      console.log('[ChatStore] Duplicate message, skipping', message.id);
      return;
    }

    // Create new array for reactivity
    const newMessages = [...messages, message];
    messagesByChat.value.set(chatId, newMessages);
    
    console.log('[ChatStore] Message added, new count:', newMessages.length);
    console.log('[ChatStore] messagesByChat keys:', Array.from(messagesByChat.value.keys()));

    // Update chat's last message
    const chat = chats.value.find((c) => c.public_id === chatId);
    if (chat) {
      chat.last_message = {
        id: message.id,
        user_name: message.user_name,
        content: message.content,
        created_at: message.created_at,
        has_media: message.attachments.length > 0,
      };
      chat.updated_at = message.created_at;
    }
  }

  function replaceMessage(chatId: string, tempId: string, message: Message) {
    const messages = messagesByChat.value.get(chatId) ?? [];
    const index = messages.findIndex((m) => m.tempId === tempId);
    if (index !== -1) {
      // Preserve keys to prevents transition flicker
      message.tempId = tempId; 
      messages[index] = message;
      messagesByChat.value.set(chatId, [...messages]);
    }
  }

  /**
   * Set/replace all messages for a chat (used by jump-to-message context loading).
   */
  function setMessagesForChat(chatId: string, messages: Message[]) {
    messagesByChat.value.set(chatId, messages);
  }

  function markMessageFailed(chatId: string, tempId: string) {
    const messages = messagesByChat.value.get(chatId) ?? [];
    const message = messages.find((m) => m.tempId === tempId);
    if (message) {
      message.pending = false;
      message.failed = true;
    }
  }

  function findMessageById(chatId: string, messageId: string | number): Message | undefined {
    const messages = messagesByChat.value.get(chatId) ?? [];
    return messages.find((m) => String(m.id) === String(messageId));
  }

  function updateMessageSeen(chatId: string, lastReadMessageId: string | number) {
    console.log('[ChatStore] updateMessageSeen called', { chatId, lastReadMessageId });
    
    const messages = messagesByChat.value.get(chatId) ?? [];
    if (messages.length === 0) {
      console.log('[ChatStore] No messages found for chat:', chatId);
      return;
    }

    // Find the index of the last read message
    const lastReadIndex = messages.findIndex((m) => String(m.id) === String(lastReadMessageId));
    
    // Clear ALL previous seen flags first, then set only the last one
    messages.forEach((m) => {
      m.is_seen = false;
      m.seen = false;
    });

    if (lastReadIndex !== -1) {
      // Only mark the LAST read message as seen (not all previous)
      messages[lastReadIndex].is_seen = true;
      messages[lastReadIndex].seen = true;
      console.log('[ChatStore] Marked message as seen:', lastReadMessageId);
    } else {
      // If message not found, mark the last message in the array as seen
      const lastMsg = messages[messages.length - 1];
      if (lastMsg) {
        lastMsg.is_seen = true;
        lastMsg.seen = true;
        console.log('[ChatStore] Message not found, marked last message as seen');
      }
    }

    // Trigger reactivity by setting a new array reference
    messagesByChat.value.set(chatId, [...messages]);
  }

  async function markAsRead(chatId: string) {
    try {
      await chatService.markAsRead(chatId);
      clearChatUnread(chatId);
    } catch {
      // Silently fail
    }
  }

  // ============================================================================
  // Actions - Typing
  // ============================================================================

  function setUserTyping(chatId: string, userPublicId: string, isTyping: boolean) {
    console.log('[ChatStore] setUserTyping', { chatId, userPublicId, isTyping, activeChatPublicId: activeChatPublicId.value });
    
    let users = typingUsers.value.get(chatId);
    if (!users) {
      users = new Set();
      typingUsers.value.set(chatId, users);
    }

    if (isTyping) {
      users.add(userPublicId);
    } else {
      users.delete(userPublicId);
    }

    // Trigger reactivity
    typingUsers.value = new Map(typingUsers.value);
    console.log('[ChatStore] typingUsers for chat:', Array.from(users));
  }

  function getTypingUsers(chatId: string): string[] {
    return Array.from(typingUsers.value.get(chatId) ?? []);
  }

  // ============================================================================
  // Actions - People & Invites
  // ============================================================================

  async function searchPeople(query?: string, onlineOnly?: boolean) {
    peopleLoading.value = true;
    try {
      people.value = await chatService.searchPeople(query, onlineOnly);
    } catch (error) {
      console.error('Failed to search people:', error);
    } finally {
      peopleLoading.value = false;
    }
  }

  async function fetchInvites() {
    invitesLoading.value = true;
    try {
      invites.value = await chatService.getInvites();
    } catch (error) {
      console.error('Failed to fetch invites:', error);
    } finally {
      invitesLoading.value = false;
    }
  }

  async function acceptInvite(invitePublicId: string) {
    try {
      const result = await chatService.acceptInvite(invitePublicId);
      // Remove from list (use id, not public_id)
      invites.value = invites.value.filter((i) => i.id !== invitePublicId);
      
      // If we accepted successfully (or were told it's already accepted), refresh chats
      await fetchChats(); 
      await fetchInvites(); // Sync invites just in case
      
      return result.chat_public_id;
    } catch (error: any) {
      // If invite is not found (404) or expired (410), it should be removed from our list anyway
      if (error.response && (error.response.status === 404 || error.response.status === 410)) {
        invites.value = invites.value.filter((i) => i.id !== invitePublicId);
        await fetchChats();
        await fetchInvites();
      }
      throw error;
    }
  }

  async function declineInvite(invitePublicId: string) {
    try {
      await chatService.declineInvite(invitePublicId);
      invites.value = invites.value.filter((i) => i.id !== invitePublicId);
      await fetchInvites();
    } catch (error: any) {
       // If invite is not found (404) or expired (410), it should be removed anyway
       if (error.response && (error.response.status === 404 || error.response.status === 410)) {
        invites.value = invites.value.filter((i) => i.id !== invitePublicId);
        await fetchInvites();
      }
      throw error;
    }
  }

  async function sendInvite(inviteePublicId: string) {
    const result = await chatService.sendInvite(inviteePublicId);
    // Refresh invites? No, outgoing invites aren't in our list usually.
    return result;
  }

  async function ensureDm(userPublicId: string) {
    return chatService.ensureDm(userPublicId);
  }

  async function addMember(chatId: string, userPublicId: string) {
    try {
      const result = await chatService.addMember(chatId, userPublicId);
      // Refresh chat to get new participant (or just manually add if we had full user object)
      // For now, simpler to refresh or let realtime event handle it. 
      // But we should at least trigger a fetch to update the list immediately if realtime lags.
      await refreshChat(chatId);
      return result;
    } catch (error) {
      throw error;
    }
  }

  function markInvitesSeen() {
    invites.value.forEach((inv) => seenInviteIds.value.add(inv.id));
  }

  // ============================================================================
  // Actions - Groups
  // ============================================================================

  async function createGroup(name?: string) {
    const chat = await chatService.createGroup(name);
    chats.value.unshift(chat);
    return chat;
  }

  async function renameGroup(chatId: string, name: string) {
    const chat = await chatService.renameGroup(chatId, name);
    const index = chats.value.findIndex((c) => c.public_id === chatId);
    if (index !== -1) {
      chats.value[index] = chat;
    }
    return chat;
  }

  async function updateGroup(chatId: string, data: { name?: string; avatar?: File }) {
    const formData = new FormData();
    if (data.name) formData.append('name', data.name);
    if (data.avatar) formData.append('avatar', data.avatar);

    const chat = await chatService.updateGroup(chatId, formData);
    
    // Update local state
    const index = chats.value.findIndex((c) => c.public_id === chatId);
    if (index !== -1) {
      chats.value[index] = chat;
    }
    
    // Also update requestor's own messages in this chat if name/avatar changed? 
    // No, messages store snapshot, but UI might need refresh. 
    // Chat object update is usually enough for header.
    
    return chat;
  }

  async function leaveGroup(chatId: string) {
    await chatService.leaveGroup(chatId);
    // Remove chat from list
    chats.value = chats.value.filter(c => c.public_id !== chatId);
    if (activeChatPublicId.value === chatId) {
      activeChatPublicId.value = null;
    }
  }

  async function kickMember(chatId: string, userPublicId: string) {
      await chatService.kickMember(chatId, userPublicId);
      const chat = chats.value.find(c => c.public_id === chatId);
      if (chat && chat.participants) {
          chat.participants = chat.participants.filter(p => p.public_id !== userPublicId);
      }
  }

  async function deleteGroup(chatId: string, password: string) {
      await chatService.deleteGroup(chatId, password);
      // Remove chat from list
      chats.value = chats.value.filter(c => c.public_id !== chatId);
      if (activeChatPublicId.value === chatId) {
        activeChatPublicId.value = null;
      }
  }

  // ============================================================================
  // Actions - UI State
  // ============================================================================

  function setReplyingTo(message: Message | null) {
    replyingToMessage.value = message;
  }

  function addPendingFile(file: File) {
    const isImage = file.type.startsWith('image/');
    const url = isImage ? URL.createObjectURL(file) : undefined;
    
    pendingFiles.value.push({
        file,
        id: `pending-${Math.random().toString(36).substring(2, 9)}`,
        url,
        name: file.name,
        isImage
    });
  }

  function removePendingFile(index: number) {
    const file = pendingFiles.value[index];
    if (file?.url) {
        URL.revokeObjectURL(file.url);
    }
    pendingFiles.value.splice(index, 1);
  }

  function clearPendingFiles() {
    pendingFiles.value.forEach(f => {
        if (f.url) URL.revokeObjectURL(f.url);
    });
    pendingFiles.value = [];
  }

  // ============================================================================
  // Reset
  // ============================================================================

  function clearState() {
    chats.value = [];
    activeChatPublicId.value = null;
    messagesByChat.value = new Map();
    people.value = [];
    invites.value = [];
    typingUsers.value = new Map();
    replyingToMessage.value = null;
    pendingFiles.value = [];
  }

  return {
    // State
    chats,
    chatsLoading,
    chatsError,
    activeChatPublicId,
    messagesByChat,
    messagesLoading,
    people,
    peopleLoading,
    invites,
    invitesLoading,
    typingUsers,
    replyingToMessage,
    pendingFiles,

    // Computed
    activeChat,
    activeMessages,
    totalUnreadCount,
    unseenInviteCount,
    sortedChats,
    dmChats,
    groupChats,
    hasMoreMessages,

    // Actions - Chats
    fetchChats,
    refreshChat,
    selectChat,
    updateChatUnreadCount,
    clearChatUnread,

    // Actions - Messages
    fetchMessages,
    sendMessage,
    uploadMessage,
    addMessage,
    setMessagesForChat,
    updateMessageSeen,
    markAsRead,
    findMessageById,

    // Actions - Typing
    setUserTyping,
    getTypingUsers,
    
    // Actions - Retry
    retryMessage,

    // Actions - People & Invites
    searchPeople,
    fetchInvites,
    acceptInvite,
    declineInvite,
    sendInvite,
    ensureDm,
    markInvitesSeen,

    // Actions - Groups
    createGroup,
    addMember,
    renameGroup,
    updateGroup,
    leaveGroup,
    kickMember,
    deleteGroup,

    // Actions - UI
    setReplyingTo,
    addPendingFile,
    removePendingFile,
    clearPendingFiles,

    // Reset
    clearState,
  };
});
