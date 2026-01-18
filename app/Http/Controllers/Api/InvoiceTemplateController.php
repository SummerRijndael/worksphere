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
        if (! $request->user()->teams->contains('public_id', $teamId)) {
            abort(403, 'Unauthorized access to team templates.');
        }

        $templates = $this->service->getAllForTeam($teamId);

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request, string $teamId): JsonResponse
    {
        $this->authorize('create', InvoiceTemplate::class);

        if (! $request->user()->teams->contains('public_id', $teamId)) {
            abort(403, 'Unauthorized action.');
        }

        $team = \App\Models\Team::where('public_id', $teamId)->firstOrFail();

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
            'created_by' => $request->user()->id,
        ]);

        $template = $this->service->create($data);

        return response()->json(['data' => $template], 201);
    }

    public function show(InvoiceTemplate $invoiceTemplate): JsonResponse
    {
        $this->authorize('view', $invoiceTemplate);

        return response()->json(['data' => $invoiceTemplate]);
    }

    public function update(Request $request, InvoiceTemplate $invoiceTemplate): JsonResponse
    {
        $this->authorize('update', $invoiceTemplate);

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

    public function destroy(InvoiceTemplate $invoiceTemplate): JsonResponse
    {
        $this->authorize('delete', $invoiceTemplate);
        $this->service->delete($invoiceTemplate->id);

        return response()->json(['message' => 'Template deleted']);
    }
}
