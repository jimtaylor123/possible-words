<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\WordGenerator;
use Illuminate\Console\Command;

class GenerateWords extends Command
{
    protected $signature = 'words:generate
                            {--count=20 : Number of words to generate}
                            {--fast : Skip dictionary checking for bulk generation}';

    protected $description = 'Generate fresh possible words using the phonotactic word generator';

    public function handle(WordGenerator $generator): int
    {
        $count = (int) $this->option('count');
        $fast = $this->option('fast');

        $this->line("Generating {$count} word(s)".($fast ? ' (fast mode, no dictionary check)' : '').'...');

        $words = $generator->generateWords($count, checkDictionary: ! $fast);
        $generatedCount = count($words);
        $rejectedAsRealWords = $generator->rejectedAsRealWords;

        $existingTexts = Word::whereIn('text', array_column($words, 'text'))->pluck('text')->toArray();
        $newWords = array_values(array_filter($words, fn ($w) => ! in_array($w['text'], $existingTexts)));
        $duplicateCount = $generatedCount - count($newWords);

        $created = $generator->createWords($newWords, dispatchCheckJob: ! $fast);

        Word::whereIn('id', array_column($created, 'id'))->update(['generated_at' => now()]);

        if ($generatedCount < $count) {
            $this->warn("  Only {$generatedCount} words generated (max attempts reached).");
        }

        $this->newLine();
        $this->info("✓ {$count} targeted, {$generatedCount} generated, ".count($created).' created');
        if (! $fast) {
            $this->warn("  Rejected (real words): {$rejectedAsRealWords}");
        }
        $this->warn("  Duplicates skipped: {$duplicateCount}");
        $this->newLine();
        $this->info('Done! '.count($created).' fresh words planted.');

        return self::SUCCESS;
    }
}
