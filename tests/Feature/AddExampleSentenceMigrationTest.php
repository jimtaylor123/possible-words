<?php

use Illuminate\Support\Facades\Schema;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('the example-sentence migration is reversible', function () {
    $migration = require database_path('migrations/2026_10_09_000001_add_example_sentence_to_definitions_table.php');

    $migration->down();

    expect(Schema::hasColumn('definitions', 'example_sentence'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('definitions', 'example_sentence'))->toBeTrue();
});
