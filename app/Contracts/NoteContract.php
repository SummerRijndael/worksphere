<?php

namespace App\Contracts;

use App\Models\Note;
use App\Models\User;

interface NoteContract
{
    /**
     * Get all notes for a user.
     */
    public function getUserNotes(User $user, int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    /**
     * Create a new note.
     */
    public function createNote(User $user, array $data): Note;

    /**
     * Update an existing note.
     */
    public function updateNote(Note $note, array $data): Note;

    /**
     * Delete a note.
     */
    public function deleteNote(Note $note): bool;

    /**
     * Reorder notes.
     */
    /**
     * Reorder notes.
     */
    public function reorderNotes(User $user, array $order): void;

    /**
     * Bulk delete notes.
     */
    public function bulkDelete(User $user, array $ids): bool;

    /**
     * Bulk update notes.
     */
    public function bulkUpdate(User $user, array $ids, array $data): bool;
}
