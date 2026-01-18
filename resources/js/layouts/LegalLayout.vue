<script setup>
import { RouterView, RouterLink } from 'vue-router';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import { ArrowLeft } from 'lucide-vue-next';
import { appConfig } from '@/config/app';
</script>

<template>
    <div class="min-h-screen bg-[var(--surface-primary)]">
        <!-- Header -->
        <header class="sticky top-0 z-50 border-b border-[var(--border-default)] bg-[var(--surface-elevated)]/95 backdrop-blur-sm">
            <div class="max-w-4xl mx-auto px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <RouterLink
                        to="/auth/login"
                        class="inline-flex items-center gap-1.5 text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                    >
                        <ArrowLeft class="h-4 w-4" />
                        Back
                    </RouterLink>
                    <div class="h-4 w-px bg-[var(--border-default)]" />
                    <RouterLink to="/" class="flex items-center gap-2">
                        <div class="h-8 w-8 rounded-lg bg-[var(--interactive-primary)] flex items-center justify-center">
                            <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <span class="text-lg font-semibold text-[var(--text-primary)]">{{ appConfig.name }}</span>
                    </RouterLink>
                </div>
                <ThemeToggle />
            </div>
        </header>

        <!-- Content -->
        <main class="max-w-4xl mx-auto px-6 py-12">
            <RouterView v-slot="{ Component }">
                <Transition name="fade" mode="out-in">
                    <component :is="Component" />
                </Transition>
            </RouterView>
        </main>

        <!-- Footer -->
        <footer class="border-t border-[var(--border-default)] bg-[var(--surface-elevated)]">
            <div class="max-w-4xl mx-auto px-6 py-8">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                    <p class="text-sm text-[var(--text-muted)]">
                        &copy; {{ new Date().getFullYear() }} {{ appConfig.name }}. All rights reserved.
                    </p>
                    <div class="flex items-center gap-6">
                        <RouterLink
                            to="/privacy"
                            class="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                        >
                            Privacy Policy
                        </RouterLink>
                        <RouterLink
                            to="/terms"
                            class="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                        >
                            Terms of Service
                        </RouterLink>
                    </div>
                </div>
            </div>
        </footer>
    </div>
</template>
