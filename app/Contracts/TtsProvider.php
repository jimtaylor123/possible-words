<?php

namespace App\Contracts;

use App\Models\Word;

interface TtsProvider
{
    public function generateAudio(Word $word): ?string;

    public function isAvailable(): bool;
}
