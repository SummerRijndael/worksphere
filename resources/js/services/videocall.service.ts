import { BaseService } from './base.service';

export class VideoCallService extends BaseService {
  /**
   * Fetch TURN/STUN ICE server credentials for a chat.
   */
  async getTurnCredentials(chatId: string): Promise<{ ice_servers: RTCIceServer[] }> {
    const response = await this.api.get(`/api/chat/${chatId}/call/turn-credentials`);
    return response.data;
  }

  /**
   * Initiate a call (notifies the other participant via broadcast).
   */
  async initiateCall(chatId: string, callType: 'video' | 'audio'): Promise<{ call_id: string; chat_id: string }> {
    const response = await this.api.post(`/api/chat/${chatId}/call/initiate`, {
      call_type: callType,
    });
    return response.data;
  }

  /**
   * Join an existing call.
   */
  async joinCall(chatId: string, callId: string): Promise<{ status: string; participants: any[]; mode: 'mesh' | 'sfu'; app_id?: string }> {
    const response = await this.api.post(`/api/chat/${chatId}/call/join`, {
      call_id: callId,
    });
    return response.data;
  }

  /**
   * Check for active call in a chat.
   */
  async getActiveCall(chatId: string): Promise<{ active: boolean; call_id?: string; type?: 'video'|'audio'; participants?: any[]; app_id?: string }> {
    const response = await this.api.get(`/api/chat/${chatId}/call/active`);
    return response.data;
  }

  /**
   * SFU Proxy: Create New Session
   */
  async sfuSessionNew(chatId: string, offer: string, tracks?: any[]): Promise<any> {
    const body: any = { sessionDescription: { type: 'offer', sdp: offer } };
    if (tracks) body.tracks = tracks;
    const response = await this.api.post(`/api/chat/${chatId}/call/sfu/sessions/new`, body);
    return response.data;
  }

  /**
   * SFU Proxy: Add Tracks
   */
  async sfuSessionTracks(chatId: string, sessionId: string, tracks: any[], offer?: string): Promise<any> {
    const body: any = { tracks };
    if (offer) {
      body.sessionDescription = { type: 'offer', sdp: offer };
    }
    const response = await this.api.post(`/api/chat/${chatId}/call/sfu/sessions/${sessionId}/tracks/new`, body);
    return response.data;
  }

  async sfuSessionRenegotiate(chatId: string, sessionId: string, sdp: string | null | undefined, type: 'offer' | 'answer' | 'rollback' = 'offer', method: 'PUT' | 'POST' = 'PUT'): Promise<any> {
    const body: any = {};
    if (sdp || type !== 'rollback') {
      // Ensure sdp is at least an empty string to preserve the key in JSON
      body.sessionDescription = { type, sdp: sdp || '' };
    } else {
      // For rollback, Cloudflare requires sessionDescription wrapper.
      // We include an empty sdp string as some validators require the field to exist.
      body.sessionDescription = { type: 'rollback', sdp: '' };
    }

    const response = await this.api.request({
      url: `/api/chat/${chatId}/call/sfu/sessions/${sessionId}/renegotiate`,
      method,
      data: Object.keys(body).length > 0 ? body : undefined
    });
    return response.data;
  }

  /**
   * Get current participants in a call.
   */
  async getParticipants(chatId: string, callId: string): Promise<{ participants: any[] }> {
    const response = await this.api.get(`/api/chat/${chatId}/call/${callId}/participants`);
    return response.data;
  }

  /**
   * Send a WebRTC signal (offer/answer/ice-candidate).
   */
  async sendSignal(
    chatId: string,
    callId: string,
    signalType: 'offer' | 'answer' | 'ice-candidate' | 'signal',
    signalData: Record<string, unknown>,
    targetPublicId?: string,
  ): Promise<void> {
    await this.api.post(`/api/chat/${chatId}/call/signal`, {
      call_id: callId,
      signal_type: signalType,
      signal_data: signalData,
      target_public_id: targetPublicId,
    });
  }

  /**
   * End a call.
   */
  async endCall(
    chatId: string,
    callId: string,
    reason: 'hangup' | 'declined' | 'timeout' | 'failed' = 'hangup',
  ): Promise<void> {
    await this.api.post(`/api/chat/${chatId}/call/end`, {
      call_id: callId,
      reason,
    });
  }
}

export const videoCallService = new VideoCallService();
