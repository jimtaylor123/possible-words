<?php

/**
 * Mark two deterministic fixture words as having a free .com so the E2E
 * specs can assert on a filtered list, with every other word unchecked.
 *
 * The committed dev-data.sqlite snapshot predates this feature (every word
 * lands on "unchecked" once the migration has run), and Playwright specs only
 * speak HTTP, so this script seeds the verdicts after migrate. It runs
 * against the throwaway E2E database (database/testing.sqlite) because
 * APP_ENV=testing makes the bootstrap read .env.testing.
 *
 * Usage: APP_ENV=testing php scripts/seed-e2e-domains.php
 */

use App\Models\Definition;
use App\Models\Favourite;
use App\Models\User;
use App\Models\Word;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expected = ['bloarp', 'bloblarp'];

Word::query()->update([
    'domain_status' => Word::DOMAIN_UNCHECKED,
    'domain_checked_at' => null,
]);

$updated = Word::query()
    ->whereIn('text', $expected)
    ->update([
        'domain_status' => Word::DOMAIN_AVAILABLE,
        'domain_checked_at' => now(),
    ]);

if ($updated !== count($expected)) {
    fwrite(STDERR, 'Expected to mark '.count($expected).' fixture words as available, marked '.$updated.".\n");
    fwrite(STDERR, 'Check that the fixture words still exist in .snapshots/dev-data.sqlite.'."\n");
    exit(1);
}

echo "Marked {$updated} fixture words (".implode(', ', $expected).") as having a free .com.\n";

$author = User::firstOrCreate(
    ['email' => 'metadata-fixture@example.com'],
    [
        'name' => 'Metadata Fixture Author with a deliberately long display name',
        'provider' => 'testing',
        'provider_id' => 'metadata-fixture',
        'password' => null,
    ],
);

$populariser = User::firstOrCreate(
    ['email' => 'metadata-fixture-popular@example.com'],
    [
        'name' => 'Metadata Fixture Populariser',
        'provider' => 'testing',
        'provider_id' => 'metadata-fixture-popular',
        'password' => null,
    ],
);

$word = Word::firstOrCreate(
    ['slug' => 'metadataview'],
    [
        'text' => 'metadataview',
        'syllables' => 4,
        'status' => 'available',
        'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
        'published_at' => now(),
        'ai_definition_status' => Word::AI_DEFINITION_GENERATED,
    ],
);

Definition::updateOrCreate(
    ['word_id' => $word->id, 'origin' => Definition::ORIGIN_AI],
    [
        'user_id' => $author->id,
        'text' => 'A fully populated responsive metadata fixture.',
        'votes_count' => 47,
        'part_of_speech' => 'noun',
    ],
);

Favourite::firstOrCreate(['user_id' => $author->id, 'word_id' => $word->id]);
Favourite::firstOrCreate(['user_id' => $populariser->id, 'word_id' => $word->id]);

echo "Seeded fully populated metadata fixture.\n";
