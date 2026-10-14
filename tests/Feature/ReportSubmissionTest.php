<?php

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

test('a muted case absorbs submissions and dismissal starts a new episode', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $third = User::factory()->create();
    $word = Word::factory()->create();
    $this->actingAs($first)->post(reportRoute($word), ['reason' => Report::REASON_WORD_UNFRESH]);
    $case = ReportCase::first();
    $case->update(['status' => ReportCase::STATUS_MUTED]);
    $this->actingAs($second)->post(reportRoute($word), ['reason' => Report::REASON_WORD_UNFRESH]);
    expect($case->fresh()->reports)->toHaveCount(2);

    $case->update(['status' => ReportCase::STATUS_DISMISSED, 'active_key' => null]);
    $this->actingAs($third)->post(reportRoute($word), ['reason' => Report::REASON_WORD_UNFRESH]);
    expect(ReportCase::count())->toBe(2)->and(ReportCase::where('status', 'open')->first()->reports)->toHaveCount(1);
});

test('guests and invalid target reasons are rejected', function () {
    $word = Word::factory()->create();
    $this->post(reportRoute($word), ['reason' => Report::REASON_WORD_UNFRESH])->assertRedirect(route('auth.google'));
    $this->actingAs(User::factory()->create())->post(reportRoute($word), ['reason' => Report::REASON_DEFINITION_OFFENSIVE])->assertSessionHasErrors('reason');
});
