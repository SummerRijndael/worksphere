<?php

namespace App\Events\Email;

use App\Models\Email;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmailReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Email $email) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("email-account.{$this->email->emailAccount->public_id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->email->id,
            'public_id' => $this->email->public_id, // Keep public_id for channel continuity if needed
            'account_id' => $this->email->emailAccount->public_id,
        ];
    }
}
