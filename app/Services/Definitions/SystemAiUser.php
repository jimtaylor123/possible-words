<?php

namespace App\Services\Definitions;

use App\Models\User;
use Illuminate\Support\Str;

class SystemAiUser
{
    public const EMAIL = 'ai@possiblewords.invalid';

    public function get(): User
    {
        return User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'PossibleWords AI',
                'password' => Str::random(64),
                'provider' => 'system',
                'provider_id' => 'possiblewords-ai',
                'avatar' => null,
            ],
        );
    }
}
