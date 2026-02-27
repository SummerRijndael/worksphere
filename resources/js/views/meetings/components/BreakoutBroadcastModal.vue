<template>
    <Modal :open="open" @update:open="$emit('update:open', $event)" title="Broadcast Message" size="md">
        <div class="space-y-4">
            <p class="text-sm text-(--text-secondary)">
                This message will be sent to all participants in <span class="font-bold text-(--text-primary)">{{ roomName }}</span>.
            </p>
            
            <div class="space-y-2">
                <label class="text-xs font-bold uppercase tracking-wider text-(--text-muted)">Message</label>
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
                    :disabled="!message.trim() || isSending" 
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
import { ref, watch } from 'vue';
import { Modal, Button, Textarea, Icon } from '@/components/ui';

const props = defineProps<{
    open: boolean;
    roomName: string;
}>();

const emit = defineEmits(['update:open', 'send']);

const message = ref('');
const isSending = ref(false);

watch(() => props.open, (newVal) => {
    if (newVal) {
        message.value = '';
        isSending.value = false;
    }
});

async function handleSend() {
    if (!message.value.trim()) return;
    
    isSending.value = true;
    try {
        await emit('send', message.value);
        emit('update:open', false);
    } finally {
        isSending.value = false;
    }
}
</script>
