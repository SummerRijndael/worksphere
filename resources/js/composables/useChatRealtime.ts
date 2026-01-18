import { ref, watch, onUnmounted } from 'vue';
import Echo from 'laravel-echo';
import { useChatStore } from '@/stores/chat';
import { useAuthStore } from '@/stores/auth';
import type { Message } from '@/types/models/chat';
import { toast } from 'vue-sonner';

// ============================================================================
// SINGLETON STATE - Shared across all useChatRealtime instances
// ============================================================================
const subscribedChannels = ref<Set<string>>(new Set());
const isConnected = ref(false);
const connectionState = ref<'connected' | 'connecting' | 'disconnected'>('disconnected');
const typingTimeouts = new Map<string, ReturnType<typeof setTimeout>>();

/**
 * Composable for managing chat realtime subscriptions via Laravel Echo.
 * Uses singleton state to prevent duplicate subscriptions when called from multiple components.
 */
export function useChatRealtime() {
  const chatStore = useChatStore();
  const authStore = useAuthStore();

  /**
   * Subscribe to a chat channel for realtime messages.
   */
  function subscribeToChat(chatId: string, chatType: 'dm' | 'group' | 'team') {
    const channelPrefix = chatType === 'dm' ? 'dm' : 'group';
    const channelName = `${channelPrefix}.${chatId}`;

    if (subscribedChannels.value.has(channelName)) {
      console.log(`[ChatRealtime] Already subscribed to: ${channelName}`);
      return; // Already subscribed
    }

    const echo = (window as any).Echo as Echo<'reverb'>;
    if (!echo) {
      console.warn('[ChatRealtime] Echo not initialized, cannot subscribe to:', channelName);
      return;
    }

    console.log(`[ChatRealtime] 🔔 Subscribing to private channel: ${channelName}`);

    const channel = echo.private(channelName);
    
    channel
      .listen('.MessageCreated', (event: { message: Message }) => {
        console.log(`[ChatRealtime] 📩 RECEIVED .MessageCreated on ${channelName}`, JSON.stringify(event, null, 2));
        handleNewMessage(chatId, event.message);
      })
      .listen('.TypingStarted', (event: { user_public_id: string }) => {
        console.log(`[ChatRealtime] ⌨️ RECEIVED .TypingStarted on ${channelName}`, JSON.stringify(event, null, 2));
        handleTypingStarted(chatId, event.user_public_id);
      })
      .listen('.TypingStopped', (event: { user_public_id: string }) => {
        console.log(`[ChatRealtime] ⌨️ RECEIVED .TypingStopped on ${channelName}`, JSON.stringify(event, null, 2));
        handleTypingStopped(chatId, event.user_public_id);
      });

    // Log subscription success/failure
    if ((channel as any).subscription) {
      (channel as any).subscription.bind('pusher:subscription_succeeded', () => {
        console.log(`[ChatRealtime] ✅ Subscription SUCCEEDED: ${channelName}`);
      });
      (channel as any).subscription.bind('pusher:subscription_error', (error: any) => {
        console.error(`[ChatRealtime] ❌ Subscription FAILED: ${channelName}`, error);
      });
    }

    subscribedChannels.value.add(channelName);
    console.log(`[ChatRealtime] Current subscriptions:`, Array.from(subscribedChannels.value));
  }

  /**
   * Unsubscribe from a chat channel.
   */
  function unsubscribeFromChat(chatId: string, chatType: 'dm' | 'group' | 'team') {
    const channelPrefix = chatType === 'dm' ? 'dm' : 'group';
    const channelName = `${channelPrefix}.${chatId}`;

    if (!subscribedChannels.value.has(channelName)) {
      return; // Not subscribed
    }

    const echo = (window as any).Echo as Echo<'reverb'>;
    if (echo) {
      echo.leave(channelName);
    }

    subscribedChannels.value.delete(channelName);

    // Clear typing timeouts for this chat
    const prefix = `${chatId}-`;
    for (const [key, timeout] of typingTimeouts) {
      if (key.startsWith(prefix)) {
        clearTimeout(timeout);
        typingTimeouts.delete(key);
      }
    }
  }

  /**
   * Subscribe to user-specific channel for read receipts, badge updates, invites.
   */
  function subscribeToUserChannel() {
    const user = authStore.user;
    if (!user?.public_id) {
      console.warn('[ChatRealtime] No user public_id, cannot subscribe to user channel');
      return;
    }

    const echo = (window as any).Echo as Echo<'reverb'>;
    if (!echo) {
      console.warn('[ChatRealtime] Echo not initialized for user channel');
      return;
    }

    const channelName = `user.${user.public_id}`;

    if (subscribedChannels.value.has(channelName)) {
      console.log(`[ChatRealtime] Already subscribed to user channel: ${channelName}`);
      return;
    }

    console.log(`[ChatRealtime] 🔔 Subscribing to user channel: ${channelName}`);

    echo
      .private(channelName)
      .listen('.MessageRead', (event: { chat_id: string; last_read_message_id: string }) => {
        console.log(`[ChatRealtime] 👁️ RECEIVED .MessageRead on ${channelName}`, JSON.stringify(event, null, 2));
        chatStore.updateMessageSeen(event.chat_id, event.last_read_message_id);
      })
      .listen('.ChatBadgeUpdated', (event: { unread_count: number }) => {
        console.log(`[ChatRealtime] 🔢 RECEIVED .ChatBadgeUpdated on ${channelName}`, event);
      })
      .listen('.invite.sent', (event: any) => {
        console.log(`[ChatRealtime] 📨 RECEIVED .invite.sent on ${channelName}`, event);
        if (event.invite?.inviter_name) {
             toast.info(`New invite from ${event.invite.inviter_name}`);
        }
        chatStore.fetchInvites();
      })
      .listen('.invite.accepted', (event: any) => {
        console.log(`[ChatRealtime] ✅ RECEIVED .invite.accepted on ${channelName}`, event);
        if (event.invite?.invitee_name) {
             toast.success(`${event.invite.invitee_name} accepted your invitation`);
        }
        chatStore.fetchChats();
        // Also refresh invites to remove the accepted one from the list
        chatStore.fetchInvites();
      })
      .listen('.invite.declined', (event: any) => {
        console.log(`[ChatRealtime] ❌ RECEIVED .invite.declined on ${channelName}`, event);
        if (event.invite?.invitee_name) {
             toast.error(`${event.invite.invitee_name} declined your invitation`);
        }
        chatStore.fetchInvites();
      });

    subscribedChannels.value.add(channelName);
    console.log(`[ChatRealtime] User channel subscribed: ${channelName}`);
  }

  // Audio for valid message
  const chatSound = new Audio('/static/sounds/chat.mp3');

  /**
   * Handle incoming new message from realtime.
   */
  function handleNewMessage(chatId: string, message: Message) {
    console.log(`[ChatRealtime] 📬 handleNewMessage called for chat: ${chatId}`, {
      messageId: message.id,
      sender: message.user_public_id,
      content: message.content?.substring(0, 50),
    });

    // Don't add if it's our own message (already added optimistically)
    const currentUser = authStore.user;

    if (currentUser && String(message.user_public_id) === String(currentUser.public_id)) {
      console.log('[ChatRealtime] Ignoring own message (already added optimistically)');
      return;
    }

    // Play sound for incoming message
    chatSound.play().catch((e) => {
      console.warn('[ChatRealtime] Failed to play chat sound:', e);
    });

    console.log(`[ChatRealtime] ✅ Adding message to store for chat: ${chatId}`);
    chatStore.addMessage(chatId, message);

    // If this is the active chat, mark as read immediately
    // This triggers the "Seen" indicator for the sender
    if (chatStore.activeChatPublicId === chatId) {
      console.log('[ChatRealtime] Active chat - marking as read for Seen indicator');
      chatStore.markAsRead(chatId);
    } else {
      // Increment unread count if not the active chat
      const chat = chatStore.chats.find((c) => c.id === chatId);
      if (chat) {
        chatStore.updateChatUnreadCount(chatId, (chat.unread_count ?? 0) + 1);
      }
    }

    // Clear typing indicator for the sender
    if (message.user_public_id) {
      handleTypingStopped(chatId, message.user_public_id);
    }
  }

  /**
   * Handle typing started event.
   */
  function handleTypingStarted(chatId: string, userPublicId: string) {
    // Fresh auth lookup to avoid stale closure issues
    const freshAuthStore = useAuthStore();
    const currentUser = freshAuthStore.user;
    
    console.log('[ChatRealtime] handleTypingStarted called', {
      chatId,
      eventUserPublicId: userPublicId,
      currentUserPublicId: currentUser?.public_id,
      isSelf: currentUser?.public_id === userPublicId
    });
    
    // Don't show typing for self
    if (currentUser && userPublicId === currentUser.public_id) {
      console.log('[ChatRealtime] Ignoring own typing started event');
      return;
    }

    console.log(`[ChatRealtime] ✅ Setting typing TRUE for ${userPublicId} on chat ${chatId}`);
    chatStore.setUserTyping(chatId, userPublicId, true);

    // Set auto-clear timeout (10 seconds - extended from 5 to handle slower typists)
    const key = `${chatId}-${userPublicId}`;
    if (typingTimeouts.has(key)) {
      console.log(`[ChatRealtime] Clearing old timeout for ${key}`);
      clearTimeout(typingTimeouts.get(key)!);
    }

    console.log(`[ChatRealtime] Setting new 10-second timeout for ${key}`);
    typingTimeouts.set(
      key,
      setTimeout(() => {
        console.log(`[ChatRealtime] ⏰ TIMEOUT fired for ${userPublicId} on chat ${chatId}`);
        chatStore.setUserTyping(chatId, userPublicId, false);
        typingTimeouts.delete(key);
      }, 10000),
    );
  }

  /**
   * Handle typing stopped event.
   */
  function handleTypingStopped(chatId: string, userPublicId: string) {
    // Fresh auth lookup to avoid stale closure issues
    const freshAuthStore = useAuthStore();
    const currentUser = freshAuthStore.user;
    
    console.log('[ChatRealtime] handleTypingStopped called', {
      chatId,
      eventUserPublicId: userPublicId,
      currentUserPublicId: currentUser?.public_id,
      isSelf: currentUser?.public_id === userPublicId
    });
    
    // Don't clear own typing (already handled clientside)
    if (currentUser && userPublicId === currentUser.public_id) {
      console.log('[ChatRealtime] Ignoring own typing stop');
      return;
    }
    
    chatStore.setUserTyping(chatId, userPublicId, false);

    const key = `${chatId}-${userPublicId}`;
    if (typingTimeouts.has(key)) {
      clearTimeout(typingTimeouts.get(key)!);
      typingTimeouts.delete(key);
    }
  }

  /**
   * Subscribe to all loaded chats.
   */
  function subscribeToAllChats() {
    for (const chat of chatStore.chats) {
      subscribeToChat(chat.id, chat.type);
    }
  }

  /**
   * Unsubscribe from all channels.
   */
  function unsubscribeAll() {
    const echo = (window as any).Echo as Echo<'reverb'>;
    if (echo) {
      for (const channel of subscribedChannels.value) {
        echo.leave(channel);
      }
    }

    subscribedChannels.value.clear();

    // Clear all typing timeouts
    for (const timeout of typingTimeouts.values()) {
      clearTimeout(timeout);
    }
    typingTimeouts.clear();
  }

  /**
   * Initialize realtime subscriptions.
   */
  function initialize() {
    subscribeToUserChannel();
    subscribeToAllChats();
    
    // Set initial state based on Echo
    const echo = (window as any).Echo as Echo<'reverb'>;
    if (echo) {
        console.log('[ChatRealtime] Echo instance found, checking connector...');
        // Assume connected initially if Echo exists, will be updated by events
        connectionState.value = 'connected';
        isConnected.value = true;
        
        // Access pusher instance safely
        const connector = echo.connector as any;
        if (connector && connector.pusher) {
            console.log('[ChatRealtime] Pusher instance found, binding connection events');
            
            connector.pusher.connection.bind('connected', () => {
                console.log('[ChatRealtime] Connection status: connected');
                connectionState.value = 'connected';
                isConnected.value = true;
            });
            
            connector.pusher.connection.bind('unavailable', () => {
                console.log('[ChatRealtime] Connection status: unavailable');
                connectionState.value = 'disconnected';
                isConnected.value = false;
            });
            
            connector.pusher.connection.bind('failed', () => {
                 console.log('[ChatRealtime] Connection status: failed');
                 connectionState.value = 'disconnected';
                 isConnected.value = false;
            });
            
            connector.pusher.connection.bind('connecting', () => {
                console.log('[ChatRealtime] Connection status: connecting');
                connectionState.value = 'connecting';
            });
            
            connector.pusher.connection.bind('disconnected', () => {
                console.log('[ChatRealtime] Connection status: disconnected');
                connectionState.value = 'disconnected';
                isConnected.value = false;
            });
            
            // Log current state
            console.log('[ChatRealtime] Current Pusher state:', connector.pusher.connection.state);
            if (connector.pusher.connection.state === 'connected') {
                 connectionState.value = 'connected';
                 isConnected.value = true;
            }
        } else {
            console.warn('[ChatRealtime] Pusher instance NOT found on connector', connector);
        }
    } else {
        console.warn('[ChatRealtime] Echo instance NOT found on window');
    }
  }

  /**
   * Cleanup on unmount.
   */
  onUnmounted(() => {
    unsubscribeAll();
    isConnected.value = false;
  });

  // Watch for new chats and subscribe
  watch(
    () => chatStore.chats,
    (newChats) => {
      for (const chat of newChats) {
        subscribeToChat(chat.id, chat.type); // chat.id is now string (via type update)
      }
    },
    { deep: true },
  );

  return {
    isConnected,
    connectionState,
    subscribedChannels,
    initialize,
    subscribeToChat,
    unsubscribeFromChat,
    subscribeToUserChannel,
    subscribeToAllChats,
    unsubscribeAll,
  };
}
