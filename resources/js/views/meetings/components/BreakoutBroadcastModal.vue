<template>
    <Modal :open="open" @update:open="$emit('update:open', $event)" title="Broadcast Message" size="md">
        <div class="space-y-4">
            <p class="text-sm text-(--text-secondary)">
                This message will be sent to all participants in <span class="font-bold text-(--text-primary)">{{ roomName }}</span>.
            </p>
            
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="text-xs font-bold uppercase tracking-wider text-(--text-muted)">Message</label>
                    <span
                        class="text-[11px] font-mono"
                        :class="messageLength >= MAX_BROADCAST_CHARS ? 'text-amber-500' : 'text-(--text-muted)'"
                    >
                        {{ messageLength }}/{{ MAX_BROADCAST_CHARS }}
                    </span>
                </div>
                <Textarea 
                    v-model="message" 
                    placeholder="Type your message here..." 
                    class="min-h-[120px]"
                    auto-focus
                />
            </div>
        </div>

        <template #footer>
            <div class="flex items-center gap-3">
                <Button variant="ghost" @click="$emit('update:open', false)">Cancel</Button>
                <Button 
                    variant="primary" 
                    :disabled="!message.trim() || isSending || messageLength > MAX_BROADCAST_CHARS" 
                    @click="handleSend"
                >
                    <Icon v-if="isSending" name="loader" class="animate-spin mr-2" size="14" />
                    <Icon v-else name="megaphone" class="mr-2" size="14" />
                    Send Broadcast
                </Button>
            </div>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Modal, Button, Textarea, Icon } from '@/components/ui';

const props = defineProps<{
    open: boolean;
    roomName: string;
}>();

const emit = defineEmits(['update:open', 'send']);

const MAX_BROADCAST_CHARS = 200;
const message = ref('');
const isSending = ref(false);
const messageLength = computed(() => message.value.length);

watch(() => props.open, (newVal) => {
    if (newVal) {
        message.value = '';
        isSending.value = false;
    }
});

watch(message, (nextValue) => {
    if (nextValue.length > MAX_BROADCAST_CHARS) {
        message.value = nextValue.slice(0, MAX_BROADCAST_CHARS);
    }
});

async function handleSend() {
    if (!message.value.trim()) return;
    
    isSending.value = true;
    try {
        await emit('send', message.value.trim());
        emit('update:open', false);
    } finally {
        isSending.value = false;
    }
}
</script>
