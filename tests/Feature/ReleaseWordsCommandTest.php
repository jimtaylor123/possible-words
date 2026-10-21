<?php

use App\Models\Definition;
use App\Models\Word;
use App\Models\WordBatch;
use App\Models\WordBatchSlot;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function readyBatch(bool $ready = true): WordBatch
{
    $batch = WordBatch::create(['scheduled_for' => now('UTC')->subHours(6), 'target_count' => 5, 'status' => WordBatch::READY, 'ready_at' => now()]);
    $user = User::factory()->create();
    foreach (range(1, 5) as $number) {
        $word = Word::create(['text' => "batchword{$number}", 'slug' => "batchword{$number}", 'status' => 'available', 'dictionary_status' => Word::DICTIONARY_NOT_FOUND, 'domain_status' => Word::DOMAIN_AVAILABLE, 'ipa' => '/test/', 'audio_url' => "audio/{$number}.mp3", 'ai_definition_status' => Word::AI_DEFINITION_GENERATED]);
        Definition::create(['word_id' => $word->id, 'user_id' => $user->id, 'text' => 'An AI definition.', 'origin' => Definition::ORIGIN_AI, 'votes_count' => 0]);
        WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => $number, 'word_id' => $word->id]);
        if (! $ready && $number === 5) $word->update(['audio_url' => null]);
    }
    return $batch;
}

describe('releasing word cohorts via artisan', function () {
    test('publishes exactly five ready cohort words with a shared timestamp', function () {
        $batch = readyBatch();
        $legacy = Word::create(['text' => 'legacyinventory', 'slug' => 'legacyinventory', 'status' => 'available', 'dictionary_status' => Word::DICTIONARY_NOT_FOUND]);

        $this->artisan('words:release')->expectsOutputToContain('Published 1 complete cohort(s).')->assertExitCode(0);

        $words = $batch->fresh()->slots()->with('word')->get()->pluck('word');
        expect($batch->fresh()->status)->toBe(WordBatch::PUBLISHED)
            ->and($words->pluck('published_at')->unique()->count())->toBe(1)
            ->and($words->filter(fn (Word $word) => $word->published_at !== null))->toHaveCount(5)
            ->and($legacy->fresh()->published_at)->toBeNull();
    });

    test('does not publish an incomplete cohort and returns it to processing', function () {
        $batch = readyBatch(false);

        $this->artisan('words:release')->assertExitCode(0);

        expect($batch->fresh()->status)->toBe(WordBatch::PROCESSING)
            ->and($batch->fresh()->published_at)->toBeNull()
            ->and($batch->slots()->with('word')->get()->pluck('word')->every(fn (Word $word) => $word->published_at === null))->toBeTrue();
    });

    test('is idempotent after cohort publication', function () {
        $batch = readyBatch();
        $this->artisan('words:release')->assertExitCode(0);
        $publishedAt = $batch->fresh()->published_at;
        $this->artisan('words:release')->expectsOutputToContain('Published 0 complete cohort(s).')->assertExitCode(0);
        expect($batch->fresh()->published_at->equalTo($publishedAt))->toBeTrue();
    });
});
