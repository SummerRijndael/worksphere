<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavigationController extends Controller
{
    public function __construct(
        protected \App\Services\PermissionService $permissionService
    ) {}

    /**
     * Get navigation items for authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $sidebarConfig = config('navigation.sidebar', []);
        $userMenu = config('navigation.user_menu', []);

        // Enrich config with dynamic data (e.g., Teams)
        $sidebarConfig = $this->enrichNavigation($sidebarConfig, $user);

        $filteredSidebar = $this->filterNavigationByPermissions($sidebarConfig, $user);

        // Get user's pinned items from preferences

        // Get user's pinned items from preferences
        $pinnedItems = $user->getPreference('sidebar.pinned', $this->getDefaultPinnedItems($sidebarConfig));
        $collapsedGroups = $user->getPreference('sidebar.collapsed_groups', []);
        $sidebarCollapsed = $user->getPreference('sidebar.collapsed', false);

        return response()->json([
            'sidebar' => $filteredSidebar,
            'user_menu' => $userMenu,
            'preferences' => [
                'pinned_items' => $pinnedItems,
                'collapsed_groups' => $collapsedGroups,
                'sidebar_collapsed' => $sidebarCollapsed,
            ],
            'badges' => $this->getBadgeCounts($user),
        ]);
    }

    /**
     * Update navigation preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pinned_items' => ['sometimes', 'array'],
            'pinned_items.*' => ['string'],
            'collapsed_groups' => ['sometimes', 'array'],
            'collapsed_groups.*' => ['string'],
            'sidebar_collapsed' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        if (isset($validated['pinned_items'])) {
            $user->setPreference('sidebar.pinned', $validated['pinned_items']);
        }

        if (isset($validated['collapsed_groups'])) {
            $user->setPreference('sidebar.collapsed_groups', $validated['collapsed_groups']);
        }

        if (isset($validated['sidebar_collapsed'])) {
            $user->setPreference('sidebar.collapsed', $validated['sidebar_collapsed']);
        }

        $user->save();

        return response()->json([
            'message' => 'Preferences updated.',
            'preferences' => [
                'pinned_items' => $user->getPreference('sidebar.pinned', []),
                'collapsed_groups' => $user->getPreference('sidebar.collapsed_groups', []),
                'sidebar_collapsed' => $user->getPreference('sidebar.collapsed', false),
            ],
        ]);
    }

    /**
     * Enrich navigation items with dynamic data.
     */
    protected function enrichNavigation(array $items, $user): array
    {
        return collect($items)->map(function ($item) use ($user) {
            // Enrich Teams
            if (isset($item['id']) && $item['id'] === 'teams') {
                $userTeams = $user->teams()->orderBy('name')->get();

                if ($userTeams->isNotEmpty()) {
                    $item['children'] = $userTeams->map(function ($team) {
                        return [
                            'id' => 'team-'.$team->public_id,
                            'label' => $team->name,
                            'route' => '/teams/'.$team->public_id,
                            'icon' => 'users', // Fallback icon
                            'avatar' => $team->avatar_url,
                            'initials' => $team->initials,
                        ];
                    })->toArray();
                }
            }

            // Enrich Projects - show actual projects from user's teams
            if (isset($item['id']) && $item['id'] === 'projects') {
                $userTeams = $user->teams;

                if ($userTeams->isNotEmpty()) {
                    $projectChildren = [
                        [
                            'id' => 'projects-all',
                            'label' => 'View All Projects',
                            'route' => '/projects',
                            'icon' => 'folder',
                        ],
                        ['id' => 'projects-sep-1', 'type' => 'divider'],
                    ];

                    $hasCreatePermission = false;

                    foreach ($userTeams as $team) {
                        $canViewAll = $this->permissionService->hasTeamPermission($user, $team, 'projects.view');
                        $canViewAssigned = $this->permissionService->hasTeamPermission($user, $team, 'projects.view_assigned');

                        if (! $canViewAll && ! $canViewAssigned) {
                            continue;
                        }

                        if (! $hasCreatePermission && $this->permissionService->hasTeamPermission($user, $team, 'projects.create')) {
                            $hasCreatePermission = true;
                        }

                        $query = \App\Models\Project::where('team_id', $team->id)->active();

                        if (! $canViewAll && $canViewAssigned) {
                            $query->whereHas('members', function ($q) use ($user) {
                                $q->where('user_id', $user->id);
                            });
                        }

                        $projects = $query->orderByDesc('updated_at')
                            ->limit(5)
                            ->get(['id', 'public_id', 'name']);

                        if ($projects->isNotEmpty()) {
                            $projectChildren[] = [
                                'id' => 'team-projects-header-'.$team->public_id,
                                'label' => $team->name,
                                'type' => 'header',
                            ];

                            foreach ($projects as $project) {
                                $projectChildren[] = [
                                    'id' => 'project-'.$project->public_id,
                                    'label' => $project->name,
                                    'route' => "/projects/{$project->public_id}?team_id={$team->public_id}",
                                ];
                            }
                        }
                    }

                    // Add "New Project" if user has create permission in any team
                    if ($hasCreatePermission || $user->hasRole('administrator')) {
                        $projectChildren[] = ['id' => 'projects-sep-2', 'type' => 'divider'];
                        $projectChildren[] = [
                            'id' => 'project-new',
                            'label' => 'New Project',
                            'route' => '/projects?create=true',
                            'icon' => 'plus',
                        ];
                    }

                    $item['children'] = $projectChildren;
                }
            }

            // Enrich Clients - show recent clients from user's teams
            if (isset($item['id']) && $item['id'] === 'clients') {
                $userTeams = $user->teams;

                if ($userTeams->isNotEmpty()) {
                    $clientChildren = [
                        [
                            'id' => 'clients-all',
                            'label' => 'View All Clients',
                            'route' => '/clients',
                            'icon' => 'users',
                        ],
                        ['id' => 'clients-sep-1', 'type' => 'divider'],
                    ];

                    foreach ($userTeams as $team) {
                        if (! $this->permissionService->hasTeamPermission($user, $team, 'clients.view')) {
                            continue;
                        }

                        $clients = \App\Models\Client::where('team_id', $team->id)
                            ->where('status', 'active')
                            ->orderByDesc('updated_at')
                            ->limit(3)
                            ->get(['id', 'public_id', 'name']);

                        if ($clients->isNotEmpty()) {
                            $clientChildren[] = [
                                'id' => 'team-clients-header-'.$team->public_id,
                                'label' => $team->name,
                                'type' => 'header',
                            ];

                            foreach ($clients as $client) {
                                $clientChildren[] = [
                                    'id' => 'client-'.$client->public_id,
                                    'label' => $client->name,
                                    'route' => "/clients/{$client->public_id}?team_id={$team->public_id}",
                                ];
                            }
                        }
                    }

                    $item['children'] = $clientChildren;
                }
            }

            // Enrich Invoices
            if (isset($item['id']) && $item['id'] === 'invoices') {
                $isAdmin = $user->hasRole('administrator');
                $item['route'] = $isAdmin ? '/admin/invoices' : '/invoices';
            }

            // Recurse for children
            if (isset($item['children']) && is_array($item['children'])) {
                $item['children'] = $this->enrichNavigation($item['children'], $user);
            }

            return $item;
        })->toArray();
    }

    /**
     * Filter navigation items based on user permissions and team membership.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function filterNavigationByPermissions(array $items, $user): array
    {
        $hasTeams = $user->teams()->exists();

        return collect($items)
            ->filter(function ($item) use ($user, $hasTeams) {
                // We used to hide items here if $hasTeams was false,
                // but now we show them so the user sees a valid sidebar
                // and gets "empty state" CTAs in the main views.

                // Always show items without permission requirements
                if (! isset($item['permission'])) {
                    return true;
                }

                $permissions = (array) $item['permission'];

                // Check if user has any of the required permissions
                foreach ($permissions as $permission) {
                    // Determine the scope of this permission
                    $scope = $this->permissionService->getPermissionScope($permission);

                    // If global permission, check global access only
                    if ($scope === 'global') {
                        if ($this->permissionService->hasPermission($user, $permission)) {
                            return true;
                        }

                        // Continue to next permission if this one failed
                        continue;
                    }

                    // If team permission, check team context
                    if ($hasTeams) {
                        foreach ($user->teams as $team) {
                            if ($this->permissionService->hasTeamPermission($user, $team, $permission)) {
                                return true;
                            }
                        }
                    }

                    // Fallback: If it's a team permission but granted globally (e.g. via base user role), allow it
                    if ($this->permissionService->hasPermission($user, $permission)) {
                        return true;
                    }
                }

                return false;
            })
            ->map(function ($item) use ($user) {
                // Filter children recursively
                if (isset($item['children']) && is_array($item['children'])) {
                    $item['children'] = $this->filterNavigationByPermissions($item['children'], $user);

                    // Omit container-only parents (no route) that have no children
                    // This handles both:
                    // 1. Items that had children but all were filtered out by permissions
                    // 2. Items that are containers with empty children arrays (e.g., Teams with no teams)
                    if (empty($item['children']) && ($item['type'] ?? null) !== 'divider') {
                        if (empty($item['route'])) {
                            // No route and no children = container-only, omit entirely
                            return null;
                        }
                        // Has route but no children - remove empty children array, keep item
                        unset($item['children']);
                    }
                }

                return $item;
            })
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Get default pinned items from config.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, string>
     */
    protected function getDefaultPinnedItems(array $items): array
    {
        return collect($items)
            ->filter(fn ($item) => ($item['pinned_default'] ?? false) && isset($item['id']))
            ->pluck('id')
            ->toArray();
    }

    /**
     * Get badge counts for navigation items.
     *
     * @return array<string, int>
     */
    protected function getBadgeCounts($user): array
    {
        // In a real application, these would be computed from actual data
        return [
            'open_tickets_count' => 0,
            'unread_notifications_count' => 0,
        ];
    }
}
