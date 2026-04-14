<?php


namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Product;
use OpenAI\Laravel\Facades\OpenAI;

class AgentService
{
    protected array $tools = [
        [
            'type' => 'function',
            'function' => [
                'name' => 'check_product_stock',
                'description' => 'Check product availability and stock levels by name or category.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Product name or search keyword, e.g. "running shoes", "black t-shirt"',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'calculate_shipping',
                'description' => 'Estimate shipping cost to a destination city.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'destination' => ['type' => 'string'],
                        'weight_kg'   => ['type' => 'number'],
                    ],
                    'required' => ['destination', 'weight_kg'],
                ],
            ],
        ],
    ];

    public function chat(Conversation $conversation, string $userMessage): string
    {
        // Persist user message
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role'    => 'user',
            'content' => $userMessage,
        ]);
        $history = $this->buildHistory($conversation);
        $response = OpenAI::chat()->create([
            'model'       => 'gpt-4o-mini',
            'messages'    => $history,
            'tools'       => $this->tools,
            'tool_choice' => 'auto',
        ]);
        $message = $response->choices[0]->message;
        // If AI wants to call a tool - handle it
        if ($message->toolCalls) {
            return $this->handleToolCalls($conversation, $message);
        }
        // Otherwise, save and return direct response
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role'    => 'assistant',
            'content' => $message->content,
        ]);
        return $message->content;
    }

    protected function handleToolCalls(Conversation $conversation, $message): string
    {
        $toolResults = [];
        foreach ($message->toolCalls as $toolCall) {
            $name = $toolCall->function->name;
            $args = json_decode($toolCall->function->arguments, true);
            $result = match ($name) {
                'check_product_stock' => $this->toolCheckStock($args['query']),
                'calculate_shipping'  => $this->toolShipping($args['destination'], $args['weight_kg']),
                default               => ['error' => 'Unknown tool'],
            };
            $toolResults[] = ['tool_call_id' => $toolCall->id, 'result' => $result];
            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role'        => 'tool',
                'content'     => json_encode($result),
                'tool_name'   => $name,
                'tool_result' => $result,
            ]);
        }
        // Send tool results back to AI for final answer
        $history = $this->buildHistory($conversation);
        $history[] = [
            'role' => 'assistant',
            'tool_calls' => array_map(fn($tc) => [
                'id'       => $tc->id,
                'type'     => 'function',
                'function' => ['name' => $tc->function->name, 'arguments' => $tc->function->arguments],
            ], $message->toolCalls),
        ];
        foreach ($toolResults as $tr) {
            $history[] = ['role' => 'tool', 'tool_call_id' => $tr['tool_call_id'], 'content' => json_encode($tr['result'])];
        }
        $final = OpenAI::chat()->create(['model' => 'gpt-4o-mini', 'messages' => $history]);
        $content = $final->choices[0]->message->content;
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role'    => 'assistant',
            'content' => $content,
        ]);
        return $content;
    }

    protected function buildHistory(Conversation $conversation): array
    {
        $messages = [[
            'role'    => 'system',
            'content' => "You are a friendly shopping assistant for our online store.
                Help customers find products, check stock, and calculate shipping.
                Always use tools to get accurate, real-time data - never guess.
                If a product is out of stock, suggest relevant alternatives.",
        ]];
        $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->latest()->take(20)->get()->reverse()
            ->each(fn($m) => $messages[] = ['role' => $m->role, 'content' => $m->content]);
        return $messages;
    }

    // === Tool Implementations ===
    protected function toolCheckStock(string $query): array
    {
        $products = Product::where('name', 'like', "%{$query}%")
            ->orWhere('category', 'like', "%{$query}%")
            ->select('name', 'price', 'stock', 'category')
            ->limit(5)->get();
        if ($products->isEmpty()) {
            return ['message' => 'No products found', 'products' => []];
        }
        return [
            'message'  => "{$products->count()} product(s) found",
            'products' => $products->map(fn($p) => [
                'name'     => $p->name,
                'price'    => '$' . number_format($p->price, 2),
                'stock'    => $p->stock > 0 ? "{$p->stock} units available" : 'Out of stock',
                'category' => $p->category,
            ])->toArray(),
        ];
    }

    protected function toolShipping(string $destination, float $weightKg): array
    {
        $ratePerKg = match (true) {
            str_contains(strtolower($destination), 'new york')    => 8,
            str_contains(strtolower($destination), 'los angeles') => 10,
            str_contains(strtolower($destination), 'chicago')     => 9,
            default => 12,
        };
        return [
            'destination'      => $destination,
            'estimated_cost'   => '$' . number_format($ratePerKg * max(1, $weightKg), 2),
            'estimated_arrival' => '3-5 business days',
            'carrier'          => 'FedEx Ground',
        ];
    }
}