<script setup lang="ts">
import { watch } from 'vue';
import { Button, Card } from '@/components/ui';
import { Shield } from 'lucide-vue-next';
import useRecaptcha from '@/composables/useRecaptcha';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close', 'verified']);

const { renderV2, resetV2 } = useRecaptcha();
const containerId = 'recaptcha-v2-container';

watch(() => props.show, (newVal) => {
  if (newVal) {
    // Small delay to ensure DOM is ready
    setTimeout(() => {
      renderV2(containerId, (token) => {
        emit('verified', token);
      });
    }, 100);
  } else {
      resetV2();
  }
});

function handleClose() {
  emit('close');
}
</script>

<template>
  <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <Card padding="lg" class="w-full max-w-sm bg-white dark:bg-gray-800 shadow-xl relative animate-in fade-in zoom-in duration-200">
      <div class="text-center">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 text-blue-600 mb-4">
          <Shield class="h-6 w-6" />
        </div>
        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Security Check</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
          Please complete the security check to proceed.
        </p>
        
        <div class="flex justify-center mb-6">
          <div :id="containerId"></div>
        </div>

        <Button variant="ghost" @click="handleClose" full-width>
          Cancel
        </Button>
      </div>
    </Card>
  </div>
</template>
