<?php

namespace App\Services\Support;

use App\Models\SupportConversation;
use App\Models\SupportGuestSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class SupportGuestSessionService
{
    public function issueForConversation(SupportConversation $conversation, Request $request): Cookie
    {
        $secret = bin2hex(random_bytes(32));

        $session = SupportGuestSession::create([
            'conversation_id' => $conversation->id,
            'token_hash' => $this->hashSecret($secret),
            'user_agent_hash' => $this->hashOptional((string) $request->userAgent()),
            'ip_hash' => $this->hashOptional((string) $request->ip()),
            'expires_at' => CarbonImmutable::now()->addMinutes($this->ttlMinutes()),
            'last_seen_at' => now(),
        ]);

        $cookieValue = $this->encodeCookieValue((string) $session->public_id, $secret);

        return $this->makeCookie($cookieValue);
    }

    public function clearCookie(): Cookie
    {
        return cookie()->forget($this->cookieName());
    }

    public function revokeSessionFromRequest(Request $request): void
    {
        $cookieValue = (string) $request->cookie($this->cookieName(), '');
        if ($cookieValue === '') {
            return;
        }

        [$sessionPublicId, $secret] = $this->decodeCookieValue($cookieValue);
        if (! $sessionPublicId || ! $secret) {
            return;
        }

        $session = SupportGuestSession::query()
            ->where('public_id', $sessionPublicId)
            ->whereNull('revoked_at')
            ->first();

        if (! $session) {
            return;
        }

        if (! hash_equals((string) $session->token_hash, $this->hashSecret($secret))) {
            return;
        }

        $session->forceFill([
            'revoked_at' => now(),
        ])->save();
    }

    public function revokeConversationSessions(SupportConversation $conversation): void
    {
        SupportGuestSession::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
            ]);
    }

    /**
     * @return SupportGuestSession|null
     */
    public function resolveSessionFromRequest(Request $request): ?SupportGuestSession
    {
        $cookieValue = (string) $request->cookie($this->cookieName(), '');
        if ($cookieValue === '') {
            return null;
        }

        [$sessionPublicId, $secret] = $this->decodeCookieValue($cookieValue);
        if (! $sessionPublicId || ! $secret) {
            return null;
        }

        $session = SupportGuestSession::query()
            ->with('conversation')
            ->where('public_id', $sessionPublicId)
            ->whereNull('revoked_at')
            ->first();

        if (! $session) {
            return null;
        }

        if (! hash_equals((string) $session->token_hash, $this->hashSecret($secret))) {
            return null;
        }

        if (! $session->conversation) {
            return null;
        }

        if (! $session->expires_at || $session->expires_at->isPast()) {
            return null;
        }

        $session->forceFill([
            'last_seen_at' => now(),
            'expires_at' => CarbonImmutable::now()->addMinutes($this->ttlMinutes()),
        ])->save();

        return $session;
    }

    public function hasConversationAccess(Request $request, SupportConversation $conversation): bool
    {
        $session = $this->resolveSessionFromRequest($request);
        if (! $session) {
            return false;
        }

        return (int) $session->conversation_id === (int) $conversation->id;
    }

    public function refreshCookieFromRequest(Request $request): ?Cookie
    {
        $cookieValue = (string) $request->cookie($this->cookieName(), '');
        if ($cookieValue === '') {
            return null;
        }

        [$sessionPublicId, $secret] = $this->decodeCookieValue($cookieValue);
        if (! $sessionPublicId || ! $secret) {
            return null;
        }

        return $this->makeCookie($cookieValue);
    }

    protected function cookieName(): string
    {
        return (string) config('support_chat.guest_resume_cookie', 'worksphere_support_guest');
    }

    protected function ttlMinutes(): int
    {
        return max(10, (int) config('support_chat.guest_resume_ttl_minutes', 4320));
    }

    protected function encodeCookieValue(string $sessionPublicId, string $secret): string
    {
        return "{$sessionPublicId}.{$secret}";
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    protected function decodeCookieValue(string $cookieValue): array
    {
        $parts = explode('.', $cookieValue, 2);
        if (count($parts) !== 2) {
            return [null, null];
        }

        [$sessionPublicId, $secret] = $parts;
        $sessionPublicId = trim((string) $sessionPublicId);
        $secret = trim((string) $secret);

        if ($sessionPublicId === '' || $secret === '') {
            return [null, null];
        }

        return [$sessionPublicId, $secret];
    }

    protected function makeCookie(string $cookieValue): Cookie
    {
        return cookie()->make(
            $this->cookieName(),
            $cookieValue,
            $this->ttlMinutes(),
            '/',
            null,
            (bool) config('session.secure_cookie', false),
            true,
            false,
            config('session.same_site', 'lax')
        );
    }

    protected function hashSecret(string $secret): string
    {
        return hash_hmac('sha256', $secret, $this->signingKey());
    }

    protected function hashOptional(string $value): ?string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        return hash('sha256', $normalized);
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

        return hash('sha256', $appKey.'|support-guest-session', true);
    }
}
