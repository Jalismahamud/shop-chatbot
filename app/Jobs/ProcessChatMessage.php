<?php

namespace App\Jobs;

use App\Services\AgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessChatMessage implements ShouldQueue
{
    public int $timeout = 60;
    public int $tries   = 2;
    public function __construct(
        public Conversation $conversation,
        public string $userMessage,
    ) {}

    public function handle(AgentService $agentService): void
    {
        try {
            $response = $agentService->chat($this->conversation, $this->userMessage);
            broadcast(new ChatResponseReceived(
                conversationId: (string) $this->conversation->id,
                message: $response,
            ));
        } catch (\Exception $e) {
            broadcast(new ChatResponseReceived(
                conversationId: (string) $this->conversation->id,
                message: 'Sorry, something went wrong. Please try again!',
                role: 'error',
            ));
            throw $e;
        }
    }
}