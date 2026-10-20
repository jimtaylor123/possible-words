<?php

use App\Backups\BackupMetricPublisher;
use App\Backups\BackupService;
use App\Backups\TursoCli;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('backups');
    config()->set('backups.source_disk', 's3');
    config()->set('backups.destination_disk', 'backups');
    config()->set('backups.production_database', 'production');
    config()->set('backups.turso_api_token', 'platform-token');
    config()->set('backups.turso_organization', 'possiblewords');
});

function backupServiceWith(TursoCli $turso): BackupService
{
    $metrics = mock(BackupMetricPublisher::class);
    $metrics->shouldReceive('publish')->byDefault();

    return new BackupService($turso, $metrics);
}

/** @param array<string, int> $tables */
function successfulTurso(array $tables = ['example' => 0]): TursoCli
{
    $turso = mock(TursoCli::class);
    $turso->shouldReceive('dump')->once()->with('production')->andReturn('CREATE TABLE example (id INTEGER);');
    $turso->shouldReceive('createFromDump')->once();
    $turso->shouldReceive('query')->withArgs(fn ($database, $sql) => $database !== 'production' && str_starts_with($sql, 'SELECT 1 FROM'))->andReturn('1');
    $turso->shouldReceive('query')->withArgs(fn ($database, $sql) => $database !== 'production' && $sql === 'PRAGMA integrity_check;')->andReturn("integrity_check\n---------------\nok\n");
    $turso->shouldReceive('query')->withArgs(fn ($database, $sql) => $database !== 'production' && str_starts_with($sql, 'SELECT COUNT(*)'))->andReturnUsing(function ($database, $sql) use ($tables): string {
        preg_match('/FROM "(.+)"/', $sql, $matches);

        return (string) $tables[str_replace('""', '"', $matches[1] ?? '')];
    });
    $turso->shouldReceive('delete')->once();

    return $turso;
}

/** @param array<string, int> $tables */
function fakeProductionTableCounts(array $tables = ['example' => 0]): void
{
    Http::fake(function (Request $request) use ($tables) {
        if ($request->method() === 'GET' && $request->url() === 'https://api.turso.tech/v1/organizations/possiblewords/databases/production') {
            return Http::response(['database' => ['Hostname' => 'production-possiblewords.turso.io']]);
        }

        if ($request->method() === 'POST' && $request->url() === 'https://api.turso.tech/v1/organizations/possiblewords/databases/production/auth/tokens'
            && $request->data() === ['expiration' => '5m', 'authorization' => 'read-only']) {
            return Http::response(['jwt' => 'short-lived-read-only-token']);
        }

        if ($request->method() === 'POST' && $request->url() === 'https://production-possiblewords.turso.io/v2/pipeline') {
            $sql = $request->data()['requests'][0]['stmt']['sql'];
            if (str_starts_with($sql, 'SELECT name FROM sqlite_schema')) {
                return pipelineResponse(array_map(
                    fn (string $name): array => [['type' => 'text', 'value' => $name]],
                    array_keys($tables),
                ));
            }

            preg_match('/FROM "(.+)"/', $sql, $matches);
            $table = str_replace('""', '"', $matches[1] ?? '');

            return pipelineResponse([[['type' => 'integer', 'value' => (string) $tables[$table]]]]);
        }

        return Http::response([], 404);
    });
}

/** @param list<list<array{type: string, value: string}>> $rows */
function pipelineResponse(array $rows, string $executeType = 'ok')
{
    return Http::response([
        'results' => [
            ['type' => $executeType, 'response' => ['type' => 'execute', 'result' => ['rows' => $rows]]],
            ['type' => 'ok', 'response' => ['type' => 'close']],
        ],
    ]);
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
        fakeProductionTableCounts(['example' => 2, 'words' => 3]);
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('select')->never();
        DB::shouldReceive('scalar')->never();
        $metrics = mock(BackupMetricPublisher::class);
        $metrics->shouldReceive('publish')->once()->with('BackupVerified', 1);

        $prefix = (new BackupService(successfulTurso(['example' => 2, 'words' => 3]), $metrics))->run();

        $manifest = json_decode(Storage::disk('backups')->get("$prefix/completion.json"), true, 512, JSON_THROW_ON_ERROR);
        expect(Storage::disk('backups')->get("$prefix/objects/audio/one.mp3"))->toBe('first')
            ->and(Storage::disk('backups')->get("$prefix/objects/favicon.ico"))->toBe('second')
            ->and($manifest['objects'])->toHaveCount(2)
            ->and($manifest['database']['tables'])->toBe(['example' => 2, 'words' => 3])
            ->and(Storage::disk('backups')->directoryExists("$prefix/restore-check"))->toBeFalse();
        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/v2/pipeline')) {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer short-lived-read-only-token')
                && $request->data()['requests'][1] === ['type' => 'close'];
        });
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.turso.tech/v1/organizations/possiblewords/databases/production'
            && $request->hasHeader('Authorization', 'Bearer platform-token'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.turso.tech/v1/organizations/possiblewords/databases/production/auth/tokens'
            && $request->data() === ['expiration' => '5m', 'authorization' => 'read-only']);
    });

    test('Given a Turso export failure, it does not publish a completion marker', function () {
        fakeProductionTableCounts();
        $turso = mock(TursoCli::class);
        $turso->shouldReceive('dump')->once()->andThrow(new RuntimeException('export failed'));

        expect(fn () => backupServiceWith($turso)->run())->toThrow(RuntimeException::class, 'export failed');
        expect(Storage::disk('backups')->allFiles('runs'))->toBe([]);
    });

    test('Given an empty Turso export, it fails without publishing a completion marker', function () {
        fakeProductionTableCounts();
        $turso = mock(TursoCli::class);
        $turso->shouldReceive('dump')->once()->with('production')->andReturn("  \n");

        expect(fn () => backupServiceWith($turso)->run())
            ->toThrow(RuntimeException::class, 'Turso export produced an empty dump.');
        expect(Storage::disk('backups')->allFiles('runs'))->toBe([]);
    });

    test('Given a required backup setting is missing, it fails before creating backup artifacts', function () {
        config()->set('backups.production_database', '');

        expect(fn () => backupServiceWith(mock(TursoCli::class))->run())
            ->toThrow(RuntimeException::class, 'Backup configuration [production_database] is required.');
        expect(Storage::disk('backups')->allFiles('runs'))->toBe([]);
    });

    test('Given temporary database verification fails, it deletes the temporary database and does not complete the run', function () {
        fakeProductionTableCounts();
        $turso = mock(TursoCli::class);
        $turso->shouldReceive('dump')->once()->andReturn('CREATE TABLE example (id INTEGER);');
        $turso->shouldReceive('createFromDump')->once();
        $turso->shouldReceive('query')->once()->withArgs(fn ($database, $sql) => str_starts_with($sql, 'SELECT 1 FROM'))->andReturn('1');
        $turso->shouldReceive('query')->once()->withArgs(fn ($database, $sql) => $sql === 'PRAGMA integrity_check;')->andThrow(new RuntimeException('integrity failed'));
        $turso->shouldReceive('delete')->once();

        expect(fn () => backupServiceWith($turso)->run())->toThrow(RuntimeException::class, 'integrity failed');
        expect(collect(Storage::disk('backups')->allFiles('runs'))->contains(fn ($path) => str_ends_with($path, '/completion.json')))->toBeFalse();
    });

    test('Given temporary database cleanup fails, it fails without publishing a completion marker', function () {
        fakeProductionTableCounts();
        $turso = mock(TursoCli::class);
        $turso->shouldReceive('dump')->once()->andReturn('CREATE TABLE example (id INTEGER);');
        $turso->shouldReceive('createFromDump')->once();
        $turso->shouldReceive('query')->once()->withArgs(fn ($database, $sql) => str_starts_with($sql, 'SELECT 1 FROM'))->andReturn('1');
        $turso->shouldReceive('query')->withArgs(fn ($database, $sql) => $sql === 'PRAGMA integrity_check;')->andReturn('ok');
        $turso->shouldReceive('query')->withArgs(fn ($database, $sql) => str_starts_with($sql, 'SELECT COUNT(*)'))->andReturn('0');
        $turso->shouldReceive('delete')->once()->andThrow(new RuntimeException('delete failed'));
        $metrics = mock(BackupMetricPublisher::class);
        $metrics->shouldNotReceive('publish');

        expect(fn () => (new BackupService($turso, $metrics))->run())
            ->toThrow(RuntimeException::class, 'Unable to delete temporary Turso database');
        expect(collect(Storage::disk('backups')->allFiles('runs'))->contains(fn ($path) => str_ends_with($path, '/completion.json')))->toBeFalse();
    });

    test('Given a temporary database that is not immediately ready, it retries the readiness probe until it succeeds', function () {
        fakeProductionTableCounts(['example' => 1]);
        $turso = mock(TursoCli::class);
        $turso->shouldReceive('dump')->once()->andReturn('CREATE TABLE example (id INTEGER);');
        $turso->shouldReceive('createFromDump')->once();
        $attempts = 0;
        $turso->shouldReceive('query')->withArgs(fn ($database, $sql) => str_starts_with($sql, 'SELECT 1 FROM'))
            ->andReturnUsing(function () use (&$attempts): string {
                $attempts++;
                if ($attempts < 3) {
                    throw new RuntimeException('database is not ready');
                }

                return '1';
            });
        $turso->shouldReceive('query')->withArgs(fn ($database, $sql) => $sql === 'PRAGMA integrity_check;')->andReturn('ok');
        $turso->shouldReceive('query')->withArgs(fn ($database, $sql) => str_starts_with($sql, 'SELECT COUNT(*)'))->andReturn('1');
        $turso->shouldReceive('delete')->once();
        $metrics = mock(BackupMetricPublisher::class);
        $metrics->shouldReceive('publish')->once()->with('BackupVerified', 1);

        $prefix = (new BackupService($turso, $metrics, 5.0, 0))->run();

        expect($attempts)->toBe(3)
            ->and(Storage::disk('backups')->exists("$prefix/completion.json"))->toBeTrue();
    });

    test('Given a temporary database that never becomes ready, it throws once the deadline passes', function () {
        fakeProductionTableCounts(['example' => 1]);
        $turso = mock(TursoCli::class);
        $turso->shouldReceive('dump')->once()->andReturn('CREATE TABLE example (id INTEGER);');
        $turso->shouldReceive('createFromDump')->once();
        $turso->shouldReceive('query')->withArgs(fn ($database, $sql) => str_starts_with($sql, 'SELECT 1 FROM'))->andThrow(new RuntimeException('database is not ready'));
        $turso->shouldNotReceive('query')->withArgs(fn ($database, $sql) => $sql === 'PRAGMA integrity_check;');
        $turso->shouldReceive('delete')->once();
        $metrics = mock(BackupMetricPublisher::class);
        $metrics->shouldNotReceive('publish');

        expect(fn () => (new BackupService($turso, $metrics, 0.0, 0))->run())
            ->toThrow(RuntimeException::class, 'Restored database did not become ready before verification.');
        expect(collect(Storage::disk('backups')->allFiles('runs'))->contains(fn ($path) => str_ends_with($path, '/completion.json')))->toBeFalse();
    });

    test('Given the Platform API rejects the hostname lookup, it fails before using the CLI or creating backup artifacts', function () {
        Http::fake(['api.turso.tech/*' => Http::response(['error' => 'denied'], 403)]);
        $turso = mock(TursoCli::class);
        $turso->shouldNotReceive('dump');

        expect(fn () => backupServiceWith($turso)->run())->toThrow(RuntimeException::class, 'Turso Platform API request failed.');
        expect(Storage::disk('backups')->allFiles('runs'))->toBe([]);
    });

    test('Given a database pipeline error response, it fails before using the CLI and still requests connection closure', function () {
        Http::fake([
            'api.turso.tech/v1/organizations/possiblewords/databases/production' => Http::response(['database' => ['Hostname' => 'production-possiblewords.turso.io']]),
            'api.turso.tech/v1/organizations/possiblewords/databases/production/auth/tokens*' => Http::response(['jwt' => 'short-lived-read-only-token']),
            'production-possiblewords.turso.io/v2/pipeline' => pipelineResponse([], 'error'),
        ]);
        $turso = mock(TursoCli::class);
        $turso->shouldNotReceive('dump');

        expect(fn () => backupServiceWith($turso)->run())->toThrow(RuntimeException::class, 'invalid pipeline response');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v2/pipeline')
            && $request->data()['requests'][1] === ['type' => 'close']);
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
