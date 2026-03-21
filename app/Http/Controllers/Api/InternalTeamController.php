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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InternalTeamController extends Controller
{
    public function __construct(protected InternalTeamServiceContract $internalTeamService)
    {
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

        $user = User::where('public_id', $data['user_id'])->orWhere('id', $data['user_id'])->firstOrFail();

        $this->internalTeamService->addMember($internalTeam, $user, $data['role'], $request->user());

        return response()->json(['message' => 'Member added successfully.']);
    }

    public function removeMember(Request $request, InternalTeam $internalTeam, string $userId): JsonResponse
    {
        $this->authorize('manageMembers', $internalTeam);

        $user = User::where('public_id', $userId)->orWhere('id', $userId)->firstOrFail();

        $this->internalTeamService->removeMember($internalTeam, $user, $request->user());

        return response()->json(['message' => 'Member removed successfully.']);
    }

    public function updateMemberRole(Request $request, InternalTeam $internalTeam, string $userId): JsonResponse
    {
        $this->authorize('manageMembers', $internalTeam);

        $validated = $request->validate([
            'role' => ['required', 'string', \Illuminate\Validation\Rule::enum(\App\Enums\InternalTeamRole::class)],
        ]);

        $user = User::where('public_id', $userId)->orWhere('id', $userId)->firstOrFail();

        $this->internalTeamService->updateMemberRole($internalTeam, $user, $validated['role'], $request->user());

        return response()->json(['message' => 'Member role updated successfully.']);
    }
}
