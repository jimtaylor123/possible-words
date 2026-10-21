<?php

namespace App\Services;

use App\Models\WordBatch;
use App\Models\WordBatchSlot;
use Illuminate\Support\Facades\DB;

class WordBatchPublisher
{
    public function __construct(private WordBatchCoordinator $coordinator, private NotificationService $notifications, private WordBatchMetrics $metrics) {}

    public function publishReady(): int
    {
        $published = 0;
        WordBatch::query()->where('status', WordBatch::READY)->orderBy('scheduled_for')->each(function (WordBatch $batch) use (&$published) {
            $didPublish = DB::transaction(function () use ($batch) {
                $batch = WordBatch::query()->lockForUpdate()->find($batch->id);
                if ($batch === null || $batch->status !== WordBatch::READY) {
                    return false;
                }
                $slots = WordBatchSlot::query()->where('word_batch_id', $batch->id)->lockForUpdate()->with('word.definitions')->orderBy('slot_number')->get();
                if ($batch->target_count !== WordBatch::SIZE || $slots->count() !== WordBatch::SIZE || $slots->pluck('word_id')->filter()->unique()->count() !== WordBatch::SIZE || ! $slots->every(fn (WordBatchSlot $slot) => $slot->word !== null && $this->coordinator->ready($slot->word))) {
                    $batch->update(['status' => WordBatch::PROCESSING, 'ready_at' => null, 'last_error' => ['reason' => 'readiness_regressed']]);

                    return false;
                }
                $at = now();
                $slots->each(fn (WordBatchSlot $slot) => $slot->word->update(['published_at' => $at]));
                $batch->update(['status' => WordBatch::PUBLISHED, 'published_at' => $at, 'retry_at' => null]);

                return true;
            });
            if ($didPublish) {
                $published++;
                $this->metrics->emit('BatchPublished');
            }
        });
        WordBatch::query()->where('status', WordBatch::PUBLISHED)->whereNull('notification_dispatched_at')->each(function (WordBatch $batch) {
            if ($this->notifications->notifyNewWordBatch($batch)) {
                $batch->update(['notification_dispatched_at' => now()]);
            } else {
                $this->metrics->emit('BatchFanoutFailure');
            }
        });

        return $published;
    }
}
