import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { Ref } from 'vue';
import { notificationService } from '@/services';
import type { Notification, PaginatedResponse } from '@/types';
import { toast } from 'vue-sonner';

export const useNotificationsStore = defineStore('notifications', () => {
    // State
    const notifications: Ref<Notification[]> = ref([]);
    const unreadCount = ref(0);
    const isLoading = ref(false);
    const page = ref(1);
    const hasMore = ref(true);

    // Actions
    async function fetchNotifications(reset: boolean = false): Promise<void> {
        if (reset) {
            page.value = 1;
            notifications.value = [];
            hasMore.value = true;
        }

        if (isLoading.value || !hasMore.value) return;

        isLoading.value = true;
        try {
            const response: PaginatedResponse<Notification> = await notificationService.fetchNotifications(page.value);

            const newNotifications = response.data;
            notifications.value = [...notifications.value, ...newNotifications];

            if (response.next_page_url) {
                page.value++;
            } else {
                hasMore.value = false;
            }
        } catch (error) {
            console.error('Failed to fetch notifications:', error);
        } finally {
            isLoading.value = false;
        }
    }

    async function fetchUnreadCount(): Promise<void> {
        try {
            unreadCount.value = await notificationService.fetchUnreadCount();
        } catch (error) {
            console.error('Failed to fetch unread count:', error);
        }
    }

    async function markAsRead(id: string): Promise<void> {
        try {
            await notificationService.markAsRead(id);
            const notification = notifications.value.find(n => n.id === id);
            if (notification && !notification.read_at) {
                notification.read_at = new Date().toISOString();
                unreadCount.value = Math.max(0, unreadCount.value - 1);
            }
        } catch (error) {
            console.error('Failed to mark as read:', error);
        }
    }

    async function markAllRead(): Promise<void> {
        try {
            await notificationService.markAllAsRead();
            notifications.value.forEach(n => {
                if (!n.read_at) n.read_at = new Date().toISOString();
            });
            unreadCount.value = 0;
            toast.success('All notifications marked as read');
        } catch (error) {
            console.error('Failed to mark all as read:', error);
            toast.error('Failed to mark all as read');
        }
    }

    async function removeNotification(id: string): Promise<void> {
        try {
            await notificationService.deleteNotification(id);
            notifications.value = notifications.value.filter(n => n.id !== id);
        } catch (error) {
            console.error('Failed to remove notification:', error);
        }
    }

    async function acceptInvitation(notificationId: string): Promise<void> {
        try {
            const response = await notificationService.api.post(`/api/invitations/${notificationId}/accept`);
            toast.success(response.data.message);
            await fetchNotifications(true); // Refresh list
            await fetchUnreadCount();

            // Refresh navigation to show new team
            const { useNavigationStore } = await import('@/stores/navigation');
            const navigationStore = useNavigationStore();
            await navigationStore.fetchNavigation();
        } catch (error: any) {
            console.error('Error accepting invitation:', error);
            toast.error(error.response?.data?.message || 'Failed to accept invitation');
        }
    }

    async function declineInvitation(notificationId: string): Promise<void> {
        try {
            const response = await notificationService.api.post(`/api/invitations/${notificationId}/decline`);
            toast.success(response.data.message);
            // Remove locally
            notifications.value = notifications.value.filter(n => n.id !== notificationId);
            await fetchUnreadCount();
        } catch (error: any) {
            console.error('Error declining invitation:', error);
            toast.error(error.response?.data?.message || 'Failed to decline invitation');
        }
    }

    // Real-time notifications
    async function startRealtimeListeners(): Promise<void> {
        const { useAuthStore } = await import('@/stores/auth');
        const authStore = useAuthStore();

        if (!authStore.user?.public_id) return;

        // Dynamic import to avoid circular dependencies
        // Use isEchoAvailable() to check if Echo is actually initialized
        const { default: echo, isEchoAvailable } = await import('@/echo');

        if (!isEchoAvailable()) {
            console.debug('[Notifications] Echo not available, skipping realtime listeners');
            return;
        }

        const channelName = `App.Models.User.${authStore.user.public_id}`;

        echo.private(channelName)
            .notification((notification: Notification) => {
                console.log('[Notifications] Received:', notification);
                // Add to list if not exists
                if (!notifications.value.find(n => n.id === notification.id)) {
                    notifications.value.unshift(notification);
                    unreadCount.value++;

                    // Show toast
                    const title = notification.title || notification.data?.title || 'New Notification';
                    const message = notification.message || notification.data?.message;

                    // Play sound
                    try {
                        const audio = new Audio('/static/sounds/notification.mp3');
                        audio.play().catch(e => console.debug('Audio play blocked', e));
                    } catch (e) {
                        // ignore
                    }

                    toast(title, {
                        description: message,
                        action: {
                            label: 'View',
                            onClick: () => {
                                // Dynamic import of router to avoid circular dependency
                                import('@/router').then(({ default: router }) => {
                                    if (notification.type === 'App\\Notifications\\EventReminder' || notification.data?.type === 'event_reminder') {
                                        router.push('/calendar');
                                    } else {
                                        router.push('/notifications');
                                    }
                                });
                            }
                        }
                    });
                }
            });

        console.debug(`[Notifications] Listening on ${channelName}`);
    }

    async function stopRealtimeListeners(): Promise<void> {
         const { useAuthStore } = await import('@/stores/auth');
         const authStore = useAuthStore();
         const { default: echo, isEchoAvailable } = await import('@/echo');

         if (authStore.user?.public_id && isEchoAvailable()) {
             echo.leave(`App.Models.User.${authStore.user.public_id}`);
         }
    }

    return {
        notifications,
        unreadCount,
        isLoading,
        hasMore,
        fetchNotifications,
        fetchUnreadCount,
        markAsRead,
        markAllRead,
        removeNotification,
        acceptInvitation,
        declineInvitation,
        startRealtimeListeners,
        stopRealtimeListeners
    };
});
