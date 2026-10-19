<?php

namespace App\Backups;

interface TursoCli
{
    public function dump(string $database): string;

    public function createFromDump(string $database, string $dumpPath): void;

    public function query(string $database, string $sql): string;

    public function delete(string $database): void;
}
