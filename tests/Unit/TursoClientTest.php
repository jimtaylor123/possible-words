<?php

use Illuminate\Support\Facades\Http;
use RichanFongdasen\Turso\Exceptions\TursoQueryException;
use RichanFongdasen\Turso\TursoClient;

test('a Turso SQL error retains its new baton so the transaction can roll back', function () {
    $ok = ['type' => 'ok', 'response' => ['type' => 'execute', 'result' => ['cols' => [], 'rows' => []]]];
    Http::fake([
        'http://turso.test/*' => Http::sequence()
            ->push(['baton' => 'after-begin', 'results' => [$ok, $ok]])
            ->push(['baton' => 'after-error', 'results' => [['type' => 'error', 'error' => ['code' => 'SQLITE_CONSTRAINT', 'message' => 'UNIQUE constraint failed']]]])
            ->push(['baton' => null, 'results' => [$ok]]),
    ]);

    $client = new TursoClient(['db_url' => 'http://turso.test', 'foreign_key_constraints' => true]);
    $client->query('BEGIN');
    expect(fn () => $client->query('INSERT INTO votes VALUES (1)'))->toThrow(TursoQueryException::class);
    expect($client->getBaton())->toBe('after-error');
    $client->query('ROLLBACK');

    Http::assertSent(fn ($request) => ($request['baton'] ?? null) === 'after-error'
        && $request['requests'][0]['stmt']['sql'] === 'ROLLBACK');
});
