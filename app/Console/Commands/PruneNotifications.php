<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Remove expired read, dismissed, and unread notifications';

    public function handle(): int
    {
        $read = DatabaseNotification::query()
            ->where(function ($query) {
                $query->whereNotNull('dismissed_at')->orWhereNotNull('read_at');
            })
            ->where('created_at', '<', now()->subDays(14))
            ->delete();

        $unread = DatabaseNotification::query()
            ->whereNull('dismissed_at')
            ->whereNull('read_at')
            ->where('created_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Pruned {$read} read or dismissed and {$unread} unread notification(s).");

        return self::SUCCESS;
    }
}
