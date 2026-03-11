<?php

namespace App\Http\Resources;

use App\Models\MeetingParticipant;
use App\Support\MeetingParticipantSession;
use App\Services\Chat\PresenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MeetingResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $authUserId = Auth::id();
        $queryParticipantId = $request->query('participant');
        $queryParticipantId = is_string($queryParticipantId) && trim($queryParticipantId) !== ''
            ? trim($queryParticipantId)
            : null;

        $sessionParticipantId = MeetingParticipantSession::resolveGuestParticipantId($request, $this->resource);

        // During room bootstrap, prefer the explicit participant token from URL.
        // This avoids stale cookie/session participant IDs hiding the newly joined participant.
        if ($queryParticipantId && (! $sessionParticipantId || strcasecmp($sessionParticipantId, $queryParticipantId) !== 0)) {
            $sessionParticipantId = $queryParticipantId;
        }
        $participantsLoaded = $this->relationLoaded('participants');
        $participants = $participantsLoaded ? $this->participants : collect();

        $isHost = $this->user_id === $authUserId;

        $isAdmittedUser = false;
        $isWaitingUser = false;
        $isAdmittedSession = false;
        $isWaitingSession = false;

        if ($participantsLoaded) {
            if ($authUserId) {
                $isAdmittedUser = $participants->contains(fn ($participant) => $participant->user_id === $authUserId && $participant->status === 'admitted');
                $isWaitingUser = $participants->contains(fn ($participant) => $participant->user_id === $authUserId && $participant->status === 'waiting');
            }

            if ($sessionParticipantId) {
                $isAdmittedSession = $participants->contains(fn ($participant) => strcasecmp((string) $participant->public_id, $sessionParticipantId) === 0 && $participant->status === 'admitted');
                $isWaitingSession = $participants->contains(fn ($participant) => strcasecmp((string) $participant->public_id, $sessionParticipantId) === 0 && $participant->status === 'waiting');
            }
        } elseif ($authUserId || $sessionParticipantId) {
            $visibilityParticipants = MeetingParticipant::query()
                ->where('meeting_id', $this->id)
                ->whereIn('status', ['admitted', 'waiting'])
                ->where(function ($query) use ($authUserId, $sessionParticipantId) {
                    if ($authUserId) {
                        $query->orWhere('user_id', $authUserId);
                    }

                    if ($sessionParticipantId) {
                        $query->orWhereRaw('LOWER(public_id) = ?', [strtolower($sessionParticipantId)]);
                    }
                })
                ->get(['user_id', 'public_id', 'status']);

            if ($authUserId) {
                $isAdmittedUser = $visibilityParticipants->contains(fn ($participant) => $participant->user_id === $authUserId && $participant->status === 'admitted');
                $isWaitingUser = $visibilityParticipants->contains(fn ($participant) => $participant->user_id === $authUserId && $participant->status === 'waiting');
            }

            if ($sessionParticipantId) {
                $isAdmittedSession = $visibilityParticipants->contains(fn ($participant) => strcasecmp((string) $participant->public_id, $sessionParticipantId) === 0 && $participant->status === 'admitted');
                $isWaitingSession = $visibilityParticipants->contains(fn ($participant) => strcasecmp((string) $participant->public_id, $sessionParticipantId) === 0 && $participant->status === 'waiting');
            }
        }

        $canViewParticipants = $isHost || $isAdmittedUser || $isAdmittedSession || $isWaitingUser || $isWaitingSession;
        $isWaitingViewer = ! $isHost && ($isWaitingUser || $isWaitingSession);

        if (! $participantsLoaded && $canViewParticipants) {
            $participants = $this->participants;
        }

        if ($isWaitingViewer) {
            $participants = $participants
                ->filter(function ($participant) use ($authUserId, $sessionParticipantId) {
                    if ($authUserId && $participant->user_id === $authUserId) {
                        return true;
                    }

                    return ! empty($sessionParticipantId) && strcasecmp((string) $participant->public_id, $sessionParticipantId) === 0;
                })
                ->values();
        }

        if (app()->environment('local')) {
            Log::channel('videocall')->debug('[MEETING_RESOURCE] Visibility decision', [
                'meeting' => $this->public_id,
                'path' => $request->path(),
                'auth_user_id' => $authUserId,
                'query_participant_id' => $queryParticipantId,
                'resolved_participant_id' => $sessionParticipantId,
                'participants_loaded' => $participantsLoaded,
                'is_host' => $isHost,
                'is_admitted_user' => $isAdmittedUser,
                'is_waiting_user' => $isWaitingUser,
                'is_admitted_session' => $isAdmittedSession,
                'is_waiting_session' => $isWaitingSession,
                'can_view_participants' => $canViewParticipants,
                'is_waiting_viewer' => $isWaitingViewer,
                'participant_ids_source' => $participantsLoaded
                    ? $this->participants->pluck('public_id')->values()->all()
                    : [],
                'participant_ids_returned' => $participants->pluck('public_id')->values()->all(),
            ]);
        }

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
            'password' => $this->when($isHost, $this->revealPassword()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'host' => $this->host ? [
                'id' => $this->host->id,
                'public_id' => $this->host->public_id,
                'name' => $this->host->name,
                'avatar_url' => $this->host->getAvatarData()->getUrl(),
                'color' => $this->host->getAvatarData()->color,
            ] : null,
            'participants' => $this->when($canViewParticipants, function () use ($participants) {
                return MeetingParticipantResource::collection($participants);
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
