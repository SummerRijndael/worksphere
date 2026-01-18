<?php

namespace App\Http\Controllers\Api;

use App\Contracts\NoteContract;
use App\Http\Controllers\Controller;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    public function __construct(protected NoteContract $noteService)
    {
        //
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);

        // Ensure per_page is valid
        if (! in_array($perPage, [20, 50, 100, 200])) {
            $perPage = 20;
        }

        return NoteResource::collection($this->noteService->getUserNotes($request->user(), (int) $perPage));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'color' => 'nullable|string|max:50',
            'is_pinned' => 'boolean',
            'position' => 'integer',
        ]);

        $note = $this->noteService->createNote($request->user(), $validated);

        return new NoteResource($note);
    }

    /**
     * Display the specified resource.
     */
    public function show(Note $note)
    {
        Gate::authorize('view', $note);

        return new NoteResource($note);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Note $note)
    {
        Gate::authorize('update', $note);

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'color' => 'nullable|string|max:50',
            'is_pinned' => 'boolean',
            'position' => 'integer',
        ]);

        $updatedNote = $this->noteService->updateNote($note, $validated);

        return new NoteResource($updatedNote);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Note $note)
    {
        Gate::authorize('delete', $note);

        $this->noteService->deleteNote($note);

        return response()->noContent();
    }

    /**
     * Reorder notes.
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'string|exists:notes,public_id',
        ]);

        $this->noteService->reorderNotes($request->user(), $validated['order']);

        return response()->noContent();
    }

    /**
     * Bulk delete notes.
     */
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'string|exists:notes,public_id',
        ]);

        $this->noteService->bulkDelete($request->user(), $validated['ids']);

        return response()->noContent();
    }

    /**
     * Bulk update notes.
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'string|exists:notes,public_id',
            'is_pinned' => 'required|boolean', // Only allow pinning for now
        ]);

        $this->noteService->bulkUpdate($request->user(), $validated['ids'], [
            'is_pinned' => $validated['is_pinned'],
        ]);

        return response()->noContent();
    }
}
