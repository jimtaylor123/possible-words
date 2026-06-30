<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\DictionaryService;
use Illuminate\Console\Command;

class CheckWordsDictionary extends Command
{
    protected $signature = 'words:check-dictionary
                            {--force : Re-check words that have already been checked}
                            {--chunk=50 : Number of words to process per chunk}';

    protected $description = 'Check words against dictionary APIs to verify they are not real words';

    public function handle(DictionaryService $service): int
    {
        $query = Word::query();

        if ($this->option('force')) {
            $this->warn('Re-checking all words (including already-checked ones).');
        } else {
            $query->where('dictionary_status', 'unchecked');
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No words to check.');

            return self::SUCCESS;
        }

        $chunkSize = (int) $this->option('chunk');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $throttle = config('dictionary.throttle_per_second', 2);
        $processed = 0;
        $results = ['not_found' => 0, 'exists_as_name' => 0, 'exists_as_word' => 0];

        $query->chunk($chunkSize, function ($words) use ($service, $bar, $throttle, &$processed, &$results) {
            foreach ($words as $word) {
                try {
                    $result = $service->checkWord($word->text);

                    $word->update([
                        'dictionary_status' => $result['status'],
                        'dictionary_checked_at' => now(),
                        'dictionary_data' => $result['sources'],
                    ]);

                    $results[$result['status']]++;
                    $processed++;

                    if ($throttle > 0) {
                        usleep((int) (1000000 / $throttle));
                    }
                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("Failed to check '{$word->text}': {$e->getMessage()}");
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Checked {$processed} words.");
        $this->info("Results: {$results['not_found']} not found, {$results['exists_as_name']} names, {$results['exists_as_word']} real words");

        if ($results['exists_as_word'] > 0) {
            $this->warn("{$results['exists_as_word']} real words found — these are now hidden from the site.");
        }

        return self::SUCCESS;
    }
}
