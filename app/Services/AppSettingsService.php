<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class AppSettingsService
{
    /**
     * Cache key for settings.
     */
    protected const CACHE_KEY = 'app_settings';

    /**
     * Cache TTL in seconds (1 hour).
     */
    protected const CACHE_TTL = 3600;

    /**
     * Mapping of setting keys to env/config keys.
     */
    protected array $envMappings = [
        // Application
        'app.name' => ['env' => 'APP_NAME', 'config' => 'app.name'],
        'app.url' => ['env' => 'APP_URL', 'config' => 'app.url'],
        'app.timezone' => ['env' => 'APP_TIMEZONE', 'config' => 'app.timezone'],
        'app.locale' => ['env' => 'APP_LOCALE', 'config' => 'app.locale'],
        // Security
        'auth.registration_enabled' => ['config' => 'auth.registration_enabled', 'default' => true],
        'auth.email_verification' => ['config' => 'auth.email_verification', 'default' => true],
        'session.lifetime' => ['env' => 'SESSION_LIFETIME', 'config' => 'session.lifetime'],
        // Mail (System Default Sender)
        'mail.from_address' => ['env' => 'MAIL_FROM_ADDRESS', 'config' => 'mail.from.address'],
        'mail.from_name' => ['env' => 'MAIL_FROM_NAME', 'config' => 'mail.from.name'],
        'mail.host' => ['env' => 'MAIL_HOST', 'config' => 'mail.mailers.smtp.host'],
        'mail.port' => ['env' => 'MAIL_PORT', 'config' => 'mail.mailers.smtp.port'],
        'mail.username' => ['env' => 'MAIL_USERNAME', 'config' => 'mail.mailers.smtp.username'],
        'mail.password' => ['env' => 'MAIL_PASSWORD', 'config' => 'mail.mailers.smtp.password'],
        'mail.encryption' => ['env' => 'MAIL_ENCRYPTION', 'config' => 'mail.mailers.smtp.encryption'],
        // reCAPTCHA
        'recaptcha.site_key' => ['env' => 'RECAPTCHA_V3_SITE_KEY'],
        'recaptcha.secret_key' => ['env' => 'RECAPTCHA_V3_SECRET_KEY'],
        // Google OAuth
        'google.client_id' => ['env' => 'GOOGLE_CLIENT_ID', 'config' => 'services.google.client_id'],
        'google.client_secret' => ['env' => 'GOOGLE_CLIENT_SECRET', 'config' => 'services.google.client_secret'],
        // GitHub OAuth
        'github.client_id' => ['env' => 'GITHUB_CLIENT_ID', 'config' => 'services.github.client_id'],
        'github.client_secret' => ['env' => 'GITHUB_CLIENT_SECRET', 'config' => 'services.github.client_secret'],
        // Social Control
        'auth.social_login_enabled' => ['config' => 'auth.social_login_enabled', 'default' => true],
        // Twilio
        'twilio.sid' => ['env' => 'TWILIO_SID'],
        'twilio.auth_token' => ['env' => 'TWILIO_AUTH_TOKEN'],
        'twilio.verify_sid' => ['env' => 'TWILIO_VERIFY_SERVICE_SID'],
        // OpenAI
        'openai.api_key' => ['env' => 'OPENAI_API_KEY'],
        'openai.organization' => ['env' => 'OPENAI_ORGANIZATION'],
        'openai.organization' => ['env' => 'OPENAI_ORGANIZATION'],
        // Storage
        'storage.max_team_storage' => ['config' => 'storage.max_team_storage', 'default' => 1024], // MB
    ];

    /**
     * Get a setting value with env/config fallback.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // First check database
        $settings = $this->getAllCached();

        if (isset($settings[$key])) {
            return $settings[$key];
        }

        // Fall back to env/config
        return $this->getEnvFallback($key, $default);
    }

    /**
     * Set a setting value.
     */
    public function set(string $key, mixed $value, array $attributes = []): Setting
    {
        $setting = Setting::setValue($key, $value, $attributes);
        $this->clearCache();

        return $setting;
    }

    /**
     * Set multiple settings at once.
     */
    public function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            if (is_array($value) && isset($value['value'])) {
                Setting::setValue($key, $value['value'], $value);
            } else {
                Setting::setValue($key, $value);
            }
        }

        $this->clearCache();
    }

    /**
     * Get all settings, grouped.
     */
    public function all(): array
    {
        $dbSettings = Setting::all();
        $grouped = [];

        foreach ($dbSettings as $setting) {
            $grouped[$setting->group][$setting->key] = [
                'value' => $setting->value,
                'type' => $setting->type,
                'description' => $setting->description,
                'is_sensitive' => $setting->is_sensitive,
            ];
        }

        return $grouped;
    }

    /**
     * Get all settings as flat key-value pairs.
     */
    public function getAllCached(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Setting::all()
                ->pluck('value', 'key')
                ->toArray();
        });
    }

    /**
     * Get settings for a specific group.
     */
    public function getGroup(string $group): array
    {
        return Setting::group($group)
            ->get()
            ->mapWithKeys(fn ($s) => [$s->key => $s->value])
            ->toArray();
    }

    /**
     * Delete a setting.
     */
    public function delete(string $key): bool
    {
        $deleted = Setting::where('key', $key)->delete() > 0;
        $this->clearCache();

        return $deleted;
    }

    /**
     * Clear the settings cache.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Get the fallback value from env/config.
     */
    protected function getEnvFallback(string $key, mixed $default = null): mixed
    {
        $mapping = $this->envMappings[$key] ?? null;

        if (! $mapping) {
            return $default;
        }

        // Try config first (it already reads from env)
        if (isset($mapping['config'])) {
            $value = config($mapping['config']);
            if ($value !== null) {
                return $value;
            }
        }

        // Try env directly
        if (isset($mapping['env'])) {
            $value = env($mapping['env']);
            if ($value !== null) {
                return $value;
            }
        }

        // Return mapping default or provided default
        return $mapping['default'] ?? $default;
    }

    /**
     * Get all env mappings for UI display.
     */
    public function getEnvMappings(): array
    {
        return $this->envMappings;
    }

    /**
     * Apply database settings to Laravel config at runtime.
     */
    public function applyToConfig(): void
    {
        $settings = $this->getAllCached();

        foreach ($settings as $key => $value) {
            $mapping = $this->envMappings[$key] ?? null;

            if ($mapping && isset($mapping['config']) && $value !== null) {
                config([$mapping['config'] => $value]);
            }
        }
    }

    /**
     * Get setting definitions for the UI (with current values).
     */
    public function getDefinitions(): array
    {
        return [
            'general' => [
                [
                    'key' => 'app.name',
                    'label' => 'Application Name',
                    'type' => 'string',
                    'value' => $this->get('app.name', 'Laravel'),
                    'description' => 'The name of your application',
                ],
                [
                    'key' => 'app.url',
                    'label' => 'Application URL',
                    'type' => 'string',
                    'value' => $this->get('app.url', 'http://localhost'),
                    'description' => 'The URL of your application',
                ],
                [
                    'key' => 'app.timezone',
                    'label' => 'Timezone',
                    'type' => 'string',
                    'value' => $this->get('app.timezone', 'UTC'),
                    'description' => 'Default timezone for the application',
                ],
            ],
            'security' => [
                [
                    'key' => 'auth.registration_enabled',
                    'label' => 'User Registration',
                    'type' => 'boolean',
                    'value' => $this->get('auth.registration_enabled', true),
                    'description' => 'Allow new users to register',
                ],
                [
                    'key' => 'auth.email_verification',
                    'label' => 'Email Verification',
                    'type' => 'boolean',
                    'value' => $this->get('auth.email_verification', true),
                    'description' => 'Require email verification for new accounts',
                ],
                [
                    'key' => 'session.lifetime',
                    'label' => 'Session Lifetime',
                    'type' => 'integer',
                    'value' => $this->get('session.lifetime', 120),
                    'description' => 'Session timeout in minutes',
                ],
            ],
            'mail' => [
                [
                    'key' => 'mail.from_address',
                    'label' => 'From Address',
                    'type' => 'string',
                    'value' => $this->get('mail.from_address'),
                    'description' => 'Default sender email address',
                ],
                [
                    'key' => 'mail.from_name',
                    'label' => 'From Name',
                    'type' => 'string',
                    'value' => $this->get('mail.from_name'),
                    'description' => 'Default sender name',
                ],
                [
                    'key' => 'mail.host',
                    'label' => 'SMTP Host',
                    'type' => 'string',
                    'value' => $this->get('mail.host'),
                    'description' => 'SMTP server hostname',
                    'is_sensitive' => false,
                ],
                [
                    'key' => 'mail.port',
                    'label' => 'SMTP Port',
                    'type' => 'string',
                    'value' => $this->get('mail.port'),
                    'description' => 'SMTP server port',
                ],
                [
                    'key' => 'mail.username',
                    'label' => 'SMTP Username',
                    'type' => 'string',
                    'value' => $this->get('mail.username'),
                    'description' => 'SMTP username',
                    'is_sensitive' => false,
                ],
                [
                    'key' => 'mail.password',
                    'label' => 'SMTP Password',
                    'type' => 'string',
                    'value' => null, // Never return password
                    'description' => 'SMTP password (encrypted)',
                    'is_sensitive' => true,
                ],
                [
                    'key' => 'mail.encryption',
                    'label' => 'Encryption',
                    'type' => 'string',
                    'value' => $this->get('mail.encryption'),
                    'description' => 'tls / ssl / null',
                ],
            ],
            'social' => [
                [
                    'key' => 'auth.social_login_enabled',
                    'label' => 'Enable Social Login',
                    'type' => 'boolean',
                    'value' => $this->get('auth.social_login_enabled', true),
                    'description' => 'Enable or disable social login globally',
                ],
                [
                    'key' => 'google.client_id',
                    'label' => 'Google Client ID',
                    'type' => 'string',
                    'value' => $this->get('google.client_id'),
                    'description' => 'Client ID for Google OAuth',
                    'is_sensitive' => true,
                ],
                [
                    'key' => 'google.client_secret',
                    'label' => 'Google Client Secret',
                    'type' => 'string',
                    'value' => null,
                    'description' => 'Client Secret for Google OAuth',
                    'is_sensitive' => true,
                ],
                [
                    'key' => 'github.client_id',
                    'label' => 'GitHub Client ID',
                    'type' => 'string',
                    'value' => $this->get('github.client_id'),
                    'description' => 'Client ID for GitHub OAuth',
                    'is_sensitive' => true,
                ],
                [
                    'key' => 'github.client_secret',
                    'label' => 'GitHub Client Secret',
                    'type' => 'string',
                    'value' => null,
                    'description' => 'Client Secret for GitHub OAuth',
                    'is_sensitive' => true,
                    'is_sensitive' => true,
                ],
            ],
            'storage' => [
                [
                    'key' => 'storage.max_team_storage',
                    'label' => 'Max Team Storage (MB)',
                    'type' => 'integer',
                    'value' => $this->get('storage.max_team_storage', 1024),
                    'description' => 'Maximum storage per team in Megabytes (MB)',
                ],
            ],
        ];
    }
}
