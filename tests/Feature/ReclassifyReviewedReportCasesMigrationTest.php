<?php

use App\Models\Report;
use App\Models\ReportCase;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('reclassifies reviewed report cases as dismissed while retaining their review history', function () {
    $word = Word::factory()->create();
    $case = $word->reportCases()->create([
        'reason' => Report::REASON_WORD_UNFRESH,
        'status' => 'reviewed',
        'processing_action' => 'reviewed',
        'processed_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_10_23_000001_reclassify_reviewed_report_cases_as_dismissed.php');
    $migration->up();

    expect($case->fresh()->status)->toBe(ReportCase::STATUS_DISMISSED)
        ->and($case->fresh()->processing_action)->toBe('reviewed');
});

test('cannot roll back the irreversible report case reclassification', function () {
    $migration = require database_path('migrations/2026_10_23_000001_reclassify_reviewed_report_cases_as_dismissed.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot restore reviewed report cases after they have been reclassified as dismissed.');
});
