<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    /**
     * Get the human-readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Administrator',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * Get the numeric level for comparison.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 100,
            self::Admin => 75,
            self::Member => 50,
            self::Viewer => 25,
        };
    }

    /**
     * Get the color associated with the role.
     */
    public function color(): string
    {
        return match ($this) {
            self::Owner => 'purple',
            self::Admin => 'blue',
            self::Member => 'green',
            self::Viewer => 'gray',
        };
    }

    /**
     * Check if this role can manage team members.
     */
    public function canManageMembers(): bool
    {
        return in_array($this, [self::Owner, self::Admin]);
    }

    /**
     * Check if this role can manage team settings.
     */
    public function canManageSettings(): bool
    {
        return in_array($this, [self::Owner, self::Admin]);
    }

    /**
     * Check if this role can delete the team.
     */
    public function canDeleteTeam(): bool
    {
        return $this === self::Owner;
    }

    /**
     * Check if this role has higher privilege than another.
     */
    public function isHigherThan(self $role): bool
    {
        return $this->level() > $role->level();
    }

    /**
     * Check if this role has at least the same privilege as another.
     */
    public function isAtLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Get all roles this role can assign to others.
     *
     * @return array<self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::Owner => [self::Admin, self::Member, self::Viewer],
            self::Admin => [self::Member, self::Viewer],
            default => [],
        };
    }
}
