<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink } from 'vue-router';
import { Button } from '@/components/ui';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import { Menu, X, ArrowRight } from 'lucide-vue-next';
import { useAuthStore } from '@/stores/auth';
import { appConfig } from '@/config/app';

const mobileMenuOpen = ref(false);
const authStore = useAuthStore();
</script>

<template>
    <header class="sticky top-0 z-50 border-b border-[var(--border-default)] bg-[var(--surface-elevated)]/95 backdrop-blur-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                <a href="/" target="_blank" class="flex items-center gap-2">
                    <div class="h-9 w-9 rounded-xl bg-gradient-to-br from-[var(--color-primary-500)] to-[var(--color-primary-700)] flex items-center justify-center shadow-lg shadow-[var(--color-primary-500)]/25">
                        <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <span class="text-xl font-bold text-[var(--text-primary)]">{{ appConfig.name }}</span>
                </a>

                <!-- Desktop Navigation -->
                <nav class="hidden md:flex items-center gap-8">
                    <a href="/#services" class="text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                        Services
                    </a>
                    <a href="/#pricing" class="text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                        Pricing
                    </a>
                    <a href="/#reviews" class="text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                        Reviews
                    </a>
                    <RouterLink to="/support" class="text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                        Support
                    </RouterLink>
                    <RouterLink to="/public/faq" class="text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                        FAQ
                    </RouterLink>
                </nav>

                <!-- Actions -->
                <div class="flex items-center gap-3">
                    <ThemeToggle />
                    <div class="hidden sm:flex items-center gap-3">
                        <template v-if="authStore.user">
                            <RouterLink to="/dashboard">
                                <Button size="sm" class="shadow-lg shadow-[var(--color-primary-500)]/25">
                                    {{ authStore.user.name.split(' ')[0] }}
                                    <ArrowRight class="h-4 w-4" />
                                </Button>
                            </RouterLink>
                        </template>
                        <template v-else>
                            <RouterLink to="/auth/login">
                                <Button variant="ghost" size="sm">Log in</Button>
                            </RouterLink>
                            <RouterLink to="/auth/login">
                                <Button size="sm" class="shadow-lg shadow-[var(--color-primary-500)]/25">
                                    Get Started
                                    <ArrowRight class="h-4 w-4" />
                                </Button>
                            </RouterLink>
                        </template>
                    </div>

                    <!-- Mobile menu button -->
                    <button
                        class="md:hidden p-2 rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors"
                        @click="mobileMenuOpen = !mobileMenuOpen"
                    >
                        <Menu v-if="!mobileMenuOpen" class="h-5 w-5" />
                        <X v-else class="h-5 w-5" />
                    </button>
                </div>
            </div>

            <!-- Mobile Navigation -->
            <Transition name="slide-up">
                <div v-if="mobileMenuOpen" class="md:hidden py-4 border-t border-[var(--border-default)]">
                    <nav class="flex flex-col gap-2">
                        <a href="/#services" class="px-3 py-2 text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] rounded-lg transition-colors" @click="mobileMenuOpen = false">
                            Services
                        </a>
                        <a href="/#pricing" class="px-3 py-2 text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] rounded-lg transition-colors" @click="mobileMenuOpen = false">
                            Pricing
                        </a>
                        <a href="/#reviews" class="px-3 py-2 text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] rounded-lg transition-colors" @click="mobileMenuOpen = false">
                            Reviews
                        </a>
                        <RouterLink to="/support" class="px-3 py-2 text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] rounded-lg transition-colors" @click="mobileMenuOpen = false">
                            Support
                        </RouterLink>
                        <RouterLink to="/public/faq" class="px-3 py-2 text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] rounded-lg transition-colors" @click="mobileMenuOpen = false">
                            FAQ
                        </RouterLink>
                        <div class="flex flex-col gap-2 mt-2 pt-2 border-t border-[var(--border-default)]">
                            <template v-if="authStore.user">
                                <RouterLink to="/dashboard" @click="mobileMenuOpen = false">
                                    <Button full-width>
                                        {{ authStore.user.name.split(' ')[0] }}
                                        <ArrowRight class="h-4 w-4 ml-2" />
                                    </Button>
                                </RouterLink>
                            </template>
                            <template v-else>
                                <RouterLink to="/auth/login" @click="mobileMenuOpen = false">
                                    <Button variant="outline" full-width>Log in</Button>
                                </RouterLink>
                                <RouterLink to="/auth/login" @click="mobileMenuOpen = false">
                                    <Button full-width>Get Started</Button>
                                </RouterLink>
                            </template>
                        </div>
                    </nav>
                </div>
            </Transition>
        </div>
    </header>
</template>
