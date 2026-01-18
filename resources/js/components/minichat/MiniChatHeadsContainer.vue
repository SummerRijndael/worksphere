<script setup lang="ts">
import { useMiniChatStore } from '@/stores/minichat';
import MiniChatHead from './MiniChatHead.vue';

const miniChatStore = useMiniChatStore();

function handleOverflowClick() {
  // Show all minimized windows when clicking overflow
  miniChatStore.minimizedWindows.forEach(win => {
    miniChatStore.restoreChatWindow(win.chatId);
  });
}
</script>

<template>
  <div 
    v-if="miniChatStore.minimizedWindows.length > 0" 
    class="minichat-heads-container"
  >
    <!-- Visible Heads -->
    <TransitionGroup name="head-pop" tag="div" class="minichat-heads-list">
      <MiniChatHead
        v-for="win in miniChatStore.visibleHeads"
        :key="win.chatId"
        :window="win"
      />
    </TransitionGroup>
    
    <!-- Overflow Indicator -->
    <button
      v-if="miniChatStore.overflowCount > 0"
      class="minichat-heads-overflow"
      @click="handleOverflowClick"
    >
      +{{ miniChatStore.overflowCount }}
    </button>
  </div>
</template>

<style scoped>
.minichat-heads-container {
  display: flex;
  flex-direction: column-reverse;
  align-items: center;
  gap: 8px;
  padding-bottom: 8px;
  position: relative;
}

.minichat-heads-overflow {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  border: 2px solid var(--border-default);
  background: var(--surface-elevated);
  color: var(--text-primary);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.minichat-heads-overflow:hover {
  transform: scale(1.1);
  background: var(--interactive-primary);
  color: white;
  border-color: var(--interactive-primary);
}

.minichat-heads-list {
  display: flex;
  flex-direction: column-reverse;
  gap: 8px;
}

/* List Transitions */
.head-pop-enter-active,
.head-pop-leave-active {
  transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.head-pop-enter-from,
.head-pop-leave-to {
  opacity: 0;
  transform: scale(0) translateY(20px);
}

.head-pop-leave-active {
  position: absolute;
}

.head-pop-move {
  transition: all 0.3s ease;
}
</style>
