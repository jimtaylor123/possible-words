<?php

use App\Models\Definition;
use App\Models\User;
use App\Models\Word;
use App\Models\WordBatch;
use App\Models\WordBatchSlot;
use App\Services\DictionaryService;
use App\Services\DomainAvailabilityService;
use App\Services\PronunciationService;
use App\Services\WordBatchCoordinator;
use App\Services\WordBatchMetrics;
use App\Services\WordBatchRunLock;
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

test('batch creation emits its metric once and accepts aligned historical replay', function () {
    $metrics = mock(WordBatchMetrics::class);
    $metrics->shouldReceive('emit')->once()->with('BatchCreated');
    app()->instance(WordBatchMetrics::class, $metrics);

    $this->artisan('words:generate-batch --scheduled-for=2020-01-01T00:00:00Z')
        ->expectsOutputToContain('Batch')
        ->assertExitCode(0);
    $this->artisan('words:generate-batch --scheduled-for=2020-01-01T00:00:00Z')->assertExitCode(0);

    expect(WordBatch::where('scheduled_for', '2020-01-01 00:00:00')->count())->toBe(1)
        ->and(WordBatchSlot::count())->toBe(WordBatch::SIZE);
});

test('batch creation rejects malformed and non-six-hour schedule timestamps', function () {
    $this->artisan('words:generate-batch --scheduled-for=not-a-date')
        ->expectsOutput('--scheduled-for must be a valid timestamp.')
        ->assertExitCode(1);
    $this->artisan('words:generate-batch --scheduled-for=2026-10-18T13:00:00Z')
        ->expectsOutput('--scheduled-for must align to a UTC six-hour slot.')
        ->assertExitCode(1);

    expect(WordBatch::count())->toBe(0);
});

test('batch processor processes its limit and releases its lease', function () {
    WordBatch::create(['scheduled_for' => now()->subHours(12), 'target_count' => WordBatch::SIZE]);
    WordBatch::create(['scheduled_for' => now()->subHours(6), 'target_count' => WordBatch::SIZE]);
    $lock = mock(WordBatchRunLock::class);
    $lock->shouldReceive('acquire')->once()->andReturn('lease-token');
    $lock->shouldReceive('release')->once()->with('lease-token');
    $coordinator = mock(WordBatchCoordinator::class);
    $coordinator->shouldReceive('process')->once();
    $coordinator->shouldReceive('monitorStalled')->once();
    app()->instance(WordBatchRunLock::class, $lock);
    app()->instance(WordBatchCoordinator::class, $coordinator);

    $this->artisan('words:process-batches --limit=1 --max-seconds=60')
        ->expectsOutput('Processed 1 word batch(es).')
        ->assertExitCode(0);
});

test('batch processor skips when its lease is held', function () {
    $lock = mock(WordBatchRunLock::class);
    $lock->shouldReceive('acquire')->once()->andReturnNull();
    $lock->shouldNotReceive('release');
    app()->instance(WordBatchRunLock::class, $lock);

    $this->artisan('words:process-batches')
        ->expectsOutput('Another batch processor holds the database lease — skipping.')
        ->assertExitCode(0);
});

test('batch processor respects its time budget', function () {
    WordBatch::create(['scheduled_for' => now()->subHours(6), 'target_count' => WordBatch::SIZE]);
    $lock = mock(WordBatchRunLock::class);
    $lock->shouldReceive('acquire')->once()->andReturn('lease-token');
    $lock->shouldReceive('release')->once()->with('lease-token');
    $coordinator = mock(WordBatchCoordinator::class);
    $coordinator->shouldNotReceive('process');
    $coordinator->shouldReceive('monitorStalled')->once();
    app()->instance(WordBatchRunLock::class, $lock);
    app()->instance(WordBatchCoordinator::class, $coordinator);

    $this->artisan('words:process-batches --max-seconds=-1')
        ->expectsOutput('Processed 0 word batch(es).')
        ->assertExitCode(0);
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

test('empty slots do not retry generation before their persisted retry time', function () {
    $batch = WordBatch::create(['scheduled_for' => now(), 'target_count' => WordBatch::SIZE]);
    $user = User::factory()->create();
    WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => 1]);
    foreach (range(2, WordBatch::SIZE) as $slot) {
        $word = Word::factory()->create([
            'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
            'domain_status' => Word::DOMAIN_AVAILABLE,
            'ipa' => '/test/',
            'audio_url' => 'audio/test.mp3',
        ]);
        Definition::create(['word_id' => $word->id, 'user_id' => $user->id, 'text' => 'An AI definition.', 'origin' => Definition::ORIGIN_AI, 'votes_count' => 0]);
        WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => $slot, 'word_id' => $word->id]);
    }
    $generator = mock(WordGenerator::class);
    $generator->shouldReceive('generateWords')->once()->with(1)->andReturn([]);
    $metrics = mock(WordBatchMetrics::class);
    $metrics->shouldReceive('emit')->with('BatchRetry')->once();
    $coordinator = new WordBatchCoordinator($generator, mock(DictionaryService::class), mock(PronunciationService::class), mock(DomainAvailabilityService::class), $metrics);

    $coordinator->process($batch);
    $retryAt = $batch->slots()->findOrFail(1)->retry_at;
    $coordinator->process($batch->fresh());

    expect($retryAt)->not->toBeNull()
        ->and($batch->slots()->findOrFail(1)->retry_at->equalTo($retryAt))->toBeTrue();
});

test('rejected slots do not replace their candidate before their persisted retry time', function () {
    $batch = WordBatch::create(['scheduled_for' => now(), 'target_count' => WordBatch::SIZE]);
    $user = User::factory()->create();
    WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => 1, 'status' => WordBatchSlot::REJECTED, 'retry_at' => now()->addHour()]);
    foreach (range(2, WordBatch::SIZE) as $slot) {
        $word = Word::factory()->create([
            'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
            'domain_status' => Word::DOMAIN_AVAILABLE,
            'ipa' => '/test/',
            'audio_url' => 'audio/test.mp3',
        ]);
        Definition::create(['word_id' => $word->id, 'user_id' => $user->id, 'text' => 'An AI definition.', 'origin' => Definition::ORIGIN_AI, 'votes_count' => 0]);
        WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => $slot, 'word_id' => $word->id]);
    }
    $generator = mock(WordGenerator::class);
    $generator->shouldNotReceive('generateWords');
    $coordinator = new WordBatchCoordinator($generator, mock(DictionaryService::class), mock(PronunciationService::class), mock(DomainAvailabilityService::class), mock(WordBatchMetrics::class));

    $coordinator->process($batch);

    expect($batch->slots()->findOrFail(1)->status)->toBe(WordBatchSlot::REJECTED)
        ->and($batch->slots()->findOrFail(1)->word_id)->toBeNull();
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
