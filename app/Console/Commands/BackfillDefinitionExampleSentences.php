<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDefinitionExampleSentence;
use App\Models\Definition;
use Illuminate\Console\Command;

class BackfillDefinitionExampleSentences extends Command
{
    private const MAX_LIMIT = 10;

    protected $signature = 'definitions:backfill-example-sentences
                            {--limit= : Number of AI definitions to backfill (1-10)}';

    protected $description = 'Queue missing example sentences for existing AI definitions';

    public function handle(): int
    {
        $limit = $this->option('limit');

        if (! is_string($limit) || filter_var($limit, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_LIMIT],
        ]) === false) {
            $this->error(sprintf('--limit must be a positive integer no greater than %d.', self::MAX_LIMIT));

            return self::FAILURE;
        }

        $queued = 0;

        Definition::query()
            ->where('origin', Definition::ORIGIN_AI)
            ->whereNull('example_sentence')
            ->whereHas('word')
            ->orderBy('id')
            ->limit((int) $limit)
            ->each(function (Definition $definition) use (&$queued): void {
                GenerateDefinitionExampleSentence::dispatch($definition->id);
                $queued++;
            });

        $this->info("Queued {$queued} missing AI example sentence(s).");

        return self::SUCCESS;
    }
}
