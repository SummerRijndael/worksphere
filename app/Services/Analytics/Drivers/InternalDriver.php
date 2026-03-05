<?php

namespace App\Services\Analytics\Drivers;

use App\Contracts\AnalyticsDriver;
use App\Models\PageView;

class InternalDriver implements AnalyticsDriver
{
    /**
     * @inheritDoc
     */
    public function track(array $data): void
    {
        PageView::create($data);
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(): bool
    {
        return true; // Internal tracking is always enabled
    }
}
