<script setup>
import { AlertTriangle } from 'lucide-vue-next';
</script>

<template>
    <div class="mb-4 flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800/50 dark:bg-amber-900/20">
        <AlertTriangle class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
        <div class="flex-1">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">
                Demo Page Only
            </p>
            <p class="text-xs text-amber-700 dark:text-amber-300">
                This page displays sample data for demonstration purposes. Features are not connected to the backend.
            </p>
        </div>
    </div>
</template>
