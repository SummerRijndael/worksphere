<?php

namespace App\Events;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AuditableEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly AuditAction $action,
        public readonly AuditCategory $category,
        public readonly ?User $user = null,
        public readonly ?Model $auditable = null,
        public readonly ?array $oldValues = null,
        public readonly ?array $newValues = null,
        public readonly array $metadata = []
    ) {}

    /**
     * Create a permission change event.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function permissionChange(
        AuditAction $action,
        User $targetUser,
        string $permissionOrRole,
        ?User $actor = null,
        array $metadata = []
    ): self {
        return new self(
            action: $action,
            category: AuditCategory::Authorization,
            user: $actor ?? auth()->user(),
            auditable: $targetUser,
            metadata: array_merge($metadata, [
                'permission_or_role' => $permissionOrRole,
            ])
        );
    }

    /**
     * Create a team action event.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function teamAction(
        AuditAction $action,
        Model $team,
        ?User $actor = null,
        array $metadata = []
    ): self {
        return new self(
            action: $action,
            category: AuditCategory::TeamManagement,
            user: $actor ?? auth()->user(),
            auditable: $team,
            metadata: $metadata
        );
    }

    /**
     * Create a security event.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function security(
        AuditAction $action,
        ?User $user = null,
        array $metadata = []
    ): self {
        return new self(
            action: $action,
            category: AuditCategory::Security,
            user: $user ?? auth()->user(),
            metadata: $metadata
        );
    }
}
