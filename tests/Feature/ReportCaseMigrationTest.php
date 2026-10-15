<?php

use App\Models\User;
use App\Models\Word;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function createFirstPhaseReportCasesTable(?Closure $defineId = null, ?Closure $defineReportableId = null, ?Closure $defineStatus = null): void
{
    Schema::create('report_cases', function (Blueprint $table) use ($defineId, $defineReportableId, $defineStatus) {
        ($defineId ?? fn (Blueprint $table) => $table->id())($table);
        $table->string('reportable_type');
        ($defineReportableId ?? fn (Blueprint $table) => $table->unsignedBigInteger('reportable_id'))($table);
        $table->string('reason');
        ($defineStatus ?? fn (Blueprint $table) => $table->string('status')->default('open'))($table);
        $table->string('active_key')->nullable()->unique();
        $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('processed_at')->nullable();
        $table->string('processing_action')->nullable();
        $table->text('processing_note')->nullable();
        $table->timestamps();

        $table->index(['reportable_type', 'reportable_id', 'reason', 'status']);
    });
}

test('backfills populated legacy reports with their open reviewed and dismissed states intact', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    $reporter = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $word = Word::factory()->create();
    $timestamps = ['created_at' => now(), 'updated_at' => now()];

    $openReportId = DB::table('reports')->insertGetId([
        'reporter_id' => $reporter->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_unfresh',
        'status' => 'open',
        ...$timestamps,
    ]);
    $reviewedReportId = DB::table('reports')->insertGetId([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_offensive',
        'status' => 'reviewed',
        'processed_by_user_id' => $admin->id,
        'processed_at' => now(),
        'processing_action' => 'reviewed',
        ...$timestamps,
    ]);
    $dismissedReportId = DB::table('reports')->insertGetId([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_offensive',
        'status' => 'dismissed',
        'processed_by_user_id' => $admin->id,
        'processed_at' => now(),
        'processing_action' => 'dismissed',
        ...$timestamps,
    ]);
    DB::table('report_review_actions')->insert([
        'report_id' => $reviewedReportId,
        'admin_user_id' => $admin->id,
        'action' => 'reviewed',
        ...$timestamps,
    ]);

    $migration->up();

    $openCase = DB::table('reports')->where('id', $openReportId)->value('report_case_id');
    $reviewedCase = DB::table('reports')->where('id', $reviewedReportId)->value('report_case_id');
    $dismissedCase = DB::table('reports')->where('id', $dismissedReportId)->value('report_case_id');

    expect(DB::table('report_cases')->where('id', $openCase)->value('status'))->toBe('open')
        ->and(DB::table('report_cases')->where('id', $reviewedCase)->value('status'))->toBe('reviewed')
        ->and(DB::table('report_cases')->where('id', $reviewedCase)->value('active_key'))->toBeNull()
        ->and(DB::table('report_cases')->where('id', $dismissedCase)->value('status'))->toBe('dismissed')
        ->and(DB::table('report_review_actions')->where('report_id', $reviewedReportId)->value('report_case_id'))->toBe($reviewedCase);
});

test('resumes when report cases were created before the migration failed', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    $reporter = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $word = Word::factory()->create();
    $timestamps = ['created_at' => now(), 'updated_at' => now()];

    $openReportId = DB::table('reports')->insertGetId([
        'reporter_id' => $reporter->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_unfresh',
        'status' => 'open',
        ...$timestamps,
    ]);
    $reviewedReportId = DB::table('reports')->insertGetId([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_offensive',
        'status' => 'reviewed',
        'processed_by_user_id' => $admin->id,
        'processed_at' => now(),
        'processing_action' => 'reviewed',
        ...$timestamps,
    ]);
    DB::table('report_review_actions')->insert([
        'report_id' => $reviewedReportId,
        'admin_user_id' => $admin->id,
        'action' => 'reviewed',
        ...$timestamps,
    ]);

    createFirstPhaseReportCasesTable();

    $migration->up();

    $openCase = DB::table('reports')->where('id', $openReportId)->value('report_case_id');
    $reviewedCase = DB::table('reports')->where('id', $reviewedReportId)->value('report_case_id');

    expect(Schema::hasColumn('reports', 'report_case_id'))->toBeTrue()
        ->and(Schema::hasColumn('report_review_actions', 'report_case_id'))->toBeTrue()
        ->and(Schema::hasIndex('reports', ['report_case_id', 'reporter_id'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('reports', ['report_case_id', 'created_at']))->toBeTrue()
        ->and(Schema::hasIndex('report_review_actions', ['report_case_id', 'created_at', 'id']))->toBeTrue()
        ->and(DB::table('report_cases')->where('id', $openCase)->value('status'))->toBe('open')
        ->and(DB::table('report_cases')->where('id', $reviewedCase)->value('status'))->toBe('reviewed')
        ->and(DB::table('report_review_actions')->where('report_id', $reviewedReportId)->value('report_case_id'))->toBe($reviewedCase);
});

test('resumes the production checkpoint without duplicating its compatible active case', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    $admin = User::factory()->create(['is_admin' => true]);
    $word = Word::factory()->create();
    $timestamps = ['created_at' => now()->subDay(), 'updated_at' => now()];
    $openReason = 'word_unfresh';
    $activeKey = implode('|', [Word::class, $word->id, $openReason]);

    createFirstPhaseReportCasesTable();
    $preservedCaseId = DB::table('report_cases')->insertGetId([
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => $openReason,
        'status' => 'open',
        'active_key' => $activeKey,
        'processing_note' => 'Preserve this first-phase row.',
        ...$timestamps,
    ]);

    $openReportId = DB::table('reports')->insertGetId([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => $openReason,
        'status' => 'open',
        ...$timestamps,
    ]);
    $secondOpenReportId = DB::table('reports')->insertGetId([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => $openReason,
        'status' => 'open',
        ...$timestamps,
    ]);
    $reviewedReportId = DB::table('reports')->insertGetId([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_offensive',
        'status' => 'reviewed',
        'processed_by_user_id' => $admin->id,
        'processed_at' => now(),
        'processing_action' => 'reviewed',
        ...$timestamps,
    ]);
    $dismissedReportId = DB::table('reports')->insertGetId([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_offensive',
        'status' => 'dismissed',
        'processed_by_user_id' => $admin->id,
        'processed_at' => now(),
        'processing_action' => 'dismissed',
        ...$timestamps,
    ]);
    DB::table('report_review_actions')->insert([
        ['report_id' => $openReportId, 'admin_user_id' => $admin->id, 'action' => 'reviewed', ...$timestamps],
        ['report_id' => $reviewedReportId, 'admin_user_id' => $admin->id, 'action' => 'reviewed', ...$timestamps],
    ]);

    Schema::table('reports', fn (Blueprint $table) => $table->foreignId('report_case_id')->nullable()->after('id'));

    $migration->up();

    $reportCaseIds = DB::table('reports')->orderBy('id')->pluck('report_case_id', 'id');
    $reportCaseForeignKeys = array_values(array_filter(
        Schema::getForeignKeys('reports'),
        fn (array $foreignKey): bool => $foreignKey['columns'] === ['report_case_id'],
    ));

    expect(DB::table('report_cases')->count())->toBe(3)
        ->and(DB::table('report_cases')->where('id', $preservedCaseId)->value('processing_note'))->toBe('Preserve this first-phase row.')
        ->and($reportCaseIds[$openReportId])->toBe($preservedCaseId)
        ->and($reportCaseIds[$secondOpenReportId])->toBe($preservedCaseId)
        ->and($reportCaseIds[$reviewedReportId])->not->toBe($preservedCaseId)
        ->and($reportCaseIds[$dismissedReportId])->not->toBe($preservedCaseId)
        ->and($reportCaseIds[$reviewedReportId])->not->toBe($reportCaseIds[$dismissedReportId])
        ->and(DB::table('report_review_actions')->whereNotNull('report_case_id')->count())->toBe(2)
        ->and(DB::table('report_review_actions')->where('report_id', $openReportId)->value('report_case_id'))->toBe($preservedCaseId)
        ->and(DB::table('report_review_actions')->where('report_id', $reviewedReportId)->value('report_case_id'))->toBe($reportCaseIds[$reviewedReportId])
        ->and(Schema::hasIndex('reports', ['reporter_id', 'reportable_type', 'reportable_id', 'status'], 'unique'))->toBeFalse()
        ->and(Schema::hasIndex('reports', ['report_case_id', 'reporter_id'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('reports', ['report_case_id', 'created_at']))->toBeTrue()
        ->and(count($reportCaseForeignKeys))->toBe(1)
        ->and($reportCaseForeignKeys[0]['foreign_table'])->toBe('report_cases')
        ->and($reportCaseForeignKeys[0]['on_delete'])->toBe('set null');
});

test('rejects an existing report cases table missing the active key unique constraint', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    createFirstPhaseReportCasesTable();
    Schema::table('report_cases', fn (Blueprint $table) => $table->dropUnique(['active_key']));

    expect(fn () => $migration->up())
        ->toThrow(\RuntimeException::class, 'it does not have exactly the active_key unique constraint and report lookup index')
        ->and(Schema::hasColumn('reports', 'report_case_id'))->toBeFalse()
        ->and(Schema::hasColumn('report_review_actions', 'report_case_id'))->toBeFalse();
});

test('rejects an existing report cases table without the id primary key', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    createFirstPhaseReportCasesTable(fn (Blueprint $table) => $table->unsignedBigInteger('id'));

    expect(fn () => $migration->up())
        ->toThrow(\RuntimeException::class, 'it does not have an id primary key')
        ->and(Schema::hasColumn('reports', 'report_case_id'))->toBeFalse()
        ->and(Schema::hasColumn('report_review_actions', 'report_case_id'))->toBeFalse();
});

test('rejects an existing report cases table with a changed column definition', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    createFirstPhaseReportCasesTable(null, fn (Blueprint $table) => $table->string('reportable_id'));

    expect(fn () => $migration->up())
        ->toThrow(\RuntimeException::class, 'its column metadata differs from the first-phase table')
        ->and(Schema::hasColumn('reports', 'report_case_id'))->toBeFalse()
        ->and(Schema::hasColumn('report_review_actions', 'report_case_id'))->toBeFalse();
});

test('rejects downstream partial-recovery artifacts before altering the schema', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();
    Schema::table('reports', function (Blueprint $table) {
        $table->foreignId('report_case_id')->nullable();
        $table->index(['report_case_id', 'created_at']);
    });

    expect(fn () => $migration->up())
        ->toThrow(\RuntimeException::class, 'reports.report_case_id exists without the validated first-phase report_cases table')
        ->and(Schema::hasTable('report_cases'))->toBeFalse()
        ->and(Schema::hasColumn('report_review_actions', 'report_case_id'))->toBeFalse();
});

test('rejects a populated checkpoint column before mutating schema or data', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    $reporter = User::factory()->create();
    $word = Word::factory()->create();
    $timestamps = ['created_at' => now(), 'updated_at' => now()];
    $reportId = DB::table('reports')->insertGetId([
        'reporter_id' => $reporter->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_unfresh',
        'status' => 'open',
        ...$timestamps,
    ]);

    createFirstPhaseReportCasesTable();
    $caseId = DB::table('report_cases')->insertGetId([
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_unfresh',
        'status' => 'open',
        'active_key' => implode('|', [Word::class, $word->id, 'word_unfresh']),
        ...$timestamps,
    ]);
    Schema::table('reports', fn (Blueprint $table) => $table->foreignId('report_case_id')->nullable()->after('id'));
    DB::table('reports')->where('id', $reportId)->update(['report_case_id' => $caseId]);

    expect(fn () => $migration->up())
        ->toThrow(\RuntimeException::class, 'reports.report_case_id contains linked reports before the reports DDL phase completed')
        ->and(DB::table('reports')->where('id', $reportId)->value('report_case_id'))->toBe($caseId)
        ->and(DB::table('report_cases')->count())->toBe(1)
        ->and(Schema::hasColumn('report_review_actions', 'report_case_id'))->toBeFalse()
        ->and(Schema::hasIndex('reports', ['reporter_id', 'reportable_type', 'reportable_id', 'status'], 'unique'))->toBeTrue();
});

test('rejects a nullable empty checkpoint column without the first-phase report cases table', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    Schema::table('reports', fn (Blueprint $table) => $table->foreignId('report_case_id')->nullable()->after('id'));

    expect(fn () => $migration->up())
        ->toThrow(\RuntimeException::class, 'reports.report_case_id exists without the validated first-phase report_cases table')
        ->and(Schema::hasTable('report_cases'))->toBeFalse()
        ->and(Schema::hasColumn('report_review_actions', 'report_case_id'))->toBeFalse()
        ->and(Schema::hasIndex('reports', ['reporter_id', 'reportable_type', 'reportable_id', 'status'], 'unique'))->toBeTrue();
});

test('retries an exact post-DDL partial-backfill checkpoint without duplicating or losing links', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    $word = Word::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $timestamps = ['created_at' => now(), 'updated_at' => now()];
    $firstReportId = DB::table('reports')->insertGetId([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_unfresh',
        'status' => 'open',
        ...$timestamps,
    ]);
    $secondReportId = DB::table('reports')->insertGetId([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_unfresh',
        'status' => 'open',
        ...$timestamps,
    ]);
    $actionId = DB::table('report_review_actions')->insertGetId([
        'report_id' => $secondReportId,
        'admin_user_id' => $admin->id,
        'action' => 'reviewed',
        ...$timestamps,
    ]);

    $migration->up();
    $caseId = DB::table('reports')->where('id', $firstReportId)->value('report_case_id');

    // This is the durable checkpoint after both DDL operations when a later
    // backfill fails: completed links remain valid and null links are pending.
    DB::table('reports')->where('id', $secondReportId)->update(['report_case_id' => null]);
    DB::table('report_review_actions')->where('id', $actionId)->update(['report_case_id' => null]);

    $migration->up();

    expect(DB::table('report_cases')->count())->toBe(1)
        ->and(DB::table('reports')->where('id', $firstReportId)->value('report_case_id'))->toBe($caseId)
        ->and(DB::table('reports')->where('id', $secondReportId)->value('report_case_id'))->toBe($caseId)
        ->and(DB::table('report_review_actions')->where('id', $actionId)->value('report_case_id'))->toBe($caseId);
});

test('rejects an incompatible existing active key before mutating schema or data', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    $reporter = User::factory()->create();
    $word = Word::factory()->create();
    $timestamps = ['created_at' => now(), 'updated_at' => now()];
    DB::table('reports')->insert([
        'reporter_id' => $reporter->id,
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_unfresh',
        'status' => 'open',
        ...$timestamps,
    ]);

    createFirstPhaseReportCasesTable();
    DB::table('report_cases')->insert([
        'reportable_type' => Word::class,
        'reportable_id' => $word->id,
        'reason' => 'word_unfresh',
        'status' => 'reviewed',
        'active_key' => implode('|', [Word::class, $word->id, 'word_unfresh']),
        ...$timestamps,
    ]);

    expect(fn () => $migration->up())
        ->toThrow(\RuntimeException::class, 'an existing active case is incompatible with a legacy report')
        ->and(Schema::hasColumn('reports', 'report_case_id'))->toBeFalse()
        ->and(Schema::hasColumn('report_review_actions', 'report_case_id'))->toBeFalse()
        ->and(DB::table('reports')->count())->toBe(1);
});

test('resumes when the reports foreign key was persisted before its other constraints', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    createFirstPhaseReportCasesTable();
    Schema::table('reports', function (Blueprint $table) {
        $table->foreignId('report_case_id')->nullable()->constrained()->nullOnDelete();
    });

    $migration->up();

    expect(Schema::hasIndex('reports', ['report_case_id', 'reporter_id'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('reports', ['report_case_id', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('report_review_actions', 'report_case_id'))->toBeTrue();
});

test('resumes when the report review action foreign key was persisted before its index', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    createFirstPhaseReportCasesTable();
    Schema::table('report_review_actions', function (Blueprint $table) {
        $table->foreignId('report_case_id')->nullable()->constrained()->nullOnDelete();
    });

    $migration->up();

    expect(Schema::hasColumn('reports', 'report_case_id'))->toBeTrue()
        ->and(Schema::hasIndex('report_review_actions', ['report_case_id', 'created_at', 'id']))->toBeTrue();
});

test('rejects an existing report cases table with a changed status default', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    createFirstPhaseReportCasesTable(null, null, fn (Blueprint $table) => $table->string('status')->default('dismissed'));

    expect(fn () => $migration->up())
        ->toThrow(\RuntimeException::class, 'its column metadata differs from the first-phase table')
        ->and(Schema::hasColumn('reports', 'report_case_id'))->toBeFalse()
        ->and(Schema::hasColumn('report_review_actions', 'report_case_id'))->toBeFalse();
});
