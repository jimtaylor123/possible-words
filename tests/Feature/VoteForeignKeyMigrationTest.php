<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Separate connection: RefreshDatabase's surrounding transaction would make
    // PRAGMA foreign_keys = OFF a no-op and hide production migration behavior.
    $this->originalConnection = DB::getDefaultConnection();
    DB::extend('migration-turso', fn () => new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'turso']));
    $url = getenv('TURSO_MIGRATION_TEST_URL');
    if ($url !== false && parse_url($url, PHP_URL_HOST) !== '127.0.0.1') {
        throw new RuntimeException('Migration integration tests require an isolated loopback Turso server.');
    }
    config(['database.connections.vote-migration' => $url === false
        ? ['driver' => 'migration-turso']
        : ['driver' => 'turso', 'db_url' => $url, 'access_token' => '', 'foreign_key_constraints' => true, 'prefix' => ''],
    ]);
    DB::setDefaultConnection('vote-migration');
    DB::unprepared('PRAGMA foreign_keys = OFF');
    foreach (['votes', 'definitions', 'words', 'users'] as $table) {
        DB::unprepared('DROP TABLE IF EXISTS "'.$table.'"');
    }
    DB::unprepared('PRAGMA foreign_keys = ON');
    DB::unprepared('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');
    DB::unprepared('CREATE TABLE words (id INTEGER PRIMARY KEY AUTOINCREMENT)');
    DB::unprepared('CREATE TABLE definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, word_id INTEGER NOT NULL REFERENCES words(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, text TEXT NOT NULL, votes_count INTEGER NOT NULL DEFAULT 0, created_at DATETIME, updated_at DATETIME, removed_at DATETIME, part_of_speech VARCHAR, origin VARCHAR, example_sentence TEXT)');
    DB::unprepared('CREATE INDEX definitions_word_id_votes_count_index ON definitions(word_id, votes_count)');
    DB::unprepared('CREATE TABLE votes (id INTEGER PRIMARY KEY AUTOINCREMENT, definition_id INTEGER NOT NULL REFERENCES definitions(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, created_at DATETIME, updated_at DATETIME)');
    DB::unprepared('CREATE UNIQUE INDEX votes_definition_id_user_id_unique ON votes(definition_id, user_id)');
    DB::unprepared('CREATE INDEX votes_definition_id_index ON votes(definition_id)');
    DB::table('users')->insert([['id' => 1], ['id' => 2]]);
    DB::table('words')->insert(['id' => 3]);
    DB::table('definitions')->insert(['id' => 7, 'word_id' => 3, 'user_id' => 1, 'text' => 'Retained meaning', 'votes_count' => 1]);
    DB::table('votes')->insert(['id' => 11, 'definition_id' => 7, 'user_id' => 2, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);
    DB::table('votes')->insert(['id' => 101, 'definition_id' => 7, 'user_id' => 1]);
    DB::table('votes')->where('id', 101)->delete();
});

afterEach(function () {
    DB::setDefaultConnection($this->originalConnection);
    DB::purge('vote-migration');
});

function breakVoteDefinitionReference(): void
{
    // Reproduce the previously shipped migration, including SQLite's automatic
    // rewrite of votes' incoming foreign key when its parent is renamed.
    $sql = DB::scalar("SELECT sql FROM sqlite_schema WHERE name = 'definitions'");
    DB::unprepared('PRAGMA foreign_keys = OFF');
    DB::unprepared('ALTER TABLE definitions RENAME TO __restore_definitions');
    DB::unprepared($sql);
    DB::unprepared('INSERT INTO definitions SELECT * FROM __restore_definitions');
    DB::unprepared('DROP TABLE __restore_definitions');
    DB::unprepared('PRAGMA foreign_keys = ON');
}

function repairVoteDefinitionReference(): void
{
    (require database_path('migrations/2026_11_05_000001_repair_votes_definition_foreign_key.php'))->up();
}

test('the Turso definitions migration preserves incoming votes and allows author deletion', function () {
    $before = DB::table('votes')->get()->toArray();
    (require database_path('migrations/2026_10_14_000001_preserve_definitions_when_users_are_deleted.php'))->up();

    expect(DB::table('votes')->get()->toArray())->toEqual($before)
        ->and(DB::scalar('PRAGMA foreign_keys'))->toBe(1)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([])
        ->and(DB::scalar('SELECT text FROM definitions WHERE id = 7'))->toBe('Retained meaning');

    DB::table('users')->where('id', 1)->delete();
    expect(DB::table('definitions')->where('id', 7)->value('user_id'))->toBeNull()
        ->and(DB::table('definitions')->where('id', 7)->value('votes_count'))->toBe(1)
        ->and(DB::table('votes')->count())->toBe(1);
    expect(fn () => DB::table('votes')->insert(['definition_id' => 7, 'user_id' => 2]))
        ->toThrow(QueryException::class, 'UNIQUE constraint failed');
});

test('repairs the production failure while preserving rows indexes timestamps and sequence on retry', function () {
    breakVoteDefinitionReference();
    $before = DB::table('votes')->get()->toArray();
    $definition = DB::table('definitions')->first();
    expect(fn () => DB::table('votes')->insert(['definition_id' => 7, 'user_id' => 1]))
        ->toThrow(QueryException::class, 'no such table: main.__restore_definitions');

    repairVoteDefinitionReference();
    $schema = DB::select("SELECT name, sql FROM sqlite_schema WHERE tbl_name = 'votes' ORDER BY name");
    repairVoteDefinitionReference();
    expect(DB::table('votes')->get()->toArray())->toEqual($before)
        ->and(DB::table('definitions')->first())->toEqual($definition)
        ->and(DB::select("SELECT name, sql FROM sqlite_schema WHERE tbl_name = 'votes' ORDER BY name"))->toEqual($schema)
        ->and(DB::scalar('PRAGMA foreign_keys'))->toBe(1)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);

    $id = DB::table('votes')->insertGetId(['definition_id' => 7, 'user_id' => 1]);
    expect($id)->toBe(102);
    expect(fn () => DB::table('votes')->insert(['definition_id' => 7, 'user_id' => 1]))->toThrow(QueryException::class, 'UNIQUE constraint failed');
    DB::table('votes')->where('id', $id)->delete();
    expect(DB::table('votes')->count())->toBe(1);
    DB::table('users')->where('id', 2)->delete();
    expect(DB::table('votes')->count())->toBe(0);
    DB::table('votes')->insert(['definition_id' => 7, 'user_id' => 1]);
    DB::table('definitions')->where('id', 7)->delete();
    expect(DB::table('votes')->count())->toBe(0);
});

test('leaves healthy schemas and their data untouched', function () {
    $before = DB::select('SELECT * FROM sqlite_schema ORDER BY name');
    repairVoteDefinitionReference();
    expect(DB::select('SELECT * FROM sqlite_schema ORDER BY name'))->toEqual($before)
        ->and(DB::table('votes')->count())->toBe(1);
});

test('rolls back the entire repair and restores foreign key enforcement when data is orphaned', function () {
    breakVoteDefinitionReference();
    DB::unprepared('PRAGMA foreign_keys = OFF');
    DB::table('votes')->insert(['definition_id' => 999, 'user_id' => 1]);
    DB::unprepared('PRAGMA foreign_keys = ON');
    $before = DB::select('SELECT * FROM sqlite_schema ORDER BY name');
    $rows = DB::table('votes')->get()->toArray();

    expect(fn () => repairVoteDefinitionReference())->toThrow(RuntimeException::class, 'Foreign key check failed');
    expect(DB::select('SELECT * FROM sqlite_schema ORDER BY name'))->toEqual($before)
        ->and(DB::table('votes')->get()->toArray())->toEqual($rows)
        ->and(DB::scalar('PRAGMA foreign_keys'))->toBe(1)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

test('restores an originally disabled foreign key setting', function () {
    breakVoteDefinitionReference();
    DB::unprepared('PRAGMA foreign_keys = OFF');
    repairVoteDefinitionReference();
    expect(DB::scalar('PRAGMA foreign_keys'))->toBe(0);
});

test('refuses to rebuild inside a transaction before changing schema or PRAGMAs', function () {
    breakVoteDefinitionReference();
    DB::beginTransaction();
    try {
        expect(fn () => repairVoteDefinitionReference())->toThrow(RuntimeException::class, 'outside a transaction');
        expect(DB::scalar('PRAGMA foreign_keys'))->toBe(1);
    } finally {
        DB::rollBack();
    }
});

test('rolls back copy SQL errors without leaving a replacement table or a consumed Turso baton', function () {
    DB::table('votes')->insert(['definition_id' => 7, 'user_id' => 1]);
    $rows = DB::table('votes')->get()->toArray();
    $before = DB::select('SELECT * FROM sqlite_schema ORDER BY name');

    expect(fn () => (new App\Support\SqliteTableRebuilder)->rebuild(
        'votes',
        'CREATE TABLE "__rebuild_votes" (id INTEGER PRIMARY KEY AUTOINCREMENT, definition_id INTEGER UNIQUE, user_id INTEGER, created_at DATETIME, updated_at DATETIME)',
        'INSERT INTO "__rebuild_votes" SELECT * FROM "votes"',
    ))->toThrow(QueryException::class, 'UNIQUE constraint failed');

    expect(DB::select('SELECT * FROM sqlite_schema ORDER BY name'))->toEqual($before)
        ->and(DB::table('votes')->get()->toArray())->toEqual($rows)
        ->and(DB::scalar('PRAGMA foreign_keys'))->toBe(1)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});
