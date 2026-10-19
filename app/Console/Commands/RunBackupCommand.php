<?php

namespace App\Console\Commands;

use App\Backups\BackupService;
use Illuminate\Console\Command;
use Throwable;

class RunBackupCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Create and verify a Turso and object-storage backup';

    public function handle(BackupService $backup): int
    {
        try {
            $this->info('Verified backup completed: '.$backup->run());

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Backup failed. See Lambda logs for details.');

            return self::FAILURE;
        }
    }
}
