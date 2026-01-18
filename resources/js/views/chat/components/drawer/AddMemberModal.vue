<script setup lang="ts">
import { ref, watch } from 'vue';
import { Modal, SearchInput } from '@/components/ui';
import { chatService } from '@/services/chat.service';
import debounce from 'lodash/debounce';
import type { DiscoverablePerson } from '@/types/models/chat';
import { useChatStore } from '@/stores/chat';

const props = defineProps<{
    isOpen: boolean;
    chatId: string;
    excludeUserIds?: string[]; // IDs to exclude from results (already members)
}>();

const emit = defineEmits<{
    'update:isOpen': [value: boolean];
    'added': [user: DiscoverablePerson];
}>();

const chatStore = useChatStore();
const query = ref('');
const results = ref<DiscoverablePerson[]>([]);
const isLoading = ref(false);
const isAdding = ref<string | null>(null); // Public ID of user being added

const handleClose = () => {
    emit('update:isOpen', false);
    query.value = '';
    results.value = [];
};

const performSearch = debounce(async (q: string) => {
    if (!q || q.length < 2) {
        results.value = [];
        return;
    }

    isLoading.value = true;
    try {
        const found = await chatService.searchPeople(q);
        // exclude existing members if passed
        if (props.excludeUserIds) {
            results.value = found.filter(u => !props.excludeUserIds?.includes(u.public_id));
        } else {
            results.value = found;
        }
    } finally {
        isLoading.value = false;
    }
}, 300);

watch(query, (val) => {
    performSearch(val);
});

async function handleAdd(user: DiscoverablePerson) {
    if (isAdding.value) return;
    isAdding.value = user.public_id;
    try {
        await chatStore.addMember(props.chatId, user.public_id);
        emit('added', user);
        emit('update:isOpen', false);
    } catch (error) {
        console.error('Failed to add member:', error);
        // Optionally show toast/error
    } finally {
        isAdding.value = null;
    }
}
</script>

<template>
    <Modal :open="isOpen" @close="handleClose" title="Add Contact to Group" size="md">
        <div class="space-y-4">
            <SearchInput
                v-model="query"
                placeholder="Search people..."
                class="w-full"
                autofocus
            />

            <div v-if="isLoading" class="flex justify-center py-4">
                <div class="w-6 h-6 border-2 border-[var(--interactive-primary)] border-t-transparent rounded-full animate-spin" />
            </div>

            <div v-else-if="results.length > 0" class="space-y-2 max-h-[60vh] overflow-y-auto pr-1">
                <div
                    v-for="user in results"
                    :key="user.public_id"
                    class="w-full flex items-center justify-between p-3 rounded-xl hover:bg-[var(--surface-tertiary)] transition-colors border border-transparent hover:border-[var(--border-default)] group"
                >
                    <div class="flex items-center gap-3">
                         <div 
                            class="w-8 h-8 rounded-full bg-cover bg-center shrink-0"
                            :class="user.avatar ? '' : 'bg-[var(--interactive-primary)]'"
                            :style="user.avatar ? { backgroundImage: `url(${user.avatar})` } : {}"
                        >
                            <span v-if="!user.avatar" class="flex items-center justify-center h-full text-white text-xs font-bold">
                                {{ user.name.charAt(0).toUpperCase() }}
                            </span>
                        </div>
                        <div class="text-left">
                            <div class="font-semibold text-sm text-[var(--text-primary)]">{{ user.name }}</div>
                            <div class="text-xs text-[var(--text-tertiary)]">{{ user.email }}</div>
                        </div>
                    </div>
                    
                    <button 
                        class="px-3 py-1.5 text-xs font-medium bg-[var(--surface-secondary)] hover:bg-[var(--interactive-primary)] hover:text-white rounded-lg transition-colors"
                        :disabled="isAdding === user.public_id"
                        @click="handleAdd(user)"
                    >
                        {{ isAdding === user.public_id ? 'Adding...' : 'Add' }}
                    </button>
                </div>
            </div>

            <div v-else-if="query.length >= 2" class="text-center py-8 text-[var(--text-secondary)]">
                No people found.
            </div>
            
            <div v-else class="text-center py-8 text-[var(--text-tertiary)] text-sm">
                Type name or email to search
            </div>
        </div>
    </Modal>
</template>
