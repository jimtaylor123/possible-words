<?php

namespace App\Events;

use App\Models\Definition;
use App\Models\Vote;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class DefinitionVoted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $definitionId;

    public $votesCount;

    public $voteValue;

    public $userId;

    public function __construct(Definition $definition, Vote $vote)
    {
        $this->definitionId = $definition->id;
        $this->votesCount = $definition->votes_count;
        $this->voteValue = $vote->value;
        $this->userId = $vote->user_id;
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
