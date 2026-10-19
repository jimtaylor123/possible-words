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
            ->toContain('NoncurrentVersionExpiration:')
            ->toContain('NoncurrentDays: 35')
            ->toContain('ExpiredObjectDeleteMarker: true')
            ->toContain('BackupLambdaErrorsAlarm:')
            ->toContain('BackupFreshnessAlarm:')
            ->toContain('jimtayloremail@gmail.com')
            ->toContain("- 'bin/turso'");
    });

    test('Given the backup Lambda, it receives only backup-specific secrets and documented complete-disk read access', function () {
        $serverless = file_get_contents(base_path('serverless.yml'));

        expect($serverless)
            ->toContain('applicationEnvironment:')
            ->toContain('environment: ${self:custom.applicationEnvironment}')
            ->toContain('TURSO_BACKUP_API_TOKEN: ${env:TURSO_BACKUP_API_TOKEN}')
            ->toContain('complete disk; see docs/backups.md.');
    });

    test('Given the deployment workflow, it verifies the pinned Turso executable before packaging it', function () {
        $workflow = file_get_contents(base_path('.github/workflows/deploy.yml'));

        expect($workflow)
            ->toContain('scripts/install-turso-cli.sh')
            ->toContain("grep -q 'bin/turso'");
    });
});
