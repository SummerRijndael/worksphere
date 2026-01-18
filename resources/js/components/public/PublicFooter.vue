<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { appConfig } from '@/config/app';
</script>

<template>
    <footer class="border-t border-[var(--border-default)] bg-[var(--surface-elevated)]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-8 mb-8">
                <!-- Brand -->
                <div class="lg:col-span-2">
                    <RouterLink to="/" class="flex items-center gap-2 mb-4">
                        <div class="h-8 w-8 rounded-lg bg-[var(--interactive-primary)] flex items-center justify-center">
                            <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <span class="text-lg font-semibold text-[var(--text-primary)]">{{ appConfig.name }}</span>
                    </RouterLink>
                    <p class="text-sm text-[var(--text-secondary)] max-w-xs">
                        Modern project management for teams that want to move fast and stay organized.
                    </p>
                </div>

                <!-- Product links -->
                <div>
                    <h4 class="font-semibold text-[var(--text-primary)] mb-4">Product</h4>
                    <ul class="space-y-2">
                        <li>
                            <a href="/#services" class="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                                Features
                            </a>
                        </li>
                        <li>
                            <a href="/#pricing" class="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                                Pricing
                            </a>
                        </li>
                        <li>
                            <a href="/#reviews" class="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                                Reviews
                            </a>
                        </li>
                        <li>
                            <RouterLink to="/public/faq" class="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                                FAQ
                            </RouterLink>
                        </li>
                        <li>
                            <RouterLink to="/support" class="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                                Support
                            </RouterLink>
                        </li>
                    </ul>
                </div>

                <!-- Legal links -->
                <div>
                    <h4 class="font-semibold text-[var(--text-primary)] mb-4">Legal</h4>
                    <ul class="space-y-2">
                        <li>
                            <RouterLink to="/privacy" class="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                                Privacy Policy
                            </RouterLink>
                        </li>
                        <li>
                            <RouterLink to="/terms" class="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                                Terms of Service
                            </RouterLink>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Bottom bar -->
            <div class="pt-8 border-t border-[var(--border-default)] flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-sm text-[var(--text-muted)]">
                    &copy; {{ new Date().getFullYear() }} {{ appConfig.name }}. All rights reserved.
                </p>
                <div class="flex items-center gap-4">
                    <a href="https://twitter.com" target="_blank" rel="noopener" class="text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors hover:scale-110 transform">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M8.29 20.251c7.547 0 11.675-6.253 11.675-11.675 0-.178 0-.355-.012-.53A8.348 8.348 0 0022 5.92a8.19 8.19 0 01-2.357.646 4.118 4.118 0 001.804-2.27 8.224 8.224 0 01-2.605.996 4.107 4.107 0 00-6.993 3.743 11.65 11.65 0 01-8.457-4.287 4.106 4.106 0 001.27 5.477A4.072 4.072 0 012.8 9.713v.052a4.105 4.105 0 003.292 4.022 4.095 4.095 0 01-1.853.07 4.108 4.108 0 003.834 2.85A8.233 8.233 0 012 18.407a11.616 11.616 0 006.29 1.84" />
                        </svg>
                    </a>
                    <a href="https://github.com" target="_blank" rel="noopener" class="text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors hover:scale-110 transform">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
                        </svg>
                    </a>
                    <a href="https://linkedin.com" target="_blank" rel="noopener" class="text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors hover:scale-110 transform">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </footer>
</template>
