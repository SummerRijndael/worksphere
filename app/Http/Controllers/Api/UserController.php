<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\AccountCreated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password; // Add this import
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(
        protected \App\Services\MediaService $mediaService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()
            ->with(['roles'])
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            })
            ->when($request->role, function ($query, $role) {
                $query->role($role);
            })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->date_from, function ($query, $date) {
                $query->whereDate('created_at', '>=', $date);
            })
            ->when($request->date_to, function ($query, $date) {
                $query->whereDate('created_at', '<=', $date);
            });

        $users = $query->paginate($request->per_page ?? 15);

        // Expose 'id' for Admin usage (e.g., Team Owner selection)

        // Expose 'id' for Admin usage (e.g., Team Owner selection)
        // $users->getCollection()->makeVisible('id');

        return UserResource::collection($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'username' => ['nullable', 'string', 'max:255', 'unique:users'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'status' => ['required', 'string', 'in:active,inactive,suspended'],
        ]);

        $randomPassword = Str::random(32);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'username' => $validated['username'] ?? null,
            'password' => Hash::make($randomPassword),
            'status' => $validated['status'],
        ]);

        $user->assignRole($validated['role']);

        $user->notify(new AccountCreated);

        return response()->json(new UserResource($user), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $user->load(['roles', 'permissions']);

        return response()->json(new UserResource($user));
    }

    /**
     * Display the specified resource with extra details for profile.
     */
    public function details(Request $request): JsonResponse
    {
        $user = $request->user();

        // Load related data
        $user->load(['teams', 'roles', 'permissions']);

        // Manually load media since it's a trait method, though 'with' often works if model setup correctly.
        // But for clarity and ensuring it's loaded for the resource:
        $user->load('media');

        return response()->json(new UserResource($user));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $authUser = $request->user();

        // Determine which fields can be updated based on permissions
        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];

        // Email can only be changed by the user themselves (self-service)
        if ($authUser->is($user)) {
            $rules['email'] = ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)];
        }

        // Role changes require users.manage_roles permission
        if ($authUser->can('users.manage_roles')) {
            $rules['role'] = ['sometimes', 'string', 'exists:roles,name'];
        }

        // Status changes require users.manage_status permission
        if ($authUser->can('users.manage_status')) {
            $rules['status'] = ['sometimes', 'string', 'in:active,inactive,suspended,pending,blocked,disabled'];
        }

        $validated = $request->validate($rules);

        // Block email change attempt by admin
        if ($request->has('email') && ! $authUser->is($user)) {
            return response()->json(['message' => 'Email can only be changed by the user themselves.'], 403);
        }

        // Block role change without permission
        if ($request->has('role') && ! $authUser->can('users.manage_roles')) {
            return response()->json(['message' => 'You do not have permission to manage roles.'], 403);
        }

        // Block status change without permission
        if ($request->has('status') && ! $authUser->can('users.manage_status')) {
            return response()->json(['message' => 'You do not have permission to manage user status.'], 403);
        }

        // Update basic fields
        $updateData = collect($validated)->only(['name', 'username', 'phone', 'email'])->filter()->toArray();
        if (! empty($updateData)) {
            $user->update($updateData);
        }

        // Handle role update
        if (isset($validated['role'])) {
            $user->syncRoles([$validated['role']]);

            // Broadcast permission update to the affected user
            \App\Events\UserPermissionsUpdated::dispatch($user, 'role_changed');
        }

        // Handle status update
        if (isset($validated['status'])) {
            $user->update(['status' => $validated['status']]);
        }

        return response()->json(new UserResource($user));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Cannot delete yourself'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    /**
     * Update the user's profile information.
     */
    /**
     * Update the user's profile information.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users')->ignore($user->id)],
            'title' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255', 'url'],
            'skills' => ['nullable', 'array'],
        ]);

        // Check if email is changing
        if ($validated['email'] !== $user->email && $user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail) {
            $user->forceFill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'email_verified_at' => null,
                'username' => $validated['username'] ?? $user->username,
                'title' => $validated['title'] ?? null,
                'bio' => $validated['bio'] ?? null,
                'location' => $validated['location'] ?? null,
                'website' => $validated['website'] ?? null,
                'skills' => $validated['skills'] ?? null,
            ])->save();

            $user->sendEmailVerificationNotification();
        } else {
            $user->forceFill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'username' => $validated['username'] ?? $user->username,
                'title' => $validated['title'] ?? null,
                'bio' => $validated['bio'] ?? null,
                'location' => $validated['location'] ?? null,
                'website' => $validated['website'] ?? null,
                'skills' => $validated['skills'] ?? null,
            ])->save();
        }

        return response()->json(new UserResource($user));
    }

    /**
     * Upload an avatar.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'max:2048']]);

        $user = $request->user();

        $this->mediaService->attachFromRequest(
            $user,
            'avatar',
            'avatars',
            Str::random(40).'.webp',
            null,
            'public' // Force public disk
        );

        return response()->json(new UserResource($user));
    }

    /**
     * Upload a cover photo.
     */
    public function uploadCover(Request $request): JsonResponse
    {
        // Custom logging as requested
        $logPath = storage_path('app/private/sys/logs/cover_debug.log');
        if (! file_exists(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }

        $logData = '--- Upload Request '.date('Y-m-d H:i:s')." ---\n";
        $logData .= 'Headers: '.json_encode($request->headers->all())."\n";
        $logData .= 'Files: '.json_encode($request->allFiles())."\n";
        $logData .= 'Post Data: '.json_encode($request->all())."\n";
        $logData .= 'Has Cover: '.($request->hasFile('cover') ? 'Yes' : 'No')."\n";

        file_put_contents($logPath, $logData, FILE_APPEND);
        $request->validate(['cover' => ['required', 'image', 'max:4096']]); // Higher limit for cover

        $user = $request->user();

        $this->mediaService->attachFromRequest(
            $user,
            'cover',
            'cover_photos',
            Str::random(40).'.webp'
        );

        return response()->json(new UserResource($user));
    }

    /**
     * Upload a document.
     */
    public function uploadDocument(Request $request): JsonResponse
    {
        $request->validate(['document' => ['required', 'file', 'max:10240']]); // 10MB limit

        $user = $request->user();

        $extension = $request->file('document')->extension();
        $this->mediaService->attachFromRequest(
            $user,
            'document',
            'documents',
            Str::random(40).'.'.$extension
        );

        // Return latest media item or refreshed user
        return response()->json(new UserResource($user->load('media')));
    }

    /**
     * Delete a media item.
     */
    public function deleteMedia(Request $request, Media $media): JsonResponse
    {
        $user = $request->user();

        // Ensure user owns this media
        if ($media->model_type !== User::class || $media->model_id !== $user->id) {
            abort(403);
        }

        $media->delete();

        return response()->json(['message' => 'File deleted.']);
    }

    /**
     * Get the user's active sessions.
     */
    public function sessions(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderBy('last_activity', 'desc')
            ->get()
            ->map(function ($session) {
                $agent = new \Jenssegers\Agent\Agent;
                $agent->setUserAgent($session->user_agent);

                try {
                    $location = geoip($session->ip_address);
                } catch (\Exception $e) {
                    $location = null;
                }

                return (object) [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'is_current_device' => $session->id === session()->getId(),
                    'last_activity' => date('Y-m-d H:i:s', $session->last_activity),
                    'device' => [
                        'browser' => $agent->browser(),
                        'browser_version' => $agent->version($agent->browser()),
                        'platform' => $agent->platform(),
                        'device' => $agent->device(),
                        'is_desktop' => $agent->isDesktop(),
                        'is_phone' => $agent->isPhone(),
                        'is_robot' => $agent->isRobot(),
                    ],
                    'location' => $location ? [
                        'city' => $location->city,
                        'state' => $location->state_name,
                        'country' => $location->country,
                        'iso_code' => $location->iso_code,
                    ] : null,
                ];
            });

        return response()->json($sessions);
    }

    /**
     * Revoke the user's sessions.
     */
    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $query = DB::table('sessions')->where('user_id', $user->id);

        if ($request->has('session_ids')) {
            $query->whereIn('id', $request->input('session_ids'));
        }

        $query->delete();

        return response()->json(['message' => 'Sessions revoked successfully']);
    }

    /**
     * Send a password reset link to the user.
     */
    public function sendPasswordReset(User $user): JsonResponse
    {
        $this->authorize('update', $user);

        // We'll use the Password broker to send the link
        $status = PasswordBroker::sendResetLink(['email' => $user->email]);

        if ($status === PasswordBroker::RESET_LINK_SENT) {
            return response()->json(['message' => __($status)]);
        }

        return response()->json(['message' => __($status)], 400);
    }

    /**
     * Get the user's audit logs (mock implementation).
     */
    public function auditLogs(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        // Mock data for now
        $logs = collect(range(1, 10))->map(function ($i) {
            return [
                'id' => $i,
                'action' => ['Login', 'Update Profile', 'Password Change', 'Logout'][rand(0, 3)],
                'ip_address' => '192.168.1.'.rand(1, 255),
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)...',
                'created_at' => now()->subDays(rand(0, 30))->toDateTimeString(),
            ];
        });

        return response()->json($logs);
    }

    /**
     * Resend the email verification notification.
     */
    public function resendVerification(User $user): JsonResponse
    {
        $this->authorize('update', $user);

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email is already verified.'], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }

    /**
     * Update the authenticated user's password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * Update the authenticated user's preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'notifications' => ['sometimes', 'array'],
            'notifications.email' => ['sometimes', 'boolean'],
            'notifications.push' => ['sometimes', 'boolean'],
            'notifications.marketing' => ['sometimes', 'boolean'],
            'notifications.updates' => ['sometimes', 'boolean'],
            'notifications.mentions' => ['sometimes', 'boolean'],
            'notifications.tasks' => ['sometimes', 'boolean'],
            'appearance' => ['sometimes', 'array'],
            'appearance.mode' => ['sometimes', 'string', 'in:light,dark,system'],
            'appearance.color' => ['sometimes', 'string'],
            'appearance.cover_offset' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'appearance.reducedMotion' => ['sometimes', 'boolean'],
            'appearance.compactMode' => ['sometimes', 'boolean'],
        ]);

        // Deep merge with existing preferences
        $currentPreferences = $user->preferences ?? [];

        // Helper function for deep merge
        $deepMerge = function (array &$original, array $new) use (&$deepMerge) {
            foreach ($new as $key => $value) {
                if (is_array($value) && isset($original[$key]) && is_array($original[$key])) {
                    $deepMerge($original[$key], $value);
                } else {
                    $original[$key] = $value;
                }
            }
        };

        // If validated data is available, merge it
        if (! empty($validated)) {
            $deepMerge($currentPreferences, $validated);
        }

        $user->update(['preferences' => $currentPreferences]);

        return response()->json([
            'message' => 'Preferences updated successfully.',
            'preferences' => $user->fresh()->preferences,
        ]);
    }

    /**
     * Update the authenticated user's ticket notification preferences.
     */
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'ticket_created' => ['sometimes', 'boolean'],
            'ticket_assigned' => ['sometimes', 'boolean'],
            'ticket_updated' => ['sometimes', 'boolean'],
            'ticket_comment' => ['sometimes', 'boolean'],
            'ticket_sla' => ['sometimes', 'boolean'],
        ]);

        // Update each preference
        foreach ($validated as $type => $enabled) {
            $user->setNotificationPreference($type, $enabled);
        }

        return response()->json([
            'message' => 'Ticket notification preferences updated.',
            'preferences' => $user->notification_preferences,
        ]);
    }

    /**
     * Get the authenticated user's ticket notification preferences.
     */
    public function getNotificationPreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'ticket_created' => $user->wantsEmailFor('ticket_created'),
            'ticket_assigned' => $user->wantsEmailFor('ticket_assigned'),
            'ticket_updated' => $user->wantsEmailFor('ticket_updated'),
            'ticket_comment' => $user->wantsEmailFor('ticket_comment'),
            'ticket_sla' => $user->wantsEmailFor('ticket_sla'),
        ]);
    }

    /**
     * Get the authenticated user's own sessions.
     */
    public function ownSessions(Request $request): JsonResponse
    {
        $user = $request->user();

        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderBy('last_activity', 'desc')
            ->get()
            ->map(function ($session) {
                $agent = new \Jenssegers\Agent\Agent;
                $agent->setUserAgent($session->user_agent);

                try {
                    $location = geoip($session->ip_address);
                } catch (\Exception $e) {
                    $location = null;
                }

                return (object) [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'is_current_device' => $session->id === session()->getId(),
                    'last_activity' => date('Y-m-d H:i:s', $session->last_activity),
                    'device' => [
                        'browser' => $agent->browser(),
                        'browser_version' => $agent->version($agent->browser()),
                        'platform' => $agent->platform(),
                        'device' => $agent->device(),
                        'is_desktop' => $agent->isDesktop(),
                        'is_phone' => $agent->isPhone(),
                        'is_robot' => $agent->isRobot(),
                    ],
                    'location' => $location ? [
                        'city' => $location->city,
                        'state' => $location->state_name,
                        'country' => $location->country,
                        'iso_code' => $location->iso_code,
                    ] : null,
                ];
            });

        return response()->json($sessions);
    }

    /**
     * Revoke all of the authenticated user's sessions except current.
     */
    public function revokeOwnSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentSessionId = session()->getId();

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        return response()->json(['message' => 'All other sessions have been revoked.']);
    }

    /**
     * Revoke a specific session of the authenticated user.
     */
    public function revokeOwnSession(Request $request, string $sessionId): JsonResponse
    {
        $user = $request->user();

        // Prevent revoking current session
        if ($sessionId === session()->getId()) {
            return response()->json(['message' => 'Cannot revoke current session. Use logout instead.'], 400);
        }

        $deleted = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', $sessionId)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        return response()->json(['message' => 'Session revoked.']);
    }

    /**
     * Get the authenticated user's social accounts.
     */
    public function socialAccounts(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get accounts from the new social_accounts table
        $accounts = $user->socialAccounts->map(function ($account) {
            return [
                'id' => $account->id,
                'provider' => $account->provider,
                'provider_name' => $account->display_name,
                'provider_email' => $account->provider_email,
                'provider_avatar' => $account->provider_avatar,
                'connected_at' => $account->created_at?->toISOString(),
            ];
        })->values()->toArray();

        // Include legacy provider if not already in social_accounts
        if ($user->provider && ! $user->socialAccounts()->where('provider', $user->provider)->exists()) {
            $accounts[] = [
                'id' => null, // Legacy has no ID
                'provider' => $user->provider,
                'provider_name' => ucfirst($user->provider),
                'provider_email' => null,
                'provider_avatar' => null,
                'connected_at' => $user->created_at?->toISOString(),
                'is_legacy' => true,
            ];
        }

        return response()->json($accounts);
    }

    /**
     * Disconnect a social account from the authenticated user.
     */
    public function disconnectSocial(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();

        // Check if user has password set (required to disconnect if it's the only auth method)
        if (! $user->is_password_set) {
            // Check how many social accounts remain
            $socialCount = $user->socialAccounts()->count();
            $hasLegacy = $user->provider !== null;
            $totalAuth = $socialCount + ($hasLegacy ? 1 : 0);

            if ($totalAuth <= 1) {
                return response()->json([
                    'message' => 'You must set a password before disconnecting your only social account.',
                ], 400);
            }
        }

        // Try to disconnect from social_accounts table first
        $socialAccount = $user->socialAccounts()->where('provider', $provider)->first();

        if ($socialAccount) {
            $socialAccount->delete();

            return response()->json(['message' => ucfirst($provider).' account disconnected.']);
        }

        // Legacy: check users.provider column
        if ($user->provider === $provider) {
            $user->update([
                'provider' => null,
                'provider_id' => null,
            ]);

            return response()->json(['message' => ucfirst($provider).' account disconnected.']);
        }

        return response()->json(['message' => 'Social account not found.'], 404);
    }

    /**
     * Get user statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        // Total Users
        $totalUsers = User::count();

        // Status Counts
        $statusCounts = User::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // Role Counts
        $roleCounts = DB::table('roles')
            ->join('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->select('roles.name', DB::raw('count(*) as count'))
            ->groupBy('roles.name')
            ->pluck('count', 'name');

        // Registration Trends (Last 30 days)
        $trends = User::selectRaw('DATE(created_at) as date, count(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->date => $item->count];
            });

        // Fill in missing dates for trends
        $chartData = [];
        $currentDate = now()->subDays(29); // Start 29 days ago to include today (30 days total)
        for ($i = 0; $i < 30; $i++) {
            $dateString = $currentDate->format('Y-m-d');
            $chartData[$dateString] = $trends[$dateString] ?? 0;
            $currentDate->addDay();
        }

        return response()->json([
            'total_users' => $totalUsers,
            'status_counts' => $statusCounts,
            'role_counts' => $roleCounts,
            'trends' => [
                'registrations' => $chartData,
            ],
        ]);
    }
}
