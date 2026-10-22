<?php

namespace App\Console\Commands;

use App\Backups\BackupMetricPublisher;
use App\Backups\BackupService;
use Illuminate\Console\Command;

class CheckBackupFreshnessCommand extends Command
{
    protected $signature = 'backup:check-freshness';

    protected $description = 'Fail when no verified backup is younger than six hours and fifteen minutes';

    public function handle(BackupService $backup, BackupMetricPublisher $metrics): int
    {
        $fresh = $backup->isFresh();
        $metrics->publish('BackupFreshness', $fresh ? 1 : 0);

        if (! $fresh) {
            $this->error('No fresh verified backup completion manifest exists.');

            return self::FAILURE;
        }

        $this->info('Latest verified backup is fresh.');

        return self::SUCCESS;
    }
}
