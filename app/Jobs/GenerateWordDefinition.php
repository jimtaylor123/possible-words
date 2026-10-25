<?php

namespace App\Jobs;

use App\Contracts\DefinitionGenerator;
use App\Events\DefinitionCreated;
use App\Exceptions\DefinitionGenerationException;
use App\Models\Definition;
use App\Models\Word;
use App\Models\WordBatchSlot;
use App\Services\Definitions\SystemAiUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class GenerateWordDefinition implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const TIMEOUT = 50;

    private const DEFER_DELAY = 5;

    public $timeout = self::TIMEOUT;

    // Rate-limit releases increment queue attempts, so only thrown provider failures are bounded.
    public $tries = 0;

    public $maxExceptions = 3;

    public function __construct(public int $wordId) {}

    public function handle(DefinitionGenerator $generator, SystemAiUser $systemAiUser): void
    {
        $word = Word::withTrashed()->find($this->wordId);
        if ($word === null || $word->trashed()) {
            return;
        }

        if ($word->definitions()->where('origin', Definition::ORIGIN_AI)->exists()) {
            $word->update([
                'ai_definition_status' => Word::AI_DEFINITION_GENERATED,
                'ai_definition_error' => null,
                'ai_definition_attempts' => 0,
                'ai_definition_retry_at' => null,
            ]);

            return;
        }

        $excludedDefinitions = $this->lowerBatchDefinitions($word);
        if ($excludedDefinitions === null) {
            $this->release(self::DEFER_DELAY);

            return;
        }

        try {
            $generated = $generator->generate($word->text, $excludedDefinitions);
            try {
                $definition = Definition::firstOrCreate(
                    ['word_id' => $word->id, 'origin' => Definition::ORIGIN_AI],
                    [
                        'user_id' => $systemAiUser->get()->id,
                        'text' => $generated->text,
                        'part_of_speech' => $generated->partOfSpeech,
                        'example_sentence' => $generated->exampleSentence,
                        'votes_count' => 0,
                    ],
                );
            } catch (QueryException $exception) {
                // Another worker may have won the unique (word_id, origin) race.
                $definition = Definition::where('word_id', $word->id)
                    ->where('origin', Definition::ORIGIN_AI)
                    ->first();

                if ($definition === null) {
                    throw $exception;
                }
            }

            $word->update([
                'ai_definition_status' => Word::AI_DEFINITION_GENERATED,
                'ai_definition_attempted_at' => now(),
                'ai_definition_error' => null,
            ]);

            if ($definition->wasRecentlyCreated) {
                try {
                    broadcast(new DefinitionCreated($definition))->toOthers();
                } catch (\Throwable $exception) {
                    Log::warning('AI definition was saved but could not be broadcast.', [
                        'word_id' => $word->id,
                        'exception' => $exception,
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            $this->recordFailure($word, $exception);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $word = Word::withTrashed()->find($this->wordId);
        if ($word !== null && ! $word->trashed()) {
            $this->recordFailure($word, $exception);
        }
    }

    public function middleware(): array
    {
        // The limiter name must match the RateLimiter::for('definitions-ai', …)
        // definition in AppServiceProvider — an unknown name silently disables
        // rate limiting (RateLimited passes through when no limiter is registered).
        return [
            new RateLimited('definitions-ai'),
            (new WithoutOverlapping("definitions-ai:{$this->wordId}"))
                ->releaseAfter(self::DEFER_DELAY)
                ->expireAfter($this->timeout * 2),
        ];
    }

    /**
     * @return list<string>|null Null means an earlier slot is not yet complete.
     */
    private function lowerBatchDefinitions(Word $word): ?array
    {
        $slot = $word->batchSlot;
        if ($slot === null) {
            return [];
        }

        $lowerSlots = WordBatchSlot::query()
            ->where('word_batch_id', $slot->word_batch_id)
            ->where('slot_number', '<', $slot->slot_number)
            ->with(['word.definitions' => fn ($query) => $query->where('origin', Definition::ORIGIN_AI)])
            ->orderBy('slot_number')
            ->get();

        if ($lowerSlots->count() !== $slot->slot_number - 1) {
            return null;
        }

        $definitions = [];
        foreach ($lowerSlots as $lowerSlot) {
            $definition = $lowerSlot->word?->definitions->first();
            if ($definition === null) {
                return null;
            }

            $definitions[] = $definition->text;
        }

        return $definitions;
    }

    private function recordFailure(Word $word, \Throwable $exception): void
    {
        // A second queued copy may finish successfully while this job is being
        // failed by the worker. Never replace a completed state with a failure.
        if ($word->definitions()->where('origin', Definition::ORIGIN_AI)->exists()) {
            $word->update([
                'ai_definition_status' => Word::AI_DEFINITION_GENERATED,
                'ai_definition_error' => null,
            ]);

            return;
        }

        $message = $exception instanceof DefinitionGenerationException
            ? $exception->getMessage()
            : 'Definition generation failed.';

        $word->update([
            'ai_definition_status' => Word::AI_DEFINITION_FAILED,
            'ai_definition_attempted_at' => now(),
            'ai_definition_error' => mb_substr($message, 0, 255),
            'ai_definition_attempts' => $word->ai_definition_attempts + 1,
            'ai_definition_retry_at' => now()->addSeconds(min(3600, 60 * (2 ** min(10, $word->ai_definition_attempts)))),
        ]);

        Log::warning('AI definition generation failed.', [
            'word_id' => $word->id,
            'word' => $word->text,
            'exception' => $exception,
        ]);
    }
}
