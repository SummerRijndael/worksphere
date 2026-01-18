<script setup lang="ts">
import { ref, onErrorCaptured, type ComponentPublicInstance } from 'vue';
import { AlertCircle, RefreshCw } from 'lucide-vue-next';
import { Button } from '@/components/ui';

const props = defineProps<{
  name?: string;
  fallback?: string;
}>();

const hasError = ref(false);
const error = ref<Error | null>(null);
const errorInfo = ref<string | null>(null);

onErrorCaptured((err: unknown, _instance: ComponentPublicInstance | null, info: string) => {
  console.error(`[ErrorBoundary:${props.name || 'Unknown'}]`, err, info);
  
  hasError.value = true;
  error.value = err instanceof Error ? err : new Error(String(err));
  errorInfo.value = info;

  // Prevent error from propagating further if handled here
  return false;
});

const retry = () => {
  hasError.value = false;
  error.value = null;
  errorInfo.value = null;
};
</script>

<template>
  <div v-if="hasError" class="rounded-lg border border-red-200 bg-red-50 p-6 text-center shadow-sm dark:border-red-900/50 dark:bg-red-950/20">
    <div class="mb-4 flex justify-center">
      <div class="rounded-full bg-red-100 p-3 dark:bg-red-900/30">
        <AlertCircle class="h-6 w-6 text-red-600 dark:text-red-400" />
      </div>
    </div>
    
    <h3 class="mb-2 text-lg font-semibold text-red-900 dark:text-red-300">
      Something went wrong
    </h3>
    
    <p class="mb-6 text-sm text-red-700 dark:text-red-400">
      {{ props.fallback || error?.message || 'An unexpected error occurred in this component.' }}
    </p>

    <div class="flex justify-center">
      <Button 
        variant="outline" 
        size="sm" 
        @click="retry"
        class="border-red-200 bg-white text-red-700 hover:bg-red-50 dark:border-red-800 dark:bg-red-950/50 dark:text-red-300 dark:hover:bg-red-900/50"
      >
        <RefreshCw class="mr-2 h-4 w-4" />
        Try Again
      </Button>
    </div>
  </div>
  
  <slot v-else></slot>
</template>
