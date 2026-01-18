<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class InvoicePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any invoices.
     */
    public function viewAny(User $user, Team $team): bool
    {
        // Check if user is team member
        if (! $team->hasMember($user)) {
            return false;
        }

        return $user->can('invoices.view');
    }

    /**
     * Determine whether the user can view the invoice.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        // Clients can view their own invoices
        if ($user->hasRole('client')) {
            $client = $user->linkedClient;

            return $client && $invoice->client_id === $client->id;
        }

        // Team members with permission can view
        $team = $invoice->team;
        if (! $team->hasMember($user)) {
            return false;
        }

        return $user->can('invoices.view');
    }

    /**
     * Determine whether the user can create invoices.
     */
    public function create(User $user, Team $team): bool
    {
        // Check if user is team member
        if (! $team->hasMember($user)) {
            return false;
        }

        return $user->can('invoices.create');
    }

    /**
     * Determine whether the user can update the invoice.
     */
    public function update(User $user, Invoice $invoice): bool
    {
        // Only allow editing draft invoices
        if (! $invoice->can_edit) {
            return false;
        }

        $team = $invoice->team;
        if (! $team->hasMember($user)) {
            return false;
        }

        return $user->can('invoices.update');
    }

    /**
     * Determine whether the user can delete the invoice.
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        $team = $invoice->team;
        if (! $team->hasMember($user)) {
            return false;
        }

        return $user->can('invoices.delete');
    }

    /**
     * Determine whether the user can send the invoice.
     */
    public function send(User $user, Invoice $invoice): bool
    {
        // Only allow sending if invoice can be sent
        if (! $invoice->can_send) {
            return false;
        }

        $team = $invoice->team;
        if (! $team->hasMember($user)) {
            return false;
        }

        return $user->can('invoices.send');
    }

    /**
     * Determine whether the user can record a payment.
     */
    public function recordPayment(User $user, Invoice $invoice): bool
    {
        // Only allow recording payment if invoice is in appropriate status
        if (! $invoice->can_record_payment) {
            return false;
        }

        $team = $invoice->team;
        if (! $team->hasMember($user)) {
            return false;
        }

        return $user->can('invoices.record_payment');
    }

    /**
     * Determine whether the user can download the invoice PDF.
     */
    public function downloadPdf(User $user, Invoice $invoice): bool
    {
        // Same as view permission
        return $this->view($user, $invoice);
    }

    /**
     * Determine whether the user can cancel the invoice.
     */
    public function cancel(User $user, Invoice $invoice): bool
    {
        // Cannot cancel paid invoices
        if ($invoice->status === \App\Enums\InvoiceStatus::Paid) {
            return false;
        }

        $team = $invoice->team;
        if (! $team->hasMember($user)) {
            return false;
        }

        return $user->can('invoices.update');
    }
}
