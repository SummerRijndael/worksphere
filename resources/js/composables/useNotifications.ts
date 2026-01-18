import { ref, type Ref } from 'vue';
import { useToast } from '@/composables/useToast';
import { notificationService } from '@/services/notification.service';
import api from '@/lib/api';
import type { Notification } from '@/types/models/notification';

// Module-level state (shared across instances)
const notifications: Ref<Notification[]> = ref([]);
const unreadCount: Ref<number> = ref(0);
const loading: Ref<boolean> = ref(false);

interface UseNotificationsReturn {
  notifications: Ref<Notification[]>;
  unreadCount: Ref<number>;
  loading: Ref<boolean>;
  fetchNotifications: (page?: number) => Promise<void>;
  fetchUnreadCount: () => Promise<void>;
  markAsRead: (id: string) => Promise<void>;
  markAllAsRead: () => Promise<void>;
  deleteNotification: (id: string) => Promise<void>;
  acceptInvitation: (notificationId: string) => Promise<void>;
  declineInvitation: (notificationId: string) => Promise<void>;
}

export function useNotifications(): UseNotificationsReturn {
  const { toast } = useToast();

  // Fetch notifications
  const fetchNotifications = async (page: number = 1): Promise<void> => {
    loading.value = true;
    try {
      const response = await notificationService.fetchNotifications(page);
      // Append if paginated, or replace? Original logic was replace.
      notifications.value = response.data;
      await fetchUnreadCount();
    } catch (error) {
      console.error('Error fetching notifications:', error);
      toast.error('Failed to load notifications');
    } finally {
      loading.value = false;
    }
  };

  // Fetch unread count
  const fetchUnreadCount = async (): Promise<void> => {
    try {
      // The service returns the count number directly
      unreadCount.value = await notificationService.fetchUnreadCount();
    } catch (error) {
      console.error('Error fetching unread count:', error);
    }
  };

  // Mark as read
  const markAsRead = async (id: string): Promise<void> => {
    try {
      await notificationService.markAsRead(id);
      const notification = notifications.value.find((n) => n.id === id);
      if (notification) notification.read_at = new Date().toISOString();
      fetchUnreadCount();
    } catch (error) {
      console.error('Error marking as read:', error);
    }
  };

  // Mark all as read
  const markAllAsRead = async (): Promise<void> => {
    try {
      await notificationService.markAllAsRead();
      notifications.value.forEach((n) => (n.read_at = new Date().toISOString()));
      unreadCount.value = 0;
      toast.success('All notifications marked as read');
    } catch (error) {
      console.error('Error marking all as read:', error);
    }
  };

  // Delete notification
  const deleteNotification = async (id: string): Promise<void> => {
    try {
      await notificationService.deleteNotification(id);
      notifications.value = notifications.value.filter((n) => n.id !== id);
      fetchUnreadCount();
      toast.success('Notification deleted');
    } catch (error) {
      console.error('Error deleting notification:', error);
    }
  };

  // Accept Invitation
  // These endpoints are specific to invitations, so we call api directly or could move to a TeamService
  const acceptInvitation = async (notificationId: string): Promise<void> => {
    try {
      const response = await api.post<{ message: string }>(`/api/invitations/${notificationId}/accept`);
      toast.success(response.data.message);
      await fetchNotifications(); // Refresh list to update status
    } catch (error: any) {
      console.error('Error accepting invitation:', error);
      toast.error(error.response?.data?.message || 'Failed to accept invitation');
    }
  };

  // Decline Invitation
  const declineInvitation = async (notificationId: string): Promise<void> => {
    try {
      const response = await api.post<{ message: string }>(`/api/invitations/${notificationId}/decline`);
      toast.success(response.data.message);
      await deleteNotification(notificationId); // Usually tracking is removed or updated
    } catch (error: any) {
      console.error('Error declining invitation:', error);
      toast.error(error.response?.data?.message || 'Failed to decline invitation');
    }
  };

  return {
    notifications,
    unreadCount,
    loading,
    fetchNotifications,
    fetchUnreadCount,
    markAsRead,
    markAllAsRead,
    deleteNotification,
    acceptInvitation,
    declineInvitation,
  };
}
