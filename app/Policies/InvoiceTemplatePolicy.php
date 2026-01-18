<?php

namespace App\Policies;

use App\Models\InvoiceTemplate;
use App\Models\User;

class InvoiceTemplatePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, InvoiceTemplate $invoiceTemplate): bool
    {
        return $user->teams->contains($invoiceTemplate->team_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, InvoiceTemplate $invoiceTemplate): bool
    {
        return $user->teams->contains($invoiceTemplate->team_id);
    }

    public function delete(User $user, InvoiceTemplate $invoiceTemplate): bool
    {
        return $user->teams->contains($invoiceTemplate->team_id);
    }

    public function restore(User $user, InvoiceTemplate $invoiceTemplate): bool
    {
        return $user->teams->contains($invoiceTemplate->team_id);
    }

    public function forceDelete(User $user, InvoiceTemplate $invoiceTemplate): bool
    {
        return $user->teams->contains($invoiceTemplate->team_id);
    }
}
