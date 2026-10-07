<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\DomainAvailabilityService;
use App\Services\DomainCheckRunLock;
use Illuminate\Console\Command;
use Throwable;

/**
 * Check the .com availability of every word against Verisign's free RDAP
 * endpoint.
 *
 * The default selection is "never checked, or checked more than a day ago",
 * so a daily run re-verifies the whole catalogue (including the words added
 * by words:generate) and an interrupted run resumes naturally: completed
 * rows carry a fresh domain_checked_at and drop out of the due set.
 *
 * Always returns SUCCESS: a flaky third-party API must not fail the
 * scheduled event.
 */
class CheckWordsDomains extends Command
{
    protected $signature = 'words:check-domains
                            {--force : Re-check every word regardless of when it was last checked}
                            {--chunk=50 : Number of words to process per chunk}
                            {--limit= : Stop after N words in this run}
                            {--throttle= : Requests per second (overrides config)}';

    protected $description = 'Check .com availability for words via the free Verisign RDAP endpoint';

    public function handle(DomainAvailabilityService $service, DomainCheckRunLock $runLock): int
    {
        if (config('domain.enabled') === false) {
            $this->info('Domain checks are disabled — skipping (DOMAIN_CHECK_ENABLED=false).');

            return self::SUCCESS;
        }

        $lockToken = $runLock->acquire();

        if ($lockToken === null) {
            $this->info('Another domain-check invocation holds the database lease — skipping.');

            return self::SUCCESS;
        }

        try {
            return $this->checkWords($service);
        } finally {
            $runLock->release($lockToken);
        }
    }

    private function checkWords(DomainAvailabilityService $service): int
    {
        $query = Word::query();

        if ($this->option('force')) {
            $this->warn('Re-checking every word, including recently checked ones.');
        } else {
            $query->where(function ($q) {
                $q->whereNull('domain_checked_at')
                    ->orWhere('domain_checked_at', '<=', now()->subDay());
            });
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No words to check.');

            return self::SUCCESS;
        }

        $chunkSize = (int) $this->option('chunk');
        $limitOption = $this->option('limit');
        $limit = $limitOption !== null ? max(0, (int) $limitOption) : null;
        $bar = $this->output->createProgressBar($limit === null ? $total : min($total, $limit));
        $bar->start();

        $throttle = (int) ($this->option('throttle') ?? config('domain.throttle_per_second', 4));
        $processed = 0;
        $failures = 0;
        $results = array_fill_keys([
            Word::DOMAIN_AVAILABLE,
            Word::DOMAIN_TAKEN,
            Word::DOMAIN_CHECK_FAILED,
        ], 0);

        // Statuses change inside this loop (a checked row leaves the due
        // set). Cursor by id so changing a row does not shift later rows
        // past an offset-based chunk.
        $query->chunkById($chunkSize, function ($words) use ($service, $bar, $throttle, $limit, &$processed, &$failures, &$results) {
            foreach ($words as $word) {
                if ($limit !== null && $processed >= $limit) {
                    return false;
                }

                try {
                    $status = $service->checkComAvailability($word->text);

                    $word->update([
                        'domain_status' => $status,
                        'domain_checked_at' => now(),
                    ]);

                    $results[$status] = ($results[$status] ?? 0) + 1;
                    $processed++;
                } catch (Throwable $e) {
                    $failures++;
                    $this->newLine();
                    $this->error("Failed to check '{$word->text}': {$e->getMessage()}");
                }

                if ($throttle > 0) {
                    usleep((int) (1000000 / $throttle));
                }

                $bar->advance();
            }

            return true;
        });

        $bar->finish();
        $this->newLine();

        $this->info(sprintf(
            'Checked %d words: %d available, %d taken, %d check_failed (%d failures).',
            $processed,
            $results[Word::DOMAIN_AVAILABLE],
            $results[Word::DOMAIN_TAKEN],
            $results[Word::DOMAIN_CHECK_FAILED],
            $failures,
        ));

        if ($results[Word::DOMAIN_CHECK_FAILED] > 0) {
            $this->warn(
                $results[Word::DOMAIN_CHECK_FAILED]
                .' word(s) could not be checked and show no link — re-run this command to retry them.'
            );
        }

        // Always SUCCESS: a flaky third-party API must not fail the scheduled event.
        return self::SUCCESS;
    }
}
