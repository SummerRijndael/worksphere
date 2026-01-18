<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleChangeRequestResource;
use App\Models\RoleChangeRequest;
use App\Services\RoleChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleChangeRequestController extends Controller
{
    public function __construct(
        protected RoleChangeService $roleChangeService
    ) {}

    /**
     * Get all role change requests.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->query('status');

        $query = RoleChangeRequest::with(['targetRole', 'requestedByUser', 'approvals.admin'])
            ->orderBy('created_at', 'desc');

        if ($status) {
            match ($status) {
                'pending' => $query->pending(),
                'approved' => $query->approved(),
                'rejected' => $query->rejected(),
                'expired' => $query->expired(),
                default => null,
            };
        }

        $requests = $query->paginate($request->query('per_page', 15));

        return RoleChangeRequestResource::collection($requests);
    }

    /**
     * Get pending requests that the current admin can approve.
     */
    public function pending(Request $request): AnonymousResourceCollection
    {
        $requests = $this->roleChangeService->getPendingRequestsForAdmin($request->user());

        return RoleChangeRequestResource::collection($requests);
    }

    /**
     * Create a new role change request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['role_title_change', 'role_permission_change', 'role_create', 'role_delete'])],
            'role_id' => ['required_unless:type,role_create', 'exists:roles,id'],
            'new_title' => ['required_if:type,role_title_change', 'string', 'max:255'],
            'permissions' => ['required_if:type,role_permission_change', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            'role_data' => ['required_if:type,role_create', 'array'],
            'role_data.name' => ['required_if:type,role_create', 'string', 'max:255', 'unique:roles,name'],
            'role_data.permissions' => ['sometimes', 'array'],
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        $role = isset($validated['role_id']) ? Role::find($validated['role_id']) : null;

        $roleChangeRequest = match ($validated['type']) {
            'role_title_change' => $this->roleChangeService->requestRoleTitleChange(
                $role,
                $validated['new_title'],
                $validated['reason'],
                $request->user()
            ),
            'role_permission_change' => $this->roleChangeService->requestRolePermissionChange(
                $role,
                $validated['permissions'],
                $validated['reason'],
                $request->user()
            ),
            'role_create' => $this->roleChangeService->requestRoleCreate(
                $validated['role_data'],
                $validated['reason'],
                $request->user()
            ),
            'role_delete' => $this->roleChangeService->requestRoleDelete(
                $role,
                $validated['reason'],
                $request->user()
            ),
        };

        return response()->json([
            'message' => 'Role change request created successfully',
            'data' => new RoleChangeRequestResource($roleChangeRequest->load(['targetRole', 'requestedByUser'])),
        ], 201);
    }

    /**
     * Get a specific role change request.
     */
    public function show(RoleChangeRequest $roleChangeRequest): RoleChangeRequestResource
    {
        return new RoleChangeRequestResource(
            $roleChangeRequest->load(['targetRole', 'requestedByUser', 'approvals.admin'])
        );
    }

    /**
     * Approve a role change request.
     */
    public function approve(Request $request, RoleChangeRequest $roleChangeRequest): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $approval = $this->roleChangeService->approveRequest(
                $roleChangeRequest,
                $request->user(),
                $validated['password'],
                $validated['comment'] ?? null
            );

            $roleChangeRequest->refresh();

            return response()->json([
                'message' => $roleChangeRequest->status === 'approved'
                    ? 'Request approved and changes applied'
                    : 'Approval recorded. Waiting for more approvals.',
                'data' => new RoleChangeRequestResource($roleChangeRequest->load(['targetRole', 'requestedByUser', 'approvals.admin'])),
                'approvals_needed' => $roleChangeRequest->getRemainingApprovalsNeeded(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reject a role change request.
     */
    public function reject(Request $request, RoleChangeRequest $roleChangeRequest): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        try {
            $this->roleChangeService->rejectRequest(
                $roleChangeRequest,
                $request->user(),
                $validated['password'],
                $validated['reason']
            );

            return response()->json([
                'message' => 'Request rejected',
                'data' => new RoleChangeRequestResource($roleChangeRequest->fresh()->load(['targetRole', 'requestedByUser', 'approvals.admin'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get approval configuration.
     */
    public function config(): JsonResponse
    {
        return response()->json([
            'data' => [
                'required_approvals' => $this->roleChangeService->getRequiredApprovalCount(),
                'request_expiry_days' => $this->roleChangeService->getRequestExpiryDays(),
            ],
        ]);
    }
}
