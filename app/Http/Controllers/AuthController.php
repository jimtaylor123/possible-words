<?php

namespace App\Http\Controllers;

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Load OpenID userinfo so we always get a stable `picture` URL.
     *
     * Socialite may treat Google's access token as a JWT and map claims from that
     * payload instead of calling userinfo; that path often omits `picture`, so
     * getAvatar() is empty even though the user has a profile photo.
     *
     * @return array{name?: string, email?: string, picture?: string}
     */
    protected function googleUserInfoFromAccessToken(?string $accessToken): array
    {
        if ($accessToken === null || $accessToken === '') {
            return [];
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->withToken($accessToken)
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        return $response->json() ?: [];
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            $info = $this->googleUserInfoFromAccessToken($googleUser->token);
            $name = $info['name'] ?? $googleUser->getName();
            $email = $info['email'] ?? $googleUser->getEmail();
            $avatar = $info['picture'] ?? $googleUser->getAvatar();

            $user = User::where('email', $email)->first();

            if ($user === null) {
                $user = User::create([
                    'provider' => 'google',
                    'provider_id' => $googleUser->getId(),
                    'name' => $name,
                    'email' => $email,
                    'avatar' => $avatar,
                    'password' => null,
                ]);

                $wasRecentlyCreated = true;
            } else {
                $wasRecentlyCreated = false;

                $user->forceFill([
                    'provider' => 'google',
                    'provider_id' => $googleUser->getId(),
                    'name' => $name,
                    'email' => $email,
                    'avatar' => $avatar,
                ])->save();
            }

            if ($wasRecentlyCreated) {
                Mail::to($user)->send(new WelcomeMail($user));
            }

            Auth::login($user, remember: true);
            $request->session()->regenerate();

            return redirect()->intended('/');
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('home')->with('error', 'Authentication failed. Please try again.');
        }
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
