<?php

use App\Models\Word;
use App\Services\LocalDomainAvailabilitySeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it seeds local domain statuses in a 45 45 10 distribution', function () {
    Word::factory()->count(20)->create();

    app(LocalDomainAvailabilitySeeder::class)->seed();

    expect(Word::where('domain_status', Word::DOMAIN_AVAILABLE)->count())->toBe(9)
        ->and(Word::where('domain_status', Word::DOMAIN_TAKEN)->count())->toBe(9)
        ->and(Word::where('domain_status', Word::DOMAIN_UNCHECKED)->count())->toBe(2)
        ->and(Word::where('domain_status', Word::DOMAIN_UNCHECKED)->whereNotNull('domain_checked_at')->count())->toBe(0);
});
