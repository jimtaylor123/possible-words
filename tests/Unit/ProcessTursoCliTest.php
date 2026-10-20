<?php

use App\Backups\ProcessTursoCli;

/** @return string path to an executable stub script */
function tursoCliStub(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'turso-cli-');
    if ($path === false || file_put_contents($path, "#!/bin/sh\n{$body}\n") === false) {
        throw new RuntimeException('Unable to write Turso CLI stub.');
    }
    chmod($path, 0755);

    return $path;
}

describe('Turso CLI process', function () {
    test('Given a successful CLI invocation, it returns stdout with the configured token and organization', function () {
        config()->set('backups.turso_cli_path', tursoCliStub('echo "$TURSO_API_TOKEN|$TURSO_ORG|$*"'));
        config()->set('backups.turso_api_token', 'backup-token');
        config()->set('backups.turso_organization', 'backup-org');

        expect((new ProcessTursoCli)->dump('production'))
            ->toBe("backup-token|backup-org|db shell production .dump\n");
    });

    test('Given a failing CLI invocation, it throws with the stderr output', function () {
        config()->set('backups.turso_cli_path', tursoCliStub("echo 'turso exploded' >&2\nexit 3"));
        config()->set('backups.turso_api_token', 'backup-token');
        config()->set('backups.turso_organization', 'backup-org');

        expect(fn () => (new ProcessTursoCli)->delete('staging'))
            ->toThrow(RuntimeException::class, 'Turso CLI command failed: turso exploded');
    });

    test('Given a SQL dump, it imports it with foreign keys disabled', function () {
        $dump = tempnam(sys_get_temp_dir(), 'turso-dump-');
        $input = tempnam(sys_get_temp_dir(), 'turso-input-');
        file_put_contents($dump, 'CREATE TABLE migrations (id INTEGER);');
        config()->set('backups.turso_cli_path', tursoCliStub("if [ \"\$1 \$2\" = \"db shell\" ]; then cat > '$input'; fi"));
        config()->set('backups.turso_api_token', 'backup-token');
        config()->set('backups.turso_organization', 'backup-org');

        (new ProcessTursoCli)->createFromDump('restore-check', $dump);

        expect(file_get_contents($dump))->toBe('CREATE TABLE migrations (id INTEGER);');
        expect(file_get_contents($input))->toBe("PRAGMA foreign_keys = OFF;\nCREATE TABLE migrations (id INTEGER);");
        unlink($dump);
        unlink($input);
    });

    test('Given the configured CLI does not exist, it fails before starting a process', function () {
        config()->set('backups.turso_cli_path', '/missing/turso');

        expect(fn () => new ProcessTursoCli)
            ->toThrow(RuntimeException::class, 'Configured Turso CLI [/missing/turso] does not exist.');
    });

    test('Given the configured CLI is not executable, it fails before starting a process', function () {
        $path = tempnam(sys_get_temp_dir(), 'turso-cli-');
        chmod($path, 0644);
        config()->set('backups.turso_cli_path', $path);

        expect(fn () => new ProcessTursoCli)
            ->toThrow(RuntimeException::class, "Configured Turso CLI [$path] is not executable.");
    });

    test('Given required Turso credentials are missing, it fails before starting a process', function () {
        config()->set('backups.turso_cli_path', tursoCliStub('exit 0'));
        config()->set('backups.turso_api_token', '');

        expect(fn () => new ProcessTursoCli)
            ->toThrow(RuntimeException::class, 'Backup configuration [turso_api_token] is required.');
    });
});
