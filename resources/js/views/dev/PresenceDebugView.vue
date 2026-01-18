<script setup>
import { ref } from 'vue';
import { cn } from '@/lib/utils';
import ChatDebug from './ChatDebug.vue';
import PresenceDebug from './PresenceDebug.vue';
import CalendarDebug from './CalendarDebug.vue';
import { 
    Activity, 
    Calendar, 
    Info,
    MessageSquare,
} from 'lucide-vue-next';

// Only show in development
const isDev = import.meta.env.DEV;

const activeTab = ref('presence');

const tabs = [
    { id: 'presence', label: 'Presence & Reverb', icon: Activity },
    { id: 'chat', label: 'Chat Debugger', icon: MessageSquare },
    { id: 'calendar', label: 'Calendar Events', icon: Calendar },
];
</script>

<template>
    <!-- Main Container: Use CSS variables from app.css to follow global theme (supports .dark class) -->
    <div v-if="isDev" class="min-h-screen p-6 font-sans bg-[var(--surface-primary)] text-[var(--text-primary)] transition-colors duration-200">
        <div class="max-w-7xl mx-auto space-y-6">
            <!-- Global Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-[var(--text-primary)] flex items-center gap-2">
                        <Info class="h-6 w-6 text-blue-500" />
                        Developer Tools
                    </h1>
                    <p class="text-sm text-[var(--text-secondary)] mt-1">
                        System diagnostics and debugging interfaces
                    </p>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="border-b border-[var(--border-default)]">
                <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        @click="activeTab = tab.id"
                        :class="cn(
                            'group inline-flex items-center gap-2 py-4 px-1 border-b-2 font-medium text-sm transition-colors',
                            activeTab === tab.id
                                ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                                : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-muted)]'
                        )"
                    >
                        <component 
                            :is="tab.icon" 
                            :class="cn(
                                'h-5 w-5',
                                activeTab === tab.id ? 'text-blue-500' : 'text-[var(--text-muted)] group-hover:text-[var(--text-secondary)]'
                            )" 
                        />
                        {{ tab.label }}
                    </button>
                </nav>
            </div>

            <!-- Tab Content -->
            <div class="mt-6">
                <!-- Keep components alive to preserve logs/state when switching -->
                <KeepAlive>
                    <PresenceDebug v-if="activeTab === 'presence'" />
                    <CalendarDebug v-else-if="activeTab === 'calendar'" />
                    <ChatDebug v-else-if="activeTab === 'chat'" />
                </KeepAlive>
            </div>
        </div>
    </div>

    <!-- Not in dev mode message -->
    <div v-else class="min-h-screen flex items-center justify-center bg-[var(--surface-primary)]">
        <div class="text-center">
            <p class="text-[var(--text-primary)] font-semibold">🔒 Development Only</p>
            <p class="text-sm text-[var(--text-secondary)] mt-1">This page is only available in development mode.</p>
        </div>
    </div>
</template>
