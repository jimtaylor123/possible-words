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
            ->toContain("Schedule::command('words:check-domains --chunk=20 --limit=20')->everyFifteenMinutes()");
    });

    test('Given the production schedule, each RDAP batch is bounded below the Lambda timeout and resumes every fifteen minutes', function () {
        $serverless = file_get_contents(base_path('serverless.yml'));
        $worstCaseSecondsPerWord = (config('domain.attempts') * config('domain.timeout'))
            + ((config('domain.retry_sleep_ms') / 1000) * (config('domain.attempts') - 1) * config('domain.attempts') / 2)
            + (1 / config('domain.throttle_per_second'));

        expect($serverless)
            ->toContain('timeout: 720')
            ->toContain("DOMAIN_TIMEOUT: '10'")
            ->toContain("DOMAIN_ATTEMPTS: '3'")
            ->toContain("DOMAIN_RETRY_SLEEP_MS: '400'")
            ->toContain("DOMAIN_THROTTLE: '4'")
            ->toContain('rate: rate(15 minutes)')
            ->toContain("cli: 'words:check-domains --chunk=20 --limit=20'");

        expect(20 * $worstCaseSecondsPerWord)->toBeLessThan(720);
    });
});
