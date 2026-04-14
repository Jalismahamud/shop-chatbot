<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ChatBotTest extends TestCase
{
    public function it_dispatches_a_job_when_message_is_sent(): void
{
    Queue::fake();
    $this->postJson('/chat/send', ['message' => 'Hello'])->assertOk();
    Queue::assertPushed(ProcessChatMessage::class);
}

/** @test */
public function stock_tool_returns_correct_data(): void
{
    Product::factory()->create(['name' => 'Nike Air Max', 'stock' => 12]);
    $service    = new AgentService();
    $reflection = new \ReflectionMethod($service, 'toolCheckStock');
    $reflection->setAccessible(true);
    $result = $reflection->invoke($service, 'Nike');
    $this->assertEquals(1, count($result['products']));
    $this->assertStringContainsString('12 units', $result['products'][0]['stock']);
}

/** @test */
public function it_broadcasts_response_after_ai_processing(): void
{
    Event::fake([ChatResponseReceived::class]);
    $conversation = Conversation::factory()->create();

    // Mock OpenAI response
    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'Hello!', 'tool_calls' => null],
            'finish_reason' => 'stop',
        ]],
    ])]);

    (new ProcessChatMessage($conversation, 'Hi'))->handle(new AgentService());
    Event::assertDispatched(ChatResponseReceived::class);
}
}
