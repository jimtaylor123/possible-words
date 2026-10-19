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
        $this->validateConfiguration();

        $source = Storage::disk(config('backups.source_disk'));
        $destination = Storage::disk(config('backups.destination_disk'));
        $runPrefix = config('backups.prefix').'/'.now('UTC')->format('Ymd\THis\Z').'-'.Str::uuid();
        $temporaryDatabase = config('backups.temporary_database_prefix').'-'.Str::lower(Str::random(20));
        $temporaryDatabaseCreated = false;

        try {
            $tables = $this->productionTableCounts();
            $dump = $this->turso->dump(config('backups.production_database'));
            if (trim($dump) === '') {
                throw new RuntimeException('Turso export produced an empty dump.');
            }
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

            $this->deleteTemporaryDatabase($temporaryDatabase);
            $temporaryDatabaseCreated = false;

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
                $this->deleteTemporaryDatabase($temporaryDatabase);
            }
        }
    }

    public function isFresh(): bool
    {
        $destination = Storage::disk(config('backups.destination_disk'));
        $manifests = collect($destination->allFiles(config('backups.prefix')))
            ->filter(fn (string $path): bool => str_ends_with($path, '/completion.json'))
            ->map(fn (string $path): array => ['path' => $path, 'contents' => $destination->get($path)])
            ->sortByDesc('path');

        foreach ($manifests as $manifest) {
            if ($this->isUsableFreshManifest($destination, $manifest['path'], $manifest['contents'])) {
                return true;
            }
        }

        return false;
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

    private function validateConfiguration(): void
    {
        foreach ([
            'source_disk',
            'destination_disk',
            'prefix',
            'production_database',
            'temporary_database_prefix',
        ] as $setting) {
            $value = config("backups.$setting");
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException("Backup configuration [$setting] is required.");
            }
        }
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

    private function deleteTemporaryDatabase(string $database): void
    {
        try {
            $this->turso->delete($database);
        } catch (Throwable $exception) {
            throw new RuntimeException("Unable to delete temporary Turso database [$database].", 0, $exception);
        }
    }

    /** @param array{path: string, contents: string} $manifest */
    private function isUsableFreshManifest(Filesystem $destination, string $manifestPath, string $contents): bool
    {
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_string($data['verified_at'] ?? null)) {
                return false;
            }
            $verifiedAt = CarbonImmutable::parse($data['verified_at']);
            if (($data['version'] ?? null) !== 1 || ! $verifiedAt->greaterThan(now('UTC')->subHours(4))
                || ! is_array($data['database'] ?? null) || ! is_array($data['objects'] ?? null)) {
                return false;
            }

            $runPrefix = dirname($manifestPath);
            $databaseChecksum = $this->checksum($destination, "$runPrefix/database.sql");
            if (! isset($data['database']['tables']) || ! is_array($data['database']['tables'])
                || ! is_string($data['database']['sha256'])
                || $databaseChecksum === null
                || ! hash_equals($data['database']['sha256'], $databaseChecksum['sha256'])) {
                return false;
            }

            foreach ($data['objects'] as $object) {
                if (! is_array($object) || ! is_string($object['path'] ?? null) || $object['path'] === ''
                    || ! is_int($object['size'] ?? null) || ! is_string($object['sha256'] ?? null)) {
                    return false;
                }
                $checksum = $this->checksum($destination, "$runPrefix/objects/{$object['path']}");
                if ($checksum === null || $checksum['size'] !== $object['size']
                    || ! hash_equals($object['sha256'], $checksum['sha256'])) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{size: int, sha256: string}|null */
    private function checksum(Filesystem $destination, string $path): ?array
    {
        $stream = $destination->readStream($path);
        if (! is_resource($stream)) {
            return null;
        }

        try {
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $stream);

            return ['size' => $size, 'sha256' => hash_final($hash)];
        } finally {
            fclose($stream);
        }
    }
}
