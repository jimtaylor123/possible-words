<?php

namespace App\Console\Commands;

use App\Jobs\GenerateWordDefinition;
use App\Models\Definition;
use App\Models\Word;
use Illuminate\Console\Command;

class GenerateWordDefinitions extends Command
{
    protected $signature = 'words:generate-definitions {--retry-failed : Include previously failed generation attempts}';

    protected $description = 'Queue missing AI definitions for generated words';

    public function handle(): int
    {
        $status = $this->option('retry-failed')
            ? [Word::AI_DEFINITION_PENDING, Word::AI_DEFINITION_FAILED]
            : [Word::AI_DEFINITION_PENDING];
        $queued = 0;

        Word::query()
            ->whereIn('ai_definition_status', $status)
            ->whereDoesntHave('definitions', fn ($query) => $query->where('origin', Definition::ORIGIN_AI))
            ->orderBy('id')
            ->chunkById(100, function ($words) use (&$queued) {
                foreach ($words as $word) {
                    GenerateWordDefinition::dispatch($word->id);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} missing AI definition(s).");

        return self::SUCCESS;
    }
}
