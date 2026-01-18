<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue';
import { useMiniChatStore } from '@/stores/minichat';
import { useChatStore } from '@/stores/chat';
import { useChatRealtime } from '@/composables/useChatRealtime';
import { Icon } from '@/components/ui';
import MiniChatPanel from './MiniChatPanel.vue';
import MiniChatWindow from './MiniChatWindow.vue';
import MiniChatHeadsContainer from './MiniChatHeadsContainer.vue';

const miniChatStore = useMiniChatStore();
const chatStore = useChatStore();
// Note: useChatRealtime restored for background updates (glow/badges)
const realtime = useChatRealtime();

const isGlowing = ref(false);
const unreadCount = computed(() => chatStore.totalUnreadCount);
const unseenInvites = computed(() => chatStore.unseenInviteCount);
const totalBadgeCount = computed(() => unreadCount.value + unseenInvites.value);

// Initialize realtime on mount
onMounted(async () => {
  // Fetch chats first if needed
  if (chatStore.chats.length === 0) {
    await chatStore.fetchChats();
  }
  
  // Background subscriptions for unread counts / badge glow
  // Bubbles up to chatStore which handles de-duplication if window is also listening
  realtime.initialize();
  realtime.subscribeToUserChannel();
  realtime.subscribeToAllChats();
});

// Watch for unread count changes to trigger glow
// Watch for badge changes to trigger glow
watch([unreadCount, unseenInvites], ([newUnread, newUnseen], [oldUnread, oldUnseen]) => {
  const isIncrease = newUnread > oldUnread || newUnseen > oldUnseen;
  
  // Glow when new items arrive and panel is closed
  if (isIncrease && !miniChatStore.isLauncherOpen) {
    isGlowing.value = true;
  }
});

function handleToggle() {
  // Stop glowing when user interacts
  isGlowing.value = false;
  
  // Mark invites as seen when opening the panel
  if (!miniChatStore.isLauncherOpen) {
    chatStore.markInvitesSeen();
  }
  miniChatStore.toggleLauncher();
}
</script>

<template>
  <div class="minichat-launcher-container">
    <!-- Minimized Chat Heads -->
    <MiniChatHeadsContainer />

    <!-- Floating Windows -->
    <MiniChatWindow
      v-for="win in miniChatStore.openWindows"
      :key="win.chatId"
      :window="win"
    />

    <!-- Launcher Panel -->
    <Transition name="minichat-panel">
      <MiniChatPanel 
        v-if="miniChatStore.isLauncherOpen" 
        @close="miniChatStore.closeLauncher()"
      />
    </Transition>

    <!-- Launcher Button -->
    <button
      class="minichat-launcher-btn"
      :class="{ 'is-active': miniChatStore.isLauncherOpen, 'is-glowing': isGlowing }"
      @click="handleToggle"
    >
      <Icon 
        :name="miniChatStore.isLauncherOpen ? 'X' : 'MessageCircle'" 
        :size="24" 
      />
      
      <!-- Unread Badge (includes invites) -->
      <span 
        v-if="totalBadgeCount > 0 && !miniChatStore.isLauncherOpen" 
        class="minichat-launcher-badge"
      >
        {{ totalBadgeCount > 99 ? '99+' : totalBadgeCount }}
      </span>
    </button>
  </div>
</template>

<style scoped>
.minichat-launcher-container {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 12px;
}

.minichat-launcher-btn {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--interactive-primary), var(--interactive-primary-hover));
  color: white;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 
    0 4px 14px rgba(0, 0, 0, 0.25),
    0 0 0 0 var(--interactive-primary);
  transition: all 0.2s ease;
  position: relative;
}

.minichat-launcher-btn:hover {
  transform: scale(1.05);
  box-shadow: 
    0 6px 20px rgba(0, 0, 0, 0.3),
    0 0 0 4px var(--interactive-primary)/20;
}

.minichat-launcher-btn.is-active {
  background: var(--surface-elevated);
  color: var(--text-primary);
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
}

.minichat-launcher-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 10px;
  background: var(--color-error);
  color: white;
  font-size: 11px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}

/* Glow animation for new messages */
.minichat-launcher-btn.is-glowing {
  animation: launcherGlow 0.5s ease-in-out infinite alternate;
}

@keyframes launcherGlow {
  from {
    box-shadow: 
      0 4px 14px rgba(0, 0, 0, 0.25),
      0 0 0 0 var(--interactive-primary);
  }
  to {
    box-shadow: 
      0 4px 14px rgba(0, 0, 0, 0.25),
      0 0 20px 8px var(--interactive-primary);
  }
}

/* Panel Transition */
.minichat-panel-enter-active,
.minichat-panel-leave-active {
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.minichat-panel-enter-from,
.minichat-panel-leave-to {
  opacity: 0;
  transform: translateY(20px) scale(0.95);
}
</style>
