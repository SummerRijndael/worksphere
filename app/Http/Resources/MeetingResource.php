<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use App\Models\MeetingParticipant;

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
            'has_password' => !empty($this->password),
            'password' => $this->when(($this->user_id === Auth::id()), $this->password),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'host' => $this->whenLoaded('host', function () {
                return [
                    'public_id' => $this->host->public_id,
                    'name' => $this->host->name,
                    'avatar_url' => $this->host->avatar_url,
                ];
            }),
            'participants' => $this->when(($this->user_id === Auth::id() || MeetingParticipant::where('meeting_id', $this->id)->where('user_id', Auth::id())->where('status', 'admitted')->exists() || (session('meeting_participant_id') && MeetingParticipant::where('meeting_id', $this->id)->where('public_id', session('meeting_participant_id'))->where('status', 'admitted')->exists())), function () {
                return MeetingParticipantResource::collection($this->whenLoaded('participants'));
            }),
            'active_breakout_session' => $this->activeBreakoutSession,
        ];
    }
}
