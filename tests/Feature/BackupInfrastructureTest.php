<?php

describe('backup infrastructure', function () {
    test('Given the production Serverless definition, backups are isolated, scheduled, and alarmed', function () {
        $serverless = file_get_contents(base_path('serverless.yml'));

        expect($serverless)
            ->toContain('backup:run')
            ->toContain('backup:check-freshness')
            ->toContain('timeout: 720')
            ->toContain('BackupBucket:')
            ->toContain('BlockPublicAcls: true')
            ->toContain('VersioningConfiguration:')
            ->toContain('ExpirationInDays: 35')
            ->toContain('BackupLambdaErrorsAlarm:')
            ->toContain('BackupFreshnessAlarm:')
            ->toContain('jimtayloremail@gmail.com')
            ->toContain("- 'bin/turso'");
    });

    test('Given the deployment workflow, it verifies the pinned Turso executable before packaging it', function () {
        $workflow = file_get_contents(base_path('.github/workflows/deploy.yml'));

        expect($workflow)
            ->toContain('scripts/install-turso-cli.sh')
            ->toContain("grep -q 'bin/turso'");
    });
});
