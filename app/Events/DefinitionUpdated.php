<?php

namespace App\Events;

use App\Models\Definition;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class DefinitionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $definition;

    public $wordId;

    public function __construct(Definition $definition)
    {
        $this->definition = $definition;
        $this->wordId = $definition->word_id;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('word.'.$this->wordId.'.definitions'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'definition.updated';
    }
}
