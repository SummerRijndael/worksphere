<?php

namespace App\Events\Chat;

use App\Models\Chat\Chat;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $chatPublicId;

    public string $chatType;

    public string $senderPublicId;

    public string $signalType; // 'offer', 'answer', 'ice-candidate'

    public array $signalData;

    public string $callId;

    public ?string $targetPublicId;

    public function __construct(Chat $chat, string $senderPublicId, string $callId, string $signalType, array $signalData, ?string $targetPublicId = null)
    {
        $this->chatPublicId = $chat->public_id;
        $this->chatType = $chat->type ?? 'dm';
        $this->senderPublicId = $senderPublicId;
        $this->callId = $callId;
        $this->signalType = $signalType;
        $this->signalData = $signalData;
        $this->targetPublicId = $targetPublicId;
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callId,
            'signal_type' => $this->signalType,
            'signal_data' => $this->signalData,
            'sender_public_id' => $this->senderPublicId,
            'target_public_id' => $this->targetPublicId,
            'chat_type' => $this->chatType,
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        $prefix = $this->chatType === 'dm' ? 'dm' : 'group';

        return new PrivateChannel("{$prefix}.{$this->chatPublicId}");
    }

    public function broadcastAs(): string
    {
        return 'CallSignal';
    }
}
