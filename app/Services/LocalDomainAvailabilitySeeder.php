<?php

namespace App\Services;

use App\Models\Word;

class LocalDomainAvailabilitySeeder
{
    public function seed(): void
    {
        Word::query()
            ->orderBy('id')
            ->each(function (Word $word, int $index): void {
                $status = match ($index % 20) {
                    0, 1, 2, 3, 4, 5, 6, 7, 8 => Word::DOMAIN_AVAILABLE,
                    9, 10, 11, 12, 13, 14, 15, 16, 17 => Word::DOMAIN_TAKEN,
                    default => Word::DOMAIN_UNCHECKED,
                };

                $word->update([
                    'domain_status' => $status,
                    'domain_checked_at' => $status === Word::DOMAIN_UNCHECKED ? null : now(),
                ]);
            });
    }
}
