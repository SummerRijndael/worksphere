<script setup>
import { ref, computed } from 'vue';
import { 
    Search, 
    Filter, 
    MoreVertical, 
    Phone, 
    Video, 
    Paperclip, 
    Send,
    Smile,
    Image as ImageIcon,
    FileText,
    Clock,
    CheckCircle2,
    XCircle,
    AlertCircle,
    UserCircle,
    MapPin,
    Globe,
    Monitor,
    MessageSquare,
    Zap,
    Book,
    Code,
    UserPlus,
    ChevronDown,
    User,
    Hash
} from 'lucide-vue-next';
import { 
    Avatar,
    Button,
    Input,
    Badge,
    Dropdown,
    DropdownItem,
    DropdownSeparator,
    Textarea
} from '@/components/ui';

// Mock Data: Conversations
const conversations = ref([
    {
        id: '1',
        visitorName: 'Alex Johnson',
        visitorInitial: 'A',
        status: 'active', // active, waiting, resolved
        lastMessage: 'How do I upgrade my current plan?',
        time: '2m ago',
        unread: 2,
        isOnline: true,
        selected: true
    },
    {
        id: '2',
        visitorName: 'Sarah Smith',
        visitorInitial: 'S',
        status: 'waiting',
        lastMessage: 'The payment page is returning an error...',
        time: '15m ago',
        unread: 0,
        isOnline: true,
        selected: false
    },
    {
        id: '3',
        visitorName: 'Guest_10492',
        visitorInitial: 'G',
        status: 'active',
        lastMessage: 'Thanks for the help!',
        time: '1h ago',
        unread: 0,
        isOnline: false,
        selected: false
    },
    {
        id: '4',
        visitorName: 'Michael Brown',
        visitorInitial: 'M',
        status: 'resolved',
        lastMessage: 'Got it working now.',
        time: 'Yesterday',
        unread: 0,
        isOnline: false,
        selected: false
    }
]);

// Mock Data: Active Chat Thread
const activeChat = ref({
    id: '1',
    visitorName: 'Alex Johnson',
    email: 'alex.j@example.com',
    location: 'New York, USA',
    browser: 'Chrome on macOS',
    ip: '192.168.1.1',
    currentTime: '10:45 AM',
    pageView: '/pricing',
    messages: [
        { id: 'm1', type: 'system', content: 'Alex Johnson started a chat from /pricing', time: '10:30 AM' },
        { id: 'm2', type: 'visitor', content: 'Hi there! I am currently on the Pro plan but we have hired 5 more team members.', time: '10:31 AM' },
        { id: 'm3', type: 'visitor', content: 'How do I upgrade my current plan to Enterprise?', time: '10:31 AM' },
        { id: 'm4', type: 'agent', content: 'Hello Alex! I can certainly help you with that. The Enterprise plan is designed for larger teams.', time: '10:33 AM', agentName: 'You' },
        { id: 'm5', type: 'agent', content: 'Let me pull up your account details real quick.', time: '10:34 AM', agentName: 'You' },
        { id: 'm6', type: 'visitor', content: 'Great, take your time.', time: '10:35 AM' },
        { id: 'm7', type: 'note', content: 'Internal Note: User has been a customer for 2 years. Eligible for loyalty discount on Enterprise tier.', time: '10:36 AM', agentName: 'You' }
    ]
});

const activeTab = ref('mine'); // 'mine', 'unassigned', 'all'
const newMessage = ref('');
const isNoteMode = ref(false);

const selectConversation = (id) => {
    conversations.value.forEach(c => c.selected = c.id === id);
    // In a real app, this would fetch the new chat's details
};

const sendMessage = () => {
    if (!newMessage.value.trim()) return;
    
    activeChat.value.messages.push({
        id: `m${Date.now()}`,
        type: isNoteMode.value ? 'note' : 'agent',
        content: newMessage.value,
        time: 'Just now',
        agentName: 'You'
    });
    
    newMessage.value = '';
};

// Helpers for UI
const getStatusClasses = (status) => {
    switch (status) {
        case 'active': return 'text-emerald-500';
        case 'waiting': return 'text-amber-500';
        case 'resolved': return 'text-[var(--text-muted)]';
        default: return 'text-[var(--text-secondary)]';
    }
};

const getStatusIcon = (status) => {
    switch (status) {
        case 'active': return CheckCircle2;
        case 'waiting': return Clock;
        case 'resolved': return CheckCircle2;
        default: return MessageSquare;
    }
};

</script>

<template>
    <div class="flex-1 w-full bg-[var(--surface-primary)] flex overflow-hidden border-t border-[var(--border-default)]">
        
        <!-- Left Sidebar: Conversations List -->
        <div class="w-80 flex-shrink-0 border-r border-[var(--border-default)] bg-[var(--surface-secondary)]/30 flex flex-col">
            <!-- Header & Filters -->
            <div class="p-4 border-b border-[var(--border-default)] space-y-3">
                <div class="flex items-center justify-between">
                    <Dropdown>
                        <template #trigger>
                            <button class="flex items-center gap-1.5 group outline-none">
                                <h2 class="font-bold text-[var(--text-primary)] text-sm tracking-tight">
                                    {{ 
                                        activeTab === 'mine' ? 'My Inbox' : 
                                        activeTab === 'unassigned' ? 'Unassigned' : 
                                        'All Activity' 
                                    }}
                                </h2>
                                <ChevronDown class="h-3.5 w-3.5 text-[var(--text-muted)] group-hover:text-[var(--text-primary)] transition-colors" />
                            </button>
                        </template>
                        <DropdownItem @click="activeTab = 'mine'" :class="{ 'bg-[var(--surface-tertiary)]': activeTab === 'mine' }">
                            <User class="mr-2 h-4 w-4" /> My Conversations
                        </DropdownItem>
                        <DropdownItem @click="activeTab = 'unassigned'" :class="{ 'bg-[var(--surface-tertiary)]': activeTab === 'unassigned' }">
                            <Zap class="mr-2 h-4 w-4" /> New Conversations
                        </DropdownItem>
                        <DropdownItem @click="activeTab = 'all'" :class="{ 'bg-[var(--surface-tertiary)]': activeTab === 'all' }">
                            <Hash class="mr-2 h-4 w-4" /> All Activity
                        </DropdownItem>
                    </Dropdown>

                    <Button variant="ghost" size="sm" class="h-8 w-8 p-0 hover:bg-white/5 rounded-full transition-colors">
                        <Filter class="h-4 w-4 text-[var(--text-secondary)]" />
                    </Button>
                </div>
                
                <div class="relative group">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--text-muted)] group-focus-within:text-[var(--interactive-primary)] transition-colors" />
                    <Input placeholder="Search..." class="pl-10 bg-[var(--surface-primary)]/50 border-[var(--border-default)]/50 focus:border-[var(--interactive-primary)] h-10 rounded-xl transition-all shadow-sm text-sm" />
                </div>
            </div>

            <!-- List -->
            <div class="flex-1 overflow-y-auto">
                <div 
                    v-for="chat in conversations" 
                    :key="chat.id"
                    @click="selectConversation(chat.id)"
                    class="p-4 border-b border-[var(--border-default)]/50 cursor-pointer transition-colors relative"
                    :class="chat.selected ? 'bg-[var(--surface-primary)] border-l-4 border-l-[var(--interactive-primary)]' : 'hover:bg-[var(--surface-primary)] border-l-4 border-l-transparent'"
                >
                    <div class="flex items-start gap-3">
                        <div class="relative">
                            <Avatar :initials="chat.visitorInitial" size="sm" class="font-medium" />
                            <div 
                                v-if="chat.isOnline" 
                                class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-emerald-500 border-2 border-[var(--surface-primary)] rounded-full"
                            ></div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-1">
                                <span class="font-medium text-[var(--text-primary)] truncate pr-2 text-sm">{{ chat.visitorName }}</span>
                                <span class="text-xs text-[var(--text-muted)] whitespace-nowrap">{{ chat.time }}</span>
                            </div>
                            <p class="text-xs text-[var(--text-secondary)] truncate">{{ chat.lastMessage }}</p>
                            
                            <div class="flex items-center justify-between mt-2">
                                <div class="flex items-center gap-1.5" :class="getStatusClasses(chat.status)">
                                    <component :is="getStatusIcon(chat.status)" class="w-3.5 h-3.5" />
                                    <span class="text-[10px] font-medium uppercase tracking-wider">{{ chat.status }}</span>
                                </div>
                                <Badge v-if="chat.unread" variant="primary" size="sm" class="h-5 min-w-[20px] px-1.5 justify-center">
                                    {{ chat.unread }}
                                </Badge>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Middle Column: Active Chat Thread -->
        <div class="flex-1 flex flex-col bg-[var(--surface-primary)] relative border-r border-[var(--border-default)]">
            <!-- Subtle Background Pattern -->
            <div class="absolute inset-0 pointer-events-none opacity-[0.03] dark:opacity-[0.05]" 
                 style="background-image: radial-gradient(circle at 50% 50%, var(--text-primary) 1px, transparent 1px); background-size: 24px 24px;">
            </div>

            <!-- Thread Header -->
            <div class="h-16 px-6 border-b border-[var(--border-default)] flex items-center justify-between bg-[var(--surface-primary)]/90 backdrop-blur-sm z-10 sticky top-0">
                <div class="flex items-center gap-4">
                    <Avatar :initials="activeChat.visitorName.charAt(0)" size="md" />
                    <div>
                        <h2 class="font-semibold text-[var(--text-primary)] flex items-center gap-2">
                            {{ activeChat.visitorName }}
                            <Badge variant="success" size="sm" class="capitalize">Active</Badge>
                        </h2>
                        <div class="text-xs text-[var(--text-secondary)] flex items-center gap-3 mt-0.5">
                            <span class="flex items-center gap-1"><Monitor class="w-3 h-3" /> Browsing: <span class="text-[var(--interactive-primary)] cursor-pointer hover:underline">{{ activeChat.pageView }}</span></span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" class="hidden sm:flex">
                        <CheckCircle2 class="w-4 h-4 mr-2" /> Mark Resolved
                    </Button>
                    <Dropdown>
                        <template #trigger>
                            <Button variant="ghost" size="sm" class="h-9 w-9 p-0">
                                <MoreVertical class="h-4 w-4 text-[var(--text-secondary)]" />
                            </Button>
                        </template>
                        <DropdownItem>Transfer Chat</DropdownItem>
                        <DropdownItem>Block User</DropdownItem>
                        <DropdownSeparator />
                        <DropdownItem class="text-red-500">Delete Conversation</DropdownItem>
                    </Dropdown>
                </div>
            </div>

            <!-- Messages Area -->
            <div class="flex-1 overflow-y-auto p-6 space-y-6 relative z-0">
                <div v-for="message in activeChat.messages" :key="message.id" class="flex flex-col">
                    
                    <!-- System Message -->
                    <div v-if="message.type === 'system'" class="flex justify-center my-2">
                        <div class="bg-[var(--surface-secondary)] px-3 py-1.5 rounded-full text-xs text-[var(--text-secondary)] border border-[var(--border-default)]/50 shadow-sm backdrop-blur-sm">
                            {{ message.content }} • {{ message.time }}
                        </div>
                    </div>

                    <!-- Internal Note -->
                    <div v-else-if="message.type === 'note'" class="flex flex-col items-center my-4">
                        <div class="bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-400 w-full max-w-lg p-4 rounded-xl shadow-sm">
                            <div class="flex items-center gap-2 mb-2">
                                <AlertCircle class="w-4 h-4" />
                                <span class="text-xs font-bold uppercase tracking-wider">Internal Note</span>
                                <span class="text-xs opacity-70 ml-auto">{{ message.time }} by {{ message.agentName }}</span>
                            </div>
                            <p class="text-sm">{{ message.content }}</p>
                        </div>
                    </div>

                    <!-- Visitor Message -->
                    <div v-else-if="message.type === 'visitor'" class="flex items-end gap-2 mb-2">
                        <Avatar :initials="activeChat.visitorName.charAt(0)" size="xs" class="mb-1" />
                        <div class="max-w-[75%] flex flex-col items-start">
                            <div class="bg-[var(--surface-elevated)] border border-black/5 dark:border-white/5 text-[var(--text-primary)] px-4 py-2.5 rounded-2xl rounded-bl-sm shadow-md backdrop-blur-sm text-sm">
                                {{ message.content }}
                            </div>
                            <span class="text-[10px] text-[var(--text-muted)] mt-1 ml-1">{{ message.time }}</span>
                        </div>
                    </div>

                    <!-- Agent Message -->
                    <div v-else-if="message.type === 'agent'" class="flex flex-col items-end mb-2">
                        <div class="max-w-[75%] flex flex-col items-end">
                            <div class="bg-[var(--interactive-primary)] text-white px-4 py-2.5 rounded-2xl rounded-br-sm shadow-md border border-black/5 dark:border-white/5 backdrop-blur-sm text-sm">
                                {{ message.content }}
                            </div>
                            <span class="text-[10px] text-[var(--text-muted)] mt-1 mr-1 text-right">{{ message.time }} • {{ message.agentName }}</span>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Composer Area -->
            <div class="p-4 bg-[var(--surface-primary)] border-t border-[var(--border-default)] z-10 relative">
                
                <!-- Composer Modes -->
                <div class="flex items-center gap-1 mb-2 px-2">
                    <button 
                        @click="isNoteMode = false"
                        class="text-xs font-semibold px-3 py-1 rounded-full transition-colors"
                        :class="!isNoteMode ? 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]' : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]'"
                    >
                        Reply
                    </button>
                    <button 
                        @click="isNoteMode = true"
                        class="text-xs font-semibold px-3 py-1 rounded-full transition-colors flex items-center gap-1"
                        :class="isNoteMode ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]'"
                    >
                        <AlertCircle class="w-3 h-3" /> Internal Note
                    </button>
                </div>

                <!-- Input Box -->
                <div 
                    class="rounded-xl border shadow-sm transition-all"
                    :class="[
                        isNoteMode ? 'bg-amber-500/5 border-amber-500/30' : 'bg-[var(--surface-secondary)] border-[var(--border-default)]',
                        'hover:border-black/10 dark:hover:border-white/15'
                    ]"
                >
                    <textarea 
                        v-model="newMessage"
                        :placeholder="isNoteMode ? 'Type an internal note (visitors cannot see this)...' : 'Type your message... (Press Shift+Enter for new line)'" 
                        class="w-full bg-transparent border-none p-3 text-sm focus:outline-none focus:ring-0 focus:ring-offset-0 focus-visible:ring-0 focus-visible:outline-none resize-none min-h-[80px] max-h-[200px] text-[var(--text-primary)]"
                        @keydown.enter.exact.prevent="sendMessage"
                    ></textarea>
                    
                    <div class="flex items-center justify-between p-2 border-t border-[var(--border-default)]/50">
                        <div class="flex items-center gap-1">
                            <Button variant="ghost" size="sm" class="h-8 w-8 p-0 hover:bg-black/5 dark:hover:bg-white/5" title="Attach file">
                                <Paperclip class="h-4 w-4 text-[var(--text-secondary)]" />
                            </Button>
                            <Button variant="ghost" size="sm" class="h-8 w-8 p-0 hover:bg-black/5 dark:hover:bg-white/5" title="Insert image">
                                <ImageIcon class="h-4 w-4 text-[var(--text-secondary)]" />
                            </Button>
                            <Button variant="ghost" size="sm" class="h-8 w-8 p-0 hover:bg-black/5 dark:hover:bg-white/5" title="Insert emoji">
                                <Smile class="h-4 w-4 text-[var(--text-secondary)]" />
                            </Button>
                            <div class="h-4 w-px bg-[var(--border-default)] mx-1"></div>
                            <Button variant="ghost" size="sm" class="h-8 px-2 text-xs font-medium text-[var(--text-secondary)] hover:bg-black/5 dark:hover:bg-white/5" title="Use a saved macro">
                                <Zap class="h-3.5 w-3.5 mr-1" /> Macros
                            </Button>
                        </div>
                        <Button 
                            :variant="isNoteMode ? 'outline' : 'primary'" 
                            size="sm" 
                            @click="sendMessage"
                            :class="[
                                isNoteMode ? 'bg-amber-100 dark:bg-amber-900 border-amber-500/30 text-amber-700 dark:text-amber-300 hover:bg-amber-200 dark:hover:bg-amber-800' : ''
                            ]"
                        >
                            <span>{{ isNoteMode ? 'Add Note' : 'Send' }}</span>
                            <Send class="h-3.5 w-3.5 ml-1.5" />
                        </Button>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right Sidebar: Visitor Info & Context -->
        <div class="w-72 flex-shrink-0 bg-[var(--surface-secondary)]/30 overflow-y-auto">
            
            <!-- Context Header -->
            <div class="p-6 border-b border-[var(--border-default)] flex flex-col items-center text-center">
                <Avatar :initials="activeChat.visitorName.charAt(0)" size="xl" class="mb-4 shadow-sm" />
                <h3 class="font-semibold text-lg text-[var(--text-primary)] mb-1">{{ activeChat.visitorName }}</h3>
                <p class="text-sm text-[var(--text-secondary)] mb-4">{{ activeChat.email }}</p>
                
                <div class="flex w-full gap-2">
                    <Button variant="outline" size="sm" class="flex-1 text-xs">View Profile</Button>
                    <Button variant="outline" size="sm" class="flex-1 text-xs">Create Ticket</Button>
                </div>
            </div>

            <!-- Session Details -->
            <div class="p-5 border-b border-[var(--border-default)] text-sm">
                <h4 class="font-semibold text-[var(--text-primary)] mb-4 text-xs uppercase tracking-wider">Current Session</h4>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <MapPin class="h-4 w-4 text-[var(--text-muted)] mt-0.5" />
                        <div>
                            <p class="text-[var(--text-secondary)] text-xs">Location</p>
                            <p class="text-[var(--text-primary)] font-medium">{{ activeChat.location }}</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-3">
                        <Globe class="h-4 w-4 text-[var(--text-muted)] mt-0.5" />
                        <div>
                            <p class="text-[var(--text-secondary)] text-xs">Browser & OS</p>
                            <p class="text-[var(--text-primary)] font-medium">{{ activeChat.browser }}</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-3">
                        <Hash class="h-4 w-4 text-[var(--text-muted)] mt-0.5" />
                        <div>
                            <p class="text-[var(--text-secondary)] text-xs">IP Address</p>
                            <p class="text-[var(--text-primary)] font-medium">{{ activeChat.ip }}</p>
                            <p class="text-xs text-[var(--text-muted)]">Local time: {{ activeChat.currentTime }}</p>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Quick Links -->
            <div class="p-5 border-b border-[var(--border-default)]">
                <h4 class="font-semibold text-[var(--text-primary)] mb-4 text-xs uppercase tracking-wider">Quick Links</h4>
                <div class="grid grid-cols-1 gap-2">
                    <button class="flex items-center gap-3 p-2 bg-[var(--surface-secondary)]/50 hover:bg-[var(--surface-secondary)] border border-[var(--border-default)] rounded-xl transition-all group w-full text-left">
                        <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center group-hover:scale-110 transition-transform shrink-0">
                            <Book class="w-4 h-4 text-blue-500" />
                        </div>
                        <span class="text-xs font-semibold text-[var(--text-primary)]">Knowledge Base</span>
                    </button>
                    <button class="flex items-center gap-3 p-2 bg-[var(--surface-secondary)]/50 hover:bg-[var(--surface-secondary)] border border-[var(--border-default)] rounded-xl transition-all group w-full text-left">
                        <div class="w-8 h-8 rounded-lg bg-purple-500/10 flex items-center justify-center group-hover:scale-110 transition-transform shrink-0">
                            <Code class="w-4 h-4 text-purple-500" />
                        </div>
                        <span class="text-xs font-semibold text-[var(--text-primary)]">API Documentation</span>
                    </button>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="p-5 text-sm">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="font-semibold text-[var(--text-primary)] text-xs uppercase tracking-wider">Recent Activity</h4>
                    <button class="text-[10px] font-bold text-[var(--interactive-primary)] hover:underline">View All</button>
                </div>
                
                <div class="space-y-2">
                    <div class="p-3 bg-[var(--surface-secondary)]/30 border border-[var(--border-default)]/50 rounded-xl flex items-center gap-3 hover:border-[var(--border-default)] transition-colors cursor-pointer group">
                        <div class="w-8 h-8 rounded-full bg-blue-500/10 flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform">
                            <MessageSquare class="w-4 h-4 text-blue-500" />
                        </div>
                        <div class="flex flex-col min-w-0">
                            <span class="text-xs font-bold text-[var(--text-primary)] truncate">Started Chat</span>
                            <span class="text-[10px] text-[var(--text-muted)]">Today, 10:30 AM</span>
                        </div>
                    </div>

                    <div class="p-3 bg-[var(--surface-secondary)]/30 border border-[var(--border-default)]/50 rounded-xl flex items-center gap-3 hover:border-[var(--border-default)] transition-colors cursor-pointer group">
                        <div class="w-8 h-8 rounded-full bg-slate-500/10 flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform">
                            <Book class="w-4 h-4 text-slate-500" />
                        </div>
                        <div class="flex flex-col min-w-0">
                            <span class="text-xs font-bold text-[var(--text-primary)] truncate">Viewed Knowledge Base</span>
                            <span class="text-[10px] text-[var(--text-muted)]">Today, 10:15 AM</span>
                        </div>
                    </div>

                    <div class="p-3 bg-[var(--surface-secondary)]/30 border border-[var(--border-default)]/50 rounded-xl flex items-center gap-3 hover:border-[var(--border-default)] transition-colors cursor-pointer group">
                        <div class="w-8 h-8 rounded-full bg-emerald-500/10 flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform">
                            <UserPlus class="w-4 h-4 text-emerald-500" />
                        </div>
                        <div class="flex flex-col min-w-0">
                            <span class="text-xs font-bold text-[var(--text-primary)] truncate">Signed up for Pro Plan</span>
                            <span class="text-[10px] text-[var(--text-muted)]">Jan 12, 2024</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</template>

<style scoped>
textarea {
    outline: none !important;
    box-shadow: none !important;
    border: none !important;
}
textarea:focus {
    outline: none !important;
    box-shadow: none !important;
    border: none !important;
}
</style>
