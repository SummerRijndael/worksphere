<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class MeetingResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'title' => $this->title,
            'description' => $this->description,
            'start_time' => $this->start_time?->toIso8601String(),
            'end_time' => $this->end_time?->toIso8601String(),
            'status' => $this->status,
            'settings' => $this->settings,
            'has_password' => !empty($this->password),
            'password' => $this->when(($this->user_id === Auth::id()), $this->password),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'host' => $this->whenLoaded('host', function () {
                return [
                    'id' => $this->host->id,
                    'public_id' => $this->host->public_id,
                    'name' => $this->host->name,
                    'avatar_url' => $this->host->avatar_url,
                ];
            }),
            'participants' => MeetingParticipantResource::collection($this->whenLoaded('participants')),
        ];
    }
}
