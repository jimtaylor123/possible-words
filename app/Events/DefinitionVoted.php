<?php

namespace App\Events;

use App\Models\Definition;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Auth;

class DefinitionVoted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $definitionId;

    public $votesCount;

    public $liked;

    public $userId;

    public function __construct(Definition $definition, bool $liked)
    {
        $this->definitionId = $definition->id;
        $this->votesCount = $definition->votes_count;
        $this->liked = $liked;
        $this->userId = Auth::id();
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('definition.'.$this->definitionId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'definition.voted';
    }
}
