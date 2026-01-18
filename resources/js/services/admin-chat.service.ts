import { BaseService } from './base.service';
import { z } from 'zod';
import type { Chat } from '@/types/models/chat';
import { chatSchema } from '@/schemas/chat.schemas';

// Extended chat schema for admin view might include more details if needed, 
// but for now the base chat schema + flagged info is enough.
// The controller returns a list of chats.

export const flaggedChatsResponseSchema = z.object({
  data: z.array(chatSchema),
});

class AdminChatService extends BaseService {
  private readonly basePath = '/api/admin/chats';

  /**
   * Get all chats flagged for deletion.
   */
  async getFlaggedChats(): Promise<Chat[]> {
    try {
      const response = await this.api.get<{ data: Chat[] }>(`${this.basePath}/flagged`);
      // Validate with schema if we want strictness, or just return data
      // Using parse ensures types match
      const validated = flaggedChatsResponseSchema.parse(response.data);
      return validated.data;
    } catch (error) {
      this.handleError(error);
      return [];
    }
  }

  /**
   * Restore a flagged chat.
   */
  async restoreChat(chatId: string): Promise<void> {
    try {
      await this.api.post(`${this.basePath}/${chatId}/restore`);
    } catch (error) {
      this.handleError(error);
    }
  }
}

export const adminChatService = new AdminChatService();
export default adminChatService;
