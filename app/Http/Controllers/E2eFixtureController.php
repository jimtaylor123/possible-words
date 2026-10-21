<?php

namespace App\Http\Controllers;

use App\Models\Definition;
use App\Models\User;
use App\Models\Word;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class E2eFixtureController extends Controller
{
    public function reset(Request $request): JsonResponse
    {
        $namespace = $this->namespace($request);
        $wordIds = Word::withTrashed()->where('slug', 'like', "e2e-{$namespace}-%")->pluck('id');
        $userIds = User::where('email', 'like', "e2e-{$namespace}-%@example.test")->pluck('id');

        if ($wordIds->isNotEmpty()) {
            DB::table('reports')->where('reportable_type', Word::class)->whereIn('reportable_id', $wordIds)->delete();
            Definition::whereIn('word_id', $wordIds)->each(function (Definition $definition): void {
                DB::table('reports')->where('reportable_type', Definition::class)->where('reportable_id', $definition->id)->delete();
                $definition->votes()->delete();
                $definition->delete();
            });
            DB::table('favourites')->whereIn('word_id', $wordIds)->delete();
            Word::withTrashed()->whereIn('id', $wordIds)->forceDelete();
        }

        if ($userIds->isNotEmpty()) {
            DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $userIds)->delete();
            User::whereIn('id', $userIds)->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function setup(Request $request): JsonResponse
    {
        $namespace = $this->namespace($request);
        $scenario = $request->string('scenario')->toString();
        abort_unless(in_array($scenario, ['word', 'browse', 'notifications'], true), 422);
        $this->reset($request);

        $user = User::create([
            'name' => 'E2E '.Str::upper($namespace),
            'email' => "e2e-{$namespace}-user@example.test",
            'provider' => 'google',
            'password' => null,
        ]);
        $words = collect();
        $count = $scenario === 'browse' ? 24 : 1;
        for ($index = 1; $index <= $count; $index++) {
            $suffix = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $words->push(Word::create([
                'text' => "E{$namespace}{$suffix}",
                'slug' => "e2e-{$namespace}-{$suffix}",
                'phonemes' => [],
                'syllables' => $index % 3 + 1,
                'status' => 'available',
                'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
                'domain_status' => $index % 2 ? Word::DOMAIN_AVAILABLE : Word::DOMAIN_TAKEN,
                'published_at' => now()->subMinute(),
                'ipa' => $index === 1 ? '/iː tuː iː/' : null,
                'audio_url' => $index === 1 ? '/audio/e2e.mp3' : null,
            ]));
        }

        if ($scenario === 'notifications') {
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\E2eNotification',
                'data' => ['title' => 'Unread E2E notification', 'body' => 'Deterministic fixture', 'url' => route('words.show', $words->first())],
            ]);
        }

        return response()->json([
            'user' => ['email' => $user->email],
            'words' => $words->map(fn (Word $word) => ['slug' => $word->slug, 'text' => $word->text]),
        ]);
    }

    private function namespace(Request $request): string
    {
        abort_unless(app()->environment('testing') || config('app.e2e_auth_enabled'), 404);
        $namespace = $request->string('namespace')->toString();
        abort_unless((bool) preg_match('/^[a-z0-9-]{1,80}$/', $namespace), 422);

        return $namespace;
    }
}
