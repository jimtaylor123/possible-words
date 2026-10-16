<?php

namespace App\Http\Controllers;

use App\Models\Definition;
use App\Models\Report;
use App\Models\ReportCase;
use App\Models\User;
use App\Models\Word;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function store(Request $request, string $targetType, string $target, NotificationService $notifications)
    {
        $reportable = $this->resolveReportable($targetType, $target);

        abort_unless($this->isReportable($reportable), 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in(Report::reasonsFor($reportable))],
            'explanation' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $reporter */
        $reporter = $request->user();

        try {
            $report = $this->submitToActiveCase($reportable, $reporter, $validated);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'duplicate-report') {
                return redirect()->back()->with('error', 'You have already reported this item.');
            }

            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isDuplicateReportException($exception)) {
                try {
                    // A competing submitter may have inserted the active case.
                    // Repeat the entire locked lookup: it also observes a dismissal
                    // that occurred before this retry and starts a new episode.
                    $report = $this->submitToActiveCase($reportable, $reporter, $validated);

                    $this->notifyAdmins($notifications, $targetType, $report);

                    return redirect()->back()->with('success', 'Report submitted.');
                } catch (QueryException $retryException) {
                    if (! $this->isDuplicateReportException($retryException)) {
                        throw $retryException;
                    }
                } catch (\RuntimeException $retryException) {
                    if ($retryException->getMessage() !== 'duplicate-report') {
                        throw $retryException;
                    }
                }

                return redirect()->back()->with('error', 'You have already reported this item.');
            }

            throw $exception;
        }

        $this->notifyAdmins($notifications, $targetType, $report);

        return redirect()->back()->with('success', 'Report submitted.');
    }

    /** @param array{reason: string, explanation?: string|null} $validated */
    protected function submitToActiveCase(Model $reportable, User $reporter, array $validated): Report
    {
        // SQLite's deferred transactions can fail when a separate connection
        // dismisses this case after the lookup. Laravel recognizes its busy /
        // locked exception as a concurrency error and restarts this complete
        // callback, including the active-case lookup.
        return DB::transaction(function () use ($reportable, $reporter, $validated): Report {
            $activeKey = ReportCase::activeKey($reportable, $validated['reason']);
            // This prevents a concurrent dismissal from clearing active_key after
            // the lookup but before the report is attached to this case.
            $case = $this->lockedActiveCase($activeKey);

            // The lock prevents this in production, but revalidate the model too
            // so a case can never be attached after it has become historical.
            if ($case !== null && ! in_array($case->fresh()?->status, [ReportCase::STATUS_OPEN, ReportCase::STATUS_MUTED], true)) {
                $case = null;
            }

            if ($case === null) {
                $case = $reportable->reportCases()->create([
                    'reason' => $validated['reason'],
                    'status' => ReportCase::STATUS_OPEN,
                    'active_key' => $activeKey,
                ]);
            }

            if ($case->reports()->where('reporter_id', $reporter->id)->exists()) {
                throw new \RuntimeException('duplicate-report');
            }

            return $reportable->reports()->create([
                'report_case_id' => $case->id,
                'reporter_id' => $reporter->id,
                'reason' => $validated['reason'],
                'explanation' => $validated['explanation'] ?? null,
                // Retained for legacy audit rows; lifecycle state is owned by the case.
                'status' => ReportCase::STATUS_OPEN,
            ]);
        }, attempts: 3);
    }

    private function notifyAdmins(NotificationService $notifications, string $targetType, Report $report): void
    {
        User::query()->where('is_admin', true)->each(function (User $admin) use ($notifications, $targetType, $report): void {
            $reportable = $report->reportable;
            $label = $reportable instanceof Definition ? $reportable->word?->text : $reportable?->text;
            $reason = Report::reasonLabel($report->reason);
            $reporter = $report->reporter?->name ?? 'A user';
            $body = $reportable instanceof Definition
                ? $reporter.' reported one of the definitions of “'.($label ?? $targetType).'” as '.$reason.'.'
                : $reporter.' reported “'.($label ?? $targetType).'” as '.$reason.'.';
            if (! $notifications->notify($admin, [
                'kind' => 'admin_report_created',
                'title' => 'New report received',
                'body' => $body,
                'url' => route('admin.dashboard'),
                'subject_type' => $reportable?->getMorphClass(),
                'subject_id' => $reportable?->getKey(),
            ], true, "report:{$report->id}:admin:{$admin->id}")) {
                Log::warning('Could not notify admin about new report.', ['admin_id' => $admin->id, 'report_id' => $report->id]);
            }
        });
    }

    protected function lockedActiveCase(string $activeKey): ?ReportCase
    {
        return ReportCase::query()
            ->where('active_key', $activeKey)
            ->whereIn('status', [ReportCase::STATUS_OPEN, ReportCase::STATUS_MUTED])
            ->lockForUpdate()
            ->first();
    }

    private function resolveReportable(string $targetType, string $target): Model
    {
        return match ($targetType) {
            'word' => Word::where('slug', $target)->firstOrFail(),
            'definition' => Definition::findOrFail($target),
            default => abort(404),
        };
    }

    private function isReportable(Model $reportable): bool
    {
        return match ($reportable::class) {
            Word::class => $reportable->isPublishable(),
            Definition::class => ! $reportable->isRemoved() && ($reportable->word?->isPublishable() ?? false),
            default => false,
        };
    }

    private function isDuplicateReportException(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }
}
