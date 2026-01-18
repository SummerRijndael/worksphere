<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Team;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Search across multiple models.
     */
    /**
     * Search across multiple models.
     */
    public function index(Request $request)
    {
        $query = $request->input('query');

        if (empty($query)) {
            return response()->json([]);
        }

        $limit = 5;

        // 1. Database Search (Business Entities)
        $clients = Client::search($query)->take($limit)->get()->map(function ($client) {
            return [
                'type' => 'Client',
                'id' => $client->id,
                'title' => $client->name,
                'subtitle' => $client->email,
                'url' => "/clients/{$client->public_id}",
            ];
        });

        $teams = Team::search($query)->take($limit)->get()->map(function ($team) {
            return [
                'type' => 'Team',
                'id' => $team->id,
                'title' => $team->name,
                'subtitle' => $team->description,
                'url' => "/teams/{$team->public_id}",
            ];
        });

        // 2. Navigation Search (System Routes)
        $navResults = $this->getNavigationResults($query);

        return response()->json([
            'results' => $navResults->concat($clients)->concat($teams),
        ]);
    }

    /**
     * Get navigation results based on query and permissions.
     */
    protected function getNavigationResults(string $query)
    {
        $user = auth()->user();
        $query = strtolower($query);

        $routes = [
            // User Management
            [
                'title' => 'Users',
                'subtitle' => 'System > User Management',
                'keywords' => ['users', 'people', 'members', 'accounts'],
                'url' => '/admin/users',
                'permission' => 'users.view',
                'type' => 'Navigation',
            ],
            [
                'title' => 'Teams',
                'subtitle' => 'System > Team Management',
                'keywords' => ['teams', 'groups', 'organizations'],
                'url' => '/admin/teams',
                'permission' => 'users.view',
                'type' => 'Navigation',
            ],
            [
                'title' => 'Roles & Permissions',
                'subtitle' => 'System > Access Control',
                'keywords' => ['roles', 'permissions', 'access', 'acl', 'groups'],
                'url' => '/admin/roles',
                'permission' => 'roles.view',
                'type' => 'Navigation',
            ],
            [
                'title' => 'Audit Logs',
                'subtitle' => 'System > Security',
                'keywords' => ['audit', 'logs', 'history', 'security', 'tracking', 'events'],
                'url' => '/system/logs',
                'permission' => 'audit.view',
                'type' => 'Navigation',
            ],

            // Personal Settings
            [
                'title' => 'My Profile',
                'subtitle' => 'Account > Profile',
                'keywords' => ['profile', 'account', 'me', 'avatar', 'personal'],
                'url' => '/profile',
                'permission' => null,
                'type' => 'Navigation',
            ],
            [
                'title' => 'Global Settings',
                'subtitle' => 'Settings > General',
                'keywords' => ['settings', 'preferences', 'config'],
                'url' => '/settings',
                'permission' => null,
                'type' => 'Navigation',
            ],
            [
                'title' => 'Notifications',
                'subtitle' => 'Settings > Notifications',
                'keywords' => ['notifications', 'alerts', 'messages'],
                'url' => '/notifications',
                'permission' => null,
                'type' => 'Navigation',
            ],
        ];

        $results = collect($routes)->filter(function ($route) use ($user, $query) {
            // 1. Check Permissions
            if ($route['permission'] && ! $user->can($route['permission'])) {
                return false;
            }

            // 2. Check Match
            $matchesTitle = str_contains(strtolower($route['title']), $query);
            $matchesKeywords = collect($route['keywords'])->contains(fn ($k) => str_contains($k, $query));

            return $matchesTitle || $matchesKeywords;
        })->map(function ($route) {
            return [
                'type' => $route['type'],
                'id' => 'nav-'.md5($route['url']), // Unique ID for keying
                'title' => $route['title'],
                'subtitle' => $route['subtitle'],
                'url' => $route['url'],
            ];
        })->values();

        return $results;
    }
}
