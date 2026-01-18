<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { cn } from '@/lib/utils';
import { WifiOff, Wifi, RefreshCw } from 'lucide-vue-next';

const props = defineProps({
    /** Position of the banner */
    position: {
        type: String,
        default: 'top',
        validator: (v) => ['top', 'bottom'].includes(v),
    },
    /** Whether to show reconnection attempts */
    showRetryInfo: {
        type: Boolean,
        default: true,
    },
});

const isOnline = ref(navigator.onLine);
const wasOffline = ref(false);
const showRestored = ref(false);
const reconnectAttempts = ref(0);

let onlineHandler = null;
let offlineHandler = null;
let restoredTimeout = null;

/**
 * Handle coming back online.
 */
function handleOnline() {
    isOnline.value = true;
    reconnectAttempts.value = 0;

    // If was previously offline, show "connection restored" message
    if (wasOffline.value) {
        showRestored.value = true;
        
        // Hide the restored message after 3 seconds
        restoredTimeout = setTimeout(() => {
            showRestored.value = false;
            wasOffline.value = false;
        }, 3000);
    }
}

/**
 * Handle going offline.
 */
function handleOffline() {
    isOnline.value = false;
    wasOffline.value = true;
    showRestored.value = false;
    
    // Clear any pending restored timeout
    if (restoredTimeout) {
        clearTimeout(restoredTimeout);
    }
}

onMounted(() => {
    onlineHandler = handleOnline;
    offlineHandler = handleOffline;
    
    window.addEventListener('online', onlineHandler);
    window.addEventListener('offline', offlineHandler);
    
    // Set initial state
    isOnline.value = navigator.onLine;
});

onUnmounted(() => {
    if (onlineHandler) {
        window.removeEventListener('online', onlineHandler);
    }
    if (offlineHandler) {
        window.removeEventListener('offline', offlineHandler);
    }
    if (restoredTimeout) {
        clearTimeout(restoredTimeout);
    }
});

const positionClasses = computed(() => ({
    top: 'top-0 left-0 right-0',
    bottom: 'bottom-0 left-0 right-0',
}[props.position]));

const shouldShow = computed(() => !isOnline.value || showRestored.value);
</script>

<template>
    <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="opacity-0 -translate-y-full"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 -translate-y-full"
    >
        <div
            v-if="shouldShow"
            :class="cn(
                'fixed z-[100] flex items-center justify-center gap-3 px-4 py-2.5',
                'text-sm font-medium',
                positionClasses,
                // Offline state - red/warning
                !isOnline && 'bg-red-600 text-white',
                // Restored state - green/success  
                showRestored && 'bg-green-600 text-white'
            )"
            role="alert"
            aria-live="polite"
        >
            <!-- Offline message -->
            <template v-if="!isOnline">
                <WifiOff class="h-4 w-4 shrink-0" />
                <span>You are currently offline</span>
                <span 
                    v-if="showRetryInfo" 
                    class="flex items-center gap-1.5 text-white/80"
                >
                    <RefreshCw class="h-3.5 w-3.5 animate-spin" />
                    <span class="text-xs">Waiting for connection...</span>
                </span>
            </template>

            <!-- Connection restored message -->
            <template v-else-if="showRestored">
                <Wifi class="h-4 w-4 shrink-0" />
                <span>Connection restored</span>
            </template>
        </div>
    </Transition>
</template>
