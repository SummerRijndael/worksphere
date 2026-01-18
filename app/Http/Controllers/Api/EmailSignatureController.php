<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmailSignatureRequest;
use App\Models\EmailSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailSignatureController extends Controller
{
    /**
     * List signatures.
     */
    public function index(Request $request): JsonResponse
    {
        $signatures = EmailSignature::forUser($request->user()->id)
            ->get();

        return response()->json($signatures);
    }

    /**
     * Create signature.
     */
    public function store(StoreEmailSignatureRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $isDefault = $validated['is_default'] ?? false;
        unset($validated['is_default']);

        $signature = $request->user()->emailSignatures()->create($validated);

        if ($isDefault) {
            $signature->setAsDefault();
        }

        return response()->json($signature, 201);
    }

    /**
     * Update signature.
     */
    public function update(StoreEmailSignatureRequest $request, EmailSignature $emailSignature): JsonResponse
    {
        $this->authorize('update', $emailSignature);

        $validated = $request->validated();
        $isDefault = $validated['is_default'] ?? false;
        unset($validated['is_default']);

        $emailSignature->update($validated);

        if ($isDefault) {
            $emailSignature->setAsDefault();
        }

        return response()->json($emailSignature);
    }

    /**
     * Delete signature.
     */
    public function destroy(EmailSignature $emailSignature): JsonResponse
    {
        $this->authorize('delete', $emailSignature);

        $emailSignature->delete();

        return response()->json(['message' => 'Signature deleted']);
    }
}
