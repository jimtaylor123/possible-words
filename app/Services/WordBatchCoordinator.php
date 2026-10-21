<?php

namespace App\Services;

use App\Jobs\GenerateWordDefinition;
use App\Models\Definition;
use App\Models\Word;
use App\Models\WordBatch;
use App\Models\WordBatchSlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WordBatchCoordinator
{
    public function __construct(
        private WordGenerator $generator,
        private DictionaryService $dictionary,
        private PronunciationService $pronunciation,
        private DomainAvailabilityService $domains,
        private WordBatchMetrics $metrics,
    ) {}

    public function process(WordBatch $batch): void
    {
        $batch->load('slots.word');
        foreach ($batch->slots as $slot) {
            if (($slot->word === null || $slot->status === WordBatchSlot::REJECTED) && ($slot->retry_at === null || $slot->retry_at->isPast())) {
                $this->fill($batch, $slot);
            }
            if ($slot->fresh()->word !== null) {
                $this->progress($slot->fresh(['word']));
            }
        }
        $this->evaluate($batch->fresh(['slots.word']));
    }

    private function fill(WordBatch $batch, WordBatchSlot $slot): void
    {
        $candidate = $this->generator->generateWords(1)[0] ?? null;
        if ($candidate === null || Word::withTrashed()->where('text', $candidate['text'] ?? '')->exists()) {
            $this->retrySlot($slot, 'generator_shortfall');

            return;
        }
        try {
            $word = DB::transaction(function () use ($candidate, $slot) {
                if (Word::withTrashed()->where('text', $candidate['text'])->exists()) {
                    return null;
                }
                $word = Word::create([
                    'text' => $candidate['text'], 'phonemes' => $candidate['phonemes'], 'syllables' => $this->generator->countSyllables($candidate['text']),
                    'status' => 'available', 'dictionary_status' => Word::DICTIONARY_UNCHECKED, 'domain_status' => Word::DOMAIN_UNCHECKED,
                    'ai_definition_status' => Word::AI_DEFINITION_PENDING, 'pronunciation_status' => Word::PRONUNCIATION_PENDING,
                    'slug' => Str::slug($candidate['text']), 'generated_at' => now(),
                ]);
                $slot->update(['word_id' => $word->id, 'status' => WordBatchSlot::PROCESSING, 'retry_at' => null, 'last_error' => null]);

                return $word;
            });
            if ($word === null) {
                $this->retrySlot($slot, 'duplicate_candidate');
            }
        } catch (\Throwable $exception) {
            $this->retrySlot($slot, 'persistence_failure', $exception);
        }
    }

    private function progress(WordBatchSlot $slot): void
    {
        $word = $slot->word;
        if ($word === null) {
            return;
        }
        try {
            if (in_array($word->dictionary_status, [Word::DICTIONARY_UNCHECKED, Word::DICTIONARY_CHECK_FAILED], true) && ($word->dictionary_retry_at === null || $word->dictionary_retry_at->isPast())) {
                $result = $this->dictionary->checkWord($word->text);
                $failed = $result['status'] === Word::DICTIONARY_CHECK_FAILED;
                $attempts = $failed ? $word->dictionary_attempts + 1 : 0;
                $word->update([
                    'dictionary_status' => $result['status'], 'dictionary_checked_at' => now(), 'dictionary_data' => $result['sources'],
                    'dictionary_attempts' => $attempts, 'dictionary_retry_at' => $failed ? now()->addSeconds($this->delay($attempts)) : null,
                    'dictionary_error' => $failed ? mb_substr(json_encode($result['errors']) ?: 'dictionary_check_failed', 0, 255) : null,
                ]);
            }
            $word->refresh();
            if (! in_array($word->dictionary_status, Word::PUBLISHABLE_DICTIONARY_STATUSES, true)) {
                if ($word->dictionary_status === Word::DICTIONARY_EXISTS_AS_WORD) {
                    $this->reject($slot, 'dictionary_rejected');
                }

                return;
            }
            if (($word->ipa === null || $word->audio_url === null) && ($word->pronunciation_retry_at === null || $word->pronunciation_retry_at->isPast())) {
                $this->pronunciation->ensurePronunciation($word);
            }
            if (! $word->definitions()->where('origin', Definition::ORIGIN_AI)->exists() && ($word->ai_definition_retry_at === null || $word->ai_definition_retry_at->isPast())) {
                GenerateWordDefinition::dispatch($word->id);
            }
            $word->refresh();
            if (in_array($word->domain_status, [Word::DOMAIN_UNCHECKED, Word::DOMAIN_CHECK_FAILED], true) && ($word->domain_retry_at === null || $word->domain_retry_at->isPast())) {
                $outcome = $this->domains->checkComAvailability($word->text);
                if ($outcome->isConclusive()) {
                    $word->update(['domain_status' => $outcome->status, 'domain_checked_at' => now(), 'domain_provider' => $outcome->provider, 'domain_retry_at' => null]);
                } elseif ($outcome->failureKind === 'invalid_label') {
                    $this->reject($slot, 'invalid_domain');
                } else {
                    $word->update(['domain_status' => Word::DOMAIN_CHECK_FAILED, 'domain_retry_at' => now()->addSeconds($this->delay($word->domain_attempts + 1)), 'domain_attempts' => $word->domain_attempts + 1, 'domain_failure' => $outcome->failureDiagnostic()]);
                }
            }
        } catch (\Throwable $exception) {
            $word->refresh();
            $field = $word->dictionary_status === Word::DICTIONARY_UNCHECKED || $word->dictionary_status === Word::DICTIONARY_CHECK_FAILED ? 'dictionary' : (($word->ipa === null || $word->audio_url === null) ? 'pronunciation' : 'ai_definition');
            $attempts = (int) $word->{$field.'_attempts'} + 1;
            $word->update([$field.'_attempts' => $attempts, $field.'_retry_at' => now()->addSeconds($this->delay($attempts)), $field.'_error' => mb_substr($exception->getMessage(), 0, 255)]);
            $this->retrySlot($slot, $field.'_failure', $exception);
        }
    }

    private function evaluate(WordBatch $batch): void
    {
        DB::transaction(function () use ($batch) {
            $batch = WordBatch::query()->lockForUpdate()->find($batch->id);
            if ($batch === null || $batch->status === WordBatch::PUBLISHED) {
                return;
            }
            $slots = WordBatchSlot::query()->where('word_batch_id', $batch->id)->lockForUpdate()->with('word')->orderBy('slot_number')->get();
            $ready = $batch->target_count === WordBatch::SIZE && $slots->count() === WordBatch::SIZE && $slots->every(fn (WordBatchSlot $slot) => $slot->word !== null && $this->ready($slot->word));
            $batch->update(['status' => $ready ? WordBatch::READY : WordBatch::PROCESSING, 'ready_at' => $ready ? ($batch->ready_at ?? now()) : null, 'generated_at' => $batch->generated_at ?? now(), 'last_progressed_at' => now(), 'retry_at' => $ready ? null : now()->addSeconds(config('word-batch.retry_base_seconds'))]);
            if ($ready) {
                $this->metrics->emit('BatchReady');
            }
        });
    }

    public function ready(Word $word): bool
    {
        return ! $word->trashed() && in_array($word->dictionary_status, Word::PUBLISHABLE_DICTIONARY_STATUSES, true) && $word->definitions()->where('origin', Definition::ORIGIN_AI)->exists() && filled($word->ipa) && filled($word->audio_url) && in_array($word->domain_status, [Word::DOMAIN_AVAILABLE, Word::DOMAIN_TAKEN], true);
    }

    public function monitorStalled(): void
    {
        $oldest = WordBatch::query()->whereNotIn('status', [WordBatch::PUBLISHED, WordBatch::FAILED])
            ->orderByRaw('COALESCE(last_progressed_at, created_at)')->first();
        $age = $oldest === null ? 0 : max(0, ($oldest->last_progressed_at ?? $oldest->created_at)->diffInSeconds(now()));
        $this->metrics->emit('BatchOldestActiveAge', $age, 'Seconds');

        $staleBefore = now()->subSeconds((int) config('word-batch.stale_seconds'));
        WordBatch::query()->whereNotIn('status', [WordBatch::PUBLISHED, WordBatch::FAILED, WordBatch::STALLED])
            ->where(fn ($query) => $query->where('last_progressed_at', '<=', $staleBefore)->orWhere(fn ($query) => $query->whereNull('last_progressed_at')->where('created_at', '<=', $staleBefore)))
            ->each(function (WordBatch $batch): void {
                $batch->update(['status' => WordBatch::STALLED, 'stalled_at' => now(), 'last_error' => ['reason' => 'stalled_batch']]);
                $this->metrics->emit('BatchStalled');
            });
    }

    private function reject(WordBatchSlot $slot, string $reason): void
    {
        $slot->update(['word_id' => null, 'status' => WordBatchSlot::REJECTED, 'retry_at' => now()->addSeconds($this->delay($slot->attempts + 1)), 'attempts' => $slot->attempts + 1, 'last_error' => ['reason' => $reason]]);
    }

    private function retrySlot(WordBatchSlot $slot, string $reason, ?\Throwable $exception = null): void
    {
        $attempts = $slot->attempts + 1;
        $slot->update(['status' => WordBatchSlot::PENDING, 'attempts' => $attempts, 'retry_at' => now()->addSeconds($this->delay($attempts)), 'last_error' => ['reason' => $reason, 'message' => $exception ? mb_substr($exception->getMessage(), 0, 255) : null]]);
        $this->metrics->emit('BatchRetry');
    }

    private function delay(int $attempts): int
    {
        return min((int) config('word-batch.retry_cap_seconds'), (int) config('word-batch.retry_base_seconds') * (2 ** min(10, max(0, $attempts - 1))));
    }
}
