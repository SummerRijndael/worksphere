<template>
    <Modal :open="open" @update:open="$emit('update:open', $event)" title="End Breakout Session" size="md">
        <div class="space-y-4 pt-2">
            <div class="flex items-start gap-4">
                <div class="shrink-0 w-10 h-10 rounded-xl bg-red-500/10 flex items-center justify-center text-red-500">
                    <Icon name="alert-triangle" size="20" />
                </div>
                
                <div class="space-y-2">
                    <p class="text-sm text-(--text-secondary) leading-relaxed">
                        Are you sure you want to end the breakout session for everyone?
                    </p>
                    <p class="text-xs text-red-500 font-medium">
                        All participants will be immediately returned to the main room.
                    </p>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex items-center justify-center gap-3 w-full">
                <Button variant="ghost" class="flex-1" @click="$emit('update:open', false)">Keep Session</Button>
                <Button 
                    variant="danger" 
                    class="flex-1"
                    :disabled="isEnding" 
                    @click="handleConfirm"
                >
                    <Icon v-if="isEnding" name="loader" class="animate-spin mr-2" size="14" />
                    End Session
                </Button>
            </div>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { Modal, Button, Icon } from '@/components/ui';

defineProps<{
    open: boolean;
}>();

const emit = defineEmits(['update:open', 'confirm']);

const isEnding = ref(false);

async function handleConfirm() {
    isEnding.value = true;
    try {
        await emit('confirm');
        emit('update:open', false);
    } finally {
        isEnding.value = false;
    }
}
</script>
