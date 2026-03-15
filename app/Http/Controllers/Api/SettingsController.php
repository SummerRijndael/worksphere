<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function __construct(
        protected AppSettingsService $settingsService,
        protected \App\Services\AuditService $auditService,
        protected \App\Services\FileSecurityValidator $fileValidator
    ) {}

    /**
     * Get all settings with definitions.
     */
    public function index(): JsonResponse
    {
        $definitions = $this->settingsService->getDefinitions();
        $dbSettings = $this->settingsService->all();

        return response()->json([
            'data' => [
                'definitions' => $definitions,
                'stored' => $dbSettings,
            ],
        ]);
    }

    /**
     * Get a single setting.
     */
    public function show(string $key): JsonResponse
    {
        $value = $this->settingsService->get($key);

        // Don't expose sensitive values
        $setting = Setting::where('key', $key)->first();
        if ($setting?->is_sensitive) {
            $value = null;
        }

        return response()->json([
            'data' => [
                'key' => $key,
                'value' => $value,
            ],
        ]);
    }

    /**
     * Update multiple settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'present',
            'settings.*.type' => 'sometimes|string|in:string,boolean,integer,json',
            'settings.*.group' => 'sometimes|string',
            'settings.*.is_sensitive' => 'sometimes|boolean',
            'password' => 'sometimes|string',
            'reason' => 'sometimes|string',
        ]);

        $oldValues = [];
        $newValues = [];
        $criticalChanges = [];

        foreach ($validated['settings'] as $setting) {
            $key = $setting['key'];
            $value = $setting['value'];

            // Check if value actually changed
            $currentValue = $this->settingsService->get($key);
            if ($currentValue !== $value) {
                if ($this->settingsService->isCritical($key)) {
                    $criticalChanges[] = $key;
                }

                // Capture old value
                $oldValues[$key] = $currentValue;
                $newValues[$key] = $value;
            }

            $attributes = [
                'type' => $setting['type'] ?? 'string',
                'group' => $setting['group'] ?? 'general',
                'is_sensitive' => $setting['is_sensitive'] ?? false,
            ];

            // Defer saving until validation passes
        }

        // If any critical settings changed, require password and reason
        if (! empty($criticalChanges)) {
            if (! $request->filled('password') || ! $request->filled('reason')) {
                return response()->json([
                    'message' => 'Critical settings require password confirmation and a reason.',
                    'required_confirmation' => true,
                    'critical_keys' => $criticalChanges,
                ], 423); // Locked
            }

            if (! \Illuminate\Support\Facades\Hash::check($request->password, $request->user()->password)) {
                return response()->json([
                    'message' => 'Invalid password provided for confirmation.',
                ], 403);
            }
        }

        // Apply changes
        foreach ($validated['settings'] as $setting) {
            $this->settingsService->set($setting['key'], $setting['value'], [
                'type' => $setting['type'] ?? 'string',
                'group' => $setting['group'] ?? 'general',
                'is_sensitive' => $setting['is_sensitive'] ?? false,
            ]);
        }

        // Log the update
        if (! empty($newValues)) {
            $this->auditService->log(
                action: \App\Enums\AuditAction::SystemSettingUpdated,
                category: \App\Enums\AuditCategory::System,
                context: [
                    'changes_count' => count($newValues),
                    'critical_changes' => $criticalChanges,
                    'reason' => $request->input('reason'),
                ],
                oldValues: $oldValues,
                newValues: $newValues
            );
        }

        return response()->json([
            'message' => 'Settings updated successfully.',
            'data' => $this->settingsService->getDefinitions(),
        ]);
    }

    /**
     * Update a single setting.
     */
    public function updateSingle(Request $request, string $key): JsonResponse
    {
        $validated = $request->validate([
            'value' => 'present',
            'type' => 'sometimes|string|in:string,boolean,integer,json',
            'group' => 'sometimes|string',
            'is_sensitive' => 'sometimes|boolean',
        ]);

        $attributes = array_filter([
            'type' => $validated['type'] ?? null,
            'group' => $validated['group'] ?? null,
            'is_sensitive' => $validated['is_sensitive'] ?? null,
        ], fn ($v) => $v !== null);

        $this->settingsService->set($key, $validated['value'], $attributes);

        return response()->json([
            'message' => 'Setting updated successfully.',
            'data' => [
                'key' => $key,
                'value' => $this->settingsService->get($key),
            ],
        ]);
    }

    /**
     * Clear settings cache.
     */
    public function clearCache(): JsonResponse
    {
        $this->settingsService->clearCache();

        return response()->json([
            'message' => 'Settings cache cleared successfully.',
        ]);
    }

    /**
     * Test SMTP connection with provided settings.
     */
    public function testSmtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'required|array',
        ]);

        $settings = $validated['settings'];

        // Build config for test mailer
        $config = [
            'transport' => 'smtp',
            'host' => $settings['mail.host'] ?? config('mail.mailers.smtp.host'),
            'port' => $settings['mail.port'] ?? config('mail.mailers.smtp.port'),
            'encryption' => $settings['mail.encryption'] ?? config('mail.mailers.smtp.encryption'),
            'username' => $settings['mail.username'] ?? config('mail.mailers.smtp.username'),
            'password' => $settings['mail.password'] ?? config('mail.mailers.smtp.password'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ];

        // Retrieve current from address config as fallback
        $fromAddress = $settings['mail.from_address'] ?? config('mail.from.address');
        $fromName = $settings['mail.from_name'] ?? config('mail.from.name');

        // Dynamically set a temporary mailer config
        config(['mail.mailers.smtp_test' => $config]);

        // We need to set global from address for this request context if we want it used,
        // or just rely on the test email construction
        config(['mail.from.address' => $fromAddress]);
        config(['mail.from.name' => $fromName]);

        try {
            // Attempt to send a raw email using the test mailer
            \Illuminate\Support\Facades\Mail::mailer('smtp_test')->raw(
                'This is a test email from your application settings to verify SMTP configuration.',
                function ($message) use ($request, $fromAddress, $fromName) {
                    $message->to($request->user()->email)
                        ->subject('SMTP Connection Test')
                        ->from($fromAddress, $fromName);
                });

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Test IMAP connection with provided settings.
     */
    public function testImap(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'required|array',
        ]);

        $settings = $validated['settings'];

        // Build config for test IMAP
        $accountData = [
            'provider' => 'custom',
            'auth_type' => 'password',
            'email' => $settings['mail.from_address'] ?? 'system@example.com',
            'username' => $settings['mail.imap_username'] ?? $settings['mail.username'] ?? '',
            'password' => $settings['mail.imap_password'] ?? $settings['mail.password'] ?? '',
            'imap_host' => $settings['mail.imap_host'] ?? '',
            'imap_port' => (int) ($settings['mail.imap_port'] ?? 993),
            'imap_encryption' => $settings['mail.imap_encryption'] ?? 'ssl',
        ];

        // Create a temporary instance (not saved)
        $account = new \App\Models\EmailAccount($accountData);
        if (! empty($accountData['password'])) {
            $account->password = $accountData['password'];
        }

        try {
            $service = app(\App\Services\EmailAccountService::class);
            $result = $service->testImapConnection($account);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Upload application logo.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ]);

        $file = $request->file('logo');
        $this->fileValidator->validate($file);

        $path = $file->storeAs('branding', Str::uuid().'.'.$file->getClientOriginalExtension(), 'public');
        $url = \Illuminate\Support\Facades\Storage::disk('public')->url($path);

        $this->settingsService->set('app.logo', $url, ['group' => 'app', 'type' => 'string']);

        return response()->json([
            'message' => 'Logo uploaded successfully.',
            'url' => $url,
        ]);
    }

    /**
     * Upload application favicon.
     */
    public function uploadFavicon(Request $request): JsonResponse
    {
        $request->validate([
            'favicon' => ['required', 'file', 'mimes:png,jpg,jpeg,svg,webp,ico', 'max:1024'],
        ]);

        $file = $request->file('favicon');
        $this->fileValidator->validate($file);

        $path = $file->storeAs('branding', Str::uuid().'.'.$file->getClientOriginalExtension(), 'public');
        $url = \Illuminate\Support\Facades\Storage::disk('public')->url($path);

        $this->settingsService->set('app.favicon', $url, ['group' => 'app', 'type' => 'string']);

        return response()->json([
            'message' => 'Favicon uploaded successfully.',
            'url' => $url,
        ]);
    }

    /**
     * Upload application OpenGraph image.
     */
    public function uploadOpengraph(Request $request): JsonResponse
    {
        $request->validate([
            'opengraph' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:min_width=600,min_height=315'],
        ]);

        $file = $request->file('opengraph');
        $this->fileValidator->validate($file);

        $path = $file->storeAs('branding', Str::uuid().'.'.$file->getClientOriginalExtension(), 'public');
        $url = \Illuminate\Support\Facades\Storage::disk('public')->url($path);

        $this->settingsService->set('app.opengraph', $url, ['group' => 'app', 'type' => 'string']);

        return response()->json([
            'message' => 'OpenGraph image uploaded successfully.',
            'url' => $url,
        ]);
    }

    /**
     * Verify the secret phrase for Demo Mode access.
     */
    public function verifyDemoAccess(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $hash = config('app.demo_mode_secret_hash');

        if (empty($hash)) {
            // Fallback just in case, or fail closed
            return response()->json(['message' => 'Demo mode configuration error.'], 500);
        }

        if (\Illuminate\Support\Facades\Hash::check($request->password, $hash)) {
            return response()->json(['success' => true]);
        }

        return response()->json([
            'message' => 'Invalid secret phrase.',
            'success' => false,
        ], 403);
    }
}
