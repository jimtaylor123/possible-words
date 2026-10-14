<?php

use App\Models\Report;
use App\Models\ReportCase;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function adminCase(string $status = 'open'): ReportCase
{
    $word = Word::factory()->create();
    $case = $word->reportCases()->create(['reason' => Report::REASON_WORD_UNFRESH, 'status' => $status, 'active_key' => $status === 'dismissed' ? null : ReportCase::activeKey($word, Report::REASON_WORD_UNFRESH)]);
    $word->reports()->create(['report_case_id' => $case->id, 'reporter_id' => User::factory()->create()->id, 'reason' => Report::REASON_WORD_UNFRESH, 'explanation' => 'Context']);

    return $case;
}

test('admin queue is case based and filters statuses', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    adminCase('open');
    adminCase('muted');
    adminCase('dismissed');
    $this->actingAs($admin)->get(route('admin.dashboard'))->assertInertia(fn ($page) => $page->where('filters.status', 'open')->has('reports.data', 1)->where('reports.data.0.reporter_count', 1)->has('reports.data.0.reports', 1));
    foreach (['muted', 'dismissed', 'all'] as $status) {
        $this->actingAs($admin)->get(route('admin.dashboard', ['status' => $status]))->assertInertia(fn ($page) => $page->where('filters.status', $status)->has('reports.data', $status === 'all' ? 3 : 1));
    }
});

test('admin can mute unmute and dismiss a case with immutable actions', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $case = adminCase();
    $this->actingAs($admin)->patch(route('admin.reports.update', $case), ['action' => 'muted', 'note' => 'Noise'])->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.reports.update', $case), ['action' => 'unmuted'])->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.reports.update', $case), ['action' => 'dismissed'])->assertRedirect();
    expect($case->fresh()->status)->toBe('dismissed')->and($case->reviewActions()->pluck('action')->all())->toBe(['muted', 'unmuted', 'dismissed']);
    $this->actingAs($admin)->patch(route('admin.reports.update', $case), ['action' => 'unmuted'])->assertSessionHasErrors('action');
});

test('queue access requires an administrator', function () {
    $case = adminCase();
    $this->get(route('admin.dashboard'))->assertRedirect(route('auth.google'));
    $this->actingAs(User::factory()->create())->patch(route('admin.reports.update', $case), ['action' => 'muted'])->assertForbidden();
});
