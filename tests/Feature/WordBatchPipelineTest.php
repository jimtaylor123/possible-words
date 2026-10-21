<?php

use App\Models\Word;
use App\Models\WordBatch;
use App\Models\WordBatchSlot;
use App\Services\DictionaryService;
use App\Services\DomainAvailabilityService;
use App\Services\PronunciationService;
use App\Services\WordBatchCoordinator;
use App\Services\WordBatchMetrics;
use App\Services\WordGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('batch creation is idempotent and always creates exactly five slots', function () {
    config()->set('word-batch.size', 4);
    $scheduledFor = '2026-10-18T12:00:00Z';

    $this->artisan("words:generate-batch --scheduled-for={$scheduledFor}")->assertExitCode(0);
    $this->artisan("words:generate-batch --scheduled-for={$scheduledFor}")->assertExitCode(0);

    $batch = WordBatch::sole();
    expect($batch->target_count)->toBe(WordBatch::SIZE)
        ->and($batch->slots()->count())->toBe(WordBatch::SIZE)
        ->and($batch->slots()->pluck('slot_number')->all())->toBe([1, 2, 3, 4, 5]);
});

test('the database rejects batch sizes and slot numbers outside the five-slot invariant', function () {
    expect(fn () => DB::table('word_batches')->insert([
        'scheduled_for' => now(), 'target_count' => 4, 'status' => WordBatch::GENERATING,
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    $batch = WordBatch::create(['scheduled_for' => now()->addHour(), 'target_count' => WordBatch::SIZE]);
    expect(fn () => DB::table('word_batch_slots')->insert([
        'word_batch_id' => $batch->id, 'slot_number' => 6, 'status' => WordBatchSlot::PENDING,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('an inconclusive dictionary result retains a diagnostic and exponential retry', function () {
    $batch = WordBatch::create(['scheduled_for' => now(), 'target_count' => WordBatch::SIZE]);
    foreach (range(1, WordBatch::SIZE) as $slot) {
        $word = Word::factory()->create(['dictionary_status' => Word::DICTIONARY_UNCHECKED]);
        WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => $slot, 'word_id' => $word->id]);
    }

    $dictionary = mock(DictionaryService::class);
    $dictionary->shouldReceive('checkWord')->times(WordBatch::SIZE)->andReturn([
        'status' => Word::DICTIONARY_CHECK_FAILED, 'sources' => [], 'errors' => ['merriam_webster' => 'timeout'],
    ]);
    $coordinator = new WordBatchCoordinator(
        mock(WordGenerator::class), $dictionary, mock(PronunciationService::class), mock(DomainAvailabilityService::class), mock(WordBatchMetrics::class)
    );

    $coordinator->process($batch);

    $word = $batch->slots()->first()->word()->first();
    expect($word->dictionary_status)->toBe(Word::DICTIONARY_CHECK_FAILED)
        ->and($word->dictionary_attempts)->toBe(1)
        ->and($word->dictionary_retry_at)->not->toBeNull()
        ->and($word->dictionary_error)->toContain('timeout');
});

test('stalled batches are diagnosed and oldest active age is emitted', function () {
    config()->set('word-batch.stale_seconds', 60);
    $batch = WordBatch::create([
        'scheduled_for' => now()->subHours(7), 'target_count' => WordBatch::SIZE,
        'last_progressed_at' => now()->subMinutes(2),
    ]);
    $metrics = mock(WordBatchMetrics::class);
    $metrics->shouldReceive('emit')->with('BatchOldestActiveAge', \Mockery::on('is_numeric'), 'Seconds')->once();
    $metrics->shouldReceive('emit')->with('BatchStalled')->once();
    $coordinator = new WordBatchCoordinator(
        mock(WordGenerator::class), mock(DictionaryService::class), mock(PronunciationService::class), mock(DomainAvailabilityService::class), $metrics
    );

    $coordinator->monitorStalled();

    expect($batch->fresh()->status)->toBe(WordBatch::STALLED)
        ->and($batch->fresh()->stalled_at)->not->toBeNull()
        ->and($batch->fresh()->last_error)->toBe(['reason' => 'stalled_batch']);
});

test('the production manifest alarms on stalled cohort age', function () {
    $serverless = file_get_contents(base_path('serverless.yml'));

    expect($serverless)->toContain('WordBatchStalledAlarm')
        ->toContain('MetricName: BatchOldestActiveAge')
        ->toContain('TreatMissingData: breaching');
});
