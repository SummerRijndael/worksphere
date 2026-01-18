<script setup>
import { RouterView } from 'vue-router';
import HeroCarousel from '@/components/auth/HeroCarousel.vue';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import { appConfig } from '@/config/app';
</script>

<template>
    <div class="min-h-screen flex">
        <!-- Auth Form Section - 30% -->
        <div class="w-full lg:w-[30%] min-w-[400px] flex flex-col bg-[var(--surface-elevated)]">
            <!-- Header -->
            <header class="flex items-center justify-between p-6">
                <RouterLink to="/" class="flex items-center gap-2 hover:opacity-80 transition-opacity">
                    <div class="h-8 w-8 rounded-lg bg-[var(--interactive-primary)] flex items-center justify-center">
                        <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <span class="text-lg font-semibold text-[var(--text-primary)]">{{ appConfig.name }}</span>
                </RouterLink>
                <ThemeToggle />
            </header>

            <!-- Auth Content -->
            <div class="flex-1 flex items-center justify-center p-6">
                <div class="w-full max-w-sm">
                    <RouterView v-slot="{ Component }">
                        <Transition name="fade" mode="out-in">
                            <component :is="Component" />
                        </Transition>
                    </RouterView>
                </div>
            </div>

            <!-- Footer -->
            <footer class="p-6 text-center">
                <p class="text-sm text-[var(--text-muted)]">
                    &copy; {{ new Date().getFullYear() }} {{ appConfig.name }}. All rights reserved.
                </p>
            </footer>
        </div>

        <!-- Hero Section - 70% -->
        <div class="hidden lg:block lg:w-[70%] relative bg-[var(--surface-secondary)]">
            <HeroCarousel />
        </div>
    </div>
</template>
