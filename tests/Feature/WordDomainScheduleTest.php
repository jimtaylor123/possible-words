<?php

/**
 * The .com check must actually be scheduled, in both places the app reads a
 * schedule from: the Laravel scheduler (a local/CI mirror) and serverless.yml
 * (the EventBridge event that fires in production).
 */
describe('domain check scheduling', function () {
    test('Given the Laravel scheduler, words:check-domains runs as a bounded recurring command', function () {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('words:check-domains')
            ->assertExitCode(0);

        expect(file_get_contents(base_path('routes/console.php')))
            ->toContain("Schedule::command('words:check-domains --chunk=8 --limit=8')->cron('*/11 * * * *')")
            ->not->toContain("Schedule::command('words:check-domains --chunk=8 --limit=8')->cron('*/11 * * * *')->withoutOverlapping()");
    });

    test('Given the production schedule, single-attempt pacing and cooldown deadlines fit its timeout and cadence while providing daily capacity', function () {
        $serverless = file_get_contents(base_path('serverless.yml'));

        // These are the values pinned in serverless.yml, rather than test-env
        // defaults. Each word gets one ten-second RDAP attempt, with pacing
        // before it and a Turso UPDATE afterwards. A Retry-After is not added
        // to this total: the command stops before it can overrun its deadline.
        $batchSize = 8;
        $cadenceSeconds = 11 * 60;
        $lambdaTimeoutSeconds = 720;
        $rdapTimeoutSeconds = 10;
        $requestPacingSeconds = 1 + 0.1;
        $tursoRequestSeconds = 15;
        $perWordSeconds = $rdapTimeoutSeconds + $requestPacingSeconds + $tursoRequestSeconds;
        // An expired lease first tries insert-or-ignore, then conditionally
        // replaces the row, before count, the full-page select, its terminating
        // empty-or-ninth-row chunkById select, and token-guarded release.
        $databaseControlSeconds = 6 * 15;
        $startupAndLoggingSeconds = 15;
        $deadlineReserveSeconds = 30;
        $normalBatchSeconds = ($batchSize * $perWordSeconds)
            + $databaseControlSeconds
            + $startupAndLoggingSeconds
            + $deadlineReserveSeconds;
        $runDeadlineSeconds = 600;
        $dailyCapacity = $batchSize * (24 * 60 / 11);

        expect($serverless)
            ->toContain('timeout: 720')
            ->toContain("DOMAIN_TIMEOUT: '10'")
            ->toContain("DOMAIN_REQUEST_INTERVAL_MS: '1000'")
            ->toContain("DOMAIN_REQUEST_JITTER_MS: '100'")
            ->toContain("DOMAIN_MAX_RETRY_AFTER_SECONDS: '600'")
            ->toContain("DOMAIN_RUN_MAX_SECONDS: '600'")
            ->toContain("DOMAIN_RUN_DEADLINE_RESERVE_SECONDS: '30'")
            ->toContain("DOMAIN_RUN_LOCK_TTL_SECONDS: '720'")
            ->toContain("TURSO_REQUEST_TIMEOUT: \${env:TURSO_REQUEST_TIMEOUT, '15'}")
            ->toContain('rate: rate(11 minutes)')
            ->toContain("cli: 'words:check-domains --chunk=8 --limit=8'");

        expect($normalBatchSeconds)->toBe(343.8)
            ->and($normalBatchSeconds)->toBeLessThan($runDeadlineSeconds)
            ->and($runDeadlineSeconds)->toBeLessThan($cadenceSeconds)
            ->and($runDeadlineSeconds)->toBeLessThan($lambdaTimeoutSeconds)
            // 1,000 current words plus 20 new words per day must be checked
            // within a day; 8 checks every eleven minutes gives 1,047/day.
            ->and($dailyCapacity)->toBeGreaterThanOrEqual(1000 + 20);
    });
});
