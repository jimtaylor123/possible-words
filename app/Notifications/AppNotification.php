<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class AppNotification extends Notification implements ShouldBroadcast
{
    use Queueable;

    public $id;

    /** @param array<string, mixed> $data */
    public function __construct(public array $data, ?string $id = null)
    {
        $this->id = $id ?? (string) Str::uuid();
    }

    public function via(object $notifiable): array
    {
        return ['broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->data;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage(['id' => $this->id, 'data' => $this->data, 'created_at' => now()->toISOString()]))
            ->onQueue(config('queue.queues.broadcasts'));
    }
}
