<?php

namespace App\Backups;

class DatabaseValidation
{
    /** @param array<string, int> $tableCounts */
    public function __construct(
        public readonly array $tableCounts,
        public readonly string $localIntegrityCheck,
    ) {}
}
