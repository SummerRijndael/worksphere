<script setup>
import { ref, nextTick, onMounted, computed, watch } from 'vue';
import { 
    MessageSquare, 
    X, 
    Send, 
    Paperclip, 
    Smile, 
    Minus, 
    ChevronLeft, 
    User, 
    Mail, 
    Clock, 
    CheckCircle2,
    PlusCircle,
    History
} from 'lucide-vue-next';
import { Avatar, Button } from '@/components/ui';
import { useAuthStore } from '@/stores/auth';
import { useSupportChatStore } from '@/stores/supportChat';
const props = defineProps({
    hideLauncher: {
        type: Boolean,
        default: false
    }
});

const authStore = useAuthStore();
const chatStore = useSupportChatStore();
const isAuthenticated = computed(() => authStore.isAuthenticated);
const currentUser = computed(() => authStore.user);

const isOpen = computed(() => chatStore.isOpen);
const viewState = computed({
    get: () => chatStore.viewState,
    set: (val) => { chatStore.viewState = val; }
});
const newMessage = ref('');
const messagesContainer = ref(null);

// Form state for guests
const leadForm = ref({
    name: '',
    email: ''
});

const toggleChat = () => {
    chatStore.toggleChat();
};

// Monitor isOpen for scrolling
watch(() => chatStore.isOpen, (newVal) => {
    if (newVal) {
        scrollToBottom();
    }
});

const goToForm = () => {
    if (isAuthenticated.value) {
        startNewChat();
    } else {
        viewState.value = 'form';
    }
};

const goToHistory = () => {
    viewState.value = 'history';
};

const startChatFromForm = () => {
    if (!leadForm.value.name || !leadForm.value.email) return;
    // In a real app, we'd save the lead here
    viewState.value = 'chat';
};

const startNewChat = () => {
    // Reset specific chat state if needed
    viewState.value = 'chat';
};

const selectConversation = (id) => {
    // Load specific conversation
    viewState.value = 'chat';
};

const goBack = () => {
    if (viewState.value === 'chat') {
        viewState.value = isAuthenticated.value ? 'history' : 'intro';
    } else {
        viewState.value = 'intro';
    }
};

// Mock data
const mockHistory = ref([
    { id: 1, lastMessage: 'Thanks for the help!', time: 'Yesterday', status: 'resolved' },
    { id: 2, lastMessage: 'Waiting for a response...', time: '2 days ago', status: 'active' },
]);

const messages = ref([
    { id: 1, type: 'agent', content: 'Hi there! 👋 How can we help you today?', time: '10:00 AM', agentName: 'Support Team' },
    { id: 2, type: 'visitor', content: 'I have a question about my recent invoice.', time: '10:05 AM' },
    { id: 3, type: 'agent', content: 'Sure thing! Can you provide me with the invoice number so I can take a look?', time: '10:06 AM', agentName: 'Sarah' }
]);

const scrollToBottom = async () => {
    await nextTick();
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
};

const sendMessage = () => {
    if (!newMessage.value.trim()) return;
    
    messages.value.push({
        id: Date.now(),
        type: 'visitor',
        content: newMessage.value,
        time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    });
    
    newMessage.value = '';
    scrollToBottom();
    
    setTimeout(() => {
        messages.value.push({
            id: Date.now() + 1,
            type: 'agent',
            content: "Thanks! Let me check on that right away for you.",
            time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
            agentName: 'Sarah'
        });
        scrollToBottom();
    }, 1500);
};

onMounted(() => {
    if (isAuthenticated.value) {
        // leadForm is not needed for auth users
    }
});
</script>

<template>
    <div class="fixed bottom-6 right-6 z-[10001]">
        
        <!-- Chat Window -->
        <Transition
            enter-active-class="transition duration-300 ease-out origin-bottom-right"
            enter-from-class="transform scale-90 opacity-0"
            enter-to-class="transform scale-100 opacity-100"
            leave-active-class="transition duration-200 ease-in origin-bottom-right"
            leave-from-class="transform scale-100 opacity-100"
            leave-to-class="transform scale-90 opacity-0 pointer-events-none"
        >
            <div 
                v-if="isOpen" 
                class="absolute bottom-0 right-0 w-[360px] h-[580px] max-h-[calc(100vh-120px)] bg-[var(--surface-primary)] rounded-2xl shadow-2xl flex flex-col overflow-hidden border border-[var(--border-default)] flex-shrink-0"
            >
                <!-- Midnight Pro Minimalist Header -->
                <div class="bg-[#0f172a] text-white p-5 flex items-center justify-between shrink-0 relative border-b border-white/5 shadow-lg">
                    <div class="flex items-center gap-3">
                        <button v-if="viewState !== 'intro'" @click="goBack" class="p-1 hover:bg-white/10 rounded-full transition-all duration-200">
                            <ChevronLeft class="w-5 h-5 text-white/70" />
                        </button>
                        <div class="flex flex-col">
                            <h2 class="font-semibold text-base tracking-tight flex items-center gap-2.5 !text-white">
                                Support
                                <span class="flex items-center gap-1.5 px-2 py-0.5 bg-emerald-500/10 rounded-full border border-emerald-500/20">
                                    <span class="w-1.5 h-1.5 bg-emerald-400 rounded-full animate-pulse"></span>
                                    <span class="text-[9px] uppercase font-bold tracking-widest text-emerald-400">Live</span>
                                </span>
                            </h2>
                        </div>
                    </div>
                    
                    <button @click="toggleChat" class="text-white/40 hover:text-white hover:bg-white/5 rounded-full p-2 transition-all">
                        <Minus class="w-4 h-4" />
                    </button>
                </div>

                <!-- Content Area -->
                <div class="flex-1 overflow-hidden flex flex-col bg-[var(--surface-primary)] relative">
                    
                    <!-- Intro View -->
                    <div v-if="viewState === 'intro'" class="flex-1 flex flex-col p-6 items-center justify-center text-center space-y-6 bg-[var(--surface-primary)]">
                        <div class="w-20 h-20 bg-blue-50 dark:bg-blue-900/20 rounded-full flex items-center justify-center mb-2">
                             <MessageSquare class="w-10 h-10 text-[var(--interactive-primary)]" />
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-[var(--text-primary)]">Welcome!</h2>
                            <p class="text-[var(--text-secondary)] text-sm mt-2 px-4">Our team is available to help you with any questions or issues.</p>
                        </div>
                        
                        <div class="w-full space-y-3 pt-4">
                            <Button @click="goToForm" class="w-full h-12 rounded-xl text-base font-semibold shadow-md">
                                Start new conversation
                            </Button>
                            <Button v-if="isAuthenticated" variant="outline" @click="goToHistory" class="w-full h-12 rounded-xl text-base font-semibold border-[var(--border-default)]">
                                <History class="w-4 h-4 mr-2" />
                                View previous chats
                            </Button>
                        </div>
                    </div>

                    <!-- Lead Form View (For Guests) -->
                    <div v-else-if="viewState === 'form'" class="flex-1 flex flex-col p-6 space-y-6">
                        <div class="space-y-2">
                            <h3 class="text-lg font-bold text-[var(--text-primary)]">Give us a few details</h3>
                            <p class="text-sm text-[var(--text-secondary)]">We'll use this to get back to you if we're offline.</p>
                        </div>
                        
                        <div class="space-y-4">
                            <div class="space-y-2">
                                <label class="text-xs font-bold text-[var(--text-muted)] uppercase ml-1">Your Name</label>
                                <div class="relative flex items-center bg-[var(--surface-secondary)] rounded-xl border border-[var(--border-default)] focus-within:border-[var(--interactive-primary)] transition-all px-4 py-3">
                                    <User class="w-4 h-4 text-[var(--text-muted)] mr-3" />
                                    <input v-model="leadForm.name" type="text" placeholder="John Doe" class="bg-transparent border-none text-sm w-full focus:outline-none" />
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="text-xs font-bold text-[var(--text-muted)] uppercase ml-1">Email Address</label>
                                <div class="relative flex items-center bg-[var(--surface-secondary)] rounded-xl border border-[var(--border-default)] focus-within:border-[var(--interactive-primary)] transition-all px-4 py-3">
                                    <Mail class="w-4 h-4 text-[var(--text-muted)] mr-3" />
                                    <input v-model="leadForm.email" type="email" placeholder="john@example.com" class="bg-transparent border-none text-sm w-full focus:outline-none" />
                                </div>
                            </div>
                        </div>
                        
                        <Button @click="startChatFromForm" :disabled="!leadForm.name || !leadForm.email" class="w-full h-12 rounded-xl text-base font-semibold shadow-md mt-auto">
                            Start Chatting
                        </Button>
                    </div>

                    <!-- History View (For Auth Users) -->
                    <div v-else-if="viewState === 'history'" class="flex-1 flex flex-col p-4 bg-[var(--surface-secondary)]/30">
                        <div class="flex justify-between items-center mb-4 px-2">
                            <h3 class="font-bold text-[var(--text-primary)]">Previous Chats</h3>
                            <Button variant="ghost" size="sm" @click="startNewChat" class="h-8 text-[var(--interactive-primary)] hover:text-[var(--interactive-hover)]">
                                <PlusCircle class="w-4 h-4 mr-1.5" />
                                New
                            </Button>
                        </div>
                        
                        <div class="space-y-2 overflow-y-auto pr-1">
                            <div 
                                v-for="item in mockHistory" 
                                :key="item.id"
                                @click="selectConversation(item.id)"
                                class="p-4 bg-[var(--surface-primary)] rounded-xl border border-[var(--border-default)] hover:border-[var(--interactive-primary)] cursor-pointer transition-all shadow-sm"
                            >
                                <div class="flex justify-between items-start mb-1">
                                    <span class="text-xs font-bold text-[var(--text-primary)]">Support #{{ item.id }}</span>
                                    <span class="text-[10px] text-[var(--text-muted)]">{{ item.time }}</span>
                                </div>
                                <p class="text-xs text-[var(--text-secondary)] line-clamp-1 truncate">{{ item.lastMessage }}</p>
                                <div class="flex items-center mt-2">
                                    <div class="w-1.5 h-1.5 rounded-full mr-2" :class="item.status === 'resolved' ? 'bg-emerald-500' : 'bg-amber-500'"></div>
                                    <span class="text-[10px] uppercase font-bold text-[var(--text-muted)]">{{ item.status }}</span>
                                </div>
                            </div>
                        </div>

                        <div v-if="mockHistory.length === 0" class="flex-1 flex flex-col items-center justify-center text-center opacity-50 space-y-3">
                             <History class="w-12 h-12 text-[var(--text-muted)]" />
                             <p class="text-sm font-medium">No previous conversations</p>
                        </div>
                    </div>

                    <!-- Active Chat View -->
                    <div v-else-if="viewState === 'chat'" class="flex-1 flex flex-col overflow-hidden">
                        <!-- Messages -->
                        <div 
                            ref="messagesContainer"
                            class="flex-1 overflow-y-auto p-4 space-y-4 bg-[var(--surface-primary)] relative"
                        >
                            <!-- Background Pattern -->
                            <div class="absolute inset-0 pointer-events-none opacity-[0.02]" 
                                 style="background-image: radial-gradient(circle at 50% 50%, var(--text-primary) 1px, transparent 1px); background-size: 20px 20px;">
                            </div>

                            <div v-for="message in messages" :key="message.id" class="flex flex-col relative z-10" :class="message.type === 'visitor' ? 'items-end' : 'items-start'">
                                <div v-if="message.type === 'agent'" class="flex items-end gap-2 max-w-[85%]">
                                    <Avatar :initials="message.agentName.charAt(0)" size="xs" class="mb-1 flex-shrink-0 bg-[var(--surface-tertiary)]" />
                                    <div class="flex flex-col">
                                        <span class="text-[10px] text-[var(--text-muted)] ml-1 mb-0.5">{{ message.agentName }}</span>
                                        <div class="bg-[var(--surface-secondary)] text-[var(--text-primary)] px-3.5 py-2.5 rounded-2xl rounded-bl-sm text-sm border border-[var(--border-default)] shadow-sm">
                                            {{ message.content }}
                                        </div>
                                        <span class="text-[10px] text-[var(--text-muted)] mt-1 ml-1">{{ message.time }}</span>
                                    </div>
                                </div>

                                <div v-else class="flex flex-col items-end max-w-[85%]">
                                    <div class="bg-[var(--interactive-primary)] text-white px-3.5 py-2.5 rounded-2xl rounded-br-sm text-sm shadow-sm border border-black/5 dark:border-white/5">
                                        {{ message.content }}
                                    </div>
                                    <span class="text-[10px] text-[var(--text-muted)] mt-1 mr-1">{{ message.time }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Input Area -->
                        <div class="p-3 bg-[var(--surface-primary)] border-t border-[var(--border-default)] shrink-0">
                            <div class="relative flex items-center bg-[var(--surface-secondary)] rounded-full border border-[var(--border-default)] transition-all pr-1 pl-4 py-1">
                                <input 
                                    v-model="newMessage"
                                    type="text" 
                                    placeholder="Write a message..." 
                                    class="flex-1 bg-transparent border-none text-sm focus:outline-none focus:ring-0 focus:ring-offset-0 focus-visible:ring-0 focus-visible:outline-none text-[var(--text-primary)] placeholder-[var(--text-muted)] h-9"
                                    @keydown.enter="sendMessage"
                                />
                                <div class="flex items-center gap-1">
                                    <button class="p-1.5 text-[var(--text-muted)] hover:text-[var(--text-secondary)] transition-colors rounded-full hover:bg-[var(--surface-tertiary)]" title="Attach file">
                                        <Paperclip class="w-4 h-4" />
                                    </button>
                                    <button class="p-1.5 text-[var(--text-muted)] hover:text-[var(--text-secondary)] transition-colors rounded-full hover:bg-[var(--surface-tertiary)]" title="Insert emoji">
                                        <Smile class="w-4 h-4" />
                                    </button>
                                    <button 
                                        @click="sendMessage"
                                        class="p-2 bg-[var(--interactive-primary)] text-white rounded-full hover:bg-[var(--interactive-hover)] transition-colors ml-1 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                                        :disabled="!newMessage.trim()"
                                    >
                                        <Send class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                            <div class="text-center mt-2">
                                <span class="text-[10px] flex items-center justify-center gap-1 text-[var(--text-muted)]">
                                    Powered by <span class="font-bold">WorkSphere Chat</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- FAB Button -->
        <button 
            v-if="!hideLauncher"
            @click="toggleChat"
            class="flex items-center justify-center w-14 h-14 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-[var(--interactive-primary)] rounded-full shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 relative group border border-slate-200/50 dark:border-slate-700/50"
            :class="isOpen ? 'scale-0 opacity-0' : 'scale-100 opacity-100'"
        >
            <MessageSquare class="w-6 h-6 transition-transform duration-300 group-hover:scale-110" />
            <!-- Notification Badge -->
            <span class="absolute top-0 right-0 w-3.5 h-3.5 bg-red-500 border-2 border-white dark:border-slate-800 rounded-full shadow-sm"></span>
            
            <!-- Tooltip -->
            <div class="absolute right-full mr-4 bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-sm font-medium px-4 py-2 rounded-xl opacity-0 pointer-events-none group-hover:opacity-100 transition-all duration-300 translate-x-4 group-hover:translate-x-0 whitespace-nowrap shadow-xl border border-white/10 dark:border-slate-200">
                Need help? Chat with us
                <div class="absolute right-0 top-1/2 -translate-y-1/2 translate-x-1/2 rotate-45 w-2 h-2 bg-slate-900 dark:bg-white"></div>
            </div>
        </button>
    </div>
</template>

<style scoped>
input, textarea {
    outline: none !important;
    box-shadow: none !important;
    border: none !important;
}
input:focus, textarea:focus {
    outline: none !important;
    box-shadow: none !important;
    border: none !important;
}

/* Custom scrollbar for message list */
::-webkit-scrollbar {
    width: 4px;
}
::-webkit-scrollbar-track {
    background: transparent;
}
::-webkit-scrollbar-thumb {
    background: var(--border-default);
    border-radius: 10px;
}
::-webkit-scrollbar-thumb:hover {
    background: var(--text-muted);
}
</style>
