<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatResponseReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public function __construct(
        public string $conversationId,
        public string $message,
        public string $role = 'assistant',
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("chat.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }
}