<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { adminChatService } from '@/services/admin-chat.service';
import type { Chat } from '@/types/models/chat';
import { Button, Card, Icon, PageLoader, Alert } from '@/components/ui';
import { useTitle } from '@vueuse/core';

useTitle('Chat Management - CoreSync');

const chats = ref<Chat[]>([]);
const loading = ref(true);
const restoringId = ref<string | null>(null);

const fetchChats = async () => {
  loading.value = true;
  try {
    chats.value = await adminChatService.getFlaggedChats();
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }
};

const handleRestore = async (chat: Chat) => {
    if (!confirm(`Are you sure you want to restore "${chat.name || 'this chat'}"?`)) return;
    
    restoringId.value = chat.public_id;
    try {
        await adminChatService.restoreChat(chat.public_id);
        // Remove from list locally
        chats.value = chats.value.filter(c => c.public_id !== chat.public_id);
    } catch (e) {
        console.error(e);
    } finally {
        restoringId.value = null;
    }
};

onMounted(() => {
    fetchChats();
});
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-[var(--text-primary)]">Chat Management</h1>
        <p class="text-[var(--text-secondary)]">Manage inactive and flagged group chats.</p>
      </div>
      <Button variant="outline" @click="fetchChats">
        <Icon name="RefreshCw" class="mr-2 h-4 w-4" />
        Refresh
      </Button>
    </div>

    <!-- Info Alert -->
    <Alert variant="warning" class="bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800">

        <div class="text-amber-800 dark:text-amber-200">
            Chats listed below have been flagged for deletion due to inactivity (60+ days) or policy violations. They will be permanently deleted after the grace period expires.
        </div>
    </Alert>

    <div v-if="loading" class="flex justify-center py-12">
        <PageLoader />
    </div>

    <Card v-else class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-[var(--text-secondary)] uppercase bg-[var(--surface-tertiary)] border-b border-[var(--border-default)]">
                    <tr>
                        <th class="px-6 py-3 font-medium">Chat Name</th>
                        <th class="px-6 py-3 font-medium">Participants</th>
                        <th class="px-6 py-3 font-medium">Last Activity</th>
                        <th class="px-6 py-3 font-medium">Flagged At</th>
                        <th class="px-6 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-default)]">
                    <tr v-if="chats.length === 0">
                        <td colspan="5" class="px-6 py-12 text-center text-[var(--text-tertiary)]">
                            No flagged chats found. Everything is clean!
                        </td>
                    </tr>
                    <tr v-for="chat in chats" :key="chat.id" class="bg-[var(--surface-primary)] hover:bg-[var(--surface-tertiary)] transition-colors">
                         <td class="px-6 py-4 font-medium text-[var(--text-primary)]">
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 rounded-lg bg-[var(--surface-tertiary)] flex items-center justify-center text-xs">
                                    {{ chat.name ? chat.name.substring(0,2).toUpperCase() : 'GC' }}
                                </div>
                                <div>
                                    <div>{{ chat.name || 'Unnamed Group' }}</div>
                                    <div class="text-xs text-[var(--text-tertiary)] font-normal">{{ chat.public_id }}</div>
                                </div>
                            </div>
                         </td>
                         <td class="px-6 py-4 text-[var(--text-secondary)]">
                            {{ chat.participants?.length || 0 }} members
                         </td>
                         <td class="px-6 py-4 text-[var(--text-secondary)]">
                            {{ chat.last_message?.created_at ? new Date(chat.last_message.created_at).toLocaleDateString() : 'Never' }}
                         </td>
                         <td class="px-6 py-4 text-red-500 font-medium">
                            <div class="flex items-center gap-1">
                                <Icon name="Clock" :size="14" />
                                {{ chat.marked_for_deletion_at ? new Date(chat.marked_for_deletion_at).toLocaleDateString() : 'Unknown' }}
                            </div>
                         </td>
                         <td class="px-6 py-4 text-right">
                            <Button 
                                size="sm" 
                                variant="outline" 
                                class="text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800"
                                :loading="restoringId === chat.public_id"
                                @click="handleRestore(chat)"
                            >
                                <Icon name="RotateCcw" class="mr-2 h-3.5 w-3.5" />
                                Restore
                            </Button>
                         </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
  </div>
</template>
