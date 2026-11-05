<?php

use App\Support\SqliteTableRebuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'turso'], true)) {
            return;
        }

        $foreignKeys = DB::select('PRAGMA foreign_key_list("votes")');
        $broken = array_filter($foreignKeys, fn ($key) => $key->from === 'definition_id' && $key->table === '__restore_definitions');
        if ($broken === []) {
            return;
        }

        // Retain the existing columns, defaults and inline constraints; change
        // only the known broken reference and the replacement table's name.
        $sql = DB::scalar("SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = 'votes'");
        $sql = preg_replace('/\bREFERENCES\s+(?:"__restore_definitions"|\'__restore_definitions\'|`__restore_definitions`|\[__restore_definitions\]|__restore_definitions\b)/i', 'REFERENCES "definitions"', $sql, -1, $references);
        $sql = preg_replace('/^CREATE\s+TABLE\s+(?:"votes"|\'votes\'|`votes`|\[votes\]|votes\b)/i', 'CREATE TABLE "__rebuild_votes"', $sql, 1, $tables);
        if ($references !== 1 || $tables !== 1) {
            throw new RuntimeException('Unexpected votes schema; refusing to rebuild it.');
        }

        $columns = array_map(fn ($column) => '"'.str_replace('"', '""', $column->name).'"', DB::select('PRAGMA table_info("votes")'));
        $columns = implode(', ', $columns);
        (new SqliteTableRebuilder)->rebuild('votes', $sql, 'INSERT INTO "__rebuild_votes" ('.$columns.') SELECT '.$columns.' FROM "votes"');
    }

    public function down(): void
    {
        // Data-preserving repair: rolling back must not restore a broken reference.
    }
};
