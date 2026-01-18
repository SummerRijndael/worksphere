/**
 * Unified avatar composable for resolving avatars across the application.
 *
 * Provides consistent avatar URL resolution, initials generation,
 * and fallback handling for User, Team, Chat, and participant entities.
 */

import type { User, Team } from '@/types/models/user';
import type { Chat, ChatParticipant } from '@/types/models/chat';

/**
 * Avatar data structure matching backend AvatarData DTO.
 */
export interface AvatarData {
  url: string | null;
  fallback: string;
  initials: string;
  color: string;
}

/**
 * Entity types that can have avatars resolved.
 */
export type AvatarEntity = User | Team | Chat | ChatParticipant | Record<string, unknown> | null;

/**
 * Color palette matching backend config/avatar.php
 */
const AVATAR_COLORS = [
  '#6366f1', // Indigo
  '#8b5cf6', // Violet
  '#a855f7', // Purple
  '#ec4899', // Pink
  '#f43f5e', // Rose
  '#ef4444', // Red
  '#f97316', // Orange
  '#eab308', // Yellow
  '#22c55e', // Green
  '#14b8a6', // Teal
  '#06b6d4', // Cyan
  '#3b82f6', // Blue
];

const DEFAULT_FALLBACK = '/static/images/avatar/blank.png';
const GROUP_FALLBACK = '/static/images/avatar/group.png';

/**
 * Composable for avatar resolution and utilities.
 */
export function useAvatar() {
  /**
   * Generate initials from a name.
   */
  function getInitials(name: string | null | undefined): string {
    if (!name || name.trim() === '') return '?';

    const words = name.trim().split(/\s+/);
    let initials = '';

    for (const word of words.slice(0, 2)) {
      if (word.length > 0) {
        initials += word[0].toUpperCase();
      }
    }

    return initials || '?';
  }

  /**
   * Generate a consistent color from an identifier.
   * Uses CRC32-like hash for consistency with backend.
   */
  function getColorFromId(identifier: string | number | null | undefined): string {
    if (!identifier) return AVATAR_COLORS[0];

    const str = String(identifier);
    let hash = 0;

    for (let i = 0; i < str.length; i++) {
      const char = str.charCodeAt(i);
      hash = ((hash << 5) - hash) + char;
      hash = hash & hash; // Convert to 32bit integer
    }

    const index = Math.abs(hash) % AVATAR_COLORS.length;
    return AVATAR_COLORS[index];
  }

  /**
   * Get the best available avatar URL from an entity.
   */
  function getAvatarUrl(entity: AvatarEntity): string | null {
    if (!entity) return null;

    // Check common avatar URL properties
    const e = entity as Record<string, unknown>;

    // Priority: avatar_url > avatar > null
    if (typeof e.avatar_url === 'string' && e.avatar_url) {
      return e.avatar_url;
    }

    if (typeof e.avatar === 'string' && e.avatar) {
      return e.avatar;
    }

    return null;
  }

  /**
   * Resolve full avatar data for any entity type.
   */
  function resolve(entity: AvatarEntity): AvatarData {
    if (!entity) {
      return {
        url: null,
        fallback: DEFAULT_FALLBACK,
        initials: '?',
        color: getColorFromId('default'),
      };
    }

    const e = entity as Record<string, unknown>;
    const name = (e.name as string) || 'Unknown';
    const identifier = (e.public_id as string) || (e.id as string | number) || name;

    return {
      url: getAvatarUrl(entity),
      fallback: DEFAULT_FALLBACK,
      initials: getInitials(name),
      color: getColorFromId(identifier),
    };
  }

  /**
   * Resolve avatar for a chat entity.
   *
   * - DM chats: Returns other participant's avatar
   * - Group chats: Returns chat avatar or group fallback
   */
  function resolveChatAvatar(
    chat: Chat | null,
    currentUserPublicId: string | null = null
  ): AvatarData {
    if (!chat) {
      return {
        url: null,
        fallback: GROUP_FALLBACK,
        initials: '?',
        color: getColorFromId('default'),
      };
    }

    // If chat has explicit avatar_url, use it
    if (chat.avatar_url) {
      return {
        url: chat.avatar_url,
        fallback: GROUP_FALLBACK,
        initials: getInitials(chat.name || 'Group'),
        color: getColorFromId(chat.public_id),
      };
    }

    // For DM chats, use other participant's avatar
    if (chat.type === 'dm' && chat.participants?.length) {
      const other = currentUserPublicId
        ? chat.participants.find(p => p.public_id !== currentUserPublicId)
        : chat.participants[0];

      if (other) {
        return resolve(other);
      }
    }

    // Group/team chat fallback
    return {
      url: null,
      fallback: GROUP_FALLBACK,
      initials: getInitials(chat.name || 'Group'),
      color: getColorFromId(chat.public_id),
    };
  }

  /**
   * Get the effective URL (url or fallback).
   */
  function getEffectiveUrl(data: AvatarData): string {
    return data.url || data.fallback;
  }

  return {
    resolve,
    resolveChatAvatar,
    getAvatarUrl,
    getInitials,
    getColorFromId,
    getEffectiveUrl,
    DEFAULT_FALLBACK,
    GROUP_FALLBACK,
    AVATAR_COLORS,
  };
}
