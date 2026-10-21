<?php

namespace App\Console\Commands;

use App\Models\WordBatch;
use App\Models\WordBatchSlot;
use App\Services\WordBatchMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class GenerateWordBatch extends Command
{
    protected $signature = 'words:generate-batch {--scheduled-for= : UTC ISO timestamp for replay}';

    protected $description = 'Create the durable five-slot word cohort for a UTC six-hour slot';

    public function handle(WordBatchMetrics $metrics): int
    {
        $requested = $this->option('scheduled-for');
        try {
            $now = now('UTC');
            $scheduledFor = $requested ? CarbonImmutable::parse($requested, 'UTC') : $now->startOfHour()->subHours($now->hour % 6);
        } catch (\Throwable) {
            $this->error('--scheduled-for must be a valid timestamp.');

            return self::FAILURE;
        }
        $scheduledFor = $scheduledFor->utc();
        if ($scheduledFor->minute !== 0 || $scheduledFor->second !== 0 || $scheduledFor->microsecond !== 0 || $scheduledFor->hour % 6 !== 0) {
            $this->error('--scheduled-for must align to a UTC six-hour slot.');

            return self::FAILURE;
        }
        try {
            $batch = WordBatch::firstOrCreate(['scheduled_for' => $scheduledFor], ['target_count' => WordBatch::SIZE, 'status' => WordBatch::GENERATING]);
        } catch (QueryException $exception) {
            $batch = WordBatch::query()->where('scheduled_for', $scheduledFor)->first();
            if ($batch === null) {
                throw $exception;
            }
        }
        DB::transaction(function () use ($batch) {
            for ($slot = 1; $slot <= WordBatch::SIZE; $slot++) {
                WordBatchSlot::query()->insertOrIgnore(['word_batch_id' => $batch->id, 'slot_number' => $slot, 'status' => WordBatchSlot::PENDING, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        if ($batch->wasRecentlyCreated) {
            $metrics->emit('BatchCreated');
        }
        $this->info("Batch {$batch->id} has ".WordBatch::SIZE." slots for {$scheduledFor->toIso8601String()}.");

        return self::SUCCESS;
    }
}
