<?php

namespace App\Http\Resources;

use App\Models\MeetingParticipant;
use App\Services\Chat\PresenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class MeetingResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'host_public_id' => $this->host ? $this->host->public_id : null,
            'title' => $this->title,
            'description' => $this->description,
            'start_time' => $this->start_time?->toIso8601String(),
            'end_time' => $this->end_time?->toIso8601String(),
            'status' => $this->status,
            'is_locked' => Cache::has("meeting:lock:{$this->public_id}"),
            'settings' => $this->settings,
            'has_password' => ! empty($this->password),
            'password' => $this->when(($this->user_id === Auth::id()), $this->password),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'host' => $this->host ? [
                'id' => $this->host->id,
                'public_id' => $this->host->public_id,
                'name' => $this->host->name,
                'avatar_url' => $this->host->getAvatarData()->getUrl(),
                'color' => $this->host->getAvatarData()->color,
            ] : null,
            'participants' => $this->when(($this->user_id === Auth::id() || MeetingParticipant::where('meeting_id', $this->id)->where('user_id', Auth::id())->where('status', 'admitted')->exists() || (session('meeting_participant_id') && MeetingParticipant::where('meeting_id', $this->id)->where('public_id', session('meeting_participant_id'))->where('status', 'admitted')->exists())), function () {
                return MeetingParticipantResource::collection($this->whenLoaded('participants'));
            }),
            'active_participant_count' => count(app(PresenceService::class)->getActiveMeetingParticipantIds($this->public_id)),
            'active_participant_ids' => app(PresenceService::class)->getActiveMeetingParticipantIds($this->public_id),
            'active_breakout_session' => $this->activeBreakoutSession,
            // PRO recording: true when MEETING_RECORDING_ENABLED=true (dev toggle).
            // Replace with subscription/billing check when real pro users exist.
            'recording_enabled' => config('services.cloudflare_realtime.recording_enabled', false),
        ];
    }
}
