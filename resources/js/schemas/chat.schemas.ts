import { z } from 'zod';

// ============================================================================
// Schema Definitions
// ============================================================================

export const chatParticipantSchema = z.object({
  id: z.string(), // public_id
  name: z.string(),
  public_id: z.string(),
  avatar: z.string().nullable(),
  role: z.enum(['owner', 'admin', 'member']).nullable(),
  is_online: z.boolean(),
  presence_status: z.enum(['online', 'away', 'busy', 'offline']),
});

export const lastMessageSchema = z.object({
  id: z.string(),
  user_name: z.string().nullable(),
  content: z.string().nullable(),
  created_at: z.string(),
  has_media: z.boolean(),
});

export const chatSchema = z.object({
  id: z.string(),
  public_id: z.string(),
  name: z.string().nullable(),
  type: z.enum(['dm', 'group', 'team']),
  avatar_url: z.string().nullable(),
  created_at: z.string(),
  updated_at: z.string(),
  participants: z.array(chatParticipantSchema),
  last_message: lastMessageSchema.nullable(),
  team_owner_id: z.number().nullable(),
  marked_for_deletion_at: z.string().nullable().optional(),
});

export const attachmentSchema = z.object({
  id: z.number(),
  name: z.string(),
  size: z.number(),
  mime_type: z.string(),
  is_image: z.boolean(),
  url: z.string(),
  download_url: z.string(),
  thumb_url: z.string().nullable(),
});

export const messageReplySchema = z.object({
  id: z.string(),
  user_public_id: z.string().nullable(),
  user_name: z.string().nullable(),
  content: z.string(),
  has_media: z.boolean(),
});

export const messageSchema = z.object({
  id: z.string(),
  type: z.enum(['user', 'system']),
  user_public_id: z.string().nullable(),
  user_name: z.string(),
  user_avatar: z.string().nullable(),
  content: z.string(),
  created_at: z.string(),
  is_seen: z.boolean(),
  seen: z.boolean(),
  seen_at: z.string().nullable(),
  reply_to: messageReplySchema.nullable(),
  attachments: z.array(attachmentSchema),
  metadata: z.record(z.any()).nullable().optional(),
});

export const chatInviteSchema = z.object({
  id: z.string(), // public_id
  inviter_name: z.string(),
  inviter_public_id: z.string().optional(),
  avatar_url: z.string().nullable(),
  sent_at: z.string(),
  type: z.enum(['dm', 'group']),
  chat_name: z.string().nullable(),
  chat_public_id: z.string().nullable().optional(),
});

export const discoverablePersonSchema = z.object({
  id: z.string(), // public_id
  name: z.string(),
  email: z.string(),
  public_id: z.string(),
  avatar: z.string().nullable(),
  is_online: z.boolean(),
  presence_status: z.enum(['online', 'away', 'busy', 'offline', 'unknown']),
});

// ============================================================================
// Request Schemas
// ============================================================================

export const sendMessageSchema = z.object({
  content: z.string().max(4000, 'Message is too long'), // Removed min(1) to allow empty content with attachments/metadata
  reply_to: z.string().optional(),
  temp_id: z.string().optional(),
  metadata: z.record(z.any()).optional(),
});

export const createGroupSchema = z.object({
  name: z.string().max(80, 'Group name is too long').optional(),
});

export const renameGroupSchema = z.object({
  name: z.string().min(1, 'Group name is required').max(80, 'Group name is too long'),
});

export const addMemberSchema = z.object({
  user_public_id: z.string().min(1, 'User public ID is required'),
});

export const ensureDmSchema = z.object({
  public_id: z.string().min(1, 'User public ID is required'),
});

export const sendInviteSchema = z.object({
  invitee_public_id: z.string().min(1, 'Invitee public ID is required'),
});

// ============================================================================
// Response Schemas
// ============================================================================

export const chatListResponseSchema = z.object({
  data: z.array(chatSchema),
});

export const chatResponseSchema = z.object({
  data: chatSchema,
});

export const messagesResponseSchema = z.object({
  data: z.array(messageSchema),
  has_more: z.boolean(),
});

export const sendMessageResponseSchema = z.object({
  data: messageSchema,
});

export const invitesResponseSchema = z.object({
  data: z.array(chatInviteSchema),
});

export const peopleResponseSchema = z.object({
  data: z.array(discoverablePersonSchema),
});

// ============================================================================
// Type Exports
// ============================================================================

export type ChatParticipant = z.infer<typeof chatParticipantSchema>;
export type Chat = z.infer<typeof chatSchema>;
export type Message = z.infer<typeof messageSchema>;
export type MessageAttachment = z.infer<typeof attachmentSchema>;
export type ChatInvite = z.infer<typeof chatInviteSchema>;
export type DiscoverablePerson = z.infer<typeof discoverablePersonSchema>;
export type SendMessageInput = z.infer<typeof sendMessageSchema>;
export type CreateGroupInput = z.infer<typeof createGroupSchema>;
