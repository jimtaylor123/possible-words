<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:send-digests';

    protected $description = 'Send daily notification email digests';

    public function handle(NotificationService $notifications): int
    {
        $notifications->sendDailyDigests();

        return self::SUCCESS;
    }
}
