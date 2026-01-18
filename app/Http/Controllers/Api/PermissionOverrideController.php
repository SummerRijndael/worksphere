<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionOverrideResource;
use App\Models\PermissionOverride;
use App\Models\Team;
use App\Models\User;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PermissionOverrideController extends Controller
{
    public function __construct(
        protected PermissionService $permissionService
    ) {}

    /**
     * Get all permission overrides for a user.
     */
    public function index(User $user): AnonymousResourceCollection
    {
        $overrides = $this->permissionService->getUserOverrides($user);

        return PermissionOverrideResource::collection($overrides);
    }

    /**
     * Create a new permission override (grant or block).
     */
    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'permission' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['grant', 'block'])],
            'scope' => ['sometimes', Rule::in(['global', 'team'])],
            'team_id' => ['nullable', 'required_if:scope,team', 'exists:teams,id'],
            'is_temporary' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'required_if:is_temporary,true', 'date', 'after:now'],
            'expiry_behavior' => ['nullable', 'required_if:is_temporary,true', Rule::in(['auto_revoke', 'grace_period'])],
            'grace_period_days' => ['nullable', 'required_if:expiry_behavior,grace_period', 'integer', 'min:1', 'max:90'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $options = [
            'scope' => $validated['scope'] ?? 'global',
            'team_id' => $validated['team_id'] ?? null,
            'is_temporary' => $validated['is_temporary'] ?? false,
            'expires_at' => isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null,
            'expiry_behavior' => $validated['expiry_behavior'] ?? null,
            'grace_period_days' => $validated['grace_period_days'] ?? null,
        ];

        $method = $validated['type'] === 'grant' ? 'grantOverride' : 'blockOverride';

        $override = $this->permissionService->$method(
            $user,
            $validated['permission'],
            $validated['reason'],
            $request->user(),
            $options
        );

        return response()->json([
            'message' => "Permission {$validated['type']} created successfully",
            'data' => new PermissionOverrideResource($override),
        ], 201);
    }

    /**
     * Update a permission override.
     */
    public function update(Request $request, PermissionOverride $override): JsonResponse
    {
        $validated = $request->validate([
            'expires_at' => ['sometimes', 'date', 'after:now'],
            'reason' => ['sometimes', 'string', 'min:10', 'max:1000'],
        ]);

        if (isset($validated['expires_at'])) {
            $this->permissionService->renewTemporaryPermission(
                $override,
                Carbon::parse($validated['expires_at']),
                $request->user()
            );
        }

        if (isset($validated['reason'])) {
            $override->update(['reason' => $validated['reason']]);
        }

        return response()->json([
            'message' => 'Permission override updated successfully',
            'data' => new PermissionOverrideResource($override->fresh()),
        ]);
    }

    /**
     * Revoke (soft delete) a permission override.
     */
    public function destroy(Request $request, PermissionOverride $override): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $this->permissionService->revokeOverride(
            $override,
            $validated['reason'],
            $request->user()
        );

        return response()->json([
            'message' => 'Permission override revoked successfully',
        ]);
    }

    /**
     * Renew a temporary permission override.
     */
    public function renew(Request $request, PermissionOverride $override): JsonResponse
    {
        if (! $override->is_temporary) {
            return response()->json([
                'message' => 'Only temporary permissions can be renewed',
            ], 422);
        }

        $validated = $request->validate([
            'expires_at' => ['required', 'date', 'after:now'],
        ]);

        $this->permissionService->renewTemporaryPermission(
            $override,
            Carbon::parse($validated['expires_at']),
            $request->user()
        );

        return response()->json([
            'message' => 'Permission renewed successfully',
            'data' => new PermissionOverrideResource($override->fresh()),
        ]);
    }

    /**
     * Get effective permissions for a user.
     */
    public function effective(Request $request, User $user): JsonResponse
    {
        $teamId = $request->query('team_id');
        $team = $teamId ? Team::find($teamId) : null;

        $permissions = $this->permissionService->getEffectivePermissions($user, $team);

        return response()->json([
            'data' => $permissions,
        ]);
    }

    /**
     * Get permissions expiring soon.
     */
    public function expiring(Request $request): AnonymousResourceCollection
    {
        $days = (int) $request->query('days', 7);

        $overrides = $this->permissionService->getExpiringPermissions($days);

        return PermissionOverrideResource::collection($overrides);
    }
}
