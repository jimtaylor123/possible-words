<?php

namespace App\Http\Controllers;

use App\Models\Definition;
use App\Models\Report;
use App\Models\ReportCase;
use App\Models\Word;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function store(Request $request, string $targetType, string $target)
    {
        $reportable = $this->resolveReportable($targetType, $target);

        abort_unless($this->isReportable($reportable), 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in(Report::reasonsFor($reportable))],
            'explanation' => ['nullable', 'string', 'max:1000'],
        ]);

        $reporter = $request->user();

        try {
            DB::transaction(function () use ($reportable, $reporter, $validated): void {
                $activeKey = ReportCase::activeKey($reportable, $validated['reason']);
                $case = ReportCase::query()->where('active_key', $activeKey)->first();

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

                $reportable->reports()->create([
                    'report_case_id' => $case->id,
                    'reporter_id' => $reporter->id,
                    'reason' => $validated['reason'],
                    'explanation' => $validated['explanation'] ?? null,
                    // Retained for legacy audit rows; lifecycle state is owned by the case.
                    'status' => ReportCase::STATUS_OPEN,
                ]);
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'duplicate-report') {
                return redirect()->back()->with('error', 'You have already reported this item.');
            }

            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isDuplicateReportException($exception)) {
                return redirect()->back()->with('error', 'You have already reported this item.');
            }

            throw $exception;
        }

        return redirect()->back()->with('success', 'Report submitted.');
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
