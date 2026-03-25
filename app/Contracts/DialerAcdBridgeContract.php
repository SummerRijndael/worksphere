<?php

namespace App\Contracts;

use App\Models\User;

interface DialerAcdBridgeContract
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function prepareOutboundContext(User $user, array $payload = []): array;

    /**
     * @return array<string, mixed>
     */
    public function describe(): array;
}
