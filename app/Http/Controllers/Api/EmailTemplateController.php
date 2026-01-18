<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmailTemplateRequest;
use App\Models\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    /**
     * List templates.
     */
    public function index(Request $request): JsonResponse
    {
        $templates = EmailTemplate::forUser($request->user()->id)
            ->get();

        return response()->json($templates);
    }

    /**
     * Create template.
     */
    public function store(StoreEmailTemplateRequest $request): JsonResponse
    {
        $template = $request->user()->emailTemplates()->create($request->validated());

        return response()->json($template, 201);
    }

    /**
     * Update template.
     */
    public function update(StoreEmailTemplateRequest $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $this->authorize('update', $emailTemplate);

        $emailTemplate->update($request->validated());

        return response()->json($emailTemplate);
    }

    /**
     * Delete template.
     */
    public function destroy(EmailTemplate $emailTemplate): JsonResponse
    {
        $this->authorize('delete', $emailTemplate);

        $emailTemplate->delete();

        return response()->json(['message' => 'Template deleted']);
    }
}
