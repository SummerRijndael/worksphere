<?php

namespace App\Console\Commands;

use App\Services\Chat\PresenceService;
use Illuminate\Console\Command;

class PruneStalePresence extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'presence:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune users who have timed out from the presence index';

    /**
     * Execute the console command.
     */
    public function handle(PresenceService $presenceService)
    {
        $count = $presenceService->pruneStaleUsers();
        $this->info("Pruned $count stale users.");
    }
}
