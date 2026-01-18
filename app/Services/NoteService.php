<?php

namespace App\Services;

use App\Contracts\NoteContract;
use App\Events\PersonalNoteCreated;
use App\Events\PersonalNoteDeleted;
use App\Events\PersonalNoteUpdated;
use App\Models\Note;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class NoteService implements NoteContract
{
    public function getUserNotes(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $user->notes()
            ->orderBy('position')
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    public function createNote(User $user, array $data): Note
    {
        return DB::transaction(function () use ($user, $data) {
            // If position is not provided, making it the first one (0) and shifting others?
            // Or just append. Let's append to position 0 and shift others if we want it at top.
            // For now, simpler approach: set position to max + 1.
            // Actually, default is 0.

            $note = $user->notes()->create($data);

            // Broadcast event
            broadcast(new PersonalNoteCreated($note));

            return $note;
        });
    }

    public function updateNote(Note $note, array $data): Note
    {
        $note->update($data);

        broadcast(new PersonalNoteUpdated($note));

        return $note;
    }

    public function deleteNote(Note $note): bool
    {
        $result = $note->delete();

        if ($result) {
            broadcast(new PersonalNoteDeleted($note->public_id, $note->user->public_id));
        }

        return $result;
    }

    public function reorderNotes(User $user, array $order): void
    {
        // $order is array of public_ids in order
        DB::transaction(function () use ($user, $order) {
            foreach ($order as $index => $publicId) {
                $user->notes()->where('public_id', $publicId)->update(['position' => $index]);
            }
        });

        // Maybe broadcast a reorder event?
        // broadcast(new PersonalNotesReordered($user));
    }

    public function bulkDelete(User $user, array $ids): bool
    {
        // Fetch notes to get their IDs for broadcasting (and verification ownership)
        $notes = $user->notes()->whereIn('public_id', $ids)->get();

        if ($notes->isEmpty()) {
            return false;
        }

        $count = $user->notes()->whereIn('public_id', $ids)->delete();

        if ($count > 0) {
            foreach ($notes as $note) {
                broadcast(new PersonalNoteDeleted($note->public_id, $user->public_id));
            }
        }

        return $count > 0;
    }

    public function bulkUpdate(User $user, array $ids, array $data): bool
    {
        // Only allow updating is_pinned for now to be safe, or allow all?
        // Controller validation should handle safety.

        $count = $user->notes()->whereIn('public_id', $ids)->update($data);

        // We need to broadcast updates. Since update() is bulk, it doesn't fire model events.
        // We might need to fetch them to broadcast individual updates or a bulk update event.
        // For simplicity/realtime responsiveness, let's fetch and broadcast.
        if ($count > 0) {
            $notes = $user->notes()->whereIn('public_id', $ids)->get();
            foreach ($notes as $note) {
                broadcast(new PersonalNoteUpdated($note));
            }
        }

        return $count > 0;
    }
}
