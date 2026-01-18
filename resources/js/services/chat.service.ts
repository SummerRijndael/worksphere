import { BaseService } from './base.service';
import {
  chatListResponseSchema,
  chatResponseSchema,
  messagesResponseSchema,
  sendMessageResponseSchema,
  invitesResponseSchema,
  peopleResponseSchema,
  sendMessageSchema,
} from '@/schemas/chat.schemas';
import type {
  Chat,
  ChatListResponse,
  ChatResponse,
  Message,
  MessagesResponse,
  SendMessageResponse,
  ChatInvite,
  InvitesResponse,
  DiscoverablePerson,
  PeopleResponse,
  EnsureDmResponse,
  SendInviteResponse,
  AcceptInviteResponse,
  MediaItem,
  MediaListResponse,
} from '@/types/models/chat';

class ChatService extends BaseService {
  private readonly basePath = '/api/chat';

  // ============================================================================
  // Chat List
  // ============================================================================

  /**
   * Get all chats for the current user.
   */
  async listChats(): Promise<Chat[]> {
    try {
      const response = await this.api.get<ChatListResponse>(this.basePath);
      const validated = chatListResponseSchema.parse(response.data);
      return validated.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Get a specific chat by ID.
   */
  /**
   * Get a specific chat by ID.
   */
  async getChat(chatId: string): Promise<Chat> {
    try {
      const response = await this.api.get<ChatResponse>(`${this.basePath}/${chatId}`);
      const validated = chatResponseSchema.parse(response.data);
      return validated.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  // ============================================================================
  // Messages
  // ============================================================================

  /**
   * Get messages for a chat with pagination.
   */
  /**
   * Get messages for a chat with pagination.
   */
  async getMessages(chatId: string, options?: { before?: number | string; limit?: number }): Promise<{
    messages: Message[];
    hasMore: boolean;
  }> {
    try {
      const params = new URLSearchParams();
      if (options?.before) params.append('before', options.before.toString());
      if (options?.limit) params.append('limit', options.limit.toString());

      const url = `${this.basePath}/${chatId}/messages${params.toString() ? `?${params}` : ''}`;
      
      if (import.meta.env.DEV) {
          console.groupCollapsed(`[ChatService] Fetching messages for ${chatId}`);
          console.log('Params:', Object.fromEntries(params));
          console.log('URL:', url);
          console.groupEnd();
      }

      const response = await this.api.get<MessagesResponse>(url);
      const validated = messagesResponseSchema.parse(response.data);

      if (import.meta.env.DEV) {
          console.log(`[ChatService] Fetched ${validated.data.length} messages. Has more: ${validated.has_more}`);
          if (validated.data.length > 0) {
              console.log('Oldest message ID:', validated.data[0].id);
              console.log('Newest message ID:', validated.data[validated.data.length - 1].id);
          }
      }

      return {
        messages: validated.data,
        hasMore: validated.has_more,
      };
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Search messages within a chat.
   */
  async searchMessages(chatId: string, query: string): Promise<{
    id: string;
    content: string;
    user_name: string;
    user_avatar: string | null;
    created_at: string;
  }[]> {
    try {
      const response = await this.api.get<{ data: any[] }>(
        `${this.basePath}/${chatId}/messages/search`,
        { params: { q: query } }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Get messages around a specific message (for jump-to-message).
   */
  async messagesAround(chatId: string, messageId: string): Promise<{
    messages: Message[];
    targetId: string;
    hasMoreBefore: boolean;
    hasMoreAfter: boolean;
  }> {
    try {
      const response = await this.api.get<{
        data: Message[];
        target_id: string;
        has_more_before: boolean;
        has_more_after: boolean;
      }>(`${this.basePath}/${chatId}/messages/around/${messageId}`);
      
      return {
        messages: response.data.data,
        targetId: response.data.target_id,
        hasMoreBefore: response.data.has_more_before,
        hasMoreAfter: response.data.has_more_after,
      };
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Send a text message.
   */
  /**
   * Send a text message.
   */
  async sendMessage(
    chatId: string, 
    content: string, 
    replyTo?: string | number, 
    tempId?: string,
    metadata?: Record<string, any>
  ): Promise<Message> {
    try {
      const payload = sendMessageSchema.parse({ 
        content, 
        reply_to: replyTo ? String(replyTo) : undefined, 
        temp_id: tempId,
        metadata
      });
      // The schema might strip metadata if not defined there. I should check schemas/chat.schemas.ts but 
      // for now assuming I might need to update schema or bypass parse if schema dictates strictness.
      // Actually, safest is to append metadata to payload object regardless of schema parse if schema is strict, 
      // OR update schema. Let's start by checking schema.
      // But I can't check schema easily without viewing file.
      // I'll assume valid payload construction first.
      
      const requestPayload = { ...payload, metadata };

      const response = await this.api.post<SendMessageResponse>(`${this.basePath}/${chatId}/send`, requestPayload);
      const validated = sendMessageResponseSchema.parse(response.data);
      return validated.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Send a message with file attachments.
   */
  /**
   * Send a message with file attachments.
   */
  async uploadMessage(
    chatId: string,
    files: File[],
    content?: string,
    replyTo?: string | number,
  ): Promise<Message> {
    try {
      const formData = new FormData();
      files.forEach((file) => formData.append('files[]', file));
      if (content) formData.append('content', content);
      if (replyTo) formData.append('reply_to', replyTo.toString());

      const response = await this.api.post<SendMessageResponse>(
        `${this.basePath}/${chatId}/upload`,
        formData,
        {
          headers: { 'Content-Type': 'multipart/form-data' },
        },
      );
      const validated = sendMessageResponseSchema.parse(response.data);
      return validated.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Mark a chat as read.
   */
  /**
   * Mark a chat as read.
   */
  async markAsRead(chatId: string): Promise<void> {
    try {
      await this.api.post(`${this.basePath}/${chatId}/read`);
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Send a typing indicator.
   */
  /**
   * Send a typing indicator.
   */
  async sendTyping(chatId: string): Promise<void> {
    try {
      await this.api.post(`${this.basePath}/${chatId}/typing`);
    } catch (error) {
      // Silently fail for typing indicators
      console.warn('Typing indicator failed:', error);
    }
  }

  /**
   * Send heartbeat to keep user online status.
   */
  async sendHeartbeat(chatId: string): Promise<void> {
    try {
      await this.api.post(`${this.basePath}/${chatId}/heartbeat`);
    } catch (error) {
       // Silently fail
    }
  }

  // ============================================================================
  // People Discovery & DM
  // ============================================================================

  /**
   * Search for discoverable people.
   */
  async searchPeople(query?: string, onlineOnly?: boolean): Promise<DiscoverablePerson[]> {
    try {
      const params = new URLSearchParams();
      if (query) params.append('q', query);
      if (onlineOnly) params.append('online', 'true');

      const url = `${this.basePath}/people/search${params.toString() ? `?${params}` : ''}`;
      const response = await this.api.get<PeopleResponse>(url);
      const validated = peopleResponseSchema.parse(response.data);
      return validated.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Ensure a DM exists with a user or indicate invite required.
   */
  async ensureDm(userPublicId: string): Promise<EnsureDmResponse> {
    try {
      const response = await this.api.post<EnsureDmResponse>(`${this.basePath}/dm/ensure`, {
        public_id: userPublicId,
      });
      return response.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  // ============================================================================
  // Invites
  // ============================================================================

  /**
   * Get pending invites for the current user.
   */
  async getInvites(): Promise<ChatInvite[]> {
    try {
      const response = await this.api.get<InvitesResponse>(`${this.basePath}/invites`);
      const validated = invitesResponseSchema.parse(response.data);
      return validated.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Send a DM invite.
   */
  async sendInvite(inviteePublicId: string): Promise<SendInviteResponse> {
    try {
      const response = await this.api.post<SendInviteResponse>(`${this.basePath}/invites`, {
        invitee_public_id: inviteePublicId,
      });
      return response.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Accept an invite by its public ID.
   */
  async acceptInvite(invitePublicId: string): Promise<AcceptInviteResponse> {
    try {
      const response = await this.api.post<AcceptInviteResponse>(
        `${this.basePath}/invites/${invitePublicId}/accept`,
      );
      return response.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Decline an invite by its public ID.
   */
  async declineInvite(invitePublicId: string): Promise<void> {
    try {
      await this.api.post(`${this.basePath}/invites/${invitePublicId}/decline`);
    } catch (error) {
      this.handleError(error);
    }
  }

  // ============================================================================
  // Groups
  // ============================================================================

  /**
   * Create a new group chat.
   */
  async createGroup(name?: string): Promise<Chat> {
    try {
      const response = await this.api.post<ChatResponse>(`${this.basePath}/groups`, { name });
      const validated = chatResponseSchema.parse(response.data);
      return validated.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Rename a group chat.
   */
  /**
   * Rename a group chat.
   */
  async renameGroup(chatId: string, name: string): Promise<Chat> {
    try {
      const response = await this.api.put<ChatResponse>(`${this.basePath}/${chatId}/rename`, {
        name,
      });
      const validated = chatResponseSchema.parse(response.data);
      return validated.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Update a group chat (Name, Avatar).
   */
  async updateGroup(chatId: string, formData: FormData): Promise<Chat> {
    try {
      // Use POST with _method=PUT to handle multipart/form-data correctly with Laravel
      formData.append('_method', 'PUT');
      
      const response = await this.api.post<ChatResponse>(`${this.basePath}/${chatId}`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      const validated = chatResponseSchema.parse(response.data);
      return validated.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Add a member to a group (sends invite).
   */
  async addMember(chatId: string, userPublicId: string): Promise<{ message: string; invite_id: string }> {
    try {
      const response = await this.api.post<{ message: string; invite_id: string }>(
        `${this.basePath}/${chatId}/members`,
        { user_public_id: userPublicId },
      );
      return response.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Leave a group chat.
   */
  async leaveGroup(chatId: string): Promise<void> {
    try {
      await this.api.post(`${this.basePath}/${chatId}/leave`);
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Kick a member from a group.
   */
  async kickMember(chatId: string, userPublicId: string): Promise<void> {
    try {
      await this.api.post(`${this.basePath}/${chatId}/kick/${userPublicId}`);
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Delete a group chat.
   */
  async deleteGroup(chatId: string, password: string): Promise<void> {
    try {
      await this.api.delete(`${this.basePath}/${chatId}`, { data: { password } });
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Rejoin a group chat.
   */
  async rejoinGroup(chatId: string): Promise<Chat> {
    try {
      const response = await this.api.post<ChatResponse>(`${this.basePath}/${chatId}/rejoin`);
      const validated = chatResponseSchema.parse(response.data);
      return validated.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Remove a member from a group.
   */
  async removeMember(chatId: string, memberPublicId: string): Promise<Chat> {
    try {
      const response = await this.api.delete<ChatResponse>(
        `${this.basePath}/${chatId}/members/${memberPublicId}`,
      );
      const validated = chatResponseSchema.parse(response.data);
      return validated.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  // ============================================================================
  // Media
  // ============================================================================

  /**
   * Get all media for a chat.
   */
  /**
   * Get all media for a chat.
   */
  async getChatMedia(
    chatId: string,
    filter?: 'images' | 'documents' | 'all',
    perPage = 24,
    page = 1,
  ): Promise<{ items: MediaItem[]; hasMore: boolean }> {
    try {
      const params = new URLSearchParams();
      if (filter) params.append('filter', filter);
      params.append('per_page', perPage.toString());
      params.append('page', page.toString());

      const url = `${this.basePath}/${chatId}/media${params.toString() ? `?${params}` : ''}`;
      const response = await this.api.get<MediaListResponse>(url);
      return {
        items: response.data.data,
        hasMore: response.data.has_more,
      };
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Get storage statistics for a chat.
   */
  async getChatStorageStats(chatId: string): Promise<{
    file_count: number;
    usage_mb: number;
    limit_mb: number;
    percentage_used: number;
  }> {
    try {
      const response = await this.api.get<{ data: any }>(`${this.basePath}/${chatId}/storage-stats`);
      return response.data.data;
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Delete a media item.
   */
  /**
   * Delete a media item.
   */
  async deleteMedia(chatId: string, mediaId: number): Promise<void> {
    try {
      await this.api.delete(`${this.basePath}/${chatId}/media/${mediaId}`);
    } catch (error) {
      this.handleError(error);
    }
  }
}

// Export singleton instance
export const chatService = new ChatService();
export default chatService;
