<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { animate, stagger } from 'animejs';

const props = defineProps<{
    show?: boolean;
}>();

const loaderRef = ref<HTMLElement | null>(null);

onMounted(() => {
    if (!loaderRef.value) return;

    // Animate the orbiting dots
    animate('.orbit-dot', {
        rotate: 360,
        duration: 2000,
        easing: 'linear',
        loop: true,
    });

    // Animate the center logo pulse
    animate('.center-logo', {
        scale: [1, 1.1, 1],
        duration: 1500,
        easing: 'easeInOutSine',
        loop: true,
    });

    // Animate the ring expansion
    animate('.pulse-ring', {
        scale: [1, 1.5],
        opacity: [0.6, 0],
        duration: 1500,
        easing: 'easeOutExpo',
        loop: true,
    });

    // Staggered dots animation
    animate('.loader-dot', {
        scale: [0.5, 1, 0.5],
        opacity: [0.3, 1, 0.3],
        duration: 1200,
        delay: stagger(150),
        easing: 'easeInOutSine',
        loop: true,
    });
});
</script>

<template>
    <Transition name="fade-loader">
        <div
            v-if="show"
            ref="loaderRef"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-[var(--surface-primary)]"
        >
            <!-- Background gradients -->
            <div class="absolute inset-0 overflow-hidden">
                <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-gradient-to-br from-[var(--color-primary-200)] to-[var(--color-primary-400)] rounded-full opacity-20 blur-3xl dark:from-[var(--color-primary-700)] dark:to-[var(--color-primary-900)] animate-pulse" />
                <div class="absolute bottom-1/4 right-1/4 w-80 h-80 bg-gradient-to-tr from-[var(--color-primary-100)] to-[var(--color-primary-300)] rounded-full opacity-15 blur-3xl dark:from-[var(--color-primary-800)] dark:to-[var(--color-primary-950)] animate-pulse" style="animation-delay: 0.5s;" />
            </div>

            <!-- Loader container -->
            <div class="relative flex flex-col items-center gap-8">
                <!-- Main loader circle -->
                <div class="relative w-32 h-32">
                    <!-- Pulse rings -->
                    <div class="pulse-ring absolute inset-0 rounded-full border-2 border-[var(--color-primary-400)]" />
                    <div class="pulse-ring absolute inset-0 rounded-full border-2 border-[var(--color-primary-500)]" style="animation-delay: 0.5s;" />

                    <!-- Orbiting ring -->
                    <div class="absolute inset-0 orbit-dot" style="transform-origin: center;">
                        <div class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 w-3 h-3 bg-[var(--color-primary-500)] rounded-full shadow-lg shadow-[var(--color-primary-500)]/50" />
                    </div>
                    <div class="absolute inset-0 orbit-dot" style="transform-origin: center; animation-delay: 0.33s;">
                        <div class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 w-2.5 h-2.5 bg-[var(--color-primary-400)] rounded-full shadow-lg shadow-[var(--color-primary-400)]/50" style="transform: rotate(120deg) translateY(-64px);" />
                    </div>
                    <div class="absolute inset-0 orbit-dot" style="transform-origin: center; animation-delay: 0.66s;">
                        <div class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 w-2 h-2 bg-[var(--color-primary-300)] rounded-full shadow-lg shadow-[var(--color-primary-300)]/50" style="transform: rotate(240deg) translateY(-64px);" />
                    </div>

                    <!-- Center logo -->
                    <div class="center-logo absolute inset-4 rounded-full bg-gradient-to-br from-[var(--color-primary-500)] to-[var(--color-primary-700)] flex items-center justify-center shadow-2xl shadow-[var(--color-primary-500)]/30">
                        <svg class="h-10 w-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>

                    <!-- Outer dashed ring -->
                    <div class="absolute -inset-4 rounded-full border-2 border-dashed border-[var(--color-primary-200)] dark:border-[var(--color-primary-800)] animate-spin" style="animation-duration: 20s;" />
                </div>

                <!-- Loading text with animated dots -->
                <div class="flex items-center gap-1">
                    <span class="text-lg font-medium text-[var(--text-secondary)]">Loading</span>
                    <div class="flex gap-1 ml-1">
                        <span class="loader-dot w-1.5 h-1.5 rounded-full bg-[var(--color-primary-500)]" />
                        <span class="loader-dot w-1.5 h-1.5 rounded-full bg-[var(--color-primary-500)]" />
                        <span class="loader-dot w-1.5 h-1.5 rounded-full bg-[var(--color-primary-500)]" />
                    </div>
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.fade-loader-enter-active {
    transition: opacity 0.3s ease;
}
.fade-loader-leave-active {
    transition: opacity 0.5s ease;
}
.fade-loader-enter-from,
.fade-loader-leave-to {
    opacity: 0;
}
</style>
