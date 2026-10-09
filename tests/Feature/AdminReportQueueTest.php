<?php

use App\Models\Definition;
use App\Models\Report;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function adminReport(Word|Definition $target, string $reason, ?User $reporter = null): Report
{
    return $target->reports()->create([
        'reporter_id' => $reporter?->id,
        'reason' => $reason,
        'explanation' => 'Reporter context',
        'status' => Report::STATUS_OPEN,
    ]);
}

test('an admin receives all report types with explicitly shaped queue payloads', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $reporter = User::factory()->create(['name' => 'Reporter']);
    $word = Word::factory()->create(['text' => 'florp']);
    $definition = $word->definitions()->create(['user_id' => $reporter->id, 'text' => 'A florp is a thing.']);
    adminReport($word, Report::REASON_WORD_UNFRESH, $reporter);
    adminReport(Word::factory()->create(), Report::REASON_WORD_OFFENSIVE, $reporter);
    adminReport($definition, Report::REASON_DEFINITION_OFFENSIVE, $reporter);

    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertInertia(fn ($page) => $page->component('Admin/Dashboard')
            ->where('filters.status', 'open')
            ->has('reports.data', 3)
            ->where('reports.data.0.status', 'open')
            ->has('reports.data.0.target')
            ->has('reports.data.0.submitted_at'));
});

test('queue filters valid lifecycle values, rejects malformed values, and preserves filters in pagination', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $word = Word::factory()->create();
    $open = adminReport($word, Report::REASON_WORD_UNFRESH);
    $closed = adminReport(Word::factory()->create(), Report::REASON_WORD_OFFENSIVE);
    $closed->update(['status' => Report::STATUS_DISMISSED]);

    $this->actingAs($admin)->get(route('admin.dashboard', ['reason' => Report::REASON_WORD_UNFRESH, 'status' => 'invalid']))
        ->assertInertia(fn ($page) => $page->where('filters.reason', Report::REASON_WORD_UNFRESH)->where('filters.status', 'open')->has('reports.data', 1));

    $this->actingAs($admin)->get(route('admin.dashboard', ['reason' => [Report::REASON_WORD_OFFENSIVE], 'status' => [Report::STATUS_DISMISSED]]))
        ->assertInertia(fn ($page) => $page->where('filters.reason', null)->where('filters.status', 'open')->has('reports.data', 1));

    $this->actingAs($admin)->get(route('admin.dashboard', ['reason' => Report::REASON_WORD_OFFENSIVE, 'status' => Report::STATUS_DISMISSED]))
        ->assertInertia(fn ($page) => $page->where('filters.reason', Report::REASON_WORD_OFFENSIVE)->where('filters.status', Report::STATUS_DISMISSED)->has('reports.data', 1));

    foreach (range(1, 20) as $index) {
        adminReport(Word::factory()->create(), Report::REASON_WORD_UNFRESH);
    }

    $this->actingAs($admin)->get(route('admin.dashboard', [
        'reason' => Report::REASON_WORD_UNFRESH,
        'status' => Report::STATUS_OPEN,
        'page' => 2,
    ]))->assertInertia(fn ($page) => $page
        ->where('reports.current_page', 2)
        ->has('reports.data', 1)
        ->where('reports.prev_page_url', fn ($url) => str_contains((string) $url, 'reason='.Report::REASON_WORD_UNFRESH)
            && str_contains((string) $url, 'status='.Report::STATUS_OPEN)));
});

test('reviewing, dismissing, and reopening append immutable audit history without changing content', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $word = Word::factory()->create(['text' => 'unchanged']);
    $definition = $word->definitions()->create(['user_id' => User::factory()->create()->id, 'text' => 'Unchanged definition']);
    $report = adminReport($definition, Report::REASON_DEFINITION_OFFENSIVE);

    $this->actingAs($admin)->patch(route('admin.reports.update', $report), ['action' => Report::ACTION_REVIEWED, 'note' => 'Looked at it.'])
        ->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.reports.update', $report), ['action' => Report::ACTION_REOPENED, 'note' => 'Needs another look.'])
        ->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.reports.update', $report), ['action' => Report::ACTION_DISMISSED, 'note' => 'No action needed.'])
        ->assertRedirect();

    expect($report->fresh())->status->toBe(Report::STATUS_DISMISSED)
        ->and($report->reviewActions()->pluck('action')->all())->toBe([Report::ACTION_REVIEWED, Report::ACTION_REOPENED, Report::ACTION_DISMISSED])
        ->and($word->fresh()->text)->toBe('unchanged')
        ->and($definition->fresh()->text)->toBe('Unchanged definition');
    $this->assertDatabaseHas('report_review_actions', ['report_id' => $report->id, 'admin_user_id' => $admin->id, 'note' => 'Looked at it.']);
});

test('reopens a dismissed report while retaining latest metadata and all actions', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $report = adminReport(Word::factory()->create(), Report::REASON_WORD_UNFRESH);

    $this->actingAs($admin)->patch(route('admin.reports.update', $report), ['action' => Report::ACTION_DISMISSED, 'note' => 'First decision.'])
        ->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.reports.update', $report), ['action' => Report::ACTION_REOPENED, 'note' => 'Reconsidering.'])
        ->assertRedirect();

    expect($report->fresh())
        ->status->toBe(Report::STATUS_OPEN)
        ->processing_action->toBe(Report::ACTION_REOPENED)
        ->processing_note->toBe('Reconsidering.')
        ->processed_by_user_id->toBe($admin->id)
        ->processed_at->not->toBeNull()
        ->and($report->reviewActions()->pluck('action')->all())->toBe([Report::ACTION_DISMISSED, Report::ACTION_REOPENED]);
});

test('atomically guards lifecycle state and does not audit a stale action', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $report = adminReport(Word::factory()->create(), Report::REASON_WORD_UNFRESH);

    $this->actingAs($admin)->patch(route('admin.reports.update', $report), ['action' => Report::ACTION_REVIEWED])
        ->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.reports.update', $report), ['action' => Report::ACTION_DISMISSED])
        ->assertSessionHasErrors('action');

    expect($report->fresh()->status)->toBe(Report::STATUS_REVIEWED)
        ->and($report->reviewActions()->pluck('action')->all())->toBe([Report::ACTION_REVIEWED]);
    $this->assertDatabaseMissing('report_review_actions', [
        'report_id' => $report->id,
        'action' => Report::ACTION_DISMISSED,
    ]);
});

test('reopening gracefully reports an existing open report conflict', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $reporter = User::factory()->create();
    $word = Word::factory()->create();
    $closedReport = adminReport($word, Report::REASON_WORD_UNFRESH, $reporter);
    $closedReport->update(['status' => Report::STATUS_DISMISSED]);
    adminReport($word, Report::REASON_WORD_OFFENSIVE, $reporter);

    $this->actingAs($admin)->patch(route('admin.reports.update', $closedReport), ['action' => Report::ACTION_REOPENED])
        ->assertRedirect()
        ->assertSessionHas('error', 'This report cannot be reopened because the reporter already has an open report for this item.');

    expect($closedReport->fresh()->status)->toBe(Report::STATUS_DISMISSED)
        ->and($closedReport->reviewActions)->toHaveCount(0);
});

test('treats empty string filters as unfiltered defaults', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    adminReport(Word::factory()->create(), Report::REASON_WORD_UNFRESH);

    $this->actingAs($admin)->get(route('admin.dashboard').'?reason=&status=')
        ->assertInertia(fn ($page) => $page
            ->where('filters.reason', null)
            ->where('filters.status', Report::STATUS_OPEN)
            ->has('reports.data', 1));
});

test('exposes the full reason and status filter option sets', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('reasonOptions', [
                ['label' => 'All types', 'value' => null],
                ['label' => 'Unfresh word', 'value' => Report::REASON_WORD_UNFRESH],
                ['label' => 'Offensive word', 'value' => Report::REASON_WORD_OFFENSIVE],
                ['label' => 'Offensive definition', 'value' => Report::REASON_DEFINITION_OFFENSIVE],
            ])
            ->where('statusOptions', [
                ['label' => 'Open', 'value' => Report::STATUS_OPEN],
                ['label' => 'Reviewed', 'value' => Report::STATUS_REVIEWED],
                ['label' => 'Dismissed', 'value' => Report::STATUS_DISMISSED],
            ]));
});

test('renders reports for soft-deleted targets without error', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $word = Word::factory()->create(['text' => 'vanishing']);
    adminReport($word, Report::REASON_WORD_UNFRESH, User::factory()->create());
    $word->delete();

    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('reports.data', 1)
            ->where('reports.data.0.target.type', 'word')
            ->where('reports.data.0.target.text', null)
            ->where('reports.data.0.target.word_text', null)
            ->where('reports.data.0.target.url', null));
});

test('rejects an unknown lifecycle action without recording a review', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $report = adminReport(Word::factory()->create(), Report::REASON_WORD_UNFRESH);

    $this->actingAs($admin)->patch(route('admin.reports.update', $report), ['action' => 'bogus'])
        ->assertSessionHasErrors('action');

    expect($report->fresh()->status)->toBe(Report::STATUS_OPEN)
        ->and($report->reviewActions)->toHaveCount(0);
});

test('guests and regular users cannot read or mutate the queue', function () {
    $report = adminReport(Word::factory()->create(), Report::REASON_WORD_UNFRESH);
    $this->get(route('admin.dashboard'))->assertRedirect(route('auth.google'));
    $this->patch(route('admin.reports.update', $report), ['action' => Report::ACTION_REVIEWED])->assertRedirect(route('auth.google'));

    $user = User::factory()->create();
    $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    $this->actingAs($user)->patch(route('admin.reports.update', $report), ['action' => Report::ACTION_REVIEWED])->assertForbidden();
});
