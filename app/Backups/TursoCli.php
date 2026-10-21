<?php

namespace App\Backups;

interface TursoCli
{
    public function dump(string $database): string;

    /** @return array<string, int> */
    public function createFromDump(string $database, string $dumpPath): array;

    public function query(string $database, string $sql): string;

    public function delete(string $database): void;
}
