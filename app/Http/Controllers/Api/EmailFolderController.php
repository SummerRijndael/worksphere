<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailFolderController extends Controller
{
    /**
     * List folders.
     */
    public function index(Request $request): JsonResponse
    {
        $folders = EmailFolder::forUser($request->user()->id)
            ->ordered()
            ->get();

        return response()->json($folders);
    }

    /**
     * Create folder.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('email_folders')->where('user_id', $request->user()->id),
            ],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $folder = $request->user()->emailFolders()->create($validated);

        return response()->json($folder, 201);
    }

    /**
     * Update folder.
     */
    public function update(Request $request, EmailFolder $emailFolder): JsonResponse
    {
        $this->authorize('update', $emailFolder);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('email_folders')
                    ->where('user_id', $request->user()->id)
                    ->ignore($emailFolder->id),
            ],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $emailFolder->update($validated);

        return response()->json($emailFolder);
    }

    /**
     * Delete folder.
     */
    public function destroy(EmailFolder $emailFolder): JsonResponse
    {
        $this->authorize('delete', $emailFolder);

        $emailFolder->delete();

        return response()->json(['message' => 'Folder deleted']);
    }
}
