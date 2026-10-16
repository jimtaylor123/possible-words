<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

class RetryImmediateNotificationEmails extends Command
{
    protected $signature = 'notifications:retry-immediate';

    protected $description = 'Retry failed immediate notification emails';

    public function handle(NotificationService $notifications): int
    {
        $notifications->retryImmediateEmails();

        return self::SUCCESS;
    }
}
