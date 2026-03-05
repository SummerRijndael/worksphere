<?php

namespace App\Contracts;

interface AnalyticsDriver
{
    /**
     * Track a page view or event.
     *
     * @param array $data
     * @return void
     */
    public function track(array $data): void;
    
    /**
     * Determine if this driver is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool;
}
