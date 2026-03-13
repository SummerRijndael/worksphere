import { defineStore } from 'pinia';
import { ref, computed, watch } from 'vue';
import type { Chat } from '@/types/models/chat';
import { useAuthStore } from '@/stores/auth';

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
  
  const DEFAULT_ACTIVE_TAB: 'chats' | 'groups' | 'people' | 'invites' = 'chats';
  const DEFAULT_ANCHORING_MODE: 'free' | 'docked' = 'docked';
  const DEFAULT_NEXT_Z_INDEX = 100;

  const isLauncherOpen = ref(false);
  const activeTab = ref<'chats' | 'groups' | 'people' | 'invites'>(DEFAULT_ACTIVE_TAB);
  const windows = ref<Map<string, MiniChatWindow>>(new Map());
  const nextZIndex = ref(DEFAULT_NEXT_Z_INDEX);
  const anchoringMode = ref<'free' | 'docked'>(DEFAULT_ANCHORING_MODE);
  
  // Max visible chat heads when minimized
  const MAX_VISIBLE_HEADS = 5;
  const DOCK_WINDOW_WIDTH = 335;
  const DOCK_WINDOW_GAP = 10;
  const DOCK_START_RIGHT = 75;
  const DOCK_START_BOTTOM = 0;

  // Persistence Logic
  const authStore = useAuthStore();
  
  function getStorageKey() {
    return `worksphere_minichat_${authStore.user?.public_id || 'guest'}`;
  }

  function resetSettingsToDefaults() {
    activeTab.value = DEFAULT_ACTIVE_TAB;
    anchoringMode.value = DEFAULT_ANCHORING_MODE;
  }
  
  function loadFromStorage() {
      try {
          resetSettingsToDefaults();

          const data = localStorage.getItem(getStorageKey());
          if (data) {
              const parsed = JSON.parse(data);

              if (parsed.anchoringMode === 'free' || parsed.anchoringMode === 'docked') {
                anchoringMode.value = parsed.anchoringMode;
              }

              if (parsed.activeTab === 'chats' || parsed.activeTab === 'groups' || parsed.activeTab === 'people' || parsed.activeTab === 'invites') {
                activeTab.value = parsed.activeTab;
              }
          }
      } catch (e) {
          console.warn('Failed to load minichat settings', e);
      }
  }

  function saveToStorage() {
      const data = {
          anchoringMode: anchoringMode.value,
          activeTab: activeTab.value
      };
      localStorage.setItem(getStorageKey(), JSON.stringify(data));
  }

  // Watch for changes to persist
  watch([anchoringMode, activeTab], () => {
      saveToStorage();
  });

  // Watch for user change to reload
  watch(() => authStore.user?.public_id, (newPublicId, oldPublicId) => {
      // Security: drop all volatile window/chat UI state when user session changes.
      if (newPublicId !== oldPublicId) {
        clearSessionState();
      }
      loadFromStorage();
  });
  
  // Initial load
  loadFromStorage();
  
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
    
    wins.forEach((win, index) => {
      const right = DOCK_START_RIGHT + (index * (DOCK_WINDOW_WIDTH + DOCK_WINDOW_GAP));
      const bottom = DOCK_START_BOTTOM; // Docked to bottom
      
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
    let rightOffset = DOCK_START_RIGHT;
    let bottomOffset = 90;
    
    if (anchoringMode.value === 'docked') {
        const openCount = openWindows.value.length;
        rightOffset = DOCK_START_RIGHT + (openCount * (DOCK_WINDOW_WIDTH + DOCK_WINDOW_GAP));
        bottomOffset = DOCK_START_BOTTOM;
    } else {
        // Free mode: Stack diagonally
        const openCount = openWindows.value.length;
        rightOffset = DOCK_START_RIGHT + (openCount * 30);
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

  function clearSessionState() {
    isLauncherOpen.value = false;
    windows.value.clear();
    nextZIndex.value = DEFAULT_NEXT_Z_INDEX;
    resetSettingsToDefaults();
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
    clearSessionState,
    minimizeAllWindows,
  };
});
