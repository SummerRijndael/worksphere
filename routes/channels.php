<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{publicId}', function ($user, $publicId) {
    return $user->public_id === $publicId;
});

// User's own presence channel - only they can subscribe
Broadcast::channel('presence.{publicId}', function ($user, $publicId) {
    return $user->public_id === $publicId;
});

// Alias for User channel to match frontend 'user.{publicId}'
Broadcast::channel('user.{publicId}', function ($user, $publicId) {
    return $user->public_id === $publicId;
});

// Global online users channel - all authenticated users can join
// Uses 'online-users' name so with prefix it becomes 'presence-online-users'
Broadcast::channel('online-users', function ($user) {
    // When joining, the user is Active by definition.
    // We strictly use their persistent preference (e.g. 'busy', 'online').
    // We ignore 'away' from cache because a page load/reconnect is an active event.
    $status = $user->presence_preference ?? 'online';

    // Return user data for presence channel member list
    return [

        'public_id' => $user->public_id,
        'name' => $user->name,
        'avatar' => $user->avatar_thumb_url,
        'status' => $status,
        'last_seen' => now()->timestamp,
    ];
});

// Ticket channel - users can subscribe if they can view the ticket
Broadcast::channel('tickets.{ticketId}', function ($user, $ticketId) {
    $ticket = \App\Models\Ticket::where('public_id', $ticketId)->first();

    if (! $ticket) {
        return false;
    }

    // Allow if user can view, is reporter, assignee, or follower
    return $user->can('view', $ticket)
        || $ticket->reporter_id === $user->id
        || $ticket->assigned_to === $user->id
        || $ticket->isFollowedBy($user);
});

Broadcast::channel('dm.{chatPublicId}', function ($user, $chatPublicId) {
    return \App\Models\Chat\Chat::where('public_id', $chatPublicId)
        ->whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->exists();
});

Broadcast::channel('group.{chatPublicId}', function ($user, $chatPublicId) {
    return \App\Models\Chat\Chat::where('public_id', $chatPublicId)
        ->whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->exists();
});

Broadcast::channel('email-account.{publicId}', function ($user, $publicId) {
    return \App\Models\EmailAccount::where('public_id', $publicId)
        ->where('user_id', $user->id)
        ->exists();
});

Broadcast::channel('personal-notes.{publicId}', function ($user, $publicId) {
    return $user->public_id === $publicId;
});
