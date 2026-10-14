<?php

use App\Http\Controllers\ReportController;
use App\Models\Report;
use App\Models\ReportCase;
use App\Models\User;
use App\Models\Word;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function reportRoute(Word $word): string
{
    return route('reports.store', ['targetType' => 'word', 'target' => $word->slug]);
}

test('groups matching reports and rejects a reporter duplicate within a case', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $word = Word::factory()->create();

    $this->actingAs($first)->post(reportRoute($word), ['reason' => Report::REASON_WORD_UNFRESH])->assertSessionHas('success');
    $this->actingAs($second)->post(reportRoute($word), ['reason' => Report::REASON_WORD_UNFRESH])->assertSessionHas('success');
    $this->actingAs($first)->post(reportRoute($word), ['reason' => Report::REASON_WORD_UNFRESH])->assertSessionHas('error', 'You have already reported this item.');

    expect(ReportCase::count())->toBe(1)->and(Report::count())->toBe(2);
    $case = ReportCase::first();
    expect($case->reports)->toHaveCount(2);
    expect(fn () => $word->reports()->create(['report_case_id' => $case->id, 'reporter_id' => $first->id, 'reason' => Report::REASON_WORD_UNFRESH]))->toThrow(QueryException::class);
});

test('allows one user to report different reasons in separate cases', function () {
    $user = User::factory()->create();
    $word = Word::factory()->create();
    $this->actingAs($user)->post(reportRoute($word), ['reason' => Report::REASON_WORD_UNFRESH]);
    $this->actingAs($user)->post(reportRoute($word), ['reason' => Report::REASON_WORD_OFFENSIVE]);
    expect(ReportCase::count())->toBe(2)->and(Report::count())->toBe(2);
});

test('a muted case absorbs submissions', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $word = Word::factory()->create();
    $this->actingAs($first)->post(reportRoute($word), ['reason' => Report::REASON_WORD_UNFRESH]);
    $case = ReportCase::first();
    $case->update(['status' => ReportCase::STATUS_MUTED]);
    $this->actingAs($second)->post(reportRoute($word), ['reason' => Report::REASON_WORD_UNFRESH]);
    expect($case->fresh()->reports)->toHaveCount(2);
});

test('a submission revalidates a case dismissed after its locked lookup', function () {
    $first = User::factory()->create();
    $laterReporter = User::factory()->create();
    $word = Word::factory()->create();

    $this->actingAs($first)->post(reportRoute($word), ['reason' => Report::REASON_WORD_UNFRESH]);
    $dismissedCase = ReportCase::first();

    $controller = new class extends ReportController
    {
        protected function lockedActiveCase(string $activeKey): ?ReportCase
        {
            $case = parent::lockedActiveCase($activeKey);

            // Interleave the admin's status-conditional dismissal after the
            // submission lookup. The submission must not use this stale model.
            $case?->update(['status' => ReportCase::STATUS_DISMISSED, 'active_key' => null]);

            return $case;
        }

        public function submitForTest(Word $word, User $reporter): void
        {
            $this->submitToActiveCase($word, $reporter, ['reason' => Report::REASON_WORD_UNFRESH]);
        }
    };
    $controller->submitForTest($word, $laterReporter);

    $newCase = ReportCase::where('status', ReportCase::STATUS_OPEN)->firstOrFail();
    expect(ReportCase::count())->toBe(2)
        ->and($dismissedCase->fresh()->reports)->toHaveCount(1)
        ->and($newCase->reports)->toHaveCount(1)
        ->and($newCase->reports->first()->reporter_id)->toBe($laterReporter->id);
});

test('guests and invalid target reasons are rejected', function () {
    $word = Word::factory()->create();
    $this->post(reportRoute($word), ['reason' => Report::REASON_WORD_UNFRESH])->assertRedirect(route('auth.google'));
    $this->actingAs(User::factory()->create())->post(reportRoute($word), ['reason' => Report::REASON_DEFINITION_OFFENSIVE])->assertSessionHasErrors('reason');
});
