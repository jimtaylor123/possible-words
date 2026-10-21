<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\DomainAvailabilityService;
use App\Services\DomainCheckRunLock;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Check the .com availability of every word against Verisign's free RDAP
 * endpoint.
 *
 * New words are always checked before deferred failures and stale conclusive
 * verdicts. A failed attempt records its next retry in the database, so a
 * restart cannot accidentally turn provider trouble into a request storm.
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
        $forced = (bool) $this->option('force');
        $now = now();

        if ($forced) {
            $this->warn('Re-checking every word, including recently checked ones.');
        }

        $queries = $forced
            ? [Word::query()]
            : [
                Word::query()->where('domain_status', Word::DOMAIN_UNCHECKED),
                Word::query()->where('domain_status', Word::DOMAIN_CHECK_FAILED)
                    ->where(function (Builder $query) use ($now) {
                        $query->whereNull('domain_retry_at')->orWhere('domain_retry_at', '<=', $now);
                    })
                    ->where(function (Builder $query) {
                        $query->whereNull('domain_failure')
                            ->orWhere('domain_failure->kind', '!=', 'invalid_label');
                    }),
                Word::query()->whereIn('domain_status', [Word::DOMAIN_AVAILABLE, Word::DOMAIN_TAKEN])
                    ->where(function (Builder $query) use ($now) {
                        $query->whereNull('domain_checked_at')->orWhere('domain_checked_at', '<=', $now->copy()->subDay());
                    }),
            ];

        $total = array_sum(array_map(fn (Builder $query) => $query->count(), $queries));

        if ($total === 0) {
            $this->info('No words to check.');

            return self::SUCCESS;
        }

        $chunkSize = (int) $this->option('chunk');
        $limitOption = $this->option('limit');
        $limit = $limitOption !== null ? max(0, (int) $limitOption) : null;
        $bar = $this->output->createProgressBar($limit === null ? $total : min($total, $limit));
        $bar->start();

        $throttle = $this->option('throttle');
        $intervalMs = $throttle !== null
            ? (int) floor(1000 / max(1, (int) $throttle))
            : max(0, (int) config('domain.request_interval_ms', 1000));
        $requestJitterMs = max(0, (int) config('domain.request_jitter_ms', 100));
        $attempted = 0;
        $processed = 0;
        $failures = 0;
        $results = array_fill_keys([
            Word::DOMAIN_AVAILABLE,
            Word::DOMAIN_TAKEN,
            Word::DOMAIN_CHECK_FAILED,
        ], 0);

        $lastRequestAt = null;
        $providerCooldowns = [];
        $runDeadline = microtime(true) + max(0, (int) config('domain.run_max_seconds', 600));
        $deadlineReserveSeconds = max(0, (int) config('domain.run_deadline_reserve_seconds', 30));

        foreach ($queries as $query) {
            $continue = $query->chunkById($chunkSize, function ($words) use ($service, $bar, $limit, $intervalMs, $requestJitterMs, $runDeadline, $deadlineReserveSeconds, &$attempted, &$processed, &$failures, &$results, &$lastRequestAt, &$providerCooldowns) {
                foreach ($words as $word) {
                    if ($limit !== null && $attempted >= $limit) {
                        return false;
                    }

                    if (! $this->paceRequest(DomainAvailabilityService::PROVIDER, $lastRequestAt, $providerCooldowns, $intervalMs, $requestJitterMs, $runDeadline, $deadlineReserveSeconds)) {
                        $this->warn('Stopping domain checks because the provider cooldown would exceed this invocation deadline.');

                        return false;
                    }

                    $lastRequestAt = microtime(true);
                    $attempted++;

                    try {
                        $outcome = $service->checkComAvailability($word->text);
                        $checkedAt = now();

                        if ($outcome->isConclusive()) {
                            $word->update([
                                'domain_status' => $outcome->status,
                                'domain_checked_at' => $checkedAt,
                                'domain_provider' => $outcome->provider,
                                'domain_retry_at' => null,
                                'domain_attempts' => 0,
                                'domain_failure' => null,
                            ]);
                        } else {
                            $attempts = $word->domain_attempts + 1;
                            $retryAfter = min(max(0, (int) ($outcome->retryAfterSeconds ?? 0)), max(0, (int) config('domain.max_retry_after_seconds', 3600)));
                            $delay = max($this->retryDelaySeconds($attempts), $retryAfter);
                            $isInvalidLabel = $outcome->failureKind === 'invalid_label';

                            $word->update([
                                'domain_status' => Word::DOMAIN_CHECK_FAILED,
                                'domain_checked_at' => $checkedAt,
                                'domain_provider' => $outcome->provider,
                                'domain_retry_at' => $isInvalidLabel ? null : $checkedAt->copy()->addSeconds($delay),
                                'domain_attempts' => $attempts,
                                'domain_failure' => $outcome->failureDiagnostic(),
                            ]);

                            if ($retryAfter > 0) {
                                $providerCooldowns[$outcome->provider] = microtime(true) + $retryAfter;
                            }
                        }

                        $results[$outcome->status] = ($results[$outcome->status] ?? 0) + 1;
                        $processed++;
                    } catch (Throwable $e) {
                        $failures++;
                        $this->newLine();
                        $this->error("Failed to persist domain check for '{$word->text}'.");
                    }

                    $bar->advance();
                }

                return true;
            });

            if ($continue === false || ($limit !== null && $attempted >= $limit)) {
                break;
            }
        }

        $bar->finish();
        $this->newLine();

        $this->info(sprintf(
            'Checked %d of %d attempted words: %d available, %d taken, %d check_failed (%d failures).',
            $processed,
            $attempted,
            $results[Word::DOMAIN_AVAILABLE],
            $results[Word::DOMAIN_TAKEN],
            $results[Word::DOMAIN_CHECK_FAILED],
            $failures,
        ));

        if ($results[Word::DOMAIN_CHECK_FAILED] > 0) {
            $this->warn(
                $results[Word::DOMAIN_CHECK_FAILED]
                .' word(s) could not be checked and will be retried after their recorded delay.'
            );
        }

        // Always SUCCESS: a flaky third-party API must not fail the scheduled event.
        return self::SUCCESS;
    }

    /** @param array<string, float> $providerCooldowns */
    private function paceRequest(string $provider, ?float $lastRequestAt, array $providerCooldowns, int $intervalMs, int $jitterMs, float $runDeadline, int $deadlineReserveSeconds): bool
    {
        $intervalSeconds = ($intervalMs + ($jitterMs > 0 ? random_int(0, $jitterMs) : 0)) / 1000;
        $nextPacedAt = ($lastRequestAt ?? microtime(true)) + $intervalSeconds;
        $notBefore = max($nextPacedAt, $providerCooldowns[$provider] ?? 0);

        if ($notBefore + $deadlineReserveSeconds > $runDeadline) {
            return false;
        }

        $sleepMicros = (int) max(0, ($notBefore - microtime(true)) * 1000000);

        if ($sleepMicros > 0) {
            usleep($sleepMicros);
        }

        return true;
    }

    private function retryDelaySeconds(int $attempts): int
    {
        $base = max(1, (int) config('domain.retry_base_seconds', 60));
        $cap = max($base, (int) config('domain.retry_cap_seconds', 3600));
        $exponential = min($cap, $base * (2 ** min(30, max(0, $attempts - 1))));
        $jitter = max(0, (int) config('domain.retry_jitter_seconds', 15));

        return min($cap, $exponential + ($jitter > 0 ? random_int(0, $jitter) : 0));
    }
}
