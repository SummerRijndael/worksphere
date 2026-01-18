<script setup lang="ts">
import { computed, watch, onUnmounted, nextTick } from 'vue';
import { DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogClose, DialogTitle, DialogDescription } from 'reka-ui';
import { X } from 'lucide-vue-next';
import { cn } from '@/lib/utils';
import Button from './Button.vue';

const props = defineProps({
    open: Boolean,
    title: String,
    description: String,
    size: {
        type: String,
        default: 'md',
    validator: (v: any) => ['sm', 'md', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', 'full'].includes(v),
    },
    showClose: {
        type: Boolean,
        default: true,
    },
    preventClose: Boolean,
});

const emit = defineEmits(['update:open', 'close']);

const contentClasses = computed(() =>
    cn(
        'fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-[1050]',
        'bg-[var(--surface-elevated)] rounded-2xl shadow-2xl',
        'focus:outline-none',
        'max-h-[85vh] overflow-auto',

        // Animation
        'data-[state=open]:animate-in data-[state=closed]:animate-out',
        'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
        'data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
        'data-[state=closed]:slide-out-to-left-1/2 data-[state=closed]:slide-out-to-top-[48%]',
        'data-[state=open]:slide-in-from-left-1/2 data-[state=open]:slide-in-from-top-[48%]',
        'duration-200',

        // Size variants
        {
            'w-full max-w-sm': props.size === 'sm',
            'w-full max-w-md': props.size === 'md',
            'w-full max-w-lg': props.size === 'lg',
            'w-full max-w-xl': props.size === 'xl',
            'w-full max-w-2xl': props.size === '2xl',
            'w-full max-w-3xl': props.size === '3xl',
            'w-full max-w-4xl': props.size === '4xl',
            'w-full max-w-5xl': props.size === '5xl',
            'w-[calc(100%-2rem)] h-[calc(100%-2rem)] max-w-none': props.size === 'full',
        }
    )
);

function handleOpenChange(open: boolean) {
    if (!open && props.preventClose) return;
    emit('update:open', open);
    if (!open) {
        emit('close');
        // Clean up scroll lock after dialog closes
        forceCleanupScrollLock();
    }
}

// Force cleanup any stale scroll locks when modal closes
// This handles cases where reka-ui or other code leaves scroll locked
function forceCleanupScrollLock() {
    // Run cleanup at multiple timings to ensure we catch reka-ui's async cleanup
    cleanupStyles();
    nextTick(cleanupStyles);
    setTimeout(cleanupStyles, 0);
    setTimeout(cleanupStyles, 100);
    setTimeout(cleanupStyles, 300);
}

function cleanupStyles() {
    // Clean up body styles
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.paddingRight = '';
    document.body.style.overflow = '';
    document.body.style.pointerEvents = '';
    document.body.style.marginRight = '';
    document.body.style.width = '';

    // Clean up html element styles (reka-ui sometimes sets these)
    document.documentElement.style.overflow = '';
    document.documentElement.style.paddingRight = '';
    document.documentElement.style.pointerEvents = '';
    document.documentElement.style.marginRight = '';

    // Remove any data attributes reka-ui might use for scroll lock
    document.body.removeAttribute('data-scroll-locked');
    document.documentElement.removeAttribute('data-scroll-locked');

    // Remove scroll lock classes that libraries might add
    document.body.classList.remove('scroll-locked', 'overflow-hidden', 'modal-open');
    document.documentElement.classList.remove('scroll-locked', 'overflow-hidden', 'modal-open');
}

watch(() => props.open, (isOpen) => {
    if (!isOpen) {
        forceCleanupScrollLock();
    }
});

onUnmounted(() => {
    forceCleanupScrollLock();
});
defineSlots<{
    default(): any;
    footer(): any;
}>();
</script>

<template>
    <DialogRoot :open="open" @update:open="handleOpenChange">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-[1040] bg-black/50 backdrop-blur-sm data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0"
            />
            <DialogContent :class="contentClasses">
                <!-- Header -->
                <div v-if="title || showClose" class="flex items-start justify-between p-6 pb-0">
                    <div v-if="title" class="space-y-1.5">
                        <DialogTitle class="text-lg font-semibold text-[var(--text-primary)]">
                            {{ title }}
                        </DialogTitle>
                        <!-- Always render DialogDescription for accessibility (reka-ui requirement) -->
                        <DialogDescription 
                            :class="description ? 'text-sm text-[var(--text-secondary)]' : 'sr-only'"
                        >
                            {{ description || title }}
                        </DialogDescription>
                    </div>
                    <DialogClose v-if="showClose && !preventClose" as-child>
                        <Button variant="ghost" size="icon" class="h-8 w-8 -mr-2 -mt-2">
                            <X class="h-4 w-4" />
                        </Button>
                    </DialogClose>
                </div>

                <!-- Content -->
                <div class="p-6">
                    <slot />
                </div>

                <!-- Footer -->
                <div v-if="$slots.footer" class="flex items-center justify-end gap-3 p-6 pt-0">
                    <slot name="footer" />
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>

<style>
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes fadeOut {
    from { opacity: 1; }
    to { opacity: 0; }
}

@keyframes zoomIn {
    from { opacity: 0; transform: translate(-50%, -50%) scale(0.9); }
    to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
}

@keyframes zoomOut {
    from { opacity: 1; transform: translate(-50%, -50%) scale(1); }
    to { opacity: 0; transform: translate(-50%, -50%) scale(0.9); }
}

.animate-in {
    animation-duration: 300ms;
    animation-timing-function: cubic-bezier(0.34, 1.56, 0.64, 1); /* Spring-like pop */
    animation-fill-mode: both;
}

.animate-out {
    animation-duration: 200ms;
    animation-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    animation-fill-mode: both;
}

.fade-in-0 { animation-name: fadeIn; }
.fade-out-0 { animation-name: fadeOut; }
.zoom-in-95 { animation-name: zoomIn; }
.zoom-out-95 { animation-name: zoomOut; }
</style>
