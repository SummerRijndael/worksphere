import { ref, onMounted } from 'vue';
import api from '@/lib/api';

/**
 * Headless core composable for WIP chat.
 * Connects to the isolated package API.
 */
export function useChatWip(chatPublicId: string) {
  const messages = ref([]);
  const isLoading = ref(false);
  const isSending = ref(false);
  const messageInput = ref('');

  async function fetchMessages() {
    if (!chatPublicId) return;
    
    isLoading.value = true;
    try {
      const response = await api.get(`/api/pkg/chat/${chatPublicId}`);
      // The show method currently returns the chat object with messages
      messages.value = response.data.data.messages || [];
      console.log(`[ChatWip] Fetched ${messages.value.length} messages`);
    } catch (error) {
      console.error('[ChatWip] Failed to fetch messages:', error);
    } finally {
      isLoading.value = false;
    }
  }

  async function sendMessage() {
    if (!messageInput.value.trim() || isSending.value || !chatPublicId) return;
    
    const content = messageInput.value;
    isSending.value = true;
    try {
      const response = await api.post(`/api/pkg/chat/${chatPublicId}/send`, {
        content: content
      });
      
      // Optimistically add message or wait for pulse? 
      // For Lab, let's just push to local list for now
      messages.value.push(response.data.data);
      messageInput.value = '';
      console.log(`[ChatWip] Message sent successfully`);
    } catch (error) {
      console.error('[ChatWip] Failed to send message:', error);
    } finally {
      isSending.value = false;
    }
  }

  onMounted(() => {
    fetchMessages();

    if (chatPublicId && window.Echo) {
      console.log(`[ChatWip] Subscribing to pkg.dm.${chatPublicId}`);
      window.Echo.private(`pkg.dm.${chatPublicId}`)
        .listen('.MessageCreated', (e: any) => {
          console.log('[ChatWip] Real-time message received:', e);
          if (e.message && !messages.value.find(m => m.id === e.message.id)) {
            messages.value.push(e.message);
          }
        });
    }
  });

  return {
    messages,
    isLoading,
    isSending,
    messageInput,
    fetchMessages,
    sendMessage
  };
}
