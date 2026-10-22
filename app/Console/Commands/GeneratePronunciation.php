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
            $query->where(fn ($query) => $query->whereNull('audio_url')->orWhereNull('ipa'));
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
                    $word = $service->ensurePronunciation($word);
                    if ($word->pronunciation_status === Word::PRONUNCIATION_GENERATED) {
                        $processed++;
                    }
                } catch (\Exception $e) {
                    $attempts = (int) ($word->pronunciation_attempts ?? 0) + 1;
                    $word->update(['pronunciation_status' => Word::PRONUNCIATION_FAILED, 'pronunciation_attempted_at' => now(), 'pronunciation_attempts' => $attempts, 'pronunciation_retry_at' => now()->addSeconds(min(3600, 60 * (2 ** min(10, $attempts - 1)))), 'pronunciation_error' => mb_substr($e->getMessage(), 0, 255)]);
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
