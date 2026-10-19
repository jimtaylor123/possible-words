<?php

use App\Backups\BackupMetricPublisher;
use App\Backups\BackupService;
use App\Backups\TursoCli;
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

    test('Given a completion manifest younger than four hours, it is fresh', function () {
        Storage::disk('backups')->put('runs/20260101T000000Z-a/completion.json', json_encode([
            'version' => 1,
            'verified_at' => now('UTC')->subHours(3)->toIso8601String(),
            'database' => [],
            'objects' => [],
        ], JSON_THROW_ON_ERROR));

        expect(backupServiceWith(mock(TursoCli::class))->isFresh())->toBeTrue();
    });

    test('Given a completion manifest exactly four hours old or malformed, it is not fresh', function () {
        Storage::disk('backups')->put('runs/old/completion.json', json_encode([
            'version' => 1,
            'verified_at' => now('UTC')->subHours(4)->toIso8601String(),
            'database' => [],
            'objects' => [],
        ], JSON_THROW_ON_ERROR));
        expect(backupServiceWith(mock(TursoCli::class))->isFresh())->toBeFalse();

        Storage::disk('backups')->put('runs/new/completion.json', '{not json');
        expect(backupServiceWith(mock(TursoCli::class))->isFresh())->toBeFalse();
    });
});
