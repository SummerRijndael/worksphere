<script setup>
import { useToast } from '@/composables/useToast.ts';
import Toast from './Toast.vue';

const { toasts, removeToast } = useToast();
</script>

<template>
    <Teleport to="body">
        <div class="fixed bottom-4 right-4 z-[1080] flex flex-col gap-2 w-full max-w-sm pointer-events-none">
            <TransitionGroup
                name="toast"
                tag="div"
                class="flex flex-col gap-2"
            >
                <Toast
                    v-for="toast in toasts"
                    :key="toast.id"
                    v-bind="toast"
                    @close="removeToast(toast.id)"
                />
            </TransitionGroup>
        </div>
    </Teleport>
</template>

<style scoped>
.toast-enter-active {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.toast-leave-active {
    transition: all 0.2s cubic-bezier(0.4, 0, 1, 1);
}

.toast-enter-from {
    opacity: 0;
    transform: translateX(100%);
}

.toast-leave-to {
    opacity: 0;
    transform: translateX(100%);
}

.toast-move {
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
</style>
