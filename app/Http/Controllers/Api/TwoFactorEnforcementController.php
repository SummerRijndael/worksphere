<?php

namespace App\Http\Controllers\Api;

use App\Events\TwoFactorEnforcementChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\EnforceTwoFactorRequest;
use App\Models\RoleTwoFactorEnforcement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class TwoFactorEnforcementController extends Controller
{
    /**
     * Enforce or disable 2FA for a user or role.
     */
    public function enforce(EnforceTwoFactorRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if ($validated['target_type'] === 'user') {
            return $this->enforceForUser($validated, $request->user());
        }

        return $this->enforceForRole($validated, $request->user());
    }

    /**
     * Enforce 2FA for a specific user.
     */
    private function enforceForUser(array $data, User $admin): JsonResponse
    {
        $user = User::where('public_id', $data['target_id'])->firstOrFail();

        $user->update([
            'two_factor_enforced' => $data['enforce'],
            'two_factor_allowed_methods' => $data['enforce'] ? $data['allowed_methods'] : null,
            'two_factor_enforced_by' => $data['enforce'] ? $admin->id : null,
            'two_factor_enforced_at' => $data['enforce'] ? now() : null,
        ]);

        // Broadcast to user
        TwoFactorEnforcementChanged::dispatch(
            $user,
            $data['enforce'],
            $data['allowed_methods'] ?? []
        );

        return response()->json([
            'message' => $data['enforce']
                ? '2FA enforcement enabled for user.'
                : '2FA enforcement disabled for user.',
        ]);
    }

    /**
     * Enforce 2FA for all users with a specific role.
     */
    private function enforceForRole(array $data, User $admin): JsonResponse
    {
        $role = Role::where('name', $data['target_id'])->firstOrFail();

        if ($data['enforce']) {
            RoleTwoFactorEnforcement::updateOrCreate(
                ['role_id' => $role->id],
                [
                    'allowed_methods' => $data['allowed_methods'],
                    'is_active' => true,
                    'enforced_by' => $admin->id,
                    'enforced_at' => now(),
                ]
            );
        } else {
            RoleTwoFactorEnforcement::where('role_id', $role->id)->delete();
        }

        // Broadcast to all users with this role
        $usersWithRole = User::role($role->name)->get();
        foreach ($usersWithRole as $user) {
            TwoFactorEnforcementChanged::dispatch(
                $user,
                $data['enforce'],
                $data['allowed_methods'] ?? []
            );
        }

        return response()->json([
            'message' => $data['enforce']
                ? "2FA enforcement enabled for role: {$role->name}"
                : "2FA enforcement disabled for role: {$role->name}",
            'affected_users' => $usersWithRole->count(),
        ]);
    }

    /**
     * Get 2FA enforcement status for the current user.
     */
    public function getEnforcementStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        $requirement = $user->requires2FASetup();

        return response()->json([
            'requires_setup' => $requirement && $requirement['required'],
            'allowed_methods' => $requirement ? $requirement['methods'] : null,
            'source' => $requirement ? $requirement['source'] : null,
            'role' => $requirement['role'] ?? null,
            'configured_methods' => $this->getUserConfiguredMethods($user),
        ]);
    }

    /**
     * Get role enforcement settings.
     */
    public function roleEnforcements(): JsonResponse
    {
        $enforcements = RoleTwoFactorEnforcement::with(['role:id,name', 'enforcedByUser:id,public_id,name'])
            ->where('is_active', true)
            ->get();

        return response()->json([
            'enforcements' => $enforcements,
        ]);
    }

    /**
     * Get the 2FA methods configured for a user.
     *
     * @return array<string>
     */
    private function getUserConfiguredMethods(User $user): array
    {
        $methods = [];

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            $methods[] = 'totp';
        }
        if ($user->two_factor_sms_enabled && $user->phone) {
            $methods[] = 'sms';
        }
        if ($user->two_factor_email_enabled) {
            $methods[] = 'email';
        }
        if ($user->webauthnCredentials()->exists()) {
            $methods[] = 'passkey';
        }

        return $methods;
    }
}
