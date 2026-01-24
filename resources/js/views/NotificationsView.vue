<script setup>
import { onMounted, ref, computed } from 'vue';
import { Card, Button, Badge, Avatar } from '@/components/ui';
import { Bell, Check, Trash2, Settings, Filter, UserPlus, X, Calendar, MessageSquare, Briefcase, FileText } from 'lucide-vue-next';
import { useNotifications } from '@/composables/useNotifications.ts';

const { 
    notifications, 
    unreadCount, 
    loading, 
    fetchNotifications, 
    markAsRead, 
    markAllAsRead, 
    deleteNotification,
    acceptInvitation,
    declineInvitation
} = useNotifications();

const filter = ref('all');

// Helper to check read status
const isRead = (n) => !!n.read_at;

const filteredNotifications = computed(() => {
    let filtered = notifications.value;
    if (filter.value === 'unread') {
        filtered = filtered.filter(n => !isRead(n));
    }
    return filtered;
});

// Grouping Logic
const groupedNotifications = computed(() => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);

    const groups = {
        today: [],
        yesterday: [],
        older: []
    };

    filteredNotifications.value.forEach(notification => {
        const date = new Date(notification.created_at);
        date.setHours(0, 0, 0, 0);

        if (date.getTime() === today.getTime()) {
            groups.today.push(notification);
        } else if (date.getTime() === yesterday.getTime()) {
            groups.yesterday.push(notification);
        } else {
            groups.older.push(notification);
        }
    });

    return groups;
});

// Icon/Avatar Helper
function getNotificationIcon(n) {
    const type = n.data?.type || n.type;
    // Specific system icons based on type
    if (type === 'team_invitation' || type.includes('TeamInvitation')) return UserPlus;
    if (type === 'mention') return MessageSquare;
    if (type === 'task') return Briefcase;
    if (type === 'project') return Calendar;
    return Bell;
}

function getIconColorClass(n) {
    const type = n.data?.type || n.type;
    if (type === 'team_invitation' || type.includes('TeamInvitation')) return 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-400';
    if (type === 'mention') return 'bg-blue-100 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400';
    if (type === 'task') return 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400';
    if (type === 'project') return 'bg-purple-100 text-purple-600 dark:bg-purple-500/20 dark:text-purple-400';
    // SLA / Warning
    if (type.includes('sla')) return 'bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400';
    
    return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400';
}

onMounted(() => {
    fetchNotifications();
});
</script>

<template>
    <div class="max-w-4xl mx-auto space-y-8 pb-12">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-[var(--text-primary)]">Notifications</h1>
                <p class="text-[var(--text-secondary)] mt-1">
                    Stay updated with your latest activity.
                </p>
            </div>
            <div class="flex items-center gap-2">
                 <Button 
                    v-if="unreadCount > 0"
                    variant="outline" 
                    size="sm" 
                    @click="markAllAsRead" 
                    class="hidden sm:flex"
                >
                    <Check class="h-4 w-4 mr-2" />
                    Mark all read
                </Button>
                 <Button variant="ghost" size="icon" @click="fetchNotifications()">
                    <Filter class="h-4 w-4 text-[var(--text-secondary)]" />
                </Button>
            </div>
        </div>

        <!-- Filters & Mobile Action -->
        <div class="flex items-center justify-between">
            <div class="flex p-1 bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-subtle)]">
                <button
                    v-for="opt in ['all', 'unread']"
                    :key="opt"
                    @click="filter = opt"
                    :class="[
                        'px-4 py-1.5 text-sm font-medium rounded-md transition-all',
                        filter === opt
                            ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                            : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                    ]"
                >
                    {{ opt.charAt(0).toUpperCase() + opt.slice(1) }}
                    <Badge v-if="opt === 'unread' && unreadCount > 0" variant="primary" size="sm" class="ml-2 px-1.5 py-0.5 min-w-[20px]">{{ unreadCount }}</Badge>
                </button>
            </div>
            
            <Button 
                v-if="unreadCount > 0"
                variant="outline" 
                size="sm" 
                @click="markAllAsRead" 
                class="sm:hidden"
            >
                Mark all read
            </Button>
        </div>

        <!-- Empty State -->
        <div v-if="!loading && notifications.length === 0" class="flex flex-col items-center justify-center py-20 text-center border border-dashed border-[var(--border-subtle)] rounded-xl bg-[var(--surface-secondary)]/30">
            <div class="h-16 w-16 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center mb-6 ring-4 ring-[var(--surface-primary)]">
                <Bell class="h-8 w-8 text-[var(--text-muted)]" />
            </div>
            <h3 class="text-lg font-semibold text-[var(--text-primary)]">No notifications</h3>
            <p class="text-[var(--text-secondary)] max-w-sm mt-2">
                We'll let you know when something important happens.
            </p>
            <Button variant="outline" class="mt-6" @click="fetchNotifications">
                Refresh
            </Button>
        </div>

        <div v-else class="space-y-8">
            <!-- Groups -->
            <template v-for="(group, name) in groupedNotifications" :key="name">
                <section v-if="group.length > 0" class="space-y-4">
                    <h2 class="text-sm font-semibold text-[var(--text-secondary)] uppercase tracking-wider pl-1">
                        {{ name }}
                    </h2>
                    
                    <Card padding="none" class="overflow-hidden border-[var(--border-subtle)] shadow-sm">
                        <div class="divide-y divide-[var(--border-muted)]">
                            <div 
                                v-for="notification in group" 
                                :key="notification.id"
                                class="group relative flex gap-4 p-5 transition-all hover:bg-[var(--surface-secondary)]/50"
                                :class="{ 'bg-[var(--surface-primary)]': isRead(notification), 'bg-[var(--color-primary-50)]/40 dark:bg-[var(--color-primary-900)]/10': !isRead(notification) }"
                            >
                                <!-- Unread Indicator -->
                                <div v-if="!isRead(notification)" class="absolute left-0 top-0 bottom-0 w-1 bg-[var(--color-primary-500)]" />

                                <!-- Icon / Avatar -->
                                <div class="shrink-0 pt-1">
                                    <Avatar 
                                        v-if="notification.data?.causer_avatar || notification.data?.causer_name"
                                        :src="notification.data.causer_avatar" 
                                        :fallback="notification.data?.causer_name?.charAt(0) || '?'"
                                        size="md"
                                        class="ring-2 ring-[var(--surface-primary)]"
                                    />
                                    <div 
                                        v-else
                                        class="h-10 w-10 flex items-center justify-center rounded-xl transition-colors"
                                        :class="getIconColorClass(notification)"
                                    >
                                        <component :is="getNotificationIcon(notification)" class="h-5 w-5" />
                                    </div>
                                </div>

                                <!-- Content -->
                                <div class="flex-1 min-w-0 space-y-1.5">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="pr-8">
                                            <p class="text-sm text-[var(--text-primary)] leading-relaxed">
                                                <span v-if="notification.data?.causer_name" class="font-semibold">{{ notification.data.causer_name }}</span>
                                                <span v-else class="font-semibold">{{ notification.data?.title || 'System' }}</span>
                                                <span class="text-[var(--text-secondary)] font-normal ml-1">
                                                     {{ notification.data?.message?.replace(notification.data?.causer_name || '', '').trim() || 'sent you a notification' }}
                                                </span>
                                            </p>
                                            
                                            <!-- Context/Snippet (Optional extra data) -->
                                            <p v-if="notification.data?.context" class="text-xs text-[var(--text-muted)] mt-1 line-clamp-1 border-l-2 border-[var(--border-default)] pl-2">
                                                "{{ notification.data.context }}"
                                            </p>
                                        </div>
                                        
                                        <span class="text-xs text-[var(--text-muted)] whitespace-nowrap shrink-0">
                                            {{ new Date(notification.created_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) }}
                                        </span>
                                    </div>

                                    <!-- Actions (Invitation, etc.) -->
                                    <div v-if="(notification.data?.type === 'team_invitation' || notification.type?.includes('TeamInvitation')) && !isRead(notification)" class="flex items-center gap-3 pt-2">
                                        <Button size="sm" @click="acceptInvitation(notification.id)" class="h-8">
                                            Accept
                                        </Button>
                                        <Button size="sm" variant="outline" @click="declineInvitation(notification.id)" class="h-8">
                                            Decline
                                        </Button>
                                    </div>
                                </div>

                                <!-- Hover Actions -->
                                <div class="absolute right-2 top-2 flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity p-1 bg-[var(--surface-primary)]/80 backdrop-blur-sm rounded-lg shadow-sm border border-[var(--border-subtle)]">
                                     <button
                                        v-if="!isRead(notification)"
                                        @click.stop="markAsRead(notification.id)"
                                        class="p-1.5 hover:bg-[var(--surface-secondary)] rounded-md text-[var(--text-secondary)] hover:text-[var(--color-primary-600)] transition-colors"
                                        title="Mark as read"
                                    >
                                        <Check class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        @click.stop="deleteNotification(notification.id)"
                                        class="p-1.5 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-md text-[var(--text-secondary)] hover:text-red-500 transition-colors"
                                        title="Delete"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </Card>
                </section>
            </template>
        </div>
    </div>
</template>
