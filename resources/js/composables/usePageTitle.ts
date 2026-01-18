import { ref, watch, onMounted, onUnmounted } from 'vue';
import { useChatStore } from '@/stores/chat';
// appConfig import removed

export function usePageTitle() {
    const chatStore = useChatStore();
    // We need to track the "intended" title (without notification)
    const originalTitle = ref(document.title);
    const blinkInterval = ref<ReturnType<typeof setInterval> | null>(null);
    const isShowingNotification = ref(false);
    let titleObserver: MutationObserver | null = null;

    onMounted(() => {
        originalTitle.value = document.title;
        
        // Watch for title changes from Router or other sources
        const titleElement = document.querySelector('title');
        if (titleElement) {
            titleObserver = new MutationObserver(() => {
                // Only update originalTitle if we are NOT currently blinking/showing notification
                if (!blinkInterval.value) {
                    originalTitle.value = document.title;
                }
            });
            titleObserver.observe(titleElement, { childList: true });
        }
    });

    onUnmounted(() => {
        stopBlinking();
        if (titleObserver) {
            titleObserver.disconnect();
        }
    });

    function startBlinking() {
        if (blinkInterval.value) return;

        // Immediately show notification
        isShowingNotification.value = true;
        updateTitle();

        blinkInterval.value = setInterval(() => {
            isShowingNotification.value = !isShowingNotification.value;
            updateTitle();
        }, 1000);
    }

    function updateTitle() {
        const count = chatStore.totalUnreadCount;
        
        if (isShowingNotification.value) {
             document.title = `New messages (${count}) | ${originalTitle.value}`;
        } else {
             document.title = originalTitle.value;
        }
    }

    function stopBlinking() {
        if (blinkInterval.value) {
            clearInterval(blinkInterval.value);
            blinkInterval.value = null;
        }
        // Restore original
        document.title = originalTitle.value;
        isShowingNotification.value = false;
    }

    // Watch unread count
    watch(() => chatStore.totalUnreadCount, (newCount) => {
        if (newCount > 0) {
            startBlinking();
        } else {
            stopBlinking();
        }
    });
}
