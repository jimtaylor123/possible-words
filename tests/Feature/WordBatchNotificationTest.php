<?php

use App\Models\Definition;
use App\Models\User;
use App\Models\Word;
use App\Models\WordBatch;
use App\Models\WordBatchSlot;
use App\Services\NotificationService;
use App\Services\WordBatchPublisher;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('a cohort creates one idempotent notification containing five words', function () {
    $user = User::factory()->create(['notification_email_mode' => 'daily']);
    $batch = WordBatch::create(['scheduled_for' => now('UTC'), 'target_count' => 5, 'status' => WordBatch::PUBLISHED]);
    foreach (range(1, 5) as $number) {
        $word = Word::create(['text' => "notifyword{$number}", 'slug' => "notifyword{$number}", 'status' => 'available', 'domain_status' => Word::DOMAIN_TAKEN]);
        WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => $number, 'word_id' => $word->id]);
    }

    app(NotificationService::class)->notifyNewWordBatch($batch);
    app(NotificationService::class)->notifyNewWordBatch($batch);

    $notification = $user->notifications()->sole();
    expect($notification->kind)->toBe('new_word_batch')
        ->and($notification->data['words'])->toHaveCount(5)
        ->and($notification->email_sent_at)->toBeNull();
});

test('an incomplete cohort creates no notification', function () {
    $user = User::factory()->create(['notification_email_mode' => 'daily']);
    $batch = WordBatch::create(['scheduled_for' => now('UTC'), 'target_count' => 5, 'status' => WordBatch::PUBLISHED]);
    foreach (range(1, 4) as $number) {
        $word = Word::create(['text' => "incompleteword{$number}", 'slug' => "incompleteword{$number}", 'status' => 'available', 'domain_status' => Word::DOMAIN_TAKEN]);
        WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => $number, 'word_id' => $word->id]);
    }

    expect(app(NotificationService::class)->notifyNewWordBatch($batch))->toBeFalse()
        ->and($user->notifications()->count())->toBe(0);
});

test('publishing a ready cohort creates a durable five-word notification payload', function () {
    $user = User::factory()->create(['notification_email_mode' => 'daily']);
    $batch = WordBatch::create(['scheduled_for' => now('UTC'), 'target_count' => 5, 'status' => WordBatch::READY, 'ready_at' => now()]);
    foreach (range(1, 5) as $number) {
        $word = Word::create([
            'text' => "publishedword{$number}", 'slug' => "publishedword{$number}", 'status' => 'available',
            'dictionary_status' => Word::DICTIONARY_NOT_FOUND, 'domain_status' => Word::DOMAIN_TAKEN,
            'ipa' => '/test/', 'audio_url' => "audio/{$number}.mp3", 'ai_definition_status' => Word::AI_DEFINITION_GENERATED,
        ]);
        $word->definitions()->create(['user_id' => $user->id, 'text' => 'An AI definition.', 'origin' => Definition::ORIGIN_AI, 'votes_count' => 0]);
        WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => $number, 'word_id' => $word->id]);
    }

    expect(app(WordBatchPublisher::class)->publishReady())->toBe(1);

    $notification = $user->notifications()->sole();
    expect($notification->kind)->toBe('new_word_batch')
        ->and($notification->data['words'])->toHaveCount(5)
        ->and(array_column($notification->data['words'], 'text'))->toBe(['publishedword1', 'publishedword2', 'publishedword3', 'publishedword4', 'publishedword5'])
        ->and($batch->fresh()->notification_dispatched_at)->not->toBeNull();
});
