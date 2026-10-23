<?php

use Symfony\Component\Yaml\Yaml;

describe('backup infrastructure', function () {
    test('Given the production Serverless definition, backups are isolated, scheduled, and alarmed', function () {
        $serverless = Yaml::parseFile(base_path('serverless.yml'));
        $backupLifecycleRules = $serverless['resources']['Resources']['BackupBucket']['Properties']['LifecycleConfiguration']['Rules'];

        expect($serverless['functions']['backup']['events'])
            ->toContain(['schedule' => ['rate' => 'cron(0 */6 * * ? *)', 'enabled' => true, 'input' => ['cli' => 'backup:run']]])
            ->toContain(['schedule' => ['rate' => 'cron(20 * * * ? *)', 'input' => ['cli' => 'backup:check-freshness']]]);
        expect($serverless['functions']['backup']['timeout'])->toBe(900);
        expect($serverless['resources']['Resources']['BackupFreshnessAlarm']['Properties']['AlarmDescription'])
            ->toBe('No verified backup manifest is younger than six hours and twenty minutes.');
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
        expect($serverless['package']['patterns'])->toContain('bin/sqlite3');
        expect($serverless['package']['patterns'])->toContain('vendor/aws/aws-sdk-php/src/data/monitoring/**');
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
            ->toContain("grep -q 'bin/sqlite3'")
            ->toContain("BACKUP_RETENTION_DAYS: \${{ vars.BACKUP_RETENTION_DAYS || '7' }}");
    });

    test('Given the Laravel console schedule, backups run every six hours and the freshness check runs quarter past the hour', function () {
        $schedule = file_get_contents(base_path('routes/console.php'));

        expect($schedule)
            ->toContain("Schedule::command('backup:run')->everySixHours()->withoutOverlapping()")
            ->toContain("Schedule::command('backup:check-freshness')->hourlyAt(20)->withoutOverlapping()")
            ->not->toContain("Schedule::command('backup:run')->everyThreeHours()")
            ->not->toContain("Schedule::command('backup:check-freshness')->hourly()");
    });

    test('Given the backup documentation and freshness command, they describe the same window as the CloudWatch alarm', function () {
        $documentation = preg_replace('/\s+/', ' ', file_get_contents(base_path('docs/backups.md')));
        $command = file_get_contents(base_path('app/Console/Commands/CheckBackupFreshnessCommand.php'));
        $alarmDescription = Yaml::parseFile(base_path('serverless.yml'))['resources']['Resources']['BackupFreshnessAlarm']['Properties']['AlarmDescription'];

        expect($documentation)
            ->toContain('an enabled backup runs every six hours')
            ->toContain('six hours and twenty minutes')
            ->toContain('turso db import ./<restore-db>.db --group default');
        expect($command)->toContain('six hours and twenty minutes');
        expect($alarmDescription)->toBe('No verified backup manifest is younger than six hours and twenty minutes.');
    });

    test('Given the pinned Turso installer, it uses the issue-02 verified Linux release archive', function () {
        $installer = file_get_contents(base_path('scripts/install-turso-cli.sh'));

        expect($installer)
            ->toContain("version='v1.0.34'")
            ->toContain("archive='turso-cli_Linux_x86_64.tar.gz'")
            ->toContain("sha256='9eb67f3efece578a6407d1fa535a75e8d7b5cde4f6af3ac1dd79929e99768dc9'");
    });
});
