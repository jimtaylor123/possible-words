<?php

use Symfony\Component\Yaml\Yaml;

describe('backup infrastructure', function () {
    test('Given the production Serverless definition, backups are isolated, scheduled, and alarmed', function () {
        $serverless = Yaml::parseFile(base_path('serverless.yml'));
        $backupLifecycleRules = $serverless['resources']['Resources']['BackupBucket']['Properties']['LifecycleConfiguration']['Rules'];

        expect($serverless['functions']['backup']['events'])
            ->toContain(['schedule' => ['rate' => 'cron(0 */3 * * ? *)', 'input' => ['cli' => 'backup:run']]])
            ->toContain(['schedule' => ['rate' => 'cron(0 * * * ? *)', 'input' => ['cli' => 'backup:check-freshness']]]);
        expect($serverless['functions']['backup']['timeout'])->toBe(720);
        expect($serverless['functions']['backup']['environment']['BROADCAST_CONNECTION'])->toBe('log');
        expect($serverless['resources']['Resources']['BackupBucket']['Properties'])
            ->toHaveKey('VersioningConfiguration')
            ->and($serverless['resources']['Resources']['BackupBucket']['Properties']['PublicAccessBlockConfiguration']['BlockPublicAcls'])->toBeTrue();
        expect($serverless['custom']['backupRetentionDays'])->toBe('${env:BACKUP_RETENTION_DAYS, 7}');
        expect($backupLifecycleRules)->toContain([
            'Id' => 'ExpireBackupRunData',
            'Status' => 'Enabled',
            'ExpirationInDays' => '${self:custom.backupRetentionDays}',
            'NoncurrentVersionExpiration' => ['NoncurrentDays' => 1],
        ])->toContain([
            'Id' => 'DeleteExpiredBackupDeleteMarkers',
            'Status' => 'Enabled',
            'ExpiredObjectDeleteMarker' => true,
        ]);
        expect($serverless['resources']['Resources'])->toHaveKeys([
            'BackupLambdaErrorsAlarm',
            'BackupFreshnessAlarm',
        ]);
        expect($serverless['resources']['Resources']['BackupAlarmTopic']['Properties']['Subscription'])
            ->toContain(['Endpoint' => 'jimtayloremail@gmail.com', 'Protocol' => 'email']);
        expect($serverless['package']['patterns'])->toContain('bin/turso');
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
            ->toContain("grep -q 'bin/turso'")
            ->toContain("BACKUP_RETENTION_DAYS: \${{ vars.BACKUP_RETENTION_DAYS || '7' }}");
    });
});
