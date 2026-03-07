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
        return app(\App\Services\PermissionService::class)->hasTeamPermission($user, $team, 'invoices.view') ||
               app(\App\Services\PermissionService::class)->hasTeamPermission($user, $team, 'invoices.manage');
    }

    /**
     * Determine whether the user can view the invoice.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        $team = $invoice->team;

        // Clients can view their own invoices
        if ($user->hasRole('client')) {
            $client = $user->linkedClient;

            return $client && $invoice->client_id === $client->id;
        }

        return app(\App\Services\PermissionService::class)->hasTeamPermission($user, $team, 'invoices.view') ||
               app(\App\Services\PermissionService::class)->hasTeamPermission($user, $team, 'invoices.manage');
    }

    /**
     * Determine whether the user can create invoices.
     */
    public function create(User $user, Team $team): bool
    {
        return app(\App\Services\PermissionService::class)->hasTeamPermission($user, $team, 'invoices.create') ||
               app(\App\Services\PermissionService::class)->hasTeamPermission($user, $team, 'invoices.manage');
    }

    /**
     * Determine whether the user can update the invoice.
     */
    public function update(User $user, Invoice $invoice): bool
    {
        // Check if status allows editing (only Draft)
        if (! $invoice->status->canEdit()) {
            return false;
        }

        return app(\App\Services\PermissionService::class)->hasTeamPermission($user, $invoice->team, 'invoices.update') ||
               app(\App\Services\PermissionService::class)->hasTeamPermission($user, $invoice->team, 'invoices.manage');
    }

    /**
     * Determine whether the user can delete the invoice.
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        return app(\App\Services\PermissionService::class)->hasTeamPermission($user, $invoice->team, 'invoices.manage');
    }

    /**
     * Determine whether the user can send the invoice.
     */
    public function send(User $user, Invoice $invoice): bool
    {
        if (! $invoice->can_send) {
            return false;
        }

        return app(\App\Services\PermissionService::class)->hasTeamPermission($user, $invoice->team, 'invoices.send') ||
               app(\App\Services\PermissionService::class)->hasTeamPermission($user, $invoice->team, 'invoices.manage');
    }

    /**
     * Determine whether the user can record a payment.
     */
    public function recordPayment(User $user, Invoice $invoice): bool
    {
        if (! $invoice->can_record_payment) {
            return false;
        }

        return app(\App\Services\PermissionService::class)->hasTeamPermission($user, $invoice->team, 'invoices.record_payment') ||
               app(\App\Services\PermissionService::class)->hasTeamPermission($user, $invoice->team, 'invoices.manage');
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

        return app(\App\Services\PermissionService::class)->hasTeamPermission($user, $invoice->team, 'invoices.manage');
    }
}
