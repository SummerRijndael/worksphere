import { BaseService } from "./base.service";

export interface Meeting {
    id: number;
    public_id: string;
    user_id: number;
    title: string;
    description: string | null;
    start_time: string;
    end_time: string | null;
    status: 'scheduled' | 'live' | 'ended';
    settings: {
        lobby_enabled: boolean;
        guest_access: boolean;
        [key: string]: any;
    };
    has_password?: boolean;
    password?: string;
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
        return response.data;
    }

    async getMeeting(id: string): Promise<{ meeting: Meeting; participants: MeetingParticipant[] }> {
        const response = await this.api.get(`/api/meetings/${id}`);
        return response.data;
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

    async getMessages(meetingId: string): Promise<any[]> {
        const response = await this.api.get(`/api/meetings/${meetingId}/messages`);
        return response.data.data || response.data;
    }

    async sendMessage(meetingId: string, participantId: string, body: string): Promise<any> {
        const response = await this.api.post(`/api/meetings/${meetingId}/messages`, {
            participant_public_id: participantId,
            body
        });
        return response.data.data || response.data;
    }

    async getTurnCredentials(meetingId: string): Promise<{ ice_servers: RTCIceServer[] }> {
        const response = await this.api.get(`/api/meetings/${meetingId}/turn-credentials`);
        return response.data;
    }

    async joinMeeting(id: string, name?: string, password?: string, email?: string): Promise<{ meeting: Meeting; participant: MeetingParticipant }> {
        const response = await this.post<{ meeting: Meeting; participant: MeetingParticipant }>(
            `/api/meetings/${id}/join`,
            { name, password, email }
        );

        if (response?.participant?.public_id) {
            localStorage.setItem(`worksphere_meeting_token_${id}`, response.participant.public_id);
            if (password) {
                localStorage.setItem(`worksphere_meeting_pwd_${id}`, password);
            }
        }
        
        return response;
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
        return this.patch<Meeting>(`/api/meetings/${id}`, data);
    }

    async deleteMeeting(id: string) {
        return this.delete(`/api/meetings/${id}`);
    }

    async lockMeeting(id: string) {
        return this.post(`/api/meetings/${id}/lock`, {});
    }

    async unlockMeeting(id: string) {
        return this.post(`/api/meetings/${id}/unlock`, {});
    }

    // --- Breakout Rooms ---

    async createBreakoutSession(meetingId: string, data: { rooms: any[], duration_minutes: number }) {
        return this.post(`/api/meetings/${meetingId}/breakout-sessions`, data);
    }

    async endBreakoutSession(meetingId: string) {
        return this.delete(`/api/meetings/${meetingId}/breakout-sessions`);
    }

    async joinBreakoutRoom(meetingId: string, roomId: string) {
        return this.post(`/api/meetings/${meetingId}/breakout-rooms/${roomId}/join`, {});
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
