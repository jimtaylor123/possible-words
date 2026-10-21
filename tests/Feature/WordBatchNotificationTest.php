<?php

use App\Models\Word;
use App\Models\WordBatch;
use App\Models\WordBatchSlot;
use App\Models\User;
use App\Services\NotificationService;

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
