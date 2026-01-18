<?php

namespace App\Jobs;

use App\Models\PermissionOverride;
use App\Models\User;
use App\Notifications\PermissionChangeNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

class NotifyAdminsPermissionChange implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public PermissionOverride $override,
        public string $action,
        public User $performedBy
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Get all administrators
        $admins = User::role('administrator')
            ->where('id', '!=', $this->performedBy->id)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new PermissionChangeNotification(
            $this->override,
            $this->action,
            $this->performedBy
        ));
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'permission-change',
            'user:'.$this->override->user_id,
            'action:'.$this->action,
        ];
    }
}
