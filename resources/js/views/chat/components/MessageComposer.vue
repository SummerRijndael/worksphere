<script setup lang="ts">
import { ref, computed } from 'vue';
import type { Message } from '@/types/models/chat';

interface Props {
  replyingTo?: Message | null;
  pendingFiles?: File[];
  disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  replyingTo: null,
  pendingFiles: () => [],
  disabled: false,
});

const emit = defineEmits<{
  send: [content: string];
  'cancel-reply': [];
  'add-files': [files: FileList];
  'remove-file': [index: number];
  'typing': [];
}>();

const inputValue = ref('');
const fileInputRef = ref<HTMLInputElement | null>(null);

const canSend = computed(() => {
  return !props.disabled && (inputValue.value.trim() || props.pendingFiles?.length > 0);
});

function handleSend() {
  if (!canSend.value) return;
  emit('send', inputValue.value);
  inputValue.value = '';
}

function handleKeyDown(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    handleSend();
  }
}

function handleInput() {
  emit('typing');
}

function openFilePicker() {
  fileInputRef.value?.click();
}

function handleFileChange(e: Event) {
  const input = e.target as HTMLInputElement;
  if (input.files && input.files.length > 0) {
    emit('add-files', input.files);
  }
  input.value = '';
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function getFileIcon(file: File): string {
  if (file.type.startsWith('image/')) return '🖼️';
  if (file.type.startsWith('video/')) return '🎬';
  if (file.type.startsWith('audio/')) return '🎵';
  if (file.type.includes('pdf')) return '📄';
  return '📎';
}
</script>

<template>
  <div class="border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
    <!-- Reply preview -->
    <div v-if="replyingTo" class="flex items-center gap-3 px-4 py-2 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
      <div class="flex items-center gap-2 flex-1 min-w-0">
        <div class="w-1 h-10 bg-blue-500 rounded"></div>
        <div class="flex-1 min-w-0">
          <span class="font-medium text-sm">{{ replyingTo.user_name }}</span>
          <p class="text-sm text-gray-600 dark:text-gray-400 truncate">
            {{ replyingTo.attachments.length > 0 ? '📎 Attachment' : replyingTo.content }}
          </p>
        </div>
      </div>
      <button
        type="button"
        class="p-1 rounded-full text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
        @click="emit('cancel-reply')"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Pending files -->
    <div v-if="pendingFiles && pendingFiles.length > 0" class="flex flex-wrap gap-2 px-4 py-2 bg-gray-50 dark:bg-gray-800">
      <div
        v-for="(file, index) in pendingFiles"
        :key="index"
        class="flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-700 rounded-lg shadow-sm"
      >
        <span class="text-lg">{{ getFileIcon(file) }}</span>
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium truncate">{{ file.name }}</div>
          <div class="text-xs text-gray-500">{{ formatFileSize(file.size) }}</div>
        </div>
        <button
          type="button"
          class="p-1 rounded-full text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
          @click="emit('remove-file', index)"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Input area -->
    <div class="flex items-end gap-2 p-3">
      <!-- Attach button -->
      <button
        type="button"
        class="p-2 rounded-full text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="disabled"
        @click="openFilePicker"
        title="Attach files"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
        </svg>
      </button>
      
      <input
        ref="fileInputRef"
        type="file"
        class="hidden"
        multiple
        accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip"
        @change="handleFileChange"
      />

      <!-- Text input -->
      <textarea
        v-model="inputValue"
        class="flex-1 max-h-32 px-4 py-2 rounded-2xl resize-none bg-gray-100 dark:bg-gray-800 border-0 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500"
        placeholder="Type a message..."
        rows="1"
        :disabled="disabled"
        @keydown="handleKeyDown"
        @input="handleInput"
      />

      <!-- Send button -->
      <button
        type="button"
        class="p-2 rounded-full transition-colors disabled:cursor-not-allowed"
        :class="canSend ? 'text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20' : 'text-gray-400'"
        :disabled="!canSend"
        @click="handleSend"
        title="Send message"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
        </svg>
      </button>
    </div>
  </div>
</template>
