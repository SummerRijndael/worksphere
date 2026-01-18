<?php

namespace App\Jobs;

use App\Models\RoleChangeRequest;
use App\Models\User;
use App\Notifications\RoleChangeRequestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

class NotifyAdminsRoleChangeRequest implements ShouldQueue
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
        public RoleChangeRequest $request,
        public string $action = 'created'
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Get all administrators except the requester
        $admins = User::role('administrator')
            ->where('id', '!=', $this->request->requested_by)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new RoleChangeRequestNotification(
            $this->request,
            $this->action
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
            'role-change-request',
            'request:'.$this->request->id,
            'action:'.$this->action,
        ];
    }
}
