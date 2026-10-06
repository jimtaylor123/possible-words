<?php

use App\Models\User;
use App\Services\Definitions\SystemAiUser;
use Illuminate\Support\Facades\Hash;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it creates the system AI user with a hashed password', function () {
    $user = app(SystemAiUser::class)->get();

    expect($user->email)->toBe(SystemAiUser::EMAIL)
        ->and($user->provider)->toBe('system')
        ->and($user->password)->not->toBe('')
        ->and(Hash::info($user->password)['algoName'])->not->toBe('unknown');
});

test('it reuses the same system AI user on every call', function () {
    $first = app(SystemAiUser::class)->get();
    $second = app(SystemAiUser::class)->get();

    expect($second->id)->toBe($first->id)
        ->and(User::where('email', SystemAiUser::EMAIL)->count())->toBe(1);
});
