<?php

describe('word release configuration', function () {
    test('Given production deployment configuration, it passes the Gemini key from GitHub Actions to Lambda', function () {
        $deployWorkflow = file_get_contents(base_path('.github/workflows/deploy.yml'));
        $serverless = file_get_contents(base_path('serverless.yml'));

        expect($deployWorkflow)
            ->toContain('GEMINI_API_KEY: ${{ secrets.GEMINI_API_KEY }}')
            ->and($serverless)
            ->toContain('GEMINI_API_KEY: ${env:GEMINI_API_KEY}');
    });

    test('Given a schema change, it migrates before deploying web code that requires it', function () {
        $deployWorkflow = file_get_contents(base_path('.github/workflows/deploy.yml'));

        expect($deployWorkflow)
            ->toContain('deploy function -f artisan')
            ->and(strpos($deployWorkflow, 'name: Deploy migration runner'))
            ->toBeLessThan(strpos($deployWorkflow, 'name: Run production migrations'))
            ->and(strpos($deployWorkflow, 'name: Run production migrations'))
            ->toBeLessThan(strpos($deployWorkflow, 'name: Deploy with Serverless'));
    });

    test('Given no deployment overrides, the initial release policy is ten words every six hours', function () {
        expect(config('word-release.batch_size'))->toBe(10)
            ->and(config('word-release.eventbridge_expression'))->toBe('cron(0 */6 * * ? *)');
    });

    test('Given the production schedule, it invokes the release command with the configured expression', function () {
        $serverless = file_get_contents(base_path('serverless.yml'));

        expect($serverless)
            ->toContain("rate: \${env:WORD_RELEASE_SCHEDULE, 'cron(0 */6 * * ? *)'}")
            ->toContain("cli: 'words:release'");
    });
});
