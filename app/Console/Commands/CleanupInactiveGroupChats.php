<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanupInactiveGroupChats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chat:cleanup-inactive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark inactive group chats for deletion and delete those past grace period';

    /**
     * Execute the console command.
     */
    public function handle(\App\Services\Chat\GroupChatService $service)
    {
        $this->info('Starting inactive chat cleanup...');

        $marked = $service->markInactiveGroupsForDeletion();
        if ($marked > 0) {
            $this->info("Marked {$marked} groups for deletion.");
        } else {
            $this->info('No new groups marked for deletion.');
        }

        $deleted = $service->cleanupMarkedGroups();
        if ($deleted > 0) {
            $this->info("Deleted {$deleted} groups.");
        } else {
            $this->info('No groups deleted.');
        }

        $this->info('Cleanup complete.');
    }
}
