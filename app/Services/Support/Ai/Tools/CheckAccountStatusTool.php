<?php

namespace App\Services\Support\Ai\Tools;

use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CheckAccountStatusTool implements Tool
{
    public function __construct(
        protected SupportConversation $conversation
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Check account status for the signed-in customer in this support chat (active, blocked, suspended, disabled, can_login).';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $conversation = SupportConversation::query()
            ->select(['id', 'requester_user_id'])
            ->find($this->conversation->id);

        if (! $conversation || ! $conversation->requester_user_id) {
            return $this->encode([
                'verified' => false,
                'status' => 'unverified',
                'can_login' => false,
                'suspended_until' => null,
                'status_reason' => null,
                'message' => 'Account status checks are only available for signed-in users in this chat.',
            ]);
        }

        /** @var User|null $user */
        $user = User::query()
            ->select(['id', 'public_id', 'status', 'status_reason', 'suspended_until'])
            ->find((int) $conversation->requester_user_id);

        if (! $user) {
            return $this->encode([
                'verified' => false,
                'status' => 'not_found',
                'can_login' => false,
                'suspended_until' => null,
                'status_reason' => null,
                'message' => 'No linked account was found for this conversation.',
            ]);
        }

        $status = strtolower(trim((string) ($user->status ?: 'active')));
        $canLogin = $user->canLogin();
        $reason = trim((string) ($user->status_reason ?? ''));
        $statusReason = $reason !== '' ? mb_substr($reason, 0, 240) : null;
        $suspendedUntil = $user->suspended_until?->toIso8601String();

        $message = match ($status) {
            'active' => 'Your account is active and can sign in.',
            'blocked' => 'Your account is currently blocked.',
            'disabled' => 'Your account is currently disabled.',
            'banned' => 'Your account is currently banned.',
            'suspended' => $suspendedUntil
                ? "Your account is suspended until {$suspendedUntil}."
                : 'Your account is currently suspended.',
            default => $canLogin
                ? "Your account status is {$status}, and sign-in is currently allowed."
                : "Your account status is {$status}, and sign-in is currently not allowed.",
        };

        return $this->encode([
            'verified' => true,
            'account_public_id' => (string) $user->public_id,
            'status' => $status,
            'can_login' => $canLogin,
            'suspended_until' => $suspendedUntil,
            'status_reason' => $statusReason,
            'message' => $message,
        ]);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json)
            ? $json
            : '{"verified":false,"status":"error","can_login":false,"message":"Unable to encode account status response."}';
    }
}

