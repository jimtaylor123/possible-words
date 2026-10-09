<?php

namespace App\Http\Controllers;

use App\Models\Definition;
use App\Models\Report;
use App\Models\Word;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminReportController extends Controller
{
    public function index(Request $request)
    {
        $reason = $this->filter($request, 'reason', [
            Report::REASON_WORD_UNFRESH,
            Report::REASON_WORD_OFFENSIVE,
            Report::REASON_DEFINITION_OFFENSIVE,
        ]);
        $status = $this->filter($request, 'status', Report::statuses()) ?? Report::STATUS_OPEN;

        $reports = Report::query()
            ->with([
                'reporter',
                'processor',
                'reviewActions.admin',
                'reportable' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([Definition::class => ['word']]);
                },
            ])
            ->when($reason, fn ($query) => $query->where('reason', $reason))
            ->where('status', $status)
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Report $report) => $this->reportPayload($report));

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
                ['label' => 'Open', 'value' => Report::STATUS_OPEN],
                ['label' => 'Reviewed', 'value' => Report::STATUS_REVIEWED],
                ['label' => 'Dismissed', 'value' => Report::STATUS_DISMISSED],
            ],
        ]);
    }

    public function update(Request $request, Report $report)
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(Report::lifecycleActionsFor($report->status))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $report, $validated): void {
            $processedAt = now();
            $report->update([
                'status' => Report::statusForAction($validated['action']),
                'processed_by_user_id' => $request->user()->id,
                'processed_at' => $processedAt,
                'processing_action' => $validated['action'],
                'processing_note' => $validated['note'] ?? null,
            ]);
            $report->reviewActions()->create([
                'admin_user_id' => $request->user()->id,
                'action' => $validated['action'],
                'note' => $validated['note'] ?? null,
                'created_at' => $processedAt,
                'updated_at' => $processedAt,
            ]);
        });

        return back()->with('success', 'Report '.($validated['action'] === Report::ACTION_REOPENED ? 'reopened' : $validated['action']).'.');
    }

    /** @param list<string> $allowed */
    private function filter(Request $request, string $key, array $allowed): ?string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** @return array<string, mixed> */
    private function reportPayload(Report $report): array
    {
        $reportable = $report->reportable;
        $word = $reportable instanceof Definition ? $reportable->word : $reportable;

        return [
            'id' => $report->id,
            'reason' => $report->reason,
            'status' => $report->status,
            'explanation' => $report->explanation,
            'submitted_at' => $report->created_at?->toISOString(),
            'reporter' => $report->reporter ? ['id' => $report->reporter->id, 'name' => $report->reporter->name] : null,
            'target' => [
                'type' => $reportable instanceof Definition ? 'definition' : 'word',
                'text' => $reportable?->text,
                'word_text' => $word?->text,
                'url' => $word instanceof Word ? route('words.show', $word) : null,
            ],
            'latest_review' => $report->processed_at ? [
                'action' => $report->processing_action,
                'note' => $report->processing_note,
                'processed_at' => $report->processed_at->toISOString(),
                'admin' => $report->processor ? ['id' => $report->processor->id, 'name' => $report->processor->name] : null,
            ] : null,
            'actions' => $report->reviewActions->map(fn ($action) => [
                'id' => $action->id,
                'action' => $action->action,
                'note' => $action->note,
                'created_at' => $action->created_at?->toISOString(),
                'admin' => $action->admin ? ['id' => $action->admin->id, 'name' => $action->admin->name] : null,
            ])->values(),
        ];
    }
}
