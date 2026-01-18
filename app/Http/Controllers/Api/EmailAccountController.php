<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailAccount;
use App\Services\EmailAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailAccountController extends Controller
{
    public function __construct(
        protected EmailAccountService $emailAccountService
    ) {}

    /**
     * List email accounts for the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mode = $request->query('mode', 'personal');

        if ($mode === 'system') {
            // Permission check: Must be Super Admin OR have explicit permission
            if (! $user->hasRole('Super Admin') && ! $user->hasPermissionTo('system.manage_email')) {
                return response()->json([
                    'message' => 'You do not have permission to view system email accounts.',
                ], 403);
            }

            $accounts = EmailAccount::where('is_system', true)
                ->orderBy('name')
                ->get();
        } else {
            $accounts = EmailAccount::where('is_system', false)
                ->where(function ($query) use ($user) {
                    $query->where('user_id', $user->id);

                    // Include team accounts if user has teams relationship
                    if (method_exists($user, 'teams')) {
                        $teamIds = $user->teams()->pluck('teams.id');
                        if ($teamIds->isNotEmpty()) {
                            $query->orWhereIn('team_id', $teamIds);
                        }
                    }
                })
                ->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get();
        }

        return response()->json([
            'data' => $accounts->map(fn ($account) => $this->formatAccount($account)),
        ]);
    }

    /**
     * Get a single email account.
     */
    public function show(EmailAccount $emailAccount): JsonResponse
    {
        $this->authorize('view', $emailAccount);

        return response()->json([
            'data' => $this->formatAccount($emailAccount),
        ]);
    }

    /**
     * Create a new email account with password auth.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'provider' => 'required|string|in:custom,gmail,outlook',
            'auth_type' => 'required|string|in:password,oauth',
            // Custom IMAP/SMTP settings
            'imap_host' => 'required_if:provider,custom|nullable|string|max:255',
            'imap_port' => 'nullable|integer|min:1|max:65535',
            'imap_encryption' => 'nullable|string|in:ssl,tls,none',
            'smtp_host' => 'required_if:provider,custom|nullable|string|max:255',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|string|in:ssl,tls,none',
            // Password auth
            'username' => 'nullable|string|max:255',
            'password' => 'required_if:auth_type,password|nullable|string',
            // Team assignment (for shared accounts)
            'team_id' => 'nullable|exists:teams,id',
            'is_system' => 'boolean',
        ]);

        // If team_id is provided, verify user belongs to team
        if (isset($validated['team_id'])) {
            $user = $request->user();
            $belongsToTeam = method_exists($user, 'teams')
                && $user->teams()->where('teams.id', $validated['team_id'])->exists();

            if (! $belongsToTeam) {
                return response()->json([
                    'message' => 'You do not belong to this team.',
                ], 403);
            }
        }

        $account = $this->emailAccountService->create($validated, $request->user());

        return response()->json([
            'message' => 'Email account created successfully.',
            'data' => $this->formatAccount($account),
        ], 201);
    }

    /**
     * Update an email account.
     */
    public function update(Request $request, EmailAccount $emailAccount): JsonResponse
    {
        $this->authorize('update', $emailAccount);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'imap_host' => 'nullable|string|max:255',
            'imap_port' => 'nullable|integer|min:1|max:65535',
            'imap_encryption' => 'nullable|string|in:ssl,tls,none',
            'smtp_host' => 'nullable|string|max:255',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|string|in:ssl,tls,none',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_system' => 'boolean',
        ]);

        // If setting as default, unset other defaults
        if ($validated['is_default'] ?? false) {
            EmailAccount::where('user_id', $emailAccount->user_id)
                ->where('id', '!=', $emailAccount->id)
                ->update(['is_default' => false]);
        }

        $emailAccount->update($validated);

        return response()->json([
            'message' => 'Email account updated successfully.',
            'data' => $this->formatAccount($emailAccount->fresh()),
        ]);
    }

    /**
     * Delete an email account.
     */
    public function destroy(EmailAccount $emailAccount): JsonResponse
    {
        $this->authorize('delete', $emailAccount);

        $emailAccount->delete();

        return response()->json([
            'message' => 'Email account deleted successfully.',
        ]);
    }

    /**
     * Test connection for an email account.
     */
    public function testConnection(EmailAccount $emailAccount): JsonResponse
    {
        $this->authorize('update', $emailAccount);

        $result = $this->emailAccountService->testConnection($emailAccount);

        return response()->json($result);
    }

    /**
     * Trigger manual sync for an email account.
     */
    public function sync(EmailAccount $emailAccount): JsonResponse
    {
        $this->authorize('update', $emailAccount);

        $syncService = app(\App\Contracts\EmailSyncServiceContract::class);

        // If never synced or partially seeded, restart/continue seed
        if ($emailAccount->sync_status === \App\Enums\EmailSyncStatus::Pending ||
            $emailAccount->sync_status === \App\Enums\EmailSyncStatus::Failed) {
            $syncService->startSeed($emailAccount);
            $message = 'Initial sync started.';
        } elseif ($emailAccount->sync_status === \App\Enums\EmailSyncStatus::Seeding) {
            // If stuck in seeding, maybe just ensure job is dispatched?
            // For now, let's just say it's in progress.
            // Or we could force re-dispatch of seed job.
            \App\Jobs\SeedEmailAccountJob::dispatch($emailAccount->id);
            $message = 'Sync continued.';
        } else {
            // Completed or Syncing -> Trigger incremental
            $syncService->fetchNewEmails($emailAccount);
            $message = 'Sync triggered.';
        }

        return response()->json([
            'message' => $message,
            'status' => $emailAccount->fresh()->sync_status,
        ]);
    }

    /**
     * Test connection with provided configuration (without saving).
     */
    public function testConfiguration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|string|in:custom,gmail,outlook',
            'auth_type' => 'required|string|in:password,oauth',
            'email' => 'required|email',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'smtp_host' => 'required|string',
            'smtp_port' => 'required|integer',
            'smtp_encryption' => 'required|string|in:ssl,tls,none',
            'access_token' => 'nullable|string',
        ]);

        // Create a temporary instance (not saved)
        $account = new EmailAccount($validated);
        // Manually set password/token attributes since they are protected/encrypted accessors
        if (! empty($validated['password'])) {
            $account->password = $validated['password'];
        }
        if (! empty($validated['access_token'])) {
            $account->access_token = $validated['access_token'];
        }

        $result = $this->emailAccountService->testConnection($account);

        return response()->json($result);
    }

    /**
     * Get available providers.
     */
    public function providers(): JsonResponse
    {
        $providers = [];
        foreach (EmailAccount::PROVIDERS as $key => $config) {
            $providers[] = [
                'id' => $key,
                'name' => $config['name'],
                'supports_oauth' => $config['supports_oauth'],
                'imap_host' => $config['imap_host'] ?? null,
                'smtp_host' => $config['smtp_host'] ?? null,
            ];
        }

        return response()->json(['data' => $providers]);
    }

    /**
     * Format account for API response.
     */
    protected function formatAccount(EmailAccount $account): array
    {
        return [
            'id' => $account->public_id,
            'name' => $account->name,
            'email' => $account->email,
            'provider' => $account->provider,
            'auth_type' => $account->auth_type,
            'imap_host' => $account->imap_host,
            'imap_port' => $account->imap_port,
            'imap_encryption' => $account->imap_encryption,
            'smtp_host' => $account->smtp_host,
            'smtp_port' => $account->smtp_port,
            'smtp_encryption' => $account->smtp_encryption,
            'username' => $account->username,
            'is_active' => $account->is_active,
            'is_verified' => $account->is_verified,
            'is_default' => $account->is_default,
            'is_system' => $account->is_system,
            'is_personal' => $account->isPersonal(),
            'is_shared' => $account->isShared(),
            'last_used_at' => $account->last_used_at?->toIso8601String(),
            'last_error' => $account->last_error,
            'sync_status' => $account->sync_status,
            'sync_error' => $account->sync_error,
            'needs_reauth' => $account->needs_reauth,
            'storage_used' => $account->storage_used,
            'storage_limit' => $account->storage_limit,
            'storage_updated_at' => $account->storage_updated_at?->toIso8601String(),
            'created_at' => $account->created_at->toIso8601String(),
        ];
    }
}
