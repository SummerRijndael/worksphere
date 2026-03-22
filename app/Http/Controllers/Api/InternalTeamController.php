<?php

namespace App\Http\Controllers\Api;

use App\Contracts\InternalTeamServiceContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalTeam\StoreInternalTeamMemberRequest;
use App\Http\Requests\InternalTeam\StoreInternalTeamRequest;
use App\Http\Requests\InternalTeam\UpdateInternalTeamRequest;
use App\Http\Resources\InternalTeamMemberResource;
use App\Http\Resources\InternalTeamResource;
use App\Models\InternalTeam;
use App\Models\User;
use App\Models\AuditLog;
use App\Models\TeamEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\MediaLibrary\Support\MediaStream;

class InternalTeamController extends Controller
{
    public function __construct(
        protected InternalTeamServiceContract $internalTeamService,
        protected \App\Services\MediaService $mediaService
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', InternalTeam::class);

        $filters = $request->only(['search', 'status']);
        $teams = $this->internalTeamService->list($filters, $request->input('per_page', 15));

        return InternalTeamResource::collection($teams);
    }

    public function store(StoreInternalTeamRequest $request): JsonResponse
    {
        $this->authorize('create', InternalTeam::class);

        $team = $this->internalTeamService->create($request->validated(), $request->user());

        return response()->json([
            'message' => 'Internal team created successfully.',
            'data' => new InternalTeamResource($team),
        ], 201);
    }

    public function show(InternalTeam $internalTeam): InternalTeamResource
    {
        $this->authorize('view', $internalTeam);

        $internalTeam->load('members');

        return new InternalTeamResource($internalTeam);
    }

    public function update(UpdateInternalTeamRequest $request, InternalTeam $internalTeam): JsonResponse
    {
        $this->authorize('update', $internalTeam);

        $team = $this->internalTeamService->update($internalTeam, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Internal team updated successfully.',
            'data' => new InternalTeamResource($team),
        ]);
    }

    public function destroy(Request $request, InternalTeam $internalTeam): JsonResponse
    {
        $this->authorize('delete', $internalTeam);

        $this->internalTeamService->delete($internalTeam, $request->user());

        return response()->json(['message' => 'Internal team deleted successfully.']);
    }

    public function members(InternalTeam $internalTeam): AnonymousResourceCollection
    {
        $this->authorize('view', $internalTeam);

        // Can apply pagination/filtering here if needed
        $members = $internalTeam->members()->paginate(50);

        return InternalTeamMemberResource::collection($members);
    }

    public function addMember(StoreInternalTeamMemberRequest $request, InternalTeam $internalTeam): JsonResponse
    {
        $this->authorize('manageMembers', $internalTeam);

        $data = $request->validated();

        $user = User::where('public_id', '=', $data['user_id'])
            ->orWhere('id', '=', $data['user_id'])
            ->firstOrFail();

        $this->internalTeamService->addMember($internalTeam, $user, $data['role'], $request->user());

        return response()->json(['message' => 'Member added successfully.']);
    }

    public function removeMember(Request $request, InternalTeam $internalTeam, string $userId): JsonResponse
    {
        $this->authorize('manageMembers', $internalTeam);

        $user = User::where('public_id', '=', $userId)->orWhere('id', '=', $userId)->firstOrFail();

        $this->internalTeamService->removeMember($internalTeam, $user, $request->user());

        return response()->json(['message' => 'Member removed successfully.']);
    }

    public function updateMemberRole(Request $request, InternalTeam $internalTeam, string $userId): JsonResponse
    {
        $this->authorize('manageMembers', $internalTeam);

        $validated = $request->validate([
            'role' => ['required', 'string', \Illuminate\Validation\Rule::enum(\App\Enums\InternalTeamRole::class)],
        ]);

        $user = User::where('public_id', '=', $userId)->orWhere('id', '=', $userId)->firstOrFail();

        $this->internalTeamService->updateMemberRole($internalTeam, $user, $validated['role'], $request->user());

        return response()->json(['message' => 'Member role updated successfully.']);
    }

    public function files(InternalTeam $internalTeam): JsonResponse
    {
        $this->authorize('view', $internalTeam);

        $media = $internalTeam->getMedia('internal_team_files')->map(function ($file) {
            return [
                'id' => $file->id,
                'uuid' => $file->uuid,
                'name' => $file->name,
                'file_name' => $file->file_name,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'api.media.show',
                    now()->addMinutes(60),
                    ['media' => $file->id]
                ),
                'created_at' => $file->created_at,
            ];
        });

        return response()->json($media);
    }

    public function uploadFile(Request $request, InternalTeam $internalTeam): JsonResponse
    {
        $this->authorize('manageFiles', $internalTeam);

        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $media = $this->mediaService->attachFromRequest($internalTeam, 'file', 'internal_team_files');

        return response()->json([
            'id' => $media->id,
            'uuid' => $media->uuid,
            'name' => $media->name,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'api.media.show',
                now()->addMinutes(60),
                ['media' => $media->id]
            ),
            'created_at' => $media->created_at,
        ]);
    }

    public function bulkDownload(Request $request, InternalTeam $internalTeam)
    {
        $this->authorize('view', $internalTeam);

        $request->validate([
            'media_ids' => ['required', 'array', 'max:10'],
            'media_ids.*' => ['exists:media,id'],
        ]);

        $media = $internalTeam->media()->whereIn('id', $request->media_ids)->get();

        if ($media->isEmpty()) {
            return response()->json(['message' => 'No files found.'], 404);
        }

        return MediaStream::create($internalTeam->slug . '-files.zip')->addMedia($media);
    }

    public function bulkDelete(Request $request, InternalTeam $internalTeam): JsonResponse
    {
        $this->authorize('manageFiles', $internalTeam);

        $request->validate([
            'media_ids' => ['required', 'array', 'max:10'],
            'media_ids.*' => ['exists:media,id'],
        ]);

        $internalTeam->media()->whereIn('id', $request->media_ids)->delete();

        return response()->json(['message' => 'Files deleted successfully.']);
    }

    public function deleteFile(InternalTeam $internalTeam, string $mediaId): JsonResponse
    {
        $this->authorize('manageFiles', $internalTeam);

        $media = $internalTeam->media()->where('id', '=', $mediaId)->firstOrFail();
        $media->delete();

        return response()->json(['message' => 'File deleted successfully.']);
    }

    public function activity(Request $request, InternalTeam $internalTeam): JsonResponse
    {
        $this->authorize('viewActivity', $internalTeam);

        $perPage = $request->input('per_page', 20);

        $logs = AuditLog::query()
            ->with('user:id,public_id,name')
            ->where(function ($query) use ($internalTeam) {
                $query->where('auditable_type', '=', 'App\\Models\\InternalTeam')
                    ->where('auditable_id', '=', $internalTeam->id);
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($logs);
    }

    public function calendar(Request $request, InternalTeam $internalTeam): JsonResponse
    {
        $this->authorize('view', $internalTeam);

        $start = $request->input('start');
        $end = $request->input('end');

        $query = TeamEvent::query()
            ->where('internal_team_id', '=', $internalTeam->id)
            ->when($start && $end, function ($q) use ($start, $end) {
                $q->between($start, $end);
            });

        $events = $query->get()->map(function ($event) {
            return [
                'id' => $event->public_id,
                'title' => $event->title,
                'start' => $event->start_time->toIso8601String(),
                'end' => $event->end_time ? $event->end_time->toIso8601String() : null,
                'allDay' => $event->is_all_day,
                'backgroundColor' => $event->color,
                'borderColor' => $event->color,
                'extendedProps' => [
                    'type' => 'event',
                    'description' => $event->description,
                    'location' => $event->location,
                ],
            ];
        });

        return response()->json(['data' => $events]);
    }
}
