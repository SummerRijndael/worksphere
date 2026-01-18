import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import type { Chat } from '@/types/models/chat';

export interface MiniChatWindow {
  chatId: string;
  chat: Chat;
  isMinimized: boolean;
  position: { right: number; bottom: number };
  zIndex: number;
}

export const useMiniChatStore = defineStore('minichat', () => {
  // ============================================================================
  // State
  // ============================================================================
  
  const isLauncherOpen = ref(false);
  const activeTab = ref<'chats' | 'groups' | 'people' | 'invites'>('chats');
  const windows = ref<Map<string, MiniChatWindow>>(new Map());
  const nextZIndex = ref(100);
  const anchoringMode = ref<'free' | 'docked'>('docked');
  
  // Max visible chat heads when minimized
  const MAX_VISIBLE_HEADS = 5;
  
  // ============================================================================
  // Computed
  // ============================================================================
  
  const openWindows = computed(() => {
    return Array.from(windows.value.values()).filter(w => !w.isMinimized);
  });
  
  const minimizedWindows = computed(() => {
    return Array.from(windows.value.values()).filter(w => w.isMinimized);
  });
  
  const visibleHeads = computed(() => {
    return minimizedWindows.value.slice(0, MAX_VISIBLE_HEADS);
  });
  
  const overflowCount = computed(() => {
    return Math.max(0, minimizedWindows.value.length - MAX_VISIBLE_HEADS);
  });
  
  const hasOpenWindows = computed(() => openWindows.value.length > 0);
  
  const isDocked = computed(() => anchoringMode.value === 'docked');
  
  // ============================================================================
  // Actions
  // ============================================================================
  
  function toggleLauncher() {
    isLauncherOpen.value = !isLauncherOpen.value;
  }
  
  function closeLauncher() {
    isLauncherOpen.value = false;
  }
  
  function setActiveTab(tab: 'chats' | 'groups' | 'people' | 'invites') {
    activeTab.value = tab;
  }

  function setAnchoringMode(mode: 'free' | 'docked') {
    anchoringMode.value = mode;
    // Re-arrange windows if switching to docked
    if (mode === 'docked') {
      rearrangeDockedWindows();
    }
  }

  function rearrangeDockedWindows() {
    const wins = openWindows.value;
    const windowWidth = 340;
    const gap = 12; // Adjusted gap
    const startRight = 90; // Launcher width + margin
    
    wins.forEach((win, index) => {
      const right = startRight + (index * (windowWidth + gap));
      const bottom = 0; // Docked to bottom
      
      const updatedWin = windows.value.get(win.chatId);
      if (updatedWin) {
        updatedWin.position = { right, bottom };
      }
    });
  }
  
  function openChatWindow(chat: Chat) {
    const chatId = chat.public_id;
    
    // If already open, bring to front
    if (windows.value.has(chatId)) {
      const existing = windows.value.get(chatId)!;
      existing.isMinimized = false;
      existing.zIndex = nextZIndex.value++;
      
      if (anchoringMode.value === 'docked') {
        rearrangeDockedWindows();
      }
      return;
    }
    
    // Calculate position for new window
    let rightOffset = 90;
    let bottomOffset = 90;
    
    // CONSTANTS
    const WINDOW_GAP = 12; // Adjust gap here
    
    if (anchoringMode.value === 'docked') {
        const windowWidth = 340;
        const openCount = openWindows.value.length;
        rightOffset = 90 + (openCount * (windowWidth + WINDOW_GAP));
        bottomOffset = 0;
    } else {
        // Free mode: Stack diagonally
        const openCount = openWindows.value.length;
        rightOffset = 90 + (openCount * 30);
        bottomOffset = 90 + (openCount * 30);
    }
    
    windows.value.set(chatId, {
      chatId,
      chat,
      isMinimized: false,
      position: { 
        right: rightOffset,
        bottom: bottomOffset,
      },
      zIndex: nextZIndex.value++,
    });
    
    // Close launcher when opening a chat
    closeLauncher();
  }
  
  function closeChatWindow(chatId: string) {
    windows.value.delete(chatId);
    if (anchoringMode.value === 'docked') {
      rearrangeDockedWindows();
    }
  }
  
  function minimizeChatWindow(chatId: string) {
    const win = windows.value.get(chatId);
    if (win) {
      win.isMinimized = true;
      if (anchoringMode.value === 'docked') {
        rearrangeDockedWindows();
      }
    }
  }
  
  function restoreChatWindow(chatId: string) {
    const win = windows.value.get(chatId);
    if (win) {
      win.isMinimized = false;
      win.zIndex = nextZIndex.value++;
      if (anchoringMode.value === 'docked') {
        rearrangeDockedWindows();
      }
    }
  }
  
  function bringToFront(chatId: string) {
    const win = windows.value.get(chatId);
    if (win) {
      win.zIndex = nextZIndex.value++;
    }
  }
  
  function updateWindowPosition(chatId: string, position: { right: number; bottom: number }) {
    const win = windows.value.get(chatId);
    if (win) {
      // In docked mode, prevent Y-axis movement (mostly) or allow dragging out of dock?
      // For simplicity, let's say dragging disables docking for that specific window or just updates position freely.
      // If user drags, it might break the dock layout.
      // Let's allow free drag even in docked mode, but snapping back would be complex.
      // For now, just update.
      win.position = position;
    }
  }
  
  function updateChatInWindow(chatId: string, chat: Chat) {
    const win = windows.value.get(chatId);
    if (win) {
      win.chat = chat;
    }
  }
  
  function closeAllWindows() {
    windows.value.clear();
  }
  
  function minimizeAllWindows() {
    windows.value.forEach(win => {
      win.isMinimized = true;
    });
  }
  
  // ============================================================================
  // Return
  // ============================================================================
  
  return {
    // State
    isLauncherOpen,
    activeTab,
    windows,
    anchoringMode,
    
    // Computed
    openWindows,
    minimizedWindows,
    visibleHeads,
    overflowCount,
    hasOpenWindows,
    isDocked,
    
    // Actions
    toggleLauncher,
    closeLauncher,
    setActiveTab,
    setAnchoringMode,
    openChatWindow,
    closeChatWindow,
    minimizeChatWindow,
    restoreChatWindow,
    bringToFront,
    updateWindowPosition,
    updateChatInWindow,
    closeAllWindows,
    minimizeAllWindows,
  };
}, {
  persist: {
    key: 'coresync-minichat',
    paths: ['anchoringMode', 'activeTab'],
  },
});
