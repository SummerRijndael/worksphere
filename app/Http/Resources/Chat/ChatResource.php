<?php

namespace App\Http\Resources\Chat;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class ChatResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $userId = Auth::id();
        $participants = $this->relationLoaded('participants') ? $this->participants : collect();
        
        $participant = $participants->firstWhere('id', $userId);
        $otherParticipant = ($this->type === 'dm' || $this->type === 'direct')
            ? $participants->firstWhere('id', '!=', $userId) 
            : null;

        $lastMessage = $this->whenLoaded('messages', function() {
            return $this->messages->first();
        }, $this->whenLoaded('latestVisibleMessage', function() {
            return $this->latestVisibleMessage;
        }, $this->lastMessage));

        $pivot = $participant ? $participant->pivot : null;
        $unreadCount = $pivot ? (int) ($pivot->unread_count ?? 0) : 0;
        
        return [
            'id' => $this->public_id,
            'public_id' => $this->public_id,
            'name' => ($this->type === 'dm' || $this->type === 'direct')
                ? ($otherParticipant->name ?? 'Deleted User') 
                : ($this->name ?? 'Untitled Group'),
            'avatar' => ($this->type === 'dm' || $this->type === 'direct')
                ? ($otherParticipant->avatar_url ?? null) 
                : ($this->avatar_url ?? null),
            'avatar_url' => ($this->type === 'dm' || $this->type === 'direct')
                ? ($otherParticipant->avatar_url ?? null) 
                : ($this->avatar_url ?? null),
            'type' => $this->type === 'direct' ? 'dm' : $this->type,
            'metadata' => empty($this->metadata) ? null : $this->metadata,
            'is_online' => ($this->type === 'dm' || $this->type === 'direct') && 
                ($otherParticipant ? app(\App\Services\Chat\PresenceService::class)->presenceStatus($otherParticipant->id) === 'online' : false),
            'last_message' => $lastMessage 
                ? [
                    'id' => $lastMessage->public_id,
                    'user_name' => $lastMessage->user->name ?? ($lastMessage->type === 'system' ? 'System' : 'Deactivated User'),
                    'content' => $lastMessage->content,
                    'created_at' => $lastMessage->created_at->toIso8601String(),
                    'has_media' => $lastMessage->relationLoaded('media') ? $lastMessage->media->isNotEmpty() : false,
                    'preview' => $lastMessage->preview,
                ] 
                : null,
            'unread_count' => (int) ($this->unread_count ?? 0),
            'team_owner_id' => $this->team_owner_id ?? null,
            'marked_for_deletion_at' => $this->marked_for_deletion_at?->toIso8601String(),
            'participant_role' => $pivot->role ?? 'member',
            'participants' => ChatParticipantResource::collection($this->relationLoaded('participants') ? $this->participants : collect()),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
