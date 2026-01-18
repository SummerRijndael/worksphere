<template>
    <Modal :open="isOpen" @close="handleClose" title="Create Label" size="sm">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">
                    Label Name
                </label>
                <input
                    v-model="labelName"
                    type="text"
                    placeholder="Enter label name..."
                    class="w-full px-3 py-2 text-sm bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/50 focus:border-[var(--interactive-primary)]"
                    @keydown.enter="handleCreate"
                    autofocus
                />
            </div>

            <div>
                <label class="block text-sm font-medium text-[var(--text-primary)] mb-2">
                    Color
                </label>
                <div class="grid grid-cols-8 gap-2">
                    <button
                        v-for="color in presetColors"
                        :key="color"
                        @click="selectedColor = color"
                        class="w-6 h-6 rounded-full transition-transform hover:scale-110"
                        :class="[
                            color,
                            selectedColor === color ? 'ring-2 ring-offset-2 ring-offset-[var(--surface-primary)] ring-[var(--interactive-primary)] scale-110' : ''
                        ]"
                        type="button"
                    />
                </div>
            </div>

            <!-- Preview -->
            <div class="pt-2 border-t border-[var(--border-subtle)]">
                <label class="block text-xs text-[var(--text-muted)] mb-2">Preview</label>
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full" :class="selectedColor"></span>
                    <span class="text-sm text-[var(--text-primary)]">
                        {{ labelName || 'Label Name' }}
                    </span>
                </div>
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
                    :disabled="!labelName.trim()"
                    class="px-4 py-2 text-sm font-medium bg-[var(--interactive-primary)] text-white rounded-lg hover:bg-[var(--interactive-primary-hover)] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                    Create Label
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
    created: [labelId: string];
}>();

const store = useEmailStore();
const presetColors = store.presetColors;
const labelName = ref('');
const selectedColor = ref(store.presetColors[10]); // Default to blue

watch(() => props.isOpen, (open) => {
    if (open) {
        labelName.value = '';
        selectedColor.value = store.presetColors[10];
    }
});

function handleClose() {
    labelName.value = '';
    emit('close');
}

async function handleCreate() {
    if (!labelName.value.trim()) return;
    
    const label = await store.addLabel(labelName.value, selectedColor.value);
    if (label) {
        emit('created', label.id);
        handleClose();
    }
}
</script>
