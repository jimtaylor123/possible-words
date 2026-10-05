<?php

describe('word release configuration', function () {
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
