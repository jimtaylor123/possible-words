<?php

namespace App\Backups;

use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Process\Process;

class ProcessTursoCli implements TursoCli
{
    private readonly string $path;

    /** @var array<string, string> */
    private readonly array $environment;

    public function __construct()
    {
        $path = config('backups.turso_cli_path');
        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('Backup configuration [turso_cli_path] is required.');
        }
        if (! is_file($path)) {
            throw new RuntimeException("Configured Turso CLI [$path] does not exist.");
        }
        if (! is_executable($path)) {
            throw new RuntimeException("Configured Turso CLI [$path] is not executable.");
        }

        $this->path = $path;
        $systemPath = getenv('PATH');
        $this->environment = [
            'TURSO_API_TOKEN' => $this->requiredConfiguration('turso_api_token'),
            'TURSO_ORG' => $this->requiredConfiguration('turso_organization'),
            'PATH' => dirname($this->path).':'.(is_string($systemPath) ? $systemPath : '/usr/local/bin:/usr/bin:/bin'),
        ];
    }

    public function dump(string $database): string
    {
        return $this->run(['db', 'shell', $database, '.dump'])->getOutput();
    }

    public function createFromDump(string $database, string $dumpPath): array
    {
        $dump = file_get_contents($dumpPath);
        if ($dump === false) {
            throw new RuntimeException("Unable to read Turso dump [$dumpPath].");
        }

        $databasePath = tempnam(sys_get_temp_dir(), 'turso-restore-');
        if ($databasePath === false) {
            throw new RuntimeException('Unable to create a temporary SQLite restore database.');
        }

        try {
            $connection = new PDO('sqlite:'.$databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $connection->exec($dump);
            $tables = $this->tableCounts($connection);
            $connection->exec('PRAGMA journal_mode = WAL;');
            unset($connection);
            $this->run(['db', 'create', $database, '--from-file', $databasePath, '--wait']);

            return $tables;
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to build SQLite restore database from Turso dump.', 0, $exception);
        } finally {
            @unlink($databasePath);
        }
    }

    public function query(string $database, string $sql): string
    {
        return $this->run(['db', 'shell', $database, $sql])->getOutput();
    }

    public function delete(string $database): void
    {
        $this->run(['db', 'destroy', $database, '--yes']);
    }

    private function run(array $arguments): Process
    {
        $process = new Process([$this->path, ...$arguments], null, $this->environment, null, 600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Turso CLI command failed: '.$process->getErrorOutput());
        }

        return $process;
    }

    /** @return array<string, int> */
    private function tableCounts(PDO $connection): array
    {
        $tables = $connection->query("SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'libsql_%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $counts = [];

        foreach ($tables as $table) {
            if (! is_string($table)) {
                throw new RuntimeException('SQLite restore database returned an invalid table name.');
            }

            $identifier = '"'.str_replace('"', '""', $table).'"';
            $counts[$table] = (int) $connection->query("SELECT COUNT(*) FROM $identifier")->fetchColumn();
        }

        if ($counts === []) {
            throw new RuntimeException('Turso dump contains no application tables.');
        }

        return $counts;
    }

    private function requiredConfiguration(string $setting): string
    {
        $value = config("backups.$setting");
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Backup configuration [$setting] is required.");
        }

        return $value;
    }
}
