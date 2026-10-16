<?php

use App\Mail\NotificationMail;
use App\Models\Report;
use App\Models\ReportCase;
use App\Models\User;
use App\Models\Word;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('immediate notifications are marked sent only after mail delivery', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_email_mode' => 'immediate']);
    app(NotificationService::class)->notify($user, ['title' => 'Hello', 'body' => 'Body', 'url' => '/']);
    expect($user->notifications()->first()->email_sent_at)->not->toBeNull();
    Mail::assertSent(NotificationMail::class);
});

test('a failed immediate delivery remains retryable', function () {
    $user = User::factory()->create(['notification_email_mode' => 'immediate']);
    Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Mail unavailable'));

    expect(app(NotificationService::class)->notify($user, ['title' => 'Hello', 'body' => 'Body', 'url' => '/'], false, 'retryable'))->toBeFalse()
        ->and($user->notifications()->first()->email_sent_at)->toBeNull();
});

test('failed immediate notifications are retried by the scheduled command', function () {
    $user = User::factory()->create(['notification_email_mode' => 'off']);
    app(NotificationService::class)->notify($user, ['title' => 'Hello', 'body' => 'Body', 'url' => '/']);
    $user->update(['notification_email_mode' => 'immediate']);

    Mail::fake();
    $this->artisan('notifications:retry-immediate')->assertSuccessful();

    expect($user->notifications()->first()->email_sent_at)->not->toBeNull();
    Mail::assertSent(NotificationMail::class);
});

test('retrying a new word fanout reuses each recipient notification', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $word = Word::factory()->create(['published_at' => now(), 'domain_status' => Word::DOMAIN_AVAILABLE]);

    $service = app(NotificationService::class);
    expect($service->notifyNewWord($word))->toBeTrue();
    expect($service->notifyNewWord($word))->toBeTrue();

    expect($firstUser->notifications()->count())->toBe(1)
        ->and($secondUser->notifications()->count())->toBe(1)
        ->and(DatabaseNotification::query()->count())->toBe(2);
});

test('a failed digest releases its durable claim', function () {
    $user = User::factory()->create(['notification_email_mode' => 'daily']);
    app(NotificationService::class)->notify($user, ['title' => 'Hello', 'body' => 'Body', 'url' => '/']);

    Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Mail unavailable'));
    app(NotificationService::class)->sendDailyDigests();

    $notification = $user->notifications()->first();
    expect($notification->email_sent_at)->toBeNull()->and($notification->email_claim_token)->toBeNull();

});

test('daily notification digests are sent by the scheduled command', function () {
    $user = User::factory()->create(['notification_email_mode' => 'daily']);
    app(NotificationService::class)->notify($user, ['title' => 'Hello', 'body' => 'Body', 'url' => '/']);

    Mail::fake();
    $this->artisan('notifications:send-digests')->assertSuccessful();

    expect($user->notifications()->first()->email_sent_at)->not->toBeNull();
    Mail::assertSent(NotificationMail::class);
});

test('a user can mark individual notifications read, read all, and clear read notifications', function () {
    $user = User::factory()->create();
    $service = app(NotificationService::class);
    $service->notify($user, ['title' => 'One', 'body' => 'Body', 'url' => '/']);
    $service->notify($user, ['title' => 'Two', 'body' => 'Body', 'url' => '/']);

    $first = $user->notifications()->oldest()->firstOrFail();
    $this->actingAs($user)->post(route('notifications.mark-read', $first))->assertOk()->assertJsonPath('unread_count', 1);
    $this->actingAs($user)->post(route('notifications.read'))->assertOk()->assertJsonPath('unread_count', 0);
    $this->actingAs($user)->post(route('notifications.clear-read'))->assertOk()->assertJsonPath('notifications', []);

    expect($user->notifications()->whereNull('dismissed_at')->count())->toBe(0);
    $this->actingAs($user)->patch(route('account.notifications.update'), ['notification_email_mode' => 'off', 'admin_report_email_mode' => 'immediate'])->assertRedirect();
    expect($user->fresh()->notification_email_mode)->toBe('off')->and($user->fresh()->admin_report_email_mode)->toBe('daily');
});

test('notification responses are bounded to fifteen visible items', function () {
    $user = User::factory()->create();
    $service = app(NotificationService::class);

    foreach (range(1, 16) as $number) {
        $service->notify($user, ['title' => "Notification {$number}", 'body' => 'Body', 'url' => '/']);
    }

    $this->actingAs($user)->get(route('notifications.index'))->assertOk()
        ->assertJsonCount(15, 'notifications')
        ->assertJsonPath('unread_count', 16);
});

test('notification responses expose only presentation-safe data', function () {
    $user = User::factory()->create();
    app(NotificationService::class)->notify($user, [
        'title' => 'Hello',
        'body' => 'Body',
        'url' => '/',
        'subject_id' => 123,
        'internal_secret' => 'do not expose',
    ]);

    $this->actingAs($user)->get(route('notifications.index'))->assertOk()
        ->assertJsonPath('notifications.0.data.title', 'Hello')
        ->assertJsonPath('notifications.0.data.body', 'Body')
        ->assertJsonPath('notifications.0.data.url', '/')
        ->assertJsonMissing(['internal_secret' => 'do not expose'])
        ->assertJsonMissing(['subject_id' => 123]);
});

test('notification pruning keeps recent notifications and removes expired read and unread notifications', function () {
    $user = User::factory()->create();
    $service = app(NotificationService::class);
    $service->notify($user, ['title' => 'Old read', 'body' => 'Body', 'url' => '/']);
    $service->notify($user, ['title' => 'Old unread', 'body' => 'Body', 'url' => '/']);
    $service->notify($user, ['title' => 'Recent', 'body' => 'Body', 'url' => '/']);

    $notifications = $user->notifications()->oldest()->get();
    $notifications[0]->forceFill(['read_at' => now()->subDays(15), 'created_at' => now()->subDays(15)])->save();
    $notifications[1]->forceFill(['created_at' => now()->subDays(31)])->save();

    $this->artisan('notifications:prune')->assertSuccessful();

    expect($user->notifications()->pluck('data')->map(fn (array $data) => $data['title'])->all())->toBe(['Recent']);
});

test('a newly created report notifies admins once', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $reporter = User::factory()->create();
    $word = Word::factory()->create();

    $this->actingAs($reporter)->post(route('reports.store', ['targetType' => 'word', 'target' => $word->slug]), ['reason' => Report::REASON_WORD_UNFRESH])->assertSessionHas('success');

    expect($admin->notifications()->count())->toBe(1)
        ->and($admin->notifications()->first()->data['url'])->toBe(route('admin.dashboard'))
        ->and($admin->notifications()->first()->data['body'])->toContain('as not fresh');
});

test('a definition report identifies the definition without including its text', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $reporter = User::factory()->create(['name' => 'Luciene Taylor']);
    $author = User::factory()->create();
    $word = Word::factory()->create(['text' => 'nath']);
    $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'A deliberately long definition that should not appear in the notification.']);

    $this->actingAs($reporter)->post(route('reports.store', ['targetType' => 'definition', 'target' => $definition->id]), ['reason' => Report::REASON_DEFINITION_OFFENSIVE])->assertSessionHas('success');

    expect($admin->notifications()->first()->data['body'])->toBe('Luciene Taylor reported one of the definitions of “nath” as offensive.');
});

test('admin removal removes a word and informs only case reporters', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $reporter = User::factory()->create();
    $word = Word::factory()->create();
    $case = $word->reportCases()->create(['reason' => Report::REASON_WORD_UNFRESH, 'status' => 'open', 'active_key' => ReportCase::activeKey($word, Report::REASON_WORD_UNFRESH)]);
    $word->reports()->create(['report_case_id' => $case->id, 'reporter_id' => $reporter->id, 'reason' => Report::REASON_WORD_UNFRESH]);
    $this->actingAs($admin)->patch(route('admin.reports.update', $case), ['action' => 'removed'])->assertRedirect();
    expect($word->fresh()->trashed())->toBeTrue()
        ->and($reporter->notifications()->first()->data['title'])->toBe('Reported content removed')
        ->and($reporter->notifications()->first()->data['body'])->toBe("The word you reported, “{$word->text}”, has been removed.")
        ->and($reporter->notifications()->first()->data['url'])->toBeNull();
});

test('admin removal does not notify reporters when the definition was already removed', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $reporter = User::factory()->create();
    $word = Word::factory()->create();
    $definition = $word->definitions()->create(['user_id' => User::factory()->create()->id, 'text' => 'Removed definition']);
    $definition->removed_at = now()->subMinute();
    $definition->save();
    $case = $definition->reportCases()->create(['reason' => Report::REASON_DEFINITION_OFFENSIVE, 'status' => 'open', 'active_key' => ReportCase::activeKey($definition, Report::REASON_DEFINITION_OFFENSIVE)]);
    $definition->reports()->create(['report_case_id' => $case->id, 'reporter_id' => $reporter->id, 'reason' => Report::REASON_DEFINITION_OFFENSIVE]);

    $this->actingAs($admin)->patch(route('admin.reports.update', $case), ['action' => 'removed'])->assertRedirect();

    expect($reporter->notifications()->count())->toBe(0);
});

test('admin dismissal includes the provided reason in the reporter notification', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $reporter = User::factory()->create();
    $word = Word::factory()->create();
    $case = $word->reportCases()->create(['reason' => Report::REASON_WORD_UNFRESH, 'status' => 'open', 'active_key' => ReportCase::activeKey($word, Report::REASON_WORD_UNFRESH)]);
    $word->reports()->create(['report_case_id' => $case->id, 'reporter_id' => $reporter->id, 'reason' => Report::REASON_WORD_UNFRESH]);

    $this->actingAs($admin)->patch(route('admin.reports.update', $case), ['action' => 'dismissed', 'note' => 'This spelling is acceptable.'])->assertRedirect();

    expect($reporter->notifications()->first()->data['body'])->toBe("Your report about “{$word->text}” was dismissed. Reason: This spelling is acceptable.");
});
