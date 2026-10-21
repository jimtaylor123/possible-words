<?php

namespace App\Console\Commands;

use App\Models\WordBatch;
use App\Models\WordBatchSlot;
use App\Services\WordBatchMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateWordBatch extends Command
{
    protected $signature = 'words:generate-batch {--scheduled-for= : UTC ISO timestamp for replay}';

    protected $description = 'Create the durable five-slot word cohort for a UTC six-hour slot';

    public function handle(WordBatchMetrics $metrics): int
    {
        $requested = $this->option('scheduled-for');
        try {
            $scheduledFor = $requested ? CarbonImmutable::parse($requested, 'UTC') : now('UTC')->startOfHour()->subHours(now('UTC')->hour % 6);
        } catch (\Throwable) {
            $this->error('--scheduled-for must be a valid timestamp.');

            return self::FAILURE;
        }
        $scheduledFor = $scheduledFor->utc();
        $batch = DB::transaction(function () use ($scheduledFor) {
            $batch = WordBatch::firstOrCreate(['scheduled_for' => $scheduledFor], ['target_count' => WordBatch::SIZE, 'status' => WordBatch::GENERATING]);
            for ($slot = 1; $slot <= WordBatch::SIZE; $slot++) {
                WordBatchSlot::firstOrCreate(['word_batch_id' => $batch->id, 'slot_number' => $slot]);
            }

            return $batch;
        });
        if ($batch->wasRecentlyCreated) {
            $metrics->emit('BatchCreated');
        }
        $this->info("Batch {$batch->id} has ".WordBatch::SIZE." slots for {$scheduledFor->toIso8601String()}.");

        return self::SUCCESS;
    }
}
