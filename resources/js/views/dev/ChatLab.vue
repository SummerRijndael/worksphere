<script setup lang="ts">
import { ref } from 'vue';
import { useChatWip } from '@/packages/chat/composables/useChatWip';
import axios from 'axios';

const activeChatId = ref<string | null>(localStorage.getItem('lab_chat_id'));
const { messageInput, sendMessage, messages, isLoading, isSending, fetchMessages } = useChatWip(activeChatId.value || '');

const joinIdInput = ref('');

async function seedLab() {
  try {
    const response = await axios.get('/api/pkg/chat/seed-lab');
    const newChatId = response.data.data.public_id;
    activeChatId.value = newChatId;
    localStorage.setItem('lab_chat_id', newChatId);
    window.location.reload(); 
  } catch (error) {
    console.error('Failed to seed lab:', error);
    alert('Failed to seed lab. Check console.');
  }
}

async function joinLab() {
  if (!joinIdInput.value.trim()) return;
  try {
    const response = await axios.post(`/api/pkg/chat/${joinIdInput.value.trim()}/join`);
    const newChatId = response.data.data.id;
    activeChatId.value = newChatId;
    localStorage.setItem('lab_chat_id', newChatId);
    window.location.reload();
  } catch (error) {
    console.error('Failed to join lab:', error);
    alert('Failed to join. Make sure the ID is correct.');
  }
}

function copyId() {
  if (!activeChatId.value) return;
  navigator.clipboard.writeText(activeChatId.value);
  alert('Chat ID copied to clipboard!');
}

function clearLab() {
  localStorage.removeItem('lab_chat_id');
  activeChatId.value = null;
  window.location.reload();
}
</script>

<template>
  <div class="p-8 max-w-4xl mx-auto">
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
          Chat Lab <span class="text-xs bg-purple-100 text-purple-600 px-2 py-0.5 rounded-full font-medium">Real-time Enabled</span>
        </h1>
        <p class="text-gray-500 mt-1">Standalone package development environment.</p>
      </div>
      <div class="flex gap-2">
        <button 
          v-if="activeChatId"
          class="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50 transition"
          @click="clearLab"
        >
          Reset Lab
        </button>
      </div>
    </div>

    <div v-if="activeChatId" class="grid grid-cols-1 gap-6">
      <div class="border rounded-xl shadow-lg bg-white overflow-hidden flex flex-col h-[600px]">
        <!-- Header -->
        <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
          <div class="flex items-center gap-3">
            <div class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></div>
            <span class="font-semibold text-gray-700">Live Lab: {{ activeChatId }}</span>
            <button @click="copyId" class="text-[10px] bg-gray-200 hover:bg-gray-300 px-2 py-1 rounded">Copy ID</button>
          </div>
          <button @click="fetchMessages" class="text-xs text-indigo-600 hover:underline">Sync State</button>
        </div>

        <!-- Messages Area -->
        <div class="flex-1 overflow-y-auto p-6 space-y-4 bg-white">
          <div v-if="isLoading" class="flex justify-center py-12">
            <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-indigo-600"></div>
          </div>
          
          <div v-else-if="messages.length === 0" class="flex flex-col items-center justify-center py-20 text-gray-400">
            <svg class="w-20 h-20 mb-4 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
            </svg>
            <p>Waiting for messages...</p>
          </div>

          <div v-for="msg in messages" :key="msg.id" class="flex flex-col">
            <div :class="[
              'max-w-[80%] rounded-2xl px-4 py-2 text-sm shadow-sm',
              msg.type === 'system' ? 'self-center bg-gray-100 text-gray-500 italic text-xs' : 'self-start bg-indigo-50 text-indigo-900 border border-indigo-100'
            ]">
              <div v-if="msg.type !== 'system'" class="text-xs font-bold mb-1 text-indigo-400">
                {{ msg.user_name || 'User' }}
              </div>
              {{ msg.content }}
            </div>
            <div class="text-[10px] text-gray-400 mt-1 ml-1">
              {{ new Date(msg.created_at).toLocaleTimeString() }}
            </div>
          </div>
        </div>

        <!-- Input Area -->
        <div class="p-4 border-t bg-gray-50">
          <div class="flex gap-3">
            <input 
              v-model="messageInput"
              type="text" 
              placeholder="Type your lab message..." 
              class="flex-1 px-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white"
              @keyup.enter="sendMessage"
            >
            <button 
              class="px-6 py-3 bg-indigo-600 text-white rounded-xl font-semibold hover:bg-indigo-700 disabled:opacity-50 shadow-md transition-all active:scale-95"
              :disabled="isSending || !messageInput.trim()"
              @click="sendMessage"
            >
              <span v-if="isSending">...</span>
              <span v-else>Send</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Debug Info -->
      <div class="p-4 bg-gray-800 rounded-lg text-xs text-green-400 font-mono overflow-auto">
        <div>// Lab Debug Info</div>
        <div>Chat ID: {{ activeChatId }}</div>
        <div>Channel: pkg.dm.{{ activeChatId }}</div>
        <div>Real-time: Listen('.MessageCreated')</div>
        <div class="mt-2 text-gray-500">// Share this ID with another user to test 2-player mode.</div>
      </div>
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-8 py-20">
      <div class="text-center p-8 bg-indigo-50 rounded-3xl border-2 border-indigo-100 flex flex-col items-center justify-center">
        <h2 class="text-2xl font-semibold text-indigo-900 mb-4">Start New Session</h2>
        <p class="text-indigo-600/70 mb-8">Creates a fresh chat in the isolated package tables.</p>
        <button 
          class="px-8 py-4 bg-indigo-600 text-white rounded-xl font-bold text-lg hover:bg-indigo-700 shadow-xl transition-all hover:-translate-y-1 block w-full"
          @click="seedLab"
        >
          Create Lab Chat
        </button>
      </div>

      <div class="text-center p-8 bg-white border-2 border-dashed border-gray-200 rounded-3xl flex flex-col items-center justify-center">
        <h2 class="text-2xl font-semibold text-gray-700 mb-4">Join Existing</h2>
        <p class="text-gray-500 mb-8">Test multi-player by joining a chat started elsewhere.</p>
        <div class="w-full space-y-3">
          <input 
            v-model="joinIdInput"
            type="text" 
            placeholder="Enter Lab Chat ID (ULID)" 
            class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
          <button 
            class="px-8 py-4 bg-gray-800 text-white rounded-xl font-bold text-lg hover:bg-black transition-all block w-full"
            :disabled="!joinIdInput.trim()"
            @click="joinLab"
          >
            Join Experience
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
