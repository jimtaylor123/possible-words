<?php

namespace App\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BackupService
{
    public function __construct(
        private readonly TursoCli $turso,
        private readonly BackupMetricPublisher $metrics,
    ) {}

    public function run(): string
    {
        $source = Storage::disk(config('backups.source_disk'));
        $destination = Storage::disk(config('backups.destination_disk'));
        $runPrefix = config('backups.prefix').'/'.now('UTC')->format('Ymd\THis\Z').'-'.Str::uuid();
        $temporaryDatabase = config('backups.temporary_database_prefix').'-'.Str::lower(Str::random(20));
        $temporaryDatabaseCreated = false;

        try {
            $tables = $this->productionTableCounts();
            $dump = $this->turso->dump(config('backups.production_database'));
            $destination->put("$runPrefix/database.sql", $dump);

            $objects = $this->copyObjects($source, $destination, $runPrefix);
            $dumpPath = $this->writeTemporaryDump($dump);

            try {
                $this->turso->createFromDump($temporaryDatabase, $dumpPath);
                $temporaryDatabaseCreated = true;
                $this->verifyDatabase($temporaryDatabase, $tables);
            } finally {
                @unlink($dumpPath);
            }

            $this->verifyObjectRestore($destination, $runPrefix, $objects);
            $manifest = [
                'version' => 1,
                'verified_at' => now('UTC')->toIso8601String(),
                'database' => ['tables' => $tables, 'sha256' => hash('sha256', $dump)],
                'objects' => $objects,
            ];
            $destination->put("$runPrefix/completion.json", json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->metrics->publish('BackupVerified', 1);

            return $runPrefix;
        } finally {
            if ($temporaryDatabaseCreated) {
                try {
                    $this->turso->delete($temporaryDatabase);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
    }

    public function isFresh(): bool
    {
        $destination = Storage::disk(config('backups.destination_disk'));
        $manifest = collect($destination->allFiles(config('backups.prefix')))
            ->filter(fn (string $path): bool => str_ends_with($path, '/completion.json'))
            ->map(fn (string $path): array => ['path' => $path, 'contents' => $destination->get($path)])
            ->sortByDesc('path')
            ->first();

        if ($manifest === null) {
            return false;
        }

        try {
            $data = json_decode($manifest['contents'], true, 512, JSON_THROW_ON_ERROR);
            $verifiedAt = CarbonImmutable::parse($data['verified_at'] ?? null);

            return ($data['version'] ?? null) === 1 && isset($data['database'], $data['objects'])
                && $verifiedAt->greaterThan(now('UTC')->subHours(4));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, int> */
    private function productionTableCounts(): array
    {
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        $counts = [];

        foreach ($tables as $table) {
            $name = (string) $table->name;
            $counts[$name] = (int) DB::scalar('SELECT COUNT(*) FROM "'.str_replace('"', '""', $name).'"');
        }

        if ($counts === []) {
            throw new RuntimeException('Production database has no application tables to verify.');
        }

        return $counts;
    }

    /** @return list<array{path: string, size: int, sha256: string}> */
    private function copyObjects(Filesystem $source, Filesystem $destination, string $runPrefix): array
    {
        $objects = [];
        foreach ($source->allFiles() as $path) {
            $sourceStream = $source->readStream($path);
            if (! is_resource($sourceStream)) {
                throw new RuntimeException("Unable to read source object [$path].");
            }

            $temporary = tmpfile();
            if ($temporary === false) {
                throw new RuntimeException('Unable to allocate temporary object stream.');
            }

            try {
                $hash = hash_init('sha256');
                $size = 0;
                while (! feof($sourceStream)) {
                    $chunk = fread($sourceStream, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException("Unable to read source object [$path].");
                    }
                    $size += strlen($chunk);
                    hash_update($hash, $chunk);
                    fwrite($temporary, $chunk);
                }
                rewind($temporary);
                $destination->writeStream("$runPrefix/objects/$path", $temporary);
                $objects[] = ['path' => $path, 'size' => $size, 'sha256' => hash_final($hash)];
            } finally {
                fclose($sourceStream);
                fclose($temporary);
            }
        }

        return $objects;
    }

    /** @param array<string, int> $expected */
    private function verifyDatabase(string $database, array $expected): void
    {
        if (strtolower(trim($this->turso->query($database, 'PRAGMA integrity_check;'))) !== 'ok') {
            throw new RuntimeException('Restored database failed integrity_check.');
        }

        foreach ($expected as $table => $count) {
            $actual = (int) trim($this->turso->query($database, 'SELECT COUNT(*) FROM "'.str_replace('"', '""', $table).'";'));
            if ($actual !== $count) {
                throw new RuntimeException("Restored table [$table] has an unexpected row count.");
            }
        }
    }

    /** @param list<array{path: string, size: int, sha256: string}> $objects */
    private function verifyObjectRestore(Filesystem $destination, string $runPrefix, array $objects): void
    {
        $restorePrefix = "$runPrefix/restore-check";
        try {
            foreach ($objects as $object) {
                $stream = $destination->readStream("$runPrefix/objects/{$object['path']}");
                if (! is_resource($stream)) {
                    throw new RuntimeException("Unable to restore object [{$object['path']}].");
                }
                $destination->writeStream("$restorePrefix/{$object['path']}", $stream);
                fclose($stream);
                $restoredStream = $destination->readStream("$restorePrefix/{$object['path']}");
                if (! is_resource($restoredStream)) {
                    throw new RuntimeException("Unable to read restored object [{$object['path']}].");
                }
                $hash = hash_init('sha256');
                $size = hash_update_stream($hash, $restoredStream);
                fclose($restoredStream);
                if ($size !== $object['size'] || hash_final($hash) !== $object['sha256']) {
                    throw new RuntimeException("Restored object [{$object['path']}] checksum mismatch.");
                }
            }
        } finally {
            $destination->deleteDirectory($restorePrefix);
        }
    }

    private function writeTemporaryDump(string $dump): string
    {
        $path = tempnam(sys_get_temp_dir(), 'possiblewords-backup-');
        if ($path === false || file_put_contents($path, $dump) === false) {
            throw new RuntimeException('Unable to write temporary database dump.');
        }

        return $path;
    }
}
