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

test('queue filters are whitelisted and preserved by pagination', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $word = Word::factory()->create();
    $open = adminReport($word, Report::REASON_WORD_UNFRESH);
    $closed = adminReport(Word::factory()->create(), Report::REASON_WORD_OFFENSIVE);
    $closed->update(['status' => Report::STATUS_DISMISSED]);

    $this->actingAs($admin)->get(route('admin.dashboard', ['reason' => Report::REASON_WORD_UNFRESH, 'status' => 'invalid']))
        ->assertInertia(fn ($page) => $page->where('filters.reason', Report::REASON_WORD_UNFRESH)->where('filters.status', 'open')->has('reports.data', 1));

    $this->actingAs($admin)->get(route('admin.dashboard', ['reason' => [Report::REASON_WORD_OFFENSIVE], 'status' => [Report::STATUS_DISMISSED]]))
        ->assertInertia(fn ($page) => $page->where('filters.reason', null)->where('filters.status', 'open')->has('reports.data', 1));
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

test('guests and regular users cannot read or mutate the queue', function () {
    $report = adminReport(Word::factory()->create(), Report::REASON_WORD_UNFRESH);
    $this->get(route('admin.dashboard'))->assertRedirect(route('auth.google'));
    $this->patch(route('admin.reports.update', $report), ['action' => Report::ACTION_REVIEWED])->assertRedirect(route('auth.google'));

    $user = User::factory()->create();
    $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    $this->actingAs($user)->patch(route('admin.reports.update', $report), ['action' => Report::ACTION_REVIEWED])->assertForbidden();
});
