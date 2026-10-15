<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_STATUS_OPEN = 'open';

    private const LEGACY_STATUS_REVIEWED = 'reviewed';

    private const REPORT_CASE_COLUMNS = [
        'id',
        'reportable_type',
        'reportable_id',
        'reason',
        'status',
        'active_key',
        'processed_by_user_id',
        'processed_at',
        'processing_action',
        'processing_note',
        'created_at',
        'updated_at',
    ];

    private const REPORT_CASE_COLUMN_DEFINITIONS = [
        ['name' => 'id', 'type_name' => 'integer', 'type' => 'integer', 'collation' => null, 'nullable' => false, 'default' => null, 'auto_increment' => true, 'comment' => null, 'generation' => null],
        ['name' => 'reportable_type', 'type_name' => 'varchar', 'type' => 'varchar', 'collation' => null, 'nullable' => false, 'default' => null, 'auto_increment' => false, 'comment' => null, 'generation' => null],
        ['name' => 'reportable_id', 'type_name' => 'integer', 'type' => 'integer', 'collation' => null, 'nullable' => false, 'default' => null, 'auto_increment' => false, 'comment' => null, 'generation' => null],
        ['name' => 'reason', 'type_name' => 'varchar', 'type' => 'varchar', 'collation' => null, 'nullable' => false, 'default' => null, 'auto_increment' => false, 'comment' => null, 'generation' => null],
        ['name' => 'status', 'type_name' => 'varchar', 'type' => 'varchar', 'collation' => null, 'nullable' => false, 'default' => "'open'", 'auto_increment' => false, 'comment' => null, 'generation' => null],
        ['name' => 'active_key', 'type_name' => 'varchar', 'type' => 'varchar', 'collation' => null, 'nullable' => true, 'default' => null, 'auto_increment' => false, 'comment' => null, 'generation' => null],
        ['name' => 'processed_by_user_id', 'type_name' => 'integer', 'type' => 'integer', 'collation' => null, 'nullable' => true, 'default' => null, 'auto_increment' => false, 'comment' => null, 'generation' => null],
        ['name' => 'processed_at', 'type_name' => 'datetime', 'type' => 'datetime', 'collation' => null, 'nullable' => true, 'default' => null, 'auto_increment' => false, 'comment' => null, 'generation' => null],
        ['name' => 'processing_action', 'type_name' => 'varchar', 'type' => 'varchar', 'collation' => null, 'nullable' => true, 'default' => null, 'auto_increment' => false, 'comment' => null, 'generation' => null],
        ['name' => 'processing_note', 'type_name' => 'text', 'type' => 'text', 'collation' => null, 'nullable' => true, 'default' => null, 'auto_increment' => false, 'comment' => null, 'generation' => null],
        ['name' => 'created_at', 'type_name' => 'datetime', 'type' => 'datetime', 'collation' => null, 'nullable' => true, 'default' => null, 'auto_increment' => false, 'comment' => null, 'generation' => null],
        ['name' => 'updated_at', 'type_name' => 'datetime', 'type' => 'datetime', 'collation' => null, 'nullable' => true, 'default' => null, 'auto_increment' => false, 'comment' => null, 'generation' => null],
    ];

    public function up(): void
    {
        $reportCasesTableAlreadyExists = Schema::hasTable('report_cases');

        if ($reportCasesTableAlreadyExists) {
            $this->assertExistingReportCasesTableMatchesFirstPhase();
        }

        $reportsHaveCheckpointColumn = $this->assertDownstreamTablesAreInLegacyState();

        if ($reportCasesTableAlreadyExists) {
            $this->assertExistingActiveCasesAreCompatibleWithLegacyReports();
        }

        if (! $reportCasesTableAlreadyExists) {
            // Deliberately not re-checking here: if another process created the
            // table since the check above, Schema::create fails loudly instead
            // of silently skipping creation with an unvalidated table.
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

        if ($reportsHaveCheckpointColumn) {
            Schema::table('reports', function (Blueprint $table) {
                $table->foreign('report_case_id')->references('id')->on('report_cases')->nullOnDelete();
                $table->dropUnique('reports_reporter_id_reportable_type_reportable_id_status_unique');
                $table->unique(['report_case_id', 'reporter_id']);
                $table->index(['report_case_id', 'created_at']);
            });
        } else {
            Schema::table('reports', function (Blueprint $table) {
                $table->foreignId('report_case_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->dropUnique('reports_reporter_id_reportable_type_reportable_id_status_unique');
                $table->unique(['report_case_id', 'reporter_id']);
                $table->index(['report_case_id', 'created_at']);
            });
        }

        Schema::table('report_review_actions', function (Blueprint $table) {
            $table->foreignId('report_case_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['report_case_id', 'created_at', 'id']);
        });

        $openCases = DB::table('report_cases')
            ->where('status', self::LEGACY_STATUS_OPEN)
            ->whereNotNull('active_key')
            ->pluck('id', 'active_key')
            ->all();
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

    private function assertExistingReportCasesTableMatchesFirstPhase(): void
    {
        $problems = [];

        // Compare by column name rather than the order the driver happens to
        // return, so column ordering alone cannot reject an otherwise-correct
        // first-phase table.
        $columns = Schema::getColumns('report_cases');
        usort($columns, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        $columnNames = array_column($columns, 'name');
        $expectedColumnNames = self::REPORT_CASE_COLUMNS;
        sort($expectedColumnNames);

        if ($columnNames !== $expectedColumnNames) {
            $problems[] = 'its columns differ from the first-phase table';
        }

        $columnDefinitions = array_map(function (array $column): array {
            $definition = array_intersect_key($column, array_flip(['name', 'type_name', 'type', 'collation', 'nullable', 'default', 'auto_increment', 'comment', 'generation']));
            ksort($definition);

            return $definition;
        }, $columns);

        $expectedColumnDefinitions = self::REPORT_CASE_COLUMN_DEFINITIONS;
        foreach ($expectedColumnDefinitions as $index => $definition) {
            ksort($expectedColumnDefinitions[$index]);
        }
        usort($expectedColumnDefinitions, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        if ($columnDefinitions !== $expectedColumnDefinitions) {
            $problems[] = 'its column metadata differs from the first-phase table';
        }

        $allIndexes = Schema::getIndexes('report_cases');
        $primaryIndexes = array_values(array_filter(
            $allIndexes,
            fn (array $index): bool => $index['primary'],
        ));

        if (count($primaryIndexes) !== 1 || $primaryIndexes[0]['columns'] !== ['id']) {
            $problems[] = 'it does not have an id primary key';
        }

        $indexes = array_values(array_filter(
            $allIndexes,
            fn (array $index): bool => ! $index['primary'],
        ));

        $hasActiveKeyUnique = count(array_filter(
            $indexes,
            fn (array $index): bool => $index['unique'] && $index['columns'] === ['active_key'],
        )) === 1;
        $hasLookupIndex = count(array_filter(
            $indexes,
            fn (array $index): bool => ! $index['unique'] && $index['columns'] === ['reportable_type', 'reportable_id', 'reason', 'status'],
        )) === 1;

        if (count($indexes) !== 2 || ! $hasActiveKeyUnique || ! $hasLookupIndex) {
            $problems[] = 'it does not have exactly the active_key unique constraint and report lookup index';
        }

        $foreignKeys = Schema::getForeignKeys('report_cases');
        $hasProcessedByUserForeignKey = count(array_filter(
            $foreignKeys,
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['processed_by_user_id']
                && $foreignKey['foreign_table'] === 'users'
                && $foreignKey['foreign_columns'] === ['id']
                && strtolower((string) $foreignKey['on_delete']) === 'set null',
        )) === 1;

        if (count($foreignKeys) !== 1 || ! $hasProcessedByUserForeignKey) {
            $problems[] = 'it does not have the processed_by_user_id foreign key to users with ON DELETE SET NULL';
        }

        if ($problems !== []) {
            throw new \RuntimeException(
                'Existing report_cases table does not match the expected first-phase recovery schema: '
                .implode('; ', $problems)
                .'. Inspect and repair the table before rerunning this migration.'
            );
        }
    }

    private function assertDownstreamTablesAreInLegacyState(): bool
    {
        $artifacts = [];
        $reportsHaveCheckpointColumn = Schema::hasColumn('reports', 'report_case_id');

        foreach (['reports', 'report_review_actions'] as $table) {
            if ($table === 'report_review_actions' && Schema::hasColumn($table, 'report_case_id')) {
                $artifacts[] = "$table.report_case_id column";
            }

            foreach (Schema::getIndexes($table) as $index) {
                if (in_array('report_case_id', $index['columns'], true)) {
                    $artifacts[] = "$table index (".implode(', ', $index['columns']).')';
                }
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['foreign_table'] === 'report_cases') {
                    $artifacts[] = "$table foreign key (".implode(', ', $foreignKey['columns']).') referencing report_cases';
                }
            }
        }

        if ($reportsHaveCheckpointColumn) {
            $reportCaseColumn = collect(Schema::getColumns('reports'))->firstWhere('name', 'report_case_id');

            if ($reportCaseColumn['type_name'] !== 'integer' || ! $reportCaseColumn['nullable'] || $reportCaseColumn['default'] !== null || $reportCaseColumn['auto_increment']) {
                $artifacts[] = 'reports.report_case_id is not the expected nullable foreign-id column';
            }

            if (DB::table('reports')->whereNotNull('report_case_id')->exists()) {
                $artifacts[] = 'reports.report_case_id contains linked reports';
            }
        }

        if (! Schema::hasIndex('reports', ['reporter_id', 'reportable_type', 'reportable_id', 'status'], 'unique')) {
            $artifacts[] = 'reports legacy uniqueness constraint is missing';
        }

        if ($artifacts !== []) {
            throw new \RuntimeException(
                'Cannot resume the report case migration because downstream partial-recovery artifacts were found ('
                .implode(', ', $artifacts)
                .'). Inspect and repair the schema before rerunning this migration; no changes were made.'
            );
        }

        return $reportsHaveCheckpointColumn;
    }

    private function assertExistingActiveCasesAreCompatibleWithLegacyReports(): void
    {
        $existingCases = DB::table('report_cases')
            ->whereNotNull('active_key')
            ->get()
            ->keyBy('active_key');

        DB::table('reports')->where('status', self::LEGACY_STATUS_OPEN)->orderBy('id')->each(function (object $report) use ($existingCases): void {
            $key = implode('|', [$report->reportable_type, $report->reportable_id, $report->reason]);
            $case = $existingCases->get($key);

            if ($case !== null && (
                $case->status !== self::LEGACY_STATUS_OPEN
                || $case->reportable_type !== $report->reportable_type
                || $case->reportable_id !== $report->reportable_id
                || $case->reason !== $report->reason
            )) {
                throw new \RuntimeException(
                    'Cannot resume the report case migration because an existing active case is incompatible with a legacy report. '
                    .'Inspect and repair the data before rerunning this migration; no changes were made.'
                );
            }
        });
    }
};
