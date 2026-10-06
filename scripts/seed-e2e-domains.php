<?php

/**
 * Mark two deterministic fixture words as having a free .com so the E2E
 * specs can assert on a filtered list.
 *
 * The committed dev-data.sqlite snapshot predates this feature (every word
 * lands on "unchecked" once the migration has run), and Playwright specs only
 * speak HTTP, so this script seeds the verdicts after migrate. It runs
 * against the throwaway E2E database (database/testing.sqlite) because
 * APP_ENV=testing makes the bootstrap read .env.testing.
 *
 * Usage: APP_ENV=testing php scripts/seed-e2e-domains.php
 */

use App\Models\Word;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expected = ['bloarp', 'bloblarp'];

$updated = Word::query()
    ->whereIn('text', $expected)
    ->update([
        'domain_status' => Word::DOMAIN_AVAILABLE,
        'domain_checked_at' => now(),
    ]);

if ($updated !== count($expected)) {
    fwrite(STDERR, "Expected to mark ".count($expected).' fixture words as available, marked '.$updated.".\n");
    fwrite(STDERR, 'Check that the fixture words still exist in .snapshots/dev-data.sqlite.'."\n");
    exit(1);
}

echo "Marked {$updated} fixture words (".implode(', ', $expected).") as having a free .com.\n";
