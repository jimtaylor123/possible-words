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

    test('Given the production schedule, its complete worst case fits the timeout and cadence while providing daily capacity', function () {
        $serverless = file_get_contents(base_path('serverless.yml'));

        // These are the values pinned in serverless.yml, rather than test-env
        // defaults. A full lookup includes all three timed-out RDAP attempts,
        // linear retry sleeps, post-word throttle, and its Turso UPDATE.
        $batchSize = 8;
        $cadenceSeconds = 11 * 60;
        $lambdaTimeoutSeconds = 720;
        $attempts = 3;
        $rdapTimeoutSeconds = 10;
        $retrySleepSeconds = 0.4 + 0.8;
        $throttleSeconds = 1 / 4;
        $tursoRequestSeconds = 15;
        $perWordSeconds = ($attempts * $rdapTimeoutSeconds)
            + $retrySleepSeconds
            + $throttleSeconds
            + $tursoRequestSeconds;
        // An expired lease first tries insert-or-ignore, then conditionally
        // replaces the row, before count, the full-page select, its terminating
        // empty-or-ninth-row chunkById select, and token-guarded release.
        $databaseControlSeconds = 6 * 15;
        $startupAndLoggingSeconds = 15;
        $safetyMarginSeconds = 120;
        $worstCaseSeconds = ($batchSize * $perWordSeconds)
            + $databaseControlSeconds
            + $startupAndLoggingSeconds
            + $safetyMarginSeconds;
        $dailyCapacity = $batchSize * (24 * 60 / 11);

        expect($serverless)
            ->toContain('timeout: 720')
            ->toContain("DOMAIN_TIMEOUT: '10'")
            ->toContain("DOMAIN_ATTEMPTS: '3'")
            ->toContain("DOMAIN_RETRY_SLEEP_MS: '400'")
            ->toContain("DOMAIN_THROTTLE: '4'")
            ->toContain("DOMAIN_RUN_LOCK_TTL_SECONDS: '720'")
            ->toContain("TURSO_REQUEST_TIMEOUT: \${env:TURSO_REQUEST_TIMEOUT, '15'}")
            ->toContain('rate: rate(11 minutes)')
            ->toContain("cli: 'words:check-domains --chunk=8 --limit=8'");

        expect($worstCaseSeconds)->toBe(596.6)
            ->and($worstCaseSeconds)->toBeLessThan($cadenceSeconds)
            ->and($worstCaseSeconds)->toBeLessThan($lambdaTimeoutSeconds)
            // 1,000 current words plus 20 new words per day must be checked
            // within a day; 8 checks every eleven minutes gives 1,047/day.
            ->and($dailyCapacity)->toBeGreaterThanOrEqual(1000 + 20);
    });
});
