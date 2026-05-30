<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\PronunciationService;
use Illuminate\Console\Command;

class GeneratePronunciation extends Command
{
    protected $signature = 'words:generate-pronunciation
                            {--force : Regenerate existing pronunciations}
                            {--chunk=100 : Number of words to process per chunk}';

    protected $description = 'Generate IPA pronunciation and audio for words';

    public function handle(PronunciationService $service): int
    {
        $query = Word::query();

        if (! $this->option('force')) {
            $query->whereNull('ipa');
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No words to process.');

            return self::SUCCESS;
        }

        $chunkSize = (int) $this->option('chunk');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;

        $query->chunk($chunkSize, function ($words) use ($service, $bar, &$processed) {
            foreach ($words as $word) {
                try {
                    $service->ensurePronunciation($word);
                    $processed++;
                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("Failed to generate pronunciation for '{$word->text}': {$e->getMessage()}");
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Generated pronunciation for {$processed} words.");

        return self::SUCCESS;
    }
}
