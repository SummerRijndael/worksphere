<?php

namespace App\Contracts;

use App\Models\DialerCall;
use App\Models\User;

interface DialerAdapterContract
{
    public function key(): string;

    public function label(): string;

    /**
     * @return array<string, mixed>
     */
    public function health(): array;

    /**
     * @return array<string, mixed>
     */
    public function capabilities(): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function placeCall(DialerCall $call, User $user, array $payload = []): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function hangupCall(DialerCall $call, User $user, array $payload = []): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function transferCall(DialerCall $call, User $user, string $targetNumber, array $payload = []): array;
}
