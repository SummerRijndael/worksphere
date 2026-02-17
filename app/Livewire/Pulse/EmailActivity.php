<?php

namespace App\Livewire\Pulse;

use App\Models\Email;
use App\Models\EmailAccount;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

#[Lazy]
class EmailActivity extends Card
{
    public function render()
    {
        // 1. Sent Emails (Last 24h)
        $sent24h = Email::where('created_at', '>=', now()->subDay())
            ->where('folder', 'sent')
            ->count();

        // 2. Received Emails (Last 24h) - Approximate by checking non-sent folders from last 24h
        $received24h = Email::where('created_at', '>=', now()->subDay())
            ->where('folder', '!=', 'sent')
            ->where('folder', '!=', 'drafts')
            ->count();

        // 3. Queued Emails (Pending Send)
        // We can check the 'emails' queue size or look for jobs in the database if using database driver
        // Or cleaner: check Emails table for 'is_draft=0' but 'sent_at=null' (if we had such a state, but we don't fully).
        // Best proxy for "Queued" in Pulse context is usually the Queue recorder itself,
        // but let's show "Scheduled" emails pending.
        $scheduledPending = Email::where('is_draft', true)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', now())
            ->count();

        // 4. Failed Accounts (Sync Status)
        $failedAccounts = EmailAccount::where('sync_status', \App\Enums\EmailSyncStatus::Failed)->count();

        return view('livewire.pulse.email-activity', [
            'sent24h' => $sent24h,
            'received24h' => $received24h,
            'scheduledPending' => $scheduledPending,
            'failedAccounts' => $failedAccounts,
        ]);
    }
}
