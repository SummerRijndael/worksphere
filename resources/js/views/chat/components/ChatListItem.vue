<script setup lang="ts">
import { computed } from 'vue';
import type { Chat } from '@/types/models/chat';

interface Props {
  chat: Chat;
  isActive?: boolean;
  currentUserPublicId?: string;
}

const props = withDefaults(defineProps<Props>(), {
  isActive: false,
  currentUserPublicId: '',
});

const emit = defineEmits<{
  click: [];
}>();

// Get other participant for DM
const otherParticipant = computed(() => {
  if (props.chat.type !== 'dm') return null;
  return props.chat.participants.find(p => p.public_id !== props.currentUserPublicId) ?? props.chat.participants[0];
});

const chatTitle = computed(() => {
  if (props.chat.name) return props.chat.name;
  if (props.chat.type === 'dm' && otherParticipant.value) {
    return otherParticipant.value.name;
  }
  return 'Group Chat';
});

const avatarUrl = computed(() => {
  if (props.chat.avatar_url) return props.chat.avatar_url;
  if (props.chat.type === 'dm' && otherParticipant.value) {
    return otherParticipant.value.avatar;
  }
  return null;
});

const initials = computed(() => {
  const name = chatTitle.value;
  const words = name.split(' ');
  return words.slice(0, 2).map(w => w[0]?.toUpperCase()).join('');
});

const isOnline = computed(() => {
  if (props.chat.type === 'dm' && otherParticipant.value) {
    return otherParticipant.value.is_online;
  }
  return false;
});

const lastMessagePreview = computed(() => {
  const msg = props.chat.last_message;
  if (!msg) return '';
  if (msg.has_media) return '📎 Attachment';
  return msg.content ?? '';
});

const lastMessageTime = computed(() => {
  const msg = props.chat.last_message;
  if (!msg) return '';
  
  const date = new Date(msg.created_at);
  const now = new Date();
  const diffDays = Math.floor((now.getTime() - date.getTime()) / (1000 * 60 * 60 * 24));
  
  if (diffDays === 0) {
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  } else if (diffDays === 1) {
    return 'Yesterday';
  } else if (diffDays < 7) {
    return date.toLocaleDateString([], { weekday: 'short' });
  }
  return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
});

const unreadCount = computed(() => props.chat.unread_count ?? 0);

const itemClasses = computed(() => {
  const base = 'w-full flex items-center gap-3 p-3 rounded-lg transition-colors text-left hover:bg-gray-100 dark:hover:bg-gray-800';
  const active = props.isActive ? 'bg-blue-50 dark:bg-blue-900/20' : '';
  return [base, active].filter(Boolean).join(' ');
});
</script>

<template>
  <button
    type="button"
    :class="itemClasses"
    @click="emit('click')"
  >
    <!-- Avatar -->
    <div class="relative shrink-0">
      <img
        v-if="avatarUrl"
        :src="avatarUrl"
        :alt="chatTitle"
        class="w-12 h-12 rounded-full object-cover"
      />
      <div v-else class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-semibold text-sm">
        {{ initials }}
      </div>
      
      <!-- Online indicator for DMs -->
      <span
        v-if="chat.type === 'dm'"
        class="absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-white dark:border-gray-900"
        :class="isOnline ? 'bg-green-500' : 'bg-gray-400'"
      />
    </div>

    <!-- Content -->
    <div class="flex-1 min-w-0">
      <div class="flex items-center justify-between gap-2">
        <span class="font-medium text-gray-900 dark:text-white truncate">{{ chatTitle }}</span>
        <span v-if="lastMessageTime" class="text-xs text-gray-500 dark:text-gray-400 shrink-0">{{ lastMessageTime }}</span>
      </div>
      
      <div class="flex items-center justify-between gap-2 mt-0.5">
        <span class="text-sm text-gray-600 dark:text-gray-400 truncate" :class="{ 'font-semibold': unreadCount > 0 }">
          {{ lastMessagePreview }}
        </span>
        <span v-if="unreadCount > 0" class="shrink-0 min-w-[20px] h-5 px-1.5 rounded-full bg-blue-600 text-white text-xs font-medium flex items-center justify-center">
          {{ unreadCount > 99 ? '99+' : unreadCount }}
        </span>
      </div>
    </div>
  </button>
</template>
