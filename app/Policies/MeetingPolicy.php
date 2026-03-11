<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MeetingPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true; // Everyone can potentially view their own meeting list
    }

    public function view(?User $user, Meeting $meeting): bool
    {
        if ($user) {
            return true;
        }

        // Guests can only fetch lobby details when guest access is enabled.
        return (bool) ($meeting->settings['guest_access'] ?? false);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $meeting->user_id === $user->id;
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $meeting->user_id === $user->id;
    }

    /**
     * Determine if a user can admit/reject participants.
     */
    public function moderate(User $user, Meeting $meeting): bool
    {
        return $meeting->user_id === $user->id ||
               MeetingParticipant::where('meeting_id', $meeting->id)
                   ->where('user_id', $user->id)
                   ->whereIn('role', ['host', 'co-host'])
                   ->exists();
    }
}
