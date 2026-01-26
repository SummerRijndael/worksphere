<?php

namespace App\Http\Controllers\Api;

use App\Contracts\TaskTemplateServiceContract;
use App\Http\Controllers\Controller;
use App\Models\TaskTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskTemplateController extends Controller
{
    public function __construct(
        protected TaskTemplateServiceContract $service
    ) {}

    public function index(Request $request, string $teamId): JsonResponse
    {
        $user = $request->user();
        $team = \App\Models\Team::where('public_id', $teamId)->firstOrFail();

        if (! $user->hasRole('administrator')) {
             $permissionService = app(\App\Services\PermissionService::class);
             if (! $permissionService->hasTeamPermission($user, $team, 'task_templates.view')) {
                abort(403, 'Unauthorized access to team templates.');
             }
        }

        $templates = $this->service->getAllForTeam($teamId);

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request, string $teamId): JsonResponse
    {
        $user = $request->user();
        $team = \App\Models\Team::where('public_id', $teamId)->firstOrFail();

        if (! $user->hasRole('administrator')) {
            $permissionService = app(\App\Services\PermissionService::class);
            if (! $permissionService->hasTeamPermission($user, $team, 'task_templates.create')) {
                 abort(403, 'Unauthorized action.');
            }
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'default_priority' => 'nullable|string|in:low,medium,high,urgent',
            'default_estimated_hours' => 'nullable|numeric|min:0',
            'checklist_template' => 'nullable|array',
            'checklist_template.*.text' => 'required|string',
            'is_active' => 'boolean',
        ]);

        // Sanitize HTML content to prevent XSS
        $validated['name'] = \Mews\Purifier\Facades\Purifier::clean($validated['name']);
        if (! empty($validated['description'])) {
            $validated['description'] = \Mews\Purifier\Facades\Purifier::clean($validated['description']);
        }
        if (! empty($validated['checklist_template'])) {
            foreach ($validated['checklist_template'] as &$item) {
                if (isset($item['text'])) {
                    $item['text'] = \Mews\Purifier\Facades\Purifier::clean($item['text']);
                }
            }
        }

        $data = array_merge($validated, [
            'team_id' => $team->id,
            'created_by' => $user->id,
        ]);

        $template = $this->service->create($data);

        return response()->json(['data' => $template], 201);
    }

    public function show(Request $request, TaskTemplate $taskTemplate): JsonResponse
    {
        $user = $request->user();

         if (! $user->hasRole('administrator')) {
             $team = \App\Models\Team::find($taskTemplate->team_id);
             $permissionService = app(\App\Services\PermissionService::class);
             if (!$team || ! $permissionService->hasTeamPermission($user, $team, 'task_templates.view')) {
                abort(403, 'Unauthorized.');
             }
        }

        return response()->json(['data' => $taskTemplate]);
    }

    public function update(Request $request, TaskTemplate $taskTemplate): JsonResponse
    {
        $user = $request->user();

         if (! $user->hasRole('administrator')) {
             $team = \App\Models\Team::find($taskTemplate->team_id);
             $permissionService = app(\App\Services\PermissionService::class);
             if (!$team || ! $permissionService->hasTeamPermission($user, $team, 'task_templates.update')) {
                abort(403, 'Unauthorized.');
             }
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'default_priority' => 'nullable|string',
            'default_estimated_hours' => 'nullable|numeric',
            'checklist_template' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $updated = $this->service->update($taskTemplate->id, $validated);

        return response()->json(['data' => $updated]);
    }

    public function destroy(Request $request, TaskTemplate $taskTemplate): JsonResponse
    {
        $user = $request->user();

         if (! $user->hasRole('administrator')) {
             $team = \App\Models\Team::find($taskTemplate->team_id);
             $permissionService = app(\App\Services\PermissionService::class);
             if (!$team || ! $permissionService->hasTeamPermission($user, $team, 'task_templates.delete')) {
                abort(403, 'Unauthorized.');
             }
        }

        $this->service->delete($taskTemplate->id);

        return response()->json(['message' => 'Template deleted']);
    }
}
