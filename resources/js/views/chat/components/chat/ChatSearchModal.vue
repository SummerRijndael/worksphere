<script setup lang="ts">
import { ref, watch } from 'vue';
import { Modal, SearchInput, Icon } from '@/components/ui';
import { chatService } from '@/services/chat.service';
import debounce from 'lodash/debounce';

const props = defineProps<{
    isOpen: boolean;
    chatId: string;
}>();

const emit = defineEmits<{
    'update:isOpen': [value: boolean];
    'jump': [messageId: string];
}>();

const query = ref('');
const results = ref<any[]>([]);
const isLoading = ref(false);

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
        results.value = await chatService.searchMessages(props.chatId, q);
    } finally {
        isLoading.value = false;
    }
}, 300);

watch(query, (val) => {
    performSearch(val);
});

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};
</script>

<template>
    <Modal :open="isOpen" @close="handleClose" title="Search Messages" size="md">
        <div class="space-y-4">
            <SearchInput
                v-model="query"
                placeholder="Search..."
                class="w-full"
                autofocus
            />

            <div v-if="isLoading" class="flex justify-center py-4">
                <div class="w-6 h-6 border-2 border-[var(--interactive-primary)] border-t-transparent rounded-full animate-spin" />
            </div>

            <div v-else-if="results.length > 0" class="space-y-2 max-h-[60vh] overflow-y-auto pr-1">
                <button
                    v-for="msg in results"
                    :key="msg.id"
                    class="w-full text-left p-3 rounded-xl hover:bg-[var(--surface-tertiary)] transition-colors border border-transparent hover:border-[var(--border-default)] group"
                    @click="emit('jump', msg.id)"
                >
                    <div class="flex justify-between items-start mb-1">
                        <div class="flex items-center gap-2">
                             <div 
                                v-if="msg.user_avatar"
                                class="w-5 h-5 rounded-full bg-cover bg-center shrink-0"
                                :style="{ backgroundImage: `url(${msg.user_avatar})` }"
                            />
                            <div v-else class="w-5 h-5 rounded-full bg-[var(--interactive-primary)] flex items-center justify-center text-[10px] text-white font-bold shrink-0">
                                {{ msg.user_name.charAt(0).toUpperCase() }}
                            </div>
                            <span class="font-semibold text-sm text-[var(--text-primary)]">{{ msg.user_name }}</span>
                        </div>
                        <span class="text-xs text-[var(--text-tertiary)]">{{ formatDate(msg.created_at) }}</span>
                    </div>
                    <div class="text-sm text-[var(--text-secondary)] line-clamp-2 pl-7 group-hover:text-[var(--text-primary)]">
                        {{ msg.content }}
                    </div>
                </button>
            </div>

            <div v-else-if="query.length >= 2" class="text-center py-8 text-[var(--text-secondary)]">
                No messages found.
            </div>
            
            <div v-else class="text-center py-8 text-[var(--text-tertiary)] text-sm">
                Type at least 2 characters to search
            </div>
        </div>
    </Modal>
</template>
