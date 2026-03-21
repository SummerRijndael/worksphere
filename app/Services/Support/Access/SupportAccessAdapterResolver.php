<?php

namespace App\Services\Support\Access;

use App\Contracts\SupportAccessAdapterContract;

class SupportAccessAdapterResolver
{
    public function __construct(
        protected LegacySupportAccessAdapter $legacyAdapter,
        protected SkillBasedSupportAccessAdapter $skillBasedAdapter
    ) {}

    public function resolve(): SupportAccessAdapterContract
    {
        $configured = (string) config('support_chat.access_adapter', 'legacy');
        $skillsEnabled = (bool) config('support_chat.skills.enabled', false);

        if ($configured === 'skills' && $skillsEnabled) {
            return $this->skillBasedAdapter;
        }

        return $this->legacyAdapter;
    }
}

