<?php

namespace App\Jobs;

use App\Enums\EmailFolderType;
use App\Models\Email;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PruneEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $this->pruneTrash();
        $this->pruneOldContent();
    }

    protected function pruneTrash(): void
    {
        $days = config('email.retention.trash', 30);
        $cutoff = Carbon::now()->subDays($days);

        $folders = [
            EmailFolderType::Trash->value,
            EmailFolderType::Spam->value,
            EmailFolderType::Drafts->value,
        ];

        // Force delete emails in trash/spam/drafts older than cutoff
        // We use cursor to minimize memory usage
        $count = 0;
        // Use received_at for age determination
        foreach (Email::whereIn('folder', $folders)->where('received_at', '<', $cutoff)->cursor() as $email) {
            // Use standardized prune and force delete
            $email->prune(forceDelete: true);
            $count++;
        }

        if ($count > 0) {
            Log::info("Pruned (force deleted) $count emails from Trash/Spam/Drafts older than $days days.");
        }
    }

    protected function pruneOldContent(): void
    {
        $days = config('email.retention.body', 90);
        $cutoff = Carbon::now()->subDays($days);

        $excludeFolders = [
            EmailFolderType::Trash->value,
            EmailFolderType::Spam->value,
            EmailFolderType::Drafts->value,
        ];

        // Find emails older than cutoff that still have body content
        $query = Email::whereNotIn('folder', $excludeFolders)
            ->where('received_at', '<', $cutoff)
            ->where(function ($q) {
                $q->whereNotNull('body_html')
                    ->orWhereNotNull('body_plain');
            });

        $count = 0;
        foreach ($query->cursor() as $email) {
            try {
                // Use the standardized prune method
                $email->prune();
                $count++;
            } catch (\Throwable $e) {
                Log::error("Failed to prune content for email {$email->id}: ".$e->getMessage());
            }
        }

        if ($count > 0) {
            Log::info("Pruned body content for $count emails older than $days days.");
        }
    }
}
