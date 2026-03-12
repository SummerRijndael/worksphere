import { BaseService } from "./base.service";

export interface Meeting {
    id: number;
    public_id: string;
    user_id: number;
    title: string;
    description: string | null;
    start_time: string;
    end_time: string | null;
    status: 'scheduled' | 'active' | 'ended';
    active_participant_count?: number;
    active_participant_ids?: string[];
    is_locked?: boolean;
    settings: {
        lobby_enabled: boolean;
        guest_access: boolean;
        require_host_or_cohost_present?: boolean;
        screen_share_host_cohost_only?: boolean;
        [key: string]: any;
    };
    has_password?: boolean;
    password?: string;
    plain_password?: string;
    host?: any;
    participants?: MeetingParticipant[];
    created_at: string;
    updated_at: string;
}

export interface MeetingParticipant {
    id: number;
    meeting_id: number;
    user_id: number | null;
    public_id: string;
    role: 'host' | 'participant';
    status: 'waiting' | 'admitted' | 'rejected' | 'left';
    metadata: any;
    user?: any;
    is_muted_by_host?: boolean;
    is_camera_disabled_by_host?: boolean;
}

class MeetingService extends BaseService {
    async listMeetings() {
        return this.get<Meeting[]>("/api/meetings");
    }

    async createMeeting(data: Partial<Meeting>) {
        const response = await this.api.post<Meeting>("/api/meetings", data);
        return (response.data as any)?.data || response.data;
    }

    async getMeeting(id: string, participantId?: string): Promise<any> {
        const response = await this.api.get(`/api/meetings/${id}`, {
            params: participantId ? { participant: participantId } : undefined,
        });
        // Support both resource payload styles:
        // 1) direct object { public_id, participants, ... }
        // 2) wrapped object { data: { public_id, participants, ... } }
        return (response.data as any)?.data ?? response.data;
    }

    async endMeeting(meetingId: string): Promise<any> {
        return this.post(`/api/meetings/${meetingId}/end`, {});
    }

    async admitParticipant(meetingId: string, participantId: string): Promise<any> {
        return this.post(`/api/meetings/${meetingId}/participants/${participantId}/admit`, {});
    }

    async rejectParticipant(meetingId: string, participantId: string): Promise<any> {
        return this.post(`/api/meetings/${meetingId}/participants/${participantId}/reject`, {});
    }

    async muteParticipant(meetingId: string, participantId: string): Promise<any> {
        return this.post(`/api/meetings/${meetingId}/participants/${participantId}/mute`, {});
    }

    async unmuteParticipant(meetingId: string, participantId: string): Promise<any> {
        return this.post(`/api/meetings/${meetingId}/participants/${participantId}/unmute`, {});
    }

    async disableCamera(meetingId: string, participantId: string): Promise<any> {
        return this.post(`/api/meetings/${meetingId}/participants/${participantId}/camera-off`, {});
    }

    async allowCamera(meetingId: string, participantId: string): Promise<any> {
        return this.post(`/api/meetings/${meetingId}/participants/${participantId}/camera-allow`, {});
    }

    async kickParticipant(meetingId: string, participantId: string): Promise<any> {
        return this.post(`/api/meetings/${meetingId}/participants/${participantId}/kick`, {});
    }

    async promoteParticipant(meetingId: string, participantId: string): Promise<any> {
        return this.post(`/api/meetings/${meetingId}/participants/${participantId}/promote`, {});
    }

    async demoteParticipant(meetingId: string, participantId: string): Promise<any> {
        return this.post(`/api/meetings/${meetingId}/participants/${participantId}/demote`, {});
    }

    async getMessages(
        meetingId: string,
        options?: {
            thread_root_id?: string | number;
            limit?: number;
            before?: string | number;
        },
    ): Promise<any[]> {
        const response = await this.api.get(`/api/meetings/${meetingId}/messages`, {
            params: {
                thread_root_id: options?.thread_root_id,
                limit: options?.limit,
                before: options?.before,
            },
        });
        return response.data.data || response.data;
    }

    async sendMessage(
        meetingId: string,
        participantId: string,
        body: string,
        options?: {
            temp_id?: string;
            reply_to?: string | number;
            metadata?: Record<string, any>;
        },
    ): Promise<any> {
        const response = await this.api.post(`/api/meetings/${meetingId}/messages`, {
            participant_public_id: participantId,
            body,
            temp_id: options?.temp_id,
            reply_to: options?.reply_to,
            metadata: options?.metadata,
        });
        return response.data.data || response.data;
    }

    async uploadMessage(
        meetingId: string,
        participantId: string,
        files: File[],
        options?: {
            body?: string;
            temp_id?: string;
            reply_to?: string | number;
            metadata?: Record<string, any>;
        },
    ): Promise<any> {
        const formData = new FormData();
        formData.append("participant_public_id", participantId);

        if (options?.body) {
            formData.append("body", options.body);
        }
        if (options?.temp_id) {
            formData.append("temp_id", options.temp_id);
        }
        if (options?.reply_to !== undefined && options?.reply_to !== null) {
            formData.append("reply_to", String(options.reply_to));
        }

        if (options?.metadata) {
            Object.entries(options.metadata).forEach(([key, value]) => {
                if (value === undefined || value === null) return;
                if (typeof value === "object") {
                    formData.append(`metadata[${key}]`, JSON.stringify(value));
                } else {
                    formData.append(`metadata[${key}]`, String(value));
                }
            });
        }

        files.forEach((file) => {
            formData.append("files[]", file);
        });

        const response = await this.api.post(
            `/api/meetings/${meetingId}/messages`,
            formData,
            {
                headers: {
                    "Content-Type": "multipart/form-data",
                },
            },
        );

        return response.data.data || response.data;
    }

    async pinMessage(meetingId: string, messageId: string | number): Promise<any> {
        const response = await this.api.post(`/api/meetings/${meetingId}/messages/${messageId}/pin`);
        return response.data.data || response.data;
    }

    async unpinMessage(meetingId: string, messageId: string | number): Promise<any> {
        const response = await this.api.delete(`/api/meetings/${meetingId}/messages/${messageId}/pin`);
        return response.data.data || response.data;
    }

    async clearPinnedMessages(meetingId: string): Promise<any[]> {
        const response = await this.api.delete(`/api/meetings/${meetingId}/messages/pins`);
        return response.data.data || response.data || [];
    }

    async editMessage(
        meetingId: string,
        messageId: string | number,
        body: string,
    ): Promise<any> {
        const response = await this.api.patch(`/api/meetings/${meetingId}/messages/${messageId}`, {
            body,
        });
        return response.data.data || response.data;
    }

    async deleteMessage(meetingId: string, messageId: string | number): Promise<any> {
        const response = await this.api.delete(`/api/meetings/${meetingId}/messages/${messageId}`);
        return response.data.data || response.data;
    }

    async toggleMessageReaction(
        meetingId: string,
        messageId: string | number,
        reaction: "like" | "laugh" | "hundred" | "sad" | "love" | "angry" | "scared" | "care",
    ): Promise<any> {
        const response = await this.api.post(
            `/api/meetings/${meetingId}/messages/${messageId}/reactions`,
            { reaction },
        );
        return response.data.data || response.data;
    }

    async getTurnCredentials(meetingId: string): Promise<{ ice_servers: RTCIceServer[] }> {
        const response = await this.api.get(`/api/meetings/${meetingId}/turn-credentials`);
        return response.data;
    }

    async joinMeeting(id: string, name?: string, password?: string, email?: string, is_companion?: boolean): Promise<{ meeting: Meeting; participant: MeetingParticipant }> {
        return this.post<{ meeting: Meeting; participant: MeetingParticipant }>(
            `/api/meetings/${id}/join`,
            { name, password, email, is_companion }
        );
    }

    async sendSignal(id: string, signalData: {
        signal_type: string;
        signal_data: any;
        sender_participant_public_id: string;
        target_participant_public_id?: string;
    }) {
        return this.post(`/api/meetings/${id}/signal`, signalData);
    }

    async updateMeeting(id: string, data: Partial<Meeting>) {
        const response = await this.api.patch<Meeting>(`/api/meetings/${id}`, data);
        return (response.data as any)?.data || response.data;
    }

    async deleteMeeting(id: string) {
        return this.delete(`/api/meetings/${id}`);
    }

    async resendInvites(id: string) {
        return this.post(`/api/meetings/${id}/resend-invites`, {});
    }

    async lockMeeting(id: string) {
        return this.post(`/api/meetings/${id}/lock`, {});
    }

    async unlockMeeting(id: string) {
        return this.post(`/api/meetings/${id}/unlock`, {});
    }

    async renewLock(id: string) {
        return this.post(`/api/meetings/${id}/lock/renew`, {});
    }

    // --- Breakout Rooms ---

    async createBreakoutSession(
        meetingId: string,
        data: { rooms: any[]; duration_minutes: number | null },
    ) {
        return this.post(`/api/meetings/${meetingId}/breakout-sessions`, data);
    }

    async endBreakoutSession(meetingId: string) {
        return this.delete(`/api/meetings/${meetingId}/breakout-sessions`);
    }

    async joinBreakoutRoom(meetingId: string, roomId: string | null) {
        const normalizedRoomId = roomId === null ? "main" : String(roomId);
        return this.post(
            `/api/meetings/${meetingId}/breakout-rooms/${normalizedRoomId}/join`,
            {},
        );
    }

    async requestHostHelp(meetingId: string, roomId: string) {
        return this.post(`/api/meetings/${meetingId}/breakout-rooms/${roomId}/help`, {});
    }

    async moveParticipant(meetingId: string, participantPublicId: string, targetRoomId: string | null) {
        return this.post(`/api/meetings/${meetingId}/breakout-participant-move`, {
            participant_public_id: participantPublicId,
            target_room_id: targetRoomId
        });
    }

    async updateBreakoutTimer(meetingId: string, additionalMinutes: number) {
        return this.post(`/api/meetings/${meetingId}/breakout-timer-update`, {
            additional_minutes: additionalMinutes
        });
    }

    async notifyBreakoutActivity(meetingId: string, message: string, targetRoomId?: string | null) {
        return this.post(`/api/meetings/${meetingId}/breakout-activity-notify`, {
            message,
            target_room_id: targetRoomId
        });
    }

    // --- SFU Protocol endpoints ---

    async sfuSessionNew(id: string, offer: string, tracks?: any[]): Promise<any> {
        const body: any = { sessionDescription: { type: 'offer', sdp: offer } };
        if (tracks) body.tracks = tracks;
        // Uses the raw api client to bypass the BaseService data extractor which might
        // mutate the exact Cloudflare response syntax needed by the WebRTC agent.
        const response = await this.api.post(`/api/meetings/${id}/sfu/sessions/new`, body);
        return response.data;
    }

    async sfuSessionTracks(id: string, sessionId: string, tracks: any[], offer?: string): Promise<any> {
        const body: any = { tracks };
        if (offer) {
            body.sessionDescription = { type: 'offer', sdp: offer };
        }
        const response = await this.api.post(`/api/meetings/${id}/sfu/sessions/${sessionId}/tracks/new`, body);
        return response.data;
    }

    async sfuTracksUpdate(id: string, sessionId: string, tracks: any[]): Promise<any> {
        const response = await this.api.put(`/api/meetings/${id}/sfu/sessions/${sessionId}/tracks/update`, { tracks });
        return response.data;
    }

    async sfuTracksClose(id: string, sessionId: string, tracks: any[], force = false): Promise<any> {
        const response = await this.api.put(`/api/meetings/${id}/sfu/sessions/${sessionId}/tracks/close`, {
            tracks,
            force,
        });
        return response.data;
    }

    async sfuSessionRenegotiate(id: string, sessionId: string, sdp: string | null | undefined, type: 'offer' | 'answer' | 'rollback' = 'offer', method: 'PUT' | 'POST' = 'PUT'): Promise<any> {
        const body: any = {};
        if (sdp || type !== 'rollback') {
            body.sessionDescription = { type, sdp: sdp || '' };
        } else {
            body.sessionDescription = { type: 'rollback', sdp: '' };
        }

        const response = await this.api.request({
            url: `/api/meetings/${id}/sfu/sessions/${sessionId}/renegotiate`,
            method,
            data: Object.keys(body).length > 0 ? body : undefined
        });
        return response.data;
    }

    // ─── PRO Recording (Cloudflare RealtimeKit) ───────────────────────────────

    /**
     * Get a RealtimeKit auth_token for the frontend SDK.
     * Also creates the RealtimeKit meeting on the backend if it doesn't exist yet.
     * Only called when MEETING_RECORDING_ENABLED=true.
     */
    async getRecordingToken(meetingId: string): Promise<{ cf_meeting_id: string; auth_token: string }> {
        const response = await this.api.post(`/api/meetings/${meetingId}/recording/token`);
        return response.data;
    }

    async startRecording(meetingId: string): Promise<{ recording_id: string; cf_recording_id: string; status: string }> {
        const response = await this.api.post(`/api/meetings/${meetingId}/recording/start`);
        return response.data;
    }

    async stopRecording(meetingId: string): Promise<{ recording_id: string; status: string }> {
        const response = await this.api.post(`/api/meetings/${meetingId}/recording/stop`);
        return response.data;
    }

    async forceStopRecording(meetingId: string): Promise<{ status: string }> {
        const response = await this.api.post(`/api/meetings/${meetingId}/recording/force-stop`);
        return response.data;
    }

    async syncRecording(meetingId: string, recordingId: string): Promise<{ id: string; status: string }> {
        const response = await this.api.post(`/api/meetings/${meetingId}/recordings/${recordingId}/sync`);
        return response.data;
    }

    async listRecordings(meetingId: string): Promise<any[]> {
        const response = await this.api.get(`/api/meetings/${meetingId}/recordings`);
        return response.data?.data ?? [];
    }

    // Helper wrappers since BaseService doesn't provide them directly
    protected async get<T>(url: string, params?: any): Promise<T> {
        return this.api.get<ApiResponse<T>>(url, { params }).then(this.extractData);
    }

    protected async post<T>(url: string, data?: any): Promise<T> {
        return this.api.post<ApiResponse<T>>(url, data).then(this.extractData);
    }

    protected async patch<T>(url: string, data?: any): Promise<T> {
        return this.api.patch<ApiResponse<T>>(url, data).then(this.extractData);
    }

    protected async delete<T>(url: string): Promise<T> {
        return this.api.delete<ApiResponse<T>>(url).then(this.extractData);
    }
}

import type { ApiResponse } from "@/types";

export const meetingService = new MeetingService();
