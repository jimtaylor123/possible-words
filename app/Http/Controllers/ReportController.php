<?php

namespace App\Http\Controllers;

use App\Models\Definition;
use App\Models\Report;
use App\Models\Word;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
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

        $existingReport = Report::query()
            ->where('reporter_id', $reporter->id)
            ->where('reportable_type', $reportable::class)
            ->where('reportable_id', $reportable->getKey())
            ->where('status', Report::STATUS_OPEN)
            ->exists();

        if ($existingReport) {
            return redirect()->back()->with('error', 'You have already reported this item.');
        }

        try {
            $reportable->reports()->create([
                'reporter_id' => $reporter->id,
                'reason' => $validated['reason'],
                'explanation' => $validated['explanation'] ?? null,
                'status' => Report::STATUS_OPEN,
            ]);
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
            Definition::class => ! $reportable->isRemoved() && $reportable->word->isPublishable(),
            default => false,
        };
    }

    private function isDuplicateReportException(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }
}
