<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\RoleChangeRequest;
use App\Models\User;
use App\Http\Resources\UserResource;
use App\Services\RoleChangeService;
use App\Services\LockoutProtectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(
        protected RoleChangeService $roleChangeService,
        protected LockoutProtectionService $lockoutProtection
    ) {}
    /**
     * Get all roles with their permissions.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $roles = Role::with('permissions')
            ->orderBy('name')
            ->get();

        // Manually count users per role to avoid Spatie model resolution issues
        $userCounts = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->selectRaw('role_id, COUNT(*) as count')
            ->groupBy('role_id')
            ->pluck('count', 'role_id');

        $roles->each(function ($role) use ($userCounts) {
            $role->users_count = $userCounts[$role->id] ?? 0;
        });

        return RoleResource::collection($roles);
    }

    /**
     * Get a specific role.
     */
    public function show(Role $role): RoleResource
    {
        $role->users_count = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('role_id', $role->id)
            ->count();

        return new RoleResource($role->load('permissions'));
    }

    /**
     * Get all available permissions grouped by category.
     */
    public function permissions(): JsonResponse
    {
        $permissions = Permission::all()
            ->groupBy(function ($permission) {
                // Group by prefix (e.g., "users.view" -> "users")
                return explode('.', $permission->name)[0];
            })
            ->map(function ($group, $category) {
                return [
                    'category' => $category,
                    'label' => ucfirst(str_replace('_', ' ', $category)),
                    'permissions' => $group->map(fn ($p) => [
                        'name' => $p->name,
                        'label' => $this->formatPermissionLabel($p->name),
                    ])->values(),
                ];
            })
            ->values();

        return response()->json([
            'data' => $permissions,
        ]);
    }

    /**
     * Get permissions for a specific role.
     */
    public function rolePermissions(Role $role): JsonResponse
    {
        return response()->json([
            'data' => $role->permissions->pluck('name'),
        ]);
    }

    /**
     * Create a new role.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        // Check if role creation requires approval
        $requiresApproval = config('roles.creation_requires_approval', false);

        if ($requiresApproval) {
            return response()->json([
                'message' => 'Role creation requires multi-admin approval',
                'requires_approval' => true,
                'approval_type' => 'role_create',
                'preview_data' => $validated, // Send back data to be included in request
            ], 422);
        }

        $role = Role::create(['name' => $validated['name']]);

        if (! empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json([
            'message' => 'Role created successfully',
            'data' => new RoleResource($role->load('permissions')),
        ], 201);
    }

    /**
     * Update a role (requires approval for title changes).
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'unique:roles,name,'.$role->id],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        // 1. Lockout Protection Check
        if (isset($validated['permissions'])) {
            $currentPermissions = $role->permissions->pluck('name')->toArray();
            $newPermissions = $validated['permissions'];
            $removedPermissions = array_diff($currentPermissions, $newPermissions);
            
            try {
                $this->lockoutProtection->validateRolePermissionChange($role, $removedPermissions);
            } catch (\Exception $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        // 2. Check Approval Requirements
        if ($this->roleChangeService->requiresApproval($role)) {
            $roleChangeRequest = null;
            $reason = "Update role " . $role->name; // In a real app, we'd valid 'reason' from request

            // Create request for permission change
            if (isset($validated['permissions'])) {
                 $roleChangeRequest = $this->roleChangeService->requestRolePermissionChange(
                    $role,
                    $validated['permissions'],
                    $reason,
                    $request->user()
                );
            }
            // Create request for title change check (if name changed)
            elseif (isset($validated['name']) && $validated['name'] !== $role->name) {
                 $roleChangeRequest = $this->roleChangeService->requestRoleTitleChange(
                    $role,
                    $validated['name'],
                    $reason,
                    $request->user()
                );
            }
            
            if ($roleChangeRequest) {
                return response()->json([
                    'message' => 'Role update requires approval. Request created.',
                    'data' => [
                        'request_id' => $roleChangeRequest->id, 
                        'status' => 'pending_approval'
                    ]
                ], 202);
            }
        }

        // 3. Direct Update (if no approval required or no sensitive change)
        if (isset($validated['name']) && $validated['name'] !== $role->name) {
            $role->update(['name' => $validated['name']]);
        }

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json([
            'message' => 'Role updated successfully',
            'data' => new RoleResource($role->fresh()->load('permissions')),
        ]);
    }

    /**
     * Get role statistics.
     */
    public function statistics(): JsonResponse
    {
        $roles = Role::all();

        // Manually count users per role
        $userCounts = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->selectRaw('role_id, COUNT(*) as count')
            ->groupBy('role_id')
            ->pluck('count', 'role_id');

        $stats = [
            'total_roles' => $roles->count(),
            'total_permissions' => Permission::count(),
            'roles' => $roles->map(fn ($role) => [
                'name' => $role->name,
                'users_count' => $userCounts[$role->id] ?? 0,
            ]),
            'pending_requests' => RoleChangeRequest::pending()->count(),
        ];

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get users assigned to a specific role.
     */
    public function users(Request $request, Role $role): AnonymousResourceCollection
    {
        $search = $request->query('search');

        $users = User::role($role->name)
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->with(['roles']) // Eager load roles
            ->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 10));

        return \App\Http\Resources\UserResource::collection($users);
    }

    /**
     * Format a permission name into a readable label.
     */
    protected function formatPermissionLabel(string $permission): string
    {
        $parts = explode('.', $permission);
        $action = end($parts);

        return ucfirst(str_replace('_', ' ', $action));
    }
}
