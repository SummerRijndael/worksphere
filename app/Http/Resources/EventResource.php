<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'description' => $this->description,
            'start_time' => $this->start_time?->toIso8601String(),
            'end_time' => $this->end_time?->toIso8601String(),
            'location' => $this->location,
            'is_all_day' => $this->is_all_day,
            'reminder_minutes_before' => $this->reminder_minutes_before,
            'is_google_event' => $this->google_event_id !== null,
            'organizer' => $this->whenLoaded('organizer', fn () => $this->normalizeUser($this->organizer)),
            'attendees' => $this->whenLoaded('attendees', fn () => $this->attendees->map(fn ($u) => $this->normalizeUser($u))),
            'external_attendees' => $this->external_attendees ?? [],
        ];
    }

    protected function normalizeUser($user): array
    {
        return [
            'public_id' => $user->public_id,
            'name' => $user->name,
            'display_name' => $user->display_name,
            'initials' => $user->initials,
            'avatar' => $user->avatar_thumb_url,
        ];
    }
}
