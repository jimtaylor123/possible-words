<?php

namespace App\Backups;

interface TursoCli
{
    public function dump(string $database): string;

    /**
     * Rebuild the dump locally and return validation derived from that snapshot
     * before uploading it with Turso's --from-file restore path.
     */
    public function createFromDump(string $database, string $dumpPath): DatabaseValidation;

    public function query(string $database, string $sql): string;

    public function delete(string $database): void;
}
