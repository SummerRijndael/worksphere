<?php

namespace App\Classes;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Represents a user's authorization "Persona" (Context) for a request.
 * Centralizes all permission logic into a single predictable object.
 */
class AuthorizationPersona
{
    /**
     * @param  bool  $isSuperAdmin  Whether the user has the administrator role.
     * @param  Collection<int, string>  $globalPermissions  Collected flat list of all global permissions.
     * @param  array<int, Collection<int, string>>  $teamPermissions  Permissions grouped by team ID.
     * @param  array{granted: array<string>, blocked: array<string>}  $overrides  Active manual overrides.
     */
    public function __construct(
        public readonly bool $isSuperAdmin,
        public readonly Collection $globalPermissions,
        public readonly array $teamPermissions,
        public readonly array $overrides = ['granted' => [], 'blocked' => []]
    ) {}

    /**
     * Check if the persona has global permission.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin) {
            return true;
        }

        if (in_array($permission, $this->overrides['blocked'])) {
            return false;
        }

        if (in_array($permission, $this->overrides['granted'])) {
            return true;
        }

        return $this->globalPermissions->contains($permission);
    }

    /**
     * Check if the persona has permission within a specific team.
     */
    public function hasTeamPermission(int $teamId, string $permission): bool
    {
        if ($this->isSuperAdmin) {
            return true;
        }

        // Overrides can be global or team-specific in the DB,
        // but for the persona we assume they've been resolved into the list.
        if (in_array($permission, $this->overrides['blocked'])) {
            return false;
        }

        if (in_array($permission, $this->overrides['granted'])) {
            return true;
        }

        $teamPerms = $this->teamPermissions[$teamId] ?? collect();

        return $teamPerms->contains($permission);
    }
}
