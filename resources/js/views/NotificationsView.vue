<script setup>
import { onMounted, ref, computed } from 'vue';
import { Card, Button, Badge, Avatar } from '@/components/ui';
import { Bell, Check, Trash2, Settings, Filter, UserPlus, X } from 'lucide-vue-next';
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

// Helper to check read status (API uses read_at timestamp)
const isRead = (n) => !!n.read_at;

const filteredNotifications = computed(() => {
    if (filter.value === 'unread') {
        return notifications.value.filter(n => !isRead(n));
    }
    return notifications.value;
});

function getTypeIcon(notification) {
    // Check for custom type in data, or fallback to class matching
    const type = notification.data?.type || notification.type;
    
    if (type === 'team_invitation' || type === 'App\\Notifications\\TeamInvitationNotification') {
        return 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400';
    }
    
    switch (type) {
        case 'mention': return 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400';
        case 'task': return 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400';
        case 'project': return 'bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400';
        case 'comment': return 'bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400';
        default: return 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400';
    }
}

onMounted(() => {
    fetchNotifications();
});
</script>

<template>
    <div class="max-w-3xl space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">Notifications</h1>
                <p class="text-[var(--text-secondary)] mt-1">
                    You have {{ unreadCount }} unread notifications
                </p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" size="sm" @click="markAllAsRead" :disabled="unreadCount === 0">
                    <Check class="h-4 w-4" />
                    Mark all read
                </Button>
                <!-- Refresh Button -->
                 <Button variant="ghost" size="sm" @click="fetchNotifications()">
                    <Filter class="h-4 w-4" /> <!-- Using Filter icon as Refresh placeholder if needed or just remove -->
                    Refresh
                </Button>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex gap-2">
            <Button
                :variant="filter === 'all' ? 'secondary' : 'ghost'"
                size="sm"
                @click="filter = 'all'"
            >
                All
            </Button>
            <Button
                :variant="filter === 'unread' ? 'secondary' : 'ghost'"
                size="sm"
                @click="filter = 'unread'"
            >
                Unread
                <Badge v-if="unreadCount > 0" variant="primary" size="sm">{{ unreadCount }}</Badge>
            </Button>
        </div>

        <!-- Notifications List -->
        <Card padding="none">
            <div v-if="loading && notifications.length === 0" class="p-8 text-center text-[var(--text-muted)]">
                Loading notifications...
            </div>
            <div v-else-if="filteredNotifications.length" class="divide-y divide-[var(--border-muted)]">
                <div
                    v-for="notification in filteredNotifications"
                    :key="notification.id"
                    :class="[
                        'group flex gap-4 p-4 transition-colors hover:bg-[var(--surface-secondary)]',
                        !isRead(notification) && 'bg-[var(--color-primary-50)] dark:bg-[var(--color-primary-900)]/10'
                    ]"
                >
                    <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', getTypeIcon(notification)]">
                        <UserPlus v-if="notification.data?.type === 'team_invitation'" class="h-5 w-5" />
                        <Bell v-else class="h-5 w-5" />
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2">
                            <!-- Title/Message -->
                            <div class="space-y-1">
                                <p :class="['text-sm', isRead(notification) ? 'text-[var(--text-primary)]' : 'font-medium text-[var(--text-primary)]']">
                                    {{ notification.data?.message || notification.data?.title || 'New Notification' }}
                                </p>
                                
                                <!-- Team Invitation Actions -->
                                <div v-if="(notification.data?.type === 'team_invitation' || notification.type === 'App\\Notifications\\TeamInvitationNotification') && !isRead(notification)" class="flex items-center gap-3 pt-2">
                                    <Button size="sm" @click="acceptInvitation(notification.id)">
                                        <Check class="h-4 w-4 mr-1.5" />
                                        Accept
                                    </Button>
                                    <Button size="sm" variant="ghost" class="text-red-500 hover:text-red-600 hover:bg-red-50" @click="declineInvitation(notification.id)">
                                        <X class="h-4 w-4 mr-1.5" />
                                        Decline
                                    </Button>
                                </div>
                                
                                <p v-else-if="notification.data?.message && notification.data?.title" class="text-sm text-[var(--text-secondary)]">
                                    {{ notification.data.message }}
                                </p>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <Button
                                    v-if="!isRead(notification) && notification.data?.type !== 'team_invitation'"
                                    variant="ghost"
                                    size="icon"
                                    class="h-7 w-7"
                                    @click="markAsRead(notification.id)"
                                    title="Mark as read"
                                >
                                    <Check class="h-3.5 w-3.5" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-7 w-7 text-[var(--color-error)]"
                                    @click="deleteNotification(notification.id)"
                                    title="Delete"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        </div>
                        <p class="text-xs text-[var(--text-muted)] mt-1">
                            {{ new Date(notification.created_at).toLocaleDateString() }} 
                            {{ new Date(notification.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) }}
                        </p>
                    </div>

                    <div v-if="!isRead(notification)" class="h-2 w-2 shrink-0 rounded-full bg-[var(--interactive-primary)] mt-2" />
                </div>
            </div>

            <div v-else class="flex flex-col items-center justify-center py-12 text-center">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-[var(--surface-secondary)] mb-4">
                    <Bell class="h-6 w-6 text-[var(--text-muted)]" />
                </div>
                <p class="text-[var(--text-primary)] font-medium">No notifications</p>
                <p class="text-sm text-[var(--text-muted)] mt-1">You're all caught up!</p>
            </div>
        </Card>
    </div>
</template>
