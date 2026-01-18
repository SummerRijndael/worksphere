<template>
    <Modal :open="isOpen" @close="handleClose" title="Create Folder" size="sm">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">
                    Folder Name
                </label>
                <input
                    v-model="folderName"
                    type="text"
                    placeholder="Enter folder name..."
                    class="w-full px-3 py-2 text-sm bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/50 focus:border-[var(--interactive-primary)]"
                    @keydown.enter="handleCreate"
                    autofocus
                />
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <button
                    @click="handleClose"
                    class="px-4 py-2 text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] rounded-lg transition-colors"
                >
                    Cancel
                </button>
                <button
                    @click="handleCreate"
                    :disabled="!folderName.trim()"
                    class="px-4 py-2 text-sm font-medium bg-[var(--interactive-primary)] text-white rounded-lg hover:bg-[var(--interactive-primary-hover)] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                    Create Folder
                </button>
            </div>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/components/ui/Modal.vue';
import { useEmailStore } from '@/stores/emailStore';

const props = defineProps<{
    isOpen: boolean;
}>();

const emit = defineEmits<{
    close: [];
    created: [folderId: string];
}>();

const store = useEmailStore();
const folderName = ref('');

watch(() => props.isOpen, (open) => {
    if (open) {
        folderName.value = '';
    }
});

function handleClose() {
    folderName.value = '';
    emit('close');
}

async function handleCreate() {
    if (!folderName.value.trim()) return;
    
    const folder = await store.addFolder(folderName.value);
    if (folder) {
        emit('created', folder.id);
        handleClose();
    }
}
</script>
