<script setup>
import { computed, watch, onUnmounted } from 'vue';
import { DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogClose, DialogTitle, DialogDescription } from 'reka-ui';
import { X } from 'lucide-vue-next';
import { cn } from '@/lib/utils';
import Button from './Button.vue';

const props = defineProps({
    open: Boolean,
    title: String,
    description: String,
    side: {
        type: String,
        default: 'right',
        validator: (v) => ['left', 'right'].includes(v),
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg', 'xl'].includes(v),
    },
    showClose: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['update:open', 'close']);

const contentClasses = computed(() =>
    cn(
        'fixed top-0 bottom-0 z-[1050]',
        'bg-[var(--surface-elevated)] shadow-2xl',
        'flex flex-col',
        'focus:outline-none',
        'transition-transform duration-300 ease-out',

        // Side
        {
            'left-0': props.side === 'left',
            'right-0': props.side === 'right',
        },

        // Size variants
        {
            'w-80': props.size === 'sm',
            'w-96': props.size === 'md',
            'w-[480px]': props.size === 'lg',
            'w-[640px]': props.size === 'xl',
        }
    )
);

const slideClasses = computed(() => ({
    'data-[state=open]:translate-x-0 data-[state=closed]:-translate-x-full': props.side === 'left',
    'data-[state=open]:translate-x-0 data-[state=closed]:translate-x-full': props.side === 'right',
}));

function handleOpenChange(open) {
    emit('update:open', open);
    if (!open) emit('close');
}

// Lock body scroll when open
watch(() => props.open, (isOpen) => {
    if (isOpen) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
});

onUnmounted(() => {
    document.body.style.overflow = '';
});
</script>

<template>
    <DialogRoot :open="open" @update:open="handleOpenChange">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-[1040] bg-black/50 backdrop-blur-sm transition-opacity duration-300 data-[state=open]:opacity-100 data-[state=closed]:opacity-0"
            />
            <DialogContent :class="[contentClasses, slideClasses]">
                <!-- Header -->
                <div class="flex items-start justify-between p-6 border-b border-[var(--border-default)]">
                    <div class="space-y-1">
                        <DialogTitle v-if="title" class="text-lg font-semibold text-[var(--text-primary)]">
                            {{ title }}
                        </DialogTitle>
                        <DialogDescription v-if="description" class="text-sm text-[var(--text-secondary)]">
                            {{ description }}
                        </DialogDescription>
                    </div>
                    <DialogClose v-if="showClose" as-child>
                        <Button variant="ghost" size="icon" class="h-8 w-8 -mr-2">
                            <X class="h-4 w-4" />
                        </Button>
                    </DialogClose>
                </div>

                <!-- Content -->
                <div class="flex-1 overflow-auto p-6">
                    <slot />
                </div>

                <!-- Footer -->
                <div v-if="$slots.footer" class="flex items-center justify-end gap-3 p-6 border-t border-[var(--border-default)]">
                    <slot name="footer" />
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
