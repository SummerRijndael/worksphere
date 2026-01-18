<?php

namespace App\Events\Email;

use App\Models\EmailAccount;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public EmailAccount $account,
        public string $status,
        public ?string $error = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("email-account.{$this->account->public_id}"),
        ];
    }
}
