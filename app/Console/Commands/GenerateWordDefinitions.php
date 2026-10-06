<?php

namespace App\Console\Commands;

use App\Jobs\GenerateWordDefinition;
use App\Models\Definition;
use App\Models\Word;
use Illuminate\Console\Command;

class GenerateWordDefinitions extends Command
{
    private const MAX_LIMIT = 20;

    protected $signature = 'words:generate-definitions
                            {--limit= : Number of eligible words to generate (1-20)}
                            {--retry-failed : Include previously failed generation attempts}';

    protected $description = 'Queue missing AI definitions for generated words';

    public function handle(): int
    {
        $limit = $this->option('limit');

        if (! is_string($limit) || filter_var($limit, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_LIMIT],
        ]) === false) {
            $this->error(sprintf('--limit must be a positive integer no greater than %d.', self::MAX_LIMIT));

            return self::FAILURE;
        }

        $status = $this->option('retry-failed')
            ? [Word::AI_DEFINITION_PENDING, Word::AI_DEFINITION_FAILED]
            : [Word::AI_DEFINITION_PENDING];
        $queued = 0;

        Word::query()
            ->whereIn('ai_definition_status', $status)
            ->whereDoesntHave('definitions', fn ($query) => $query->where('origin', Definition::ORIGIN_AI))
            ->orderBy('id')
            ->limit((int) $limit)
            ->each(function (Word $word) use (&$queued): void {
                GenerateWordDefinition::dispatch($word->id);
                $queued++;
            });

        $this->info("Queued {$queued} missing AI definition(s).");

        return self::SUCCESS;
    }
}
