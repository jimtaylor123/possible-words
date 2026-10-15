<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_STATUS_OPEN = 'open';

    private const LEGACY_STATUS_REVIEWED = 'reviewed';

    public function up(): void
    {
        if (! Schema::hasTable('report_cases')) {
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
        }

        // SQLite rebuilds the referenced reports table for this alteration and
        // applies report_review_actions.report_id's nullOnDelete rule. Retain
        // the legacy link explicitly so the backfill is lossless everywhere.
        $reviewActionReports = DB::table('report_review_actions')->pluck('report_id', 'id');

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('report_case_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->dropUnique('reports_reporter_id_reportable_type_reportable_id_status_unique');
            $table->unique(['report_case_id', 'reporter_id']);
            $table->index(['report_case_id', 'created_at']);
        });

        Schema::table('report_review_actions', function (Blueprint $table) {
            $table->foreignId('report_case_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['report_case_id', 'created_at', 'id']);
        });

        $openCases = [];
        DB::table('reports')->orderBy('id')->each(function (object $report) use (&$openCases): void {
            $isOpen = $report->status === self::LEGACY_STATUS_OPEN;
            $key = implode('|', [$report->reportable_type, $report->reportable_id, $report->reason]);

            if ($isOpen && isset($openCases[$key])) {
                $caseId = $openCases[$key];
            } else {
                $caseId = DB::table('report_cases')->insertGetId([
                    'reportable_type' => $report->reportable_type,
                    'reportable_id' => $report->reportable_id,
                    'reason' => $report->reason,
                    // Reviewed was a distinct legacy terminal state. Keep it
                    // distinct in case history rather than presenting it as a
                    // dismissal in the new dashboard.
                    'status' => $isOpen
                        ? self::LEGACY_STATUS_OPEN
                        : ($report->status === self::LEGACY_STATUS_REVIEWED ? self::LEGACY_STATUS_REVIEWED : 'dismissed'),
                    'active_key' => $isOpen ? $key : null,
                    'processed_by_user_id' => $report->processed_by_user_id,
                    'processed_at' => $report->processed_at,
                    'processing_action' => $report->processing_action,
                    'processing_note' => $report->processing_note,
                    'created_at' => $report->created_at,
                    'updated_at' => $report->updated_at,
                ]);

                if ($isOpen) {
                    $openCases[$key] = $caseId;
                }
            }

            DB::table('reports')->where('id', $report->id)->update(['report_case_id' => $caseId]);
        });

        foreach ($reviewActionReports as $actionId => $reportId) {
            $caseId = DB::table('reports')->where('id', $reportId)->value('report_case_id');

            if ($caseId !== null) {
                DB::table('report_review_actions')->where('id', $actionId)->update([
                    'report_id' => $reportId,
                    'report_case_id' => $caseId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('report_review_actions', function (Blueprint $table) {
            $table->dropIndex(['report_case_id', 'created_at', 'id']);
            $table->dropConstrainedForeignId('report_case_id');
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['report_case_id', 'created_at']);
            $table->dropUnique(['report_case_id', 'reporter_id']);
            $table->dropConstrainedForeignId('report_case_id');
            $table->unique(['reporter_id', 'reportable_type', 'reportable_id', 'status']);
        });

        Schema::dropIfExists('report_cases');
    }
};
