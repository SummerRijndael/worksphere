<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Zap } from 'lucide-vue-next';

defineProps<{
    show?: boolean;
}>();
</script>

<template>
    <Transition name="fade-loader">
        <div
            v-if="show"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-[var(--surface-primary)]/90 backdrop-blur-md"
        >
            <div class="relative flex flex-col items-center justify-center">
                <!-- Fluid Gradient Ring -->
                <div class="relative w-32 h-32 flex items-center justify-center">
                    <div
                        class="absolute inset-0 rounded-full bg-gradient-to-tr from-[var(--color-primary-500)] via-[var(--color-landing-secondary)] to-[var(--color-primary-500)] animate-spin-slow blur-xl opacity-50"
                    ></div>
                    <div
                        class="absolute inset-2 rounded-full bg-[var(--surface-primary)] z-10"
                    ></div>
                    <div
                        class="absolute inset-0 rounded-full bg-gradient-to-tr from-[var(--color-primary-500)] via-transparent to-[var(--color-landing-secondary)] animate-spin-slow"
                        style="mask-image: radial-gradient(transparent 60%, black 64%); -webkit-mask-image: radial-gradient(transparent 60%, black 64%);"
                    ></div>
                    
                    <!-- Center Logo -->
                    <div class="relative z-20 flex items-center justify-center">
                        <div class="w-12 h-12 bg-gradient-to-br from-[var(--color-primary-500)] to-[var(--color-primary-700)] rounded-xl flex items-center justify-center shadow-lg shadow-[var(--color-primary-500)]/40 animate-pulse-slow">
                            <Zap class="w-6 h-6 text-white fill-current" />
                        </div>
                    </div>
                </div>

                <!-- Text -->
                <div class="mt-8 flex flex-col items-center gap-2">
                    <h3 class="text-xl font-bold text-[var(--text-primary)] tracking-tight">WorkSphere</h3>
                    <div class="flex items-center gap-1">
                        <span class="text-sm font-medium text-[var(--text-secondary)] uppercase tracking-widest">Loading</span>
                        <span class="flex gap-1 ml-1">
                            <span class="w-1 h-1 rounded-full bg-[var(--color-primary-500)] animate-bounce" style="animation-delay: 0ms"></span>
                            <span class="w-1 h-1 rounded-full bg-[var(--color-primary-500)] animate-bounce" style="animation-delay: 150ms"></span>
                            <span class="w-1 h-1 rounded-full bg-[var(--color-primary-500)] animate-bounce" style="animation-delay: 300ms"></span>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.fade-loader-enter-active,
.fade-loader-leave-active {
    transition: opacity 0.4s ease;
}

.fade-loader-enter-from,
.fade-loader-leave-to {
    opacity: 0;
}

.animate-spin-slow {
    animation: spin 3s linear infinite;
}

.animate-pulse-slow {
    animation: pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

@keyframes pulse {
    0%, 100% {
        opacity: 1;
        transform: scale(1);
    }
    50% {
        opacity: 0.8;
        transform: scale(0.95);
    }
}
</style>
