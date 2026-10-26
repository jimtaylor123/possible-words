<?php

use App\Mail\AccountSuspensionMail;
use App\Models\AccountSuspensionAction;
use App\Models\Report;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('user management requires an administrator', function () {
    $user = User::factory()->create();

    $this->get(route('admin.users.index'))->assertRedirect(route('auth.google'));
    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($user)->patch(route('admin.users.suspend', $user), ['reason' => 'No'])->assertForbidden();
});

test('admin users tab exposes activity and account counts', function () {
    $admin = User::factory()->create(['is_admin' => true, 'name' => 'Moderator', 'email' => 'moderator@example.test']);
    $inactive = User::factory()->create(['name' => 'Ada Lovelace', 'last_active_at' => now()->subMinutes(6)]);
    User::factory()->create(['name' => 'Active User', 'email' => 'active@example.test', 'last_active_at' => now()->subSeconds(30)]);

    $this->actingAs($admin)->get(route('admin.dashboard', ['tab' => 'users', 'search' => 'Ada']))
        ->assertInertia(fn ($page) => $page->component('Admin/Dashboard')->where('tab', 'users')->has('users.data', 1)
            ->where('users.data.0.id', $inactive->id)->where('users.data.0.activity_state', 'red'));
    $this->actingAs($admin)->get(route('admin.dashboard', ['tab' => 'users']))
        ->assertInertia(fn ($page) => $page->has('users.data', 3));
});

test('admin users tab searches, sorts, paginates, and exposes report breakdowns', function () {
    $this->travelTo('2026-10-23 12:00:00');
    $admin = User::factory()->create(['is_admin' => true, 'name' => 'Moderator', 'email' => 'moderator@example.test', 'last_active_at' => now()->subMinute()]);
    $ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'avatar' => 'https://example.test/ada.jpg', 'last_active_at' => now()->subSeconds(30)]);
    $oneMinute = User::factory()->create(['name' => 'One Minute', 'email' => 'one-minute@example.test', 'last_active_at' => now()->subMinute()]);
    $bert = User::factory()->create(['name' => 'Bert Example', 'email' => 'bert@example.test', 'last_active_at' => now()->subMinutes(5)]);
    $unknown = User::factory()->create(['name' => 'Unknown User', 'last_active_at' => null]);
    $word = Word::factory()->create();
    $definition = $word->definitions()->create(['user_id' => $ada->id, 'text' => 'A definition.']);
    $word->reports()->create(['reporter_id' => $ada->id, 'reason' => Report::REASON_WORD_OFFENSIVE]);
    $definition->reports()->create(['reporter_id' => $ada->id, 'reason' => Report::REASON_DEFINITION_OFFENSIVE]);

    $this->actingAs($admin)->get(route('admin.dashboard', ['tab' => 'users', 'search' => 'ada']))
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.id', $ada->id)
            ->where('users.data.0.avatar', 'https://example.test/ada.jpg')
            ->where('users.data.0.definitions_count', 1)
            ->where('users.data.0.reports_count', 2)
            ->where('users.data.0.word_reports_count', 1)
            ->where('users.data.0.definition_reports_count', 1)
            ->where('users.data.0.activity_state', 'green'));

    $this->actingAs($admin)->get(route('admin.dashboard', ['tab' => 'users', 'search' => 'example.test', 'sort' => 'email', 'direction' => 'asc']))
        ->assertInertia(fn ($page) => $page->has('users.data', 4)->where('users.data.0.id', $ada->id));

    foreach (['last_active', 'name', 'email', 'definitions', 'reports'] as $sort) {
        $this->actingAs($admin)->get(route('admin.dashboard', ['tab' => 'users', 'sort' => $sort, 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('userFilters.sort', $sort)->where('userFilters.direction', 'asc'));
    }

    $this->actingAs($admin)->get(route('admin.dashboard', ['tab' => 'users']))
        ->assertInertia(fn ($page) => $page
            ->where('users.data.1.id', $ada->id)
            ->where('users.data.2.id', $oneMinute->id)
            ->where('users.data.2.activity_state', 'orange')
            ->where('users.data.3.activity_state', 'orange')
            ->where('users.data.4.activity_state', 'red'));

    User::factory()->count(20)->create();
    $this->actingAs($admin)->get(route('admin.dashboard', ['tab' => 'users', 'search' => 'example.test', 'page' => 1]))
        ->assertInertia(fn ($page) => $page->where('users.current_page', 1)->where('users.per_page', 20));
    $this->travelBack();
});

test('legacy user management URL redirects to the users tab', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'Ada']))
        ->assertRedirect(route('admin.dashboard', ['search' => 'Ada', 'tab' => 'users']));
});

test('suspension validates a trimmed reason and revokes sessions from the configured session table', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $target = User::factory()->create();
    config(['session.driver' => 'database', 'session.table' => 'custom_sessions']);
    Schema::create('custom_sessions', function ($table) {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->text('payload');
        $table->integer('last_activity')->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
    });
    DB::table('custom_sessions')->insert(['id' => 'target-session', 'user_id' => $target->id, 'payload' => 'payload', 'last_activity' => now()->timestamp]);

    $this->actingAs($admin)->patch(route('admin.users.suspend', $target), ['reason' => '   '])->assertSessionHasErrors('reason');
    $this->actingAs($admin)->patch(route('admin.users.suspend', $target), ['reason' => str_repeat('x', 1001)])->assertSessionHasErrors('reason');
    $this->actingAs($admin)->patch(route('admin.users.suspend', $target), ['reason' => '  Repeated abusive reports  '])->assertRedirect();

    expect($target->fresh()->suspension_reason)->toBe('Repeated abusive reports');
    $this->assertDatabaseMissing('custom_sessions', ['user_id' => $target->id]);
    $this->assertDatabaseHas('account_suspension_actions', ['user_id' => $target->id, 'admin_user_id' => $admin->id, 'action' => 'suspended', 'reason' => 'Repeated abusive reports']);
});

test('a mail failure does not roll back a committed suspension', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $target = User::factory()->create();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('mail transport unavailable'));

    $this->actingAs($admin)->patch(route('admin.users.suspend', $target), ['reason' => 'Moderation action'])->assertRedirect();

    expect($target->fresh()->suspended_at)->not->toBeNull();
    $this->assertDatabaseHas('account_suspension_actions', ['user_id' => $target->id, 'action' => 'suspended']);
});

test('admin suspends and restores a user with an immutable audit trail', function () {
    Mail::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $otherAdmin = User::factory()->create(['is_admin' => true]);
    $target = User::factory()->create();

    $this->actingAs($admin)->patch(route('admin.users.suspend', $target), ['reason' => 'Repeated abusive reports'])->assertRedirect();
    expect($target->fresh()->suspended_at)->not->toBeNull()
        ->and(AccountSuspensionAction::where('user_id', $target->id)->first()->action)->toBe(AccountSuspensionAction::ACTION_SUSPENDED);
    Mail::assertSent(AccountSuspensionMail::class, fn (AccountSuspensionMail $mail) => $mail->user->is($target) && $mail->suspended);

    $this->actingAs($admin)->patch(route('admin.users.unsuspend', $target))->assertRedirect();
    expect($target->fresh()->suspended_at)->toBeNull()
        ->and(AccountSuspensionAction::where('user_id', $target->id)->pluck('action')->all())->toBe(['suspended', 'unsuspended']);
    Mail::assertSent(AccountSuspensionMail::class, fn (AccountSuspensionMail $mail) => $mail->user->is($target) && ! $mail->suspended);
});

test('admins cannot suspend themselves or the final active admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->patch(route('admin.users.suspend', $admin), ['reason' => 'No'])->assertSessionHasErrors('user');
    $otherAdmin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->patch(route('admin.users.suspend', $otherAdmin), ['reason' => 'No'])->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.users.suspend', $admin), ['reason' => 'No'])->assertSessionHasErrors('user');
});

test('stale suspension mutations cannot bypass the final active admin rule', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $otherAdmin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->patch(route('admin.users.suspend', $otherAdmin), ['reason' => 'No longer active'])->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.users.suspend', $otherAdmin), ['reason' => 'Duplicate request'])->assertSessionHasErrors('user');
    $this->actingAs($admin)->patch(route('admin.users.suspend', $admin), ['reason' => 'Last administrator'])->assertSessionHasErrors('user');
    $this->actingAs($admin)->patch(route('admin.users.unsuspend', $otherAdmin))->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.users.unsuspend', $otherAdmin))->assertSessionHasErrors('user');

    expect(User::query()->where('is_admin', true)->whereNull('suspended_at')->count())->toBe(2);
    expect(AccountSuspensionAction::where('user_id', $otherAdmin->id)->pluck('action')->all())->toBe(['suspended', 'unsuspended']);
});
