<?php

namespace App\Services\EmailAdapters;

use App\Contracts\EmailProviderAdapter;
use App\Models\EmailAccount;

/**
 * Factory for creating provider-specific email adapters.
 */
class AdapterFactory
{
    /**
     * Registered adapter classes by provider.
     */
    protected static array $adapters = [
        'gmail' => GmailAdapter::class,
        'outlook' => OutlookAdapter::class,
        'custom' => CustomImapAdapter::class,
    ];

    /**
     * Create an adapter for the given email account.
     */
    public static function make(EmailAccount $account): EmailProviderAdapter
    {
        return self::forProvider($account->provider);
    }

    /**
     * Create an adapter for a specific provider.
     */
    public static function forProvider(string $provider): EmailProviderAdapter
    {
        $adapterClass = self::$adapters[$provider] ?? self::$adapters['custom'];

        return new $adapterClass;
    }

    /**
     * Register a custom adapter for a provider.
     */
    public static function register(string $provider, string $adapterClass): void
    {
        self::$adapters[$provider] = $adapterClass;
    }

    /**
     * Get all registered providers.
     */
    public static function getProviders(): array
    {
        return array_keys(self::$adapters);
    }
}
