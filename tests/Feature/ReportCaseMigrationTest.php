<?php

use App\Models\User;
use App\Models\Word;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

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

    Schema::create('report_cases', function (Blueprint $table) {
        $table->id();
        $table->string('reportable_type');
        $table->unsignedBigInteger('reportable_id');
        $table->string('reason');
        $table->string('status')->default('open');
        $table->string('active_key')->nullable()->unique();
        $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('processed_at')->nullable();
        $table->string('processing_action')->nullable();
        $table->text('processing_note')->nullable();
        $table->timestamps();

        $table->index(['reportable_type', 'reportable_id', 'reason', 'status']);
    });

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

test('rejects an existing report cases table missing the active key unique constraint', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_report_cases_table.php');
    $migration->down();

    Schema::create('report_cases', function (Blueprint $table) {
        $table->id();
        $table->string('reportable_type');
        $table->unsignedBigInteger('reportable_id');
        $table->string('reason');
        $table->string('status')->default('open');
        $table->string('active_key')->nullable();
        $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('processed_at')->nullable();
        $table->string('processing_action')->nullable();
        $table->text('processing_note')->nullable();
        $table->timestamps();

        $table->index(['reportable_type', 'reportable_id', 'reason', 'status']);
    });

    expect(fn () => $migration->up())
        ->toThrow(\RuntimeException::class, 'it does not have exactly the active_key unique constraint and report lookup index')
        ->and(Schema::hasColumn('reports', 'report_case_id'))->toBeFalse()
        ->and(Schema::hasColumn('report_review_actions', 'report_case_id'))->toBeFalse();
});
