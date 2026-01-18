<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class CalendarPolicy
{
    /**
     * Determine whether the user can view the calendar.
     */
    public function view(User $user, User $calendarOwner): bool
    {
        if ($user->id === $calendarOwner->id) {
            return true;
        }

        return $calendarOwner->calendarShares()
            ->where('shared_with_user_id', $user->id)
            ->exists();
    }

    /**
     * Determine whether the user can create events in the calendar (for now, only owner).
     */
    public function create(User $user, User $calendarOwner): bool
    {
        if ($user->id === $calendarOwner->id) {
            return true;
        }

        // Future: Check 'edit' permission if we allow creating on others' calendars
        return $calendarOwner->calendarShares()
            ->where('shared_with_user_id', $user->id)
            ->where('permission_level', 'edit')
            ->exists();
    }

    /**
     * Determine whether the user can update the event.
     */
    public function update(User $user, Event $event): bool
    {
        if ($user->id === $event->user_id) {
            return true;
        }

        // Check if the event owner has shared their calendar with 'edit' permission
        return $event->organizer->calendarShares()
            ->where('shared_with_user_id', $user->id)
            ->where('permission_level', 'edit')
            ->exists();
    }

    /**
     * Determine whether the user can delete the event.
     */
    public function delete(User $user, Event $event): bool
    {
        return $this->update($user, $event);
    }
}
