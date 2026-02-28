<?php

use App\Helpers\ChannelAuthLogger;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{publicId}', ChannelAuthLogger::wrap('App.Models.User.{publicId}', function ($user, $publicId) {
    return (string) $user->public_id === (string) $publicId;
}));

// User's own presence channel - only they can subscribe
Broadcast::channel('presence.{publicId}', ChannelAuthLogger::wrap('presence.{publicId}', function ($user, $publicId) {
    return $user->public_id === $publicId;
}));

// Alias for User channel to match frontend 'user.{publicId}'
Broadcast::channel('user.{publicId}', ChannelAuthLogger::wrap('user.{publicId}', function ($user, $publicId) {
    return (string) $user->public_id === (string) $publicId;
}));

// Global online users channel - all authenticated users can join
// Uses 'online-users' name so with prefix it becomes 'presence-online-users'
Broadcast::channel('online-users', ChannelAuthLogger::wrap('online-users', function ($user) {
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
}));

// Ticket queue channel - for support staff to receive new ticket notifications
// IMPORTANT: This specific route MUST come before tickets.{ticketId} parameterized route
Broadcast::channel('tickets.queue', ChannelAuthLogger::wrap('tickets.queue', function ($user) {
    return $user->hasPermissionTo('tickets.view') || $user->hasPermissionTo('tickets.manage');
}));

// Ticket channel - users can subscribe if they can view the ticket
Broadcast::channel('tickets.{ticketId}', ChannelAuthLogger::wrap('tickets.{ticketId}', function ($user, $ticketId) {
    $ticket = \App\Models\Ticket::where('public_id', $ticketId)->first();

    if (! $ticket) {
        return false;
    }

    // Allow if user can view, is reporter, assignee, or follower
    return $user->can('view', $ticket)
        || $ticket->reporter_id === $user->id
        || $ticket->assigned_to === $user->id
        || $ticket->isFollowedBy($user);
}));

Broadcast::channel('dm.{chatPublicId}', ChannelAuthLogger::wrap('dm.{chatPublicId}', function ($user, $chatPublicId) {
    return \App\Models\Chat\Chat::where('public_id', (string) $chatPublicId)
        ->whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->exists();
}));

Broadcast::channel('group.{chatPublicId}', ChannelAuthLogger::wrap('group.{chatPublicId}', function ($user, $chatPublicId) {
    return \App\Models\Chat\Chat::where('public_id', (string) $chatPublicId)
        ->whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->exists();
}));

Broadcast::channel('email-account.{publicId}', ChannelAuthLogger::wrap('email-account.{publicId}', function ($user, $publicId) {
    return \App\Models\EmailAccount::where('public_id', $publicId)
        ->where('user_id', $user->id)
        ->exists();
}));

Broadcast::channel('personal-notes.{publicId}', ChannelAuthLogger::wrap('personal-notes.{publicId}', function ($user, $publicId) {
    return $user->public_id === $publicId;
}));

// Team Projects Channel - Tiered Access
// Team Leads/SMEs/QAs (view) - See all projects
// Operators (view_assigned) - See only assigned projects (technically can see channel events, filtered by frontend)
Broadcast::channel('teams.{teamId}.projects', ChannelAuthLogger::wrap('teams.{teamId}.projects', function ($user, $teamId) {
    $team = \App\Models\Team::find($teamId);

    if (! $team) {
        return false;
    }

    $permissionService = app(\App\Services\PermissionService::class);

    // Must be a team member
    if (! $permissionService->isTeamMember($user, $team)) {
        return false;
    }

    // Check permissions
    return $permissionService->hasTeamPermission($user, $team, 'projects.view')
        || $permissionService->hasTeamPermission($user, $team, 'projects.view_assigned');
}));

// Project Tasks Channel - Tiered Access
// Team Leads/SMEs/QAs (view) - See all tasks
// Operators (view_assigned) - See only assigned tasks (technically can see channel events, filtered by frontend)
Broadcast::channel('projects.{projectId}.tasks', ChannelAuthLogger::wrap('projects.{projectId}.tasks', function ($user, $projectId) {
    $project = \App\Models\Project::find($projectId);

    if (! $project) {
        return false;
    }

    $permissionService = app(\App\Services\PermissionService::class);
    $team = $project->team;

    // Check permissions
    return $permissionService->hasTeamPermission($user, $team, 'tasks.view')
        || $permissionService->hasTeamPermission($user, $team, 'tasks.view_assigned');
}));

// Meeting Channel - Secure signaling and presence
// Allows both registered users and guest participants
Broadcast::channel('meeting.{meetingId}', ChannelAuthLogger::wrap('meeting.{meetingId}', function ($user, $meetingId) {
    $meeting = \App\Models\Meeting::where('public_id', $meetingId)->first();
    if (! $meeting) {
        return false;
    }

    // 1. If host: Always allow — but return their PARTICIPANT public_id, not user public_id
    if ($user && $meeting->user_id === $user->id) {
        $hostParticipant = \App\Models\MeetingParticipant::where('meeting_id', $meeting->id)
            ->where('user_id', $user->id)
            ->first();

        return [
            'public_id' => $hostParticipant ? $hostParticipant->public_id : $user->public_id,
            'name' => $user->name,
            'avatar' => $user->avatar_url,
            'role' => 'host',
            'status' => 'admitted',
        ];
    }

    // 2. Check participant record (works for both guests and registered users)
    // We check either the authenticated user's ID or the participant ID from the session/request
    $participantId = request()->header('X-Participant-ID') ?: (session('meeting_participant_id') ?: session('participant_id'));

    $participantQuery = \App\Models\MeetingParticipant::where('meeting_id', $meeting->id);

    if ($user) {
        $participantQuery->where('user_id', $user->id);
    } elseif ($participantId) {
        $participantQuery->where('public_id', $participantId);
    } else {
        return false;
    }

    $participant = $participantQuery->first();

    if ($participant) {
        return [
            'public_id' => $participant->public_id,
            'name' => $participant->user?->name ?: ($participant->metadata['guest_name'] ?? 'Guest'),
            'avatar' => $participant->user?->avatar_url,
            'role' => $participant->role,
            'status' => $participant->status,
        ];
    }

    return false;
}));
