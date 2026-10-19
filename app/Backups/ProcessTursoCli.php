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
        $this->path = (string) config('backups.turso_cli_path');
        $this->environment = [
            'TURSO_API_TOKEN' => (string) config('backups.turso_api_token'),
            'TURSO_ORG' => (string) config('backups.turso_organization'),
        ];
    }

    public function dump(string $database): string
    {
        return $this->run(['db', 'shell', $database, '.dump'])->getOutput();
    }

    public function createFromDump(string $database, string $dumpPath): void
    {
        $this->run(['db', 'create', $database, '--from-dump', $dumpPath]);
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
}
