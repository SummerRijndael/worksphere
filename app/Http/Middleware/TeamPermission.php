<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TeamPermission
{
    public function __construct(
        protected PermissionService $permissionService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $permission  Permission to check (can be pipe-separated for "any of")
     * @param  string  $teamParameter  Route parameter name for team (default: 'team')
     */
    public function handle(Request $request, Closure $next, string $permission, string $teamParameter = 'team'): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new AccessDeniedHttpException('Unauthenticated.');
        }

        $team = $this->resolveTeam($request, $teamParameter);

        if (! $team) {
            throw new AccessDeniedHttpException('Team not found.');
        }

        // Check if user is a member of the team
        // Super Admins bypass membership check
        $superAdminRole = config('roles.super_admin_role', 'administrator');
        if (! $user->hasRole($superAdminRole) && ! $this->permissionService->isTeamMember($user, $team)) {
            throw new AccessDeniedHttpException('You are not a member of this team.');
        }

        // Check for multiple permissions (pipe-separated)
        $permissions = explode('|', $permission);

        if (count($permissions) > 1) {
            // Any permission is sufficient
            if (! $this->permissionService->hasAnyTeamPermission($user, $team, $permissions)) {
                throw new AccessDeniedHttpException('Insufficient team permissions.');
            }
        } else {
            if (! $this->permissionService->hasTeamPermission($user, $team, $permission)) {
                throw new AccessDeniedHttpException('Insufficient team permissions.');
            }
        }

        // Store team in request for convenience
        $request->attributes->set('current_team', $team);

        return $next($request);
    }

    /**
     * Resolve the team from the request.
     */
    protected function resolveTeam(Request $request, string $teamParameter): ?Team
    {
        $team = $request->route($teamParameter);

        if ($team instanceof Team) {
            return $team;
        }

        if (is_string($team) || is_int($team)) {
            return Team::where('public_id', $team)
                ->orWhere('id', $team)
                ->first();
        }

        // Check for header from frontend
        $headerTeamId = $request->header('X-Team-ID');
        if ($headerTeamId) {
            return Team::where('public_id', $headerTeamId)
                ->orWhere('id', $headerTeamId)
                ->first();
        }

        // Fallback: Check for input parameter (query/body)
        // Useful for resource controllers that receive team_id in payload
        $inputTeamId = $request->input('team_id') ?? $request->input('team');
        if ($inputTeamId) {
             return Team::where('public_id', $inputTeamId)
                ->orWhere('id', $inputTeamId)
                ->first();
        }

        return null;
    }
}
