// Chat TypeScript Types

export type ChatType = "dm" | "group" | "team";

export type ParticipantRole = "owner" | "admin" | "member";

export type InviteStatus = "pending" | "accepted" | "declined" | "expired";

export type InviteType = "dm" | "group";

export interface ChatParticipant {
    id: string; // Changed to string to match backend public_id mapping
    name: string;
    public_id: string;
    avatar: string | null;
    role: ParticipantRole | null;
    is_online: boolean;
    presence_status: "online" | "away" | "busy" | "offline";
}

export interface PendingFile {
    file: File;
    name: string;
    size: number;
    isImage: boolean;
    url?: string;
}

export interface LastMessage {
    id: string; // Message ID as string
    user_name: string | null;
    content: string | null;
    created_at: string;
    has_media: boolean;
}

export interface Chat {
    id: string; // Chat Public ID maps to this
    public_id: string;
    name: string | null;
    type: ChatType;
    avatar_url: string | null;
    created_at: string;
    updated_at: string;
    participants: ChatParticipant[];
    last_message: LastMessage | null;
    team_owner_id: number | null;
    unread_count?: number;
    marked_for_deletion_at?: string | null;
}

export interface MessageAttachment {
    id: number;
    name: string;
    size: number;
    mime_type: string;
    is_image: boolean;
    url: string;
    download_url: string;
    thumb_url: string | null;
}

export interface MessageReply {
    id: string;
    user_public_id: string | null;
    user_name: string | null;
    content: string;
    has_media: boolean;
}

export interface Message {
    id: string;
    public_id?: string;
    type: "user" | "system";
    user_public_id: string | null;
    user_name: string;
    user_avatar: string | null;
    content: string;
    created_at: string;
    is_seen: boolean;
    seen: boolean;
    seen_at: string | null;
    reply_to: MessageReply | null;
    attachments: MessageAttachment[];
    metadata?: Record<string, any> | null;
    // Optimistic UI state
    pending?: boolean;
    failed?: boolean;
    tempId?: string;
}

export interface ChatInvite {
    id: string; // Public ID
    public_id?: string;
    inviter_name: string;
    avatar_url: string | null;
    sent_at: string;
    type: InviteType;
    chat_name: string | null;
}

export interface DiscoverablePerson {
    id: string; // Changed to string
    name: string;
    email: string;
    public_id: string;
    avatar: string | null;
    is_online: boolean;
    presence_status: "online" | "away" | "busy" | "offline" | "unknown";
}

// API Request Types
export interface SendMessageRequest {
    content: string;
    reply_to?: string;
}

export interface UploadMessageRequest {
    content?: string;
    reply_to?: string;
    files: File[];
}

export interface CreateGroupRequest {
    name?: string;
}

export interface RenameGroupRequest {
    name: string;
}

export interface AddMemberRequest {
    user_id: number;
}

export interface EnsureDmRequest {
    user_id: number;
}

export interface SendInviteRequest {
    invitee_id: number;
}

// API Response Types
export interface ChatListResponse {
    data: Chat[];
}

export interface ChatResponse {
    data: Chat;
}

export interface MessagesResponse {
    data: Message[];
    has_more: boolean;
}

export interface SendMessageResponse {
    data: Message;
}

export interface InvitesResponse {
    data: ChatInvite[];
}

export interface PeopleResponse {
    data: DiscoverablePerson[];
}

export interface EnsureDmResponse {
    status: "chat_exists" | "invite_required";
    chat_id?: number;
    chat_public_id?: string;
    data?: Chat;
    message?: string;
}

export interface SendInviteResponse {
    status: "invite_sent" | "chat_exists" | "invite_pending";
    invite_id?: number;
    chat_id?: number;
    message?: string;
}

export interface AcceptInviteResponse {
    status: "ok";
    chat_id: number;
    chat_public_id?: string;
}

export interface MediaItem {
    id: number;
    name: string;
    file_name: string; // Alias used by some components
    size: number;
    size_bytes: number; // Alias for size in bytes
    size_human: string;
    mime_type: string;
    is_image: boolean;
    created_at_human: string;
    url: string;
    download_url: string;
    thumb_url: string;
}

export interface MediaListResponse {
    data: MediaItem[];
    has_more: boolean;
}

// Alias for backwards compatibility
export type ChatMedia = MediaItem;

