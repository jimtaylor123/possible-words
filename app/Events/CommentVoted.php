<?php

namespace App\Events;

use App\Models\Comment;
use App\Models\CommentVote;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class CommentVoted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $commentId;
    public $votesCount;
    public $voteValue;
    public $userId;

    public function __construct(Comment $comment, CommentVote $vote)
    {
        $this->commentId = $comment->id;
        $this->votesCount = $comment->votes_count;
        $this->voteValue = $vote->value;
        $this->userId = $vote->user_id;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('comment.'.$this->commentId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'comment.voted';
    }
}
