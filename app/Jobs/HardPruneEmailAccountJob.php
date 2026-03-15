<?php

namespace App\Jobs;

use App\Models\Email;
use App\Models\EmailAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class HardPruneEmailAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $accountId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $account = EmailAccount::query()->where('id', $this->accountId)->first();
        if (! $account) {
            return;
        }

        Log::info("[HardPruneEmailAccount] Starting hard prune for account", [
            'account_id' => $account->id,
            'email' => $account->email,
        ]);

        $count = 0;
        // Fetch all emails (including soft-deleted ones)
        $emails = Email::query()
            ->withTrashed()
            ->where('email_account_id', $this->accountId);
        
        foreach ($emails->cursor() as $email) {
            /** @var Email $email */
            try {
                // Hard prune: wipe body, delete files, force delete record
                $email->prune(forceDelete: true);
                $count++;
            } catch (\Throwable $e) {
                Log::error("[HardPruneEmailAccount] Failed to prune email {$email->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("[HardPruneEmailAccount] Completed hard prune for account", [
            'account_id' => $account->id,
            'emails_pruned' => $count,
        ]);

        // Finally, delete the account record itself
        $account->delete();
    }
}
