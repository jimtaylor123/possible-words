<?php

namespace App\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BackupService
{
    public function __construct(
        private readonly TursoCli $turso,
        private readonly BackupMetricPublisher $metrics,
        private readonly float $restoreDeadlineSeconds = 60.0,
        private readonly int $restorePollSeconds = 1,
    ) {
        if ($restoreDeadlineSeconds < 0) {
            throw new RuntimeException('Restore deadline must not be negative.');
        }

        if ($restorePollSeconds < 0) {
            throw new RuntimeException('Restore poll interval must not be negative.');
        }
    }

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
                $this->waitForRestore($temporaryDatabase, $tables);
                $this->verifyDatabase($temporaryDatabase, $tables);
            } finally {
                // No error suppression here: if the dump cannot be removed the
                // failure surfaces instead of quietly leaving stale files.
                if (file_exists($dumpPath)) {
                    unlink($dumpPath);
                }
            }

            // Clear the flag before the attempt so a failed deletion is never
            // retried from the finally block, where a second exception would
            // mask this one.
            $temporaryDatabaseCreated = false;
            $this->deleteTemporaryDatabase($temporaryDatabase);

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
        $platformToken = $this->requiredConfiguration('turso_api_token');
        $organization = $this->requiredConfiguration('turso_organization');
        $database = $this->requiredConfiguration('production_database');
        $databasePath = 'v1/organizations/'.rawurlencode($organization).'/databases/'.rawurlencode($database);
        $hostnameResponse = $this->platformRequest($platformToken, 'get', $databasePath);
        $hostname = $hostnameResponse->json('database.Hostname');
        if (! is_string($hostname) || ! $this->isValidHostname($hostname)) {
            throw new RuntimeException('Turso Platform API returned an invalid production database hostname.');
        }

        $tokenResponse = $this->platformRequest(
            $platformToken,
            'post',
            $databasePath.'/auth/tokens',
            ['expiration' => '5m', 'authorization' => 'read-only'],
        );
        $sqlToken = $tokenResponse->json('jwt');
        if (! is_string($sqlToken) || trim($sqlToken) === '') {
            throw new RuntimeException('Turso Platform API did not return a read-only database SQL token.');
        }

        $tables = $this->pipelineRows(
            $hostname,
            $sqlToken,
            "SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'libsql_%'",
        );
        $counts = [];

        foreach ($tables as $table) {
            $name = $this->textValue($table[0] ?? null, 'table name');
            $count = $this->pipelineRows(
                $hostname,
                $sqlToken,
                'SELECT COUNT(*) FROM "'.str_replace('"', '""', $name).'"',
            );
            $counts[$name] = $this->integerValue($count[0][0] ?? null, "row count for table [$name]");
        }

        if ($counts === []) {
            throw new RuntimeException('Production database has no application tables to verify.');
        }

        return $counts;
    }

    /** @param array<string, string> $data */
    private function platformRequest(string $token, string $method, string $path, array $data = []): Response
    {
        try {
            $request = Http::timeout(15)
                ->acceptJson()
                ->withToken($token);
            $url = rtrim($this->requiredConfiguration('turso_platform_api_url'), '/').'/'.$path;
            $response = $method === 'get'
                ? $request->get($url)
                : $request->post($url, $data);
        } catch (Throwable) {
            throw new RuntimeException('Unable to contact the Turso Platform API.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Turso Platform API request failed.');
        }

        return $response;
    }

    /** @return list<list<array{type: string, value?: string}>> */
    private function pipelineRows(string $hostname, string $token, string $sql): array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withToken($token)
                ->post("https://$hostname/v2/pipeline", [
                    'requests' => [
                        ['type' => 'execute', 'stmt' => ['sql' => $sql]],
                        ['type' => 'close'],
                    ],
                ]);
        } catch (Throwable) {
            throw new RuntimeException('Unable to contact the Turso production database.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Turso production database query failed.');
        }

        $results = $response->json('results');
        $execute = is_array($results) ? $results[0] ?? null : null;
        $close = is_array($results) ? $results[1] ?? null : null;
        $rows = is_array($execute) ? data_get($execute, 'response.result.rows') : null;

        if (data_get($execute, 'type') !== 'ok' || data_get($execute, 'response.type') !== 'execute'
            || ! is_array($rows) || data_get($close, 'type') !== 'ok' || data_get($close, 'response.type') !== 'close') {
            throw new RuntimeException('Turso production database returned an invalid pipeline response.');
        }

        return $rows;
    }

    /** @param array{type?: mixed, value?: mixed}|mixed $value */
    private function textValue(mixed $value, string $description): string
    {
        if (! is_array($value) || ($value['type'] ?? null) !== 'text' || ! is_string($value['value'] ?? null) || $value['value'] === '') {
            throw new RuntimeException("Turso production database returned an invalid $description.");
        }

        return $value['value'];
    }

    /** @param array{type?: mixed, value?: mixed}|mixed $value */
    private function integerValue(mixed $value, string $description): int
    {
        if (! is_array($value) || ($value['type'] ?? null) !== 'integer' || ! is_string($value['value'] ?? null)
            || preg_match('/\A\d+\z/', $value['value']) !== 1) {
            throw new RuntimeException("Turso production database returned an invalid $description.");
        }

        return (int) $value['value'];
    }

    private function isValidHostname(string $hostname): bool
    {
        return preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/i', $hostname) === 1;
    }

    private function requiredConfiguration(string $setting): string
    {
        $value = config("backups.$setting");
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Backup configuration [$setting] is required.");
        }

        return $value;
    }

    private function validateConfiguration(): void
    {
        foreach ([
            'source_disk',
            'destination_disk',
            'prefix',
            'production_database',
            'temporary_database_prefix',
            'turso_api_token',
            'turso_organization',
            'turso_platform_api_url',
        ] as $setting) {
            $this->requiredConfiguration($setting);
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
                    $length = strlen($chunk);
                    $offset = 0;
                    while ($offset < $length) {
                        $written = fwrite($temporary, substr($chunk, $offset));
                        if ($written === false || $written === 0) {
                            throw new RuntimeException("Unable to write temporary copy of object [$path].");
                        }
                        $offset += $written;
                    }
                    $size += $length;
                    hash_update($hash, $chunk);
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
        $integrityCheck = $this->turso->query($database, 'PRAGMA integrity_check;');
        if (preg_match('/(?:^|\R)\s*ok\s*(?:\R|$)/i', $integrityCheck) !== 1) {
            throw new RuntimeException('Restored database failed integrity_check.');
        }

        foreach ($expected as $table => $count) {
            $actual = (int) trim($this->turso->query($database, 'SELECT COUNT(*) FROM '.$this->quoteIdentifier($table).';'));
            if ($actual !== $count) {
                throw new RuntimeException("Restored table [$table] has an unexpected row count.");
            }
        }
    }

    /** @param array<string, int> $expected */
    private function waitForRestore(string $database, array $expected): void
    {
        // Build the probe statements before the retry loop so an invalid
        // identifier fails fast instead of being swallowed as "not ready".
        $probes = array_map(
            fn (string $table): string => 'SELECT 1 FROM '.$this->quoteIdentifier($table).' LIMIT 1;',
            array_keys($expected),
        );

        $deadline = microtime(true) + $this->restoreDeadlineSeconds;

        do {
            try {
                foreach ($probes as $sql) {
                    $this->turso->query($database, $sql);
                }

                return;
            } catch (RuntimeException $exception) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Restored database did not become ready before verification.', 0, $exception);
                }

                sleep($this->restorePollSeconds);
            }
        } while (true);
    }

    /**
     * Quote an SQL identifier using SQLite double-quote escaping.
     *
     * Only characters that may legitimately appear inside a double-quoted
     * identifier are accepted, so a table name can never break out of the
     * quoting even if it were ever sourced from untrusted input.
     */
    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new RuntimeException("Unsupported SQL identifier [$identifier].");
        }

        return '"'.str_replace('"', '""', $identifier).'"';
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
            $bytesRead = hash_update_stream($hash, $stream);

            return ['size' => $bytesRead, 'sha256' => hash_final($hash)];
        } finally {
            fclose($stream);
        }
    }
}
