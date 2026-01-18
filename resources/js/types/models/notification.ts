export interface Notification {
  id: string;
  type: string;
  notifiable_type: string;
  notifiable_id: number;
  data: NotificationData;
  read_at: string | null;
  created_at: string;
  updated_at: string;
  // Convenience properties for real-time notifications
  title?: string;
  message?: string;
}

export interface NotificationData {
  title: string;
  message: string;
  action_url?: string;
  icon?: string;
  [key: string]: any;
}
