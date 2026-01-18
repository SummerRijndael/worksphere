<script setup lang="ts">
import { defineProps, defineEmits } from 'vue';
import { X, Calendar, User } from 'lucide-vue-next';
import { format } from 'date-fns';

const props = defineProps<{
    show: boolean;
    article: any;
}>();

const emit = defineEmits(['close']);
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-[10000] flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity" @click="emit('close')"></div>
        
        <!-- Content -->
        <div class="relative w-full max-w-4xl max-h-[90vh] bg-[var(--surface-primary)] rounded-2xl shadow-2xl overflow-hidden flex flex-col animate-scale-fade-in">
            <!-- Header (Modal specific) -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-[var(--border-default)] bg-[var(--surface-elevated)] shrink-0 z-10">
                <h3 class="text-sm font-semibold text-[var(--text-secondary)] uppercase tracking-wider">
                    Preview Mode
                </h3>
                <button 
                    @click="emit('close')"
                    class="p-2 rounded-full hover:bg-[var(--surface-secondary)] text-[var(--text-secondary)] transition-colors"
                >
                    <X class="w-5 h-5" />
                </button>
            </div>

            <!-- Preview Body (Replicates Public View) -->
            <div class="overflow-y-auto p-8 sm:p-12">
                <div v-if="article">
                     <!-- Article Header -->
                    <div class="mb-8 border-b border-[var(--border-default)] pb-8">
                        <div class="flex items-center gap-2 mb-4">
                            <span v-if="article.category" class="px-3 py-1 rounded-full text-xs font-medium bg-[var(--color-primary-100)] text-[var(--color-primary-700)] dark:bg-[var(--color-primary-900)] dark:text-[var(--color-primary-300)]">
                                {{ article.category.name }}
                            </span>
                             <span v-else class="px-3 py-1 rounded-full text-xs font-medium bg-[var(--surface-tertiary)] text-[var(--text-secondary)]">
                                Uncategorized
                            </span>
                        </div>
                        <h1 class="text-3xl sm:text-4xl font-bold text-[var(--text-primary)] mb-6">
                            {{ article.title || 'Untitled Article' }}
                        </h1>
                        
                        <div v-if="article.tags && article.tags.length > 0" class="flex flex-wrap gap-2 mb-6">
                             <span v-for="tag in article.tags" :key="tag" class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border border-[var(--border-default)]">
                                #{{ tag }}
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-6 text-sm text-[var(--text-muted)]">
                            <div class="flex items-center gap-2">
                                <User class="h-4 w-4" />
                                <span>{{ article.author?.name || 'CoreSync Team' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <Calendar class="h-4 w-4" />
                                <span>Updated {{ article.updated_at ? format(new Date(article.updated_at), 'MMM d, yyyy') : 'Just now' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="prose prose-lg dark:prose-invert max-w-none">
                        <div v-if="article.content" v-html="article.content"></div>
                        <p v-else class="text-[var(--text-muted)] italic">No content written yet...</p>
                    </div>

                    <!-- Mock Footer -->
                     <div class="mt-12 pt-8 border-t border-[var(--border-default)] opacity-50 select-none">
                        <div class="bg-[var(--surface-secondary)] p-6 rounded-xl border border-[var(--border-default)] text-center">
                            <p class="font-medium text-[var(--text-primary)]">Feedback Section Placeholder</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Footer Actions -->
             <div class="px-6 py-4 border-t border-[var(--border-default)] bg-[var(--surface-elevated)] shrink-0 flex justify-end">
                <button 
                    @click="emit('close')"
                    class="btn btn-primary"
                >
                    Close Preview
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.animate-scale-fade-in {
    animation: scaleFadeIn 0.2s ease-out forwards;
}

@keyframes scaleFadeIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}
</style>
