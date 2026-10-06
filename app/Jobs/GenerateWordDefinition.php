<?php

namespace App\Jobs;

use App\Contracts\DefinitionGenerator;
use App\Events\DefinitionCreated;
use App\Exceptions\DefinitionGenerationException;
use App\Models\Definition;
use App\Models\Word;
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

    public $timeout = 30;

    public $tries = 3;

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
            ]);

            return;
        }

        try {
            $generated = $generator->generate($word->text);
            try {
                $definition = Definition::firstOrCreate(
                    ['word_id' => $word->id, 'origin' => Definition::ORIGIN_AI],
                    [
                        'user_id' => $systemAiUser->get()->id,
                        'text' => $generated->text,
                        'part_of_speech' => $generated->partOfSpeech,
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
        return [
            new RateLimited('definitions-ai'),
            (new WithoutOverlapping("definitions-ai:{$this->wordId}"))
                ->releaseAfter(5)
                ->expireAfter($this->timeout * 2),
        ];
    }

    private function recordFailure(Word $word, \Throwable $exception): void
    {
        $message = $exception instanceof DefinitionGenerationException
            ? $exception->getMessage()
            : 'Definition generation failed.';

        $word->update([
            'ai_definition_status' => Word::AI_DEFINITION_FAILED,
            'ai_definition_attempted_at' => now(),
            'ai_definition_error' => mb_substr($message, 0, 255),
        ]);

        Log::warning('AI definition generation failed.', [
            'word_id' => $word->id,
            'word' => $word->text,
            'exception' => $exception,
        ]);
    }
}
