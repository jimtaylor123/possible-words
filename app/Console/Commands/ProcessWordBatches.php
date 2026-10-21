<?php

namespace App\Console\Commands;

use App\Models\WordBatch;
use App\Services\WordBatchCoordinator;
use App\Services\WordBatchRunLock;
use Illuminate\Console\Command;

class ProcessWordBatches extends Command
{
    protected $signature = 'words:process-batches {--limit= : Maximum cohorts} {--max-seconds= : Time budget}';
    protected $description = 'Progress due word cohorts under a durable database lease';

    public function handle(WordBatchRunLock $lock, WordBatchCoordinator $coordinator): int
    {
        $token = $lock->acquire();
        if ($token === null) { $this->info('Another batch processor holds the database lease — skipping.'); return self::SUCCESS; }
        $limit = (int) ($this->option('limit') ?: config('word-batch.coordinator_items'));
        $deadline = microtime(true) + (int) ($this->option('max-seconds') ?: config('word-batch.coordinator_seconds'));
        try {
            $processed = 0;
            WordBatch::due()->orderBy('scheduled_for')->limit($limit)->each(function (WordBatch $batch) use ($coordinator, $deadline, &$processed) {
                if (microtime(true) >= $deadline) return false;
                $coordinator->process($batch);
                $processed++;
                return true;
            });
            $this->info("Processed {$processed} word batch(es).");
            return self::SUCCESS;
        } finally { $lock->release($token); }
    }
}
