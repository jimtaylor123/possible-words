<?php

namespace App\Jobs;

use App\Contracts\DefinitionGenerator;
use App\Events\DefinitionUpdated;
use App\Models\Definition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class GenerateDefinitionExampleSentence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const TIMEOUT = GenerateWordDefinition::TIMEOUT;

    public $timeout = self::TIMEOUT;

    public $tries = 0;

    public $maxExceptions = 3;

    public function __construct(public int $definitionId) {}

    public function handle(DefinitionGenerator $generator): void
    {
        $definition = Definition::with('word')->find($this->definitionId);
        if ($definition === null
            || $definition->origin !== Definition::ORIGIN_AI
            || $definition->example_sentence !== null
            || $definition->word === null) {
            return;
        }

        try {
            $exampleSentence = $generator->generateExampleSentence(
                $definition->word->text,
                $definition->text,
                $definition->part_of_speech ?? 'other',
            );

            // The null predicate protects a concurrent worker's completed example and
            // limits this legacy-data job to its one permitted column update.
            $updated = Definition::query()
                ->whereKey($definition->id)
                ->whereNull('example_sentence')
                ->update(['example_sentence' => $exampleSentence]);

            if ($updated === 1) {
                try {
                    broadcast(new DefinitionUpdated($definition->fresh()))->toOthers();
                } catch (\Throwable $exception) {
                    Log::warning('AI definition example was saved but could not be broadcast.', [
                        'definition_id' => $definition->id,
                        'word_id' => $definition->word_id,
                        'exception' => $exception,
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('AI definition example generation failed.', [
                'definition_id' => $definition->id,
                'word_id' => $definition->word_id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function middleware(): array
    {
        return [
            new RateLimited('definitions-ai'),
            (new WithoutOverlapping("definitions-ai-example:{$this->definitionId}"))
                ->releaseAfter(5)
                ->expireAfter($this->timeout * 2),
        ];
    }
}
