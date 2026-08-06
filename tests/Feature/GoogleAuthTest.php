<?php

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Signing in with Google.
 *
 * Given a visitor chooses "Sign in with Google",
 * When Google redirects back to the callback,
 * Then they are authenticated as a user (new or linked by email).
 */
function fakeGoogleUser(array $overrides = []): SocialiteUser
{
    $googleUser = new SocialiteUser;
    $googleUser->map([
        'id' => $overrides['id'] ?? 'google-id-123',
        'name' => $overrides['name'] ?? 'Jane Doe',
        'email' => $overrides['email'] ?? 'jane@example.com',
        'avatar' => $overrides['avatar'] ?? 'https://lh3.googleusercontent.com/photo',
    ]);
    $googleUser->token = $overrides['token'] ?? 'access-token-abc';

    return $googleUser;
}

describe('google sign-in', function () {
    test('Given a first-time visitor, the callback creates an account and logs them in', function () {
        Mail::fake();
        Http::fake(['googleapis.com/*' => Http::response([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'picture' => 'https://lh3.googleusercontent.com/photo',
        ])]);

        Socialite::shouldReceive('driver->user')->andReturn(fakeGoogleUser());

        $this->get(route('auth.google.callback'))
            ->assertRedirect('/');

        $user = User::where('email', 'jane@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->provider)->toBe('google');
        expect($user->provider_id)->toBe('google-id-123');
        expect($user->name)->toBe('Jane Doe');
        expect($user->avatar)->toBe('https://lh3.googleusercontent.com/photo');

        $this->assertAuthenticatedAs($user);

        Mail::assertSent(WelcomeMail::class, fn ($mail) => $mail->hasTo('jane@example.com'));
    });

    test('Given an existing user with the same email, the callback links the Google account', function () {
        $existing = User::factory()->create(['email' => 'jane@example.com']);
        Http::fake(['googleapis.com/*' => Http::response([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'picture' => 'https://lh3.googleusercontent.com/photo',
        ])]);

        Socialite::shouldReceive('driver->user')->andReturn(fakeGoogleUser());

        $this->get(route('auth.google.callback'))
            ->assertRedirect('/');

        $user = $existing->fresh();
        expect($user->provider)->toBe('google');
        expect($user->provider_id)->toBe('google-id-123');
        expect(User::count())->toBe(1);

        $this->assertAuthenticatedAs($existing);
    });

    test('Given Google returns a different avatar, the OpenID userinfo picture is used', function () {
        Http::fake(['googleapis.com/*' => Http::response([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'picture' => 'https://stable.picture/photo.jpg',
        ])]);

        // The token on the socialite user says one thing; userinfo overrides it.
        Socialite::shouldReceive('driver->user')->andReturn(fakeGoogleUser(['avatar' => 'https://other.picture/ignored.jpg']));

        $this->get(route('auth.google.callback'));

        expect(User::where('email', 'jane@example.com')->first()->avatar)->toBe('https://stable.picture/photo.jpg');
    });

    test('Given userinfo fails, the callback still logs the user in with Socialite data', function () {
        Http::fake(['googleapis.com/*' => Http::response(status: 500)]);

        Socialite::shouldReceive('driver->user')->andReturn(fakeGoogleUser());

        $this->get(route('auth.google.callback'))
            ->assertRedirect('/');

        $user = User::where('email', 'jane@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->name)->toBe('Jane Doe');
        expect($user->avatar)->toBe('https://lh3.googleusercontent.com/photo');
    });

    test('Given Google throws, the callback redirects home with an error', function () {
        Socialite::shouldReceive('driver->user')->andThrow(new \Exception('oauth failure'));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error');
    });
});

describe('signing out', function () {
    test('Given a logged-in user, signing out logs them out and redirects home', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    });
});

describe('redirect to google', function () {
    test('Given a visitor hits the sign-in route, they are redirected to Google', function () {
        Socialite::shouldReceive('driver->redirect')->andReturn(
            redirect('https://accounts.google.com/o/oauth2/auth?...')
        );

        $this->get(route('auth.google'))
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth?...');
    });
});
