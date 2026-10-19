<?php

use App\Backups\BackupMetricPublisher;
use App\Backups\BackupService;
use App\Backups\TursoCli;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('backups');
    config()->set('backups.source_disk', 's3');
    config()->set('backups.destination_disk', 'backups');
    config()->set('backups.production_database', 'production');
});

function backupServiceWith(TursoCli $turso): BackupService
{
    $metrics = mock(BackupMetricPublisher::class);
    $metrics->shouldReceive('publish')->byDefault();

    return new BackupService($turso, $metrics);
}

function successfulTurso(): TursoCli
{
    $turso = mock(TursoCli::class);
    $turso->shouldReceive('dump')->once()->with('production')->andReturn('CREATE TABLE example (id INTEGER);');
    $turso->shouldReceive('createFromDump')->once();
    $turso->shouldReceive('query')->withArgs(fn ($database, $sql) => $database !== 'production' && $sql === 'PRAGMA integrity_check;')->andReturn('ok');
    $turso->shouldReceive('query')->withArgs(function ($database, $sql) {
        if ($database === 'production' || ! str_starts_with($sql, 'SELECT COUNT(*)')) {
            return false;
        }

        return (string) DB::scalar($sql) !== '';
    })->andReturnUsing(fn ($database, $sql) => (string) DB::scalar($sql));
    $turso->shouldReceive('delete')->once();

    return $turso;
}

/** @param list<array{path: string, contents: string}> $objects */
function putCompletionManifest(string $run, CarbonInterface $verifiedAt, array $objects = []): void
{
    $disk = Storage::disk('backups');
    $dump = 'CREATE TABLE example (id INTEGER);';
    $disk->put("runs/$run/database.sql", $dump);
    foreach ($objects as $object) {
        $disk->put("runs/$run/objects/{$object['path']}", $object['contents']);
    }
    $disk->put("runs/$run/completion.json", json_encode([
        'version' => 1,
        'verified_at' => $verifiedAt->toIso8601String(),
        'database' => ['tables' => ['example' => 0], 'sha256' => hash('sha256', $dump)],
        'objects' => array_map(fn (array $object): array => [
            'path' => $object['path'],
            'size' => strlen($object['contents']),
            'sha256' => hash('sha256', $object['contents']),
        ], $objects),
    ], JSON_THROW_ON_ERROR));
}

describe('verified backups', function () {
    test('Given source objects and a successful restore drill, it publishes a completion manifest after copying every object', function () {
        Storage::disk('s3')->put('audio/one.mp3', 'first');
        Storage::disk('s3')->put('favicon.ico', 'second');

        $prefix = backupServiceWith(successfulTurso())->run();

        $manifest = json_decode(Storage::disk('backups')->get("$prefix/completion.json"), true, 512, JSON_THROW_ON_ERROR);
        expect(Storage::disk('backups')->get("$prefix/objects/audio/one.mp3"))->toBe('first')
            ->and(Storage::disk('backups')->get("$prefix/objects/favicon.ico"))->toBe('second')
            ->and($manifest['objects'])->toHaveCount(2)
            ->and(Storage::disk('backups')->directoryExists("$prefix/restore-check"))->toBeFalse();
    });

    test('Given a Turso export failure, it does not publish a completion marker', function () {
        $turso = mock(TursoCli::class);
        $turso->shouldReceive('dump')->once()->andThrow(new RuntimeException('export failed'));

        expect(fn () => backupServiceWith($turso)->run())->toThrow(RuntimeException::class, 'export failed');
        expect(Storage::disk('backups')->allFiles('runs'))->toBe([]);
    });

    test('Given temporary database verification fails, it deletes the temporary database and does not complete the run', function () {
        $turso = mock(TursoCli::class);
        $turso->shouldReceive('dump')->once()->andReturn('CREATE TABLE example (id INTEGER);');
        $turso->shouldReceive('createFromDump')->once();
        $turso->shouldReceive('query')->once()->withArgs(fn ($database, $sql) => $sql === 'PRAGMA integrity_check;')->andThrow(new RuntimeException('integrity failed'));
        $turso->shouldReceive('delete')->once();

        expect(fn () => backupServiceWith($turso)->run())->toThrow(RuntimeException::class, 'integrity failed');
        expect(collect(Storage::disk('backups')->allFiles('runs'))->contains(fn ($path) => str_ends_with($path, '/completion.json')))->toBeFalse();
    });

    test('Given temporary database cleanup fails, it fails without publishing a completion marker', function () {
        $turso = mock(TursoCli::class);
        $turso->shouldReceive('dump')->once()->andReturn('CREATE TABLE example (id INTEGER);');
        $turso->shouldReceive('createFromDump')->once();
        $turso->shouldReceive('query')->withArgs(fn ($database, $sql) => $sql === 'PRAGMA integrity_check;')->andReturn('ok');
        $turso->shouldReceive('query')->withArgs(fn ($database, $sql) => str_starts_with($sql, 'SELECT COUNT(*)'))->andReturn('0');
        $turso->shouldReceive('delete')->once()->andThrow(new RuntimeException('delete failed'));
        $metrics = mock(BackupMetricPublisher::class);
        $metrics->shouldNotReceive('publish');

        expect(fn () => (new BackupService($turso, $metrics))->run())
            ->toThrow(RuntimeException::class, 'Unable to delete temporary Turso database');
        expect(collect(Storage::disk('backups')->allFiles('runs'))->contains(fn ($path) => str_ends_with($path, '/completion.json')))->toBeFalse();
    });

    test('Given a completion manifest younger than four hours, it is fresh', function () {
        putCompletionManifest('20260101T000000Z-a', now('UTC')->subHours(3), [['path' => 'audio/one.mp3', 'contents' => 'first']]);

        expect(backupServiceWith(mock(TursoCli::class))->isFresh())->toBeTrue();
    });

    test('Given a completion manifest exactly four hours old or malformed, it is not fresh', function () {
        putCompletionManifest('old', now('UTC')->subHours(4));
        expect(backupServiceWith(mock(TursoCli::class))->isFresh())->toBeFalse();

        Storage::disk('backups')->put('runs/new/completion.json', '{not json');
        expect(backupServiceWith(mock(TursoCli::class))->isFresh())->toBeFalse();
    });

    test('Given a fresh valid manifest before a later malformed or stale marker, it is fresh', function () {
        putCompletionManifest('20260101T000000Z-valid', now('UTC')->subHours(3));
        Storage::disk('backups')->put('runs/20260101T020000Z-malformed/completion.json', '{not json');
        putCompletionManifest('20260101T010000Z-stale', now('UTC')->subHours(5));

        expect(backupServiceWith(mock(TursoCli::class))->isFresh())->toBeTrue();
    });

    test('Given a fresh manifest whose declared artifacts are missing or changed, it is not fresh', function () {
        putCompletionManifest('broken', now('UTC')->subHours(3), [['path' => 'audio/one.mp3', 'contents' => 'first']]);
        Storage::disk('backups')->put('runs/broken/objects/audio/one.mp3', 'changed');

        expect(backupServiceWith(mock(TursoCli::class))->isFresh())->toBeFalse();
    });
});
