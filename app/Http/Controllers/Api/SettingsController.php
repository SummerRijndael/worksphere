<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        protected AppSettingsService $settingsService,
        protected \App\Services\AuditService $auditService
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
        ]);

        $oldValues = [];
        $newValues = [];

        foreach ($validated['settings'] as $setting) {
            $key = $setting['key'];
            $value = $setting['value'];

            // Capture old value
            $oldValues[$key] = $this->settingsService->get($key);
            $newValues[$key] = $value;

            $attributes = [
                'type' => $setting['type'] ?? 'string',
                'group' => $setting['group'] ?? 'general',
                'is_sensitive' => $setting['is_sensitive'] ?? false,
            ];

            $this->settingsService->set($key, $value, $attributes);
        }

        // Log the update
        if (! empty($newValues)) {
            $this->auditService->log(
                action: \App\Enums\AuditAction::SystemSettingUpdated,
                category: \App\Enums\AuditCategory::System,
                context: [
                    'changes_count' => count($newValues),
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
     * Upload application logo.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'max:2048', 'dimensions:min_width=100,min_height=100'],
        ]);

        $path = $request->file('logo')->store('branding', 'public');
        $url = \Illuminate\Support\Facades\Storage::url($path);

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
            'favicon' => ['required', 'image', 'max:1024', 'dimensions:max_width=512,max_height=512'],
        ]);

        $path = $request->file('favicon')->store('branding', 'public');
        $url = \Illuminate\Support\Facades\Storage::url($path);

        $this->settingsService->set('app.favicon', $url, ['group' => 'app', 'type' => 'string']);

        return response()->json([
            'message' => 'Favicon uploaded successfully.',
            'url' => $url,
        ]);
    }
}
