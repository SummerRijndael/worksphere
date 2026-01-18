<?php

namespace App\Http\Controllers;

use App\Mail\EventInvitation;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CalendarController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'users' => 'nullable|array',
            'users.*' => 'string|exists:users,public_id',
        ]);

        // Get IDs of users who have shared their calendar with the current user
        $sharedUserIds = DB::table('calendar_shares')
            ->where('shared_with_user_id', auth()->id())
            ->whereIn('permission_level', ['view', 'edit'])
            ->pluck('user_id')
            ->toArray();

        // Allowed IDs = Shared IDs + Own ID
        $allowedIds = array_merge($sharedUserIds, [auth()->id()]);

        $requestedPublicIds = $request->input('users', []);

        // Convert requested Public IDs to Internal IDs
        $requestedIds = [];
        if (! empty($requestedPublicIds)) {
            $requestedIds = User::whereIn('public_id', $requestedPublicIds)
                ->pluck('id')
                ->toArray();
        }

        // Ensure we only fetch for allowed users (security check)
        $targetIds = array_intersect($requestedIds, $allowedIds);

        // If no valid users selected, return empty (or default to self? Let's respect the empty selection if user unchecked everything)
        // But if the intersection is empty due to invalid IDs, returning empty is safe.

        // If no valid users selected, return empty (or default to self? Let's respect the empty selection if user unchecked everything)
        // But if the intersection is empty due to invalid IDs, returning empty is safe.

        $events = Event::query()
            ->where(function ($query) use ($targetIds) {
                // 1. Events owned by selected users
                $query->whereIn('user_id', $targetIds);

                // 2. If current user is selected, also include events they are attending
                if (in_array(auth()->id(), $targetIds)) {
                    $query->orWhereHas('attendees', function ($q) {
                        $q->where('user_id', auth()->id());
                    });
                }
            })
            ->between($request->start, $request->end)
            ->with(['attendees', 'organizer'])
            ->get();

        return \App\Http\Resources\EventResource::collection($events);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after_or_equal:start_time',
            'location' => 'nullable|string|max:255',
            'is_all_day' => 'boolean',
            'reminder_minutes_before' => 'nullable|integer|min:0',
            'attendees' => 'nullable|array',
            'attendees.*' => 'exists:users,public_id',
            'external_emails' => 'nullable|array',
            'external_emails.*' => 'email',
            'send_invite' => 'boolean',
        ]);

        return DB::transaction(function () use ($validated) {
            $event = Event::create([
                ...$validated,
                'user_id' => auth()->id(),
                'external_attendees' => $validated['external_emails'] ?? [],
            ]);

            $attendeeUsers = collect();
            if (! empty($validated['attendees'])) {
                $attendeeUsers = \App\Models\User::whereIn('public_id', $validated['attendees'])->get();
                $event->attendees()->attach($attendeeUsers->pluck('id'));
            }

            if ($validated['send_invite'] ?? false) {
                // Send to internal attendees
                foreach ($attendeeUsers as $user) {
                    if ($user->id !== auth()->id()) {
                        Mail::to($user)->queue(new EventInvitation($event));
                    }
                }

                // Send to external attendees
                if (! empty($validated['external_emails'])) {
                    foreach ($validated['external_emails'] as $email) {
                        Mail::to($email)->queue(new EventInvitation($event));
                    }
                }
            }

            return new \App\Http\Resources\EventResource($event->load(['attendees', 'organizer']));
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(Event $event)
    {
        $this->authorize('view', $event); // Need Policy or manual check

        return new \App\Http\Resources\EventResource($event->load(['attendees', 'organizer']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Event $event)
    {
        // Simple authorization check for MVP
        if ($event->user_id !== auth()->id()) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'start_time' => 'sometimes|date',
            'end_time' => 'sometimes|date|after_or_equal:start_time',
            'location' => 'nullable|string|max:255',
            'is_all_day' => 'boolean',
            'reminder_minutes_before' => 'nullable|integer|min:0',
            'attendees' => 'nullable|array',
            'attendees.*' => 'exists:users,public_id',
            'external_emails' => 'nullable|array',
            'external_emails.*' => 'email',
            'send_invite' => 'boolean',
        ]);

        return DB::transaction(function () use ($validated, $event, $request) {
            $event->update([
                ...$validated,
                'external_attendees' => $validated['external_emails'] ?? $event->external_attendees,
            ]);

            if (isset($validated['attendees'])) {
                $userIds = \App\Models\User::whereIn('public_id', $validated['attendees'])->pluck('id');
                $event->attendees()->sync($userIds);
            }

            if ($request->has('send_invite') && $request->boolean('send_invite')) {
                // Logic to send/update invitations
                // Send to internal attendees
                $attendees = $event->attendees;
                foreach ($attendees as $user) {
                    if ($user->id !== auth()->id()) {
                        Mail::to($user)->queue(new EventInvitation($event, true));
                    }
                }

                // Send to external attendees
                if (! empty($event->external_attendees)) {
                    foreach ($event->external_attendees as $email) {
                        Mail::to($email)->queue(new EventInvitation($event, true));
                    }
                }
            }

            return new \App\Http\Resources\EventResource($event->load(['attendees', 'organizer']));
        });
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Event $event)
    {
        if ($event->user_id !== auth()->id()) {
            abort(403, 'Unauthorized action.');
        }

        $event->delete();

        return response()->json(null, 204);
    }

    /**
     * Trigger a debug reminder for the latest event or a dummy event.
     * Development use only.
     */
    public function debugReminder(Request $request)
    {
        // Ideally restrict to dev/local, but relying on route middleware or simple check
        if (! app()->isLocal() && ! auth()->user()->isAdmin()) {
            abort(403, 'Debug only');
        }

        $event = Event::where('user_id', auth()->id())
            ->latest('start_time')
            ->first();

        if (! $event) {
            // Create a real event in the DB to avoid ModelNotFoundException during serialization
            $event = Event::create([
                'title' => 'Debug Event '.now()->format('H:i:s'),
                'description' => 'This is a simulated event for testing notifications.',
                'start_time' => now()->addMinutes(15),
                'end_time' => now()->addMinutes(45),
                'reminder_minutes_before' => 15,
                'user_id' => auth()->id(),
                'location' => 'Debug Console',
                'is_all_day' => false,
            ]);
        }

        auth()->user()->notify(new \App\Notifications\EventReminder($event));

        return response()->json([
            'message' => 'Reminder triggered',
            'event' => new \App\Http\Resources\EventResource($event->load(['organizer', 'attendees'])),
        ]);
    }

    /**
     * Send email invites to all attendees of an event.
     */
    public function invite(Event $event)
    {
        if ($event->user_id !== auth()->id()) {
            abort(403, 'Only the organizer can send invites.');
        }

        $event->load(['organizer', 'attendees']);

        if ($event->attendees->isEmpty()) {
            return response()->json(['message' => 'No attendees to invite.'], 422);
        }

        foreach ($event->attendees as $attendee) {
            $attendee->notify(new \App\Notifications\EventInviteNotification($event));
        }

        return response()->json([
            'message' => 'Invites sent to '.$event->attendees->count().' attendee(s).',
        ]);
    }

    /**
     * Download ICS file for an event.
     */
    public function downloadIcs(Event $event)
    {
        // User must be organizer or attendee
        $isOrganizer = $event->user_id === auth()->id();
        $isAttendee = $event->attendees()->where('user_id', auth()->id())->exists();

        if (! $isOrganizer && ! $isAttendee) {
            abort(403, 'Unauthorized access.');
        }

        $event->load(['organizer', 'attendees']);

        $exportService = app(\App\Services\CalendarExportService::class);
        $icsContent = $exportService->generateIcs($event);

        $filename = \Illuminate\Support\Str::slug($event->title).'.ics';

        return response($icsContent, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Export multiple events as ICS file.
     */
    /**
     * Export multiple events as ICS file based on date range.
     */
    public function export(Request $request)
    {
        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
        ]);

        $events = Event::query()
            ->where(function ($query) {
                $query->where('user_id', auth()->id())
                    ->orWhereHas('attendees', function ($q) {
                        $q->where('user_id', auth()->id());
                    });
            })
            ->between($request->start, $request->end)
            ->with(['attendees', 'organizer'])
            ->get();

        if ($events->isEmpty()) {
            return response()->json(['message' => 'No events to export in this date range.'], 422);
        }

        $exportService = app(\App\Services\CalendarExportService::class);
        $icsContent = $exportService->generateMultipleIcs($events);

        $filename = 'calendar-export-'.now()->format('Y-m-d').'.ics';

        return response($icsContent, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Bulk export specific selected events.
     */
    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'event_ids' => 'required|array',
            'event_ids.*' => 'string|exists:events,public_id',
        ]);

        $events = Event::query()
            ->whereIn('public_id', $validated['event_ids'])
            ->where(function ($query) {
                $query->where('user_id', auth()->id())
                    ->orWhereHas('attendees', function ($q) {
                        $q->where('user_id', auth()->id());
                    });
            })
            ->with(['attendees', 'organizer'])
            ->get();

        if ($events->isEmpty()) {
            return response()->json(['message' => 'No valid events found to export.'], 422);
        }

        $exportService = app(\App\Services\CalendarExportService::class);
        $icsContent = $exportService->generateMultipleIcs($events);

        $filename = 'calendar-selection-'.now()->format('Y-m-d-His').'.ics';

        return response($icsContent, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
