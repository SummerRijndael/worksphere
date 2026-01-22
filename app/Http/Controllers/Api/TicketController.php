<?php

namespace App\Http\Controllers\Api;

use App\Contracts\TicketServiceContract;
use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\TicketCommentResource;
use App\Http\Resources\TicketInternalNoteResource;
use App\Http\Resources\TicketResource;
use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class TicketController extends Controller
{
    public function __construct(
        protected TicketServiceContract $ticketService,
        protected \App\Services\MediaService $mediaService,
        protected AuditService $auditService
    ) {}

    /**
     * Display a listing of tickets.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $filters = $request->only([
            'status', 'priority', 'type', 'assigned_to', 'reporter_id', 'team_id',
            'search', 'date_from', 'date_to', 'overdue', 'sla_breached',
            'sort_by', 'sort_direction', 'archived', 'exclude',
        ]);

        // Resolve 'me' and public IDs to internal IDs
        if (isset($filters['reporter_id'])) {
            if ($filters['reporter_id'] === 'me') {
                $filters['reporter_id'] = $user->id;
            } elseif (is_string($filters['reporter_id'])) {
                $filters['reporter_id'] = User::where('public_id', $filters['reporter_id'])->value('id') ?? $filters['reporter_id'];
            }
        }
        if (isset($filters['assigned_to'])) {
            if ($filters['assigned_to'] === 'me') {
                $filters['assigned_to'] = $user->id;
            } elseif ($filters['assigned_to'] !== 'unassigned' && is_string($filters['assigned_to'])) {
                $filters['assigned_to'] = User::where('public_id', $filters['assigned_to'])->value('id') ?? $filters['assigned_to'];
            }
        }

        // If user can only view own tickets, filter by user
        if (! $user->hasPermissionTo('tickets.view') && ! $user->hasRole('administrator')) {
            $filters['for_user'] = $user;
        }

        $tickets = $this->ticketService->list($filters, $request->integer('per_page', 15));

        return TicketResource::collection($tickets);
    }

    /**
     * Get ticket statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = [];

        // If user can only view own tickets, get stats for them only
        if (! $user->hasPermissionTo('tickets.view') && ! $user->hasRole('administrator')) {
            $filters['for_user'] = $user;
        }

        return response()->json($this->ticketService->getStats($filters));
    }

    /**
     * Search for linkable tickets (lightweight endpoint for Link to Master modal).
     * Returns only essential fields: id (public_id), ticket_number, title, status.
     */
    public function searchLinkable(Request $request): JsonResponse
    {
        $search = $request->get('search', '');
        $excludeId = $request->get('exclude'); // public_id to exclude (current ticket)

        $query = Ticket::query()
            ->active() // Only non-archived
            ->whereNull('parent_id'); // Only master-eligible tickets (not already children)

        // Exclude current ticket
        if ($excludeId) {
            $query->where('public_id', '!=', $excludeId);
        }

        // Search by ticket_number, title, or public_id
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('public_id', 'like', "%{$search}%");
            });
        }

        $tickets = $query->orderBy('created_at', 'desc')
            ->limit(50)
            ->get(['public_id', 'ticket_number', 'title', 'status']);

        return response()->json([
            'data' => $tickets->map(fn ($t) => [
                'id' => $t->public_id,
                'ticket_number' => $t->ticket_number,
                'title' => $t->title,
                'status' => $t->status->value,
                'label' => $t->ticket_number.' - '.$t->title,
            ]),
        ]);
    }

    /**
     * Store a newly created ticket.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|string|in:low,medium,high,critical',
            'type' => 'nullable|string|in:bug,feature,task,question,improvement',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'assigned_to' => 'nullable|exists:users,public_id',
            'team_id' => 'nullable|exists:teams,public_id',
            'sla_response_hours' => 'nullable|integer|min:1',
            'sla_resolution_hours' => 'nullable|integer|min:1',
            'due_date' => 'nullable|date|after:now',
        ]);

        // Convert public_id to internal id for assignee
        if (isset($validated['assigned_to'])) {
            $assignee = User::where('public_id', $validated['assigned_to'])->first();
            $validated['assigned_to'] = $assignee?->id;
        }

        // Convert public_id to internal id for team
        if (isset($validated['team_id'])) {
            $team = \App\Models\Team::where('public_id', $validated['team_id'])->first();
            $validated['team_id'] = $team?->id;
        }

        $ticket = $this->ticketService->create($validated, $request->user());

        return response()->json([
            'message' => 'Ticket created successfully.',
            'data' => new TicketResource($ticket),
        ], 201);
    }

    /**
     * Display the specified ticket.
     */
    public function show(Request $request, Ticket $ticket): TicketResource
    {
        $this->authorize('view', $ticket);

        // Track view (Debounced)
        $user = $request->user();
        $cacheKey = "ticket_viewed:{$ticket->id}:{$user->id}";

        if (! Cache::has($cacheKey)) {
            try {
                $this->auditService->log(
                    AuditAction::TicketViewed,
                    AuditCategory::DataModification,
                    $ticket,
                    $user,
                    null,
                    null,
                    ['source' => 'api']
                );
                // Cache for 1 hour to prevent flooding
                Cache::put($cacheKey, true, now()->addHour());
            } catch (\Exception $e) {
                // Silently fail logging to not disrupt the user experience
            }
        }

        $ticket->load(['reporter', 'assignee', 'team', 'comments.author', 'parent', 'children']);

        if ($request->user()->can('viewFollowers', $ticket)) {
            $ticket->load('followers');
        }

        // Load internal notes if user has permission
        if ($request->user()->can('viewInternalNotes', $ticket)) {
            $ticket->load(['internalNotes' => function ($query) {
                $query->with(['author', 'media']);
            }]);
        }

        return new TicketResource($ticket);
    }

    /**
     * Update the specified ticket.
     */
    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'sometimes|string|in:low,medium,high,critical',
            'type' => 'sometimes|string|in:bug,feature,task,question,improvement',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'assigned_to' => 'sometimes|nullable|exists:users,public_id',
            'reporter_id' => 'sometimes|nullable|exists:users,public_id',
            'sla_response_hours' => 'nullable|integer|min:1',
            'sla_resolution_hours' => 'nullable|integer|min:1',
            'due_date' => 'nullable|date',
            'reason' => 'required|string|min:3|max:500',
        ]);

        $reason = $validated['reason'];
        unset($validated['reason']);

        // Sanitize 'assigned_to' if user doesn't have permission to assign
        if (isset($validated['assigned_to']) && ! $request->user()->can('assign', $ticket)) {
            unset($validated['assigned_to']);
        }

        // Sanitize 'tags' if user doesn't have permission to update (edit tags)
        // Actually 'update' policy is for the whole ticket.
        // If user has 'tickets.update', they can edit everything.
        // If user is Owner (view_own/update_own) but NOT 'tickets.update' (staff), they shouldn't edit tags.
        // We'll check for the specific permission 'tickets.update' or admin.
        if (isset($validated['tags']) && ! ($request->user()->hasPermissionTo('tickets.update') || $request->user()->hasRole('administrator'))) {
            unset($validated['tags']);
        }

        // Convert public_id to internal id for assignee
        if (isset($validated['assigned_to'])) {
            $assignee = User::where('public_id', $validated['assigned_to'])->first();
            $validated['assigned_to'] = $assignee?->id;
        }

        // Convert public_id to internal id for reporter
        if (isset($validated['reporter_id'])) {
            $reporter = User::where('public_id', $validated['reporter_id'])->first();
            $validated['reporter_id'] = $reporter?->id;
        }

        $ticket = $this->ticketService->update($ticket, $validated, $reason);

        return response()->json([
            'message' => 'Ticket updated successfully.',
            'data' => new TicketResource($ticket),
        ]);
    }

    /**
     * Remove the specified ticket.
     */
    public function destroy(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('delete', $ticket);

        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:500',
        ]);

        $this->ticketService->delete($ticket, $validated['reason']);

        return response()->json([
            'message' => 'Ticket deleted successfully.',
        ]);
    }

    /**
     * Assign the ticket to a user.
     */
    public function assign(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('assign', $ticket);

        $validated = $request->validate([
            'assigned_to' => 'nullable|exists:users,public_id',
        ]);

        $assignee = null;
        if (isset($validated['assigned_to'])) {
            $assignee = User::where('public_id', $validated['assigned_to'])->first();
        }

        $ticket = $this->ticketService->assign($ticket, $assignee);

        return response()->json([
            'message' => $assignee ? 'Ticket assigned successfully.' : 'Ticket unassigned.',
            'data' => new TicketResource($ticket),
        ]);
    }

    /**
     * Change ticket status.
     */
    public function changeStatus(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('close', $ticket);

        $validated = $request->validate([
            'status' => 'required|string|in:open,in_progress,resolved,closed',
        ]);

        $status = TicketStatus::from($validated['status']);
        $ticket = $this->ticketService->changeStatus($ticket, $status);

        return response()->json([
            'message' => 'Ticket status updated successfully.',
            'data' => new TicketResource($ticket),
        ]);
    }

    /**
     * Follow a ticket.
     */
    public function follow(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('follow', $ticket);

        $this->ticketService->follow($ticket, $request->user());

        return response()->json([
            'message' => 'You are now following this ticket.',
        ]);
    }

    /**
     * Unfollow a ticket.
     */
    public function unfollow(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('follow', $ticket);

        $this->ticketService->unfollow($ticket, $request->user());

        return response()->json([
            'message' => 'You have unfollowed this ticket.',
        ]);
    }

    /**
     * List comments for a ticket.
     */
    public function comments(Ticket $ticket): AnonymousResourceCollection
    {
        $this->authorize('view', $ticket);

        $comments = $ticket->comments()->with('author')->get();

        return TicketCommentResource::collection($comments);
    }

    /**
     * Add a comment to a ticket.
     */
    public function addComment(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('addComment', $ticket);

        $validated = $request->validate([
            'content' => 'required|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240', // 10MB
        ]);

        $comment = $this->ticketService->addComment(
            $ticket,
            $request->user(),
            $validated['content'],
            $request->allFiles()['attachments'] ?? []
        );

        return response()->json([
            'message' => 'Comment added successfully.',
            'data' => new TicketCommentResource($comment),
        ], 201);
    }

    /**
     * List internal notes for a ticket.
     */
    public function internalNotes(Ticket $ticket): AnonymousResourceCollection
    {
        $this->authorize('viewInternalNotes', $ticket);

        $notes = $ticket->internalNotes()->with('author')->get();

        return TicketInternalNoteResource::collection($notes);
    }

    /**
     * Add an internal note to a ticket.
     */
    public function addInternalNote(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('addInternalNote', $ticket);

        $validated = $request->validate([
            'content' => 'required|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240', // 10MB
        ]);

        $note = $this->ticketService->addInternalNote(
            $ticket,
            $request->user(),
            $validated['content'],
            $request->allFiles()['attachments'] ?? []
        );

        return response()->json([
            'message' => 'Internal note added successfully.',
            'data' => new TicketInternalNoteResource($note),
        ], 201);
    }

    /**
     * Get activity/audit trail for a ticket.
     */
    public function activity(Ticket $ticket): AnonymousResourceCollection
    {
        $this->authorize('viewActivity', $ticket);

        $activities = AuditLog::where('auditable_type', Ticket::class)
            ->where('auditable_id', $ticket->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->paginate(20);

        return AuditLogResource::collection($activities);
    }

    /**
     * Upload an attachment to a ticket.
     */
    public function uploadAttachment(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);

        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
        ]);

        $media = $this->mediaService->attachFromRequest($ticket, 'file', 'attachments');

        return response()->json([
            'message' => 'Attachment uploaded successfully.',
            'data' => [
                'id' => $media->uuid,
                'name' => $media->file_name,
                'size' => $media->size,
                'mime_type' => $media->mime_type,
                'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'api.media.show',
                    now()->addMinutes(60),
                    ['media' => $media->id]
                ),
                'thumb_url' => $media->hasGeneratedConversion('thumb')
                    ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                        'api.media.show',
                        now()->addMinutes(60),
                        ['media' => $media->id, 'conversion' => 'thumb']
                    )
                    : null,
            ],
        ], 201);
    }

    /**
     * List attachments for a ticket.
     */
    public function attachments(Ticket $ticket): JsonResponse
    {
        $this->authorize('viewAttachments', $ticket);

        $ticketMedia = $ticket->getMedia('attachments');

        $commentMedia = \Spatie\MediaLibrary\MediaCollections\Models\Media::where('collection_name', 'attachments')
            ->where('model_type', \App\Models\TicketComment::class)
            ->whereIn('model_id', $ticket->comments()->pluck('id'))
            ->get();

        $internalNoteMedia = collect();
        if (request()->user()->can('viewInternalNotes', $ticket)) {
            $internalNoteMedia = \Spatie\MediaLibrary\MediaCollections\Models\Media::where('collection_name', 'attachments')
                ->where('model_type', \App\Models\TicketInternalNote::class)
                ->whereIn('model_id', $ticket->internalNotes()->pluck('id'))
                ->get();
        }

        $media = $ticketMedia->merge($commentMedia)->merge($internalNoteMedia)->sortByDesc('created_at')->values();

        $data = $media->map(fn ($m) => [
            'id' => $m->uuid,
            'name' => $m->file_name,
            'size' => $m->size,
            'mime_type' => $m->mime_type,
            'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'api.media.show',
                now()->addMinutes(60),
                ['media' => $m->id]
            ),
            'thumb_url' => $m->hasGeneratedConversion('thumb')
                ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'api.media.show',
                    now()->addMinutes(60),
                    ['media' => $m->id, 'conversion' => 'thumb']
                )
                : null,
            'created_at' => $m->created_at->toISOString(),
            'user' => $m->model_type === \App\Models\Ticket::class
                ? [
                    'name' => $ticket->reporter->name,
                    'avatar_url' => $ticket->reporter->avatar_thumb_url,
                ]
                : ($m->model ? [
                    'name' => $m->model->author->name ?? 'Unknown',
                    'avatar_url' => $m->model->author->avatar_thumb_url ?? null,
                ] : null),
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Delete an attachment from a ticket.
     */
    public function deleteAttachment(Ticket $ticket, string $mediaId): JsonResponse
    {
        $this->authorize('update', $ticket);

        // First, look in direct ticket attachments
        $media = $ticket->getMedia('attachments')->firstWhere('uuid', $mediaId);

        // If not found, search in comment attachments
        if (! $media) {
            $commentIds = $ticket->comments()->pluck('id');
            $media = \Spatie\MediaLibrary\MediaCollections\Models\Media::where('collection_name', 'attachments')
                ->where('model_type', \App\Models\TicketComment::class)
                ->whereIn('model_id', $commentIds)
                ->where('uuid', $mediaId)
                ->first();
        }

        // If still not found, search in internal note attachments
        if (! $media) {
            $noteIds = $ticket->internalNotes()->pluck('id');
            $media = \Spatie\MediaLibrary\MediaCollections\Models\Media::where('collection_name', 'attachments')
                ->where('model_type', \App\Models\TicketInternalNote::class)
                ->whereIn('model_id', $noteIds)
                ->where('uuid', $mediaId)
                ->first();
        }

        if (! $media) {
            return response()->json(['message' => 'Attachment not found.'], 404);
        }

        $media->delete();

        return response()->json(['message' => 'Attachment deleted successfully.']);
    }

    /**
     * Link a child ticket to a master ticket.
     */
    public function link(Request $request, Ticket $ticket): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => 'required|exists:tickets,public_id',
        ]);

        $master = Ticket::where('public_id', $validated['parent_id'])->firstOrFail();

        // Policy check: user must be able to edit child?
        // Policy check: user must be able to update ticket to link it
        $this->authorize('update', $ticket);

        try {
            $this->ticketService->linkChild($master, $ticket);

            return response()->json(['message' => 'Ticket linked successfully.']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Unlink a child ticket.
     */
    public function unlink(Ticket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);

        $this->ticketService->unlinkChild($ticket);

        return response()->json(['message' => 'Ticket unlinked successfully.']);
    }

    /**
     * Archive a ticket.
     */
    public function archive(Request $request, Ticket $ticket): JsonResponse
    {
        // $this->authorize('delete', $ticket); // Or specific 'archive'? Use delete for now.

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $this->ticketService->archive($ticket, $validated['reason'] ?? null);

            return response()->json(['message' => 'Ticket archived successfully.']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Bulk archive tickets.
     */
    public function bulkArchive(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:tickets,public_id',
            'reason' => 'nullable|string|max:500',
        ]);

        // Resolve public IDs to internal IDs
        $ticketIds = Ticket::whereIn('public_id', $validated['ids'])->pluck('id')->toArray();

        // Permission check?
        // For now assume user can archive if they have generic access.
        // Ideally check per ticket or global permission.

        $count = $this->ticketService->bulkArchive($ticketIds, $validated['reason'] ?? null);

        return response()->json(['message' => "{$count} tickets archived successfully."]);
    }
}
