<?php

namespace App\Http\Controllers\Api;

use App\Contracts\InvoiceTemplateServiceContract;
use App\Http\Controllers\Controller;
use App\Models\InvoiceTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceTemplateController extends Controller
{
    public function __construct(
        protected InvoiceTemplateServiceContract $service
    ) {}

    public function index(Request $request, string $teamId): JsonResponse
    {
        $user = $request->user();
        $team = \App\Models\Team::where('public_id', $teamId)->firstOrFail();

        if (! $user->hasRole('administrator')) {
             $permissionService = app(\App\Services\PermissionService::class);
             
             if (! $permissionService->hasTeamPermission($user, $team, 'invoice_templates.view')) {
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
            if (! $permissionService->hasTeamPermission($user, $team, 'invoice_templates.create')) {
                 abort(403, 'Unauthorized action.');
            }
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'currency' => 'nullable|string|size:3',
            'default_terms' => 'nullable|string',
            'default_notes' => 'nullable|string',
            'logo_url' => 'nullable|string|url',
            'is_active' => 'boolean',
            'line_items' => 'nullable|array',
            'line_items.*.description' => 'required|string',
            'line_items.*.quantity' => 'required|numeric',
            'line_items.*.unit_price' => 'required|numeric',
        ]);

        // Sanitize HTML content to prevent XSS
        $validated['name'] = \Mews\Purifier\Facades\Purifier::clean($validated['name']);
        if (! empty($validated['description'])) {
            $validated['description'] = \Mews\Purifier\Facades\Purifier::clean($validated['description']);
        }
        if (! empty($validated['default_terms'])) {
            $validated['default_terms'] = \Mews\Purifier\Facades\Purifier::clean($validated['default_terms']);
        }
        if (! empty($validated['default_notes'])) {
            $validated['default_notes'] = \Mews\Purifier\Facades\Purifier::clean($validated['default_notes']);
        }
        if (! empty($validated['line_items'])) {
            foreach ($validated['line_items'] as &$item) {
                $item['description'] = \Mews\Purifier\Facades\Purifier::clean($item['description']);
            }
        }

        $data = array_merge($validated, [
            'team_id' => $team->id,
            'created_by' => $user->id,
        ]);

        $template = $this->service->create($data);

        return response()->json(['data' => $template], 201);
    }

    public function show(Request $request, InvoiceTemplate $invoiceTemplate): JsonResponse
    {
        $user = $request->user();
        
        if (! $user->hasRole('administrator')) {
             // Resolve team
             $team = \App\Models\Team::find($invoiceTemplate->team_id);
             $permissionService = app(\App\Services\PermissionService::class);
             if (!$team || ! $permissionService->hasTeamPermission($user, $team, 'invoice_templates.view')) {
                abort(403, 'Unauthorized.');
             }
        }

        return response()->json(['data' => $invoiceTemplate]);
    }

    public function update(Request $request, InvoiceTemplate $invoiceTemplate): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('administrator')) {
             $team = \App\Models\Team::find($invoiceTemplate->team_id);
             $permissionService = app(\App\Services\PermissionService::class);
             if (!$team || ! $permissionService->hasTeamPermission($user, $team, 'invoice_templates.update')) {
                abort(403, 'Unauthorized.');
             }
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'currency' => 'nullable|string|size:3',
            'default_terms' => 'nullable|string',
            'default_notes' => 'nullable|string',
            'logo_url' => 'nullable|string',
            'is_active' => 'boolean',
            'line_items' => 'nullable|array',
            'line_items.*.description' => 'required|string',
            'line_items.*.quantity' => 'required|numeric',
            'line_items.*.unit_price' => 'required|numeric',
        ]);

        $updated = $this->service->update($invoiceTemplate->id, $validated);

        return response()->json(['data' => $updated]);
    }

    public function destroy(Request $request, InvoiceTemplate $invoiceTemplate): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('administrator')) {
             $team = \App\Models\Team::find($invoiceTemplate->team_id);
             $permissionService = app(\App\Services\PermissionService::class);
             if (!$team || ! $permissionService->hasTeamPermission($user, $team, 'invoice_templates.delete')) {
                abort(403, 'Unauthorized.');
             }
        }

        $this->service->delete($invoiceTemplate->id);

        return response()->json(['message' => 'Template deleted']);
    }
}
