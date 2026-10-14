<?php

use App\Models\Favourite;
use App\Models\Ownership;
use App\Models\Report;
use App\Models\ReportReviewAction;
use App\Models\User;
use App\Models\Vote;
use App\Models\Word;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => config(['session.driver' => 'database']));

test('account routes require authentication', function () {
    $this->get(route('account.show'))->assertRedirect(route('auth.google'));
    $this->get(route('account.export'))->assertRedirect(route('auth.google'));
    $this->delete(route('account.destroy'))->assertRedirect(route('auth.google'));
});

test('an account export includes account activity but excludes credentials and sessions', function () {
    $user = User::factory()->create(['password' => 'secret-password', 'remember_token' => 'remember-me']);
    $word = Word::factory()->create();
    $definition = $word->definitions()->create(['user_id' => $user->id, 'text' => 'A definition.']);
    $user->votes()->create(['definition_id' => $definition->id]);
    Favourite::create(['user_id' => $user->id, 'word_id' => $word->id]);
    Ownership::create([
        'user_id' => $user->id,
        'word_id' => $word->id,
        'start_at' => now(),
        'end_at' => now()->addMonth(),
    ]);
    $report = $word->reports()->create(['reporter_id' => $user->id, 'reason' => Report::REASON_WORD_UNFRESH]);
    $processedReport = $word->reports()->create([
        'reason' => Report::REASON_WORD_OFFENSIVE,
        'processed_by_user_id' => $user->id,
        'processing_action' => Report::ACTION_REVIEWED,
        'processing_note' => 'Processed by this user.',
        'processed_at' => now(),
    ]);
    $report->update([
        'processed_by_user_id' => $user->id,
        'processing_action' => Report::ACTION_REVIEWED,
        'processing_note' => 'Submitted and processed by this user.',
        'processed_at' => now(),
    ]);
    ReportReviewAction::create(['report_id' => $report->id, 'admin_user_id' => $user->id, 'action' => Report::ACTION_REVIEWED]);
    DB::table('sessions')->insert(['id' => 'account-export-session', 'user_id' => $user->id, 'payload' => 'sensitive', 'last_activity' => now()->timestamp]);

    $response = $this->actingAs($user)->get(route('account.export'));

    $response->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertDownload('possiblewords-account-export.json');

    $export = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($export['profile'])->toMatchArray(['id' => $user->id, 'email' => $user->email])
        ->and($export['profile'])->not->toHaveKey('password')
        ->not->toHaveKey('remember_token')
        ->and($export['definitions'])->toHaveCount(1)
        ->and($export['votes'])->toHaveCount(1)
        ->and($export['favourites'])->toHaveCount(1)
        ->and($export['ownerships'])->toHaveCount(1)
        ->and($export['reports'])->toHaveCount(2)
        ->and(collect($export['reports'])->pluck('id')->all())->toEqualCanonicalizing([$report->id, $processedReport->id])
        ->and($export['report_review_actions'])->toHaveCount(1)
        ->and($export)->not->toHaveKey('sessions');
});

test('account deletion requires the literal DELETE confirmation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete(route('account.destroy'), [])
        ->assertSessionHasErrors('confirmation');
    $this->assertDatabaseHas('users', ['id' => $user->id]);

    $this->actingAs($user)->delete(route('account.destroy'), ['confirmation' => 'delete'])
        ->assertSessionHasErrors('confirmation');
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

test('cookie sessions cannot access an account after it is deleted', function () {
    $sessionDriver = config('session.driver');
    config(['session.driver' => 'cookie']);
    $user = User::factory()->create();

    try {
        app('session')->forgetDrivers();
        $this->get('/');

        $sessionKey = auth()->guard()->getName();
        $sessionResponse = $this->withSession([$sessionKey => $user->id])->get(route('account.show'));
        $sessionCookie = $sessionResponse->getCookie(config('session.cookie'));

        expect($sessionCookie)->not->toBeNull();

        $this->withCookie(config('session.cookie'), $sessionCookie->getValue())
            ->delete(route('account.destroy'), ['confirmation' => 'DELETE'])
            ->assertRedirect('/');

        $this->withCookie(config('session.cookie'), $sessionCookie->getValue())
            ->get(route('account.show'))
            ->assertRedirect(route('auth.google'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    } finally {
        config(['session.driver' => $sessionDriver]);
    }
});

test('account deletion removes personal data, releases words, and retains anonymous public records', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $word = Word::factory()->create(['status' => 'owned', 'owner_user_id' => $user->id, 'owned_until' => now()->addMonth()]);
    $definition = $word->definitions()->create(['user_id' => $user->id, 'text' => 'A retained public definition.', 'votes_count' => 2]);
    $otherDefinition = $word->definitions()->create(['user_id' => $otherUser->id, 'text' => 'A voted definition.', 'votes_count' => 1]);
    Vote::create(['user_id' => $user->id, 'definition_id' => $definition->id]);
    Vote::create(['user_id' => $otherUser->id, 'definition_id' => $definition->id]);
    Vote::create(['user_id' => $user->id, 'definition_id' => $otherDefinition->id]);
    Favourite::create(['user_id' => $user->id, 'word_id' => $word->id]);
    $ownership = Ownership::create([
        'user_id' => $user->id,
        'word_id' => $word->id,
        'start_at' => now(),
        'end_at' => now()->addMonth(),
        'stripe_session_id' => 'cs_test_account_deletion',
    ]);
    $report = $word->reports()->create([
        'reporter_id' => $user->id,
        'reason' => Report::REASON_WORD_OFFENSIVE,
        'explanation' => 'Retained moderation text.',
    ]);
    $reviewAction = ReportReviewAction::create([
        'report_id' => $report->id,
        'admin_user_id' => $user->id,
        'action' => Report::ACTION_REVIEWED,
        'note' => 'Retained review history.',
    ]);
    DB::table('sessions')->insert([
        ['id' => 'delete-current-session', 'user_id' => $user->id, 'payload' => 'payload', 'last_activity' => now()->timestamp],
        ['id' => 'delete-other-session', 'user_id' => $user->id, 'payload' => 'payload', 'last_activity' => now()->timestamp],
    ]);

    $this->actingAs($user)->delete(route('account.destroy'), ['confirmation' => 'DELETE'])
        ->assertRedirect('/')
        ->assertSessionHas('success', 'Your account has been deleted.');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('votes', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('favourites', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('ownerships', ['id' => $ownership->id]);
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    $this->assertDatabaseHas('definitions', ['id' => $definition->id, 'user_id' => null, 'votes_count' => 1]);
    $this->assertDatabaseHas('definitions', ['id' => $otherDefinition->id, 'votes_count' => 0]);
    $this->assertDatabaseHas('words', ['id' => $word->id, 'status' => 'available', 'owner_user_id' => null, 'owned_until' => null]);
    $this->assertDatabaseHas('reports', ['id' => $report->id, 'reporter_id' => null, 'explanation' => 'Retained moderation text.']);
    $this->assertDatabaseHas('report_review_actions', ['id' => $reviewAction->id, 'admin_user_id' => null, 'note' => 'Retained review history.']);

    $this->get(route('account.show'))->assertRedirect(route('auth.google'));
});

test('word pages expose a retained definition without former user data', function () {
    $user = User::factory()->create();
    $word = Word::factory()->create();
    $definition = $word->definitions()->create(['user_id' => $user->id, 'text' => 'A retained definition.']);

    $user->delete();

    $this->get(route('words.show', $word))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('word.definitions.0.user', null)
        );

    $this->assertDatabaseHas('definitions', ['id' => $definition->id, 'user_id' => null]);
});
