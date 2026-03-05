<?php

namespace App\Services\Analytics\Drivers;

use App\Contracts\AnalyticsDriver;
use App\Models\Setting;

class GoogleAnalyticsDriver implements AnalyticsDriver
{
    /**
     * @inheritDoc
     */
    public function track(array $data): void
    {
        // Google Analytics tracking is primary handled in the frontend via script tags.
        // However, we can also implement server-side Measurement Protocol here if needed.
        // For now, we just indicate this is a placeholder for potential server-side GA hits.
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(): bool
    {
        return (bool) Setting::getValue('analytics_ga_enabled', false);
    }
}
