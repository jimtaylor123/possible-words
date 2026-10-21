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
        $path = tursoCliStub('echo "$TURSO_API_TOKEN|$TURSO_ORG|$PATH|$*"');
        config()->set('backups.turso_cli_path', $path);
        config()->set('backups.turso_api_token', 'backup-token');
        config()->set('backups.turso_organization', 'backup-org');

        expect((new ProcessTursoCli)->dump('production'))
            ->toStartWith('backup-token|backup-org|'.dirname($path).':')
            ->toEndWith("|db shell production .dump\n");
    });

    test('Given a failing CLI invocation, it throws with the stderr output', function () {
        config()->set('backups.turso_cli_path', tursoCliStub("echo 'turso exploded' >&2\nexit 3"));
        config()->set('backups.turso_api_token', 'backup-token');
        config()->set('backups.turso_organization', 'backup-org');

        expect(fn () => (new ProcessTursoCli)->delete('staging'))
            ->toThrow(RuntimeException::class, 'Turso CLI command failed: turso exploded');
    });

    test('Given a SQL dump, it creates the database from a SQLite file', function () {
        $dump = tempnam(sys_get_temp_dir(), 'turso-dump-');
        $database = tempnam(sys_get_temp_dir(), 'turso-database-');
        $arguments = tempnam(sys_get_temp_dir(), 'turso-arguments-');
        file_put_contents($dump, 'CREATE TABLE migrations (id INTEGER);');
        config()->set('backups.turso_cli_path', tursoCliStub("cp \"\$5\" '$database'\nprintf '%s' \"\$*\" > '$arguments'"));
        config()->set('backups.turso_api_token', 'backup-token');
        config()->set('backups.turso_organization', 'backup-org');

        (new ProcessTursoCli)->createFromDump('restore-check', $dump);

        expect(file_get_contents($dump))->toBe('CREATE TABLE migrations (id INTEGER);');
        expect(file_get_contents($arguments))
            ->toStartWith('db create restore-check --from-file ')
            ->toEndWith(' --wait');
        expect((new PDO('sqlite:'.$database))->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'migrations'")->fetchColumn())
            ->toBe('migrations');
        expect((new PDO('sqlite:'.$database))->query('PRAGMA journal_mode;')->fetchColumn())
            ->toBe('wal');
        unlink($dump);
        unlink($database);
        unlink($arguments);
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
