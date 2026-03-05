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
    /**
     * Settings that require password confirmation to change.
     */
    protected array $criticalSettings = [
        'app.url',
        'app.is_demo_mode',
        'auth.registration_enabled',
        'auth.email_verification',
        'auth.social_login_enabled',
        'mail.host',
        'mail.username',
        'mail.password',
        'mail.imap_host',
        'mail.imap_username',
        'mail.imap_password',
        'recaptcha.site_key',
        'recaptcha.secret_key',
        'google.client_id',
        'google.client_secret',
        'github.client_id',
        'github.client_secret',
        'twilio.sid',
        'twilio.auth_token',
        'twilio.verify_sid',
        'openai.api_key',
        'analytics_ga_measurement_id',
    ];

    /**
     * Mapping of setting keys to config keys.
     */
    protected array $envMappings = [
        // Application
        'app.name' => ['config' => 'app.name'],
        'app.url' => ['config' => 'app.url'],
        'app.timezone' => ['config' => 'app.timezone'],
        'app.locale' => ['config' => 'app.locale'],
        'app.is_demo_mode' => ['config' => 'app.is_demo_mode', 'default' => false],
        // Security
        'auth.registration_enabled' => ['config' => 'auth.registration_enabled', 'default' => true],
        'auth.email_verification' => ['config' => 'auth.email_verification', 'default' => true],
        'session.lifetime' => ['config' => 'session.lifetime'],
        // Mail (System Default Sender)
        'mail.from_address' => ['config' => 'mail.from.address'],
        'mail.from_name' => ['config' => 'mail.from.name'],
        'mail.host' => ['config' => 'mail.mailers.smtp.host'],
        'mail.port' => ['config' => 'mail.mailers.smtp.port'],
        'mail.username' => ['config' => 'mail.mailers.smtp.username'],
        'mail.password' => ['config' => 'mail.mailers.smtp.password'],
        'mail.encryption' => ['config' => 'mail.mailers.smtp.encryption'],
        // IMAP (System Default Receiver)
        'mail.imap_host' => ['config' => 'email.imap_defaults.host'],
        'mail.imap_port' => ['config' => 'email.imap_defaults.port'],
        'mail.imap_username' => ['config' => 'email.imap_defaults.username'],
        'mail.imap_password' => ['config' => 'email.imap_defaults.password'],
        'mail.imap_encryption' => ['config' => 'email.imap_defaults.encryption'],
        // reCAPTCHA
        'recaptcha.site_key' => ['config' => 'recaptcha.site_key'],
        'recaptcha.secret_key' => ['config' => 'recaptcha.secret_key'],
        // Google OAuth
        'google.client_id' => ['config' => 'services.google.client_id'],
        'google.client_secret' => ['config' => 'services.google.client_secret'],
        // GitHub OAuth
        'github.client_id' => ['config' => 'services.github.client_id'],
        'github.client_secret' => ['config' => 'services.github.client_secret'],
        // Social Control
        'auth.social_login_enabled' => ['config' => 'auth.social_login_enabled', 'default' => true],
        // Twilio
        'twilio.sid' => ['config' => 'services.twilio.sid'],
        'twilio.auth_token' => ['config' => 'services.twilio.token'],
        'twilio.verify_sid' => ['config' => 'services.twilio.verify_sid'],
        // OpenAI
        'openai.api_key' => ['config' => 'services.openai.api_key'],
        'openai.organization' => ['config' => 'services.openai.organization'],
        // Storage
        'storage.max_team_storage' => ['config' => 'storage.max_team_storage', 'default' => 1024], // MB
        // Team Lifecycle
        'teams.max_owned' => ['config' => 'teams.limits.max_teams_owned', 'default' => 5],
        'teams.max_joined' => ['config' => 'teams.limits.max_teams_joined', 'default' => 20],
        'teams.dormant_days' => ['config' => 'teams.health.dormant_after_days', 'default' => 90],
        'teams.deletion_grace_days' => ['config' => 'teams.health.deletion_grace_days', 'default' => 30],
        'teams.auto_delete' => ['config' => 'teams.health.auto_delete_enabled', 'default' => false],
        'teams.require_approval' => ['config' => 'teams.limits.require_approval', 'default' => false],
        // Contact Information
        'contact.support' => ['config' => 'contact.support'],
        'contact.legal' => ['config' => 'contact.legal'],
        'contact.dmca' => ['config' => 'contact.dmca'],
        'contact.privacy' => ['config' => 'contact.privacy'],
        // Social Media Links
        'contact.social.twitter' => ['config' => 'contact.social.twitter', 'default' => 'https://twitter.com'],
        'contact.social.github' => ['config' => 'contact.social.github', 'default' => 'https://github.com'],
        'contact.social.linkedin' => ['config' => 'contact.social.linkedin', 'default' => 'https://linkedin.com'],
        // Analytics
        'analytics_ga_enabled' => ['config' => 'analytics.ga_enabled', 'default' => false],
        'analytics_ga_measurement_id' => ['config' => 'analytics.ga_measurement_id'],
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

        // Fall back to config
        return $this->getConfigFallback($key, $default);
    }

    /**
     * Get list of critical settings.
     */
    public function getCriticalSettings(): array
    {
        return $this->criticalSettings;
    }

    /**
     * Check if a key is critical.
     */
    public function isCritical(string $key): bool
    {
        return in_array($key, $this->criticalSettings);
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
        $settings = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Setting::all()
                ->pluck('value', 'key')
                ->toArray();
        });

        return is_array($settings) ? $settings : [];
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
     * Get the fallback value from config.
     */
    protected function getConfigFallback(string $key, mixed $default = null): mixed
    {
        $mapping = $this->envMappings[$key] ?? null;

        if (! $mapping) {
            return $default;
        }

        // Try config
        if (isset($mapping['config'])) {
            $value = config($mapping['config']);
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
                [
                    'key' => 'app.logo',
                    'label' => 'Logo',
                    'type' => 'string',
                    'value' => $this->get('app.logo'),
                    'description' => 'Application Logo URL',
                ],
                [
                    'key' => 'app.favicon',
                    'label' => 'Favicon',
                    'type' => 'string',
                    'value' => $this->get('app.favicon'),
                    'description' => 'Application Favicon URL',
                ],
                [
                    'value' => $this->get('app.opengraph'),
                    'description' => 'Social Share Image URL',
                ],
                [
                    'key' => 'app.is_demo_mode',
                    'label' => 'Demo Mode',
                    'type' => 'boolean',
                    'value' => $this->get('app.is_demo_mode', false),
                    'description' => 'Enable Demo Mode (restricts destructive actions)',
                ],
                [
                    'key' => 'features.public_pricing_page.enabled',
                    'label' => 'Public Pricing Page',
                    'type' => 'boolean',
                    'value' => $this->get('features.public_pricing_page.enabled', true),
                    'description' => 'Show or hide the public-facing pricing page/section',
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
                    'label' => 'SMTP Encryption',
                    'type' => 'string',
                    'value' => $this->get('mail.encryption'),
                    'description' => 'tls / ssl / null',
                ],
                [
                    'key' => 'mail.imap_host',
                    'label' => 'IMAP Host',
                    'type' => 'string',
                    'value' => $this->get('mail.imap_host'),
                    'description' => 'IMAP server hostname',
                ],
                [
                    'key' => 'mail.imap_port',
                    'label' => 'IMAP Port',
                    'type' => 'string',
                    'value' => $this->get('mail.imap_port'),
                    'description' => 'IMAP server port',
                ],
                [
                    'key' => 'mail.imap_username',
                    'label' => 'IMAP Username',
                    'type' => 'string',
                    'value' => $this->get('mail.imap_username'),
                    'description' => 'IMAP username',
                ],
                [
                    'key' => 'mail.imap_password',
                    'label' => 'IMAP Password',
                    'type' => 'string',
                    'value' => null,
                    'description' => 'IMAP password (encrypted)',
                    'is_sensitive' => true,
                ],
                [
                    'key' => 'mail.imap_encryption',
                    'label' => 'IMAP Encryption',
                    'type' => 'string',
                    'value' => $this->get('mail.imap_encryption'),
                    'description' => 'tls / ssl / null',
                ],
            ],
            'contact' => [
                [
                    'key' => 'contact.support',
                    'label' => 'Support Email',
                    'type' => 'string',
                    'value' => $this->get('contact.support'),
                    'description' => 'Support email address',
                ],
                [
                    'key' => 'contact.legal',
                    'label' => 'Legal Email',
                    'type' => 'string',
                    'value' => $this->get('contact.legal'),
                    'description' => 'Legal inquiries email',
                ],
                [
                    'key' => 'contact.dmca',
                    'label' => 'DMCA / Copyright Email',
                    'type' => 'string',
                    'value' => $this->get('contact.dmca'),
                    'description' => 'DMCA inquiries email',
                ],
                [
                    'key' => 'contact.privacy',
                    'label' => 'Privacy Officer Email',
                    'type' => 'string',
                    'value' => $this->get('contact.privacy'),
                    'description' => 'Privacy officer email',
                ],
                [
                    'key' => 'contact.social.twitter',
                    'label' => 'Twitter/X Profile URL',
                    'type' => 'string',
                    'value' => $this->get('contact.social.twitter', 'https://twitter.com'),
                    'description' => 'Link to your Twitter/X profile',
                ],
                [
                    'key' => 'contact.social.github',
                    'label' => 'GitHub Profile URL',
                    'type' => 'string',
                    'value' => $this->get('contact.social.github', 'https://github.com'),
                    'description' => 'Link to your GitHub profile',
                ],
                [
                    'key' => 'contact.social.linkedin',
                    'label' => 'LinkedIn Profile URL',
                    'type' => 'string',
                    'value' => $this->get('contact.social.linkedin', 'https://linkedin.com'),
                    'description' => 'Link to your LinkedIn profile',
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
            'teams' => [
                [
                    'key' => 'teams.max_owned',
                    'label' => 'Max Teams Owned',
                    'type' => 'integer',
                    'value' => $this->get('teams.max_owned', 5),
                    'description' => 'Maximum number of teams a user can own',
                ],
                [
                    'key' => 'teams.max_joined',
                    'label' => 'Max Teams Joined',
                    'type' => 'integer',
                    'value' => $this->get('teams.max_joined', 20),
                    'description' => 'Maximum number of teams a user can join',
                ],
                [
                    'key' => 'teams.dormant_days',
                    'label' => 'Days Until Dormant',
                    'type' => 'integer',
                    'value' => $this->get('teams.dormant_days', 90),
                    'description' => 'Days without activity before team is marked dormant',
                ],
                [
                    'key' => 'teams.deletion_grace_days',
                    'label' => 'Deletion Grace Period',
                    'type' => 'integer',
                    'value' => $this->get('teams.deletion_grace_days', 30),
                    'description' => 'Days after dormant warning before deletion',
                ],
                [
                    'key' => 'teams.auto_delete',
                    'label' => 'Auto-Delete Teams',
                    'type' => 'boolean',
                    'value' => $this->get('teams.auto_delete', false),
                    'description' => 'Automatically delete teams after grace period',
                ],
                [
                    'key' => 'teams.require_approval',
                    'label' => 'Require Team Creation Approval',
                    'type' => 'boolean',
                    'value' => $this->get('teams.require_approval', false),
                    'description' => 'Require admin approval for new team creation requests',
                ],
            ],
            'tickets' => [
                // SLA Enable/Disable
                [
                    'key' => 'tickets.sla.enabled',
                    'label' => 'Enable SLA',
                    'type' => 'boolean',
                    'value' => $this->get('tickets.sla.enabled', true),
                    'description' => 'Enable Service Level Agreement (SLA) tracking',
                ],
                // Business Hours
                [
                    'key' => 'tickets.sla.business_hours_enabled',
                    'label' => 'Enable Business Hours',
                    'type' => 'boolean',
                    'value' => $this->get('tickets.sla.business_hours_enabled', false),
                    'description' => 'Calculate SLA based on business hours only',
                ],
                [
                    'key' => 'tickets.sla.business_hours_start',
                    'label' => 'Business Day Start',
                    'type' => 'string',
                    'value' => $this->get('tickets.sla.business_hours_start', '09:00'),
                    'description' => 'Start time of business day (HH:mm)',
                ],
                [
                    'key' => 'tickets.sla.business_hours_end',
                    'label' => 'Business Day End',
                    'type' => 'string',
                    'value' => $this->get('tickets.sla.business_hours_end', '17:00'),
                    'description' => 'End time of business day (HH:mm)',
                ],
                [
                    'key' => 'tickets.sla.business_days',
                    'label' => 'Business Days',
                    'type' => 'json',
                    'value' => $this->get('tickets.sla.business_days', [1, 2, 3, 4, 5]),
                    'description' => 'Days of week considered business days (1=Mon)',
                ],
                // Holidays
                [
                    'key' => 'tickets.sla.holiday_country',
                    'label' => 'Holiday Country',
                    'type' => 'string',
                    'value' => $this->get('tickets.sla.holiday_country', 'US'),
                    'description' => 'Country code for holiday calendar',
                ],
                [
                    'key' => 'tickets.sla.exclude_holidays',
                    'label' => 'Exclude Holidays',
                    'type' => 'boolean',
                    'value' => $this->get('tickets.sla.exclude_holidays', false),
                    'description' => 'Exclude public holidays from SLA calculation',
                ],
                // Warning Threshold
                [
                    'key' => 'tickets.sla.warning_threshold',
                    'label' => 'Warning Threshold (%)',
                    'type' => 'integer',
                    'value' => $this->get('tickets.sla.warning_threshold', 80),
                    'description' => 'Percentage of SLA time elapsed to trigger warning',
                ],
                // Default Response SLA
                [
                    'key' => 'tickets.sla.default_response_hours.critical',
                    'label' => 'Critical Response SLA',
                    'type' => 'integer',
                    'value' => $this->get('tickets.sla.default_response_hours.critical', 1),
                    'description' => 'Default response time for Critical priority (hours)',
                ],
                [
                    'key' => 'tickets.sla.default_response_hours.high',
                    'label' => 'High Response SLA',
                    'type' => 'integer',
                    'value' => $this->get('tickets.sla.default_response_hours.high', 2),
                    'description' => 'Default response time for High priority (hours)',
                ],
                [
                    'key' => 'tickets.sla.default_response_hours.medium',
                    'label' => 'Medium Response SLA',
                    'type' => 'integer',
                    'value' => $this->get('tickets.sla.default_response_hours.medium', 4),
                    'description' => 'Default response time for Medium priority (hours)',
                ],
                [
                    'key' => 'tickets.sla.default_response_hours.low',
                    'label' => 'Low Response SLA',
                    'type' => 'integer',
                    'value' => $this->get('tickets.sla.default_response_hours.low', 8),
                    'description' => 'Default response time for Low priority (hours)',
                ],
                // Default Resolution SLA
                [
                    'key' => 'tickets.sla.default_resolution_hours.critical',
                    'label' => 'Critical Resolution SLA',
                    'type' => 'integer',
                    'value' => $this->get('tickets.sla.default_resolution_hours.critical', 4),
                    'description' => 'Default resolution time for Critical priority (hours)',
                ],
                [
                    'key' => 'tickets.sla.default_resolution_hours.high',
                    'label' => 'High Resolution SLA',
                    'type' => 'integer',
                    'value' => $this->get('tickets.sla.default_resolution_hours.high', 8),
                    'description' => 'Default resolution time for High priority (hours)',
                ],
                [
                    'key' => 'tickets.sla.default_resolution_hours.medium',
                    'label' => 'Medium Resolution SLA',
                    'type' => 'integer',
                    'value' => $this->get('tickets.sla.default_resolution_hours.medium', 24),
                    'description' => 'Default resolution time for Medium priority (hours)',
                ],
                [
                    'key' => 'tickets.sla.default_resolution_hours.low',
                    'label' => 'Low Resolution SLA',
                    'type' => 'integer',
                    'value' => $this->get('tickets.sla.default_resolution_hours.low', 48),
                    'description' => 'Default resolution time for Low priority (hours)',
                ],
            ],
            'analytics' => [
                [
                    'key' => 'analytics_ga_enabled',
                    'label' => 'Enable Google Analytics',
                    'type' => 'boolean',
                    'value' => $this->get('analytics_ga_enabled', false),
                    'description' => 'Enable or disable Google Analytics 4 tracking',
                ],
                [
                    'key' => 'analytics_ga_measurement_id',
                    'label' => 'Measurement ID',
                    'type' => 'string',
                    'value' => $this->get('analytics_ga_measurement_id'),
                    'description' => 'Your GA4 Measurement ID (G-XXXXXXXXXX)',
                ],
            ],
        ];
    }
}
