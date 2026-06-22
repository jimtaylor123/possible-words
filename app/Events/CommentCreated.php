<?php

namespace App\Events;

use App\Models\Comment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class CommentCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $comment;
    public $definitionId;

    public function __construct(Comment $comment)
    {
        $this->comment = $comment->load('user');
        $this->definitionId = $comment->definition_id;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('definition.'.$this->definitionId.'.comments'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'comment.created';
    }
}
