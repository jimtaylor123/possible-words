<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class SqliteTableRebuilder
{
    /**
     * Follow SQLite's create/copy/drop/rename procedure. Never rename the old
     * parent table: that rewrites incoming foreign keys to a temporary name.
     * SQL and identifiers here must come from migration code, not user input.
     */
    public function rebuild(string $table, string $createSql, string $copySql): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Table rebuild must start outside a transaction so foreign keys can be disabled.');
        }

        $foreignKeys = (int) $connection->scalar('PRAGMA foreign_keys');
        $connection->unprepared('PRAGMA foreign_keys = OFF');

        try {
            if ((int) $connection->scalar('PRAGMA foreign_keys') !== 0) {
                throw new RuntimeException('Could not disable foreign keys for table rebuild.');
            }

            $connection->transaction(function () use ($connection, $table, $createSql, $copySql): void {
                $objects = $connection->select("SELECT sql FROM sqlite_schema WHERE tbl_name = ? AND type IN ('index', 'trigger') AND sql IS NOT NULL", [$table]);
                $sequence = $connection->scalar('SELECT seq FROM sqlite_sequence WHERE name = ?', [$table]);
                $count = $connection->table($table)->count();

                $connection->unprepared($createSql);
                $connection->unprepared($copySql);
                if ($connection->table('__rebuild_'.$table)->count() !== $count) {
                    throw new RuntimeException('Table rebuild did not preserve every row.');
                }

                $connection->unprepared('DROP TABLE "'.$table.'"');
                $connection->unprepared('ALTER TABLE "__rebuild_'.$table.'" RENAME TO "'.$table.'"');
                foreach ($objects as $object) {
                    $connection->unprepared($object->sql);
                }

                // Preserve the high-water mark even when the highest IDs were deleted.
                if ($sequence !== null) {
                    $connection->table('sqlite_sequence')->where('name', $table)->update(['seq' => $sequence]);
                }

                if ($connection->select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('Foreign key check failed after table rebuild; changes rolled back.');
                }
            });
        } finally {
            $connection->unprepared('PRAGMA foreign_keys = '.$foreignKeys);
        }
    }
}
