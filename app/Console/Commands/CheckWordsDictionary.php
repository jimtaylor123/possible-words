<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\DictionaryService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Check generated words against the dictionary APIs.
 *
 * Words are only recorded once a lookup actually returns a verdict: an
 * unreachable API leaves the word at check_failed so the next run retries it,
 * rather than promoting it to a confirmed non-word.
 */
class CheckWordsDictionary extends Command
{
    protected $signature = 'words:check-dictionary
                            {--force : Re-check words that have already been checked}
                            {--retry-failed : Also re-check not_found words whose lookup recorded no dictionary_data}
                            {--chunk=50 : Number of words to process per chunk}';

    protected $description = 'Check words against dictionary APIs to verify they are not real words';

    private const PREVIEW_LIMIT = 25;

    public function handle(DictionaryService $service): int
    {
        $query = Word::query();

        if ($this->option('force')) {
            $this->warn('Re-checking all words (including already-checked ones).');
        } elseif ($this->option('retry-failed')) {
            $this->warn('Re-checking unchecked, failed and unverified not_found words.');

            // 'not_found' with an empty dictionary_data is a verdict the old
            // fail-open classifier invented: no source ever answered. A real
            // not_found carries {"free_dictionary":{"found":false}} and is skipped.
            $query->whereIn('dictionary_status', [
                Word::DICTIONARY_UNCHECKED,
                Word::DICTIONARY_CHECK_FAILED,
                Word::DICTIONARY_NOT_FOUND,
            ])->where(function ($q) {
                $q->whereNull('dictionary_data')
                    ->orWhere('dictionary_data', '[]');
            });
        } else {
            $query->whereIn('dictionary_status', [
                Word::DICTIONARY_UNCHECKED,
                Word::DICTIONARY_CHECK_FAILED,
            ]);
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
        $failures = 0;
        $results = array_fill_keys([
            Word::DICTIONARY_NOT_FOUND,
            Word::DICTIONARY_EXISTS_AS_NAME,
            Word::DICTIONARY_EXISTS_AS_WORD,
            Word::DICTIONARY_CHECK_FAILED,
        ], 0);
        $leftPublicList = [];
        $enteredPublicList = [];

        // Check statuses change inside this loop. Cursor by id so changing a
        // row does not shift later rows past an offset-based chunk.
        $query->chunkById($chunkSize, function ($words) use ($service, $bar, $throttle, &$processed, &$failures, &$results, &$leftPublicList, &$enteredPublicList) {
            foreach ($words as $word) {
                $wasPublishable = $word->isPublishable();

                try {
                    $result = $service->checkWord($word->text);

                    $word->update([
                        'dictionary_status' => $result['status'],
                        'dictionary_checked_at' => now(),
                        'dictionary_data' => $result['sources'],
                    ]);

                    $results[$result['status']] = ($results[$result['status']] ?? 0) + 1;
                    $processed++;
                } catch (Throwable $e) {
                    $failures++;
                    $this->newLine();
                    $this->error("Failed to check '{$word->text}': {$e->getMessage()}");
                }

                $isPublishable = $word->refresh()->isPublishable();

                if ($wasPublishable && ! $isPublishable) {
                    $leftPublicList[] = $word->text;
                } elseif (! $wasPublishable && $isPublishable) {
                    $enteredPublicList[] = $word->text;
                }

                if ($throttle > 0) {
                    usleep((int) (1000000 / $throttle));
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info("Checked {$processed} words ({$failures} failures).");
        $this->line(sprintf(
            '  %d not found, %d names, %d real words, %d checks that did not complete',
            $results[Word::DICTIONARY_NOT_FOUND],
            $results[Word::DICTIONARY_EXISTS_AS_NAME],
            $results[Word::DICTIONARY_EXISTS_AS_WORD],
            $results[Word::DICTIONARY_CHECK_FAILED],
        ));

        $this->reportPublicListChange('Left the public list', $leftPublicList);
        $this->reportPublicListChange('Joined the public list', $enteredPublicList);

        if ($results[Word::DICTIONARY_CHECK_FAILED] > 0) {
            $this->warn(
                $results[Word::DICTIONARY_CHECK_FAILED]
                .' word(s) could not be checked and are still hidden — re-run this command to retry them.'
            );
        }

        if ($failures > 0) {
            $this->warn("{$failures} word(s) could not be checked at all — re-run this command to retry them.");
        }

        // Always SUCCESS: a flaky third-party API must not fail the scheduled event.
        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $texts
     */
    private function reportPublicListChange(string $label, array $texts): void
    {
        if ($texts === []) {
            return;
        }

        $this->line("{$label}: ".count($texts));

        foreach (array_slice($texts, 0, self::PREVIEW_LIMIT) as $text) {
            $this->line("  - {$text}");
        }

        if (count($texts) > self::PREVIEW_LIMIT) {
            $this->line('  ... and '.(count($texts) - self::PREVIEW_LIMIT).' more');
        }
    }
}
