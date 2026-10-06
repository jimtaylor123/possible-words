<?php

/**
 * The .com check must actually be scheduled, in both places the app reads a
 * schedule from: the Laravel scheduler (a local/CI mirror) and serverless.yml
 * (the EventBridge event that fires in production).
 */
describe('domain check scheduling', function () {
    test('Given the Laravel scheduler, words:check-domains is registered as a daily command', function () {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('words:check-domains')
            ->assertExitCode(0);
    });

    test('Given the production schedule, the domain check runs daily at 07:00 UTC after generate and dictionary check', function () {
        $serverless = file_get_contents(base_path('serverless.yml'));

        expect($serverless)
            ->toContain('rate: cron(0 7 * * ? *)')
            ->toContain("cli: 'words:check-domains --chunk=50'");
    });
});
