import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useSupportChatStore = defineStore('supportChat', () => {
    const isOpen = ref(false);
    const viewState = ref('intro'); // 'intro', 'form', 'history', 'chat'

    function openChat(initialState = null) {
        isOpen.value = true;
        if (initialState) {
            viewState.value = initialState;
        }
    }

    function closeChat() {
        isOpen.value = false;
    }

    function toggleChat() {
        isOpen.value = !isOpen.value;
    }

    return {
        isOpen,
        viewState,
        openChat,
        closeChat,
        toggleChat
    };
});
