<?php

namespace App\Http\Controllers\Api\Support;

use App\Contracts\SupportConversationServiceContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\AssignSupportConversationSkillRequest;
use App\Http\Requests\Support\StoreSupportSkillRequest;
use App\Http\Requests\Support\UpdateSupportSkillRequest;
use App\Http\Requests\Support\UpsertSupportSkillMemberRequest;
use App\Http\Resources\Support\SupportConversationResource;
use App\Http\Resources\Support\SupportSkillMembershipResource;
use App\Http\Resources\Support\SupportSkillResource;
use App\Models\SupportConversation;
use App\Models\SupportSkill;
use App\Models\User;
use App\Services\Support\SupportSkillService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class SupportSkillController extends Controller
{
    public function __construct(
        protected SupportSkillService $supportSkillService,
        protected SupportConversationServiceContract $supportConversationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupportSkill::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable'],
            'include_members' => ['nullable'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $q = trim((string) ($validated['q'] ?? ''));
        $department = trim((string) ($validated['department'] ?? ''));
        $isActive = isset($validated['is_active']) ? filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $includeMembers = filter_var($validated['include_members'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $query = SupportSkill::query()
            ->with('creator:id,public_id,name,email')
            ->withCount(['memberships as members_count'])
            ->withCount([
                'memberships as active_members_count' => fn ($membership) => $membership->where('is_active', true),
            ])
            ->orderBy('priority')
            ->orderBy('name');

        if ($q !== '') {
            $query->where(function ($scoped) use ($q): void {
                $scoped->where('name', 'like', '%'.$q.'%')
                    ->orWhere('slug', 'like', '%'.$q.'%')
                    ->orWhere('description', 'like', '%'.$q.'%');
            });
        }

        if ($department !== '') {
            $query->where('department', $department);
        }

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        if ($includeMembers) {
            $query->with(['memberships' => function ($membershipQuery): void {
                $membershipQuery
                    ->with('user:id,public_id,name,email,status')
                    ->orderByDesc('is_primary')
                    ->orderBy('membership_role')
                    ->orderBy('id');
            }]);
        }

        $paginator = $query->paginate($perPage);

        return $this->paginatedSkillResponse($paginator, $request, $includeMembers);
    }

    public function store(StoreSupportSkillRequest $request): JsonResponse
    {
        $this->authorize('create', SupportSkill::class);

        $skill = $this->supportSkillService->upsertSkill(
            $request->validated(),
            null,
            $request->user()
        );

        $skill->load('creator:id,public_id,name,email')
            ->loadCount(['memberships as members_count'])
            ->loadCount([
                'memberships as active_members_count' => fn ($membership) => $membership->where('is_active', true),
            ]);

        return response()->json([
            'message' => 'Support skill created successfully.',
            'data' => new SupportSkillResource($skill),
        ], 201);
    }

    public function update(UpdateSupportSkillRequest $request, SupportSkill $skill): JsonResponse
    {
        $this->authorize('update', $skill);

        $skill = $this->supportSkillService->upsertSkill(
            $request->validated(),
            $skill,
            $request->user()
        );

        $skill->load('creator:id,public_id,name,email')
            ->loadCount(['memberships as members_count'])
            ->loadCount([
                'memberships as active_members_count' => fn ($membership) => $membership->where('is_active', true),
            ]);

        return response()->json([
            'message' => 'Support skill updated successfully.',
            'data' => new SupportSkillResource($skill),
        ]);
    }

    public function upsertMember(UpsertSupportSkillMemberRequest $request, SupportSkill $skill): JsonResponse
    {
        $this->authorize('assignMembers', $skill);

        $validated = $request->validated();

        $user = User::query()
            ->where('public_id', (string) $validated['agent_public_id'])
            ->firstOrFail();

        $membership = $this->supportSkillService->setAgentMembership($skill, $user, $validated);
        $membership->load('user:id,public_id,name,email,status');

        return response()->json([
            'message' => 'Skill membership updated successfully.',
            'data' => new SupportSkillMembershipResource($membership),
        ]);
    }

    public function destroyMember(Request $request, SupportSkill $skill, User $user): JsonResponse
    {
        $this->authorize('assignMembers', $skill);

        $this->supportSkillService->removeAgentMembership($skill, $user);

        return response()->json([
            'message' => 'Skill membership removed successfully.',
        ]);
    }

    public function assignConversationSkill(AssignSupportConversationSkillRequest $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorize('assign', $conversation);

        $validated = $request->validated();
        $skillPublicId = trim((string) ($validated['support_skill_id'] ?? ''));

        $skill = null;
        if ($skillPublicId !== '') {
            $skill = SupportSkill::query()
                ->where('public_id', $skillPublicId)
                ->firstOrFail();

            $this->authorize('update', $skill);

            if (! $skill->is_active) {
                return response()->json([
                    'message' => 'Only active support skills can be assigned.',
                ], 422);
            }
        }

        try {
            $conversation = $this->supportConversationService->getConversationForActor(
                $conversation,
                $request->user()
            );
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $conversation = $this->supportSkillService->assignConversationSkill(
            $conversation,
            $skill,
            $request->user()
        );

        return response()->json([
            'message' => $skill
                ? 'Conversation routed to support skill successfully.'
                : 'Conversation routing has been reset to global.',
            'data' => new SupportConversationResource($conversation, includePrivateNotes: true),
        ]);
    }

    protected function paginatedSkillResponse(LengthAwarePaginator $paginator, Request $request, bool $includeMembers): JsonResponse
    {
        $data = $paginator->getCollection()
            ->map(fn (SupportSkill $skill) => (new SupportSkillResource($skill, $includeMembers))->toArray($request))
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
