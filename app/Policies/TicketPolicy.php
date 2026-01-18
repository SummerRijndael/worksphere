<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('tickets.view')
            || $user->hasPermissionTo('tickets.view_own')
            || $user->hasRole('administrator');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        // Admin or full view permission
        if ($user->hasPermissionTo('tickets.view') || $user->hasRole('administrator')) {
            return true;
        }

        // Can view own tickets (reporter or assignee)
        if ($user->hasPermissionTo('tickets.view_own')) {
            return $ticket->reporter_id === $user->id
                || $ticket->assigned_to === $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('tickets.create') || $user->hasRole('administrator');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        // Admin or full update permission
        if ($user->hasPermissionTo('tickets.update') || $user->hasRole('administrator')) {
            return true;
        }

        // Can update own tickets
        if ($user->hasPermissionTo('tickets.update_own')) {
            return $ticket->reporter_id === $user->id;
        }

        // Assignees can update their tickets
        if ($ticket->assigned_to === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->hasPermissionTo('tickets.delete') || $user->hasRole('administrator');
    }

    /**
     * Determine whether the user can assign the ticket.
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->hasPermissionTo('tickets.assign') || $user->hasRole('administrator');
    }

    /**
     * Determine whether the user can close the ticket.
     */
    public function close(User $user, Ticket $ticket): bool
    {
        return $user->hasPermissionTo('tickets.close') || $user->hasRole('administrator');
    }

    /**
     * Determine whether the user can view internal notes.
     */
    public function viewInternalNotes(User $user, Ticket $ticket): bool
    {
        return $user->hasPermissionTo('tickets.internal_notes') || $user->hasRole('administrator');
    }

    /**
     * Determine whether the user can add internal notes.
     */
    public function addInternalNote(User $user, Ticket $ticket): bool
    {
        return $user->hasPermissionTo('tickets.internal_notes')
            || $user->hasRole('administrator')
            || $ticket->assigned_to === $user->id;
    }

    /**
     * Determine whether the user can view unassigned tickets.
     */
    public function viewUnassigned(User $user): bool
    {
        return $user->hasPermissionTo('tickets.view') || $user->hasRole('administrator');
    }

    /**
     * Determine whether the user can add comments.
     */
    public function addComment(User $user, Ticket $ticket): bool
    {
        // Anyone who can view the ticket can comment on it
        return $this->view($user, $ticket);
    }

    /**
     * Determine whether the user can follow the ticket.
     */
    public function follow(User $user, Ticket $ticket): bool
    {
        // Anyone who can view the ticket can follow it
        return $this->view($user, $ticket);
    }
}
