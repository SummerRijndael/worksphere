<?php

namespace App\Services\Support;

use App\Contracts\SupportConversationServiceContract;
use App\Models\SupportConversation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Pusher\Pusher;

class SupportRealtimeService
{
    public function __construct(
        protected SupportConversationServiceContract $supportService
    ) {}

    /**
     * @return array{
     *   token: string,
     *   expires_at: string,
     *   channels: list<string>,
     *   auth_endpoint: string
     * }
     */
    public function conversationRealtimeMeta(SupportConversation $conversation, ?User $actor = null, ?string $guestToken = null): array
    {
        if ($actor && $this->supportService->canOperateAsAgent($actor)) {
            return $this->agentRealtimeMeta($actor, $conversation->public_id);
        }

        return $this->customerRealtimeMeta($conversation, $actor, $guestToken);
    }

    /**
     * @return array{
     *   token: string,
     *   expires_at: string,
     *   channels: list<string>,
     *   auth_endpoint: string
     * }
     */
    public function customerRealtimeMeta(SupportConversation $conversation, ?User $actor = null, ?string $guestToken = null): array
    {
        $claims = [
            'scope' => 'customer',
            'sub' => (string) $conversation->public_id,
            'actor' => $actor ? 'user' : 'guest',
            'uid' => $actor?->public_id,
            'guest_hash' => $actor ? null : $this->hashGuestToken((string) $guestToken),
        ];

        [$token, $expiresAt] = $this->issueToken($claims);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'channels' => ["support.customer.{$conversation->public_id}"],
            'auth_endpoint' => '/api/support/chats/broadcasting/auth',
        ];
    }

    /**
     * @return array{
     *   token: string,
     *   expires_at: string,
     *   channels: list<string>,
     *   auth_endpoint: string
     * }
     */
    public function agentRealtimeMeta(User $agent, ?string $conversationPublicId = null): array
    {
        if (! $this->supportService->canOperateAsAgent($agent)) {
            throw new AuthorizationException('Only support agents can receive realtime support feeds.');
        }

        [$token, $expiresAt] = $this->issueToken([
            'scope' => 'agent',
            'actor' => 'agent',
            'uid' => (string) $agent->public_id,
            'sub' => $conversationPublicId,
        ]);

        $channels = ['support.agent.inbox'];
        if ($conversationPublicId) {
            $channels[] = "support.agent.{$conversationPublicId}";
        }

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'channels' => $channels,
            'auth_endpoint' => '/api/support/chats/broadcasting/auth',
        ];
    }

    /**
     * @throws AuthorizationException
     */
    public function authenticateBroadcasting(
        string $channelName,
        string $socketId,
        ?User $actor,
        ?string $token = null
    ): Response {
        $normalizedChannel = $this->normalizeChannelName($channelName);
        $claims = $this->decodeToken((string) $token);

        if (! $claims) {
            throw new AuthorizationException('Missing or invalid support realtime token.');
        }

        if (preg_match('/^support\.customer\.([a-zA-Z0-9]+)$/', $normalizedChannel, $matches) === 1) {
            $conversationPublicId = (string) $matches[1];
            $this->authorizeCustomerChannel($conversationPublicId, $claims, $actor);

            return $this->generateSocketAuth($channelName, $socketId);
        }

        if ($normalizedChannel === 'support.agent.inbox') {
            $this->authorizeAgentScope($claims, $actor);

            return $this->generateSocketAuth($channelName, $socketId);
        }

        if (preg_match('/^support\.agent\.([a-zA-Z0-9]+)$/', $normalizedChannel, $matches) === 1) {
            $conversationPublicId = (string) $matches[1];
            $this->authorizeAgentScope($claims, $actor);

            $exists = SupportConversation::query()
                ->where('public_id', $conversationPublicId)
                ->exists();

            if (! $exists) {
                throw new AuthorizationException('Support conversation channel is invalid.');
            }

            return $this->generateSocketAuth($channelName, $socketId);
        }

        throw new AuthorizationException('Unsupported support channel.');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function issueToken(array $claims): array
    {
        $now = CarbonImmutable::now();
        $ttlSeconds = max(60, (int) config('support_chat.realtime_token_ttl', 300));
        $exp = $now->addSeconds($ttlSeconds);

        $payload = array_merge([
            'v' => 1,
            'iss' => 'worksphere.support.realtime',
            'iat' => $now->getTimestamp(),
            'exp' => $exp->getTimestamp(),
            'jti' => (string) Str::ulid(),
        ], $claims);

        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedPayload, $this->signingKey(), true);
        $encodedSignature = $this->base64UrlEncode($signature);

        return [
            "{$encodedPayload}.{$encodedSignature}",
            $exp->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadPart, $signaturePart] = $parts;
        $expectedSignature = hash_hmac('sha256', $payloadPart, $this->signingKey(), true);
        $providedSignature = $this->base64UrlDecode($signaturePart);

        if (! is_string($providedSignature) || ! hash_equals($expectedSignature, $providedSignature)) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($payloadPart);
        if (! is_string($payloadJson)) {
            return null;
        }

        try {
            $claims = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($claims)) {
            return null;
        }

        $exp = (int) Arr::get($claims, 'exp', 0);
        if ($exp <= CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function authorizeCustomerChannel(string $conversationPublicId, array $claims, ?User $actor): void
    {
        if ((string) Arr::get($claims, 'scope') !== 'customer') {
            throw new AuthorizationException('Realtime token scope is invalid for customer channel.');
        }

        if ((string) Arr::get($claims, 'sub') !== $conversationPublicId) {
            throw new AuthorizationException('Realtime token is not valid for this support conversation.');
        }

        $conversation = SupportConversation::query()
            ->where('public_id', $conversationPublicId)
            ->first();

        if (! $conversation) {
            throw new AuthorizationException('Support conversation not found.');
        }

        $actorType = (string) Arr::get($claims, 'actor');
        if ($actorType === 'user') {
            $tokenUserPublicId = (string) Arr::get($claims, 'uid', '');
            if (! $actor || $tokenUserPublicId === '' || $tokenUserPublicId !== (string) $actor->public_id) {
                throw new AuthorizationException('Realtime token does not match authenticated requester.');
            }

            if ((int) $conversation->requester_user_id !== (int) $actor->id) {
                throw new AuthorizationException('You are not allowed to subscribe to this support conversation.');
            }

            return;
        }

        if ($actorType === 'guest') {
            $expectedGuestHash = $this->hashGuestToken((string) $conversation->guest_token);
            $providedGuestHash = (string) Arr::get($claims, 'guest_hash', '');
            if ($providedGuestHash === '' || ! hash_equals($expectedGuestHash, $providedGuestHash)) {
                throw new AuthorizationException('Guest realtime token is invalid for this support conversation.');
            }

            return;
        }

        throw new AuthorizationException('Unsupported realtime actor type.');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function authorizeAgentScope(array $claims, ?User $actor): void
    {
        if ((string) Arr::get($claims, 'scope') !== 'agent') {
            throw new AuthorizationException('Realtime token scope is invalid for support agent channel.');
        }

        if (! $actor || ! $this->supportService->canOperateAsAgent($actor)) {
            throw new AuthorizationException('Support agent authentication is required for this channel.');
        }

        $tokenUserPublicId = (string) Arr::get($claims, 'uid', '');
        if ($tokenUserPublicId === '' || $tokenUserPublicId !== (string) $actor->public_id) {
            throw new AuthorizationException('Realtime token does not match authenticated support agent.');
        }
    }

    protected function normalizeChannelName(string $channelName): string
    {
        $normalized = $channelName;
        if (str_starts_with($normalized, 'private-')) {
            $normalized = substr($normalized, strlen('private-'));
        }
        if (str_starts_with($normalized, 'presence-')) {
            $normalized = substr($normalized, strlen('presence-'));
        }

        return $normalized;
    }

    protected function generateSocketAuth(string $channelName, string $socketId): Response
    {
        $connection = config('broadcasting.default');
        $config = config("broadcasting.connections.{$connection}");

        $pusher = new Pusher(
            $config['key'],
            $config['secret'],
            $config['app_id'],
            $config['options'] ?? []
        );

        $auth = $pusher->socket_auth($channelName, $socketId);

        return response($auth)->header('Content-Type', 'application/json');
    }

    protected function signingKey(): string
    {
        $appKey = (string) config('app.key', '');
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                $appKey = $decoded;
            }
        }

        return hash('sha256', $appKey.'|support-realtime', true);
    }

    protected function hashGuestToken(string $guestToken): string
    {
        if ($guestToken === '') {
            return '';
        }

        return hash_hmac('sha256', $guestToken, $this->signingKey());
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
