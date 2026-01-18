<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailLabelController extends Controller
{
    /**
     * List labels.
     */
    public function index(Request $request): JsonResponse
    {
        $labels = EmailLabel::forUser($request->user()->id)
            ->get();

        return response()->json($labels);
    }

    /**
     * Create label.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('email_labels')->where('user_id', $request->user()->id),
            ],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $label = $request->user()->emailLabels()->create($validated);

        return response()->json($label, 201);
    }

    /**
     * Update label.
     */
    public function update(Request $request, EmailLabel $emailLabel): JsonResponse
    {
        $this->authorize('update', $emailLabel);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('email_labels')
                    ->where('user_id', $request->user()->id)
                    ->ignore($emailLabel->id),
            ],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $emailLabel->update($validated);

        return response()->json($emailLabel);
    }

    /**
     * Delete label.
     */
    public function destroy(EmailLabel $emailLabel): JsonResponse
    {
        $this->authorize('delete', $emailLabel);

        $emailLabel->delete();

        return response()->json(['message' => 'Label deleted']);
    }
}
