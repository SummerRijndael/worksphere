<template>
  <div class="space-y-6">
      <!-- Connection Status Header -->
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium">Chat Realtime Debugger</h2>
        <div class="flex items-center gap-4">
           <div class="flex items-center gap-2 px-3 py-1 rounded-full bg-white dark:bg-gray-800 border" :class="connectionColor">
             <div class="w-2 h-2 rounded-full" :class="connectionDot"></div>
             <span class="text-sm font-medium">{{ connectionStatus }}</span>
           </div>
        </div>
      </div>

      <!-- Selectors Row -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- Sender Selector -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sender (Act As)</label>
          <select v-model="selectedSender" class="w-full px-3 py-2 border rounded-md dark:bg-gray-700 dark:border-gray-600">
            <option :value="null">Select sender...</option>
            <option v-for="user in users" :key="user.id" :value="user">
              {{ user.name }} ({{ user.email }})
            </option>
          </select>
        </div>

        <!-- Chat Selector -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Target Chat</label>
          <select v-model="selectedChat" class="w-full px-3 py-2 border rounded-md dark:bg-gray-700 dark:border-gray-600">
            <option :value="null">Select chat...</option>
            <option v-for="chat in chats" :key="chat.public_id" :value="chat">
              {{ chat.name || chatLabel(chat) }} ({{ chat.type }})
            </option>
          </select>
          <p v-if="selectedChat" class="text-xs text-gray-500 mt-1">
            Participants: {{ selectedChat.participants.map((p: any) => p.name).join(', ') }}
          </p>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Simulate Events</h3>
        <div class="flex flex-wrap gap-3">
          <!-- Send Message -->
          <div class="flex gap-2 items-center">
            <input 
              v-model="messageContent" 
              type="text" 
              placeholder="Message content..." 
              class="px-3 py-2 border rounded-md text-sm dark:bg-gray-700 dark:border-gray-600 w-64"
            />
            <button 
              @click="sendMessage" 
              :disabled="!canAct || isLoading"
              class="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 disabled:opacity-50"
            >
              {{ isLoading ? '...' : 'Send Message' }}
            </button>
          </div>

          <!-- Typing Indicator -->
          <button 
            @click="triggerTyping" 
            :disabled="!canAct || isLoading"
            class="px-4 py-2 bg-amber-500 text-white text-sm rounded-md hover:bg-amber-600 disabled:opacity-50"
          >
            Trigger Typing
          </button>

          <!-- Mark Seen -->
          <button 
            @click="markSeen" 
            :disabled="!canAct || isLoading"
            class="px-4 py-2 bg-green-600 text-white text-sm rounded-md hover:bg-green-700 disabled:opacity-50"
          >
            Mark Seen
          </button>
        </div>
        <p v-if="!canAct" class="text-xs text-red-500 mt-2">Select both a sender and a chat to enable actions.</p>
      </div>

      <!-- Debug Layout -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Active Channels -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <h2 class="text-lg font-medium mb-4 flex justify-between">
            <span>Active Channels</span>
            <span class="text-xs bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded">{{ channels.length }}</span>
          </h2>
          <div class="space-y-2 max-h-72 overflow-y-auto">
            <div v-if="channels.length === 0" class="text-gray-400 text-sm italic">No active subscriptions</div>
            <div v-for="channel in channels" :key="channel.name" class="p-2 bg-gray-50 dark:bg-gray-900 rounded border dark:border-gray-700 text-sm">
              <div class="font-mono text-blue-600 dark:text-blue-400">{{ channel.name }}</div>
            </div>
          </div>
        </div>

        <!-- Event Log -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 flex flex-col h-[400px]">
          <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-medium">Realtime Event Log</h2>
            <button @click="clearLogs" class="text-xs text-red-600 hover:text-red-800">Clear</button>
          </div>
          <div ref="logContainer" class="flex-1 overflow-y-auto bg-gray-900 text-gray-300 p-4 rounded font-mono text-xs space-y-1">
            <div v-if="logs.length === 0" class="text-gray-600 italic">Waiting for events...</div>
            <div v-for="(log, idx) in logs" :key="idx" class="border-b border-gray-800 pb-1 mb-1 last:border-0 hover:bg-gray-800">
              <span class="text-gray-500">[{{ log.time }}]</span>
              <span :class="log.type === 'out' ? 'text-blue-400' : 'text-yellow-500'" class="ml-2">{{ log.event }}</span>
              <div class="ml-14 text-green-400 overflow-x-hidden whitespace-pre-wrap break-all">{{ log.channel }}</div>
              <div class="ml-14 text-gray-400 overflow-hidden text-ellipsis whitespace-nowrap opacity-60">
                 {{ JSON.stringify(log.data) }}
              </div>
            </div>
          </div>
        </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue';
import axios from 'axios';

// Data
const users = ref<any[]>([]);
const chats = ref<any[]>([]);
const selectedSender = ref<any>(null);
const selectedChat = ref<any>(null);
const messageContent = ref('Test message ' + Date.now());
const isLoading = ref(false);
const logs = ref<any[]>([]);
const channels = ref<any[]>([]);
const logContainer = ref<HTMLElement | null>(null);

// Computed
const canAct = computed(() => selectedSender.value && selectedChat.value);

const connectionStatus = computed(() => {
    if (window.Echo && window.Echo.connector) {
        const socket = window.Echo.connector.socket || window.Echo.connector.pusher?.connection;
        if (socket && typeof socket.connected !== 'undefined') {
             return socket.connected ? 'Connected' : 'Disconnected';
        }
        if (window.Echo.connector.pusher?.connection?.state === 'connected') {
            return 'Connected';
        }
    }
    return 'Unknown';
});

const connectionColor = computed(() => {
    if (connectionStatus.value === 'Connected') return 'border-green-200 bg-green-50 text-green-700 dark:bg-green-900/30 dark:border-green-800 dark:text-green-300';
    return 'border-red-200 bg-red-50 text-red-700 dark:bg-red-900/30 dark:border-red-800 dark:text-red-300';
});

const connectionDot = computed(() => {
    if (connectionStatus.value === 'Connected') return 'bg-green-500';
    return 'bg-red-500';
});

function chatLabel(chat: any) {
    return chat.participants?.map((p: any) => p.name).slice(0, 2).join(' & ') || 'Chat';
}

function addLog(type: 'in' | 'out' | 'error', event: string, channel: string, data: any) {
    logs.value.unshift({
        time: new Date().toLocaleTimeString(),
        type,
        event,
        channel,
        data
    });
    if (logs.value.length > 50) logs.value.pop();
}

function clearLogs() {
    logs.value = [];
}

// API Calls
async function loadData() {
    try {
        const [usersRes, chatsRes] = await Promise.all([
            axios.get('/api/dev/users'),
            axios.get('/api/dev/chats')
        ]);
        users.value = usersRes.data;
        chats.value = chatsRes.data;
    } catch (e) {
        console.error('Failed to load data', e);
    }
}

async function sendMessage() {
    if (!canAct.value) return;
    isLoading.value = true;
    try {
        const res = await axios.post('/api/dev/chat/send-message', {
            as_user_id: selectedSender.value.id,
            chat_public_id: selectedChat.value.public_id,
            content: messageContent.value || 'Test message'
        });
        addLog('out', 'SENT MessageCreated', res.data.channel, res.data);
        messageContent.value = 'Test message ' + Date.now(); // Reset
    } catch (e: any) {
        addLog('error', 'ERROR sendMessage', '-', e.response?.data || e.message);
    } finally {
        isLoading.value = false;
    }
}

async function triggerTyping() {
    if (!canAct.value) return;
    isLoading.value = true;
    try {
        const res = await axios.post('/api/dev/chat/typing', {
            as_user_id: selectedSender.value.id,
            chat_public_id: selectedChat.value.public_id
        });
        addLog('out', 'SENT TypingStarted', res.data.channel, res.data);
    } catch (e: any) {
        addLog('error', 'ERROR triggerTyping', '-', e.response?.data || e.message);
    } finally {
        isLoading.value = false;
    }
}

async function markSeen() {
    if (!canAct.value) return;
    isLoading.value = true;
    try {
        const res = await axios.post('/api/dev/chat/mark-seen', {
            as_user_id: selectedSender.value.id,
            chat_public_id: selectedChat.value.public_id
        });
        addLog('out', 'SENT MessageRead', res.data.channel, res.data);
    } catch (e: any) {
        addLog('error', 'ERROR markSeen', '-', e.response?.data || e.message);
    } finally {
        isLoading.value = false;
    }
}

// Echo Integration
let statusInterval: any = null;
const listeningChannels = new Set<string>();

function scanEcho() {
    if (!window.Echo) return;
    const connector = window.Echo.connector;
    if (connector && connector.channels) {
        channels.value = Object.keys(connector.channels).map(key => ({
            name: key
        }));
    }
}

function attachListeners() {
    if (!window.Echo || !window.Echo.connector || !window.Echo.connector.channels) return;
    const currentChannels = Object.values(window.Echo.connector.channels) as any[];
    
    currentChannels.forEach(channel => {
        if (!listeningChannels.has(channel.name)) {
            if (channel.bind_global) {
                channel.bind_global((event: string, data: any) => {
                    if (event.startsWith('pusher:')) return;
                    addLog('in', 'RECV ' + event, channel.name, data);
                });
                listeningChannels.add(channel.name);
            }
        }
    });
}

onMounted(() => {
    loadData();
    scanEcho();
    attachListeners();
    
    statusInterval = setInterval(() => {
        scanEcho();
        attachListeners();
    }, 1000);
});

onUnmounted(() => {
    clearInterval(statusInterval);
});
</script>
