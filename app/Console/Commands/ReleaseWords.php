<?php

namespace App\Console\Commands;

use App\Services\WordBatchPublisher;
use Illuminate\Console\Command;

class ReleaseWords extends Command
{
    protected $signature = 'words:release';

    protected $description = 'Publish complete ready word cohorts';

    public function handle(WordBatchPublisher $publisher): int
    {
        $this->info('Published '.$publisher->publishReady().' complete cohort(s).');

        return self::SUCCESS;
    }
}
