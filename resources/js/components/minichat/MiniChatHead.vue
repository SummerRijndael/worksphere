<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useMiniChatStore, type MiniChatWindow } from '@/stores/minichat';
import { useAuthStore } from '@/stores/auth';
import { useChatStore } from '@/stores/chat';
import { Avatar, Icon } from '@/components/ui';

const props = defineProps<{
  window: MiniChatWindow;
}>();

const miniChatStore = useMiniChatStore();
const authStore = useAuthStore();
const chatStore = useChatStore();

const isWiggling = ref(false);
const isBadgePopping = ref(false);

const currentUserPublicId = computed(() => authStore.user?.public_id || '');

const chatTitle = computed(() => {
  const chat = props.window.chat;
  if (chat.name) return chat.name;
  if (chat.type === 'dm' && chat.participants.length) {
    const other = chat.participants.find(p => p.public_id !== currentUserPublicId.value);
    return other?.name || 'Chat';
  }
  return 'Group';
});

const chatAvatar = computed(() => {
  const chat = props.window.chat;
  if (chat.avatar_url) return chat.avatar_url;
  if (chat.type === 'dm' && chat.participants.length) {
    const other = chat.participants.find(p => p.public_id !== currentUserPublicId.value);
    return other?.avatar || null;
  }
  return null;
});

const unreadCount = computed(() => {
  const chat = chatStore.chats.find(c => c.public_id === props.window.chatId);
  return chat?.unread_count || 0;
});

// Watch for unread count changes to trigger animations
watch(unreadCount, (newCount, oldCount) => {
  if (newCount > oldCount) {
    // Trigger wiggle
    isWiggling.value = true;
    setTimeout(() => {
      isWiggling.value = false;
    }, 1000); // Duration matches animation

    // Trigger badge pop
    isBadgePopping.value = true;
    setTimeout(() => {
      isBadgePopping.value = false;
    }, 300);
  }
});

function handleRestore() {
  miniChatStore.restoreChatWindow(props.window.chatId);
}

function handleClose() {
  miniChatStore.closeChatWindow(props.window.chatId);
}
</script>

<template>
  <div 
    class="minichat-head"
    :class="{ 'is-wiggling': isWiggling }"
    @click="handleRestore"
  >
    <Avatar
      :src="chatAvatar"
      :name="chatTitle"
      size="lg"
      class="minichat-head-avatar"
    />
    
    <!-- Unread Badge -->
    <span 
      v-if="unreadCount > 0" 
      class="minichat-head-badge"
      :class="{ 'is-popping': isBadgePopping }"
    >
      {{ unreadCount > 9 ? '9+' : unreadCount }}
    </span>
    
    <!-- Close Button -->
    <button 
      class="minichat-head-close"
      @click.stop="handleClose"
    >
      <Icon name="X" :size="10" />
    </button>
    
    <!-- Tooltip -->
    <div class="minichat-head-tooltip">
      {{ chatTitle }}
    </div>
  </div>
</template>

<style scoped>
.minichat-head {
  position: relative;
  width: 48px;
  height: 48px;
  cursor: pointer;
  transition: transform 0.15s ease;
  /* Removed conflicting animation: headPopIn */
}

.minichat-head.is-wiggling {
  animation: wiggle 0.4s ease-in-out infinite;
}

@keyframes wiggle {
  0%, 100% { transform: rotate(0deg) scale(1.1); }
  25% { transform: rotate(-10deg) scale(1.1); }
  75% { transform: rotate(10deg) scale(1.1); }
}

.minichat-head:hover {
  transform: scale(1.1);
}

.minichat-head-avatar {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
  border: 2px solid var(--surface-elevated);
}

.minichat-head-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 9px;
  background: var(--color-error);
  color: white;
  font-size: 10px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
  transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

.minichat-head-badge.is-popping {
  transform: scale(1.3);
}

.minichat-head-close {
  position: absolute;
  top: -6px;
  left: -6px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: none;
  background: var(--surface-elevated);
  color: var(--text-secondary);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  transition: all 0.15s ease;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
}

.minichat-head:hover .minichat-head-close {
  opacity: 1;
}

.minichat-head-close:hover {
  background: var(--color-error);
  color: white;
}

.minichat-head-tooltip {
  position: absolute;
  bottom: 100%;
  left: 50%;
  transform: translateX(-50%);
  margin-bottom: 8px;
  padding: 6px 10px;
  border-radius: 6px;
  background: var(--surface-overlay);
  color: white;
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.15s ease;
}

.minichat-head:hover .minichat-head-tooltip {
  opacity: 1;
}
</style>
