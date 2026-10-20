<?php

namespace App\Backups;

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
        $this->environment = [
            'TURSO_API_TOKEN' => $this->requiredConfiguration('turso_api_token'),
            'TURSO_ORG' => $this->requiredConfiguration('turso_organization'),
        ];
    }

    public function dump(string $database): string
    {
        return $this->run(['db', 'shell', $database, '.dump'])->getOutput();
    }

    public function createFromDump(string $database, string $dumpPath): void
    {
        $dump = fopen($dumpPath, 'rb');
        if ($dump === false) {
            throw new RuntimeException("Unable to read Turso dump [$dumpPath].");
        }

        try {
            $this->run(['db', 'create', $database, '--wait']);
            // The dump creates tables out of foreign-key dependency order.
            $this->run(['db', 'shell', $database], $this->dumpInput($dump));
        } finally {
            fclose($dump);
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

    /** @param resource $dump */
    private function dumpInput($dump): \Generator
    {
        yield "PRAGMA foreign_keys = OFF;\n";

        while (! feof($dump)) {
            $chunk = fread($dump, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Unable to read Turso dump.');
            }

            yield $chunk;
        }
    }

    private function run(array $arguments, mixed $input = null): Process
    {
        $process = new Process([$this->path, ...$arguments], null, $this->environment, null, 600);
        $process->setInput($input);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Turso CLI command failed: '.$process->getErrorOutput());
        }

        return $process;
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
