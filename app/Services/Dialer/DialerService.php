<?php

namespace App\Services\Dialer;

use App\Contracts\DialerAcdBridgeContract;
use App\Enums\DialerCallStatus;
use App\Models\DialerCall;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DialerService
{
    public function __construct(
        protected DialerAdapterManager $adapterManager,
        protected DialerAcdBridgeContract $acdBridge,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(User $user): array
    {
        $adapter = $this->adapterManager->default();

        return [
            'adapter' => [
                'key' => $adapter->key(),
                'label' => $adapter->label(),
                ...$adapter->health(),
                'capabilities' => $adapter->capabilities(),
            ],
            'acd_pipe' => $this->acdBridge->describe(),
            'active_call' => $this->activeCallFor($user),
            'recent_calls' => $this->recentCallsFor($user, (int) config('dialer.history_limit', 15)),
            'composer' => [
                'input_hint' => 'Use international format, for example +639171234567',
                'caller_id' => $adapter->health()['caller_id'] ?? null,
            ],
        ];
    }

    public function dial(User $user, string $toNumber, array $payload = []): DialerCall
    {
        $adapter = $this->adapterManager->default();
        $normalizedTo = $this->normalizePhoneNumber($toNumber);

        if ($normalizedTo === null) {
            throw new InvalidArgumentException('Enter a valid phone number in international format.');
        }

        if ($existing = $this->activeCallForModel($user)) {
            throw new InvalidArgumentException('Finish the current outbound call before starting another one.');
        }

        return DB::transaction(function () use ($adapter, $normalizedTo, $payload, $user) {
            $call = DialerCall::create([
                'user_id' => $user->id,
                'provider' => $adapter->key(),
                'direction' => 'outbound',
                'from_number' => $adapter->health()['caller_id'] ?? null,
                'to_number' => $normalizedTo,
                'status' => DialerCallStatus::Queued,
                'contact_name' => $payload['contact_name'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'requested_at' => now(),
                'acd_context' => $this->acdBridge->prepareOutboundContext($user, $payload),
                'provider_payload' => [
                    'adapter' => $adapter->key(),
                ],
            ]);

            try {
                $result = $adapter->placeCall($call, $user, $payload);

                $call->forceFill([
                    'provider_call_id' => $result['provider_call_id'] ?? null,
                    'status' => DialerCallStatus::fromProviderStatus($result['status'] ?? 'failed'),
                    'started_at' => $result['started_at'] ?? null,
                    'ended_at' => $result['ended_at'] ?? null,
                    'duration_seconds' => $result['duration_seconds'] ?? null,
                    'provider_payload' => array_merge($call->provider_payload ?? [], (array) ($result['payload'] ?? [])),
                ])->save();
            } catch (\Throwable $e) {
                $call->forceFill([
                    'status' => DialerCallStatus::Failed,
                    'ended_at' => now(),
                    'provider_payload' => array_merge($call->provider_payload ?? [], [
                        'error' => $e->getMessage(),
                    ]),
                ])->save();

                throw $e;
            }

            return $call->fresh();
        });
    }

    public function hangup(User $user, DialerCall $call): DialerCall
    {
        if ((int) $call->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('You can only control your own dialer calls.');
        }

        if (! $call->status?->isActive()) {
            throw new InvalidArgumentException('This call is no longer active.');
        }

        $adapter = $this->adapterManager->driver((string) $call->provider);
        $result = $adapter->hangupCall($call, $user);

        $call->forceFill([
            'status' => DialerCallStatus::fromProviderStatus($result['status'] ?? 'completed'),
            'ended_at' => $result['ended_at'] ?? now(),
            'provider_payload' => array_merge($call->provider_payload ?? [], (array) ($result['payload'] ?? [])),
        ])->save();

        if ($call->started_at && $call->ended_at) {
            $call->forceFill([
                'duration_seconds' => max(0, $call->ended_at->diffInSeconds($call->started_at)),
            ])->save();
        }

        return $call->fresh();
    }

    public function transfer(User $user, DialerCall $call, string $targetNumber, array $payload = []): DialerCall
    {
        if ((int) $call->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('You can only transfer your own dialer calls.');
        }

        if (! $call->status?->isActive()) {
            throw new InvalidArgumentException('Only active calls can be transferred.');
        }

        $normalizedTarget = $this->normalizePhoneNumber($targetNumber);
        if ($normalizedTarget === null) {
            throw new InvalidArgumentException('Enter a valid transfer number in international format.');
        }

        if ($normalizedTarget === (string) $call->to_number) {
            throw new InvalidArgumentException('Transfer target must be different from the current destination.');
        }

        $adapter = $this->adapterManager->driver((string) $call->provider);
        $result = $adapter->transferCall($call, $user, $normalizedTarget, $payload);
        $status = DialerCallStatus::fromProviderStatus($result['status'] ?? 'completed');
        $providerPayload = array_merge($call->provider_payload ?? [], (array) ($result['payload'] ?? []));

        $transfers = $providerPayload['transfers'] ?? [];
        if (! is_array($transfers)) {
            $transfers = [];
        }

        $transfers[] = [
            'from' => $call->to_number,
            'to' => $normalizedTarget,
            'requested_by' => $user->public_id,
            'requested_at' => now()->toIso8601String(),
        ];
        $providerPayload['transfers'] = $transfers;

        $call->forceFill([
            'status' => $status,
            'ended_at' => $result['ended_at'] ?? (! $status->isActive() ? now() : null),
            'provider_payload' => $providerPayload,
        ])->save();

        if ($call->started_at && $call->ended_at) {
            $call->forceFill([
                'duration_seconds' => max(0, $call->ended_at->diffInSeconds($call->started_at)),
            ])->save();
        }

        return $call->fresh();
    }

    public function recentCallsFor(User $user, int $limit = 15): LengthAwarePaginator
    {
        return DialerCall::query()
            ->where('user_id', $user->id)
            ->latest('requested_at')
            ->paginate($limit);
    }

    public function activeCallFor(User $user): ?DialerCall
    {
        return $this->activeCallForModel($user);
    }

    public function syncProviderStatus(string $provider, string $providerCallId, array $payload = []): ?DialerCall
    {
        $call = DialerCall::query()
            ->where('provider', $provider)
            ->where('provider_call_id', $providerCallId)
            ->first();

        if (! $call) {
            return null;
        }

        $status = DialerCallStatus::fromProviderStatus($payload['status'] ?? 'failed');
        $startedAt = $call->started_at;
        $endedAt = $call->ended_at;

        if ($status === DialerCallStatus::InProgress && ! $startedAt) {
            $startedAt = now();
        }

        if (! $status->isActive() && ! $endedAt) {
            $endedAt = now();
        }

        $call->forceFill([
            'status' => $status,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => isset($payload['duration_seconds']) ? (int) $payload['duration_seconds'] : $call->duration_seconds,
            'provider_payload' => array_merge($call->provider_payload ?? [], $payload),
        ])->save();

        if ($call->started_at && $call->ended_at && $call->duration_seconds === null) {
            $call->forceFill([
                'duration_seconds' => max(0, $call->ended_at->diffInSeconds($call->started_at)),
            ])->save();
        }

        return $call->fresh();
    }

    protected function activeCallForModel(User $user): ?DialerCall
    {
        return DialerCall::query()
            ->where('user_id', $user->id)
            ->whereIn('status', array_map(
                static fn (DialerCallStatus $status) => $status->value,
                array_filter(DialerCallStatus::cases(), static fn (DialerCallStatus $status) => $status->isActive())
            ))
            ->latest('requested_at')
            ->first();
    }

    protected function normalizePhoneNumber(string $value): ?string
    {
        $trimmed = trim($value);
        $startsWithPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '' || strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return $startsWithPlus ? '+'.$digits : '+'.$digits;
    }
}
