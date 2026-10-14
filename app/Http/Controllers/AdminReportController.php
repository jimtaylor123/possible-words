<?php

namespace App\Http\Controllers;

use App\Models\Definition;
use App\Models\Report;
use App\Models\ReportCase;
use App\Models\Word;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminReportController extends Controller
{
    public function index(Request $request)
    {
        $reason = $this->filter($request, 'reason', [
            Report::REASON_WORD_UNFRESH,
            Report::REASON_WORD_OFFENSIVE,
            Report::REASON_DEFINITION_OFFENSIVE,
        ]);
        $status = $this->filter($request, 'status', [...ReportCase::statuses(), 'all']) ?? ReportCase::STATUS_OPEN;

        $reports = ReportCase::query()
            ->with([
                'reports.reporter',
                'processor',
                'reviewActions.admin',
                'reportable' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([Definition::class => ['word']]);
                },
            ])
            ->when($reason, fn ($query) => $query->where('reason', $reason))
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ReportCase $case) => $this->casePayload($case));

        return inertia('Admin/Dashboard', [
            'reports' => $reports,
            'filters' => compact('reason', 'status'),
            'reasonOptions' => [
                ['label' => 'All types', 'value' => null],
                ['label' => 'Unfresh word', 'value' => Report::REASON_WORD_UNFRESH],
                ['label' => 'Offensive word', 'value' => Report::REASON_WORD_OFFENSIVE],
                ['label' => 'Offensive definition', 'value' => Report::REASON_DEFINITION_OFFENSIVE],
            ],
            'statusOptions' => [
                ['label' => 'Open', 'value' => ReportCase::STATUS_OPEN],
                ['label' => 'Dismissed', 'value' => ReportCase::STATUS_DISMISSED],
                ['label' => 'Muted', 'value' => ReportCase::STATUS_MUTED],
                ['label' => 'All', 'value' => 'all'],
            ],
        ]);
    }

    public function update(Request $request, ReportCase $reportCase)
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in([
                ReportCase::ACTION_DISMISSED,
                ReportCase::ACTION_MUTED,
                ReportCase::ACTION_UNMUTED,
            ])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $reportCase, $validated): void {
            $processedAt = now();
            $expectedStatus = match ($validated['action']) {
                ReportCase::ACTION_DISMISSED, ReportCase::ACTION_MUTED => ReportCase::STATUS_OPEN,
                ReportCase::ACTION_UNMUTED => ReportCase::STATUS_MUTED,
            };
            $nextStatus = ReportCase::statusForAction($validated['action']);
            $updated = ReportCase::query()
                ->whereKey($reportCase->getKey())
                ->where('status', $expectedStatus)
                ->update([
                    'status' => $nextStatus,
                    'active_key' => $nextStatus === ReportCase::STATUS_DISMISSED ? null : $reportCase->active_key,
                    'processed_by_user_id' => $request->user()->id,
                    'processed_at' => $processedAt,
                    'processing_action' => $validated['action'],
                    'processing_note' => $validated['note'] ?? null,
                    'updated_at' => $processedAt,
                ]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'action' => 'This report has already been updated. Refresh the queue and try again.',
                ]);
            }

            $reportCase->reviewActions()->create([
                'admin_user_id' => $request->user()->id,
                'action' => $validated['action'],
                'note' => $validated['note'] ?? null,
                'created_at' => $processedAt,
                'updated_at' => $processedAt,
            ]);
        });

        return back()->with('success', 'Report case '.$validated['action'].'.');
    }

    /** @param list<string> $allowed */
    private function filter(Request $request, string $key, array $allowed): ?string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** @return array<string, mixed> */
    private function casePayload(ReportCase $case): array
    {
        $reportable = $case->reportable;
        $word = $reportable instanceof Definition ? $reportable->word : $reportable;
        $priorDismissed = ReportCase::query()
            ->with('reviewActions.admin')
            ->where('reportable_type', $case->reportable_type)
            ->where('reportable_id', $case->reportable_id)
            ->where('reason', $case->reason)
            ->where('status', ReportCase::STATUS_DISMISSED)
            ->whereKeyNot($case->id)
            ->latest()
            ->get();

        return [
            'id' => $case->id,
            'reason' => $case->reason,
            'status' => $case->status,
            'reporter_count' => $case->reports->pluck('reporter_id')->filter()->unique()->count(),
            'target' => [
                'type' => $reportable instanceof Definition ? 'definition' : 'word',
                'text' => $reportable?->text,
                'word_text' => $word?->text,
                'url' => $word instanceof Word ? route('words.show', $word) : null,
            ],
            'latest_review' => $case->processed_at ? [
                'action' => $case->processing_action,
                'note' => $case->processing_note,
                'processed_at' => $case->processed_at->toISOString(),
                'admin' => $case->processor ? ['id' => $case->processor->id, 'name' => $case->processor->name] : null,
            ] : null,
            'reports' => $case->reports->map(fn (Report $report) => [
                'id' => $report->id, 'explanation' => $report->explanation,
                'submitted_at' => $report->created_at?->toISOString(),
                'reporter' => $report->reporter ? ['id' => $report->reporter->id, 'name' => $report->reporter->name] : null,
            ])->values(),
            'actions' => $case->reviewActions->map(fn ($action) => [
                'id' => $action->id,
                'action' => $action->action,
                'note' => $action->note,
                'created_at' => $action->created_at?->toISOString(),
                'admin' => $action->admin ? ['id' => $action->admin->id, 'name' => $action->admin->name] : null,
            ])->values(),
            'prior_dismissed' => $priorDismissed->map(fn (ReportCase $prior) => [
                'id' => $prior->id,
                'reporter_count' => $prior->reports()->whereNotNull('reporter_id')->distinct('reporter_id')->count('reporter_id'),
                'actions' => $prior->reviewActions->map(fn ($action) => ['id' => $action->id, 'action' => $action->action, 'created_at' => $action->created_at?->toISOString()])->values(),
            ])->values(),
        ];
    }
}
