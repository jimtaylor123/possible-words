<?php

use App\Http\Controllers\NotificationController;
use App\Models\User;
use App\Models\Word;
use App\Models\WordBatch;
use App\Models\WordBatchSlot;
use App\Services\NotificationService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function browsingBatch(): WordBatch
{
    $batch = WordBatch::create(['scheduled_for' => now(), 'target_count' => 5, 'status' => WordBatch::PUBLISHED]);
    foreach (range(1, 5) as $number) {
        $word = Word::create([
            'text' => "cohortword{$number}", 'slug' => "cohortword{$number}", 'syllables' => 2,
            'status' => 'available', 'dictionary_status' => Word::DICTIONARY_NOT_FOUND, 'published_at' => now(),
        ]);
        WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => $number, 'word_id' => $word->id]);
    }
    Word::create(['text' => 'cohortoutsider', 'slug' => 'cohortoutsider', 'status' => 'available', 'dictionary_status' => Word::DICTIONARY_NOT_FOUND, 'published_at' => now()]);

    return $batch;
}

test('batch notifications and historical notification payloads point to a scoped list', function () {
    $user = User::factory()->create(['notification_email_mode' => 'off']);
    $batch = browsingBatch();
    app(NotificationService::class)->notifyNewWordBatch($batch);
    $notification = $user->notifications()->sole();
    expect($notification->data['url'])->toBe(route('home', ['batch' => $batch->id]));

    $data = $notification->data;
    $data['url'] = route('words.show', $batch->slots()->first()->word);
    $notification->update(['data' => $data]);
    expect(NotificationController::forUser($user)['items']->sole()['data']['url'])->toBe(route('home', ['batch' => $batch->id]));
});

test('batch browsing excludes outsiders and preserves scope alongside other filters and pagination', function () {
    $batch = browsingBatch();
    $this->get(route('home', ['batch' => $batch->id]))->assertInertia(fn ($page) => $page
        ->component('Home')->has('words.data', 5)->where('words.total', 5)->where('filters.batch', $batch->id));
    $this->get(route('home', ['batch' => $batch->id, 'search' => 'word3', 'sort' => 'alphabetical', 'direction' => 'asc']))->assertInertia(fn ($page) => $page
        ->has('words.data', 1)->where('words.data.0.slug', 'cohortword3')->where('filters.batch', $batch->id));
    $this->get(route('home', ['batch' => $batch->id, 'page' => 2]))->assertInertia(fn ($page) => $page->has('words.data', 0)->where('words.total', 5));
    $this->get(route('words.suggestions', ['q' => 'cohort', 'batch' => $batch->id]))->assertJsonCount(5)->assertJsonMissing(['slug' => 'cohortoutsider']);
});

test('batch browsing continues to respect word and batch visibility', function () {
    $batch = browsingBatch();
    $batch->slots()->first()->word->update(['published_at' => null]);
    $this->get(route('home', ['batch' => $batch->id]))->assertInertia(fn ($page) => $page->has('words.data', 4));
    $batch->update(['status' => WordBatch::PROCESSING]);
    $this->get(route('home', ['batch' => $batch->id]))->assertInertia(fn ($page) => $page->has('words.data', 0));
    $this->get(route('words.suggestions', ['q' => 'cohort', 'batch' => $batch->id]))->assertExactJson([]);
});

test('invalid or unknown batch filters never fall back to the entire catalogue', function ($batch) {
    browsingBatch();
    $this->get(route('home', ['batch' => $batch]))->assertInertia(fn ($page) => $page->has('words.data', 0));
    $this->get(route('words.suggestions', ['q' => 'cohort', 'batch' => $batch]))->assertExactJson([]);
})->with(['unknown' => 99999, 'zero' => 0, 'negative' => -1, 'text' => 'oops', 'array' => [[1]], 'blank' => '']);
