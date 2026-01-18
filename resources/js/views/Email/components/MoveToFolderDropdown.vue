<template>
    <Dropdown :items="folderItems" :align="align">
        <template #trigger>
            <slot name="trigger">
                <button 
                    class="p-1.5 text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] rounded transition-colors"
                    title="Move to folder"
                >
                    <FolderInputIcon class="w-4 h-4" />
                </button>
            </slot>
        </template>
    </Dropdown>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { FolderInputIcon } from 'lucide-vue-next';
import Dropdown from '@/components/ui/Dropdown.vue';
import { useEmailStore } from '@/stores/emailStore';

const props = withDefaults(defineProps<{
    emailIds: string[];
    currentFolder?: string;
    align?: 'start' | 'end';
}>(), {
    align: 'end'
});

const emit = defineEmits<{
    moved: [folderId: string, count: number];
}>();

const store = useEmailStore();

const folderItems = computed(() => {
    return store.folders
        .filter(f => f.id !== props.currentFolder) // Don't show current folder
        .map(folder => ({
            label: folder.name,
            icon: folder.icon,
            disabled: props.emailIds.length === 0,
            action: () => {
                store.moveEmails(props.emailIds, folder.id).then(count => {
                     if (count > 0) {
                        emit('moved', folder.id, count);
                    }
                });
            }
        }));
});
</script>
