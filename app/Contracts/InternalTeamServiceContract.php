<?php

namespace App\Contracts;

use App\Models\InternalTeam;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

interface InternalTeamServiceContract
{
    /**
     * List all internal teams, optionally filtered.
     *
     * @param array<string, mixed> $filters
     */
    public function list(array $filters, int $perPage = 15): LengthAwarePaginator;

    /**
     * Create a new internal team.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $actor): InternalTeam;

    /**
     * Update an internal team.
     *
     * @param array<string, mixed> $data
     */
    public function update(InternalTeam $team, array $data, User $actor): InternalTeam;

    /**
     * Delete an internal team.
     */
    public function delete(InternalTeam $team, User $actor): void;

    /**
     * Add a member to a team.
     */
    public function addMember(InternalTeam $team, User $user, string $role, User $actor): void;

    /**
     * Remove a member from a team.
     */
    public function removeMember(InternalTeam $team, User $user, User $actor): void;

    /**
     * Update a member's role.
     */
    public function updateMemberRole(InternalTeam $team, User $user, string $role, User $actor): void;

    /**
     * Sync support skills for a team.
     *
     * @param array<int> $skillIds
     */
    public function syncSupportSkills(InternalTeam $team, array $skillIds, User $actor): void;
}
