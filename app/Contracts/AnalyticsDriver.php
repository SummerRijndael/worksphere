<?php

namespace App\Contracts;

interface AnalyticsDriver
{
    /**
     * Track a page view or event.
     */
    public function track(array $data): void;

    /**
     * Determine if this driver is enabled.
     */
    public function isEnabled(): bool;
}
